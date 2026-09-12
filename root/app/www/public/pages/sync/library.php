<?php

/*
----------------------------------
------  Created: 091126   ------
------  Austin Best       ------
----------------------------------
*/

$syncLibraries = $syncLibraries ?? [];
$hasOnline     = false;
foreach ($syncLibraries as $syncApp) {
    if (!empty($syncApp['online'])) {
        $hasOnline = true;
        break;
    }
}

?>
<div class="container-fluid">
    <?php if (!$syncLibraries) { ?>
                            <p class="text-body-secondary mb-0"><?= htmlEscape(translate('noLibraries')) ?></p>
    <?php } else { ?>
                            <div class="mb-3">
                                <label for="syncLibraryScan" class="form-label"><?= htmlEscape(translate('libraryScan')) ?></label>
                                <select class="form-select" id="syncLibraryScan">
                                    <option value="<?= MediaLibraryScans::LAST_SCAN ?>" selected><?= htmlEscape(translate('sinceLastScan')) ?></option>
                                    <option value="<?= MediaLibraryScans::FULL ?>"><?= htmlEscape(translate('fullLibraryScan')) ?></option>
                                </select>
                            </div>
                            <div class="row">
                                <?php foreach ($syncLibraries as $syncApp) {
                                    $platformName      = $mediaApps->getPlatformName($syncApp['platform']);
                                    $platformLogo      = $mediaApps->getPlatformLogo($syncApp['platform']);
                                    $online            = !empty($syncApp['online']);
                                    $libraries         = $syncApp['libraries'] ?? [];
                                    $appLibraryChecked = 0;
                                    $appLibraryTotal   = 0;
                                    foreach ($libraries as $syncLibrary) {
                                        if (($syncLibrary['key'] ?? '') == '') {
                                            continue;
                                        }
                                        $appLibraryTotal++;
                                        if (!empty($syncLibrary['sync'])) {
                                            $appLibraryChecked++;
                                        }
                                    }
                                    ?>
                                                        <div class="col">
                                                            <h3 class="h6 d-flex align-items-center">
                                                                <?php if ($platformLogo) { ?>
                                                                                        <img src="<?= $platformLogo ?>" alt="<?= htmlEscape($platformName) ?>" class="me-2" style="height: 1.25em; width: auto;">
                                                                <?php } ?>
                                                                <?php if ($online && $appLibraryTotal) { ?>
                                                                                        <input class="form-check-input me-2" type="checkbox" id="syncLibraryAll-<?= intval($syncApp['id']) ?>"<?= $appLibraryChecked && $appLibraryChecked == $appLibraryTotal ? ' checked' : '' ?>>
                                                                                        <label class="form-check-label mb-0" for="syncLibraryAll-<?= intval($syncApp['id']) ?>"><?= htmlEscape($syncApp['name']) ?></label>
                                                                                        <span class="badge text-bg-success ms-1"><?= htmlEscape(translate('online')) ?></span>
                                                                <?php } else { ?>
                                                                                        <?= htmlEscape($syncApp['name']) ?> <span class="badge text-bg-<?= $online ? 'success' : 'danger' ?> ms-1"><?= htmlEscape(translate($online ? 'online' : 'offline')) ?></span>
                                                                <?php } ?>
                                                            </h3>
                                                            <?php if (!$appLibraryTotal) { ?>
                                                                                    <p class="text-body-secondary small"><?= htmlEscape(translate('noLibraries')) ?></p>
                                                            <?php } ?>
                                                            <?php foreach ($libraries as $syncLibrary) {
                                                                if (($syncLibrary['key'] ?? '') == '') {
                                                                    continue;
                                                                }
                                                                ?>
                                                                                    <div class="form-check">
                                                                                        <input class="form-check-input sync-library" type="checkbox" id="syncLibrary-<?= md5($syncApp['id'] . ':' . $syncLibrary['key']) ?>" value="<?= intval($syncApp['id']) ?>:<?= $syncLibrary['key'] ?>"<?= $online && !empty($syncLibrary['sync']) ? ' checked' : '' ?><?= $online ? '' : ' disabled' ?>>
                                                                                        <label class="form-check-label" for="syncLibrary-<?= md5($syncApp['id'] . ':' . $syncLibrary['key']) ?>"><?= htmlEscape($syncLibrary['title']) ?></label>
                                                                                    </div>
                                                            <?php } ?>
                                                        </div>
                                <?php } ?>
                            </div>
                            <div class="text-center w-100 mt-3">
                                <button type="button" class="btn btn-outline-primary me-2" onclick="saveLibraryScan()"><?= htmlEscape(translate('saveSyncLibraries')) ?></button>
                                <button type="button" class="btn btn-outline-<?= $hasOnline ? 'success' : 'danger' ?>"<?php if ($hasOnline) { ?> onclick="startLibrarySync()"<?php } else { ?> disabled title="<?= htmlEscape(translate('offline')) ?>"<?php } ?>><?= htmlEscape(translate('startSync')) ?></button>
                            </div>
    <?php } ?>
</div>
