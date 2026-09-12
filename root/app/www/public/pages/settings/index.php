<?php

/*
----------------------------------
------  Created: 091026   ------
------  Austin Best       ------
----------------------------------
*/

$currentUsername = $userdata['username'] ?? '';

?>
<div class="row">
    <div class="col-12">
        <div class="card border shadow-sm">
            <div class="card-body">
                <h1 class="h3 mb-3"><?= htmlEscape(translate('settings')) ?></h1>
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
                <button type="button" class="btn btn-primary" onclick="saveSettings();"><?= htmlEscape(translate('saveSettings')) ?></button>
            </div>
        </div>
    </div>
</div>
