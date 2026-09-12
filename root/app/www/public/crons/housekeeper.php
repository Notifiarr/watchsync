<?php

/*
----------------------------------
------  Created: 091226   ------
------  Austin Best       ------
----------------------------------
*/

chdir(dirname(__DIR__));
require 'loader.php';

$dirs = [
    LOGS_PATH . 'crons',
    LOGS_PATH . 'system',
    JOBS_PATH,
];

foreach ($dirs as $dir) {
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
}

logger(CRON_HOUSEKEEPER_LOG, 'housekeeper complete');
loggerFlush();
