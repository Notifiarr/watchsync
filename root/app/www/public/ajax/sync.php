<?php

/*
----------------------------------
------  Created: 091126   ------
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

switch ($_POST['event'] ?? '') {
    case 'libraryForm':
        $syncLibraries = $mediaApps->getAppLibraries();
        require RELATIVE_PATH . 'pages/sync/library.php';
        exit;

    case 'usersForm':
        $master = [];
        foreach ($database->getMediaApps() as $mediaApp) {
            if ($mediaApp['active'] && $mediaApp['role'] == MediaAppRoles::MASTER) {
                $master = $mediaApp;
                break;
            }
        }
        if (!$master) {
            echo '<div class="alert alert-danger mb-0" role="alert">' . htmlEscape(translate('mustHaveMainSource')) . '</div>';
            exit;
        }
        $syncUsers = [];
        foreach ($database->getMediaApps() as $mediaApp) {
            if (!$mediaApp['active']) {
                continue;
            }
            $mediaApps->refreshUsers($mediaApp['id']);
        }
        $mediaApps->linkUsers();
        $userLinks      = [];
        $linkedAppCount = [];
        $listenerCount  = 0;
        $userApps       = [];
        foreach ($database->getMediaApps() as $mediaApp) {
            if (!$mediaApp['active']) {
                continue;
            }
            if (intval($mediaApp['role']) != MediaAppRoles::MASTER) {
                $listenerCount++;
            }
            foreach ($database->getMediaAppUsers($mediaApp['id']) as $user) {
                $userApps[intval($user['id'])] = intval($mediaApp['id']);
            }
        }
        $userMasters = [];
        foreach ($database->getMediaAppUserLinks() as $link) {
            $masterId = intval($link['media_app_user_id']);
            $linkedId = intval($link['linked_media_app_user_id']);
            $icon     = [
                'color' => $mediaApps->parityLinkColor(intval($link['id'])),
                'app'   => $userApps[$linkedId] ?? 0,
                'id'    => $linkedId,
            ];
            $userLinks[$masterId][] = $icon;
            $userLinks[$linkedId][] = $icon;
            $userMasters[$linkedId] = $masterId;
            if (!empty($userApps[$linkedId])) {
                $linkedAppCount[$masterId][$userApps[$linkedId]] = true;
            }
        }
        foreach ($database->getMediaApps() as $mediaApp) {
            if (!$mediaApp['active']) {
                continue;
            }
            $users = $database->getMediaAppUsers($mediaApp['id']);
            foreach ($users as &$user) {
                $userId            = intval($user['id']);
                $user['links']     = $userLinks[$userId] ?? [];
                $user['link_id']   = $mediaApp['role'] == MediaAppRoles::MASTER ? $userId : intval($userMasters[$userId] ?? 0);
                $user['linkedAll'] = $listenerCount > 0 && count($linkedAppCount[$userId] ?? []) >= $listenerCount;
            }
            unset($user);
            $syncUsers[] = [
                'id'       => $mediaApp['id'],
                'name'     => $mediaApp['name'],
                'platform' => $mediaApp['platform'],
                'role'     => $mediaApp['role'],
                'users'    => $users,
            ];
        }
        $mediaApps->linkLibraries();
        $libraryLinks         = [];
        $libraryLinkIds       = [];
        $linkedLibraryAppCount = [];
        $syncLibraries        = [];
        foreach ($database->getMediaAppLibraryLinks() as $link) {
            $masterKey = intval($link['media_app_id']) . ':' . $link['library_key'];
            $linkedKey = intval($link['linked_media_app_id']) . ':' . $link['linked_library_key'];
            $icon      = [
                'color' => $mediaApps->parityLinkColor(intval($link['id'])),
                'app'   => intval($link['linked_media_app_id']),
                'id'    => $link['linked_library_key'],
            ];
            $libraryLinks[$masterKey][] = $icon;
            $libraryLinks[$linkedKey][] = $icon;
            $libraryLinkIds[$masterKey] = $masterKey;
            $libraryLinkIds[$linkedKey] = $masterKey;
            $linkedLibraryAppCount[$masterKey][intval($link['linked_media_app_id'])] = true;
        }
        foreach ($database->getMediaApps() as $mediaApp) {
            if (!$mediaApp['active']) {
                continue;
            }
            $libraries = $mediaApps->getLibraries($mediaApp);
            foreach ($libraries as &$library) {
                $libraryKey          = intval($mediaApp['id']) . ':' . ($library['key'] ?? '');
                $library['links']    = $libraryLinks[$libraryKey] ?? [];
                $library['link_id']  = $libraryLinkIds[$libraryKey] ?? ($mediaApp['role'] == MediaAppRoles::MASTER ? $libraryKey : '');
                $library['linkedAll'] = $listenerCount > 0 && count($linkedLibraryAppCount[$libraryKey] ?? []) >= $listenerCount;
            }
            unset($library);
            $syncLibraries[] = [
                'id'        => $mediaApp['id'],
                'name'      => $mediaApp['name'],
                'platform'  => $mediaApp['platform'],
                'role'      => $mediaApp['role'],
                'libraries' => $libraries,
            ];
        }
        require RELATIVE_PATH . 'pages/sync/parity.php';
        exit;

    case 'linkUser':
        $masterUserId = intval($_POST['masterUserId'] ?? 0);
        $linkedUserId = intval($_POST['linkedUserId'] ?? 0);
        $masterUser   = $database->getMediaAppUser($masterUserId);
        $linkedUser   = $database->getMediaAppUser($linkedUserId);
        if (!$masterUser || !$linkedUser || intval($masterUser['media_app_id']) == intval($linkedUser['media_app_id'])) {
            echo json_encode(['error' => true, 'message' => translate('couldNotSaveSettings')]);
            exit;
        }
        $masterApp = $database->getMediaApp($masterUser['media_app_id']);
        if (!$masterApp || intval($masterApp['role']) != MediaAppRoles::MASTER) {
            echo json_encode(['error' => true, 'message' => translate('mustHaveMainSource')]);
            exit;
        }
        $linkId = $database->addMediaAppUserLink($masterUserId, $linkedUserId);
        echo json_encode(['error' => false, 'masterId' => $masterUserId, 'linkedId' => $linkedUserId, 'linkedAppId' => intval($linkedUser['media_app_id']), 'color' => $mediaApps->parityLinkColor($linkId)]);
        exit;

    case 'unlinkUser':
        $userId = intval($_POST['userId'] ?? 0);
        $master = !empty($_POST['master']);
        if ($master) {
            foreach ($database->getMediaAppUserLinks($userId) as $link) {
                $database->deleteMediaAppUserLink($link['linked_media_app_user_id']);
            }
        } else {
            $database->deleteMediaAppUserLink($userId);
        }
        echo json_encode(['error' => false]);
        exit;

    case 'linkLibrary':
        $mediaAppId       = intval($_POST['mediaAppId'] ?? 0);
        $libraryKey       = $_POST['libraryKey'] ?? '';
        $linkedMediaAppId = intval($_POST['linkedMediaAppId'] ?? 0);
        $linkedLibraryKey = $_POST['linkedLibraryKey'] ?? '';
        if (!$mediaAppId || $libraryKey === '' || !$linkedMediaAppId || $linkedLibraryKey === '' || $mediaAppId == $linkedMediaAppId) {
            echo json_encode(['error' => true, 'message' => translate('couldNotSaveSettings')]);
            exit;
        }
        $masterApp = $database->getMediaApp($mediaAppId);
        if (!$masterApp || intval($masterApp['role']) != MediaAppRoles::MASTER) {
            echo json_encode(['error' => true, 'message' => translate('mustHaveMainSource')]);
            exit;
        }
        $linkId    = $database->setMediaAppLibraryLink($mediaAppId, $libraryKey, $linkedMediaAppId, $linkedLibraryKey);
        $masterKey = $mediaAppId . ':' . $libraryKey;
        echo json_encode(['error' => false, 'masterId' => $masterKey, 'linkedId' => $linkedLibraryKey, 'linkedAppId' => $linkedMediaAppId, 'color' => $mediaApps->parityLinkColor($linkId)]);
        exit;

    case 'unlinkLibrary':
        $mediaAppId = intval($_POST['mediaAppId'] ?? 0);
        $libraryKey = $_POST['libraryKey'] ?? '';
        $master     = !empty($_POST['master']);
        if ($master) {
            $database->deleteMediaAppLibraryLinks($mediaAppId, $libraryKey);
        } else {
            $database->deleteMediaAppLibraryLink($mediaAppId, $libraryKey);
        }
        echo json_encode(['error' => false]);
        exit;

    case 'startLibrary':
        $libraries = [];
        foreach (explode(',', $_POST['libraries'] ?? '') as $libraryValue) {
            $parts = explode(':', $libraryValue, 2);
            if (count($parts) != 2 || !intval($parts[0]) || $parts[1] === '') {
                continue;
            }
            $libraries[] = [
                'media_app_id' => intval($parts[0]),
                'key'          => $parts[1],
            ];
        }
        if (!$libraries) {
            echo json_encode(['error' => true, 'message' => translate('missingSyncLibraries')]);
            exit;
        }
        $libraries = $mediaApps->labelAppLibraries($libraries);
        $scan      = intval($_POST['scan'] ?? MediaLibraryScans::LAST_SCAN);
        if (!in_array($scan, [MediaLibraryScans::LAST_SCAN, MediaLibraryScans::FULL])) {
            $scan = MediaLibraryScans::LAST_SCAN;
        }
        echo json_encode($cron->start(0, [], MediaSyncModes::PULL, MediaSyncTypes::LIBRARY, $libraries, 0, $scan));
        exit;

    case 'startAppLibrary':
        $mediaApp = $database->getMediaApp(intval($_POST['id'] ?? 0));
        if (!$mediaApp) {
            echo json_encode(['error' => true, 'message' => translate('pageNotFound')]);
            exit;
        }
        if (!$mediaApps->getLibraries($mediaApp)) {
            echo json_encode(['error' => true, 'message' => translate('noLibraries')]);
            exit;
        }
        echo json_encode($cron->start(intval($mediaApp['id']), [], MediaSyncModes::PULL, MediaSyncTypes::LIBRARY, [], 0, MediaLibraryScans::LAST_SCAN));
        exit;

    case 'startAppHistory':
        $mediaApp = $database->getMediaApp(intval($_POST['id'] ?? 0));
        if (!$mediaApp) {
            echo json_encode(['error' => true, 'message' => translate('pageNotFound')]);
            exit;
        }
        if (!$database->getMediaAppUsers($mediaApp['id'])) {
            echo json_encode(['error' => true, 'message' => translate('missingSyncUsers')]);
            exit;
        }
        $syncMode = intval($mediaApp['sync_mode'] ?? MediaSyncModes::BOTH);
        if (!in_array($syncMode, [MediaSyncModes::BOTH, MediaSyncModes::PUSH, MediaSyncModes::PULL])) {
            $syncMode = MediaSyncModes::BOTH;
        }
        echo json_encode($cron->start(intval($mediaApp['id']), [], $syncMode, MediaSyncTypes::HISTORY, [], 0));
        exit;

    case 'startUsers':
        $userIds = [];
        foreach (explode(',', $_POST['users'] ?? '') as $userId) {
            if (intval($userId)) {
                $userIds[] = intval($userId);
            }
        }
        if (!$userIds) {
            echo json_encode(['error' => true, 'message' => translate('missingSyncUsers')]);
            exit;
        }
        echo json_encode($cron->start(0, $userIds, MediaSyncModes::PUSH, MediaSyncTypes::USERS, [], 1));
        exit;

    case 'startLibraries':
        $libraries = [];
        foreach (explode(',', $_POST['libraries'] ?? '') as $libraryValue) {
            $parts = explode(':', $libraryValue, 2);
            if (count($parts) != 2 || !intval($parts[0]) || $parts[1] === '') {
                continue;
            }
            $libraries[] = [
                'media_app_id' => intval($parts[0]),
                'key'          => $parts[1],
            ];
        }
        if (!$libraries) {
            echo json_encode(['error' => true, 'message' => translate('missingSyncLibraries')]);
            exit;
        }
        $libraries = $mediaApps->labelAppLibraries($libraries);
        echo json_encode($cron->start(0, [], MediaSyncModes::PUSH, MediaSyncTypes::LIBRARIES, $libraries, 0));
        exit;

    case 'historyForm':
        $master = [];
        foreach ($database->getMediaApps() as $mediaApp) {
            if ($mediaApp['active'] && $mediaApp['role'] == MediaAppRoles::MASTER) {
                $master = $mediaApp;
                break;
            }
        }
        if (!$master) {
            echo '<div class="alert alert-danger mb-0" role="alert">' . htmlEscape(translate('mustHaveMainSource')) . '</div>';
            exit;
        }
        $mediaApps->refreshUsers($master['id']);
        $syncUsers = $database->getMediaAppUsers($master['id']);
        $syncMode  = $master['sync_mode'] ?? MediaSyncModes::BOTH;
        require RELATIVE_PATH . 'pages/sync/usersHistory.php';
        exit;

    case 'startHistory':
        $syncMode = intval($_POST['syncMode'] ?? 0);
        $userIds  = [];
        foreach (explode(',', $_POST['users'] ?? '') as $userId) {
            if (intval($userId)) {
                $userIds[] = intval($userId);
            }
        }
        if (!$userIds) {
            echo json_encode(['error' => true, 'message' => translate('missingSyncUsers')]);
            exit;
        }
        if (!in_array($syncMode, [MediaSyncModes::BOTH, MediaSyncModes::PUSH, MediaSyncModes::PULL])) {
            $syncMode = MediaSyncModes::BOTH;
        }
        echo json_encode($cron->start(0, $userIds, $syncMode, MediaSyncTypes::HISTORY, [], 0));
        exit;

    case 'history':
        $jobs = $cron->jobs();
        require RELATIVE_PATH . 'pages/sync/history.php';
        exit;

    case 'logViewer':
        $id  = $_POST['id'] ?? '';
        $job = $cron->job($id);
        if (!$job) {
            echo '<div class="alert alert-danger mb-0" role="alert">' . htmlEscape(translate('pageNotFound')) . '</div>';
            exit;
        }
        $logFile = $cron->logFile($job['id']);
        $offset  = $logFile ? filesize($logFile) : 0;
        $chunk   = $cron->logLines($job['id'], -1, 50);
        $html    = '';
        foreach ($chunk['lines'] as $line) {
            $html .= htmlEscape($line) . "\n";
        }
        ?>
        <pre class="mb-0 small" id="syncLogLines" style="white-space: pre-wrap;" data-id="<?= htmlEscape($job['id']) ?>" data-status="<?= htmlEscape($job['status']) ?>" data-offset="<?= intval($offset) ?>" data-before="<?= intval($chunk['before']) ?>"<?= !empty($chunk['done']) ? ' data-done="1"' : '' ?>><?= $html ?></pre>
        <?php
        exit;

    case 'logTail':
        $id     = $_POST['id'] ?? '';
        $offset = intval($_POST['offset'] ?? 0);
        $tail   = $cron->tailLog($id, $offset);
        $html   = '';
        foreach ($tail['lines'] as $line) {
            $html .= htmlEscape($line) . "\n";
        }
        $job = $cron->job($id);
        echo json_encode([
            'error'   => false,
            'html'    => $html,
            'offset'  => intval($tail['offset']),
            'status'  => $tail['status'] ?? '',
            'id'      => $id,
            'runtime' => $job['runtime'] ?? '',
            'size'    => byteConversion($job['size'] ?? 0),
        ]);
        exit;

    case 'cancel':
        echo json_encode($cron->cancel($_POST['id'] ?? ''));
        exit;

    case 'delete':
        echo json_encode($cron->deleteLog($_POST['id'] ?? ''));
        exit;

    case 'log':
        $id     = $_POST['id'] ?? '';
        $before = array_key_exists('before', $_POST) ? intval($_POST['before']) : -1;
        $chunk  = $cron->logLines($id, $before, 50);
        $html   = '';
        foreach ($chunk['lines'] as $line) {
            $html .= htmlEscape($line) . "\n";
        }
        echo json_encode([
            'error'  => false,
            'html'   => $html,
            'before' => $chunk['before'],
            'done'   => $chunk['done'],
        ]);
        exit;

    default:
        echo json_encode(['error' => true, 'message' => translate('unknownSettingsEvent')]);
        exit;
}
