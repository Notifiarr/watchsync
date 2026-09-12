<?php

/*
----------------------------------
------  Created: 091026   ------
------  Austin Best       ------
----------------------------------
*/

$currentUsername = $userdata['username'] ?? '';
$backupTime      = normalizeBackupTime($database->getSetting('backupTime') ?: '03:00');
$backupKeep      = $database->getSetting('backupKeep') ?: '7';
$syncSettings    = [
    'syncParityAutoUsers'     => $database->settingEnabled('syncParityAutoUsers'),
    'syncParityAutoLibraries' => $database->settingEnabled('syncParityAutoLibraries'),
    'syncLibraryAutoMeta'     => $database->settingEnabled('syncLibraryAutoMeta'),
    'syncHistoryNewUsers'     => $database->settingEnabled('syncHistoryNewUsers'),
    'syncHistoryNewLibraries' => $database->settingEnabled('syncHistoryNewLibraries'),
];

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
                        <button class="nav-link active" id="settings-backups-tab" data-bs-toggle="tab" data-bs-target="#settings-backups" type="button" role="tab"><?= htmlEscape(translate('backups')) ?></button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="settings-sync-tab" data-bs-toggle="tab" data-bs-target="#settings-sync" type="button" role="tab"><?= htmlEscape(translate('sync')) ?></button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="settings-reset-tab" data-bs-toggle="tab" data-bs-target="#settings-reset" type="button" role="tab"><?= htmlEscape(translate('reset')) ?></button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="settings-login-tab" data-bs-toggle="tab" data-bs-target="#settings-login" type="button" role="tab"><?= htmlEscape(translate('login')) ?></button>
                    </li>
                </ul>
                <div class="tab-content">
                    <div class="tab-pane fade show active" id="settings-backups" role="tabpanel">
                        <div class="mb-3">
                            <label for="backupTime" class="form-label"><?= htmlEscape(translate('backupTime')) ?></label>
                            <select class="form-select" id="backupTime" name="backupTime">
                                <?php foreach (range(0, 23) as $hour) { ?>
                                    <?php foreach ([0, 10, 20, 30, 40, 50] as $minute) {
                                        $option = sprintf('%02d:%02d', $hour, $minute);
                                        $label  = date('g:i A', mktime($hour, $minute, 0));
                                        ?>
                                        <option value="<?= htmlEscape($option) ?>"<?= $option == $backupTime ? ' selected' : '' ?>><?= htmlEscape($label) ?></option>
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
                    <div class="tab-pane fade" id="settings-sync" role="tabpanel">
                        <h2 class="h5"><?= htmlEscape(translate('parity')) ?></h2>
                        <p class="text-body-secondary"><?= htmlEscape(translate('syncParitySettingsDescription')) ?></p>
                        <div class="form-check form-switch mb-3">
                            <input class="form-check-input" type="checkbox" role="switch" id="syncParityAutoUsers"<?= !empty($syncSettings['syncParityAutoUsers']) ? ' checked' : '' ?>>
                            <label class="form-check-label" for="syncParityAutoUsers"><?= htmlEscape(translate('syncParityAutoUsers')) ?></label>
                            <div class="form-text"><?= htmlEscape(translate('syncParityAutoUsersDescription')) ?></div>
                        </div>
                        <div class="form-check form-switch mb-4">
                            <input class="form-check-input" type="checkbox" role="switch" id="syncParityAutoLibraries"<?= !empty($syncSettings['syncParityAutoLibraries']) ? ' checked' : '' ?>>
                            <label class="form-check-label" for="syncParityAutoLibraries"><?= htmlEscape(translate('syncParityAutoLibraries')) ?></label>
                            <div class="form-text"><?= htmlEscape(translate('syncParityAutoLibrariesDescription')) ?></div>
                        </div>
                        <h2 class="h5"><?= htmlEscape(translate('libraries')) ?></h2>
                        <p class="text-body-secondary"><?= htmlEscape(translate('syncLibrarySettingsDescription')) ?></p>
                        <div class="form-check form-switch mb-4">
                            <input class="form-check-input" type="checkbox" role="switch" id="syncLibraryAutoMeta"<?= !empty($syncSettings['syncLibraryAutoMeta']) ? ' checked' : '' ?>>
                            <label class="form-check-label" for="syncLibraryAutoMeta"><?= htmlEscape(translate('syncLibraryAutoMeta')) ?></label>
                            <div class="form-text"><?= htmlEscape(translate('syncLibraryAutoMetaDescription')) ?></div>
                        </div>
                        <h2 class="h5"><?= htmlEscape(translate('history')) ?></h2>
                        <p class="text-body-secondary"><?= htmlEscape(translate('syncHistorySettingsDescription')) ?></p>
                        <div class="form-check form-switch mb-3">
                            <input class="form-check-input" type="checkbox" role="switch" id="syncHistoryNewUsers"<?= !empty($syncSettings['syncHistoryNewUsers']) ? ' checked' : '' ?>>
                            <label class="form-check-label" for="syncHistoryNewUsers"><?= htmlEscape(translate('syncHistoryNewUsers')) ?></label>
                            <div class="form-text"><?= htmlEscape(translate('syncHistoryNewUsersDescription')) ?></div>
                        </div>
                        <div class="form-check form-switch mb-4">
                            <input class="form-check-input" type="checkbox" role="switch" id="syncHistoryNewLibraries"<?= !empty($syncSettings['syncHistoryNewLibraries']) ? ' checked' : '' ?>>
                            <label class="form-check-label" for="syncHistoryNewLibraries"><?= htmlEscape(translate('syncHistoryNewLibraries')) ?></label>
                            <div class="form-text"><?= htmlEscape(translate('syncHistoryNewLibrariesDescription')) ?></div>
                        </div>
                        <button type="button" class="btn btn-primary" onclick="saveSyncSettings();"><?= htmlEscape(translate('saveSettings')) ?></button>
                    </div>
                    <div class="tab-pane fade" id="settings-reset" role="tabpanel">
                        <p class="text-body-secondary"><?= htmlEscape(translate('resetHistoryDescription')) ?></p>
                        <div id="resetUserList">
                            <?php require RELATIVE_PATH . 'pages/settings/reset.php'; ?>
                        </div>
                        <button type="button" class="btn btn-outline-danger mt-3" onclick="resetWatchHistory();"><?= htmlEscape(translate('resetHistory')) ?></button>
                    </div>
                    <div class="tab-pane fade" id="settings-login" role="tabpanel">
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
