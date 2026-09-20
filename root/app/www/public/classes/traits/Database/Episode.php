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
            $this->setLastError('empty episode path');
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
            $this->setLastError('missing series for episode');
            return 0;
        }
        if (!$this->query($sql)) {
            $insertError = $this->getLastError() ?: ($this->error() ?: 'episode insert failed');
            $id          = $this->recoverEpisodeInsert($path, $plex, $emby, $jellyfin, $plexRemoteId, $embyRemoteId, $jellyfinRemoteId);
            if ($id) {
                return $id;
            }
            $this->setLastError($insertError);
            return 0;
        }

        $id = intval($this->insertId());
        if (!$id) {
            $id = $this->recoverEpisodeInsert($path, $plex, $emby, $jellyfin, $plexRemoteId, $embyRemoteId, $jellyfinRemoteId);
            if ($id) {
                return $id;
            }
            $this->setLastError(intval($seriesId) ? 'episode insert returned no id' : 'series not found for episode insert');
            return 0;
        }

        return $id;
    }

    public function recoverEpisodeInsert($path, $plex, $emby, $jellyfin, $plexRemoteId, $embyRemoteId, $jellyfinRemoteId)
    {
        $row = [];
        if ($embyRemoteId != '' && $embyRemoteId != null) {
            $row = $this->getEpisodeByRemoteId(MediaPlatforms::EMBY, $embyRemoteId);
        }
        if (!$row && $plexRemoteId != '' && $plexRemoteId != null) {
            $row = $this->getEpisodeByRemoteId(MediaPlatforms::PLEX, $plexRemoteId);
        }
        if (!$row && $jellyfinRemoteId != '' && $jellyfinRemoteId != null) {
            $row = $this->getEpisodeByRemoteId(MediaPlatforms::JELLYFIN, $jellyfinRemoteId);
        }
        if (!$row) {
            $row = $this->getMediaLibraryItemByPath(EPISODE_TABLE, $path);
        }
        if (!$row) {
            return 0;
        }

        $id   = intval($row['id']);
        $sets = [];
        if (!empty($plex)) {
            $sets[] = '`plex` = 1';
            if ($plexRemoteId != '' && $plexRemoteId != null && trim(strval($row['plex_remote_id'] ?? '')) == '') {
                $sets[] = '`plex_remote_id` = ' . $this->sqlStringOrNull($plexRemoteId);
            }
        }
        if (!empty($emby)) {
            $sets[] = '`emby` = 1';
            if ($embyRemoteId != '' && $embyRemoteId != null && trim(strval($row['emby_remote_id'] ?? '')) == '') {
                $sets[] = '`emby_remote_id` = ' . $this->sqlStringOrNull($embyRemoteId);
            }
        }
        if (!empty($jellyfin)) {
            $sets[] = '`jellyfin` = 1';
            if ($jellyfinRemoteId != '' && $jellyfinRemoteId != null && trim(strval($row['jellyfin_remote_id'] ?? '')) == '') {
                $sets[] = '`jellyfin_remote_id` = ' . $this->sqlStringOrNull($jellyfinRemoteId);
            }
        }
        $fixed = $this->normalizePath($path, 'episode');
        if ($fixed != '' && ($this->pathLooksMangled($row['path'] ?? '') || trim(strval($row['path'] ?? '')) == '')) {
            $sets[] = "`path` = '" . $this->prepare($fixed) . "'";
        }
        if ($sets) {
            $this->query("UPDATE " . EPISODE_TABLE . " SET " . implode(', ', $sets) . " WHERE id = " . $id);
        }
        $this->setLastError('');

        return $id;
    }

    public function setEpisodePlatform($platform, $remoteId, $path)
    {
        return $this->setMediaLibraryPlatform(EPISODE_TABLE, $platform, $remoteId, $path);
    }
}
