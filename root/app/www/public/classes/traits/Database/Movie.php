<?php

/*
----------------------------------
------  Created: 091126   ------
------  Austin Best       ------
----------------------------------
*/

trait Movie
{
    public function getMovies()
    {
        $movies = [];

        $sql = "SELECT id, title, year, path, plex, emby, jellyfin, plex_remote_id, emby_remote_id, jellyfin_remote_id, poster
                FROM " . MOVIE_TABLE . "
                ORDER BY title ASC";
        $res = $this->query($sql);
        while ($row = $this->fetchAssoc($res)) {
            $movies[] = $row;
        }

        return $movies;
    }

    public function getMovie($id)
    {
        $sql = "SELECT id, title, year, path, plex, emby, jellyfin, plex_remote_id, emby_remote_id, jellyfin_remote_id, poster
                FROM " . MOVIE_TABLE . "
                WHERE id = " . intval($id);
        $res = $this->query($sql);
        $row = $this->fetchAssoc($res);

        return $row ?: [];
    }

    public function getMovieByRemoteId($platform, $remoteId)
    {
        $field = $this->mediaLibraryRemoteField($platform);
        if (!$field || $remoteId == '' || $remoteId == null) {
            return [];
        }

        $sql = "SELECT id, title, year, path, plex, emby, jellyfin, plex_remote_id, emby_remote_id, jellyfin_remote_id, poster
                FROM " . MOVIE_TABLE . "
                WHERE " . $field . " = '" . $this->prepare($remoteId) . "'";
        $res = $this->query($sql);
        $row = $this->fetchAssoc($res);

        return $row ?: [];
    }

    public function addMovie($title, $year, $path, $plex, $emby, $jellyfin, $plexRemoteId, $embyRemoteId, $jellyfinRemoteId, $poster = '')
    {
        $path = $this->normalizePath($path, 'movie');
        if ($path == '') {
            $this->setLastError('empty movie path');
            return 0;
        }

        $sql = "INSERT INTO " . MOVIE_TABLE . "
                (`title`, `year`, `path`, `plex`, `emby`, `jellyfin`, `plex_remote_id`, `emby_remote_id`, `jellyfin_remote_id`, `poster`)
                VALUES
                ('" . $this->prepare($title) . "', " . intval($year) . ", '" . $this->prepare($path) . "', " . intval($plex) . ", " . intval($emby) . ", " . intval($jellyfin) . ", " . $this->sqlStringOrNull($plexRemoteId) . ", " . $this->sqlStringOrNull($embyRemoteId) . ", " . $this->sqlStringOrNull($jellyfinRemoteId) . ", '" . $this->prepare($poster) . "')";
        if (!$this->query($sql)) {
            $insertError = $this->getLastError() ?: ($this->error() ?: 'movie insert failed');
            $row         = $this->findMediaLibraryItem(MOVIE_TABLE, $emby ? MediaPlatforms::EMBY : ($plex ? MediaPlatforms::PLEX : MediaPlatforms::JELLYFIN), $embyRemoteId ?: ($plexRemoteId ?: $jellyfinRemoteId), $path);
            if ($row) {
                $this->setLastError('');
                return intval($row['id']);
            }
            $this->setLastError($insertError);
            return 0;
        }

        return intval($this->insertId());
    }

    public function setMoviePlatform($platform, $remoteId, $path)
    {
        return $this->setMediaLibraryPlatform(MOVIE_TABLE, $platform, $remoteId, $path);
    }
}
