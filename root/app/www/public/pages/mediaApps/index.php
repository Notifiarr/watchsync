<?php

/*
----------------------------------
------  Created: 091026   ------
------  Austin Best       ------
----------------------------------
*/

$mediaAppRows = $database->getMediaApps();
$masterApp    = [];
foreach ($mediaAppRows as $mediaAppRow) {
    if (intval($mediaAppRow['role']) == MediaAppRoles::MASTER) {
        $masterApp = $mediaAppRow;
        break;
    }
}
$masterCounts = [
    'users'       => 0,
    'movies'      => 0,
    'series'      => 0,
    'episodes'    => 0,
    'rootFolders' => 0,
];
if ($masterApp) {
    $masterLibrary = $database->getMediaLibraryCounts($masterApp['platform']);
    $masterCounts  = [
        'users'       => count($database->getMediaAppUsers($masterApp['id'])),
        'movies'      => intval($masterLibrary['movies']),
        'series'      => intval($masterLibrary['series']),
        'episodes'    => intval($masterLibrary['episodes']),
        'rootFolders' => count($mediaApps->getRootFolders($masterApp)),
    ];
}
$runningAppIds = [];
foreach ($cron->jobs() as $job) {
    if (($job['status'] ?? '') != 'running' || intval($job['sync_type'] ?? 0) != MediaSyncTypes::LIBRARY) {
        continue;
    }
    if (!empty($job['media_app_id'])) {
        $runningAppIds[intval($job['media_app_id'])] = true;
    }
    foreach ($job['libraries'] ?? [] as $library) {
        if (!empty($library['media_app_id'])) {
            $runningAppIds[intval($library['media_app_id'])] = true;
        }
    }
}

