<?php

/*
----------------------------------
------  Created: 091226   ------
------  Austin Best       ------
----------------------------------
*/

define('IS_STARTUP', true);
define('RELATIVE_PATH', './');

$startupDirs = [
    '/config/logs/crons',
    '/config/logs/system',
    '/config/logs/nginx',
    '/config/logs/php',
    '/config/jobs',
    '/config/database',
];

foreach ($startupDirs as $startupDir) {
    if (!is_dir($startupDir)) {
        mkdir($startupDir, 0755, true);
    }
}

chdir('/app/www/public');
require __DIR__ . '/loader.php';

if (class_exists('Memcached')) {
    $memcached = new Memcached();
    $memcached->addServer(MEMCACHE_HOST, MEMCACHE_PORT);
}

logger(STARTUP_LOG, 'Container init (Start/Restart) ->');
logger(STARTUP_LOG, 'Container init (Start/Restart) <-');
loggerFlush();
