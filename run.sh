#!/bin/sh
cd $(dirname $(readlink -f $0))
/usr/bin/php oxalis-outbound-daemon.php
