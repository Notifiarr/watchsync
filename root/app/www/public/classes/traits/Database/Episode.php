<?php

/*
----------------------------------
------  Created: 091126   ------
------  Austin Best       ------
----------------------------------
*/

trait Episode
{
    public function getEpisodes()
    {
        $episodes = [];

        $sql = "SELECT id, series_id, season, episode, title, path, plex, emby, jellyfin, plex_remote_id, emby_remote_id, jellyfin_remote_id, poster
                FROM " . EPISODE_TABLE . "
                ORDER BY series_id ASC, season ASC, episode ASC";
        $res = $this->query($sql);
        while ($row = $this->fetchAssoc($res)) {
            $episodes[] = $row;
        }

        return $episodes;
    }

    public function getEpisode($id)
    {
        $sql = "SELECT id, series_id, season, episode, title, path, plex, emby, jellyfin, plex_remote_id, emby_remote_id, jellyfin_remote_id, poster
                FROM " . EPISODE_TABLE . "
                WHERE id = " . intval($id);
        $res = $this->query($sql);
        $row = $this->fetchAssoc($res);

        return $row ?: [];
    }

    public function getEpisodeByRemoteId($platform, $remoteId)
    {
        $field = $this->mediaLibraryRemoteField($platform);
        if (!$field || $remoteId == '' || $remoteId == null) {
            return [];
        }

        $sql = "SELECT id, series_id, season, episode, title, path, plex, emby, jellyfin, plex_remote_id, emby_remote_id, jellyfin_remote_id, poster
                FROM " . EPISODE_TABLE . "
                WHERE " . $field . " = '" . $this->prepare($remoteId) . "'";
        $res = $this->query($sql);
        $row = $this->fetchAssoc($res);

        return $row ?: [];
    }

    public function addEpisode($seriesId, $season, $episode, $title, $path, $plex, $emby, $jellyfin, $plexRemoteId, $embyRemoteId, $jellyfinRemoteId, $seriesWhere = '', $poster = '')
    {
        $path = $this->normalizePath($path, 'episode');
        if ($path == '') {
            return 0;
        }

        if (intval($seriesId)) {
            $sql = "INSERT INTO " . EPISODE_TABLE . "
                    (`series_id`, `season`, `episode`, `title`, `path`, `plex`, `emby`, `jellyfin`, `plex_remote_id`, `emby_remote_id`, `jellyfin_remote_id`, `poster`)
                    VALUES
                    (" . intval($seriesId) . ", " . intval($season) . ", " . intval($episode) . ", '" . $this->prepare($title) . "', '" . $this->prepare($path) . "', " . intval($plex) . ", " . intval($emby) . ", " . intval($jellyfin) . ", " . $this->sqlStringOrNull($plexRemoteId) . ", " . $this->sqlStringOrNull($embyRemoteId) . ", " . $this->sqlStringOrNull($jellyfinRemoteId) . ", '" . $this->prepare($poster) . "')";
        } else if ($seriesWhere != '') {
            $sql = "INSERT INTO " . EPISODE_TABLE . "
                    (`series_id`, `season`, `episode`, `title`, `path`, `plex`, `emby`, `jellyfin`, `plex_remote_id`, `emby_remote_id`, `jellyfin_remote_id`, `poster`)
                    SELECT id, " . intval($season) . ", " . intval($episode) . ", '" . $this->prepare($title) . "', '" . $this->prepare($path) . "', " . intval($plex) . ", " . intval($emby) . ", " . intval($jellyfin) . ", " . $this->sqlStringOrNull($plexRemoteId) . ", " . $this->sqlStringOrNull($embyRemoteId) . ", " . $this->sqlStringOrNull($jellyfinRemoteId) . ", '" . $this->prepare($poster) . "'
                    FROM " . SERIES_TABLE . "
                    WHERE " . $seriesWhere . "
                    LIMIT 1";
        } else {
            return 0;
        }
        if (!$this->query($sql)) {
            return 0;
        }

        return intval($this->insertId());
    }

    public function setEpisodePlatform($platform, $remoteId, $path)
    {
        return $this->setMediaLibraryPlatform(EPISODE_TABLE, $platform, $remoteId, $path);
    }
}
