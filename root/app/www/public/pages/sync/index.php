<?php

/*
----------------------------------
------  Created: 091126   ------
------  Austin Best       ------
----------------------------------
*/

$parityAuto  = $cron->automaticNextRun('parity');
$libraryAuto = $cron->automaticNextRun('library');
$historyAuto = $cron->automaticNextRun('history');
$autoTitle   = translate('automationNotAvailableYet');

?>
<div class="row">
    <div class="col-12 mb-3">
        <h1 class="h3 mb-0"><?= htmlEscape(translate('sync')) ?></h1>
    </div>
    <div class="col-sm-4 mb-3">
        <div class="card border shadow-sm h-100 position-relative" style="cursor: pointer;" onclick="openSyncUsers()">
            <span class="position-absolute top-0 end-0 p-2 small text-body-secondary sync-automatic" data-key="parity" title="<?= htmlEscape($autoTitle) ?>" style="cursor: pointer;" onclick="event.stopPropagation(); toggleSyncAutomatic(this);">
                <i class="fas fa-clock text-danger"></i> <?= htmlEscape($parityAuto['label']) ?>
            </span>
            <div class="card-body text-center d-flex flex-column justify-content-center">
                <h2 class="h5 mb-2">
                    <i class="fas fa-users"></i>
                    <?= htmlEscape(translate('parity')) ?>
                </h2>
                <p class="text-body-secondary mb-0"><?= htmlEscape(translate('parityDescription')) ?></p>
            </div>
        </div>
    </div>
    <div class="col-sm-4 mb-3">
        <div class="card border shadow-sm h-100 position-relative" style="cursor: pointer;" onclick="openSyncLibrary()">
            <span class="position-absolute top-0 end-0 p-2 small text-body-secondary sync-automatic" data-key="library" title="<?= htmlEscape($autoTitle) ?>" style="cursor: pointer;" onclick="event.stopPropagation(); toggleSyncAutomatic(this);">
                <i class="fas fa-clock text-danger"></i> <?= htmlEscape($libraryAuto['label']) ?>
            </span>
            <div class="card-body text-center d-flex flex-column justify-content-center">
                <h2 class="h5 mb-2">
                    <i class="fas fa-photo-film"></i>
                    <?= htmlEscape(translate('library')) ?>
                </h2>
                <p class="text-body-secondary mb-0"><?= htmlEscape(translate('libraryDescription')) ?></p>
            </div>
        </div>
    </div>
    <div class="col-sm-4 mb-3">
        <div class="card border shadow-sm h-100 position-relative" style="cursor: pointer;" onclick="openSyncHistory()">
            <span class="position-absolute top-0 end-0 p-2 small text-body-secondary sync-automatic" data-key="history" title="<?= htmlEscape($autoTitle) ?>" style="cursor: pointer;" onclick="event.stopPropagation(); toggleSyncAutomatic(this);">
                <i class="fas fa-clock text-danger"></i> <?= htmlEscape($historyAuto['label']) ?>
            </span>
            <div class="card-body text-center d-flex flex-column justify-content-center">
                <h2 class="h5 mb-2">
                    <i class="fas fa-clock-rotate-left"></i>
                    <?= htmlEscape(translate('history')) ?>
                </h2>
                <p class="text-body-secondary mb-0"><?= htmlEscape(translate('historyDescription')) ?></p>
            </div>
        </div>
    </div>
    <div class="col-12" id="syncHistorySection">
        <div class="card border shadow-sm">
            <div class="card-body">
                <h2 class="h5 mb-3"><?= htmlEscape(translate('syncHistory')) ?> <span class="text-small text-muted">- <?= htmlEscape(translate('queuedCheckedEveryMinute')) ?></span></h2>
                <div id="syncHistory">
                    <?php require RELATIVE_PATH . 'pages/sync/history.php'; ?>
                </div>
            </div>
        </div>
    </div>
</div>
