<?php

/*
----------------------------------
------  Created: 091326   ------
------  Austin Best       ------
----------------------------------
*/

$seasons = [];
foreach ($detail['episodes'] ?? [] as $episode) {
    $seasons[intval($episode['season'])][] = $episode;
}

$appsByPlatform = [];
foreach ($database->getMediaApps() as $mediaApp) {
    $platform = intval($mediaApp['platform'] ?? 0);
    if (!$platform) {
        continue;
    }
    if (empty($appsByPlatform[$platform])) {
        $appsByPlatform[$platform] = $mediaApp;
    }
    if (intval($mediaApp['id']) == intval($detail['app']['id'] ?? 0)) {
        $appsByPlatform[$platform] = $mediaApp;
    }
}

$firstSeason = true;

?>
<div class="library-series-watch">
    <?php if (!empty($series['title'])) { ?>
                                    <div class="fw-semibold mb-2"><?= htmlEscape($series['title']) ?></div>
    <?php } ?>
    <?php if (!$seasons) { ?>
                                    <div class="text-body-secondary"><?= htmlEscape(translate('noLibraryItems')) ?></div>
    <?php } ?>
    <?php foreach ($seasons as $season => $episodes) { ?>
                                    <h3 class="h6 mb-2<?php if (!$firstSeason) { ?> mt-3<?php } ?>"><?= htmlEscape(translate('season')) ?>                         <?= intval($season) ?></h3>
                                    <?php $firstSeason = false; ?>
                                    <div class="table-responsive">
                                        <table class="table table-bordered table-hover table-no-squish mb-0">
                                            <thead>
                                                <tr>
                                                    <th style="width: 4rem;"><?= htmlEscape(translate('episode')) ?></th>
                                                    <th><?= htmlEscape(translate('name')) ?></th>
                                                    <th style="width: 8rem;"><?= htmlEscape(translate('plex')) ?></th>
                                                    <th style="width: 8rem;"><?= htmlEscape(translate('emby')) ?></th>
                                                    <th style="width: 8rem;"><?= htmlEscape(translate('jellyfin')) ?></th>
                                                    <th style="width: 6rem;" class="text-center"><?= htmlEscape(translate('status')) ?></th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($episodes as $episode) {
                                                    $status = '';
                                                    if (!empty($episode['finished'])) {
                                                        $status = '<i class="fa-solid fa-check library-watch-check"></i>';
                                                    } else if (!empty($episode['inprogress'])) {
                                                        $status = '<span class="library-watch-progress">' . htmlEscape(formatWatchDuration($episode['inprogress'])) . '</span>';
                                                    }
                                                    $plexRemote     = trim(strval($episode['plex_remote_id'] ?? ''));
                                                    $embyRemote     = trim(strval($episode['emby_remote_id'] ?? ''));
                                                    $jellyfinRemote = trim(strval($episode['jellyfin_remote_id'] ?? ''));
                                                    $path           = trim(strval($episode['path'] ?? ''));
                                                    $plexUrl        = $mediaApps->getItemWebUrl($appsByPlatform[MediaPlatforms::PLEX] ?? [], $plexRemote);
                                                    $embyUrl        = $mediaApps->getItemWebUrl($appsByPlatform[MediaPlatforms::EMBY] ?? [], $embyRemote);
                                                    $jellyfinUrl    = $mediaApps->getItemWebUrl($appsByPlatform[MediaPlatforms::JELLYFIN] ?? [], $jellyfinRemote);
                                                    ?>
                                                                                <tr>
                                                                                    <td rowspan="2"><?= intval($episode['episode']) ?></td>
                                                                                    <td><?= htmlEscape($episode['title']) ?></td>
                                                                                    <td class="font-monospace small<?= $plexRemote == '' ? ' text-body-secondary' : '' ?>"><?php if ($plexRemote == '') { ?>-<?php } else if ($plexUrl != '') { ?><a href="<?= htmlEscape($plexUrl) ?>" target="_blank" rel="noopener noreferrer"><?= htmlEscape($plexRemote) ?></a><?php } else { ?><?= htmlEscape($plexRemote) ?><?php } ?></td>
                                                                                    <td class="font-monospace small<?= $embyRemote == '' ? ' text-body-secondary' : '' ?>"><?php if ($embyRemote == '') { ?>-<?php } else if ($embyUrl != '') { ?><a href="<?= htmlEscape($embyUrl) ?>" target="_blank" rel="noopener noreferrer"><?= htmlEscape($embyRemote) ?></a><?php } else { ?><?= htmlEscape($embyRemote) ?><?php } ?></td>
                                                                                    <td class="font-monospace small<?= $jellyfinRemote == '' ? ' text-body-secondary' : '' ?>"><?php if ($jellyfinRemote == '') { ?>-<?php } else if ($jellyfinUrl != '') { ?><a href="<?= htmlEscape($jellyfinUrl) ?>" target="_blank" rel="noopener noreferrer"><?= htmlEscape($jellyfinRemote) ?></a><?php } else { ?><?= htmlEscape($jellyfinRemote) ?><?php } ?></td>
                                                                                    <td class="text-center"><?= $status ?></td>
                                                                                </tr>
                                                                                <tr>
                                                                                    <td colspan="5" class="font-monospace small<?= $path == '' ? ' text-body-secondary' : '' ?>"<?php if ($path != '') { ?> title="<?= htmlEscape($path) ?>"<?php } ?>><?= $path != '' ? htmlEscape(truncateMiddle($path, 75)) : '-' ?></td>
                                                                                </tr>
                                                <?php } ?>
                                            </tbody>
                                        </table>
                                    </div>
    <?php } ?>
</div>
