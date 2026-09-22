<?php

/*
----------------------------------
------  Created: 091126   ------
------  Austin Best       ------
----------------------------------
*/

define('APP_DATA_PATH', '/config/');
define('LOGS_PATH', APP_DATA_PATH . 'logs/');
define('WEBHOOK_LOGS_PATH', LOGS_PATH . 'webhooks/');
define('CRON_LOGS_PATH', LOGS_PATH . 'crons/');
define('JOBS_PATH', APP_DATA_PATH . 'jobs/');
define('DATABASE_PATH', APP_DATA_PATH . 'database/');
define('BACKUP_PATH', APP_DATA_PATH . 'backups/');
define('POSTER_CACHE_PATH', APP_DATA_PATH . 'cache/posters/');
define('MIGRATION_FILE', APP_DATA_PATH . '.migration-running');

define('SYSTEM_LOG', LOGS_PATH . 'system/app.log');
define('STARTUP_LOG', LOGS_PATH . 'system/startup.log');
define('MIGRATION_LOG', LOGS_PATH . 'system/migrations.log');
define('CRON_DISPATCHER_LOG', LOGS_PATH . 'crons/dispatcher.log');
define('CRON_HOUSEKEEPER_LOG', LOGS_PATH . 'crons/housekeeper.log');
define('CRON_BACKUP_LOG', LOGS_PATH . 'crons/backup.log');
define('CRON_SYNC_LOG', LOGS_PATH . 'crons/sync.log');

define('LOG_ROTATE_SIZE', 2);

define('MEMCACHE_HOST', '127.0.0.1');
define('MEMCACHE_PORT', 11211);
define('MEMCACHE_PREFIX', 'watchsync-');
