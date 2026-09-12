<?php

/*
----------------------------------
------  Created: 091626   ------
------  Austin Best       ------
----------------------------------
*/

define('IS_API', true);

$apiStarted    = microtime(true);
$apiKey        = '';
$mediaApp      = '';
$webhookParsed = [];

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

require RELATIVE_PATH . 'loader.php';

webhookMergeRequest();

$agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
switch (true) {
    case webhookNamesApp('jellyfin', $agent, $_POST):
        $mediaApp = 'jellyfin';
        break;
    case webhookNamesApp('emby', $agent, $_POST):
        $mediaApp = 'emby';
        break;
    case webhookNamesApp('plex', $agent, $_POST):
        $mediaApp = 'plex';
        break;
}

if ($mediaApp != '') {
    $webhookParsed = webhookParse($mediaApp, $_POST);
}

$apiKey = trim(strval($_GET['apikey'] ?? ($_SERVER['HTTP_X_API_KEY'] ?? '')));
$stored = strval($database->getSetting('apiKey'));
if ($apiKey == '' || $stored == '' || strlen($apiKey) != strlen($stored) || !hash_equals($stored, $apiKey)) {
    apiResponse(401, 'Unknown API key');
}
if ($mediaApp == '') {
    apiResponse(400, 'Unknown media app');
}

apiResponse(200, webhookApply($mediaApp, $webhookParsed));
