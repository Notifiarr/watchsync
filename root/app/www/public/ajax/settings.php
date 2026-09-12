<?php

/*
----------------------------------
------  Created: 091026   ------
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

try {
    switch ($_POST['event'] ?? '') {
        case 'saveSettings':
            $uid = intval($userdata['id'] ?? 0);
            $msg = $user->saveOwnSettings(
                $uid,
                $_POST['username'] ?? '',
                $_POST['current_password'] ?? '',
                $_POST['new_password'] ?? '',
                $_POST['new_password_confirm'] ?? ''
            );
            $fresh = $user->getUserdata($uid);
            if (!empty($fresh)) {
                if (session_status() != PHP_SESSION_ACTIVE) {
                    session_start();
                }
                $_SESSION['userdata'] = $fresh;
                session_write_close();
            }
            $notifications->notify(0, 'user_update', [
                'event'    => 'user_update',
                'username' => $fresh['username'] ?? ($_POST['username'] ?? ''),
            ]);
            $result = [
                'error'   => false,
                'message' => $msg,
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
