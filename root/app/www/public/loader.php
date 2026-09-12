<?php

/*
----------------------------------
------  Created: 091026   ------
------  Austin Best       ------
----------------------------------
*/

error_reporting(E_ERROR | E_PARSE);
ini_set('session.gc_maxlifetime', 5400);

date_default_timezone_set(getenv('TZ') ?: 'America/New_York');

if ((!defined('IS_API') || !IS_API) && session_status() != PHP_SESSION_ACTIVE) {
    session_start();
}

if (!defined('RELATIVE_PATH')) {
    switch (true) {
        case file_exists('loader.php'):
            define('RELATIVE_PATH', './');
            break;
        case file_exists('../loader.php'):
            define('RELATIVE_PATH', '../');
            break;
        case file_exists('../../loader.php'):
            define('RELATIVE_PATH', '../../');
            break;
    }
}

if (!defined('ABSOLUTE_PATH')) {
    define('ABSOLUTE_PATH', str_replace('\\', '/', __DIR__) . '/');
}

if (!defined('IS_STARTUP')) {
    define('IS_STARTUP', false);
}

$autoload = [
    'includes/constants.php',
    'includes/constants',
    'functions',
    'functions/helpers',
    'classes/interfaces',
    'classes',
];

foreach ($autoload as $toLoad) {
    if (str_contains($toLoad, '.php')) {
        require RELATIVE_PATH . $toLoad;
    } else {
        $dir = RELATIVE_PATH . $toLoad;
        if (is_dir($dir)) {
            $handle = opendir($dir);
            while ($file = readdir($handle)) {
                if ($file[0] != '.' && !is_dir($dir . '/' . $file) && str_contains($file, '.php')) {
                    require $dir . '/' . $file;
                }
            }
            closedir($handle);
        }
    }
}

$database = new Database();
$db       = $database->db;

if (defined('IS_API') && IS_API) {
    define('IS_GUEST', true);
    $userdata = [];
} else {
    applyLoginTrust($database);
    define('IS_GUEST', !isset($_SESSION['userdata']) || !$_SESSION['userdata']);

    $userdata = [];
    if (!empty($_SESSION['userdata'])) {
        $userdata = $_SESSION['userdata'] ?? [];
    }
}

$localization = new Localization();
define('CURRENT_LOCALE', $localization->getLocale());

$user          = new User();
$notifications = new Notifications();
$mediaApps     = new MediaApps();
$shell         = new Shell();
$cron          = new Cron();

if (session_status() == PHP_SESSION_ACTIVE) {
    session_write_close();
}
