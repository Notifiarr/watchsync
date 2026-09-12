<?php

/*
----------------------------------
------  Created: 091126   ------
------  Austin Best       ------
----------------------------------
*/

$syncUsers = $syncUsers ?? [];
$rowCount  = 0;
$canSync   = !empty($canSync);
foreach ($syncUsers as $syncApp) {
    $rowCount = max($rowCount, count($syncApp['users'] ?? []));
}

?>
    <p class="small text-body-secondary mb-2"><?= htmlEscape(translate('parityLinkHint')) ?></p>
    <?php if (!$syncUsers) { ?>
                            <p class="text-body-secondary mb-0"><?= htmlEscape(translate('noMediaAppUsers')) ?></p>
    <?php } else { ?>
                            <div class="table-responsive">
                                <table class="table table-bordered parity-table">
                                    <thead>
                                        <tr>
                                            <?php foreach ($syncUsers as $syncApp) {
                                                $platformName   = $mediaApps->getPlatformName($syncApp['platform']);
                                                $platformLogo   = $mediaApps->getPlatformLogo($syncApp['platform']);
                                                $isMaster       = intval($syncApp['role']) == MediaAppRoles::MASTER;
                                                $online         = !empty($syncApp['online']);
                                                $appUserTotal   = 0;
                                                $appUserChecked = 0;
                                                foreach ($syncApp['users'] as $syncUser) {
                                                    if (empty($syncUser['id'])) {
                                                        continue;
                                                    }
                                                    $appUserTotal++;
                                                    if (!empty($syncUser['sync'])) {
                                                        $appUserChecked++;
                                                    }
                                                }
                                                ?>
                                                                <th>
                                                                    <div class="d-flex align-items-center">
                                                                        <?php if ($platformLogo) { ?>
                                                                                                <img src="<?= $platformLogo ?>" alt="<?= htmlEscape($platformName) ?>" class="me-2" style="height: 1.25em; width: auto;">
                                                                        <?php } ?>
                                                                        <?php if ($isMaster && $appUserTotal && $online) { ?>
                                                                                                <input class="form-check-input me-2 parity-select-all" type="checkbox" id="syncUserAll-<?= intval($syncApp['id']) ?>" data-kind="user" data-app="<?= intval($syncApp['id']) ?>"<?= $appUserChecked && $appUserChecked == $appUserTotal ? ' checked' : '' ?>>
                                                                                                <label class="form-check-label mb-0" for="syncUserAll-<?= intval($syncApp['id']) ?>"><?= htmlEscape($syncApp['name']) ?></label>
                                                                                                <span class="badge text-bg-success ms-1"><?= htmlEscape(translate('online')) ?></span>
                                                                        <?php } else { ?>
                                                                                                <?= htmlEscape($syncApp['name']) ?> <span class="badge text-bg-<?= $online ? 'success' : 'danger' ?> ms-1"><?= htmlEscape(translate($online ? 'online' : 'offline')) ?></span>
                                                                        <?php } ?>
                                                                    </div>
                                                                </th>
                                            <?php } ?>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php for ($index = 0; $index < $rowCount; $index++) { ?>
                                                                <tr>
                                                                    <?php foreach ($syncUsers as $syncApp) {
                                                                        $isMaster = intval($syncApp['role']) == MediaAppRoles::MASTER;
                                                                        $online   = !empty($syncApp['online']);
                                                                        $syncUser = $syncApp['users'][$index] ?? [];
                                                                        if (empty($syncUser['id'])) {
                                                                            ?>
                                                                                                                <td class="parity-item parity-item-empty" data-sort="<?= intval($index) ?>"></td>
                                                                                                            <?php
                                                                                                            continue;
                                                                        }
                                                                        $linkId = intval($syncUser['link_id'] ?? 0);
                                                                        ?>
                                                                                            <td class="parity-item" style="cursor: pointer;" data-kind="user" data-app="<?= intval($syncApp['id']) ?>" data-id="<?= intval($syncUser['id']) ?>" data-master="<?= $isMaster ? '1' : '0' ?>" data-link="<?= $linkId ?>" data-sort="<?= intval($index) ?>"<?= $online ? '' : ' data-offline="1"' ?>>
                                                                                                <div class="parity-item-content">
                                                                                                    <div class="parity-check"><?php if ($isMaster && $online) { ?>
                                                                                                                        <div class="form-check d-inline-block mb-0">
                                                                                                                            <input class="form-check-input sync-user" type="checkbox" id="syncUser-<?= intval($syncUser['id']) ?>" value="<?= intval($syncUser['id']) ?>"<?= !empty($syncUser['sync']) ? ' checked' : '' ?>>
                                                                                                                        </div>
                                                                                                    <?php } ?></div>
                                                                                                    <span class="parity-name"><?= htmlEscape((!empty($syncUser['is_admin']) ? '* ' : '') . $syncUser['username']) ?></span><?php if (($syncUser['libraries'] ?? '') != '') { ?><span class="text-body-secondary small ms-1">(<?= htmlEscape(translate('userLibraryCount', [$syncUser['libraries'] == 'all' ? translate('all') : intval($syncUser['libraries'])])) ?>)</span><?php } ?><?php foreach ($syncUser['links'] ?? [] as $linkIcon) { ?>
                                                                                                                            <i class="fas fa-link small parity-unlink ms-1" style="color: <?= htmlEscape($linkIcon['color']) ?>;" data-app="<?= intval($linkIcon['app']) ?>" data-id="<?= htmlEscape($linkIcon['id']) ?>" title="<?= htmlEscape(translate('unlink')) ?>"></i><?php } ?>
                                                                                                </div>
                                                                                            </td>
                                                                    <?php } ?>
                                                                </tr>
                                        <?php } ?>
                                    </tbody>
                                </table>
                            </div>
                            <p class="small text-body-secondary mt-2"><?= htmlEscape(translate('adminAccountNote')) ?></p>
                            <div class="text-center w-100 mt-3">
                                <button type="button" class="btn btn-outline-primary me-2" onclick="saveParityUsers()"><?= htmlEscape(translate('saveSyncUsers')) ?></button>
                                <button type="button" class="btn btn-outline-<?= $canSync ? 'success' : 'danger' ?>"<?php if ($canSync) { ?> onclick="startUsersSync()"<?php } else { ?> disabled title="<?= htmlEscape(translate('needOnlineMediaApps')) ?>"<?php } ?>><?= htmlEscape(translate('startSync')) ?></button>
                            </div>
    <?php } ?>
