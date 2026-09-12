<?php

/*
----------------------------------
------  Created: 091626   ------
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

if (IS_GUEST) {
    echo json_encode(['error' => true, 'message' => translate('notSignedIn')]);
    exit;
}

$result = [
    'error'   => true,
    'message' => translate('unknownSettingsEvent'),
];

$event = $_POST['event'] ?? $_GET['event'] ?? '';

try {
    switch ($event) {
        case 'generateApiKey':
            $result = [
                'error' => false,
                'key'   => generateApikey(),
            ];
            break;
        case 'saveWebhooks':
            $database->setSetting('apiKey', trim(strval($_POST['apiKey'] ?? '')));
            $posted = $_POST['apps'] ?? [];
            if (!is_array($posted)) {
                $posted = [];
            }
            foreach ($database->getMediaApps() as $mediaApp) {
                $id = intval($mediaApp['id']);
                $database->setMediaAppWebhooks($id, !empty($posted[$id]) || !empty($posted[strval($id)]) ? 1 : 0);
            }
            $result = [
                'error'   => false,
                'message' => translate('saved'),
            ];
            break;
        case 'deleteWebhookLog':
            $file = basename(strval($_POST['file'] ?? ''));
            $path = ($file != '' && str_ends_with($file, '.log')) ? (WEBHOOK_LOGS_PATH . $file) : '';
            if ($path == '' || !is_file($path)) {
                $result = [
                    'error'   => true,
                    'message' => translate('couldNotLoadLog'),
                ];
                break;
            }
            unlink($path);
            $result = [
                'error'   => false,
                'message' => translate('removed'),
            ];
            break;
        case 'deleteAllWebhookLogs':
            if (is_dir(WEBHOOK_LOGS_PATH)) {
                foreach (glob(WEBHOOK_LOGS_PATH . '*.log') ?: [] as $path) {
                    if (is_file($path)) {
                        unlink($path);
                    }
                }
            }
            $result = [
                'error'   => false,
                'message' => translate('webhookLogsCleared'),
            ];
            break;
    }
} catch (Throwable $error) {
    $result = [
        'error'   => true,
        'message' => translate('couldNotSaveWebhooks'),
    ];
}

echo json_encode($result);
