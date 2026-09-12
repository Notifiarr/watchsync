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

$event = $_POST['event'] ?? $_GET['event'] ?? '';

try {
    switch ($event) {
        case 'saveBackupSettings':
            $database->setSettings([
                'backupTime' => normalizeBackupTime($_POST['backupTime'] ?? '03:00'),
                'backupKeep' => $_POST['backupKeep'] ?? '7',
            ]);
            $result = [
                'error'   => false,
                'message' => translate('saved'),
            ];
            break;
        case 'saveSyncSettings':
            $database->setSettings([
                'syncParityAutoUsers'     => !empty($_POST['syncParityAutoUsers']) ? '1' : '',
                'syncParityAutoLibraries' => !empty($_POST['syncParityAutoLibraries']) ? '1' : '',
                'syncLibraryAutoMeta'     => !empty($_POST['syncLibraryAutoMeta']) ? '1' : '',
                'syncHistoryNewUsers'     => !empty($_POST['syncHistoryNewUsers']) ? '1' : '',
                'syncHistoryNewLibraries' => !empty($_POST['syncHistoryNewLibraries']) ? '1' : '',
            ]);
            $mediaApps->applySyncAutoSelections();
            $result = [
                'error'   => false,
                'message' => translate('saved'),
            ];
            break;
        case 'saveLoginSettings':
            $uid = intval($userdata['id'] ?? 0);
            try {
                $msg   = $user->saveOwnSettings(
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
                $notifications->notify(0, 'userUpdate', [
                    'event'    => 'userUpdate',
                    'username' => $fresh['username'] ?? ($_POST['username'] ?? ''),
                ]);
                $result = [
                    'error'   => false,
                    'message' => $msg,
                ];
            } catch (InvalidArgumentException $error) {
                if ($error->getMessage() == translate('nothingToUpdate')) {
                    $result = [
                        'error'   => false,
                        'message' => translate('saved'),
                    ];
                } else {
                    throw $error;
                }
            }
            break;
        case 'runBackup':
            if (!$cron->runBackup('manual')) {
                $result = [
                    'error'   => true,
                    'message' => translate('couldNotRunBackup'),
                ];
                break;
            }
            $result = [
                'error'   => false,
                'message' => translate('backupComplete'),
            ];
            break;
        case 'listBackups':
            ob_start();
            require RELATIVE_PATH . 'pages/settings/backupList.php';
            $result = [
                'error' => false,
                'html'  => ob_get_clean(),
            ];
            break;
        case 'deleteBackup':
            $folder = basename($_POST['folder'] ?? '');
            $run    = basename($_POST['run'] ?? '');
            $path   = BACKUP_PATH . $folder . '/' . $run;
            if (is_dir($path)) {
                $shell->exec('rm -rf ' . $path);
            }
            $dateDir = BACKUP_PATH . $folder;
            if (is_dir($dateDir)) {
                $left = array_diff(scandir($dateDir), ['.', '..']);
                if (!$left) {
                    rmdir($dateDir);
                }
            }
            $result = [
                'error'   => false,
                'message' => translate('removed'),
            ];
            break;
        case 'restoreBackup':
            $folder = basename($_POST['folder'] ?? '');
            $run    = basename($_POST['run'] ?? '');
            $path   = BACKUP_PATH . $folder . '/' . $run;
            if (!preg_match('/^\d{8}$/', $folder) || !preg_match('/^\d{6}_(manual|automatic)$/', $run) || !is_dir($path)) {
                $result = [
                    'error'   => true,
                    'message' => translate('couldNotRestoreBackup'),
                ];
                break;
            }
            if (!$database->mysqli_restore($path)) {
                $result = [
                    'error'   => true,
                    'message' => translate('couldNotRestoreBackup'),
                ];
                break;
            }
            logger(SYSTEM_LOG, 'Database restored from backup ' . $folder . '/' . $run);
            $result = [
                'error'   => false,
                'message' => translate('backupRestored'),
            ];
            break;
        case 'resetHistory':
            $userIds = [];
            foreach (explode(',', $_POST['users'] ?? '') as $userId) {
                if (intval($userId)) {
                    $userIds[] = intval($userId);
                }
            }
            $userIds = array_values(array_unique($userIds));
            if (!$userIds) {
                $result = [
                    'error'   => true,
                    'message' => translate('missingSyncUsers'),
                ];
                break;
            }
            $database->deleteUserMovieLinksByUserIds($userIds);
            $database->deleteUserEpisodeLinksByUserIds($userIds);
            $result = [
                'error'   => false,
                'message' => translate('resetHistoryComplete'),
            ];
            break;
        case 'listResetUsers':
            ob_start();
            require RELATIVE_PATH . 'pages/settings/reset.php';
            $result = [
                'error' => false,
                'html'  => ob_get_clean(),
            ];
            break;
        case 'downloadBackup':
            $folder = basename($_REQUEST['folder'] ?? '');
            $run    = basename($_REQUEST['run'] ?? '');
            $path   = BACKUP_PATH . $folder . '/' . $run;
            $zip    = sys_get_temp_dir() . '/' . $folder . '_' . $run . '.zip';
            if (!zipBackupFolder($path, $zip)) {
                $result = [
                    'error'   => true,
                    'message' => translate('couldNotRunBackup'),
                ];
                break;
            }
            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="' . $folder . '_' . $run . '.zip"');
            header('Content-Length: ' . filesize($zip));
            readfile($zip);
            unlink($zip);
            exit;
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
