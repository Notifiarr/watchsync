<?php

/*
----------------------------------
------  Created: 091126   ------
------  Austin Best       ------
----------------------------------
*/

trait MediaLibrary
{
    public function mediaLibraryFlag($platform)
    {
        switch (intval($platform)) {
            case MediaPlatforms::EMBY:
                return 'emby';
            case MediaPlatforms::JELLYFIN:
                return 'jellyfin';
            case MediaPlatforms::PLEX:
                return 'plex';
            default:
                return '';
        }
    }

    public function mediaLibraryRemoteField($platform)
    {
        $flag = $this->mediaLibraryFlag($platform);
        if (!$flag) {
            return '';
        }

        return $flag . '_remote_id';
    }

    public function mediaLibraryMatchWhere($platform, $remoteId, $path)
    {
        $field = $this->mediaLibraryRemoteField($platform);
        $where = [];

        if ($field && $remoteId !== '' && $remoteId !== null) {
            $where[] = $field . " = '" . $this->prepare($remoteId) . "'";
        }
        if ($path !== '' && $path !== null) {
            $where[] = "`path` = '" . $this->prepare($path) . "'";
        }

        return $where ? implode(' OR ', $where) : '';
    }

    public function setMediaLibraryPlatform($table, $platform, $remoteId, $path)
    {
        $flag  = $this->mediaLibraryFlag($platform);
        $field = $this->mediaLibraryRemoteField($platform);
        $where = $this->mediaLibraryMatchWhere($platform, $remoteId, $path);
        if (!$flag || !$field || $where === '') {
            return 0;
        }

        $sql = "UPDATE " . $table . "
                SET " . $flag . " = 1,
                    " . $field . " = '" . $this->prepare($remoteId) . "'
                WHERE " . $where;
        $res = $this->query($sql);

        return $this->matchedRows();
    }

    public function getMediaLibraryCounts($platform)
    {
        $counts = [
            'movies'   => 0,
            'series'   => 0,
            'episodes' => 0,
        ];
        $field = $this->mediaLibraryRemoteField($platform);
        if (!$field) {
            return $counts;
        }

        $tables = [
            'movies'   => MOVIE_TABLE,
            'series'   => SERIES_TABLE,
            'episodes' => EPISODE_TABLE,
        ];
        foreach ($tables as $key => $table) {
            $sql = "SELECT COUNT(id) AS total
                    FROM " . $table . "
                    WHERE " . $field . " != ''";
            $res = $this->query($sql);
            $row = $this->fetchAssoc($res);
            $counts[$key] = intval($row['total'] ?? 0);
        }

        return $counts;
    }

    public function rootFolder($path, $kind)
    {
        $original = trim($path);
        if ($original === '' || $original === '/' || $original === '\\') {
            return '';
        }

        $normalized = str_replace('\\', '/', $original);
        $unc        = str_starts_with($normalized, '//');
        $parts      = array_values(array_filter(explode('/', $normalized), 'strlen'));
        if (!$parts) {
            return '';
        }

        $last = $parts[count($parts) - 1];
        if (preg_match('/\.[a-z0-9]{2,5}$/i', $last)) {
            array_pop($parts);
        }

        if ($kind == 'episode' && $parts) {
            $last = $parts[count($parts) - 1];
            if (preg_match('/^(season[\s._-]*\d+|specials|extras|s\d{1,2})$/i', $last)) {
                array_pop($parts);
            }
        }

        if (count($parts) > 1) {
            $withoutTitle = $parts;
            array_pop($withoutTitle);
            $root = $this->joinRootParts($original, $withoutTitle, $unc);
            if ($this->validRootFolder($root)) {
                return $root;
            }
        }

        $root = $this->joinRootParts($original, $parts, $unc);
        return $this->validRootFolder($root) ? $root : '';
    }

    public function joinRootParts($original, $parts, $unc)
    {
        if (!$parts) {
            return '';
        }

        $joined = implode('/', $parts);
        if ($unc) {
            return '//' . $joined;
        }

        $normalized = str_replace('\\', '/', $original);
        if (preg_match('/^[a-zA-Z]:/', $parts[0])) {
            return $joined;
        }
        if (($normalized[0] ?? '') == '/') {
            return '/' . $joined;
        }

        return $joined;
    }

    public function validRootFolder($root)
    {
        $norm = rtrim(str_replace('\\', '/', trim($root)), '/');
        if ($norm === '' || $norm === '/' || $norm === '//') {
            return false;
        }
        if (preg_match('/^[a-zA-Z]:$/', $norm)) {
            return false;
        }

        return true;
    }

    public function getMediaLibraryRoots($platform)
    {
        $field = $this->mediaLibraryRemoteField($platform);
        if (!$field) {
            return [];
        }

        $kinds = [
            'movie'   => MOVIE_TABLE,
            'series'  => SERIES_TABLE,
            'episode' => EPISODE_TABLE,
        ];
        $seen = [];
        foreach ($kinds as $kind => $table) {
            $sql = "SELECT path
                    FROM " . $table . "
                    WHERE " . $field . " != ''
                      AND path != ''";
            $res = $this->query($sql);
            while ($row = $this->fetchAssoc($res)) {
                $root = $this->rootFolder($row['path'] ?? '', $kind);
                if ($root === '') {
                    continue;
                }
                $key = strtolower($root);
                if (!isset($seen[$key])) {
                    $seen[$key] = $root;
                }
            }
        }

        $roots = array_values($seen);
        natcasesort($roots);

        return array_values($roots);
    }
}
