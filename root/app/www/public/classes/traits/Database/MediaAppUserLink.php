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
        if (!is_array($this->userLinksCache)) {
            $this->userLinksCache = [];
            $sql                  = "SELECT id, media_app_user_id, linked_media_app_user_id
                    FROM " . MEDIA_APP_USER_LINK_TABLE;
            $res                  = $this->query($sql);
            while ($row = $this->fetchAssoc($res)) {
                $this->userLinksCache[] = $row;
            }
        }

        if (!intval($mediaAppUserId)) {
            return $this->userLinksCache;
        }

        $links = [];
        foreach ($this->userLinksCache as $row) {
            if (intval($row['media_app_user_id']) == intval($mediaAppUserId)) {
                $links[] = $row;
            }
        }

        return $links;
    }

    public function getMediaAppUserLink($linkedMediaAppUserId)
    {
        $linkedMediaAppUserId = intval($linkedMediaAppUserId);
        foreach ($this->getMediaAppUserLinks() as $row) {
            if (intval($row['linked_media_app_user_id']) == $linkedMediaAppUserId) {
                return $row;
            }
        }

        return [];
    }

    public function getMediaAppUserLinkForApp($mediaAppUserId, $mediaAppId)
    {
        $mediaAppUserId = intval($mediaAppUserId);
        $mediaAppId     = intval($mediaAppId);
        $appUsers       = [];
        foreach ($this->getMediaAppUsers($mediaAppId) as $user) {
            $userId = intval($user['id'] ?? 0);
            if ($userId) {
                $appUsers[$userId] = true;
            }
        }
        if (!$appUsers) {
            return [];
        }

        foreach ($this->getMediaAppUserLinks() as $row) {
            $masterId = intval($row['media_app_user_id']);
            $linkedId = intval($row['linked_media_app_user_id']);
            $otherId  = 0;
            if ($masterId == $mediaAppUserId && !empty($appUsers[$linkedId])) {
                $otherId = $linkedId;
            } else if ($linkedId == $mediaAppUserId && !empty($appUsers[$masterId])) {
                $otherId = $masterId;
            }
            if (!$otherId) {
                continue;
            }

            return [
                'id'                       => intval($row['id']),
                'media_app_user_id'        => $mediaAppUserId,
                'linked_media_app_user_id' => $otherId,
            ];
        }

        return [];
    }

    public function addMediaAppUserLink($mediaAppUserId, $linkedMediaAppUserId)
    {
        $existing = $this->getMediaAppUserLink($linkedMediaAppUserId);
        if ($existing) {
            if (intval($existing['media_app_user_id']) != intval($mediaAppUserId)) {
                $sql                  = "UPDATE " . MEDIA_APP_USER_LINK_TABLE . "
                        SET media_app_user_id = " . intval($mediaAppUserId) . "
                        WHERE id = " . intval($existing['id']);
                $res                  = $this->query($sql);
                $this->userLinksCache = null;
            }

            return intval($existing['id']);
        }

        $sql = "INSERT INTO " . MEDIA_APP_USER_LINK_TABLE . "
                (`media_app_user_id`, `linked_media_app_user_id`)
                VALUES
                (" . intval($mediaAppUserId) . ", " . intval($linkedMediaAppUserId) . ")";
        $res = $this->query($sql);

        $this->userLinksCache = null;

        return $this->insertId();
    }

    public function deleteMediaAppUserLink($linkedMediaAppUserId)
    {
        $sql                  = "DELETE FROM " . MEDIA_APP_USER_LINK_TABLE . "
                WHERE linked_media_app_user_id = " . intval($linkedMediaAppUserId);
        $res                  = $this->query($sql);
        $this->userLinksCache = null;

        return $res;
    }

    public function deleteMediaAppUserLinkById($id)
    {
        $sql = "DELETE FROM " . MEDIA_APP_USER_LINK_TABLE . "
                WHERE id = " . intval($id);
        $this->query($sql);
        $this->userLinksCache = null;
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

        $sql                  = "DELETE FROM " . MEDIA_APP_USER_LINK_TABLE . "
                WHERE media_app_user_id IN (" . implode(',', $ids) . ")
                OR linked_media_app_user_id IN (" . implode(',', $ids) . ")";
        $res                  = $this->query($sql);
        $this->userLinksCache = null;

        return $res;
    }
}
