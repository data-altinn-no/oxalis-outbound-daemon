<?php

namespace Nadobe\OxalisOutboundAzure;

require_once "vendor/autoload.php";

use MicrosoftAzure\Storage\Common\Exceptions\ServiceException;
use MicrosoftAzure\Storage\Queue\QueueRestProxy;
use MicrosoftAzure\Storage\Blob\BlobRestProxy;

use \ApplicationInsights\Channel\Contracts\Data_Point_Type;

use Monolog\Logger;

LoadConfigFromEnvironment();

$queueClient = QueueRestProxy::createQueueService(STORAGE_ACCOUNT_CONNECTION_STRING);
$blobClient = BlobRestProxy::createBlobService(STORAGE_ACCOUNT_CONNECTION_STRING);


Logger()->debug("Polling for new messages ...");

while (true) {

    try {
        $messages = GetQueueMessages($queueClient, QUEUE_OUTBOUND);
        if (count($messages)) {
            // Disabled pending release with this fix: https://github.com/Microsoft/ApplicationInsights-PHP/commit/675ebd57b71702542770799325cd7677f1f143aa
            //Telemetry()->trackMetric("messagesPerPoll", count($messages), Data_Point_Type::Measurement);
        }
        foreach ($messages as $message) {
            Logger()->Info("Processing queue message id " . $message->getMessageId(), [$message]);
            $starttime = microtime(true);
            $ehfXml = DownloadXmlBlobFromQueueMessage($blobClient, $message);
            $receipt = SendEhfViaOxalisStandalone($ehfXml);
            UploadReceipt($blobClient, $receipt, $message, BLOB_ARCHIVED);
            DeleteXmlBlob($blobClient, $message);
            DeleteQueueMessage($queueClient, QUEUE_OUTBOUND, $message);
            // Disabled pending release with this fix: https://github.com/Microsoft/ApplicationInsights-PHP/commit/675ebd57b71702542770799325cd7677f1f143aa
	    //Telemetry()->trackMetric("messageProcessTime", microtime(true) - $starttime, Data_Point_Type::Measurement);
        }
    }
    catch (ServiceException $e) {

        if ($e->getCode() == 404 && strpos($e->getMessage(), "specified blob does not exist") !== false) {
            Logger()->error("Queue message referring blob that did not exist, deleting queue message", ['exception' => $e]);
            DeleteQueueMessage($queueClient, QUEUE_OUTBOUND, $message);
        }
        else {
            Logger()->error($e->getMessage(), ['exception' => $e]);
        }
    }
    catch (InvalidXmlException $e) {
        // The exceptions and failing XML is already logged at this point, so just delete and move on
        DeleteXmlBlob($blobClient, $message);
        DeleteQueueMessage($queueClient, QUEUE_OUTBOUND, $message);
    }
    catch (OxalisStandaloneException $e) {
        // To avoid head-of-line blocking, delete it from the queue
        // FIXME! This required additional attempts
        DeleteQueueMessage($queueClient, QUEUE_OUTBOUND, $message);
    }
    catch (IOException $e) {	    
        // This requires user intervention, just sleep for 60 seconds to avoid filling the logs
        sleep(60);
    }
    finally {
	if (!empty($messages)) Logger()->debug("Polling for new messages ...");
    }

    sleep(1);
}

//////////////////////////////////////////////////////////////////////////////////////////

function GetQueueMessages($queueClient, $queueName) {

    $message_options = new \MicrosoftAzure\Storage\Queue\Models\ListMessagesOptions();
    $message_options->setNumberOfMessages(10);

    $listMessagesResult = $queueClient->listMessages($queueName, $message_options);
    $messages = $listMessagesResult->getQueueMessages();

    return $messages;
}

function DeleteQueueMessage($queueClient, $queueName, $message) {
    $messageId = $message->getMessageId();
    $popReceipt = $message->getPopReceipt();

    Logger()->info("Deleting queue message", [$messageId, $popReceipt]);

    $queueClient->deleteMessage($queueName, $messageId, $popReceipt);
}

