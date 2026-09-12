<?php

/*
----------------------------------
------  Created: 091326   ------
------  Austin Best       ------
----------------------------------
*/

$apps      = $watch['apps'] ?? [];
$users     = $watch['users'] ?? [];
$matrix    = $watch['matrix'] ?? [];
$noHistory = intval($watch['noHistory'] ?? 0);
$colspan   = 1 + count($apps);

?>
<div class="table-responsive">
    <table class="table table-bordered table-hover table-no-squish mb-0">
        <thead>
            <tr>
                <th><?= htmlEscape(translate('users')) ?></th>
                <?php foreach ($apps as $app) { ?>
                            <th class="text-center">
                                <?php if ($mediaApps->getPlatformLogo($app['platform'])) { ?>
                                            <img src="<?= $mediaApps->getPlatformLogo($app['platform']) ?>" alt="" style="height: 1.1em; width: auto;" class="me-1">
                                <?php } ?>
                                <?= htmlEscape($app['name']) ?>
                            </th>
                <?php } ?>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($users as $user) { ?>
                        <tr>
                            <td><?= htmlEscape((!empty($user['is_admin']) ? '* ' : '') . $user['username']) ?></td>
                            <?php foreach ($apps as $app) {
                                $cell = $matrix[intval($user['id'])][intval($app['id'])] ?? [];
                                $html = '';
                                if (!empty($cell['finished'])) {
                                    $html = '<i class="fa-solid fa-check library-watch-check"></i>';
                                } else if (!empty($cell['inprogress'])) {
                                    $label = formatWatchDuration($cell['inprogress']);
                                    if ($kind == 'series' && (isset($cell['season']) || isset($cell['episode']))) {
                                        $label = 'S' . str_pad(strval(intval($cell['season'] ?? 0)), 2, '0', STR_PAD_LEFT)
                                            . 'E' . str_pad(strval(intval($cell['episode'] ?? 0)), 2, '0', STR_PAD_LEFT)
                                            . ' ' . $label;
                                    }
                                    $html = '<span class="library-watch-progress">' . htmlEscape($label) . '</span>';
                                } else if (!empty($cell['watched']) && !empty($cell['total'])) {
                                    $html = '<span class="library-watch-progress">' . htmlEscape(intval($cell['watched']) . '/' . intval($cell['total'])) . '</span>';
                                }
                                $clickable = $kind == 'series';
                                ?>
                                        <td class="text-center<?php if ($clickable) { ?> library-series-cell<?php } ?>"
                                            <?php if ($clickable) { ?>
                                                    data-series-id="<?= intval($item['id']) ?>"
                                                    data-user-id="<?= intval($user['id']) ?>"
                                                    data-app-id="<?= intval($app['id']) ?>"
                                                    data-username="<?= htmlEscape($user['username']) ?>"
                                                    data-app-name="<?= htmlEscape($app['name']) ?>"
                                                    data-series-title="<?= htmlEscape($item['title'] ?? '') ?>"
                                            <?php } ?>
                                        ><?= $html ?></td>
                            <?php } ?>
                        </tr>
            <?php } ?>
            <tr class="library-watch-no-history">
                <td colspan="<?= intval($colspan) ?>" class="text-muted small">
                    <?= htmlEscape(translate('usersWithNoHistory', [$noHistory])) ?>
                </td>
            </tr>
        </tbody>
    </table>
</div>
