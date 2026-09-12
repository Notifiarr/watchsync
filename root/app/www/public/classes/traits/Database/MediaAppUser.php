<?php

/*
----------------------------------
------  Created: 091026   ------
------  Austin Best       ------
----------------------------------
*/

trait MediaAppUser
{
    public function getMediaAppUsers($mediaAppId, $alignUsers = [])
    {
        $id = intval($mediaAppId);
        if (isset($this->usersCache[$id])) {
            $users = $this->usersCache[$id];
        } else {
            $users = [];
            $sql   = "SELECT id, media_app_id, remote_id, username, email, user_type, is_admin, last_seen, token_age, pin, pin_required,
                            (CASE WHEN token IS NOT NULL AND token != '' THEN 1 ELSE 0 END) AS has_token
                    FROM " . MEDIA_APP_USER_TABLE . "
                    WHERE media_app_id = " . $id;
            $res   = $this->query($sql);
            while ($row = $this->fetchAssoc($res)) {
                $users[] = $row;
            }
            $users                 = $this->sortMediaAppUsers($users);
            $this->usersCache[$id] = $users;
        }
        if (!$alignUsers) {
            return $users;
        }

        $byId   = [];
        $byName = [];
        foreach ($users as $user) {
            $byId[intval($user['id'])] = $user;
            $name                      = strtolower(trim($user['username'] ?? ''));
            if ($name != '') {
                $byName[$name] = $user;
            }
        }

        $linksByMaster = [];
        foreach ($this->getMediaAppUserLinks() as $link) {
            $linkedId = intval($link['linked_media_app_user_id']);
            if ($linkedId && !empty($byId[$linkedId])) {
                $linksByMaster[intval($link['media_app_user_id'])] = $linkedId;
            }
        }

        $aligned = [];
        $seen    = [];
        foreach ($alignUsers as $match) {
            $picked   = [];
            $linkedId = intval($linksByMaster[intval($match['id'] ?? 0)] ?? 0);
            if ($linkedId && !empty($byId[$linkedId])) {
                $picked = $byId[$linkedId];
            } else {
                $name = strtolower(trim($match['username'] ?? ''));
                if ($name != '' && !empty($byName[$name])) {
                    $picked = $byName[$name];
                }
            }
            if (!$picked) {
                $aligned[] = [];
                continue;
            }
            $id = intval($picked['id']);
            if (!$id || !empty($seen[$id])) {
                $aligned[] = [];
                continue;
            }
            $seen[$id] = true;
            $aligned[] = $picked;
        }

        return $aligned;
    }