function DownloadXmlBlobFromQueueMessage($blobClient, $message) {
    Logger()->debug("Parsing queue message object", [$message]);
    $blobRef = GetMessageTextObject($message);
    [$container, $fileName] = GetContainerAndBlobNameFromUrl($blobRef->data->url);

    Logger()->info("Downloading from blob storage", [$container, $fileName]);
    $getBlobResult = $blobClient->getBlob($container, $fileName);
    Logger()->debug("Got result", [$getBlobResult]);

    $buf = "";
    $s = $getBlobResult->getContentStream();
    while (!feof($s)) {
        $buf .= fgets($s, 64*1024);
    }

    return $buf;
}

function SendEhfViaOxalisStandalone($ehfXml) {
    $randstr = md5(uniqid("", true));
    $tmpfile = tempnam(sys_get_temp_dir(), 'ehf' . $randstr);
    Logger()->debug("Saving XML to temporary file", [$tmpfile]);
    file_put_contents($tmpfile, $ehfXml);

    $evidenceDir = sys_get_temp_dir() . "/evidence" . $randstr;
    if (@!mkdir($evidenceDir, true)) {
        Logger()->error("Failed to create temporary directory for evidence");
        throw new IOException();
    }
    Logger()->debug("Created temporary directory for evidence", [$evidenceDir]);

    [$receiver,$sender] = GetSenderReceiverFromXml($ehfXml);
    $cmd = sprintf("%s -f %s -s %s -r %s -e %s -cert %s", 
        OXALIS_STANDALONE, 
        escapeshellarg($tmpfile), 
        escapeshellarg($sender), 
        escapeshellarg($receiver),
	escapeshellarg($evidenceDir),	
	escapeshellarg(PEPPOL_CERT_PATH)
    );

    Logger()->info("Executing oxalis-standalone", [$cmd]);

    exec($cmd, $output, $return_code);

    if ($return_code != 0) {
        Logger()->error("Failed to run oxalis-standalone, got return code $return_code", ["output" => join("\n", $output)]);
        throw new OxalisStandaloneException();
    }

    $evidence = glob($evidenceDir . "/*");
    if (!count($evidence) || !is_readable($evidence[0])) {
        Logger()->error("oxalis-standalone did not create a readable evidence in $evidenceDir as requested", ["output" => join("\n", $output)]);
        throw new OxalisStandaloneException();
    }

    $evidenceContents = "";
    foreach ($evidence as $evidenceFile) {
	Logger()->debug("Getting receipt evidence from $evidenceFile");
	$evidenceContents .= file_get_contents($evidenceFile);
	unlink($evidenceFile);
    }

    Logger()->debug("Deleting temporary file", [$tmpfile]);
    unlink($tmpfile);
    Logger()->debug("Deleting temporary evidence directory", [$tmpfile]);
    rmdir($evidenceDir);

    return $evidenceContents;
}

function UploadReceipt($blobClient, $receipt, $message, $archiveContainer) {
    $blobRef = GetMessageTextObject($message);
    [$container, $fileName] = GetContainerAndBlobNameFromUrl($blobRef->data->url);

    $tmpfile = tempnam(sys_get_temp_dir(), 'ehfreceipt');
    Logger()->debug("Saving receipt to temporary file", [$tmpfile]);
    file_put_contents($tmpfile, $receipt);

    $receiptFileName = $fileName . "_receipt.xml";
    Logger()->info("Uploading receipt", [$receiptFileName]);

    $blobClient->createBlockBlob($archiveContainer, $receiptFileName, fopen($tmpfile, "r"));

    Logger()->debug("Deleting temporary file", [$tmpfile]);
    unlink($tmpfile);
}

function DeleteXmlBlob($blobClient, $message) {
    $blobRef = GetMessageTextObject($message);
    [$container, $fileName] = GetContainerAndBlobNameFromUrl($blobRef->data->url);

    Logger()->info("Deleting XML from blob storage", [$fileName]);
    $blobClient->deleteBlob($container, $fileName);
}

function GetMessageTextObject($message) {
    return json_decode(base64_decode($message->getMessageText()));
}

function GetContainerAndBlobNameFromUrl($url) {
    $parts = parse_url($url, PHP_URL_PATH);
    return explode("/", substr($parts, 1));
}

