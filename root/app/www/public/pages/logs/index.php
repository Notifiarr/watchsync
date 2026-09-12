<?php

/*
----------------------------------
------  Created: 091326   ------
------  Austin Best       ------
----------------------------------
*/

$logSections = getLogSections();
$hasLogs     = !empty($logSections['appLogs']) || !empty($logSections['containerLogs']);

?>
<div class="row">
    <div class="col-12 mb-3">
        <h1 class="h3 mb-0"><?= htmlEscape(translate('logs')) ?></h1>
    </div>
    <div class="col-12">
        <div class="card border shadow-sm">
            <div class="card-body">
                <?php if (!$hasLogs) { ?>
                            <p class="text-body-secondary mb-0"><?= htmlEscape(translate('noLogs')) ?></p>
                <?php } else { ?>
                            <div class="row">
                                <div class="col-sm-3">
                                    <?php
                                    $firstSection = true;
                                    foreach ($logSections as $sectionKey => $sectionGroups) {
                                        if (!$sectionGroups) {
                                            continue;
                                        }
                                        $sectionClass = $firstSection ? 'h4 text-uppercase mb-2' : 'h4 text-uppercase mt-4 mb-2';
                                        $firstSection = false;
                                        $container    = $sectionKey == 'containerLogs';
                                        ?>
                                                <h3 class="<?= $sectionClass ?>"><?= htmlEscape(translate($sectionKey)) ?></h3>
                                                <?php foreach ($sectionGroups as $group => $groupLogs) {
                                                    $current = [];
                                                    $rotated = [];
                                                    foreach ($groupLogs as $log) {
                                                        if (!empty($log['rotated'])) {
                                                            $rotated[] = $log;
                                                        } else {
                                                            $current[] = $log;
                                                        }
                                                    }
                                                    ?>
                                                            <h4 class="log-group-title mt-3 mb-0 d-inline"><?= htmlEscape($group) ?></h4>
                                                            <?php if (!$container) { ?>
                                                                        <i class="far fa-trash-alt text-danger ms-1" style="cursor: pointer;" title="<?= htmlEscape(translate('deleteAllGroupLogs', [$group])) ?>" onclick="purgeLogs('<?= htmlEscape($group) ?>');"></i>
                                                            <?php } ?>
                                                            <div class="small text-body-secondary mb-2"><?= htmlEscape(LOGS_PATH . $group) ?>/</div>
                                                            <div class="log-file-list mb-3">
                                                                <?php foreach ($current as $log) {
                                                                    $hash = md5($log['path']);
                                                                    ?>
                                                                            <div class="log-file-row">
                                                                                <span id="logList-<?= htmlEscape($hash) ?>" class="text-secondary log-file-name" style="cursor: pointer;" onclick="viewLog('<?= htmlEscape($log['path']) ?>', '<?= htmlEscape($hash) ?>');"><?= htmlEscape($log['name']) ?></span>
                                                                                (<?= htmlEscape(byteConversion($log['size'])) ?>)
                                                                                <?php if (!$container) { ?>
                                                                                            <i class="far fa-trash-alt text-danger" style="cursor: pointer;" title="<?= htmlEscape(translate('deleteLog')) ?>" onclick="deleteLog('<?= htmlEscape($log['path']) ?>');"></i>
                                                                                <?php } ?>
                                                                                <i class="fas fa-file-download text-primary" style="cursor: pointer;" title="<?= htmlEscape(translate('downloadLog')) ?>" onclick="downloadLog('<?= htmlEscape($log['path']) ?>');"></i>
                                                                            </div>
                                                                <?php } ?>
                                                                <?php if ($rotated) { ?>
                                                                            <?php if ($current) { ?>
                                                                                        <hr>
                                                                            <?php } ?>
                                                                            <?php foreach ($rotated as $log) {
                                                                                $hash = md5($log['path']);
                                                                                ?>
                                                                                        <div class="log-file-row">
                                                                                            <span id="logList-<?= htmlEscape($hash) ?>" class="text-secondary log-file-name" style="cursor: pointer;" onclick="viewLog('<?= htmlEscape($log['path']) ?>', '<?= htmlEscape($hash) ?>');"><?= htmlEscape($log['name']) ?></span>
                                                                                            (<?= htmlEscape(byteConversion($log['size'])) ?>)
                                                                                            <?php if (!$container) { ?>
                                                                                                        <i class="far fa-trash-alt text-danger" style="cursor: pointer;" title="<?= htmlEscape(translate('deleteLog')) ?>" onclick="deleteLog('<?= htmlEscape($log['path']) ?>');"></i>
                                                                                            <?php } ?>
                                                                                            <i class="fas fa-file-download text-primary" style="cursor: pointer;" title="<?= htmlEscape(translate('downloadLog')) ?>" onclick="downloadLog('<?= htmlEscape($log['path']) ?>');"></i>
                                                                                        </div>
                                                                            <?php } ?>
                                                                <?php } ?>
                                                            </div>
                                                <?php } ?>
                                    <?php } ?>
                                </div>
                                <div class="col-sm-9">
                                    <span id="logHeader"></span>
                                    <pre class="log-viewer" id="logViewer"><?= htmlEscape(translate('selectLogToView')) ?></pre>
                                </div>
                            </div>
                <?php } ?>
            </div>
        </div>
    </div>
</div>
