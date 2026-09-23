<?php

/*
----------------------------------
------  Created: 091326   ------
------  Austin Best       ------
----------------------------------
*/

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

loggedOutResponse();


$result = [
    'error'   => true,
    'message' => translate('unknownSettingsEvent'),
];

$event = $_POST['event'] ?? $_GET['event'] ?? '';

try {
    switch ($event) {
        case 'viewLog':
            $path = resolveLogFile($_POST['name'] ?? '');
            if (!$path) {
                $result = [
                    'error'   => true,
                    'message' => translate('couldNotLoadLog'),
                    'header'  => '',
                    'log'     => '',
                ];
                break;
            }

            logger(SYSTEM_LOG, 'View log: ' . ($_POST['name'] ?? ''));
            $read   = readLogFile($path);
            $header = ($_POST['name'] ?? '') . ' (' . byteConversion($read['size']) . ')';
            if ($read['trimmed']) {
                $header .= ' - ' . translate('logTrimmed');
            }

            $result = [
                'error'  => false,
                'header' => $header,
                'log'    => $read['data'] != '' ? formatLogViewerHtml($read['data']) : htmlEscape(translate('emptyLog')),
                'size'   => intval($read['size']),
            ];
            break;
        case 'downloadLog':
            $name = $_POST['name'] ?? $_GET['name'] ?? '';
            $path = resolveLogFile($name);
            if (!$path) {
                $result = [
                    'error'   => true,
                    'message' => translate('couldNotLoadLog'),
                ];
                break;
            }

            header('Content-Type: text/plain; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . basename($path) . '"');
            header('Content-Length: ' . filesize($path));
            readfile($path);
            exit;
        case 'deleteLog':
            $path = resolveLogFile($_POST['name'] ?? '');
            if (!$path || isProtectedLog($_POST['name'] ?? '')) {
                $result = [
                    'error'   => true,
                    'message' => translate('couldNotLoadLog'),
                ];
                break;
            }

            logger(SYSTEM_LOG, 'Delete log: ' . ($_POST['name'] ?? ''));
            unlink($path);
            $result = [
                'error'   => false,
                'message' => translate('logDeleted'),
            ];
            break;
        case 'purgeLogs':
            $group = resolveLogGroup($_POST['group'] ?? '');
            if (!$group || isContainerLogGroup($_POST['group'] ?? '')) {
                $result = [
                    'error'   => true,
                    'message' => translate('couldNotLoadLog'),
                ];
                break;
            }

            logger(SYSTEM_LOG, 'Purge logs: ' . ($_POST['group'] ?? ''));
            deleteLogGroupFiles($group);
            $result = [
                'error'   => false,
                'message' => translate('logsPurged'),
            ];
            break;
        default:
            break;
    }
} catch (Throwable $error) {
    $result = [
        'error'   => true,
        'message' => $error->getMessage(),
    ];
}

echo json_encode($result);
exit;
