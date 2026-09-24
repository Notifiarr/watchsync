<?php

/*
----------------------------------
------  Created: 091026   ------
------  Austin Best       ------
----------------------------------
*/

$currentUsername                     = $userdata['username'] ?? '';
$appliedMigration                    = $database->appliedMigration();
$backupTime                          = normalizeBackupTime($database->getSetting('backupTime') ?: '03:00');
$backupKeep                          = $database->getSetting('backupKeep') ?: '7';
$cronLogLength                       = max(1, intval($database->getSetting('cronLogLength') ?: 1));
$systemLogLength                     = max(1, intval($database->getSetting('systemLogLength') ?: 1));
$webhookLogLength                    = max(1, intval($database->getSetting('webhookLogLength') ?: 1));
$logLevel                            = strtolower(trim(strval($database->getSetting('logLevel') ?: 'info'))) == 'debug' ? 'debug' : 'info';
$loginMode                           = normalizeLoginMode($database->getSetting('loginMode') ?: LoginModes::REQUIRED);
$allLoginSettings                    = $database->getSettings();
$loginAllowLoopback                  = array_key_exists('loginAllowLoopback', $allLoginSettings) ? ($allLoginSettings['loginAllowLoopback'] == '1') : true;
$loginAllowPrivate                   = !empty($allLoginSettings['loginAllowPrivate']) && $allLoginSettings['loginAllowPrivate'] == '1';
$loginUpstreams                      = strval($allLoginSettings['loginUpstreams'] ?? '');
$loginAuthHeader                     = normalizeLoginAuthHeader($allLoginSettings['loginAuthHeader'] ?? LOGIN_DEFAULT_AUTH_HEADER);
$loginPeerIp                         = requestPeerIp();
$loginPeerAllowed                    = loginPeerAllowed($database, $loginPeerIp);
$parityHours                         = $parityMinutes = $libraryHours = $libraryMinutes = $historyHours = $historyMinutes = 0;
list($parityHours, $parityMinutes)   = $cron->automaticIntervalParts($cron->automaticInterval('parity'));
list($libraryHours, $libraryMinutes) = $cron->automaticIntervalParts($cron->automaticInterval('library'));
list($historyHours, $historyMinutes) = $cron->automaticIntervalParts($cron->automaticInterval('history'));
$syncSettings                        = [
    'syncParityAutoUsers'     => $database->settingEnabled('syncParityAutoUsers'),
    'syncParityAutoLibraries' => $database->settingEnabled('syncParityAutoLibraries'),
    'syncLibraryAutoMeta'     => $database->settingEnabled('syncLibraryAutoMeta'),
    'syncHistoryNewUsers'     => $database->settingEnabled('syncHistoryNewUsers'),
    'syncHistoryNewLibraries' => $database->settingEnabled('syncHistoryNewLibraries'),
    'automaticParity'         => $cron->automaticEnabled('parity'),
    'automaticLibrary'        => $cron->automaticEnabled('library'),
    'automaticHistory'        => $cron->automaticEnabled('history'),
    'parityHours'             => $parityHours,
    'parityMinutes'           => $parityMinutes,
    'libraryHours'            => $libraryHours,
    'libraryMinutes'          => $libraryMinutes,
    'historyHours'            => $historyHours,
    'historyMinutes'          => $historyMinutes,
];

$intervalHourOptions   = range(0, 24);
$intervalMinuteOptions = [0, 15, 30, 45];
$browseSummary         = $database->browseTablesSummary();

