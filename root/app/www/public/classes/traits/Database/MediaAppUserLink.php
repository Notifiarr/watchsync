<?php

/*
----------------------------------
------  Created: 091226   ------
------  Austin Best       ------
----------------------------------
*/

trait MediaAppUserLink
{
    public function getMediaAppUserLinks($mediaAppUserId = 0)
    {
        $links = [];

        $sql = "SELECT id, media_app_user_id, linked_media_app_user_id
                FROM " . MEDIA_APP_USER_LINK_TABLE;
        if (intval($mediaAppUserId)) {
            $sql .= " WHERE media_app_user_id = " . intval($mediaAppUserId);
        }
        $res = $this->query($sql);
        while ($row = $this->fetchAssoc($res)) {
            $links[] = $row;
        }

        return $links;
    }

    public function getMediaAppUserLink($linkedMediaAppUserId)
    {
        $sql = "SELECT id, media_app_user_id, linked_media_app_user_id
                FROM " . MEDIA_APP_USER_LINK_TABLE . "
                WHERE linked_media_app_user_id = " . intval($linkedMediaAppUserId);
        $res = $this->query($sql);
        $row = $this->fetchAssoc($res);

        return $row ?: [];
    }

    public function getMediaAppUserLinkForApp($mediaAppUserId, $mediaAppId)
    {
        $sql = "SELECT l.id, l.media_app_user_id, l.linked_media_app_user_id
                FROM " . MEDIA_APP_USER_LINK_TABLE . " l
                JOIN " . MEDIA_APP_USER_TABLE . " u ON u.id = l.linked_media_app_user_id
                WHERE l.media_app_user_id = " . intval($mediaAppUserId) . "
                AND u.media_app_id = " . intval($mediaAppId);
        $res = $this->query($sql);
        $row = $this->fetchAssoc($res);

        return $row ?: [];
    }

    public function addMediaAppUserLink($mediaAppUserId, $linkedMediaAppUserId)
    {
        $existing = $this->getMediaAppUserLink($linkedMediaAppUserId);
        if ($existing) {
            if (intval($existing['media_app_user_id']) != intval($mediaAppUserId)) {
                $sql = "UPDATE " . MEDIA_APP_USER_LINK_TABLE . "
                        SET media_app_user_id = " . intval($mediaAppUserId) . "
                        WHERE id = " . intval($existing['id']);
                $res = $this->query($sql);
            }

            return intval($existing['id']);
        }

        $sql = "INSERT INTO " . MEDIA_APP_USER_LINK_TABLE . "
                (`media_app_user_id`, `linked_media_app_user_id`)
                VALUES
                (" . intval($mediaAppUserId) . ", " . intval($linkedMediaAppUserId) . ")";
        $res = $this->query($sql);

        return $this->insertId();
    }

    public function deleteMediaAppUserLink($linkedMediaAppUserId)
    {
        $sql = "DELETE FROM " . MEDIA_APP_USER_LINK_TABLE . "
                WHERE linked_media_app_user_id = " . intval($linkedMediaAppUserId);
        $res = $this->query($sql);

        return $res;
    }

    public function deleteMediaAppUserLinksByUserIds($userIds)
    {
        if (!$userIds) {
            return;
        }

        $ids = [];
        foreach ($userIds as $userId) {
            $ids[] = intval($userId);
        }

        $sql = "DELETE FROM " . MEDIA_APP_USER_LINK_TABLE . "
                WHERE media_app_user_id IN (" . implode(',', $ids) . ")
                OR linked_media_app_user_id IN (" . implode(',', $ids) . ")";
        $res = $this->query($sql);

        return $res;
    }
}
