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

        $sql = "SELECT id, title, year, path, plex, emby, jellyfin, plex_remote_id, emby_remote_id, jellyfin_remote_id
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
        $sql = "SELECT id, title, year, path, plex, emby, jellyfin, plex_remote_id, emby_remote_id, jellyfin_remote_id
                FROM " . MOVIE_TABLE . "
                WHERE id = " . intval($id);
        $res = $this->query($sql);
        $row = $this->fetchAssoc($res);

        return $row ?: [];
    }

    public function getMovieByRemoteId($platform, $remoteId)
    {
        $field = $this->mediaLibraryRemoteField($platform);
        if (!$field || $remoteId === '' || $remoteId === null) {
            return [];
        }

        $sql = "SELECT id, title, year, path, plex, emby, jellyfin, plex_remote_id, emby_remote_id, jellyfin_remote_id
                FROM " . MOVIE_TABLE . "
                WHERE " . $field . " = '" . $this->prepare($remoteId) . "'";
        $res = $this->query($sql);
        $row = $this->fetchAssoc($res);

        return $row ?: [];
    }

    public function addMovie($title, $year, $path, $plex, $emby, $jellyfin, $plexRemoteId, $embyRemoteId, $jellyfinRemoteId)
    {
        $sql = "INSERT INTO " . MOVIE_TABLE . "
                (`title`, `year`, `path`, `plex`, `emby`, `jellyfin`, `plex_remote_id`, `emby_remote_id`, `jellyfin_remote_id`)
                VALUES
                ('" . $this->prepare($title) . "', " . intval($year) . ", '" . $this->prepare($path) . "', " . intval($plex) . ", " . intval($emby) . ", " . intval($jellyfin) . ", '" . $this->prepare($plexRemoteId) . "', '" . $this->prepare($embyRemoteId) . "', '" . $this->prepare($jellyfinRemoteId) . "')";
        $res = $this->query($sql);

        return $this->insertId();
    }

    public function setMoviePlatform($platform, $remoteId, $path)
    {
        return $this->setMediaLibraryPlatform(MOVIE_TABLE, $platform, $remoteId, $path);
    }
}
