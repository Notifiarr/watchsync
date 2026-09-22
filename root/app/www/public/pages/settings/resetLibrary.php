<?php

/*
----------------------------------
------  Created: 091926   ------
------  Austin Best       ------
----------------------------------
*/

$resetLibraries = [];
$libraryTotal   = 0;
foreach ($database->getMediaApps() as $mediaApp) {
    if (!$mediaApp['active']) {
        continue;
    }
    $libraries = [];
    foreach ($database->getMediaAppLibraries($mediaApp['id']) as $library) {
        if (($library['key'] ?? '') == '' || empty($library['paths'])) {
            continue;
        }
        $libraries[] = $library;
        $libraryTotal++;
    }
    if (!$libraries) {
        continue;
    }
    $resetLibraries[] = $mediaApp + ['libraries' => $libraries];
}

?>
<?php if (!$libraryTotal) { ?>
    <p class="text-body-secondary mb-0"><?= htmlEscape(translate('noLocalLibraries')) ?></p>
<?php } else { ?>
    <div class="row">
        <?php foreach ($resetLibraries as $resetApp) {
            $platformName = $mediaApps->getPlatformName($resetApp['platform']);
            $platformLogo = $mediaApps->getPlatformLogo($resetApp['platform']);
            $appId        = intval($resetApp['id']);
            ?>
            <div class="col">
                <h3 class="h6 d-flex align-items-center mb-2">
                    <input class="form-check-input me-2" type="checkbox" id="resetLibraryAll-<?= $appId ?>" onchange="$('.reset-local-library-<?= $appId ?>').prop('checked', $(this).prop('checked'))">
                    <?php if ($platformLogo) { ?>
                        <img src="<?= $platformLogo ?>" alt="<?= htmlEscape($platformName) ?>" class="me-2" style="height: 1.25em; width: auto;">
                    <?php } ?>
                    <label class="form-check-label mb-0" for="resetLibraryAll-<?= $appId ?>"><?= htmlEscape($resetApp['name']) ?></label>
                </h3>
                <?php foreach ($resetApp['libraries'] as $library) {
                    $value = $appId . ':' . strval($library['key'] ?? '');
                    $id    = 'resetLibrary-' . md5($value);
                    ?>
                    <div class="form-check mb-2">
                        <input class="form-check-input reset-local-library reset-local-library-<?= $appId ?>" type="checkbox" id="<?= htmlEscape($id) ?>" value="<?= htmlEscape($value) ?>">
                        <label class="form-check-label" for="<?= htmlEscape($id) ?>">
                            <?= htmlEscape($library['title'] ?? $library['key']) ?>
                            <span class="text-body-secondary small"><?= htmlEscape(translate('libraryPathCount', [count($library['paths'] ?? [])])) ?></span>
                        </label>
                    </div>
                <?php } ?>
            </div>
        <?php } ?>
    </div>
<?php } ?>