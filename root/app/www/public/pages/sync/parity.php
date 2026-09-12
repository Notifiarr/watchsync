<?php

/*
----------------------------------
------  Created: 091226   ------
------  Austin Best       ------
----------------------------------
*/

$syncUsers     = $syncUsers ?? [];
$syncLibraries = $syncLibraries ?? [];

?>
<div class="container-fluid">
    <p class="text-body-secondary"><?= htmlEscape(translate('parityDescription')) ?></p>
    <p class="small text-body-secondary"><?= htmlEscape(translate('parityLinkHint')) ?></p>
    <ul class="nav nav-tabs mb-3" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link active" id="parity-users-tab" data-bs-toggle="tab" data-bs-target="#parity-users" type="button" role="tab"><?= htmlEscape(translate('users')) ?></button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="parity-libraries-tab" data-bs-toggle="tab" data-bs-target="#parity-libraries" type="button" role="tab"><?= htmlEscape(translate('libraries')) ?></button>
        </li>
    </ul>
    <div class="tab-content">
        <div class="tab-pane fade show active" id="parity-users" role="tabpanel">
            <?php require RELATIVE_PATH . 'pages/sync/users.php'; ?>
        </div>
        <div class="tab-pane fade" id="parity-libraries" role="tabpanel">
            <?php require RELATIVE_PATH . 'pages/sync/parityLibraries.php'; ?>
        </div>
    </div>
</div>
