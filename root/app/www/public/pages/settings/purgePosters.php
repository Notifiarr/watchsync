<?php

/*
----------------------------------
------  Created: 092826   ------
------  Austin Best       ------
----------------------------------
*/

$posterLibraries = [];
$posterMaster    = $mediaApps->masterMediaApp();
if ($posterMaster) {
    foreach ($database->getMediaAppLibraries($posterMaster['id']) as $library) {
        if (($library['key'] ?? '') == '' || empty($library['paths'])) {
            continue;
        }
        $table = $mediaApps->libraryPosterTable($library['type'] ?? '');
        if ($table == '') {
            continue;
        }
        $fileType = $table == SERIES_TABLE ? 'series' : 'movie';
        $cached   = 0;
        foreach ($database->mediaIdsUnderRoots($table, $library['paths']) as $itemId) {
            if ($mediaApps->libraryPosterExists($fileType, $itemId)) {
                $cached++;
            }
        }
        $library['cached']  = $cached;
        $posterLibraries[]  = $library;
    }
}

?>
<?php if (!$posterLibraries) { ?>
    <p class="text-body-secondary mb-0"><?= htmlEscape(translate('noPosterCacheLibraries')) ?></p>
<?php } else { ?>
    <div class="form-check mb-3">
        <input class="form-check-input" type="checkbox" id="purgePosterAll" onchange="$('.purge-poster-library').prop('checked', $(this).prop('checked'))">
        <label class="form-check-label" for="purgePosterAll"><?= htmlEscape($posterMaster['name'] ?? '') ?></label>
    </div>
    <?php foreach ($posterLibraries as $library) {
        $key = strval($library['key'] ?? '');
        $id  = 'purgePoster-' . md5($key);
        ?>
        <div class="form-check mb-2">
            <input class="form-check-input purge-poster-library" type="checkbox" id="<?= htmlEscape($id) ?>" value="<?= htmlEscape($key) ?>">
            <label class="form-check-label" for="<?= htmlEscape($id) ?>">
                <?= htmlEscape($library['title'] ?? $key) ?>
                <span class="text-body-secondary small"><?= htmlEscape(translate('posterCacheCount', [intval($library['cached'] ?? 0)])) ?></span>
            </label>
        </div>
    <?php } ?>
<?php } ?>
