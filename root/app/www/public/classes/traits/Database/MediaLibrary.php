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
        $flag = $this->mediaLibraryFlag($platform);
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

    public function mergeDuplicateLibraryItems()
    {
        $merged = 0;
        foreach ([MOVIE_TABLE => 'movie', SERIES_TABLE => 'series', EPISODE_TABLE => 'episode'] as $table => $kind) {
            $merged += $this->normalizeLibraryPathColumn($table, $kind);
            $merged += $this->mergeLibraryRowsByRemote($table, $kind);
            $merged += $this->mergeLibraryRowsByExactPath($table, $kind);
        }
        $merged += $this->absorbSeasonFolderSeries();
        $merged += $this->mergeBlankPosterLibraryDuplicates();

        return $merged;
    }

    public function mergeBlankPosterLibraryDuplicates()
    {
        $merged = 0;
        foreach ([MOVIE_TABLE => 'movie', SERIES_TABLE => 'series'] as $table => $kind) {
            $merged += $this->mergeBlankPosterRows($table, $kind);
        }

        return $merged;
    }

    public function mergeBlankPosterRows($table, $kind)
    {
        $rows = [];
        $res  = $this->query($this->libraryItemColumns($table));
        while ($row = $this->fetchAssoc($res)) {
            $rows[] = $row;
        }

        $withPoster = [];
        $blank      = [];
        foreach ($rows as $row) {
            if (trim(strval($row['poster'] ?? '')) == '') {
                $blank[] = $row;
            } else {
                $withPoster[] = $row;
            }
        }
        if (!$blank || !$withPoster) {
            return 0;
        }

        $merged = 0;
        foreach ($blank as $lose) {
            $keep = $this->findBlankPosterKeep($lose, $withPoster, $kind);
            if (!$keep) {
                continue;
            }
            $this->absorbLibraryItem($table, $kind, $keep, $lose);
            $merged++;
        }

        return $merged;
    }

    public function findBlankPosterKeep($lose, $withPoster, $kind)
    {
        $loseTitle = strtolower(trim(strval($lose['title'] ?? '')));
        $loseYear  = intval($lose['year'] ?? 0);
        $losePath  = $this->normalizePath($lose['path'] ?? '', $kind);

        $best      = [];
        $bestScore = -1;
        foreach ($withPoster as $keep) {
            if (intval($keep['id']) == intval($lose['id'])) {
                continue;
            }

            $score = 0;
            foreach (['plex_remote_id', 'emby_remote_id', 'jellyfin_remote_id'] as $field) {
                $loseRemote = trim(strval($lose[$field] ?? ''));
                $keepRemote = trim(strval($keep[$field] ?? ''));
                if ($loseRemote != '' && $loseRemote == $keepRemote) {
                    $score += 100;
                }
            }

            $keepPath = $this->normalizePath($keep['path'] ?? '', $kind);
            if ($losePath != '' && $keepPath != '' && strcasecmp($losePath, $keepPath) == 0) {
                $score += 50;
            }

            $keepTitle = strtolower(trim(strval($keep['title'] ?? '')));
            if ($loseTitle != '' && $loseTitle == $keepTitle) {
                $keepYear = intval($keep['year'] ?? 0);
                if ($loseYear <= 0 || $keepYear <= 0 || $loseYear == $keepYear) {
                    $score += 20;
                }
            }

            if ($score > $bestScore) {
                $bestScore = $score;
                $best      = $keep;
            }
        }

        return $bestScore >= 20 ? $best : [];
    }

    public function libraryItemColumns($table)
    {
        if ($table == EPISODE_TABLE) {
            return "SELECT id, series_id, season, episode, title, path, plex, emby, jellyfin, plex_remote_id, emby_remote_id, jellyfin_remote_id, poster
                    FROM " . $table;
        }

        return "SELECT id, title, year, path, plex, emby, jellyfin, plex_remote_id, emby_remote_id, jellyfin_remote_id, poster
                FROM " . $table;
    }

    public function normalizeLibraryPathColumn($table, $kind)
    {
        $merged = 0;
        $rows   = [];
        $res    = $this->query($this->libraryItemColumns($table));
        while ($row = $this->fetchAssoc($res)) {
            $rows[] = $row;
        }
        foreach ($rows as $row) {
            $norm = $this->normalizeLibraryPath($row['path'] ?? '');
            if ($kind == 'series') {
                $show = $this->mediaLibraryShowPath($norm);
                if ($show != '') {
                    $norm = $show;
                }
            }
            if ($norm == strval($row['path'] ?? '')) {
                continue;
            }
            if ($norm == '') {
                $sql = "UPDATE " . $table . "
                        SET `path` = ''
                        WHERE id = " . intval($row['id']);
                $this->query($sql);
                continue;
            }
            $other = $this->getMediaLibraryItemByPath($table, $norm);
            if ($other && intval($other['id']) != intval($row['id'])) {
                $this->absorbLibraryItem($table, $kind, $other, $row);
                $merged++;
                continue;
            }
            $sql = "UPDATE " . $table . "
                    SET `path` = '" . $this->prepare($norm) . "'
                    WHERE id = " . intval($row['id']);
            $this->query($sql);
        }

        return $merged;
    }

    public function mergeLibraryRowsByRemote($table, $kind)
    {
        $merged = 0;
        foreach (['plex_remote_id', 'emby_remote_id', 'jellyfin_remote_id'] as $field) {
            $sql     = "SELECT `" . $field . "` AS remote_id
                    FROM " . $table . "
                    WHERE `" . $field . "` IS NOT NULL
                    AND `" . $field . "` != ''
                    GROUP BY `" . $field . "`
                    HAVING COUNT(*) > 1";
            $res     = $this->query($sql);
            $remotes = [];
            while ($row = $this->fetchAssoc($res)) {
                $remotes[] = $row['remote_id'];
            }
            foreach ($remotes as $remote) {
                $rows = [];
                $sql  = $this->libraryItemColumns($table) . "
                        WHERE `" . $field . "` = '" . $this->prepare($remote) . "'
                        ORDER BY id ASC";
                $res  = $this->query($sql);
                while ($row = $this->fetchAssoc($res)) {
                    $rows[] = $row;
                }
                $merged += $this->mergeLibraryCluster($table, $kind, $rows);
            }
        }

        return $merged;
    }

    public function mergeLibraryRowsByExactPath($table, $kind)
    {
        $merged = 0;
        $sql    = "SELECT `path`
                FROM " . $table . "
                WHERE `path` != ''
                GROUP BY `path`
                HAVING COUNT(*) > 1";
        $res    = $this->query($sql);
        $paths  = [];
        while ($row = $this->fetchAssoc($res)) {
            $paths[] = $row['path'];
        }
        foreach ($paths as $path) {
            $rows = [];
            $sql  = $this->libraryItemColumns($table) . "
                    WHERE `path` = '" . $this->prepare($path) . "'
                    ORDER BY id ASC";
            $res  = $this->query($sql);
            while ($row = $this->fetchAssoc($res)) {
                $rows[] = $row;
            }
            $merged += $this->mergeLibraryCluster($table, $kind, $rows);
        }

        return $merged;
    }

    public function absorbSeasonFolderSeries()
    {
        $merged = 0;
        $rows   = [];
        $res    = $this->query($this->libraryItemColumns(SERIES_TABLE));
        while ($row = $this->fetchAssoc($res)) {
            $rows[] = $row;
        }
        foreach ($rows as $row) {
            $path = $this->normalizeLibraryPath($row['path'] ?? '');
            if ($path == '' || !$this->mediaLibrarySeasonFolder(basename($path))) {
                continue;
            }
            $parent = $this->mediaLibraryShowPath($path);
            if ($parent == '' || $parent == $path) {
                continue;
            }
            $keep = $this->getMediaLibraryItemByPath(SERIES_TABLE, $parent);
            if ($keep && intval($keep['id']) != intval($row['id'])) {
                $this->absorbLibraryItem(SERIES_TABLE, 'series', $keep, $row);
                $merged++;
                continue;
            }
            $sql = "UPDATE " . SERIES_TABLE . "
                    SET `path` = '" . $this->prepare($parent) . "'
                    WHERE id = " . intval($row['id']);
            $this->query($sql);
        }

        return $merged;
    }

    public function mergeLibraryCluster($table, $kind, $rows)
    {
        if (count($rows) < 2) {
            return 0;
        }

        $keep = $rows[0];
        foreach ($rows as $row) {
            if ($this->libraryItemScore($row) > $this->libraryItemScore($keep)) {
                $keep = $row;
            }
        }

        $count = 0;
        foreach ($rows as $row) {
            if (intval($row['id']) == intval($keep['id'])) {
                continue;
            }
            $this->absorbLibraryItem($table, $kind, $keep, $row);
            $count++;
        }

        return $count;
    }

    public function libraryItemScore($row)
    {
        $score = intval($row['plex'] ?? 0) + intval($row['emby'] ?? 0) + intval($row['jellyfin'] ?? 0);
        $path  = $this->normalizeLibraryPath($row['path'] ?? '');
        if ($path != '') {
            $score += 10;
        }
        if ($path != '' && !$this->mediaLibrarySeasonFolder(basename($path))) {
            $score += 5;
        }
        if (intval($row['year'] ?? 0) > 0) {
            $score += 3;
        }
        if (($row['poster'] ?? '') != '') {
            $score += 1;
        }

        return $score;
    }

    public function absorbLibraryItem($table, $kind, &$keep, $row)
    {
        $sets = [];
        foreach (['plex', 'emby', 'jellyfin'] as $flag) {
            if (intval($row[$flag]) && !intval($keep[$flag])) {
                $sets[]      = "`" . $flag . "` = 1";
                $keep[$flag] = 1;
            }
        }
        foreach (['plex_remote_id', 'emby_remote_id', 'jellyfin_remote_id', 'poster', 'path'] as $field) {
            if (($keep[$field] ?? '') == '' && ($row[$field] ?? '') != '') {
                $sets[]       = "`" . $field . "` = " . $this->sqlStringOrNull($row[$field]);
                $keep[$field] = $row[$field];
            }
        }
        if (intval($keep['year'] ?? 0) <= 0 && intval($row['year'] ?? 0) > 0) {
            $sets[]       = "`year` = " . intval($row['year']);
            $keep['year'] = intval($row['year']);
        }
        if ($sets) {
            $sql = "UPDATE " . $table . "
                    SET " . implode(', ', $sets) . "
                    WHERE id = " . intval($keep['id']);
            $this->query($sql);
        }

        if ($kind == 'series') {
            $this->absorbSeriesEpisodes(intval($keep['id']), intval($row['id']));
        } else if ($kind == 'episode') {
            $this->absorbEpisode($keep, $row);
            return;
        } else {
            $this->reassignUserMovieLinks(intval($keep['id']), intval($row['id']));
        }

        $sql = "DELETE FROM " . $table . "
                WHERE id = " . intval($row['id']);
        $this->query($sql);
    }

    public function absorbSeriesEpisodes($keepSeriesId, $loseSeriesId)
    {
        $sql = "SELECT id, series_id, season, episode, title, path, plex, emby, jellyfin, plex_remote_id, emby_remote_id, jellyfin_remote_id, poster
                FROM " . EPISODE_TABLE . "
                WHERE series_id = " . intval($loseSeriesId);
        $res = $this->query($sql);
        while ($episode = $this->fetchAssoc($res)) {
            $keep = $this->findSeriesEpisodeMatch(intval($keepSeriesId), $episode);
            if ($keep) {
                $this->absorbEpisode($keep, $episode);
            } else {
                $sql = "UPDATE " . EPISODE_TABLE . "
                        SET series_id = " . intval($keepSeriesId) . "
                        WHERE id = " . intval($episode['id']);
                $this->query($sql);
            }
        }
    }

    public function findSeriesEpisodeMatch($seriesId, $episode)
    {
        $path = $this->normalizeLibraryPath($episode['path'] ?? '');
        if ($path != '') {
            $sql = "SELECT id, series_id, season, episode, title, path, plex, emby, jellyfin, plex_remote_id, emby_remote_id, jellyfin_remote_id, poster
                    FROM " . EPISODE_TABLE . "
                    WHERE series_id = " . intval($seriesId) . "
                    AND `path` = '" . $this->prepare($path) . "'
                    LIMIT 1";
            $row = $this->fetchAssoc($this->query($sql));
            if ($row) {
                return $row;
            }
        }

        foreach (['plex_remote_id', 'emby_remote_id', 'jellyfin_remote_id'] as $field) {
            $remote = trim(strval($episode[$field] ?? ''));
            if ($remote == '') {
                continue;
            }
            $sql = "SELECT id, series_id, season, episode, title, path, plex, emby, jellyfin, plex_remote_id, emby_remote_id, jellyfin_remote_id, poster
                    FROM " . EPISODE_TABLE . "
                    WHERE series_id = " . intval($seriesId) . "
                    AND `" . $field . "` = '" . $this->prepare($remote) . "'
                    LIMIT 1";
            $row = $this->fetchAssoc($this->query($sql));
            if ($row) {
                return $row;
            }
        }

        $sql = "SELECT id, series_id, season, episode, title, path, plex, emby, jellyfin, plex_remote_id, emby_remote_id, jellyfin_remote_id, poster
                FROM " . EPISODE_TABLE . "
                WHERE series_id = " . intval($seriesId) . "
                AND season = " . intval($episode['season'] ?? 0) . "
                AND episode = " . intval($episode['episode'] ?? 0) . "
                LIMIT 1";
        $row = $this->fetchAssoc($this->query($sql));

        return $row ?: [];
    }

    public function absorbEpisode($keep, $row)
    {
        $sets = [];
        foreach (['plex', 'emby', 'jellyfin'] as $flag) {
            if (intval($row[$flag]) && !intval($keep[$flag])) {
                $sets[] = "`" . $flag . "` = 1";
            }
        }
        foreach (['plex_remote_id', 'emby_remote_id', 'jellyfin_remote_id', 'poster', 'path'] as $field) {
            if (($keep[$field] ?? '') == '' && ($row[$field] ?? '') != '') {
                $sets[] = "`" . $field . "` = " . $this->sqlStringOrNull($row[$field]);
            }
        }
        if ($sets) {
            $sql = "UPDATE " . EPISODE_TABLE . "
                    SET " . implode(', ', $sets) . "
                    WHERE id = " . intval($keep['id']);
            $this->query($sql);
        }
        $this->reassignUserEpisodeLinks(intval($keep['id']), intval($row['id']));
        $sql = "DELETE FROM " . EPISODE_TABLE . "
                WHERE id = " . intval($row['id']);
        $this->query($sql);
    }

    public function reassignUserEpisodeLinks($keepId, $loseId)
    {
        $sql = "SELECT id, media_app_user_id, platform
                FROM " . USER_EPISODE_LINK_TABLE . "
                WHERE episode_id = " . intval($loseId);
        $res = $this->query($sql);
        while ($link = $this->fetchAssoc($res)) {
            $exists = "SELECT id
                    FROM " . USER_EPISODE_LINK_TABLE . "
                    WHERE episode_id = " . intval($keepId) . "
                    AND media_app_user_id = " . intval($link['media_app_user_id']) . "
                    AND platform = " . intval($link['platform']);
            $found  = $this->fetchAssoc($this->query($exists));
            if ($found) {
                $sql = "DELETE FROM " . USER_EPISODE_LINK_TABLE . "
                        WHERE id = " . intval($link['id']);
            } else {
                $sql = "UPDATE " . USER_EPISODE_LINK_TABLE . "
                        SET episode_id = " . intval($keepId) . "
                        WHERE id = " . intval($link['id']);
            }
            $this->query($sql);
        }
    }

    public function reassignUserMovieLinks($keepId, $loseId)
    {
        $sql = "SELECT id, media_app_user_id, platform
                FROM " . USER_MOVIE_LINK_TABLE . "
                WHERE movie_id = " . intval($loseId);
        $res = $this->query($sql);
        while ($link = $this->fetchAssoc($res)) {
            $exists = "SELECT id
                    FROM " . USER_MOVIE_LINK_TABLE . "
                    WHERE movie_id = " . intval($keepId) . "
                    AND media_app_user_id = " . intval($link['media_app_user_id']) . "
                    AND platform = " . intval($link['platform']);
            $found  = $this->fetchAssoc($this->query($exists));
            if ($found) {
                $sql = "DELETE FROM " . USER_MOVIE_LINK_TABLE . "
                        WHERE id = " . intval($link['id']);
            } else {
                $sql = "UPDATE " . USER_MOVIE_LINK_TABLE . "
                        SET movie_id = " . intval($keepId) . "
                        WHERE id = " . intval($link['id']);
            }
            $this->query($sql);
        }
    }
}