?>
<div class="row">
    <div class="col-12 mb-3">
        <h1 class="h3 mb-0"><?= htmlEscape(translate('settings')) ?></h1>
    </div>
    <div class="col-12">
        <div class="card border shadow-sm">
            <div class="card-body">
                <ul class="nav nav-tabs mb-3" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active" id="settings-database-tab" data-bs-toggle="tab" data-bs-target="#settings-database" type="button" role="tab"><?= htmlEscape(translate('database')) ?></button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="settings-sync-tab" data-bs-toggle="tab" data-bs-target="#settings-sync" type="button" role="tab"><?= htmlEscape(translate('sync')) ?></button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="settings-purge-tab" data-bs-toggle="tab" data-bs-target="#settings-purge" type="button" role="tab"><?= htmlEscape(translate('purge')) ?></button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="settings-logs-tab" data-bs-toggle="tab" data-bs-target="#settings-logs" type="button" role="tab"><?= htmlEscape(translate('logs')) ?></button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="settings-login-tab" data-bs-toggle="tab" data-bs-target="#settings-login" type="button" role="tab"><?= htmlEscape(translate('login')) ?></button>
                    </li>
                </ul>
                <div class="tab-content">
                    <div class="tab-pane fade show active" id="settings-database" role="tabpanel">
                        <div class="card border mb-4">
                            <div class="card-body">
                                <h2 class="h5 mb-3"><?= htmlEscape(translate('migrations')) ?></h2>
                                <div class="text-body-secondary small"><?= htmlEscape(translate('currentMigration')) ?></div>
                                <div><?= htmlEscape($appliedMigration['number'] . ($appliedMigration['name'] != '' ? ' ' . $appliedMigration['name'] : '')) ?></div>
                            </div>
                        </div>
                        <div class="card border mb-4">
                            <div class="card-body">
                                <h2 class="h5 mb-3"><?= htmlEscape(translate('backups')) ?></h2>
                                <div class="mb-3">
                                    <label for="backupTime" class="form-label"><?= htmlEscape(translate('backupTime')) ?></label>
                                    <select class="form-select" id="backupTime" name="backupTime">
                                        <?php foreach (range(0, 23) as $hour) { ?>
                                            <?php foreach ([0, 10, 20, 30, 40, 50] as $minute) {
                                                $option = sprintf('%02d:%02d', $hour, $minute);
                                                $label  = date('g:i A', mktime($hour, $minute, 0));
                                                ?>
                                                <option value="<?= htmlEscape($option) ?>" <?= $option == $backupTime ? ' selected' : '' ?>><?= htmlEscape($label) ?></option>
                                            <?php } ?>
                                        <?php } ?>
                                    </select>
                                    <div class="form-text"><?= htmlEscape(translate('backupTimeDescription')) ?></div>
                                </div>
                                <div class="mb-3">
                                    <label for="backupKeep" class="form-label"><?= htmlEscape(translate('backupKeep')) ?></label>
                                    <input type="number" class="form-control" id="backupKeep" name="backupKeep" value="<?= htmlEscape($backupKeep) ?>">
                                    <div class="form-text"><?= htmlEscape(translate('backupKeepDescription')) ?></div>
                                </div>
                                <button type="button" class="btn btn-primary me-2" onclick="saveBackupSettings();"><?= htmlEscape(translate('saveSettings')) ?></button>
                                <button type="button" class="btn btn-outline-secondary" onclick="runBackup();"><?= htmlEscape(translate('runBackup')) ?></button>
                                <div id="backupList" class="mt-4">
                                    <?php require RELATIVE_PATH . 'pages/settings/backupList.php'; ?>
                                </div>
                            </div>
                        </div>
                        <div class="card border">
                            <div class="card-body">
                                <h2 class="h5 mb-3"><?= htmlEscape(translate('browse')) ?></h2>
                                <div id="browseDatabaseList">
                                    <?php
                                    $browseView = 'list';
                                    require RELATIVE_PATH . 'pages/settings/browseDatabase.php';
                                    ?>
                                </div>
                                <h2 class="h5 mt-4 mb-3"><?= htmlEscape(translate('query')) ?></h2>
                                <div class="input-group mb-3">
                                    <input type="text" class="form-control font-monospace" id="browseDatabaseQuery" placeholder="<?= htmlEscape(translate('browseDatabaseQueryPlaceholder')) ?>" onkeydown="if (event.key == 'Enter') { runDatabaseQuery(); }">
                                    <button type="button" class="btn btn-primary" onclick="runDatabaseQuery();"><?= htmlEscape(translate('run')) ?></button>
                                </div>
                                <div id="browseDatabaseQueryResult"></div>
                            </div>
                        </div>
                    </div>
                    <div class="tab-pane fade" id="settings-sync" role="tabpanel">
                        <h2 class="h5"><?= htmlEscape(translate('parity')) ?></h2>
                        <p class="text-body-secondary"><?= htmlEscape(translate('syncParitySettingsDescription')) ?></p>
                        <div class="form-check form-switch mb-3">
                            <input class="form-check-input" type="checkbox" role="switch" id="automaticParity" <?= !empty($syncSettings['automaticParity']) ? ' checked' : '' ?>>
                            <label class="form-check-label" for="automaticParity"><?= htmlEscape(translate('automationEnabled')) ?></label>
                            <div class="form-text"><?= htmlEscape(translate('automationEnabledDescription')) ?></div>
                        </div>
                        <div class="row g-2 align-items-end mb-3">
                            <div class="col-auto">
                                <label for="automaticParityHours" class="form-label mb-1"><?= htmlEscape(translate('intervalHours')) ?></label>
                                <select class="form-select sync-interval-hours" id="automaticParityHours" data-minutes="#automaticParityMinutes">
                                    <?php foreach ($intervalHourOptions as $hour) { ?>
                                        <option value="<?= $hour ?>" <?= intval($syncSettings['parityHours']) == $hour ? ' selected' : '' ?>><?= $hour ?></option>
                                    <?php } ?>
                                </select>
                            </div>
                            <div class="col-auto">
                                <label for="automaticParityMinutes" class="form-label mb-1"><?= htmlEscape(translate('intervalMinutes')) ?></label>
                                <select class="form-select sync-interval-minutes" id="automaticParityMinutes" <?= intval($syncSettings['parityHours']) >= 24 ? ' disabled' : '' ?>>
                                    <?php foreach ($intervalMinuteOptions as $minute) { ?>
                                        <option value="<?= $minute ?>" <?= intval($syncSettings['parityMinutes']) == $minute ? ' selected' : '' ?>><?= $minute ?></option>
                                    <?php } ?>
                                </select>
                            </div>
                        </div>
                        <div class="form-check form-switch mb-3">
                            <input class="form-check-input" type="checkbox" role="switch" id="syncParityAutoUsers" <?= !empty($syncSettings['syncParityAutoUsers']) ? ' checked' : '' ?>>
                            <label class="form-check-label" for="syncParityAutoUsers"><?= htmlEscape(translate('syncParityAutoUsers')) ?></label>
                            <div class="form-text"><?= htmlEscape(translate('syncParityAutoUsersDescription')) ?></div>
                        </div>
                        <div class="form-check form-switch mb-4">
                            <input class="form-check-input" type="checkbox" role="switch" id="syncParityAutoLibraries" <?= !empty($syncSettings['syncParityAutoLibraries']) ? ' checked' : '' ?>>
                            <label class="form-check-label" for="syncParityAutoLibraries"><?= htmlEscape(translate('syncParityAutoLibraries')) ?></label>
                            <div class="form-text"><?= htmlEscape(translate('syncParityAutoLibrariesDescription')) ?></div>
                        </div>
                        <h2 class="h5"><?= htmlEscape(translate('library')) ?></h2>
                        <p class="text-body-secondary"><?= htmlEscape(translate('syncLibrarySettingsDescription')) ?></p>
                        <div class="form-check form-switch mb-3">
                            <input class="form-check-input" type="checkbox" role="switch" id="automaticLibrary" <?= !empty($syncSettings['automaticLibrary']) ? ' checked' : '' ?>>
                            <label class="form-check-label" for="automaticLibrary"><?= htmlEscape(translate('automationEnabled')) ?></label>
                            <div class="form-text"><?= htmlEscape(translate('automationEnabledDescription')) ?></div>
                        </div>
                        <div class="row g-2 align-items-end mb-3">
                            <div class="col-auto">
                                <label for="automaticLibraryHours" class="form-label mb-1"><?= htmlEscape(translate('intervalHours')) ?></label>
                                <select class="form-select sync-interval-hours" id="automaticLibraryHours" data-minutes="#automaticLibraryMinutes">
                                    <?php foreach ($intervalHourOptions as $hour) { ?>
                                        <option value="<?= $hour ?>" <?= intval($syncSettings['libraryHours']) == $hour ? ' selected' : '' ?>><?= $hour ?></option>
                                    <?php } ?>
                                </select>
                            </div>
                            <div class="col-auto">
                                <label for="automaticLibraryMinutes" class="form-label mb-1"><?= htmlEscape(translate('intervalMinutes')) ?></label>
                                <select class="form-select sync-interval-minutes" id="automaticLibraryMinutes" <?= intval($syncSettings['libraryHours']) >= 24 ? ' disabled' : '' ?>>
                                    <?php foreach ($intervalMinuteOptions as $minute) { ?>
                                        <option value="<?= $minute ?>" <?= intval($syncSettings['libraryMinutes']) == $minute ? ' selected' : '' ?>><?= $minute ?></option>
                                    <?php } ?>
                                </select>
                            </div>
                        </div>
                        <div class="form-check form-switch mb-4">
                            <input class="form-check-input" type="checkbox" role="switch" id="syncLibraryAutoMeta" <?= !empty($syncSettings['syncLibraryAutoMeta']) ? ' checked' : '' ?>>
                            <label class="form-check-label" for="syncLibraryAutoMeta"><?= htmlEscape(translate('syncLibraryAutoMeta')) ?></label>
                            <div class="form-text"><?= htmlEscape(translate('syncLibraryAutoMetaDescription')) ?></div>
                        </div>
                        <h2 class="h5"><?= htmlEscape(translate('history')) ?></h2>
                        <p class="text-body-secondary"><?= htmlEscape(translate('syncHistorySettingsDescription')) ?></p>
                        <div class="form-check form-switch mb-3">
                            <input class="form-check-input" type="checkbox" role="switch" id="automaticHistory" <?= !empty($syncSettings['automaticHistory']) ? ' checked' : '' ?>>
                            <label class="form-check-label" for="automaticHistory"><?= htmlEscape(translate('automationEnabled')) ?></label>
                            <div class="form-text"><?= htmlEscape(translate('automationEnabledDescription')) ?></div>
                        </div>
                        <div class="row g-2 align-items-end mb-3">
                            <div class="col-auto">
                                <label for="automaticHistoryHours" class="form-label mb-1"><?= htmlEscape(translate('intervalHours')) ?></label>
                                <select class="form-select sync-interval-hours" id="automaticHistoryHours" data-minutes="#automaticHistoryMinutes">
                                    <?php foreach ($intervalHourOptions as $hour) { ?>
                                        <option value="<?= $hour ?>" <?= intval($syncSettings['historyHours']) == $hour ? ' selected' : '' ?>><?= $hour ?></option>
                                    <?php } ?>
                                </select>
                            </div>
                            <div class="col-auto">
                                <label for="automaticHistoryMinutes" class="form-label mb-1"><?= htmlEscape(translate('intervalMinutes')) ?></label>
                                <select class="form-select sync-interval-minutes" id="automaticHistoryMinutes" <?= intval($syncSettings['historyHours']) >= 24 ? ' disabled' : '' ?>>
                                    <?php foreach ($intervalMinuteOptions as $minute) { ?>
                                        <option value="<?= $minute ?>" <?= intval($syncSettings['historyMinutes']) == $minute ? ' selected' : '' ?>><?= $minute ?></option>
                                    <?php } ?>
                                </select>
                            </div>
                        </div>
                        <div class="form-check form-switch mb-3">
                            <input class="form-check-input" type="checkbox" role="switch" id="syncHistoryNewUsers" <?= !empty($syncSettings['syncHistoryNewUsers']) ? ' checked' : '' ?>>
                            <label class="form-check-label" for="syncHistoryNewUsers"><?= htmlEscape(translate('syncHistoryNewUsers')) ?></label>
                            <div class="form-text"><?= htmlEscape(translate('syncHistoryNewUsersDescription')) ?></div>
                        </div>
                        <div class="form-check form-switch mb-4">
                            <input class="form-check-input" type="checkbox" role="switch" id="syncHistoryNewLibraries" <?= !empty($syncSettings['syncHistoryNewLibraries']) ? ' checked' : '' ?>>
                            <label class="form-check-label" for="syncHistoryNewLibraries"><?= htmlEscape(translate('syncHistoryNewLibraries')) ?></label>
                            <div class="form-text"><?= htmlEscape(translate('syncHistoryNewLibrariesDescription')) ?></div>
                        </div>
                        <button type="button" class="btn btn-primary" onclick="saveSyncSettings();"><?= htmlEscape(translate('saveSettings')) ?></button>
                    </div>
                    <div class="tab-pane fade" id="settings-purge" role="tabpanel">
                        <ul class="nav nav-pills mb-3" role="tablist">
                            <li class="nav-item" role="presentation">
                                <button class="nav-link active" id="settings-purge-library-tab" data-bs-toggle="tab" data-bs-target="#settings-purge-library" type="button" role="tab"><?= htmlEscape(translate('library')) ?></button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" id="settings-purge-history-tab" data-bs-toggle="tab" data-bs-target="#settings-purge-history" type="button" role="tab"><?= htmlEscape(translate('history')) ?></button>
                            </li>
                        </ul>
                        <div class="tab-content">
                            <div class="tab-pane fade show active" id="settings-purge-library" role="tabpanel">
                                <p class="text-body-secondary"><?= htmlEscape(translate('deleteLocalLibraryDescription')) ?></p>
                                <div id="resetLibraryList">
                                    <?php require RELATIVE_PATH . 'pages/settings/resetLibrary.php'; ?>
                                </div>
                                <button type="button" class="btn btn-outline-danger mt-3" onclick="deleteLocalLibraries();"><?= htmlEscape(translate('deleteLocalLibraries')) ?></button>
                            </div>
                            <div class="tab-pane fade" id="settings-purge-history" role="tabpanel">
                                <p class="text-body-secondary"><?= htmlEscape(translate('resetHistoryDescription')) ?></p>
                                <div id="resetUserList">
                                    <?php require RELATIVE_PATH . 'pages/settings/reset.php'; ?>
                                </div>
                                <button type="button" class="btn btn-outline-danger mt-3" onclick="resetWatchHistory();"><?= htmlEscape(translate('resetHistory')) ?></button>
                            </div>
                        </div>
                    </div>
                    <div class="tab-pane fade" id="settings-logs" role="tabpanel">
                        <div class="mb-3">
                            <label for="logLevel" class="form-label"><?= htmlEscape(translate('logLevel')) ?></label>
                            <select class="form-select" id="logLevel">
                                <option value="info" <?= $logLevel == 'info' ? ' selected' : '' ?>><?= htmlEscape(translate('logLevelInfo')) ?></option>
                                <option value="debug" <?= $logLevel == 'debug' ? ' selected' : '' ?>><?= htmlEscape(translate('logLevelDebug')) ?></option>
                            </select>
                            <div class="form-text"><?= htmlEscape(translate('logLevelDescription')) ?></div>
                        </div>
                        <div class="mb-3">
                            <label for="cronLogLength" class="form-label"><?= htmlEscape(translate('cronLogLength')) ?></label>
                            <input type="number" class="form-control" id="cronLogLength" min="1" value="<?= intval($cronLogLength) ?>">
                            <div class="form-text"><?= htmlEscape(translate('cronLogLengthDescription')) ?></div>
                        </div>
                        <div class="mb-3">
                            <label for="systemLogLength" class="form-label"><?= htmlEscape(translate('systemLogLength')) ?></label>
                            <input type="number" class="form-control" id="systemLogLength" min="1" value="<?= intval($systemLogLength) ?>">
                            <div class="form-text"><?= htmlEscape(translate('systemLogLengthDescription')) ?></div>
                        </div>
                        <div class="mb-3">
                            <label for="webhookLogLength" class="form-label"><?= htmlEscape(translate('webhookLogLength')) ?></label>
                            <input type="number" class="form-control" id="webhookLogLength" min="1" value="<?= intval($webhookLogLength) ?>">
                            <div class="form-text"><?= htmlEscape(translate('webhookLogLengthDescription')) ?></div>
                        </div>
                        <p class="text-body-secondary small"><?= htmlEscape(translate('logRotateDescription', [LOG_ROTATE_SIZE])) ?></p>
                        <button type="button" class="btn btn-primary" onclick="saveLogSettings();"><?= htmlEscape(translate('saveSettings')) ?></button>
                    </div>
                    <div class="tab-pane fade" id="settings-login" role="tabpanel">
                        <div class="mb-3">
                            <label for="loginMode" class="form-label"><?= htmlEscape(translate('loginSetting')) ?></label>
                            <select class="form-select" id="loginMode" onchange="toggleLoginTrustSettings();">
                                <option value="<?= htmlEscape(LoginModes::REQUIRED) ?>" <?= $loginMode == LoginModes::REQUIRED ? ' selected' : '' ?>><?= htmlEscape(translate('loginRequired')) ?></option>
                                <option value="<?= htmlEscape(LoginModes::OFF) ?>" <?= $loginMode == LoginModes::OFF ? ' selected' : '' ?>><?= htmlEscape(translate('loginOff')) ?></option>
                                <option value="<?= htmlEscape(LoginModes::BYPASS) ?>" <?= $loginMode == LoginModes::BYPASS ? ' selected' : '' ?>><?= htmlEscape(translate('loginBypass')) ?></option>
                            </select>
                            <div class="form-text"><?= htmlEscape(translate('loginSettingDescription')) ?></div>
                        </div>
                        <div id="loginTrustSettings" class="mb-4" <?= $loginMode == LoginModes::BYPASS ? '' : ' style="display:none;"' ?>>
                            <h2 class="h5 mb-3"><?= htmlEscape(translate('loginUpstreams')) ?></h2>
                            <div class="form-text mb-3"><?= htmlEscape(translate('loginUpstreamsDescription')) ?></div>
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="checkbox" id="loginAllowLoopback" <?= $loginAllowLoopback ? ' checked' : '' ?>>
                                <label class="form-check-label" for="loginAllowLoopback"><?= htmlEscape(translate('loginAllowLoopback')) ?></label>
                                <div class="form-text"><?= htmlEscape(translate('loginAllowLoopbackDescription')) ?></div>
                            </div>
                            <div class="form-check mb-3">
                                <input class="form-check-input" type="checkbox" id="loginAllowPrivate" <?= $loginAllowPrivate ? ' checked' : '' ?>>
                                <label class="form-check-label" for="loginAllowPrivate"><?= htmlEscape(translate('loginAllowPrivate')) ?></label>
                                <div class="form-text"><?= htmlEscape(translate('loginAllowPrivateDescription')) ?></div>
                            </div>
                            <div class="mb-3">
                                <label for="loginUpstreams" class="form-label"><?= htmlEscape(translate('loginCustomUpstreams')) ?></label>
                                <textarea class="form-control font-monospace" id="loginUpstreams" rows="3" placeholder="127.0.0.1/32&#10;10.0.0.0/8"><?= htmlEscape($loginUpstreams) ?></textarea>
                                <div class="form-text"><?= htmlEscape(translate('loginCustomUpstreamsDescription')) ?></div>
                            </div>
                            <div class="mb-3">
                                <div class="form-text"><?= htmlEscape(translate('loginCurrentConnection')) ?>: <span class="font-monospace"><?= htmlEscape($loginPeerIp != '' ? $loginPeerIp : translate('unknown')) ?></span>
                                    <?php if ($loginPeerAllowed) { ?>
                                        <span class="text-success">(<?= htmlEscape(translate('loginPeerAllowed')) ?>)</span>
                                    <?php } else { ?>
                                        <span class="text-warning">(<?= htmlEscape(translate('loginPeerNotAllowed')) ?>)</span>
                                    <?php } ?>
                                </div>
                            </div>
                            <div class="mb-3">
                                <label for="loginAuthHeader" class="form-label"><?= htmlEscape(translate('loginAuthHeader')) ?></label>
                                <input type="text" class="form-control font-monospace" id="loginAuthHeader" value="<?= htmlEscape($loginAuthHeader) ?>" placeholder="<?= htmlEscape(LOGIN_DEFAULT_AUTH_HEADER) ?>">
                                <div class="form-text"><?= htmlEscape(translate('loginAuthHeaderDescription')) ?></div>
                            </div>
                        </div>
                        <h2 class="h5 mb-3"><?= htmlEscape(translate('credentials')) ?></h2>
                        <div class="mb-3">
                            <label for="userSettingsUsername" class="form-label"><?= htmlEscape(translate('username')) ?></label>
                            <input type="text" class="form-control" id="userSettingsUsername" name="username" value="<?= htmlEscape($currentUsername) ?>" autocomplete="username">
                        </div>
                        <div class="mb-3">
                            <label for="userSettingsCurrentPassword" class="form-label"><?= htmlEscape(translate('currentPassword')) ?></label>
                            <input type="password" class="form-control" id="userSettingsCurrentPassword" name="current_password" value="" autocomplete="current-password">
                        </div>
                        <div class="mb-3">
                            <label for="userSettingsNewPassword" class="form-label"><?= htmlEscape(translate('newPassword')) ?></label>
                            <input type="password" class="form-control" id="userSettingsNewPassword" name="new_password" value="" autocomplete="new-password">
                        </div>
                        <div class="mb-3">
                            <label for="userSettingsNewPasswordConfirm" class="form-label"><?= htmlEscape(translate('confirmNewPassword')) ?></label>
                            <input type="password" class="form-control" id="userSettingsNewPasswordConfirm" name="new_password_confirm" value="" autocomplete="new-password">
                        </div>
                        <button type="button" class="btn btn-primary" onclick="saveLoginSettings();"><?= htmlEscape(translate('saveSettings')) ?></button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>