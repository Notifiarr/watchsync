<?php

/*
----------------------------------
------  Created: 100526   ------
------  Austin Best       ------
----------------------------------
*/

chdir(dirname(__DIR__));
require 'loader.php';

$cron->runWebhookQueue();
loggerFlush();
