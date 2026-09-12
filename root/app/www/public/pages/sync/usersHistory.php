<?php

/*
----------------------------------
------  Created: 091126   ------
------  Austin Best       ------
----------------------------------
*/

$syncUsers = $syncUsers ?? [];
$syncMode  = $syncMode ?? MediaSyncModes::BOTH;

?>
<div class="container-fluid">
    <p class="text-body-secondary"><?= htmlEscape(translate('historyDescription')) ?></p>
    <?php if (!$syncUsers) { ?>
    <p class="text-body-secondary mb-0"><?= htmlEscape(translate('noMediaAppUsers')) ?></p>
    <?php } else { ?>
    <div class="mb-3">
        <label for="syncHistoryMode" class="form-label"><?= htmlEscape(translate('syncMode')) ?></label>
        <select class="form-select" id="syncHistoryMode">
            <option value="<?= MediaSyncModes::BOTH ?>"<?= intval($syncMode) == MediaSyncModes::BOTH ? ' selected' : '' ?>><?= htmlEscape(translate('pushAndPull')) ?></option>
            <option value="<?= MediaSyncModes::PUSH ?>"<?= intval($syncMode) == MediaSyncModes::PUSH ? ' selected' : '' ?>><?= htmlEscape(translate('pushOnly')) ?></option>
            <option value="<?= MediaSyncModes::PULL ?>"<?= intval($syncMode) == MediaSyncModes::PULL ? ' selected' : '' ?>><?= htmlEscape(translate('pullOnly')) ?></option>
        </select>
        <div class="form-text"><?= htmlEscape(translate('syncModeDescription')) ?></div>
    </div>
    <div class="mb-2">
        <div class="form-check">
            <input class="form-check-input" type="checkbox" id="syncHistoryUserAll" checked onchange="$('.sync-history-user').prop('checked', $(this).prop('checked'))">
            <label class="form-check-label" for="syncHistoryUserAll"><?= htmlEscape(translate('users')) ?></label>
        </div>
    </div>
    <?php foreach ($syncUsers as $syncUser) { ?>
    <div class="form-check">
        <input class="form-check-input sync-history-user" type="checkbox" id="syncHistoryUser-<?= intval($syncUser['id']) ?>" value="<?= intval($syncUser['id']) ?>" checked>
        <label class="form-check-label" for="syncHistoryUser-<?= intval($syncUser['id']) ?>"><?= htmlEscape((!empty($syncUser['is_admin']) ? '* ' : '') . $syncUser['username']) ?></label>
    </div>
    <?php } ?>
    <p class="small text-body-secondary mt-2"><?= htmlEscape(translate('adminAccountNote')) ?></p>
    <div class="text-center w-100 mt-3">
        <button type="button" class="btn btn-outline-success" onclick="startHistorySync()"><?= htmlEscape(translate('startSync')) ?></button>
    </div>
    <?php } ?>
</div>
