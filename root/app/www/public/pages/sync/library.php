<?php

/*
----------------------------------
------  Created: 091126   ------
------  Austin Best       ------
----------------------------------
*/

$syncLibraries = $syncLibraries ?? [];

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
    <div class="mb-2">
        <div class="form-check">
            <input class="form-check-input" type="checkbox" id="syncLibraryAll" checked onchange="$('.sync-library').prop('checked', $(this).prop('checked'))">
            <label class="form-check-label" for="syncLibraryAll"><?= htmlEscape(translate('library')) ?></label>
        </div>
    </div>
    <div class="row">
        <?php foreach ($syncLibraries as $syncApp) {
            $platformName = $mediaApps->getPlatformName($syncApp['platform']);
            $platformLogo = $mediaApps->getPlatformLogo($syncApp['platform']);
            ?>
        <div class="col">
            <h3 class="h6 d-flex align-items-center">
                <?php if ($platformLogo) { ?>
                <img src="<?= $platformLogo ?>" alt="<?= htmlEscape($platformName) ?>" class="me-2" style="height: 1.25em; width: auto;">
                <?php } ?>
                <?= htmlEscape($syncApp['name']) ?>
            </h3>
            <?php if (empty($syncApp['libraries'])) { ?>
            <p class="text-body-secondary small"><?= htmlEscape(translate('noLibraries')) ?></p>
            <?php } ?>
            <?php foreach ($syncApp['libraries'] as $syncLibrary) { ?>
            <div class="form-check">
                <input class="form-check-input sync-library" type="checkbox" id="syncLibrary-<?= md5($syncApp['id'] . ':' . $syncLibrary['key']) ?>" value="<?= intval($syncApp['id']) ?>:<?= $syncLibrary['key'] ?>" checked>
                <label class="form-check-label" for="syncLibrary-<?= md5($syncApp['id'] . ':' . $syncLibrary['key']) ?>"><?= htmlEscape($syncLibrary['title']) ?></label>
            </div>
            <?php } ?>
        </div>
        <?php } ?>
    </div>
    <div class="text-center w-100 mt-3">
        <button type="button" class="btn btn-outline-success" onclick="startLibrarySync()"><?= htmlEscape(translate('startSync')) ?></button>
    </div>
    <?php } ?>
</div>
