# oxalis-outbound-daemon

## About
This is a simple daemon in PHP which utilizes oxalis-standalone to send out PEPPOL messages places in a Azure Storage Blob, which triggers an Event Grid event. This is implemented using simple polling of the event queue.

The messages needs to be wrapped in an SBD for sender/receiver identification. Receipts are uploaded to a configurable container in Azure, and sent messages can either be deleted, moved to another container or just left as-is.

Logging/tracing is performed via Azure Application Insights. 


## Running 

All configurable must be present as environment variables. See `LoadConfigFromEnvironment()` in `oxalis-outbound-daemon.php`. To start the daemon, run `run.sh`.