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

    public function normalizeLibraryPath($path)
    {
        $path = str_replace('\\', '/', trim(strval($path)));
        if ($path == '' || $path == '.') {
            return '';
        }
        if (preg_match('/^[a-zA-Z]:\/$/', $path)) {
            return $path;
        }

        return rtrim($path, '/');
    }

    public function normalizePath($path, $kind = '')
    {
        $path = $this->normalizeLibraryPath($path);
        if ($path == '') {
            return '';
        }

        $kind = strtolower(trim(strval($kind)));
        if ($kind == 'episode') {
            return $path;
        }

        if ($kind == 'series') {
            $show = $this->mediaLibraryShowPath($path);
            return $show != '' ? $show : $path;
        }

        if (preg_match('/\.[a-z0-9]{2,5}$/i', basename($path))) {
            $parent = $this->normalizeLibraryPath(dirname($path));
            if ($parent != '') {
                return $parent;
            }
        }

        return $path;
    }

    public function sqlStringOrNull($value)
    {
        $value = trim(strval($value));
        if ($value == '') {
            return 'NULL';
        }

        return "'" . $this->prepare($value) . "'";
    }

    public function mediaLibraryMatchWhere($platform, $remoteId, $path)
    {
        $field = $this->mediaLibraryRemoteField($platform);
        $path  = $this->normalizeLibraryPath($path);
        $where = [];
        if ($field && $remoteId != '' && $remoteId != null) {
            $where[] = $field . " = '" . $this->prepare($remoteId) . "'";
        }
        if ($path != '') {
            $where[] = "`path` = '" . $this->prepare($path) . "'";
        }

        return $where ? implode(' OR ', $where) : '';
    }

    public function getMediaLibraryItemByPath($table, $path)
    {
        $path = $this->normalizeLibraryPath($path);
        if ($path == '') {
            return [];
        }

        $sql = "SELECT id, title, year, path, plex, emby, jellyfin, plex_remote_id, emby_remote_id, jellyfin_remote_id, poster
                FROM " . $table . "
                WHERE `path` = '" . $this->prepare($path) . "'
                LIMIT 1";
        $res = $this->query($sql);
        $row = $this->fetchAssoc($res);

        return $row ?: [];
    }

    public function setMediaLibraryPlatform($table, $platform, $remoteId, $path)
    {
        $flag  = $this->mediaLibraryFlag($platform);
        $field = $this->mediaLibraryRemoteField($platform);
        if (!$flag || !$field) {
            return 0;
        }

        if ($remoteId != '' && $remoteId != null) {
            $sql = "UPDATE " . $table . "
                    SET `" . $flag . "` = 1
                    WHERE `" . $field . "` = '" . $this->prepare($remoteId) . "'
                    LIMIT 1";
            $this->query($sql);
            $matched = $this->matchedRows();
            if ($matched > 0) {
                return $matched;
            }
        }

        $path = $this->normalizeLibraryPath($path);
        if ($path == '') {
            return 0;
        }

        $sets = "`" . $flag . "` = 1";
        if ($remoteId != '' && $remoteId != null) {
            $sets .= ", `" . $field . "` = " . $this->sqlStringOrNull($remoteId);
        }
        $sql = "UPDATE " . $table . "
                SET " . $sets . "
                WHERE `path` = '" . $this->prepare($path) . "'
                LIMIT 1";
        $this->query($sql);

        return $this->matchedRows();
    }

    public function setMediaLibraryPlatformById($table, $platform, $remoteId, $path, $id)
    {
        $flag  = $this->mediaLibraryFlag($platform);
        $field = $this->mediaLibraryRemoteField($platform);
        $path  = $this->normalizeLibraryPath($path);
        $id    = intval($id);
        if (!$flag || !$field || !$id) {
            return 0;
        }

        $sets = $flag . " = 1";
        if ($remoteId != '' && $remoteId != null) {
            $sets .= ", `" . $field . "` = " . $this->sqlStringOrNull($remoteId);
        }
        if ($path != '') {
            $sets .= ", `path` = '" . $this->prepare($path) . "'";
        }
        $sql = "UPDATE " . $table . "
                SET " . $sets . "
                WHERE id = " . $id;
        $this->query($sql);

        return $this->matchedRows();
    }

    public function clearMediaLibraryRemote($platform, $remoteId)
    {
        $flag     = $this->mediaLibraryFlag($platform);
        $field    = $this->mediaLibraryRemoteField($platform);
        $remoteId = trim(strval($remoteId));
        if (!$flag || !$field || $remoteId == '') {
            return 0;
        }

        $cleared = 0;
        foreach ([MOVIE_TABLE, SERIES_TABLE, EPISODE_TABLE] as $table) {
            $sql = "UPDATE `" . $table . "`
                    SET `" . $flag . "` = 0, `" . $field . "` = NULL
                    WHERE `" . $field . "` = '" . $this->prepare($remoteId) . "'";
            $this->query($sql);
            $cleared += $this->matchedRows();
        }

        return $cleared;
    }

    public function clearUnseenMediaLibraryRemotes($platform, $seenRemoteIds)
    {
        $flag  = $this->mediaLibraryFlag($platform);
        $field = $this->mediaLibraryRemoteField($platform);
        if (!$flag || !$field) {
            return 0;
        }

        $seen = [];
        foreach ($seenRemoteIds as $remoteId) {
            $remoteId = trim(strval($remoteId));
            if ($remoteId != '') {
                $seen[$remoteId] = true;
            }
        }
        if (!$seen) {
            return 0;
        }

        $in = [];
        foreach (array_keys($seen) as $remoteId) {
            $in[] = "'" . $this->prepare($remoteId) . "'";
        }

        $cleared = 0;
        foreach ([MOVIE_TABLE, SERIES_TABLE, EPISODE_TABLE] as $table) {
            $sql = "UPDATE `" . $table . "`
                    SET `" . $flag . "` = 0, `" . $field . "` = NULL
                    WHERE `" . $field . "` IS NOT NULL
                      AND `" . $field . "` != ''
                      AND `" . $field . "` NOT IN (" . implode(',', $in) . ")";
            $this->query($sql);
            $cleared += $this->matchedRows();
        }

        return $cleared;
    }

    public function mediaLibrarySeasonFolder($name)
    {
        return preg_match('/^(season[\s._-]*\d+|series[\s._-]*\d+|specials|extras|s\d{1,2}|\d{1,3})$/i', trim(strval($name))) ? 1 : 0;
    }

    public function mediaLibraryShowPath($path)
    {
        $path = $this->normalizeLibraryPath($path);
        if ($path == '') {
            return '';
        }
        if (preg_match('/\.[a-z0-9]{2,5}$/i', basename($path))) {
            $path = $this->normalizeLibraryPath(dirname($path));
        }
        while ($path != '' && $path != '/' && $this->mediaLibrarySeasonFolder(basename($path))) {
            $parent = $this->normalizeLibraryPath(dirname($path));
            if ($parent == $path) {
                break;
            }
            $path = $parent;
        }

        return $path == '.' ? '' : $path;
    }

    public function findMediaLibraryItem($table, $platform, $remoteId, $path, $title = '', $year = 0)
    {
        $path  = $this->normalizeLibraryPath($path);
        $field = $this->mediaLibraryRemoteField($platform);
        if ($field && $remoteId != '' && $remoteId != null) {
            $sql = "SELECT id, title, year, path, plex, emby, jellyfin, plex_remote_id, emby_remote_id, jellyfin_remote_id, poster
                    FROM " . $table . "
                    WHERE " . $field . " = '" . $this->prepare($remoteId) . "'
                    ORDER BY CASE WHEN `path` = '' THEN 1 ELSE 0 END ASC, id ASC
                    LIMIT 1";
            $res = $this->query($sql);
            $row = $this->fetchAssoc($res);
            if ($row) {
                return $row;
            }
        }

        if ($path != '') {
            return $this->getMediaLibraryItemByPath($table, $path);
        }

        return [];
    }

    public function getMediaLibraryItem($table, $platform, $remoteId, $path)
    {
        return $this->findMediaLibraryItem($table, $platform, $remoteId, $path);
    }

    public function updateMediaLibraryMetadata($table, $platform, $remoteId, $path, $title, $year, $poster, $allowPoster)
    {
        $row = $this->findMediaLibraryItem($table, $platform, $remoteId, $path);
        if (!$row) {
            return [];
        }

        $sets        = [];
        $writePoster = $poster != '' && $poster != null && ($allowPoster || ($row['poster'] ?? '') == '');
        if ($title != '' && $title != null) {
            $sets[]       = "`title` = '" . $this->prepare($title) . "'";
            $row['title'] = $title;
        }
        if (intval($year) > 0) {
            $sets[]      = "`year` = " . intval($year);
            $row['year'] = intval($year);
        }
        if ($writePoster) {
            $sets[]        = "`poster` = '" . $this->prepare($poster) . "'";
            $row['poster'] = $poster;
        }
        if ($sets) {
            $sql = "UPDATE " . $table . "
                    SET " . implode(', ', $sets) . "
                    WHERE id = " . intval($row['id']);
            $this->query($sql);
        }

        return $row;
    }

    public function fillBlankLibraryMetadata($table, $platform, $remoteId, $path, $title = '', $year = 0, $poster = '')
    {
        $path   = $this->normalizeLibraryPath($path);
        $title  = trim(strval($title));
        $poster = trim(strval($poster));
        $year   = intval($year);
        if ($title == '' && $year <= 0 && $poster == '') {
            return 0;
        }

        $where = $this->mediaLibraryMatchWhere($platform, $remoteId, $path);
        if ($where == '') {
            return 0;
        }

        $sets = [];
        if ($title != '') {
            $sets[] = "`title` = IF(`title` = '', '" . $this->prepare($title) . "', `title`)";
        }
        if ($year > 0) {
            $sets[] = "`year` = IF(`year` <= 0, " . $year . ", `year`)";
        }
        if ($poster != '') {
            $sets[] = "`poster` = IF(`poster` = '', '" . $this->prepare($poster) . "', `poster`)";
        }
        if (!$sets) {
            return 0;
        }

        $sql = "UPDATE " . $table . "
                SET " . implode(', ', $sets) . "
                WHERE (" . $where . ")
                AND (`title` = '' OR `year` <= 0 OR `poster` = '')";
        $this->query($sql);

        return $this->matchedRows();
    }

    public function getMediaLibraryCounts($platform)
    {
        $counts = [
            'movies'   => 0,
            'series'   => 0,
            'episodes' => 0,
        ];
        $field  = $this->mediaLibraryRemoteField($platform);
        if (!$field) {
            return $counts;
        }

        $tables = [
            'movies'   => MOVIE_TABLE,
            'series'   => SERIES_TABLE,
            'episodes' => EPISODE_TABLE,
        ];
        foreach ($tables as $key => $table) {
            $sql          = "SELECT COUNT(id) AS total
                    FROM " . $table . "
                    WHERE " . $field . " != ''";
            $res          = $this->query($sql);
            $row          = $this->fetchAssoc($res);
            $counts[$key] = intval($row['total'] ?? 0);
        }

        return $counts;
    }

    public function hasMediaLibraryData()
    {
        foreach ([MOVIE_TABLE, EPISODE_TABLE] as $table) {
            $res = $this->query("SELECT id FROM " . $table . " LIMIT 1");
            if ($this->fetchAssoc($res)) {
                return true;
            }
        }

        return false;
    }

    public function rootFolder($path, $kind)
    {
        $original = trim($path);
        if ($original == '' || $original == '/' || $original == '\\') {
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
            if (preg_match('/^(season[\s._-]*\d+|specials|extras|s\d{1,2}|\d{1,3})$/i', $last)) {
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
        if ($norm == '' || $norm == '/' || $norm == '//') {
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
        $seen  = [];
        foreach ($kinds as $kind => $table) {
            $sql = "SELECT path
                    FROM " . $table . "
                    WHERE " . $field . " != ''
                      AND path != ''";
            $res = $this->query($sql);
            while ($row = $this->fetchAssoc($res)) {
                $root = $this->rootFolder($row['path'] ?? '', $kind);
                if ($root == '') {
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