    public function getMediaAppUser($id)
    {
        $sql = "SELECT id, media_app_id, remote_id, username, email, user_type, is_admin, last_seen, token, token_age, pin, pin_required,
                        (CASE WHEN token IS NOT NULL AND token != '' THEN 1 ELSE 0 END) AS has_token
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

    public function updateMediaAppUserRemoteId($id, $remoteId)
    {
        $remoteId = trim(strval($remoteId ?? ''));
        if (!intval($id) || $remoteId == '') {
            return;
        }

        $sql = "UPDATE " . MEDIA_APP_USER_TABLE . "
                SET remote_id = '" . $this->prepare($remoteId) . "'
                WHERE id = " . intval($id);
        $this->query($sql);
    }

    public function updateMediaAppUserLastSeen($id, $seen)
    {
        $id   = intval($id);
        $seen = intval($seen);
        if (!$id || $seen <= 0) {
            return;
        }

        $sql = "UPDATE " . MEDIA_APP_USER_TABLE . "
                SET last_seen = " . $seen . "
                WHERE id = " . $id . "
                AND last_seen < " . $seen;
        $this->query($sql);
        $this->usersCache = [];
    }

    public function updateMediaAppUserToken($id, $token, $manual = true)
    {
        $id    = intval($id);
        $token = trim(strval($token ?? ''));
        if (!$id) {
            return false;
        }

        if ($token == '') {
            $tokenAge = 0;
        } else if ($manual) {
            $tokenAge = -1;
        } else {
            $tokenAge = time();
        }
        $sql = "UPDATE " . MEDIA_APP_USER_TABLE . "
                SET token = '" . $this->prepare($token) . "',
                    token_age = " . intval($tokenAge) . "
                WHERE id = " . $id;
        $this->query($sql);
        $this->usersCache = [];

        return true;
    }

    public function lockMediaAppUserToken($id)
    {
        $id = intval($id);
        if (!$id) {
            return false;
        }

        $sql = "UPDATE " . MEDIA_APP_USER_TABLE . "
                SET token = '',
                    token_age = " . (-1 * time()) . "
                WHERE id = " . $id;
        $this->query($sql);
        $this->usersCache = [];

        return true;
    }

    public function updateMediaAppUserPinRequired($id, $pinRequired)
    {
        $id = intval($id);
        if (!$id) {
            return false;
        }

        $sql = "UPDATE " . MEDIA_APP_USER_TABLE . "
                SET pin_required = " . (!empty($pinRequired) ? 1 : 0) . "
                WHERE id = " . $id;
        $this->query($sql);
        $this->usersCache = [];

        return true;
    }

    public function updateMediaAppUserPin($id, $pin)
    {
        $id  = intval($id);
        $pin = trim(strval($pin ?? ''));
        if (!$id) {
            return false;
        }

        $sql = "UPDATE " . MEDIA_APP_USER_TABLE . "
                SET pin = '" . $this->prepare($pin) . "'
                WHERE id = " . $id;
        $this->query($sql);
        $this->usersCache = [];

        return true;
    }

    public function replaceMediaAppUsers($mediaAppId, $users)
    {
        $existingByRemote = [];
        $existingByName   = [];
        $existingIds      = [];
        $sql              = "SELECT id, remote_id, username, email, user_type, is_admin, token, token_age, pin, pin_required
                             FROM " . MEDIA_APP_USER_TABLE . "
                             WHERE media_app_id = " . intval($mediaAppId);
        $res              = $this->query($sql);
        if ($res == false) {
            return;
        }
        while ($row = $this->fetchAssoc($res)) {
            $existingIds[intval($row['id'])] = intval($row['id']);
            if (($row['remote_id'] ?? '') != '') {
                $existingByRemote[$row['remote_id']] = $row;
            }
            $name = strtolower(trim($row['username'] ?? ''));
            if ($name != '' && (empty($existingByName[$name]) || intval($row['id']) < intval($existingByName[$name]['id']))) {
                $existingByName[$name] = $row;
            }
        }

        if (!$users) {
            return;
        }

        $keep        = [];
        $keepName    = [];
        $writeFailed = false;
        $users       = $this->sortMediaAppUsers($users);
        foreach ($users as $user) {
            $remoteId = trim(strval($user['remote_id'] ?? ''));
            $username = trim($user['username'] ?? '');
            if ($remoteId == '' || $remoteId == '0' || $username == '' || $username == '0') {
                continue;
            }
            if (!empty($keepName[strtolower($username)])) {
                continue;
            }

            $found = [];
            foreach (array_merge([$username], $user['match_names'] ?? []) as $name) {
                $name = strtolower(trim(strval($name)));
                if ($name != '' && !empty($existingByName[$name])) {
                    $found = $existingByName[$name];
                    break;
                }
            }
            if (!$found && !empty($existingByRemote[$remoteId])) {
                $found = $existingByRemote[$remoteId];
            }
            if (!$found) {
                foreach ($user['match_ids'] ?? [] as $matchId) {
                    $matchId = trim(strval($matchId));
                    if ($matchId != '' && !empty($existingByRemote[$matchId])) {
                        $found = $existingByRemote[$matchId];
                        break;
                    }
                }
            }

            $token      = trim(strval($user['token'] ?? ''));
            $tokenAge   = intval($user['token_age'] ?? 0);
            $foundAge   = intval($found['token_age'] ?? 0);
            $foundToken = trim(strval($found['token'] ?? ''));
            if ($found && $foundAge == -1) {
                $token    = $foundToken;
                $tokenAge = -1;
            } else if ($found && $foundAge < 0 && $foundToken == '') {
                $token    = '';
                $tokenAge = $foundAge;
            } else if ($token == '' && $found) {
                $token    = trim(strval($found['token'] ?? ''));
                $tokenAge = intval($found['token_age'] ?? 0);
            } else if ($token != '' && !$tokenAge) {
                $tokenAge = time();
            }
            $pin = trim(strval($found['pin'] ?? ''));
            if (array_key_exists('pin_required', $user)) {
                $pinRequired = !empty($user['pin_required']) ? 1 : 0;
            } else if ($found) {
                $pinRequired = intval($found['pin_required'] ?? 0);
            } else {
                $pinRequired = 0;
            }

            if ($found) {
                $email     = trim($user['email'] ?? '') != '' ? $user['email'] : ($found['email'] ?? '');
                $userType  = trim($user['user_type'] ?? '') != '' ? $user['user_type'] : ($found['user_type'] ?? '');
                $isAdmin   = !empty($user['is_admin']) ? 1 : intval($found['is_admin'] ?? 0);
                $lastSeen  = intval($user['last_seen'] ?? 0);
                $foundSeen = intval($found['last_seen'] ?? 0);
                if ($foundSeen > $lastSeen) {
                    $lastSeen = $foundSeen;
                }
                $sql = "UPDATE " . MEDIA_APP_USER_TABLE . "
                            SET remote_id = '" . $this->prepare($remoteId) . "',
                                username = '" . $this->prepare($username) . "',
                                email = '" . $this->prepare($email) . "',
                                user_type = '" . $this->prepare($userType) . "',
                                is_admin = " . $isAdmin . ",
                                last_seen = " . $lastSeen . ",
                                token = '" . $this->prepare($token) . "',
                                token_age = " . intval($tokenAge) . ",
                                pin = '" . $this->prepare($pin) . "',
                                pin_required = " . intval($pinRequired) . "
                            WHERE id = " . intval($found['id']);
                $this->query($sql);
                if ($this->error()) {
                    $writeFailed = true;
                    continue;
                }
                $keep[]                          = intval($found['id']);
                $keepName[strtolower($username)] = true;
                unset($existingByRemote[$found['remote_id'] ?? '']);
                unset($existingByRemote[$remoteId]);
                unset($existingByName[strtolower(trim($found['username'] ?? ''))]);
                unset($existingByName[strtolower($username)]);
            } else {
                $sql = "INSERT INTO " . MEDIA_APP_USER_TABLE . "
                            (`media_app_id`, `remote_id`, `username`, `email`, `user_type`, `is_admin`, `last_seen`, `token`, `token_age`, `pin`, `pin_required`)
                            VALUES
                            (" . intval($mediaAppId) . ", '" . $this->prepare($remoteId) . "', '" . $this->prepare($username) . "', '" . $this->prepare($user['email'] ?? '') . "', '" . $this->prepare($user['user_type'] ?? '') . "', " . intval($user['is_admin']) . ", " . intval($user['last_seen'] ?? 0) . ", '" . $this->prepare($token) . "', " . intval($tokenAge) . ", '" . $this->prepare($pin) . "', " . intval($pinRequired) . ")";
                $this->query($sql);
                if ($this->error()) {
                    $writeFailed = true;
                    continue;
                }
                $keep[]                          = $this->insertId();
                $keepName[strtolower($username)] = true;
            }
        }

        if ($writeFailed) {
            $this->usersCache = [];
            return;
        }

        $remove = [];
        foreach ($existingIds as $existingId) {
            if (!in_array($existingId, $keep)) {
                $remove[] = $existingId;
            }
        }

        if ($remove) {
            $this->deleteMediaAppUsersByIds($remove);
        }
        $this->usersCache = [];
    }

    public function deleteMediaAppUsersByIds($userIds)
    {
        $ids = [];
        foreach ($userIds as $userId) {
            $userId = intval($userId);
            if ($userId) {
                $ids[] = $userId;
            }
        }
        if (!$ids) {
            return false;
        }

        $this->deleteMediaAppUserLinksByUserIds($ids);
        $this->deleteUserMovieLinksByUserIds($ids);
        $this->deleteUserEpisodeLinksByUserIds($ids);
        $sql              = "DELETE FROM " . MEDIA_APP_USER_TABLE . "
                WHERE id IN (" . implode(',', $ids) . ")";
        $res              = $this->query($sql);
        $this->usersCache = [];

        return $res;
    }
}
