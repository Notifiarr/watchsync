<?php

/*
----------------------------------
------  Created: 091026   ------
------  Austin Best       ------
----------------------------------
*/

trait NotificationTrigger
{
    public function getNotificationTriggers()
    {
        $this->ensureCamelCaseNotificationTriggers();

        if ($this->notificationTriggersTable) {
            return $this->notificationTriggersTable;
        }

        $notificationTriggersTable = [];

        $sql = "SELECT id, name, label, description, event
                FROM " . NOTIFICATION_TRIGGER_TABLE . "
                ORDER BY label ASC";
        $res = $this->query($sql);
        while ($row = $this->fetchAssoc($res)) {
            $notificationTriggersTable[$row['id']] = $row;
        }

        $this->notificationTriggersTable = $notificationTriggersTable;

        return $notificationTriggersTable;
    }

    public function ensureCamelCaseNotificationTriggers()
    {
        if (!intval($this->getSetting('notification_triggers_sync_update_removed'))) {
            $this->query("DELETE FROM " . NOTIFICATION_TRIGGER_TABLE . "
                    WHERE `name` IN ('sync_update', 'syncUpdate')");
            $this->notificationTriggersTable = null;
            $this->setSetting('notification_triggers_sync_update_removed', '1');
        }

        if (intval($this->getSetting('notification_triggers_camelcase_done'))) {
            return;
        }

        $renames = [
            'user_login'       => 'userLogin',
            'user_update'      => 'userUpdate',
            'connection_error' => 'connectionError',
            'sync_start'       => 'syncStart',
            'sync_end'         => 'syncEnd',
        ];
        foreach ($renames as $old => $new) {
            $this->query("UPDATE " . NOTIFICATION_TRIGGER_TABLE . "
                    SET `name` = '" . $this->prepare($new) . "'
                    WHERE `name` = '" . $this->prepare($old) . "'");
        }

        $this->notificationTriggersTable = null;
        $this->setSetting('notification_triggers_camelcase_done', '1');
    }

    public function getNotificationTriggerFromName($name)
    {
        $triggers = $this->getNotificationTriggers();

        foreach ($triggers as $trigger) {
            if ($name == $trigger['name']) {
                return $trigger;
            }
        }

        return [];
    }
}
