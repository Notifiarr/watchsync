<?php

/*
----------------------------------
------  Created: 091126   ------
------  Austin Best       ------
----------------------------------
*/

$jobs = $cron->jobs();

?>
<div class="row">
    <div class="col-12 mb-3">
        <h1 class="h3 mb-0"><?= htmlEscape(translate('sync')) ?></h1>
    </div>
    <div class="col-sm-4 mb-3">
        <div class="card border shadow-sm h-100" style="cursor: pointer;" onclick="openSyncUsers()">
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
        <div class="card border shadow-sm h-100" style="cursor: pointer;" onclick="openSyncLibrary()">
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
        <div class="card border shadow-sm h-100" style="cursor: pointer;" onclick="openSyncHistory()">
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
                <h2 class="h5 mb-3"><?= htmlEscape(translate('syncHistory')) ?></h2>
                <div id="syncHistory">
                    <?php require RELATIVE_PATH . 'pages/sync/history.php'; ?>
                </div>
            </div>
        </div>
    </div>
</div>
