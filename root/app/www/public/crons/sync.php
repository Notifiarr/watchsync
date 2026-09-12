<?php

/*
----------------------------------
------  Created: 091226   ------
------  Austin Best       ------
----------------------------------
*/

chdir(dirname(__DIR__));
set_time_limit(0);
ignore_user_abort(true);
require 'loader.php';

$jobId = $argv[1] ?? '';
$cron->run($jobId);
loggerFlush();
