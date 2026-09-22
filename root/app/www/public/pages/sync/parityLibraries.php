<?php

/*
----------------------------------
------  Created: 091226   ------
------  Austin Best       ------
----------------------------------
*/

$syncLibraries = $syncLibraries ?? [];
$canSync       = !empty($canSync);
$rowCount      = 0;
foreach ($syncLibraries as $syncApp) {
    $rowCount = max($rowCount, count($syncApp['libraries'] ?? []));
}

?>
<p class="small text-body-secondary mb-2"><?= htmlEscape(translate('parityLibraryLinkHint')) ?></p>
<?php if (!$syncLibraries) { ?>
    <p class="text-body-secondary mb-0"><?= htmlEscape(translate('noLibraries')) ?></p>
<?php } else { ?>
    <div class="table-responsive">
        <table class="table table-bordered parity-table">
            <thead>
                <tr>
                    <?php foreach ($syncLibraries as $syncApp) {
                        $platformName      = $mediaApps->getPlatformName($syncApp['platform']);
                        $platformLogo      = $mediaApps->getPlatformLogo($syncApp['platform']);
                        $isMaster          = intval($syncApp['role']) == MediaAppRoles::MASTER;
                        $online            = !empty($syncApp['online']);
                        $appLibraryTotal   = 0;
                        $appLibraryChecked = 0;
                        foreach ($syncApp['libraries'] as $syncLibrary) {
                            if (($syncLibrary['key'] ?? '') == '') {
                                continue;
                            }
                            $appLibraryTotal++;
                            if (!empty($syncLibrary['sync'])) {
                                $appLibraryChecked++;
                            }
                        }
                        ?>
                        <th>
                            <div class="d-flex align-items-center">
                                <?php if ($platformLogo) { ?>
                                    <img src="<?= $platformLogo ?>" alt="<?= htmlEscape($platformName) ?>" class="me-2" style="height: 1.25em; width: auto;">
                                <?php } ?>
                                <?php if ($isMaster && $appLibraryTotal && $online) { ?>
                                    <input class="form-check-input me-2 parity-select-all" type="checkbox" id="syncParityLibraryAll-<?= intval($syncApp['id']) ?>" data-type="library" data-app="<?= intval($syncApp['id']) ?>" <?= $appLibraryChecked && $appLibraryChecked == $appLibraryTotal ? ' checked' : '' ?>>
                                    <label class="form-check-label mb-0" for="syncParityLibraryAll-<?= intval($syncApp['id']) ?>"><?= htmlEscape($syncApp['name']) ?></label>
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
                        <?php foreach ($syncLibraries as $syncApp) {
                            $isMaster    = intval($syncApp['role']) == MediaAppRoles::MASTER;
                            $online      = !empty($syncApp['online']);
                            $syncLibrary = $syncApp['libraries'][$index] ?? [];
                            if (($syncLibrary['key'] ?? '') == '') {
                                ?>
                                <td class="parity-item parity-item-empty" data-sort="<?= intval($index) ?>"></td>
                                <?php
                                continue;
                            }
                            $linkId = $syncLibrary['link_id'] ?? '';
                            ?>
                            <td class="parity-item" style="cursor: pointer;" data-type="library" data-app="<?= intval($syncApp['id']) ?>" data-id="<?= htmlEscape($syncLibrary['key']) ?>" data-master="<?= $isMaster ? '1' : '0' ?>" data-link="<?= htmlEscape($linkId) ?>" data-sort="<?= intval($index) ?>" <?= $online ? '' : ' data-offline="1"' ?>>
                                <div class="parity-item-content">
                                    <div class="parity-check"><?php if ($isMaster && $online) { ?>
                                            <div class="form-check d-inline-block mb-0">
                                                <input class="form-check-input sync-parity-library" type="checkbox" id="syncParityLibrary-<?= md5($syncApp['id'] . ':' . $syncLibrary['key']) ?>" value="<?= intval($syncApp['id']) ?>:<?= $syncLibrary['key'] ?>" <?= !empty($syncLibrary['sync']) ? ' checked' : '' ?>>
                                            </div>
                                        <?php } ?>
                                    </div>
                                    <span class="parity-name"><?= htmlEscape($syncLibrary['title']) ?></span><?php foreach ($syncLibrary['links'] ?? [] as $linkIcon) { ?>
                                        <i class="fas fa-link small parity-unlink ms-1" style="color: <?= htmlEscape($linkIcon['color']) ?>;" data-app="<?= intval($linkIcon['app']) ?>" data-id="<?= htmlEscape($linkIcon['id']) ?>" title="<?= htmlEscape(translate('unlink')) ?>"></i><?php } ?>
                                </div>
                            </td>
                        <?php } ?>
                    </tr>
                <?php } ?>
            </tbody>
        </table>
    </div>
    <div class="text-center w-100 mt-3">
        <button type="button" class="btn btn-outline-primary me-2" onclick="saveParityLibraries()"><?= htmlEscape(translate('saveSyncLibraries')) ?></button>
        <button type="button" class="btn btn-outline-<?= $canSync ? 'success' : 'danger' ?>" <?php if ($canSync) { ?> onclick="startParityLibrariesSync()" <?php } else { ?> disabled title="<?= htmlEscape(translate('needOnlineMediaApps')) ?>" <?php } ?>><?= htmlEscape(translate('startSync')) ?></button>
    </div>
<?php } ?>