?>
<div class="row">
    <div class="col-12 mb-3">
        <h1 class="h3 mb-0"><?= htmlEscape(translate('mediaApps')) ?></h1>
        <p class="text-body-secondary mb-0"><?= htmlEscape(translate('mediaAppsDescription')) ?></p>
    </div>
    <?php foreach ($mediaAppRows as $mediaApp) {
        $platformName  = $mediaApps->getPlatformName($mediaApp['platform']);
        $platformLogo  = $mediaApps->getPlatformLogo($mediaApp['platform']);
        $userCount     = count($database->getMediaAppUsers($mediaApp['id']));
        $libraryCounts = $database->getMediaLibraryCounts($mediaApp['platform']);
        $libraryRoots  = $mediaApps->getRootFolders($mediaApp);
        $online        = $mediaApps->isOnline($mediaApp);
        $isListener    = intval($mediaApp['role']) != MediaAppRoles::MASTER;
        $pills         = [
            ['key' => 'users', 'count' => $userCount, 'onclick' => 'openMediaAppUsers(' . intval($mediaApp['id']) . ')'],
            ['key' => 'movies', 'count' => intval($libraryCounts['movies'])],
            ['key' => 'series', 'count' => intval($libraryCounts['series'])],
            ['key' => 'episodes', 'count' => intval($libraryCounts['episodes'])],
            ['key' => 'rootFolders', 'count' => count($libraryRoots), 'onclick' => 'openMediaAppRootFolders(' . intval($mediaApp['id']) . ')'],
        ];
        ?>
                <div class="col-sm-4 mb-3">
                    <div class="card border shadow-sm h-100">
                        <div class="card-body">
                            <div class="d-flex align-items-center mb-2">
                                <h2 class="h5 mb-0 d-flex align-items-center flex-fill">
                                    <?php if ($platformLogo) { ?>
                                                <img src="<?= $platformLogo ?>" alt="<?= htmlEscape($platformName) ?>" class="me-2" style="height: 1.25em; width: auto;">
                                    <?php } ?>
                                    <?= htmlEscape($mediaApp['name']) ?>
                                </h2>
                                <div class="flex-fill text-center">
                                    <?php if (!empty($runningAppIds[intval($mediaApp['id'])])) { ?>
                                                <span class="small text-muted"><?= htmlEscape(translate('syncAge')) ?>: <?= htmlEscape(translate('runningNow')) ?></span>
                                    <?php } else if (!empty($mediaApp['last_scan']) || !empty($mediaApp['needs_sync'])) { ?>
                                                            <span class="small text-muted"><?= htmlEscape(translate('syncAge')) ?>:<?php if (!empty($mediaApp['last_scan'])) { ?>                                     <?= htmlEscape(relativeBetweenDates(intval($mediaApp['last_scan']), time(), true)) ?>                        <?php } ?>                        <?php if (!empty($mediaApp['needs_sync'])) { ?> <span class="text-warning fst-italic"><?= htmlEscape(translate('outdated')) ?></span><?php } ?></span>
                                    <?php } ?>
                                </div>
                                <div class="flex-fill text-end">
                                    <i class="fas fa-pen-to-square" style="cursor: pointer;" title="<?= htmlEscape(translate('edit')) ?>" onclick="openMediaAppForm(<?= intval($mediaApp['id']) ?>)"></i>
                                </div>
                            </div>
                            <div class="mb-2">
                                <?php if ($mediaApp['name'] != $platformName) { ?>
                                            <span class="badge text-bg-dark"><?= htmlEscape($platformName) ?></span>
                                <?php } ?>
                                <span class="badge text-bg-<?= $mediaApp['role'] == MediaAppRoles::MASTER ? 'success' : 'primary' ?>"><?= htmlEscape($mediaApps->getRoleName($mediaApp['role'])) ?></span>
                                <span class="badge text-bg-info"><?= htmlEscape($mediaApps->getSyncModeName($mediaApp['sync_mode'])) ?></span>
                                <?php if (!$mediaApp['active']) { ?>
                                            <span class="badge text-bg-warning"><?= htmlEscape(translate('inactive')) ?></span>
                                <?php } ?>
                            </div>
                            <div class="mb-2">
                                <?php foreach ($pills as $pill) {
                                    $label     = translate($pill['key']);
                                    $pillClass = 'badge rounded-pill text-bg-secondary';
                                    $title     = $label;
                                    if ($isListener) {
                                        $diff = intval($masterCounts[$pill['key']]) - intval($pill['count']);
                                        if ($diff == 0) {
                                            $pillClass .= ' border border-success';
                                        } else {
                                            $pillClass .= ' border border-warning';
                                            $noun       = strtolower($label);
                                            $title      = $diff > 0 ? translate('lessThanSource', [abs($diff), $noun]) : translate('moreThanSource', [abs($diff), $noun]);
                                        }
                                    }
                                    ?>
                                            <span class="<?= $pillClass ?>"<?php if (!empty($pill['onclick'])) { ?> style="cursor: pointer;" onclick="<?= $pill['onclick'] ?>"<?php } ?> title="<?= htmlEscape($title) ?>"><?= htmlEscape($label) ?>: <?= intval($pill['count']) ?></span>
                                <?php } ?>
                            </div>
                            <div class="small mb-3 d-flex align-items-center">
                                <i class="fas fa-circle text-<?= $online ? 'success' : 'danger' ?> me-2" title="<?= htmlEscape(translate($online ? 'online' : 'offline')) ?>"></i>
                                <span class="text-body-secondary text-truncate me-2"><?= htmlEscape($mediaApp['url']) ?></span>
                                <button type="button" class="btn btn-outline-<?= $online ? 'success' : 'danger' ?> btn-sm py-0 px-2 ms-auto"<?php if ($online) { ?> onclick="startMediaAppLibrarySync(<?= intval($mediaApp['id']) ?>)"<?php } else { ?> disabled title="<?= htmlEscape(translate('offline')) ?>"<?php } ?>><?= htmlEscape(translate('syncLibraries')) ?></button>
                                <button type="button" class="btn btn-outline-<?= $online ? 'success' : 'danger' ?> btn-sm py-0 px-2 ms-1"<?php if ($online) { ?> onclick="startMediaAppHistorySync(<?= intval($mediaApp['id']) ?>)"<?php } else { ?> disabled title="<?= htmlEscape(translate('offline')) ?>"<?php } ?>><?= htmlEscape(translate('syncHistory')) ?></button>
                            </div>
                            <div class="form-check form-switch mb-0">
                                <input class="form-check-input" type="checkbox" role="switch" id="mediaAppActive-<?= intval($mediaApp['id']) ?>"<?= $mediaApp['active'] ? ' checked' : '' ?> onchange="toggleMediaApp(<?= intval($mediaApp['id']) ?>)">
                                <label class="form-check-label" for="mediaAppActive-<?= intval($mediaApp['id']) ?>"><?= htmlEscape(translate('active')) ?></label>
                            </div>
                        </div>
                    </div>
                </div>
    <?php } ?>
    <div class="col-sm-4 mb-3">
        <div class="card border shadow-sm h-100" style="cursor: pointer;" onclick="openMediaAppForm(0)">
            <div class="card-body text-center d-flex flex-column justify-content-center">
                <h2 class="h5 mb-2">
                    <i class="fas fa-plus-circle"></i>
                    <?= htmlEscape(translate('addMediaApp')) ?>
                </h2>
                <p class="text-body-secondary mb-0"><?= htmlEscape($mediaAppRows ? translate('addAnotherMediaApp') : translate('noMediaApps')) ?></p>
            </div>
        </div>
    </div>
</div>
