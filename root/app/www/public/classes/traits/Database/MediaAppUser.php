<?php

/*
----------------------------------
------  Created: 091026   ------
------  Austin Best       ------
----------------------------------
*/

trait MediaAppUser
{
    public function getMediaAppUsers($mediaAppId)
    {
        $users = [];

        $sql = "SELECT id, media_app_id, remote_id, username, email, user_type, is_admin, last_seen
                FROM " . MEDIA_APP_USER_TABLE . "
                WHERE media_app_id = " . intval($mediaAppId) . "
                ORDER BY username ASC";
        $res = $this->query($sql);
        while ($row = $this->fetchAssoc($res)) {
            $users[] = $row;
        }

        return $this->sortMediaAppUsers($users);
    }

    public function getMediaAppUser($id)
    {
        $sql = "SELECT id, media_app_id, remote_id, username, email, user_type, is_admin, last_seen
                FROM " . MEDIA_APP_USER_TABLE . "
                WHERE id = " . intval($id);
        $res = $this->query($sql);
        $row = $this->fetchAssoc($res);

        return $row ?: [];
    }

    public function sortMediaAppUsers($users)
    {
        usort($users, function ($a, $b) {
            $typeOrder = ['home' => 0, 'guest' => 0, 'shared' => 1];
            $aType     = $typeOrder[$a['user_type'] ?? ''] ?? 0;
            $bType     = $typeOrder[$b['user_type'] ?? ''] ?? 0;
            if ($aType != $bType) {
                return $aType - $bType;
            }

            $aAdmin = !empty($a['is_admin']) ? 0 : 1;
            $bAdmin = !empty($b['is_admin']) ? 0 : 1;
            if ($aAdmin != $bAdmin) {
                return $aAdmin - $bAdmin;
            }

            return strcasecmp($a['username'] ?? '', $b['username'] ?? '');
        });

        return $users;
    }

    public function replaceMediaAppUsers($mediaAppId, $users)
    {
        $existing = [];
        $sql      = "SELECT id, remote_id
                     FROM " . MEDIA_APP_USER_TABLE . "
                     WHERE media_app_id = " . intval($mediaAppId);
        $res      = $this->query($sql);
        while ($row = $this->fetchAssoc($res)) {
            $existing[$row['remote_id']] = $row['id'];
        }

        $keep = [];
        if ($users) {
            $users = $this->sortMediaAppUsers($users);
            foreach ($users as $user) {
                $remoteId = $user['remote_id'];
                if (!empty($existing[$remoteId])) {
                    $sql = "UPDATE " . MEDIA_APP_USER_TABLE . "
                            SET username = '" . $this->prepare($user['username']) . "',
                                email = '" . $this->prepare($user['email'] ?? '') . "',
                                user_type = '" . $this->prepare($user['user_type'] ?? '') . "',
                                is_admin = " . intval($user['is_admin']) . ",
                                last_seen = " . intval($user['last_seen'] ?? 0) . "
                            WHERE id = " . intval($existing[$remoteId]);
                    $res1  = $this->query($sql);
                    $keep[] = intval($existing[$remoteId]);
                } else {
                    $sql = "INSERT INTO " . MEDIA_APP_USER_TABLE . "
                            (`media_app_id`, `remote_id`, `username`, `email`, `user_type`, `is_admin`, `last_seen`)
                            VALUES
                            (" . intval($mediaAppId) . ", '" . $this->prepare($user['remote_id']) . "', '" . $this->prepare($user['username']) . "', '" . $this->prepare($user['email'] ?? '') . "', '" . $this->prepare($user['user_type'] ?? '') . "', " . intval($user['is_admin']) . ", " . intval($user['last_seen'] ?? 0) . ")";
                    $res1   = $this->query($sql);
                    $keep[] = $this->insertId();
                }
            }
        }

        $remove = [];
        foreach ($existing as $existingId) {
            if (!in_array(intval($existingId), $keep)) {
                $remove[] = intval($existingId);
            }
        }

        if ($remove) {
            $this->deleteMediaAppUserLinksByUserIds($remove);
            $this->deleteUserMovieLinksByUserIds($remove);
            $this->deleteUserEpisodeLinksByUserIds($remove);
            $sql = "DELETE FROM " . MEDIA_APP_USER_TABLE . "
                    WHERE id IN (" . implode(',', $remove) . ")";
            $res = $this->query($sql);
        }
    }
}
