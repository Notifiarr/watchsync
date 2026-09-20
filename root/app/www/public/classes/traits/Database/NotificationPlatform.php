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

        $sql = "DELETE FROM " . NOTIFICATION_LINK_TABLE . "
                WHERE platform = 4";
        $this->query($sql);
        $sql = "DELETE FROM " . NOTIFICATION_PLATFORM_TABLE . "
                WHERE id = 4 OR platform = 'Webhook'";
        $this->query($sql);

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
