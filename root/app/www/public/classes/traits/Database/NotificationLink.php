<?php

/*
----------------------------------
------  Created: 091026   ------
------  Austin Best       ------
----------------------------------
*/

trait NotificationLink
{
    public function getNotificationLinks()
    {
        if ($this->notificationLinkTable) {
            return $this->notificationLinkTable;
        }

        $notificationLinkTable = [];

        $sql = "SELECT id, name, platform, platform_parameters, trigger_ids
                FROM " . NOTIFICATION_LINK_TABLE;
        $res = $this->query($sql);
        while ($row = $this->fetchAssoc($res)) {
            $notificationLinkTable[$row['id']] = $row;
        }

        $this->notificationLinkTable = $notificationLinkTable;

        return $notificationLinkTable;
    }

    public function addNotificationLink($platformId, $triggerIds, $platformParameters, $senderName)
    {
        $sql = "INSERT INTO " . NOTIFICATION_LINK_TABLE . "
                (`name`, `platform`, `platform_parameters`, `trigger_ids`)
                VALUES
                ('" . $this->prepare($senderName) . "', " . intval($platformId) . ", '" . $this->prepare(json_encode($platformParameters)) . "', '" . $this->prepare(json_encode($triggerIds)) . "')";
        $res = $this->query($sql);

        $this->notificationLinkTable = '';

        return $this->getNotificationLinks();
    }

    public function updateNotificationLink($linkId, $triggerIds, $platformParameters, $senderName)
    {
        $sql = "UPDATE " . NOTIFICATION_LINK_TABLE . "
                SET name = '" . $this->prepare($senderName) . "',
                    platform_parameters = '" . $this->prepare(json_encode($platformParameters)) . "',
                    trigger_ids = '" . $this->prepare(json_encode($triggerIds)) . "'
                WHERE id = " . intval($linkId);
        $res = $this->query($sql);

        $this->notificationLinkTable = '';

        return $this->getNotificationLinks();
    }

    public function deleteNotificationLink($linkId)
    {
        $sql = "DELETE FROM " . NOTIFICATION_LINK_TABLE . "
                WHERE id = " . intval($linkId);
        $res = $this->query($sql);

        $this->notificationLinkTable = '';

        return $this->getNotificationLinks();
    }
}
