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

switch ($_POST['event'] ?? '') {
    case 'form':
        $id             = intval($_POST['id'] ?? 0);
        $mediaApp       = $id ? $database->getMediaApp($id) : [];
        $mediaApps      = $database->getMediaApps();
        $isFirst        = !$mediaApps;
        $isMaster       = $isFirst || ($mediaApp && $mediaApp['role'] == MediaAppRoles::MASTER);
        $hasOtherMaster = false;
        foreach ($mediaApps as $existingApp) {
            if (intval($existingApp['role']) == MediaAppRoles::MASTER && intval($existingApp['id']) != $id) {
                $hasOtherMaster = true;
                break;
            }
        }
        $platform = $mediaApp['platform'] ?? MediaPlatforms::PLEX;
        $syncMode = $mediaApp['sync_mode'] ?? MediaSyncModes::BOTH;
        $active   = $mediaApp ? $mediaApp['active'] : 1;
        ?>
                                                                <div class="container-fluid">
                                                                    <div class="mb-3">
                                                                        <label for="mediaAppName" class="form-label"><?= htmlEscape(translate('name')) ?> <span class="small text-danger"><?= htmlEscape(translate('required')) ?></span></label>
                                                                        <input type="text" class="form-control" id="mediaAppName" value="<?= $mediaApp['name'] ?? '' ?>">
                                                                    </div>
                                                                    <div class="mb-3">
                                                                        <label for="mediaAppPlatform" class="form-label"><?= htmlEscape(translate('platform')) ?> <span class="small text-danger"><?= htmlEscape(translate('required')) ?></span></label>
                                                                        <select class="form-select" id="mediaAppPlatform" data-plex="<?= MediaPlatforms::PLEX ?>" onchange="toggleMediaAppAuth()">
                                                                            <option value="<?= MediaPlatforms::PLEX ?>" <?= intval($platform) == MediaPlatforms::PLEX ? ' selected' : '' ?>><?= htmlEscape(translate('plex')) ?></option>
                                                                            <option value="<?= MediaPlatforms::EMBY ?>" <?= intval($platform) == MediaPlatforms::EMBY ? ' selected' : '' ?>><?= htmlEscape(translate('emby')) ?></option>
                                                                            <option value="<?= MediaPlatforms::JELLYFIN ?>" <?= intval($platform) == MediaPlatforms::JELLYFIN ? ' selected' : '' ?>><?= htmlEscape(translate('jellyfin')) ?></option>
                                                                        </select>
                                                                    </div>
                                                                    <div class="mb-3">
                                                                        <label for="mediaAppUrl" class="form-label"><?= htmlEscape(translate('url')) ?> <span class="small text-danger"><?= htmlEscape(translate('required')) ?></span></label>
                                                                        <input type="text" class="form-control" id="mediaAppUrl" value="<?= $mediaApp['url'] ?? '' ?>" placeholder="http://192.168.1.10:32400">
                                                                        <div class="form-text"><?= htmlEscape(translate('mediaAppUrlDescription')) ?></div>
                                                                    </div>
                                                                    <div class="mb-3<?= intval($platform) == MediaPlatforms::PLEX ? '' : ' d-none' ?>" id="mediaAppTokenGroup">
                                                                        <label for="mediaAppToken" class="form-label"><?= htmlEscape(translate('plexToken')) ?> <span class="small text-danger"><?= htmlEscape(translate('required')) ?></span></label>
                                                                        <input type="password" class="form-control" id="mediaAppToken" value="<?= $mediaApp['token'] ?? '' ?>" autocomplete="off">
                                                                        <div class="form-text"><?= htmlEscape(translate('plexTokenDescription')) ?></div>
                                                                    </div>
                                                                    <div class="mb-3<?= intval($platform) == MediaPlatforms::PLEX ? ' d-none' : '' ?>" id="mediaAppApikeyGroup">
                                                                        <label for="mediaAppApikey" class="form-label"><?= htmlEscape(translate('apiKey')) ?> <span class="small text-danger"><?= htmlEscape(translate('required')) ?></span></label>
                                                                        <input type="password" class="form-control" id="mediaAppApikey" value="<?= $mediaApp['apikey'] ?? '' ?>" autocomplete="off">
                                                                        <div class="form-text"><?= htmlEscape(translate('apiKeyDescription')) ?></div>
                                                                    </div>
                                                                    <div class="mb-3">
                                                                        <label for="mediaAppSyncMode" class="form-label"><?= htmlEscape(translate('syncMode')) ?></label>
                                                                        <select class="form-select" id="mediaAppSyncMode">
                                                                            <option value="<?= MediaSyncModes::BOTH ?>" <?= intval($syncMode) == MediaSyncModes::BOTH ? ' selected' : '' ?>><?= htmlEscape(translate('pushAndPull')) ?></option>
                                                                            <option value="<?= MediaSyncModes::PUSH ?>" <?= intval($syncMode) == MediaSyncModes::PUSH ? ' selected' : '' ?>><?= htmlEscape(translate('pushOnly')) ?></option>
                                                                            <option value="<?= MediaSyncModes::PULL ?>" <?= intval($syncMode) == MediaSyncModes::PULL ? ' selected' : '' ?>><?= htmlEscape(translate('pullOnly')) ?></option>
                                                                        </select>
                                                                        <div class="form-text"><?= htmlEscape(translate('syncModeDescription')) ?></div>
                                                                    </div>
                                                                    <div class="mb-3">
                                                                        <div class="form-check">
                                                                            <input class="form-check-input" type="checkbox" id="mediaAppMaster" <?= $isMaster ? ' checked' : '' ?><?= $isFirst ? ' disabled' : '' ?>>
                                                                            <label class="form-check-label" for="mediaAppMaster"><?= htmlEscape(translate('mainSource')) ?></label>
                                                                        </div>
                                                                        <div class="form-text"><?= htmlEscape(translate('mainSourceDescription')) ?></div>
                                                                        <?php if ($hasOtherMaster) { ?>
                                                                                                        <div class="form-text text-warning"><?= htmlEscape(translate('mainSourceConvertNote')) ?></div>
                                                                        <?php } ?>
                                                                    </div>
                                                                    <div class="mb-3">
                                                                        <div class="form-check form-switch">
                                                                            <input class="form-check-input" type="checkbox" role="switch" id="mediaAppActive" <?= $active ? ' checked' : '' ?>>
                                                                            <label class="form-check-label" for="mediaAppActive"><?= htmlEscape(translate('active')) ?></label>
                                                                        </div>
                                                                    </div>
                                                                    <div class="text-center w-100 mt-3">
                                                                        <button type="button" class="btn btn-outline-success" onclick="saveMediaApp(<?= $id ?>)"><?= htmlEscape(translate('validateAndSave')) ?></button>
                                                                        <?php if ($id) { ?>
                                                                                                        <button type="button" class="btn btn-outline-danger" onclick="deleteMediaApp(<?= $id ?>)"><?= htmlEscape(translate('remove')) ?></button>
                                                                        <?php } ?>
                                                                    </div>
                                                                </div>
                                                                <?php
                                                                exit;

    case 'save':
        $result = $mediaApps->save(
            intval($_POST['id'] ?? 0),
            $_POST['name'] ?? '',
            intval($_POST['platform'] ?? 0),
            $_POST['url'] ?? '',
            $_POST['token'] ?? '',
            $_POST['apikey'] ?? '',
            !empty($_POST['master']),
            intval($_POST['syncMode'] ?? MediaSyncModes::BOTH),
            !empty($_POST['active'])
        );
        echo json_encode($result);
        exit;

    case 'toggle':
        $id = intval($_POST['id'] ?? 0);
        if (!$id || !$database->getMediaApp($id)) {
            echo json_encode(['error' => true, 'message' => translate('mediaAppNotFound')]);
            exit;
        }

        $database->setMediaAppActive($id, !empty($_POST['active']) ? 1 : 0);
        echo json_encode(['error' => false, 'message' => translate('saved')]);
        exit;

    case 'delete':
        echo json_encode($mediaApps->delete(intval($_POST['id'] ?? 0)));
        exit;

    case 'userToken':
        $userId = intval($_POST['id'] ?? 0);
        $user   = $database->getMediaAppUser($userId);
        if (!$user) {
            echo '<div class="alert alert-danger mb-0" role="alert">' . htmlEscape(translate('mediaAppNotFound')) . '</div>';
            exit;
        }
        $mediaApp = $database->getMediaApp(intval($user['media_app_id'] ?? 0));
        if (!$mediaApp || intval($mediaApp['platform']) != MediaPlatforms::PLEX) {
            echo '<div class="alert alert-danger mb-0" role="alert">' . htmlEscape(translate('mediaAppNotFound')) . '</div>';
            exit;
        }
        ?>
                                                                <div class="mb-3">
                                                                    <div class="fw-semibold mb-2"><?= htmlEscape($user['username'] ?? '') ?></div>
                                                                    <label for="mediaAppUserToken" class="form-label"><?= htmlEscape(translate('token')) ?></label>
                                                                    <input type="text" class="form-control" id="mediaAppUserToken" value="<?= htmlEscape($user['token'] ?? '') ?>" autocomplete="off">
                                                                </div>
                                                                <div class="mb-3">
                                                                    <label for="mediaAppUserPin" class="form-label"><?= htmlEscape(translate('pin')) ?></label>
                                                                    <input type="text" class="form-control" id="mediaAppUserPin" value="<?= htmlEscape($user['pin'] ?? '') ?>" autocomplete="off">
                                                                </div>
                                                                <div class="text-center">
                                                                    <button type="button" class="btn btn-secondary me-2" onclick="validateMediaAppUserToken(<?= intval($userId) ?>);"><?= htmlEscape(translate('validate')) ?></button>
                                                                    <button type="button" class="btn btn-primary" onclick="saveMediaAppUserToken(<?= intval($userId) ?>);"><?= htmlEscape(translate('save')) ?></button>
                                                                </div>
                                                                <?php
                                                                exit;

    case 'validateUserToken':
        echo json_encode($mediaApps->validatePlexUserToken(intval($_POST['id'] ?? 0), $_POST['token'] ?? '', $_POST['pin'] ?? ''));
        exit;

    case 'saveUserToken':
        $userId = intval($_POST['id'] ?? 0);
        $user   = $database->getMediaAppUser($userId);
        if (!$user) {
            echo json_encode(['error' => true, 'message' => translate('mediaAppNotFound')]);
            exit;
        }
        $mediaApp = $database->getMediaApp(intval($user['media_app_id'] ?? 0));
        if (!$mediaApp || intval($mediaApp['platform']) != MediaPlatforms::PLEX) {
            echo json_encode(['error' => true, 'message' => translate('mediaAppNotFound')]);
            exit;
        }
        $token   = trim(strval($_POST['token'] ?? ''));
        $current = trim(strval($user['token'] ?? ''));
        $pin     = trim(strval($_POST['pin'] ?? ''));
        if ($token != $current) {
            $database->updateMediaAppUserToken($userId, $token);
        }
        if ($pin != '') {
            $check = $mediaApps->plexCheckUserPin($mediaApp, $user, $pin);
            if (empty($check['ok'])) {
                $updated = $database->getMediaAppUser($userId);
                echo json_encode([
                    'error'       => true,
                    'message'     => !empty($check['rejected']) ? translate('pinInvalid') : translate('pinNotChecked'),
                    'icon_html'   => mediaAppUserTokenIconFromRow($updated),
                    'pin_cleared' => !empty($check['cleared']),
                ]);
                exit;
            }
        }
        $database->updateMediaAppUserPin($userId, $pin);
        $updated = $database->getMediaAppUser($userId);
        echo json_encode([
            'error'     => false,
            'message'   => translate('saved'),
            'has_token' => !empty($updated['has_token']),
            'icon_html' => mediaAppUserTokenIconFromRow($updated),
        ]);
        exit;

    case 'refreshUserTokens':
        echo json_encode($mediaApps->refreshPlexUserTokens(intval($_POST['id'] ?? 0)));
        exit;

    case 'users':
        $id       = intval($_POST['id'] ?? 0);
        $mediaApp = $database->getMediaApp($id);
        if (!$mediaApp) {
            echo '<div class="alert alert-danger mb-0" role="alert">' . htmlEscape(translate('mediaAppNotFound')) . '</div>';
            exit;
        }

        $result = $mediaApps->refreshUsers($id);
        $users  = [];
        $error  = '';
        if ($result['error']) {
            $error = $result['message'];
            $users = $mediaApps->visibleMediaAppUsers($mediaApp);
        } else {
            $users = [];
            foreach ($result['users'] ?? [] as $userRow) {
                if ($mediaApps->userIsDeleted($userRow)) {
                    continue;
                }
                $users[] = $userRow;
            }
        }
        $showEmail = false;
        $showType  = false;
        $showToken = intval($mediaApp['platform']) == MediaPlatforms::PLEX;
        foreach ($users as $userRow) {
            if (!empty($userRow['email'])) {
                $showEmail = true;
            }
            if (!empty($userRow['user_type'])) {
                $showType = true;
            }
        }
        $platformName = $mediaApps->getPlatformName($mediaApp['platform']);
        $platformLogo = $mediaApps->getPlatformLogo($mediaApp['platform']);
        ?>
                                                                <div class="container-fluid">
                                                                    <h3 class="h5 mb-3 d-flex align-items-center">
                                                                        <?php if ($platformLogo) { ?>
                                                                                                        <img src="<?= $platformLogo ?>" alt="<?= htmlEscape($platformName) ?>" class="me-2" style="height: 1.25em; width: auto;">
                                                                        <?php } ?>
                                                                        <?= htmlEscape($mediaApp['name']) ?>
                                                                    </h3>
                                                                    <?php if ($error) { ?>
                                                                                                    <div class="alert alert-warning" role="alert"><?= htmlEscape($error) ?></div>
                                                                    <?php } ?>
                                                                    <?php if (!$users) { ?>
                                                                                                    <p class="text-body-secondary mb-0"><?= htmlEscape(translate('noMediaAppUsers')) ?></p>
                                                                    <?php } else { ?>
                                                                                                    <table class="table table-bordered table-hover table-no-squish">
                                                                                                        <thead>
                                                                                                            <tr>
                                                                                                                <th><?= htmlEscape(translate('username')) ?></th>
                                                                                                                <?php if ($showEmail) { ?>
                                                                                                                                                <th><?= htmlEscape(translate('email')) ?></th>
                                                                                                                <?php } ?>
                                                                                                                <?php if ($showType) { ?>
                                                                                                                                                <th><?= htmlEscape(translate('userType')) ?></th>
                                                                                                                <?php } ?>
                                                                                                                <th><?= htmlEscape(translate('remoteId')) ?></th>
                                                                                                                <th><?= htmlEscape(translate('lastSeen')) ?></th>
                                                                                                                <?php if ($showToken) { ?>
                                                                                                                                                <th>
                                                                                                                                                    <?= htmlEscape(translate('token')) ?>
                                                                                                                                                    <i class="fas fa-rotate ms-1 text-body-secondary" style="cursor: pointer;" title="<?= htmlEscape(translate('refreshTokens')) ?>" onclick="refreshMediaAppUserTokens(<?= intval($id) ?>);"></i>
                                                                                                                                                </th>
                                                                                                                <?php } ?>
                                                                                                            </tr>
                                                                                                        </thead>
                                                                                                        <tbody>
                                                                                                            <?php foreach ($users as $userRow) { ?>
                                                                                                                                            <tr>
                                                                                                                                                <td><?= htmlEscape((!empty($userRow['is_admin']) ? '* ' : '') . $userRow['username']) ?></td>
                                                                                                                                                <?php if ($showEmail) { ?>
                                                                                                                                                                                <td><?= htmlEscape($userRow['email'] ?? '') ?></td>
                                                                                                                                                <?php } ?>
                                                                                                                                                <?php if ($showType) { ?>
                                                                                                                                                                                <td><?= htmlEscape(!empty($userRow['user_type']) ? translate($userRow['user_type']) : '') ?></td>
                                                                                                                                                <?php } ?>
                                                                                                                                                <td><?= htmlEscape($userRow['remote_id']) ?></td>
                                                                                                                                                <td><?= htmlEscape(!empty($userRow['last_seen']) ? date('m/d/Y', intval($userRow['last_seen'])) : '') ?></td>
                                                                                                                                                <?php if ($showToken) { ?>
                                                                                                                                                                                <td class="text-center">
                                                                                                                                                                                    <?= mediaAppUserTokenIconFromRow($userRow) ?>
                                                                                                                                                                                </td>
                                                                                                                                                <?php } ?>
                                                                                                                                            </tr>
                                                                                                            <?php } ?>
                                                                                                        </tbody>
                                                                                                    </table>
                                                                                                    <p class="small text-body-secondary mb-0"><?= htmlEscape(translate('adminAccountNote')) ?></p>
                                                                    <?php } ?>
                                                                </div>
                                                                <?php
                                                                exit;

    case 'rootFolders':
        $id       = intval($_POST['id'] ?? 0);
        $mediaApp = $database->getMediaApp($id);
        if (!$mediaApp) {
            echo '<div class="alert alert-danger mb-0" role="alert">' . htmlEscape(translate('mediaAppNotFound')) . '</div>';
            exit;
        }

        $rootFolders = $mediaApps->getRootFolders($mediaApp);
        require RELATIVE_PATH . 'pages/mediaApps/rootFolders.php';
        exit;

    default:
        echo json_encode(['error' => true, 'message' => translate('unknownSettingsEvent')]);
        exit;
}
