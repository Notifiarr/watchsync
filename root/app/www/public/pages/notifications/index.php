<?php

/*
----------------------------------
------  Created: 091026   ------
------  Austin Best       ------
----------------------------------
*/

$notificationPlatformTable = $database->getNotificationPlatforms();
$notificationTriggersTable = $database->getNotificationTriggers();
$notificationLinkTable     = $database->getNotificationLinks();

?>
<div class="row">
    <div class="col-12 mb-3">
        <h1 class="h3 mb-0"><?= htmlEscape(translate('notifications')) ?></h1>
    </div>
    <div class="col-12 mb-4">
        <div class="card border shadow-sm">
            <div class="card-body">
                <h2 class="h5 mb-3"><?= htmlEscape(translate('platforms')) ?></h2>
                <div class="row">
                    <?php foreach ($notificationPlatformTable as $notificationPlatform) {
                        $canAdd = $notificationPlatform['parameters'] ? true : false;
                        ?>
                                            <div class="col-sm-4 mb-3">
                                                <div class="card border h-100">
                                                    <div class="card-body text-center">
                                                        <h3 class="h5 mb-0">
                                                            <?= htmlEscape($notificationPlatform['platform']) ?>
                                                            <?php if ($canAdd) { ?>
                                                                                    <i class="fas fa-plus-circle ms-2" style="cursor: pointer;" title="<?= htmlEscape(translate('add')) ?>" onclick="openNotificationTriggers(<?= intval($notificationPlatform['id']) ?>)"></i>
                                                            <?php } ?>
                                                        </h3>
                                                    </div>
                                                </div>
                                            </div>
                    <?php } ?>
                </div>
            </div>
        </div>
    </div>
    <div class="col-12">
        <div class="card border shadow-sm">
            <div class="card-body">
                <h2 class="h5 mb-3"><?= htmlEscape(translate('configuredSenders')) ?></h2>
                <?php if (!$notificationLinkTable) { ?>
                                        <p class="text-body-secondary mb-0"><?= htmlEscape(translate('notificationsNotSetup')) ?></p>
                <?php } else { ?>
                                        <div class="row">
                                            <?php foreach ($notificationLinkTable as $notificationLink) {
                                                $triggerIds      = $notificationLink['trigger_ids'] ? json_decode($notificationLink['trigger_ids'], true) : [];
                                                $enabledTriggers = [];
                                                if (is_array($triggerIds)) {
                                                    foreach ($triggerIds as $triggerId) {
                                                        $triggerLabel = $notifications->getNotificationTriggerNameFromId($triggerId, $notificationTriggersTable);
                                                        if ($triggerLabel) {
                                                            $enabledTriggers[] = $triggerLabel;
                                                        }
                                                    }
                                                }
                                                ?>
                                                                    <div class="col-sm-4 mb-3">
                                                                        <div class="card border h-100">
                                                                            <div class="card-body">
                                                                                <h3 class="h5">
                                                                                    <?= htmlEscape($notificationLink['name']) ?>
                                                                                    <i class="fas fa-tools ms-2" style="cursor: pointer;" title="<?= htmlEscape(translate('save')) ?>" onclick="openNotificationTriggers(<?= intval($notificationLink['platform']) ?>, <?= intval($notificationLink['id']) ?>)"></i>
                                                                                    <i class="far fa-bell ms-1" style="cursor: pointer;" title="<?= htmlEscape(translate('testNotification')) ?>" onclick="testNotify(<?= intval($notificationLink['id']) ?>, 'test')"></i>
                                                                                </h3>
                                                                                <div>
                                                                                    <span class="text-success"><?= htmlEscape(translate('enabled')) ?>:</span>
                                                                                    <?= htmlEscape($enabledTriggers ? implode(', ', $enabledTriggers) : translate('noTriggersEnabled')) ?>
                                                                                </div>
                                                                            </div>
                                                                        </div>
                                                                    </div>
                                            <?php } ?>
                                        </div>
                <?php } ?>
            </div>
        </div>
    </div>
</div>
