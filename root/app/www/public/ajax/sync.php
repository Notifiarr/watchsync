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
        $mediaApps->refreshParity();
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
            if (empty($userApps[$masterId]) || empty($userApps[$linkedId])) {
                $database->deleteMediaAppUserLinkById(intval($link['id']));
                continue;
            }
            $icon                                            = [
                'color' => $mediaApps->parityLinkColor(intval($link['id'])),
                'app'   => $userApps[$linkedId],
                'id'    => $linkedId,
            ];
            $userLinks[$masterId][]                          = $icon;
            $userLinks[$linkedId][]                          = $icon;
            $userMasters[$linkedId]                          = $masterId;
            $linkedAppCount[$masterId][$userApps[$linkedId]] = true;
        }
        $masterUsers = $database->getMediaAppUsers($master['id']);
        foreach ($database->getMediaApps() as $mediaApp) {
            if (!$mediaApp['active']) {
                continue;
            }
            $isMaster  = intval($mediaApp['role']) == MediaAppRoles::MASTER;
            $online    = $mediaApps->isOnline($mediaApp);
            $users     = $isMaster || !$masterUsers ? $database->getMediaAppUsers($mediaApp['id']) : $database->getMediaAppUsers($mediaApp['id'], $masterUsers);
            $libraries = $mediaApps->getLibraries($mediaApp, [], false);
            foreach ($users as &$user) {
                $userId = intval($user['id'] ?? 0);
                if (!$userId) {
                    continue;
                }
                $user['links']     = $userLinks[$userId] ?? [];
                $user['link_id']   = $mediaApp['role'] == MediaAppRoles::MASTER ? $userId : intval($userMasters[$userId] ?? 0);
                $user['linkedAll'] = $listenerCount > 0 && count($linkedAppCount[$userId] ?? []) >= $listenerCount;
                $user['sync']      = $mediaApps->parityItemSelected('user', $isMaster ? $userId : intval($userMasters[$userId] ?? 0));
                $user['libraries'] = $mediaApps->userLibraryAccessLabel($mediaApp, $user, $libraries);
            }
            unset($user);
            $syncUsers[] = [
                'id'       => $mediaApp['id'],
                'name'     => $mediaApp['name'],
                'platform' => $mediaApp['platform'],
                'role'     => $mediaApp['role'],
                'online'   => $online,
                'users'    => $users,
            ];
        }
        $mediaApps->linkLibraries();
        $libraryLinks          = [];
        $libraryLinkIds        = [];
        $linkedLibraryAppCount = [];
        $syncLibraries         = [];
        foreach ($database->getMediaAppLibraryLinks() as $link) {
            $masterKey                                                               = intval($link['media_app_id']) . ':' . $link['library_key'];
            $linkedKey                                                               = intval($link['linked_media_app_id']) . ':' . $link['linked_library_key'];
            $icon                                                                    = [
                'color' => $mediaApps->parityLinkColor(intval($link['id'])),
                'app'   => intval($link['linked_media_app_id']),
                'id'    => $link['linked_library_key'],
            ];
            $libraryLinks[$masterKey][]                                              = $icon;
            $libraryLinks[$linkedKey][]                                              = $icon;
            $libraryLinkIds[$masterKey]                                              = $masterKey;
            $libraryLinkIds[$linkedKey]                                              = $masterKey;
            $linkedLibraryAppCount[$masterKey][intval($link['linked_media_app_id'])] = true;
        }
        $masterLibraries = $mediaApps->getLibraries($master, [], false);
        foreach ($database->getMediaApps() as $mediaApp) {
            if (!$mediaApp['active']) {
                continue;
            }
            $isMaster  = intval($mediaApp['role']) == MediaAppRoles::MASTER;
            $online    = $mediaApps->isOnline($mediaApp);
            $libraries = $isMaster || !$masterLibraries ? $mediaApps->getLibraries($mediaApp, [], false) : $mediaApps->getLibraries($mediaApp, $masterLibraries, false);
            foreach ($libraries as &$library) {
                if (($library['key'] ?? '') == '') {
                    continue;
                }
                $libraryKey           = intval($mediaApp['id']) . ':' . $library['key'];
                $library['links']     = $libraryLinks[$libraryKey] ?? [];
                $library['link_id']   = $libraryLinkIds[$libraryKey] ?? ($mediaApp['role'] == MediaAppRoles::MASTER ? $libraryKey : '');
                $library['linkedAll'] = $listenerCount > 0 && count($linkedLibraryAppCount[$libraryKey] ?? []) >= $listenerCount;
                $library['sync']      = $mediaApps->parityItemSelected('library', $isMaster ? $libraryKey : ($libraryLinkIds[$libraryKey] ?? ''));
            }
            unset($library);
            $syncLibraries[] = [
                'id'        => $mediaApp['id'],
                'name'      => $mediaApp['name'],
                'platform'  => $mediaApp['platform'],
                'role'      => $mediaApp['role'],
                'online'    => $online,
                'libraries' => $libraries,
            ];
        }
        $canSync = $mediaApps->canSyncApps(2);
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
        if (!$mediaAppId || $libraryKey == '' || !$linkedMediaAppId || $linkedLibraryKey == '' || $mediaAppId == $linkedMediaAppId) {
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
            if (count($parts) != 2 || !intval($parts[0]) || $parts[1] == '') {
                continue;
            }
            $mediaApp = $database->getMediaApp(intval($parts[0]));
            if (!$mediaApp || !$mediaApps->isOnline($mediaApp)) {
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
        if (!$mediaApps->isOnline($mediaApp)) {
            echo json_encode(['error' => true, 'message' => translate('offline')]);
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
        if (!$cron->hasLibraryData()) {
            echo json_encode(['error' => true, 'message' => translate('historyNeedsLibraryData')]);
            exit;
        }
        if (!$mediaApps->isOnline($mediaApp)) {
            echo json_encode(['error' => true, 'message' => translate('offline')]);
            exit;
        }
        if (!$database->getMediaAppUsers($mediaApp['id'])) {
            echo json_encode(['error' => true, 'message' => translate('missingSyncUsers')]);
            exit;
        }
        $syncMode = intval($mediaApp['sync_mode'] ?? MediaSyncModes::BOTH);
        if (!in_array($syncMode, [MediaSyncModes::BOTH, MediaSyncModes::PUSH, MediaSyncModes::PULL])) {
            $syncMode = MediaSyncModes::PULL;
        }
        if ($syncMode == MediaSyncModes::BOTH && !$mediaApps->canSyncApps(2)) {
            $syncMode = MediaSyncModes::PULL;
        }
        echo json_encode($cron->start(intval($mediaApp['id']), [], $syncMode, MediaSyncTypes::HISTORY, [], 0));
        exit;

    case 'saveLibraryScan':
        $items = json_decode($_POST['items'] ?? '', true);
        if (!is_array($items)) {
            echo json_encode(['error' => true, 'message' => translate('couldNotSaveSettings')]);
            exit;
        }
        $mediaApps->setScanLibraries($items);
        echo json_encode(['error' => false, 'message' => translate('saved')]);
        exit;

    case 'saveParitySync':
        $kind  = $_POST['kind'] ?? '';
        $items = json_decode($_POST['items'] ?? '', true);
        if (($kind != 'user' && $kind != 'library') || !is_array($items)) {
            echo json_encode(['error' => true, 'message' => translate('couldNotSaveSettings')]);
            exit;
        }
        $mediaApps->setParitySync($kind, $items);
        echo json_encode(['error' => false, 'message' => translate('saved')]);
        exit;

    case 'startUsers':
        if (!$mediaApps->canSyncApps(2)) {
            echo json_encode(['error' => true, 'message' => translate('needOnlineMediaApps')]);
            exit;
        }
        echo json_encode($cron->start(0, [], MediaSyncModes::PUSH, MediaSyncTypes::USERS, [], 1));
        exit;

    case 'startLibraries':
        if (!$mediaApps->canSyncApps(2)) {
            echo json_encode(['error' => true, 'message' => translate('needOnlineMediaApps')]);
            exit;
        }
        $items = json_decode($_POST['items'] ?? '', true);
        if (is_array($items)) {
            $mediaApps->setParitySync('library', $items);
        }
        echo json_encode($cron->start(0, [], MediaSyncModes::PUSH, MediaSyncTypes::LIBRARIES, [], 0));
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
        if ($mediaApps->isOnline($master)) {
            $mediaApps->refreshUsers($master['id']);
        }
        $syncUsers = $database->getMediaAppUsers($master['id']);
        $syncApps  = [];
        foreach ($database->getMediaApps() as $mediaApp) {
            if (!$mediaApp['active']) {
                continue;
            }
            $mediaApp['online'] = $mediaApps->isOnline($mediaApp);
            $syncApps[]         = $mediaApp;
        }
        foreach ($syncUsers as &$user) {
            $user['watch'] = [];
            foreach ($syncApps as $mediaApp) {
                $watchUserId = intval($user['id']);
                if (intval($mediaApp['id']) != intval($master['id'])) {
                    $link        = $database->getMediaAppUserLinkForApp($watchUserId, $mediaApp['id']);
                    $watchUserId = intval($link['linked_media_app_user_id'] ?? 0);
                }
                $user['watch'][intval($mediaApp['id'])] = [
                    'movies'   => $watchUserId ? $database->countUserMovieLinks($watchUserId, $mediaApp['platform']) : 0,
                    'episodes' => $watchUserId ? $database->countUserEpisodeLinks($watchUserId, $mediaApp['platform']) : 0,
                ];
            }
        }
        unset($user);
        $onlineCount = $mediaApps->activeOnlineCount();
        $syncMode    = intval($master['sync_mode'] ?? MediaSyncModes::BOTH);
        if ($onlineCount < 2 && $syncMode == MediaSyncModes::BOTH) {
            $syncMode = MediaSyncModes::PULL;
        }
        $canSync     = $onlineCount >= 1 && $cron->hasLibraryData();
        $canSyncBoth = $onlineCount >= 2;
        $hasLibrary  = $cron->hasLibraryData();
        require RELATIVE_PATH . 'pages/sync/usersHistory.php';
        exit;

    case 'startHistory':
        $syncMode = intval($_POST['syncMode'] ?? 0);
        if (!$cron->hasLibraryData()) {
            echo json_encode(['error' => true, 'message' => translate('historyNeedsLibraryData')]);
            exit;
        }
        if ($syncMode == MediaSyncModes::BOTH && !$mediaApps->canSyncApps(2)) {
            echo json_encode(['error' => true, 'message' => translate('needOnlineMediaApps')]);
            exit;
        }
        if (!$mediaApps->canSyncApps(1)) {
            echo json_encode(['error' => true, 'message' => translate('offline')]);
            exit;
        }
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
        if (!in_array($syncMode, [MediaSyncModes::BOTH, MediaSyncModes::PUSH, MediaSyncModes::PULL])) {
            $syncMode = MediaSyncModes::PULL;
        }
        echo json_encode($cron->start(0, $userIds, $syncMode, MediaSyncTypes::HISTORY, [], 0, 0, !empty($_POST['dryRun']) ? 1 : 0));
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
        $html    = $cron->renderLogHtml($chunk['lines'], $chunk['before']);
        if ($html == '' && ($job['status'] ?? '') == 'running') {
            $html = '<div class="sync-log-line sync-log-waiting">' . htmlEscape('Waiting for job output...') . '</div>';
        }
        $end = intval($chunk['end'] ?? (intval($chunk['before']) + count($chunk['lines'])));
        $tailAttr = !empty($chunk['tail']) ? ' data-tail="1"' : '';
        ?>
                                <div class="sync-log-split" id="syncLogSplit">
                                    <div class="sync-log-pane sync-log-pane-main" id="syncLogMainPane">
                                        <pre class="mb-0 small sync-log-lines" id="syncLogLines" style="white-space: pre-wrap;" data-id="<?= htmlEscape($job['id']) ?>" data-status="<?= htmlEscape($job['status']) ?>" data-offset="<?= intval($offset) ?>" data-before="<?= intval($chunk['before']) ?>" data-end="<?= $end ?>"<?= !empty($chunk['done']) ? ' data-done="1"' : '' ?><?= $tailAttr ?>><?= $html ?></pre>
                                    </div>
                                    <div class="sync-log-pane sync-log-pane-matches d-none" id="syncLogMatchesPane">
                                        <pre class="mb-0 small sync-log-lines" id="syncLogMatches" style="white-space: pre-wrap;"></pre>
                                    </div>
                                </div>
                                <?php
                                exit;

    case 'logSearch':
        $id     = $_POST['id'] ?? '';
        $query  = $_POST['query'] ?? '';
        $limit  = 100;
        if (array_key_exists('after', $_POST)) {
            $chunk = $cron->logSearchChunk($id, $query, 0, $limit, intval($_POST['after']));
        } else {
            $before = array_key_exists('before', $_POST) ? intval($_POST['before']) : -1;
            $chunk  = $cron->logSearchChunk($id, $query, $before, $limit);
        }
        echo json_encode([
            'error'  => false,
            'html'   => $chunk['html'],
            'before' => $chunk['before'],
            'end'    => $chunk['end'],
            'done'   => $chunk['done'],
            'tail'   => !empty($chunk['tail']),
            'total'  => intval($chunk['total']),
            'query'  => $chunk['query'],
        ]);
        exit;

    case 'logJump':
        $id     = $_POST['id'] ?? '';
        $line   = intval($_POST['line'] ?? 0);
        $chunk  = $cron->logWindow($id, $line, 50);
        $html   = $cron->renderLogHtml($chunk['lines'], $chunk['before']);
        echo json_encode([
            'error'  => false,
            'html'   => $html,
            'before' => $chunk['before'],
            'end'    => $chunk['end'],
            'done'   => $chunk['done'],
            'tail'   => !empty($chunk['tail']),
            'line'   => $line,
        ]);
        exit;

    case 'logTail':
        $id     = $_POST['id'] ?? '';
        $offset = intval($_POST['offset'] ?? 0);
        $tail   = $cron->tailLog($id, $offset);
        $job = $cron->job($id);
        echo json_encode([
            'error'   => false,
            'lines'   => $tail['lines'],
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

    case 'deleteAll':
        echo json_encode($cron->deleteAllLogs());
        exit;

    case 'requeue':
        echo json_encode($cron->requeue($_POST['id'] ?? ''));
        exit;

    case 'log':
        $id     = $_POST['id'] ?? '';
        $limit  = 50;
        if (array_key_exists('after', $_POST)) {
            $chunk = $cron->logLines($id, 0, $limit, intval($_POST['after']));
            $html  = $cron->renderLogHtml($chunk['lines'], $chunk['before']);
            echo json_encode([
                'error'     => false,
                'html'      => $html,
                'before'    => $chunk['before'],
                'end'       => $chunk['end'],
                'done'      => $chunk['done'],
                'tail'      => !empty($chunk['tail']),
                'direction' => 'after',
            ]);
            exit;
        }
        $before = array_key_exists('before', $_POST) ? intval($_POST['before']) : -1;
        $chunk  = $cron->logLines($id, $before, $limit);
        $html   = $cron->renderLogHtml($chunk['lines'], $chunk['before']);
        echo json_encode([
            'error'     => false,
            'html'      => $html,
            'before'    => $chunk['before'],
            'end'       => $chunk['end'],
            'done'      => $chunk['done'],
            'tail'      => !empty($chunk['tail']),
            'direction' => 'before',
        ]);
        exit;

    default:
        echo json_encode(['error' => true, 'message' => translate('unknownSettingsEvent')]);
        exit;
}
