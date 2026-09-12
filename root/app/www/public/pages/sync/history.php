<?php

/*
----------------------------------
------  Created: 091126   ------
------  Austin Best       ------
----------------------------------
*/

$jobs = $jobs ?? $cron->jobs();

?>
<div class="table-responsive">
<table class="table table-bordered table-hover table-no-squish">
    <colgroup>
        <col class="sync-history-started" style="width: 9%;">
        <col class="sync-history-runtime" style="width: 8%;">
        <col class="sync-history-trigger" style="width: 7%;">
        <col class="sync-history-media-apps">
        <col>
        <col class="sync-history-mode" style="width: 15%;">
        <col class="sync-history-users">
        <col class="sync-history-status" style="width: 7%;">
        <col class="sync-history-size" style="width: 7%;">
        <col class="sync-history-remove" style="width: 3%;">
    </colgroup>
    <thead>
        <tr>
            <th class="sync-history-started" style="width: 9%;"><?= htmlEscape(translate('started')) ?></th>
            <th class="sync-history-runtime" style="width: 8%;"><?= htmlEscape(translate('runtime')) ?></th>
            <th class="sync-history-trigger" style="width: 7%;"><?= htmlEscape(translate('trigger')) ?></th>
            <th class="sync-history-media-apps"><?= htmlEscape(translate('mediaApps')) ?></th>
            <th><?= htmlEscape(translate('type')) ?></th>
            <th class="sync-history-mode" style="width: 15%;"><?= htmlEscape(translate('mode')) ?></th>
            <th class="sync-history-users"><?= htmlEscape(translate('users')) ?></th>
            <th class="sync-history-status" style="width: 7%;"><?= htmlEscape(translate('status')) ?></th>
            <th class="sync-history-size" style="width: 7%;"><?= htmlEscape(translate('size')) ?></th>
            <th class="sync-history-remove" style="width: 3%;"></th>
        </tr>
    </thead>
    <tbody>
        <?php if (!$jobs) { ?>
        <tr>
            <td colspan="10"><?= htmlEscape(translate('noSyncHistory')) ?></td>
        </tr>
        <?php } ?>
        <?php foreach ($jobs as $job) { ?>
        <tr class="sync-job-row" style="cursor: pointer;" data-id="<?= htmlEscape($job['id']) ?>" data-status="<?= htmlEscape($job['status']) ?>">
            <td class="sync-history-started" style="width: 9%;"><?php if (($job['status'] ?? '') == 'queued') { ?>
                <?= htmlEscape(translate('queued')) ?>
            <?php } else if (!empty($job['started'])) { ?>
                <?= htmlEscape(date('m/d/Y', intval($job['started']))) ?><br>
                <?= htmlEscape(date('g:i A', intval($job['started']))) ?>
            <?php } ?></td>
            <td class="sync-history-runtime" style="width: 8%;"><?= htmlEscape(($job['status'] ?? '') == 'queued' ? '0s' : ($job['runtime'] ?? '')) ?></td>
            <td class="sync-history-trigger" style="width: 7%;"><?= htmlEscape($mediaApps->getTriggerName($job['trigger'] ?? MediaSyncTriggers::MANUAL)) ?></td>
            <?php
            $mediaAppItems = [];
            if (!empty($job['libraries'])) {
                foreach ($job['libraries'] as $library) {
                    $label = $library['name'] ?? '';
                    if (!empty($library['title'])) {
                        $label .= ($label !== '' ? ' / ' : '') . $library['title'];
                    }
                    if ($label !== '') {
                        $mediaAppItems[] = $label;
                    }
                }
            }
            if (!$mediaAppItems && !empty($job['media_app_name'])) {
                foreach (explode(', ', $job['media_app_name']) as $part) {
                    if ($part !== '') {
                        $mediaAppItems[] = $part;
                    }
                }
            }
            if (!$mediaAppItems) {
                $mediaAppItems[] = translate('allMediaApps');
            }
            ?>
            <td class="sync-history-media-apps"><?php if (count($mediaAppItems) == 1) { ?>
                <?= htmlEscape($mediaAppItems[0]) ?>
            <?php } else { ?>
                <span class="sync-history-details">
                    <span class="sync-history-toggle" style="cursor: pointer;" title="<?= htmlEscape(translate('details')) ?>" onclick="event.stopPropagation(); toggleSyncHistoryList(this)"><i class="fas fa-circle-info me-1"></i></span>
                    <span><?= htmlEscape(translate('items', [count($mediaAppItems)])) ?></span>
                    <div class="sync-history-list" hidden onclick="event.stopPropagation();"><?php foreach ($mediaAppItems as $mediaAppItem) { ?>
                        <div><?= htmlEscape($mediaAppItem) ?></div>
                    <?php } ?></div>
                </span>
            <?php } ?></td>
            <td><?= htmlEscape($mediaApps->getSyncTypeName($job['sync_type'] ?? MediaSyncTypes::USERS, $job['sync_accounts'] ?? 0)) ?></td>
            <td class="sync-history-mode" style="width: 15%;"><?= htmlEscape($mediaApps->getJobModeName($job)) ?></td>
            <?php
            $jobType   = intval($job['sync_type'] ?? MediaSyncTypes::USERS);
            $usersCell = ($jobType == MediaSyncTypes::LIBRARY || $jobType == MediaSyncTypes::LIBRARIES) ? '' : (!empty($job['users']) ? implode(', ', $job['users']) : translate('allUsers'));
            ?>
            <td class="sync-history-users"><?= htmlEscape($usersCell) ?></td>
            <td class="sync-history-status text-center" style="width: 7%;">
                <?php
                $jobStatus = $job['status'] ?? 'running';
                if ($jobStatus == 'running') {
                    ?>
                <i class="fas fa-spinner fa-spin" title="<?= htmlEscape(translate('running')) ?>"></i>
                <i class="fas fa-xmark text-danger ms-2" style="cursor: pointer;" title="<?= htmlEscape(translate('cancelSync')) ?>" onclick="event.stopPropagation(); cancelSync('<?= htmlEscape($job['id']) ?>')"></i>
                    <?php
                } else if ($jobStatus == 'queued') {
                    ?>
                <i class="fas fa-clock" title="<?= htmlEscape(translate('queued')) ?>"></i>
                <i class="fas fa-xmark text-danger ms-2" style="cursor: pointer;" title="<?= htmlEscape(translate('cancelSync')) ?>" onclick="event.stopPropagation(); cancelSync('<?= htmlEscape($job['id']) ?>')"></i>
                    <?php
                } else if ($jobStatus == 'finished') {
                    ?>
                <i class="fas fa-check text-success" title="<?= htmlEscape(translate('finished')) ?>"></i>
                    <?php
                } else if ($jobStatus == 'cancelled') {
                    ?>
                <i class="fas fa-exclamation text-warning" title="<?= htmlEscape(translate('cancelled')) ?>"></i>
                    <?php
                } else {
                    ?>
                <i class="fas fa-exclamation text-danger" title="<?= htmlEscape(translate('error')) ?>"></i>
                    <?php
                }
                ?>
            </td>
            <td class="sync-history-size" style="width: 7%;"><?= htmlEscape(byteConversion($job['size'] ?? 0)) ?></td>
            <td class="sync-history-remove text-center" style="width: 3%;">
                <i class="fas fa-trash text-danger" style="cursor: pointer;" title="<?= htmlEscape(translate('remove')) ?>" onclick="event.stopPropagation(); deleteLog('<?= htmlEscape($job['id']) ?>')"></i>
            </td>
        </tr>
        <?php } ?>
    </tbody>
</table>
</div>
