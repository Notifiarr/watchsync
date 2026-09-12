<?php

/*
----------------------------------
------  Created: 091026   ------
------  Austin Best       ------
----------------------------------
*/

trait NotificationPlatform
{
    public function getNotificationPlatforms()
    {
        if ($this->notificationPlatformTable) {
            return $this->notificationPlatformTable;
        }

        $notificationPlatformTable = [];

        $this->query("DELETE FROM " . NOTIFICATION_LINK_TABLE . " WHERE platform = 4");
        $this->query("DELETE FROM " . NOTIFICATION_PLATFORM_TABLE . " WHERE id = 4 OR platform = 'Webhook'");

        $sql = "SELECT id, platform, parameters
                FROM " . NOTIFICATION_PLATFORM_TABLE;
        $res = $this->query($sql);
        while ($row = $this->fetchAssoc($res)) {
            $notificationPlatformTable[$row['id']] = $row;
        }

        $this->notificationPlatformTable = $notificationPlatformTable;

        return $notificationPlatformTable;
    }
}
