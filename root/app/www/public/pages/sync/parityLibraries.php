<?php

/*
----------------------------------
------  Created: 091226   ------
------  Austin Best       ------
----------------------------------
*/

$syncLibraries = $syncLibraries ?? [];

?>
    <?php if (!$syncLibraries) { ?>
    <p class="text-body-secondary mb-0"><?= htmlEscape(translate('noLibraries')) ?></p>
    <?php } else { ?>
    <div class="mb-2">
        <div class="form-check">
            <input class="form-check-input" type="checkbox" id="syncParityLibraryAll" checked onchange="$('.sync-parity-library').prop('checked', $(this).prop('checked'))">
            <label class="form-check-label" for="syncParityLibraryAll"><?= htmlEscape(translate('libraries')) ?></label>
        </div>
    </div>
    <div class="row">
        <?php foreach ($syncLibraries as $syncApp) {
            $platformName = $mediaApps->getPlatformName($syncApp['platform']);
            $platformLogo = $mediaApps->getPlatformLogo($syncApp['platform']);
            $isMaster     = intval($syncApp['role']) == MediaAppRoles::MASTER;
            $linkedLibraries   = [];
            $unlinkedLibraries = [];
            foreach ($syncApp['libraries'] as $index => $syncLibrary) {
                $syncLibrary['_sort'] = $index;
                if (!empty($syncLibrary['links'])) {
                    $linkedLibraries[] = $syncLibrary;
                } else {
                    $unlinkedLibraries[] = $syncLibrary;
                }
            }
            $libraryGroups = [
                '1' => ['label' => translate('linked'), 'libraries' => $linkedLibraries],
                '0' => ['label' => translate('unlinked'), 'libraries' => $unlinkedLibraries],
            ];
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
            <?php foreach ($libraryGroups as $linkedFlag => $libraryGroup) { ?>
            <div class="parity-group<?= $linkedFlag === '0' ? '' : ' mb-3' ?>" data-linked="<?= $linkedFlag ?>">
                <div class="small text-body-secondary mb-1"><?= htmlEscape($libraryGroup['label']) ?></div>
                <?php foreach ($libraryGroup['libraries'] as $syncLibrary) {
                    $linkId = $syncLibrary['link_id'] ?? '';
                    ?>
                <div class="parity-item mb-1" style="cursor: pointer;" data-kind="library" data-app="<?= intval($syncApp['id']) ?>" data-id="<?= htmlEscape($syncLibrary['key']) ?>" data-master="<?= $isMaster ? '1' : '0' ?>" data-link="<?= htmlEscape($linkId) ?>" data-sort="<?= intval($syncLibrary['_sort']) ?>">
                    <?php if ($isMaster) { ?>
                    <div class="form-check d-inline-block mb-0 me-1">
                        <input class="form-check-input<?= empty($syncLibrary['linkedAll']) ? ' sync-parity-library' : '' ?>" type="checkbox" id="syncParityLibrary-<?= md5($syncApp['id'] . ':' . $syncLibrary['key']) ?>" value="<?= intval($syncApp['id']) ?>:<?= $syncLibrary['key'] ?>"<?= empty($syncLibrary['linkedAll']) ? ' checked' : ' disabled' ?>>
                    </div>
                    <?php } ?>
                    <span class="parity-name"><?= htmlEscape($syncLibrary['title']) ?></span><?php foreach ($syncLibrary['links'] ?? [] as $linkIcon) { ?>
                    <i class="fas fa-link small parity-unlink ms-1" style="color: <?= htmlEscape($linkIcon['color']) ?>;" data-app="<?= intval($linkIcon['app']) ?>" data-id="<?= htmlEscape($linkIcon['id']) ?>" title="<?= htmlEscape(translate('unlink')) ?>"></i><?php } ?>
                </div>
                <?php } ?>
            </div>
            <?php } ?>
        </div>
        <?php } ?>
    </div>
    <div class="text-center w-100 mt-3">
        <button type="button" class="btn btn-outline-success" onclick="startParityLibrariesSync()"><?= htmlEscape(translate('startSync')) ?></button>
    </div>
    <?php } ?>
