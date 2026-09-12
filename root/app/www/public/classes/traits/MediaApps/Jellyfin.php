<?php

/*
----------------------------------
------  Created: 091026   ------
------  Austin Best       ------
----------------------------------
*/

trait Jellyfin
{
    public function jellyfinTestConnection($url, $apikey)
    {
        return $this->testEmbyJellyfinConnection($url, $apikey);
    }

    public function jellyfinGetLibraries($url, $apikey)
    {
        return $this->embyGetLibraries($url, $apikey);
    }

    public function jellyfinGetItems($url, $apikey, $libraryKeys = [], $seriesRemoteId = '', $seriesTitle = '', $since = 0)
    {
        return $this->embyGetItems($url, $apikey, $libraryKeys, $seriesRemoteId, $seriesTitle, $since);
    }

    public function jellyfinGetWatchStatus($url, $apikey, $remoteId, $username = '')
    {
        return $this->embyGetWatchStatus($url, $apikey, $remoteId, $username);
    }

    public function jellyfinSetWatchStatus($url, $apikey, $userId, $remoteId, $started, $inprogress, $finished)
    {
        return $this->embySetWatchStatus($url, $apikey, $userId, $remoteId, $started, $inprogress, $finished);
    }

    public function jellyfinGetUsers($url, $apikey)
    {
        return $this->getEmbyJellyfinUsers($url, $apikey);
    }

    public function jellyfinCreateLibrary($url, $apikey, $title, $type, $paths)
    {
        return $this->embyCreateLibrary($url, $apikey, $title, $type, $paths);
    }

    public function jellyfinCreateUser($url, $apikey, $username)
    {
        return $this->embyCreateUser($url, $apikey, $username);
    }

    public function jellyfinUpdateUser($url, $apikey, $remoteId, $username)
    {
        return $this->embyUpdateUser($url, $apikey, $remoteId, $username);
    }

    public function jellyfinDeleteUser($url, $apikey, $remoteId)
    {
        return $this->embyDeleteUser($url, $apikey, $remoteId);
    }
}
