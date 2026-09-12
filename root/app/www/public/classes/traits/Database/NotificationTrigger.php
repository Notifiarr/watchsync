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
        if ($this->notificationTriggersTable) {
            return $this->notificationTriggersTable;
        }

        $notificationTriggersTable = [];

        $sql = "SELECT id, name, label, description, event
                FROM " . NOTIFICATION_TRIGGER_TABLE;
        $res = $this->query($sql);
        while ($row = $this->fetchAssoc($res)) {
            $notificationTriggersTable[$row['id']] = $row;
        }

        $this->notificationTriggersTable = $notificationTriggersTable;

        return $notificationTriggersTable;
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
