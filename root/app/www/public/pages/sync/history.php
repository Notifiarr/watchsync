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
<table class="table table-bordered table-hover table-no-squish" id="sync-history-table">
    <colgroup>
        <col class="sync-history-started" style="width: 9%;">
        <col class="sync-history-runtime" style="width: 8%;">
        <col class="sync-history-trigger" style="width: 7%;">
        <col class="sync-history-media-apps">
        <col class="sync-history-type">
        <col class="sync-history-mode" style="width: 18%;">
        <col class="sync-history-users">
        <col class="sync-history-status" style="width: 7%;">
        <col class="sync-history-size" style="width: 7%;">
        <col class="sync-history-remove" style="width: 5%;">
    </colgroup>
    <thead>
        <tr>
            <th class="sync-history-started" style="width: 9%;"><?= htmlEscape(translate('started')) ?></th>
            <th class="sync-history-runtime" style="width: 8%;"><?= htmlEscape(translate('runtime')) ?></th>
            <th class="sync-history-trigger" style="width: 7%;"><?= htmlEscape(translate('trigger')) ?></th>
            <th class="sync-history-media-apps"><?= htmlEscape(translate('mediaApps')) ?></th>
            <th class="sync-history-type"><?= htmlEscape(translate('type')) ?></th>
            <th class="sync-history-mode" style="width: 18%;"><?= htmlEscape(translate('mode')) ?></th>
            <th class="sync-history-users"><?= htmlEscape(translate('users')) ?></th>
            <th class="sync-history-status" style="width: 7%;"><?= htmlEscape(translate('status')) ?></th>
            <th class="sync-history-size" style="width: 7%;"><?= htmlEscape(translate('size')) ?></th>
            <th class="sync-history-remove no-sort text-center" style="width: 5%;">
                <i class="fas fa-trash text-danger" style="cursor: pointer;" title="<?= htmlEscape(translate('clearSyncHistory')) ?>" onclick="deleteAllSyncHistory()"></i>
            </th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($jobs as $job) { ?>
                                    <tr class="sync-job-row" style="cursor: pointer;" data-id="<?= htmlEscape($job['id']) ?>" data-status="<?= htmlEscape($job['status']) ?>">
                                        <td class="sync-history-started" style="width: 9%;"><?php if (($job['status'] ?? '') == 'queued') { ?>
                                                                        <?= htmlEscape(translate('queued')) ?><br>
                                                                        <span class="sync-history-queued-wait"><?= htmlEscape($job['queued_wait'] ?? '') ?></span>
                                        <?php } else if (!empty($job['started'])) { ?>
                                                                        <?= htmlEscape(date('m/d/Y', intval($job['started']))) ?><br>
                                                                        <?= htmlEscape(date('g:i A', intval($job['started']))) ?>
                                        <?php } ?></td>
                                        <td class="sync-history-runtime" style="width: 8%;"><?= htmlEscape(($job['status'] ?? '') == 'queued' ? '0s' : ($job['runtime'] ?? '')) ?></td>
                                        <td class="sync-history-trigger" style="width: 7%;">
                                            <?= htmlEscape($mediaApps->getTriggerName($job['trigger'] ?? MediaSyncTriggers::MANUAL)) ?><br>
                                            <span class="small text-body-secondary"><?= htmlEscape(!empty($job['dry_run']) ? translate('dryRun') : translate('liveRun')) ?></span>
                                        </td>
                                        <?php
                                        $mediaAppItems = [];
                                        if (!empty($job['libraries'])) {
                                            foreach ($job['libraries'] as $library) {
                                                $label = $library['name'] ?? '';
                                                if (!empty($library['title'])) {
                                                    $label .= ($label != '' ? ' / ' : '') . $library['title'];
                                                }
                                                if ($label != '') {
                                                    $mediaAppItems[] = $label;
                                                }
                                            }
                                        }
                                        if (!$mediaAppItems && !empty($job['media_app_name'])) {
                                            foreach (explode(', ', $job['media_app_name']) as $part) {
                                                if ($part != '') {
                                                    $mediaAppItems[] = $part;
                                                }
                                            }
                                        }
                                        if (!$mediaAppItems && !empty($job['media_app_id'])) {
                                            $mediaApp = $database->getMediaApp($job['media_app_id']);
                                            if (!empty($mediaApp['name'])) {
                                                $mediaAppItems[] = $mediaApp['name'];
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
                                        <td class="sync-history-type"><?= htmlEscape($mediaApps->getSyncTypeName($job['sync_type'] ?? MediaSyncTypes::USERS, $job['sync_accounts'] ?? 0, $job['trigger'] ?? 0, $job['webhook_event'] ?? '')) ?></td>
                                        <td class="sync-history-mode" style="width: 18%;"><?= htmlEscape($mediaApps->getJobModeName($job)) ?></td>
                                        <?php
                                        $jobType = intval($job['sync_type'] ?? MediaSyncTypes::USERS);
                                        $users   = [];
                                        if ($jobType != MediaSyncTypes::LIBRARY && $jobType != MediaSyncTypes::LIBRARIES && !empty($job['users'])) {
                                            $users = array_values($job['users']);
                                        }
                                        ?>
                                        <td class="sync-history-users"><?php if ($jobType == MediaSyncTypes::LIBRARY || $jobType == MediaSyncTypes::LIBRARIES) { ?>
                                        <?php } else if (!$users) { ?>
                                                                        <?= htmlEscape(translate('allUsers')) ?>
                                        <?php } else if (count($users) <= 3) { ?>
                                                                        <?= htmlEscape(implode(', ', $users)) ?>
                                        <?php } else { ?>
                                                                                                                        <span class="sync-history-details">
                                                                            <?= htmlEscape(implode(', ', array_slice($users, 0, 3))) ?>
                                                                                                                            <a href="#" class="sync-history-toggle"><?= htmlEscape(translate('moreUsers', [count($users) - 3])) ?></a>
                                                                                                                            <div class="sync-history-list" hidden onclick="event.stopPropagation();"><?php foreach (array_slice($users, 3) as $userName) { ?>
                                                                                                                                                            <div><?= htmlEscape($userName) ?></div>
                                                                            <?php } ?></div>
                                                                                                                        </span>
                                        <?php } ?></td>
                                        <td class="sync-history-status text-center" style="width: 7%;">
                                            <?php $jobStatus = $job['status'] ?? 'running'; ?>
                                            <?= syncJobStatusHtml($jobStatus, $job['id'] ?? '') ?>
                                        </td>
                                        <td class="sync-history-size" style="width: 7%;"><?= htmlEscape(byteConversion($job['size'] ?? 0)) ?></td>
                                        <td class="sync-history-remove text-center" style="width: 5%;">
                                            <?php if ($jobStatus != 'running' && $jobStatus != 'queued') { ?>
                                                                <i class="fa-solid fa-rotate-right text-info me-2" style="cursor: pointer;" title="<?= htmlEscape(translate('reRun')) ?>" onclick="event.stopPropagation(); requeueSync('<?= htmlEscape($job['id']) ?>')"></i>
                                            <?php } ?>
                                            <i class="fas fa-trash text-danger" style="cursor: pointer;" title="<?= htmlEscape(translate('remove')) ?>" onclick="event.stopPropagation(); deleteLog('<?= htmlEscape($job['id']) ?>')"></i>
                                        </td>
                                    </tr>
        <?php } ?>
    </tbody>
</table>
</div>
