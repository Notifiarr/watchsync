<?php

/*
----------------------------------
------  Created: 091026   ------
------  Austin Best       ------
----------------------------------
*/

trait MediaApp
{
    public function getMediaApps()
    {
        $mediaApps = [];

        $sql = "SELECT id, name, platform, url, token, apikey, role, sync_mode, active, server_id, server_name, last_scan, needs_sync
                FROM " . MEDIA_APP_TABLE . "
                ORDER BY role ASC, name ASC";
        $res = $this->query($sql);
        while ($row = $this->fetchAssoc($res)) {
            $mediaApps[$row['id']] = $row;
        }

        return $mediaApps;
    }

    public function getMediaApp($id)
    {
        $sql = "SELECT id, name, platform, url, token, apikey, role, sync_mode, active, server_id, server_name, last_scan, needs_sync
                FROM " . MEDIA_APP_TABLE . "
                WHERE id = " . intval($id);
        $res = $this->query($sql);
        $row = $this->fetchAssoc($res);

        return $row ?: [];
    }

    public function addMediaApp($name, $platform, $url, $token, $apikey, $role, $syncMode, $active, $serverId, $serverName)
    {
        $sql = "INSERT INTO " . MEDIA_APP_TABLE . "
                (`name`, `platform`, `url`, `token`, `apikey`, `role`, `sync_mode`, `active`, `server_id`, `server_name`)
                VALUES
                ('" . $this->prepare($name) . "', " . intval($platform) . ", '" . $this->prepare($url) . "', '" . $this->prepare($token) . "', '" . $this->prepare($apikey) . "', " . intval($role) . ", " . intval($syncMode) . ", " . intval($active) . ", '" . $this->prepare($serverId) . "', '" . $this->prepare($serverName) . "')";
        $res = $this->query($sql);

        return $this->insertId();
    }

    public function updateMediaApp($id, $name, $platform, $url, $token, $apikey, $role, $syncMode, $active, $serverId, $serverName)
    {
        $sql = "UPDATE " . MEDIA_APP_TABLE . "
                SET name = '" . $this->prepare($name) . "',
                    platform = " . intval($platform) . ",
                    url = '" . $this->prepare($url) . "',
                    token = '" . $this->prepare($token) . "',
                    apikey = '" . $this->prepare($apikey) . "',
                    role = " . intval($role) . ",
                    sync_mode = " . intval($syncMode) . ",
                    active = " . intval($active) . ",
                    server_id = '" . $this->prepare($serverId) . "',
                    server_name = '" . $this->prepare($serverName) . "'
                WHERE id = " . intval($id);
        $res = $this->query($sql);

        return $res;
    }

    public function setMediaAppActive($id, $active)
    {
        $sql = "UPDATE " . MEDIA_APP_TABLE . "
                SET active = " . intval($active) . "
                WHERE id = " . intval($id);
        $res = $this->query($sql);

        return $res;
    }

    public function setMediaAppRole($id, $role)
    {
        $sql = "UPDATE " . MEDIA_APP_TABLE . "
                SET role = " . intval($role) . "
                WHERE id = " . intval($id);
        $res = $this->query($sql);

        return $res;
    }

    public function setMediaAppLastScan($id, $lastScan)
    {
        $sql = "UPDATE " . MEDIA_APP_TABLE . "
                SET last_scan = " . intval($lastScan) . ",
                    needs_sync = 0
                WHERE id = " . intval($id);
        $res = $this->query($sql);

        return $res;
    }

    public function setMediaAppNeedsSync($id, $needsSync = 1)
    {
        $sql = "UPDATE " . MEDIA_APP_TABLE . "
                SET needs_sync = " . intval($needsSync) . "
                WHERE id = " . intval($id);
        $res = $this->query($sql);

        return $res;
    }

    public function setAllMediaAppsListener()
    {
        $sql = "UPDATE " . MEDIA_APP_TABLE . "
                SET role = " . MediaAppRoles::LISTENER . "
                WHERE id > 0";
        $res = $this->query($sql);

        return $res;
    }

    public function deleteMediaApp($id)
    {
        $userIds = [];
        $sql     = "SELECT id
                    FROM " . MEDIA_APP_USER_TABLE . "
                    WHERE media_app_id = " . intval($id);
        $res     = $this->query($sql);
        while ($row = $this->fetchAssoc($res)) {
            $userIds[] = $row['id'];
        }

        $this->deleteMediaAppUserLinksByUserIds($userIds);
        $this->deleteMediaAppLibraryLinks($id);
        $this->deleteUserMovieLinksByUserIds($userIds);
        $this->deleteUserEpisodeLinksByUserIds($userIds);

        $sql = "DELETE FROM " . MEDIA_APP_USER_TABLE . "
                WHERE media_app_id = " . intval($id);
        $res = $this->query($sql);

        $sql = "DELETE FROM " . MEDIA_APP_TABLE . "
                WHERE id = " . intval($id);
        $res = $this->query($sql);

        return $res;
    }
}
