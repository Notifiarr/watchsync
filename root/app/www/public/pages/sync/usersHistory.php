<?php

/*
----------------------------------
------  Created: 091126   ------
------  Austin Best       ------
----------------------------------
*/

$syncUsers   = $syncUsers ?? [];
$syncApps    = $syncApps ?? [];
$syncMode    = $syncMode ?? MediaSyncModes::BOTH;
$canSync     = !empty($canSync);
$canSyncBoth = !empty($canSyncBoth);
$hasLibrary  = !empty($hasLibrary);
if (!$canSyncBoth && intval($syncMode) == MediaSyncModes::BOTH) {
    $syncMode = MediaSyncModes::PULL;
}
$syncDisabledTitle = !$hasLibrary ? translate('historyNeedsLibraryData') : translate('offline');
?>
<div class="container-fluid">
    <p class="text-body-secondary"><?= htmlEscape(translate('historyDescription')) ?></p>
    <?php if (!$syncUsers) { ?>
                            <p class="text-body-secondary mb-0"><?= htmlEscape(translate('noMediaAppUsers')) ?></p>
    <?php } else { ?>
                            <div class="mb-3">
                                <label for="syncHistoryMode" class="form-label"><?= htmlEscape(translate('syncMode')) ?></label>
                                <select class="form-select" id="syncHistoryMode">
                                    <?php if ($canSyncBoth) { ?>
                                                            <option value="<?= MediaSyncModes::BOTH ?>"<?= intval($syncMode) == MediaSyncModes::BOTH ? ' selected' : '' ?>><?= htmlEscape(translate('pushAndPull')) ?></option>
                                    <?php } ?>
                                    <option value="<?= MediaSyncModes::PUSH ?>"<?= intval($syncMode) == MediaSyncModes::PUSH ? ' selected' : '' ?>><?= htmlEscape(translate('pushOnly')) ?></option>
                                    <option value="<?= MediaSyncModes::PULL ?>"<?= intval($syncMode) == MediaSyncModes::PULL ? ' selected' : '' ?>><?= htmlEscape(translate('pullOnly')) ?></option>
                                </select>
                            </div>
                            <div class="row">
                                <div class="col">
                                    <div class="form-check mb-2">
                                        <input class="form-check-input" type="checkbox" id="syncHistoryUserAll" checked onchange="$('.sync-history-user').prop('checked', $(this).prop('checked'))">
                                        <label class="form-check-label" for="syncHistoryUserAll"><?= htmlEscape(translate('users')) ?></label>
                                    </div>
                                    <?php foreach ($syncUsers as $syncUser) { ?>
                                                            <div class="form-check">
                                                                <input class="form-check-input sync-history-user" type="checkbox" id="syncHistoryUser-<?= intval($syncUser['id']) ?>" value="<?= intval($syncUser['id']) ?>" checked>
                                                                <label class="form-check-label" for="syncHistoryUser-<?= intval($syncUser['id']) ?>"><?= htmlEscape((!empty($syncUser['is_admin']) ? '* ' : '') . $syncUser['username']) ?></label>
                                                            </div>
                                    <?php } ?>
                                </div>
                                <?php foreach ($syncApps as $syncApp) {
                                    $platformName = $mediaApps->getPlatformName($syncApp['platform']);
                                    $platformLogo = $mediaApps->getPlatformLogo($syncApp['platform']);
                                    $online       = !empty($syncApp['online']);
                                    ?>
                                                        <div class="col">
                                                            <h3 class="h6 d-flex align-items-center mb-2">
                                                                <?php if ($platformLogo) { ?>
                                                                                        <img src="<?= $platformLogo ?>" alt="<?= htmlEscape($platformName) ?>" class="me-2" style="height: 1.25em; width: auto;">
                                                                <?php } ?>
                                                                <?= htmlEscape($syncApp['name']) ?> <span class="badge text-bg-<?= $online ? 'success' : 'danger' ?> ms-1"><?= htmlEscape(translate($online ? 'online' : 'offline')) ?></span>
                                                            </h3>
                                                            <?php foreach ($syncUsers as $syncUser) {
                                                                $watch = $syncUser['watch'][intval($syncApp['id'])] ?? [];
                                                                ?>
                                                                                    <div class="form-check">
                                                                                        <span class="form-check-label text-body-secondary small"><?= htmlEscape(translate('userWatchCount', [intval($watch['movies'] ?? 0), intval($watch['episodes'] ?? 0)])) ?></span>
                                                                                    </div>
                                                            <?php } ?>
                                                        </div>
                                <?php } ?>
                            </div>
                            <p class="small text-body-secondary mt-2"><?= htmlEscape(translate('adminAccountNote')) ?></p>
                            <div class="text-center w-100 mt-3">
                                <button type="button" class="btn btn-outline-secondary me-2"<?php if ($canSync) { ?> onclick="startHistorySync(true)"<?php } else { ?> disabled title="<?= htmlEscape($syncDisabledTitle) ?>"<?php } ?>><?= htmlEscape(translate('dryRun')) ?></button>
                                <button type="button" class="btn btn-outline-success"<?php if ($canSync) { ?> onclick="startHistorySync(false)"<?php } else { ?> disabled title="<?= htmlEscape($syncDisabledTitle) ?>"<?php } ?>><?= htmlEscape(translate('startSync')) ?></button>
                            </div>
    <?php } ?>
</div>