function GetSenderReceiverFromXml($ehfXml) {
    libxml_use_internal_errors(true);
    $sxe = simplexml_load_string($ehfXml);
    $errors = [];
    if ($sxe === false) {
        foreach (libxml_get_errors() as $error) {
            $errors[] = $error;
        }

        $e = new InvalidXmlException("Unable to parse XML: not well formed");
        Logger()->error("Unable to parse XML", ["reason" => "notwellformed", "errors" => $errors, "xml" => $ehfXml, "exception" => $e]);
        throw $e;
    }

    $receiver = $sxe->xpath('//cac:ContractingParty[1]/cac:Party/cac:PartyIdentification/cbc:ID');
    $sender = $sxe->xpath('//resp:PartyIdentification[1]/resp:ID');

    if (empty($receiver[0]) || empty($sender[0])) {
        $e = new InvalidXmlException("Unable to parse XML: unable to find parties");
        Logger()->error("Unable to parse XML", ["reason" => "nopartiesfound", "errors" => $errors, "xml" => $ehfXml, "exception" => $e]);
        throw $e;
    }

    $receiverIdentifier = $receiver[0]->attributes()->schemeID . ":" . ((string) $receiver[0]);
    $senderIdentifier = $sender[0]->attributes()->schemeID . ":" . ((string) $sender[0]);

    return [$receiverIdentifier, $senderIdentifier];
}

function LoadConfigFromEnvironment() {

    $connectionString = null;
    if (!($connectionString = getenv('AZURE_STORAGE_ACCOUNT_CONNECTION_STRING'))) {
        if (!file_exists(__DIR__ . "/connectionstring.txt") || !($connectionString = trim(file_get_contents(__DIR__ . "/connectionstring.txt")))) {
            echo "ConnectionString to storage account must be set via the AZURE_STORAGE_ACCOUNT_CONNECTION_STRING environment variable or placed in the file connectionstring.txt\n";
            exit(1);
        }
    }
    $instrumentationKey = null;
    if (!($instrumentationKey = getenv('OUTBOUND_AZURE_INSIGHTS_INSTRUMENTATION_KEY'))) {
        if (!file_exists(__DIR__ . "/instrumentationkey.txt") || !($instrumentationKey = trim(file_get_contents(__DIR__ . "/instrumentationkey.txt")))) {
            echo "Application Insights instrumentation key must be set in the OUTBOUND_AZURE_INSIGHTS_INSTRUMENTATION_KEY environment variable or placed in the file instrumentationkey.txt.\n";
            exit(1);
        }
    }

    $peppolCertPath = null;
    if (!($peppolCertPath = getenv('PEPPOL_CERT_PATH'))) {
	echo "PEPPOL_CERT_PATH not set\n";
	exit(1);
    }

    define('STORAGE_ACCOUNT_CONNECTION_STRING', $connectionString);
    define('INSIGHTS_INSTRUMENTATION_KEY', $instrumentationKey);
    define('OXALIS_STANDALONE', getenv('OXALIS_STANDALONE') ?: 'sh /oxalis/bin-standalone/run-docker.sh');
    define('PEPPOL_CERT_PATH', $peppolCertPath);
    define('BLOB_ARCHIVED', getenv('OUTBOUND_AZURE_BLOB_ARCHIVED') ?: 'archived');
    define('BLOB_FAILED', getenv('OUTBOUND_AZURE_BLOB_FAILED') ?: 'failed');
    define('QUEUE_OUTBOUND', getenv('OUTBOUND_AZURE_QUEUE_OUTBOUND') ?: 'outbound');
}

function Logger() {
    static $logger = null;

    if ($logger != null) {
        return $logger;
    }

    $logger = new Logger('default');
    $logger->pushHandler(new \Monolog\Handler\ErrorLogHandler());
    $logger->pushHandler(new \ER\MSApplicationInsightsMonolog\MSApplicationInsightsHandler(Telemetry()));

    return $logger;
}

function Telemetry() {
    static $client = null;

    if ($client != null) {
        return $client;
    }

    $client = new \ApplicationInsights\Telemetry_Client();
    $client->getContext()->setInstrumentationKey(INSIGHTS_INSTRUMENTATION_KEY);

    return $client;
}


class InvalidXmlException extends \Exception {}
class OxalisStandaloneException extends \Exception {}
class IOException extends \Exception {}
