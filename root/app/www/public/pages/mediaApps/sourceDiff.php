<?php

/*
----------------------------------
------  Created: 092726   ------
------  Austin Best       ------
----------------------------------
*/

$rows     = $rows ?? [];
$listener = $listener ?? [];
$master   = $master ?? [];
$order    = ['users', 'movies', 'series', 'episodes', 'rootFolders'];

?>
<div class="container-fluid">
    <p class="text-body-secondary mb-3"><?= htmlEscape(translate('diffFromMain', [$listener['name'] ?? '', $master['name'] ?? ''])) ?></p>
    <div class="table-responsive">
        <table class="table table-bordered table-hover mb-0">
            <tbody>
                <?php foreach ($order as $key) {
                    $more       = $rows[$key]['more'] ?? [];
                    $less       = $rows[$key]['less'] ?? [];
                    $isEpisodes = $key == 'episodes';
                    $format     = function ($sign, $items) use ($isEpisodes) {
                        if (!$isEpisodes) {
                            return htmlEscape($sign . count($items) . ': ' . implode(', ', $items));
                        }
                        $count = 0;
                        $parts = [];
                        foreach ($items as $group) {
                            $codes  = $group['codes'] ?? [];
                            $count += count($codes);
                            $series = trim(strval($group['series'] ?? ''));
                            $piece  = '';
                            if ($series != '') {
                                $piece = '<span class="fw-bold">' . htmlEscape($series) . '</span>';
                            }
                            if ($codes) {
                                $piece = trim($piece . ' ' . htmlEscape(implode(', ', $codes)));
                            }
                            if ($piece != '') {
                                $parts[] = $piece;
                            }
                        }

                        return htmlEscape($sign . $count . ': ') . implode(', ', $parts);
                    };
                    $lines = [];
                    if ($more) {
                        $lines[] = $format('+', $more);
                    }
                    if ($less) {
                        $lines[] = $format('-', $less);
                    }
                    ?>
                    <tr>
                        <td class="text-nowrap"><?= htmlEscape(translate($key)) ?></td>
                        <td>
                            <?php if (!$lines) { ?>
                                <i class="fa-solid fa-check text-success" title="<?= htmlEscape(translate('matched')) ?>"></i>
                            <?php } else { ?>
                                <?php foreach ($lines as $line) { ?>
                                    <div><?= $line ?></div>
                                <?php } ?>
                            <?php } ?>
                        </td>
                    </tr>
                <?php } ?>
            </tbody>
        </table>
    </div>
</div>
