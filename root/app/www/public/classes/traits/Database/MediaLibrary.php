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

    public function mediaLibrarySelectColumns($table)
    {
        if ($table == EPISODE_TABLE) {
            return 'id, series_id, season, episode, title, path, plex, emby, jellyfin, plex_remote_id, emby_remote_id, jellyfin_remote_id, poster';
        }

        return 'id, title, year, path, plex, emby, jellyfin, plex_remote_id, emby_remote_id, jellyfin_remote_id, poster';
    }

    public function normalizeLibraryPath($path)
    {
        $path = str_replace('\\', '/', trim(strval($path)));
        if ($path == '' || $path == '.') {
            return '';
        }
        if (preg_match('/^([a-zA-Z]:)([^\/].*)$/', $path, $m)) {
            $path = $m[1] . '/' . $m[2];
        }
        if (preg_match('/^[a-zA-Z]:\/$/', $path)) {
            return $path;
        }

        return rtrim($path, '/');
    }

    public function pathSlashlessKey($path)
    {
        $path = $this->normalizeLibraryPath($path);
        if ($path == '') {
            return '';
        }

        return strtolower(preg_replace('#[/\\\\]+#', '', $path));
    }

    public function pathLooksMangled($path)
    {
        $path = strval($path);
        if ($path == '') {
            return false;
        }
        if (preg_match('/^[a-zA-Z]:[^\\\\\\/]/', $path)) {
            return true;
        }

        $normalized = $this->normalizeLibraryPath($path);
        if (preg_match('/^[a-zA-Z]:\//', $normalized)) {
            $rest = substr($normalized, 3);
            if ($rest != '' && !str_contains($rest, '/')) {
                return true;
            }
        }

        return false;
    }

    public function normalizePath($path, $type = '')
    {
        $path = $this->normalizeLibraryPath($path);
        if ($path == '') {
            return '';
        }

        $type = strtolower(trim(strval($type)));
        if ($type == 'episode') {
            return $path;
        }

        if ($type == 'series') {
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

        $cols = $this->mediaLibrarySelectColumns($table);
        $sql  = "SELECT " . $cols . "
                FROM " . $table . "
                WHERE `path` = '" . $this->prepare($path) . "'
                LIMIT 1";
        $res  = $this->query($sql);
        $row  = $this->fetchAssoc($res);
        if ($row) {
            return $row;
        }

        $key = $this->pathSlashlessKey($path);
        if ($key == '') {
            return [];
        }
        $sql = "SELECT " . $cols . "
                FROM " . $table . "
                WHERE LOWER(REPLACE(REPLACE(`path`, '/', ''), '\\\\', '')) = '" . $this->prepare($key) . "'
                ORDER BY CASE WHEN `path` LIKE '%/%' OR `path` LIKE '%\\\\%' THEN 0 ELSE 1 END,
                         CASE WHEN `poster` != '' THEN 0 ELSE 1 END,
                         id ASC
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
        $matched = $this->matchedRows();
        if ($matched > 0) {
            return $matched;
        }

        // Same path with different slash style / mangled drive path.
        $row = $this->getMediaLibraryItemByPath($table, $path);
        if ($row) {
            return $this->setMediaLibraryPlatformById($table, $platform, $remoteId, $path, $row['id']);
        }

        return 0;
    }

    public function setMediaLibraryPlatformById($table, $platform, $remoteId, $path, $id)
    {
        $flag  = $this->mediaLibraryFlag($platform);
        $field = $this->mediaLibraryRemoteField($platform);
        $path  = $this->normalizePath($path);
        $id    = intval($id);
        if (!$flag || !$field || !$id) {
            return 0;
        }

        $sql = "SELECT path, `" . $field . "` AS remote_id
                FROM " . $table . "
                WHERE id = " . $id . "
                LIMIT 1";
        $res = $this->query($sql);
        $row = $this->fetchAssoc($res);
        if (!$row) {
            return 0;
        }

        $sets = $flag . " = 1";
        if ($remoteId != '' && $remoteId != null && trim(strval($row['remote_id'] ?? '')) == '') {
            $sets .= ", `" . $field . "` = " . $this->sqlStringOrNull($remoteId);
        } else if ($remoteId != '' && $remoteId != null) {
            $sets .= ", `" . $field . "` = " . $this->sqlStringOrNull($remoteId);
        }
        $currentPath = trim(strval($row['path'] ?? ''));
        if ($path != '' && ($currentPath == '' || ($this->pathLooksMangled($currentPath) && !$this->pathLooksMangled($path)))) {
            $sets .= ", `path` = '" . $this->prepare($path) . "'";
        }
        $sql = "UPDATE " . $table . "
                SET " . $sets . "
                WHERE id = " . $id;
        $this->query($sql);

        return $this->matchedRows();
    }

    public function forceLibraryItemPath($table, $id, $path, $pathType = '')
    {
        $id = intval($id);
        if (!$id) {
            return 0;
        }
        if ($pathType != '') {
            $path = $this->normalizePath($path, $pathType);
        } else {
            $path = $this->normalizeLibraryPath($path);
        }
        if ($path == '') {
            return 0;
        }

        $sql = "UPDATE " . $table . "
                SET `path` = '" . $this->prepare($path) . "'
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

    public function findSeriesIdForEpisodePath($episodePath)
    {
        $episodePath = $this->normalizeLibraryPath($episodePath);
        if ($episodePath == '') {
            return 0;
        }

        $showPath = $this->mediaLibraryShowPath($episodePath);
        if ($showPath != '') {
            $row = $this->getMediaLibraryItemByPath(SERIES_TABLE, $showPath);
            if ($row) {
                return intval($row['id']);
            }
        }

        $sql     = "SELECT id, path
                FROM " . SERIES_TABLE . "
                WHERE path != ''
                ORDER BY LENGTH(path) DESC, id ASC";
        $res     = $this->query($sql);
        $bestId  = 0;
        $bestLen = 0;
        while ($row = $this->fetchAssoc($res)) {
            $root = $this->normalizeLibraryPath($row['path'] ?? '');
            if ($root == '') {
                continue;
            }
            if (strcasecmp($episodePath, $root) != 0 && strncasecmp($episodePath, $root . '/', strlen($root) + 1) != 0) {
                continue;
            }
            $len = strlen($root);
            if ($len > $bestLen) {
                $bestLen = $len;
                $bestId  = intval($row['id']);
            }
        }

        return $bestId;
    }

    public function rehomeMisplacedEpisodes($limit = 0)
    {
        $sql = "SELECT e.id, e.series_id, e.path, s.path AS series_path
                FROM " . EPISODE_TABLE . " e
                LEFT JOIN " . SERIES_TABLE . " s ON s.id = e.series_id
                WHERE e.path != ''
                ORDER BY e.id ASC";
        if (intval($limit) > 0) {
            $sql .= " LIMIT " . intval($limit);
        }
        $res     = $this->query($sql);
        $moved   = 0;
        $checked = 0;
        while ($row = $this->fetchAssoc($res)) {
            $checked++;
            $episodePath = $this->normalizeLibraryPath($row['path'] ?? '');
            $seriesPath  = $this->normalizeLibraryPath($row['series_path'] ?? '');
            $ok          = $seriesPath != '' && (
                strcasecmp($episodePath, $seriesPath) == 0
                || strncasecmp($episodePath, $seriesPath . '/', strlen($seriesPath) + 1) == 0
            );
            if ($ok) {
                continue;
            }
            $targetId = $this->findSeriesIdForEpisodePath($episodePath);
            if (!$targetId || $targetId == intval($row['series_id'])) {
                continue;
            }
            $sqlMove = "UPDATE " . EPISODE_TABLE . "
                        SET series_id = " . intval($targetId) . "
                        WHERE id = " . intval($row['id']);
            $this->query($sqlMove);
            if ($this->matchedRows() > 0) {
                $moved++;
            }
        }

        return ['checked' => $checked, 'moved' => $moved];
    }

    public function findMediaLibraryItem($table, $platform, $remoteId, $path)
    {
        $path  = $this->normalizeLibraryPath($path);
        $field = $this->mediaLibraryRemoteField($platform);
        if ($field && $remoteId != '' && $remoteId != null) {
            $sql = "SELECT " . $this->mediaLibrarySelectColumns($table) . "
                    FROM " . $table . "
                    WHERE " . $field . " = '" . $this->prepare($remoteId) . "'
                    LIMIT 1";
            $res = $this->query($sql);
            $row = $this->fetchAssoc($res);
            if ($row) {
                return $row;
            }
        }

        if ($path != '') {
            $row = $this->getMediaLibraryItemByPath($table, $path);
            if ($row) {
                return $row;
            }
        }

        return [];
    }

    public function pathUnderRoot($path, $root)
    {
        $rawPath = strval($path);
        $path    = $this->normalizeLibraryPath($path);
        $root    = $this->normalizeLibraryPath($root);
        if (($path == '' && $rawPath == '') || $root == '') {
            return false;
        }
        if ($path != '') {
            if (strcasecmp($path, $root) == 0) {
                return true;
            }
            $prefix = $root . '/';
            if (strncasecmp($path, $prefix, strlen($prefix)) == 0) {
                return true;
            }
            $show = $this->mediaLibraryShowPath($path);
            if ($show != '' && strcasecmp($show, $root) == 0) {
                return true;
            }
        }

        $pathKey = $this->pathSlashlessKey($rawPath != '' ? $rawPath : $path);
        $rootKey = $this->pathSlashlessKey($root);
        if ($pathKey == '' || $rootKey == '') {
            return false;
        }
        if ($pathKey == $rootKey) {
            return true;
        }
        $showKey = $this->pathSlashlessKey($this->mediaLibraryShowPath($rawPath != '' ? $rawPath : $path));
        if ($showKey != '' && $showKey == $rootKey) {
            return true;
        }

        return false;
    }

    public function pathUnderRoots($path, $roots)
    {
        foreach ($roots as $root) {
            if ($this->pathUnderRoot($path, $root)) {
                return true;
            }
        }

        return false;
    }

    public function deleteMovieById($id)
    {
        $id = intval($id);
        if (!$id) {
            return 0;
        }

        $sql = "DELETE FROM " . USER_MOVIE_LINK_TABLE . "
                WHERE movie_id = " . $id;
        $this->query($sql);
        $sql = "DELETE FROM " . MOVIE_TABLE . "
                WHERE id = " . $id;
        $this->query($sql);

        return $this->matchedRows();
    }

    public function deleteSeriesById($id)
    {
        $id = intval($id);
        if (!$id) {
            return 0;
        }

        $episodeIds = [];
        $sql        = "SELECT id
                FROM " . EPISODE_TABLE . "
                WHERE series_id = " . $id;
        $res        = $this->query($sql);
        while ($row = $this->fetchAssoc($res)) {
            $episodeIds[] = intval($row['id']);
        }
        if ($episodeIds) {
            $list = implode(',', $episodeIds);
            $sql  = "DELETE FROM " . USER_EPISODE_LINK_TABLE . "
                    WHERE episode_id IN (" . $list . ")";
            $this->query($sql);
            $sql = "DELETE FROM " . EPISODE_TABLE . "
                    WHERE series_id = " . $id;
            $this->query($sql);
        }
        $sql = "DELETE FROM " . SERIES_TABLE . "
                WHERE id = " . $id;
        $this->query($sql);

        return $this->matchedRows();
    }

    public function deleteEpisodeById($id)
    {
        $id = intval($id);
        if (!$id) {
            return 0;
        }

        $sql = "DELETE FROM " . USER_EPISODE_LINK_TABLE . "
                WHERE episode_id = " . $id;
        $this->query($sql);
        $sql = "DELETE FROM " . EPISODE_TABLE . "
                WHERE id = " . $id;
        $this->query($sql);

        return $this->matchedRows();
    }

    public function deleteMediaLibraryItemsUnderRoots($roots)
    {
        $clean = [];
        foreach ($roots as $root) {
            $root = $this->normalizeLibraryPath($root);
            if ($root != '') {
                $clean[$root] = $root;
            }
        }
        if (!$clean) {
            return ['movies' => 0, 'series' => 0, 'episodes' => 0];
        }
        $roots = array_values($clean);

        $deleted = ['movies' => 0, 'series' => 0, 'episodes' => 0];

        $sql = "SELECT id, path
                FROM " . MOVIE_TABLE;
        $res = $this->query($sql);
        while ($row = $this->fetchAssoc($res)) {
            if ($this->pathUnderRoots($row['path'] ?? '', $roots)) {
                $deleted['movies'] += $this->deleteMovieById($row['id']);
            }
        }

        $sql = "SELECT id, path
                FROM " . SERIES_TABLE;
        $res = $this->query($sql);
        while ($row = $this->fetchAssoc($res)) {
            if ($this->pathUnderRoots($row['path'] ?? '', $roots)) {
                $deleted['series'] += $this->deleteSeriesById($row['id']);
            }
        }

        $sql = "SELECT id, path
                FROM " . EPISODE_TABLE;
        $res = $this->query($sql);
        while ($row = $this->fetchAssoc($res)) {
            if ($this->pathUnderRoots($row['path'] ?? '', $roots)) {
                $deleted['episodes'] += $this->deleteEpisodeById($row['id']);
            }
        }

        $sql = "SELECT s.id
                FROM " . SERIES_TABLE . " s
                LEFT JOIN " . EPISODE_TABLE . " e ON e.series_id = s.id
                GROUP BY s.id
                HAVING COUNT(e.id) = 0 AND s.path = ''";
        $res = $this->query($sql);
        while ($row = $this->fetchAssoc($res)) {
            $deleted['series'] += $this->deleteSeriesById($row['id']);
        }

        return $deleted;
    }

    public function mergeLibraryItemOnto($table, $keeperId, $source)
    {
        $keeperId = intval($keeperId);
        if (!$keeperId || !is_array($source)) {
            return 0;
        }

        $sql    = "SELECT " . $this->mediaLibrarySelectColumns($table) . "
                FROM " . $table . "
                WHERE id = " . $keeperId . "
                LIMIT 1";
        $res    = $this->query($sql);
        $keeper = $this->fetchAssoc($res);
        if (!$keeper) {
            return 0;
        }

        $sets = [];
        foreach (['plex', 'emby', 'jellyfin'] as $flag) {
            if (empty($keeper[$flag]) && !empty($source[$flag])) {
                $sets[]        = "`" . $flag . "` = 1";
                $keeper[$flag] = 1;
            }
        }
        foreach (['plex_remote_id', 'emby_remote_id', 'jellyfin_remote_id'] as $field) {
            $incoming = trim(strval($source[$field] ?? ''));
            $current  = trim(strval($keeper[$field] ?? ''));
            if ($incoming != '' && $current == '') {
                $sets[]         = "`" . $field . "` = " . $this->sqlStringOrNull($incoming);
                $keeper[$field] = $incoming;
            }
        }
        $poster = trim(strval($source['poster'] ?? ''));
        if ($poster != '' && trim(strval($keeper['poster'] ?? '')) == '') {
            $sets[]           = "`poster` = '" . $this->prepare($poster) . "'";
            $keeper['poster'] = $poster;
        }
        $title = trim(strval($source['title'] ?? ''));
        if ($title != '' && trim(strval($keeper['title'] ?? '')) == '') {
            $sets[]          = "`title` = '" . $this->prepare($title) . "'";
            $keeper['title'] = $title;
        }
        if ($table != EPISODE_TABLE) {
            $year = intval($source['year'] ?? 0);
            if ($year > 0 && intval($keeper['year'] ?? 0) <= 0) {
                $sets[]         = "`year` = " . $year;
                $keeper['year'] = $year;
            }
        }
        $path = $this->normalizePath($source['path'] ?? '', $table == EPISODE_TABLE ? 'episode' : '');
        if ($path != '' && !$this->pathLooksMangled($path)) {
            $keeperPath = trim(strval($keeper['path'] ?? ''));
            if ($keeperPath == '' || $this->pathLooksMangled($keeperPath)) {
                $sets[]         = "`path` = '" . $this->prepare($path) . "'";
                $keeper['path'] = $path;
            }
        }
        if ($sets) {
            $sql = "UPDATE " . $table . "
                    SET " . implode(', ', $sets) . "
                    WHERE id = " . $keeperId;
            $this->query($sql);
        }

        return $keeperId;
    }

    public function dedupeLibrary()
    {
        $merged = ['movies' => 0, 'series' => 0, 'episodes' => 0];
        foreach ([MOVIE_TABLE => 'movies', SERIES_TABLE => 'series'] as $table => $bucket) {
            $merged[$bucket] += $this->mergeSlashlessPathDuplicates($table);
            $merged[$bucket] += $this->mergeExactTitleDuplicates($table);
        }
        $merged['episodes'] += $this->mergeSlashlessEpisodeDuplicates();
        $merged['episodes'] += $this->mergeMangledEpisodeDuplicates();
        $merged['episodes'] += $this->mergeEpisodeCodeDuplicates();

        return $merged;
    }

    public function episodePathQuality($path, $seriesPath = '')
    {
        $path = $this->normalizeLibraryPath($path);
        if ($path == '') {
            return 0;
        }

        $score = 1;
        if ($this->pathLooksMangled($path)) {
            $score -= 50;
        }
        $seriesPath = $this->normalizeLibraryPath($seriesPath);
        if ($seriesPath != '') {
            $seriesLower = strtolower($seriesPath);
            $pathLower   = strtolower($path);
            if ($pathLower == $seriesLower || str_starts_with($pathLower, $seriesLower . '/')) {
                $score += 20;
            }
        }
        if (preg_match('#/(plex)/#i', $path)) {
            $score += 10;
        }
        if (preg_match('#^[a-z]:/tv shows/#i', $path)) {
            $score -= 15;
        }

        return $score;
    }

    public function mergeEpisodeCodeDuplicates()
    {
        $sql    = "SELECT e.id, e.series_id, e.season, e.episode, e.title, e.path, e.plex, e.emby, e.jellyfin,
                       e.plex_remote_id, e.emby_remote_id, e.jellyfin_remote_id, e.poster, s.path AS series_path
                FROM " . EPISODE_TABLE . " e
                LEFT JOIN " . SERIES_TABLE . " s ON s.id = e.series_id
                ORDER BY e.series_id ASC, e.season ASC, e.episode ASC, e.id ASC";
        $res    = $this->query($sql);
        $groups = [];
        while ($row = $this->fetchAssoc($res)) {
            $title = trim(strval($row['title'] ?? ''));
            if (intval($row['series_id']) <= 0 || $title == '') {
                continue;
            }
            $key = intval($row['series_id']) . ':' . intval($row['season']) . ':' . intval($row['episode']) . ':' . strtolower($title);
            if (!isset($groups[$key])) {
                $groups[$key] = [];
            }
            $groups[$key][] = $row;
        }

        $merged = 0;
        foreach ($groups as $group) {
            if (count($group) < 2) {
                continue;
            }
            usort($group, function ($a, $b) {
                $aq = $this->episodePathQuality($a['path'] ?? '', $a['series_path'] ?? '');
                $bq = $this->episodePathQuality($b['path'] ?? '', $b['series_path'] ?? '');
                if ($aq != $bq) {
                    return $bq - $aq;
                }
                $ac = intval($a['plex'] ?? 0) + intval($a['emby'] ?? 0) + intval($a['jellyfin'] ?? 0);
                $bc = intval($b['plex'] ?? 0) + intval($b['emby'] ?? 0) + intval($b['jellyfin'] ?? 0);
                if ($ac != $bc) {
                    return $bc - $ac;
                }

                return intval($a['id']) - intval($b['id']);
            });
            $keeper = array_shift($group);
            foreach ($group as $dup) {
                $merged += $this->mergeLibraryDuplicateRow(EPISODE_TABLE, $keeper, $dup);
            }
        }

        return $merged;
    }

    public function mergeExactTitleDuplicates($table)
    {
        $sql    = "SELECT id, title, year, path, plex, emby, jellyfin, plex_remote_id, emby_remote_id, jellyfin_remote_id, poster
                FROM " . $table . "
                ORDER BY id ASC";
        $res    = $this->query($sql);
        $groups = [];
        while ($row = $this->fetchAssoc($res)) {
            $title = trim(strval($row['title'] ?? ''));
            if ($title == '') {
                continue;
            }
            $key = strtolower($title) . ':' . intval($row['year'] ?? 0);
            if (!isset($groups[$key])) {
                $groups[$key] = [];
            }
            $groups[$key][] = $row;
        }

        $merged = 0;
        foreach ($groups as $group) {
            if (count($group) < 2) {
                continue;
            }
            usort($group, function ($a, $b) {
                $am = $this->pathLooksMangled($a['path'] ?? '') ? 1 : 0;
                $bm = $this->pathLooksMangled($b['path'] ?? '') ? 1 : 0;
                if ($am != $bm) {
                    return $am - $bm;
                }
                $ac = intval($a['plex'] ?? 0) + intval($a['emby'] ?? 0) + intval($a['jellyfin'] ?? 0);
                $bc = intval($b['plex'] ?? 0) + intval($b['emby'] ?? 0) + intval($b['jellyfin'] ?? 0);
                if ($ac != $bc) {
                    return $bc - $ac;
                }
                $ap = trim(strval($a['poster'] ?? '')) != '' ? 0 : 1;
                $bp = trim(strval($b['poster'] ?? '')) != '' ? 0 : 1;
                if ($ap != $bp) {
                    return $ap - $bp;
                }

                return intval($a['id']) - intval($b['id']);
            });
            $keeper = array_shift($group);
            foreach ($group as $dup) {
                $merged += $this->mergeLibraryDuplicateRow($table, $keeper, $dup);
            }
        }

        return $merged;
    }

    public function mergeSlashlessPathDuplicates($table)
    {
        if ($table == EPISODE_TABLE) {
            return $this->mergeSlashlessEpisodeDuplicates();
        }

        $sql   = "SELECT " . $this->mediaLibrarySelectColumns($table) . "
                FROM " . $table . "
                WHERE path != ''
                ORDER BY id ASC";
        $res   = $this->query($sql);
        $byKey = [];
        while ($row = $this->fetchAssoc($res)) {
            $key = $this->pathSlashlessKey($row['path'] ?? '');
            if ($key == '') {
                continue;
            }
            if (!isset($byKey[$key])) {
                $byKey[$key] = [];
            }
            $byKey[$key][] = $row;
        }

        return $this->mergeLibraryDuplicateGroups($table, $byKey);
    }

    public function mergeSlashlessEpisodeDuplicates()
    {
        $sql   = "SELECT " . $this->mediaLibrarySelectColumns(EPISODE_TABLE) . "
                FROM " . EPISODE_TABLE . "
                WHERE path != ''
                ORDER BY id ASC";
        $res   = $this->query($sql);
        $byKey = [];
        while ($row = $this->fetchAssoc($res)) {
            $slash = $this->pathSlashlessKey($row['path'] ?? '');
            if ($slash == '') {
                continue;
            }
            $key = $slash . ':' . intval($row['season']) . ':' . intval($row['episode']);
            if (!isset($byKey[$key])) {
                $byKey[$key] = [];
            }
            $byKey[$key][] = $row;
        }

        return $this->mergeLibraryDuplicateGroups(EPISODE_TABLE, $byKey);
    }

    public function mergeMangledEpisodeDuplicates()
    {
        $sql   = "SELECT " . $this->mediaLibrarySelectColumns(EPISODE_TABLE) . "
                FROM " . EPISODE_TABLE . "
                WHERE path != ''
                ORDER BY series_id ASC, season ASC, episode ASC, id ASC";
        $res   = $this->query($sql);
        $byKey = [];
        while ($row = $this->fetchAssoc($res)) {
            if (intval($row['series_id'] ?? 0) <= 0) {
                continue;
            }
            $key = intval($row['series_id']) . ':' . intval($row['season']) . ':' . intval($row['episode']);
            if (!isset($byKey[$key])) {
                $byKey[$key] = [];
            }
            $byKey[$key][] = $row;
        }

        $merged = 0;
        foreach ($byKey as $group) {
            if (count($group) < 2) {
                continue;
            }
            $hasMangled = false;
            foreach ($group as $row) {
                if ($this->pathLooksMangled($row['path'] ?? '')) {
                    $hasMangled = true;
                    break;
                }
            }
            if (!$hasMangled) {
                continue;
            }
            $merged += $this->mergeLibraryDuplicateGroups(EPISODE_TABLE, [$group]);
        }

        return $merged;
    }

    public function mergeLibraryDuplicateGroups($table, $byKey)
    {
        $merged = 0;
        foreach ($byKey as $group) {
            if (!is_array($group) || count($group) < 2) {
                continue;
            }
            usort($group, function ($a, $b) {
                $am = $this->pathLooksMangled($a['path'] ?? '') ? 1 : 0;
                $bm = $this->pathLooksMangled($b['path'] ?? '') ? 1 : 0;
                if ($am != $bm) {
                    return $am - $bm;
                }
                $ac = intval($a['plex'] ?? 0) + intval($a['emby'] ?? 0) + intval($a['jellyfin'] ?? 0);
                $bc = intval($b['plex'] ?? 0) + intval($b['emby'] ?? 0) + intval($b['jellyfin'] ?? 0);
                if ($ac != $bc) {
                    return $bc - $ac;
                }
                $ap = trim(strval($a['poster'] ?? '')) != '' ? 0 : 1;
                $bp = trim(strval($b['poster'] ?? '')) != '' ? 0 : 1;
                if ($ap != $bp) {
                    return $ap - $bp;
                }

                return intval($a['id']) - intval($b['id']);
            });
            $keeper = array_shift($group);
            foreach ($group as $dup) {
                $merged += $this->mergeLibraryDuplicateRow($table, $keeper, $dup);
            }
        }

        return $merged;
    }

    public function mergeLibraryDuplicateRow($table, $keeper, $dup)
    {
        if (!$keeper || !$dup || intval($keeper['id']) == intval($dup['id'])) {
            return 0;
        }

        $this->mergeLibraryItemOnto($table, $keeper['id'], $dup);
        if ($table == SERIES_TABLE) {
            $sql = "UPDATE " . EPISODE_TABLE . "
                    SET series_id = " . intval($keeper['id']) . "
                    WHERE series_id = " . intval($dup['id']);
            $this->query($sql);
            $this->deleteSeriesById($dup['id']);
        } else if ($table == EPISODE_TABLE) {
            $keepUsers = [];
            $sql       = "SELECT media_app_user_id
                    FROM " . USER_EPISODE_LINK_TABLE . "
                    WHERE episode_id = " . intval($keeper['id']);
            $res       = $this->query($sql);
            while ($userRow = $this->fetchAssoc($res)) {
                $keepUsers[] = intval($userRow['media_app_user_id']);
            }
            if ($keepUsers) {
                $sql = "DELETE FROM " . USER_EPISODE_LINK_TABLE . "
                        WHERE episode_id = " . intval($dup['id']) . "
                          AND media_app_user_id IN (" . implode(',', $keepUsers) . ")";
                $this->query($sql);
            }
            $sql = "UPDATE " . USER_EPISODE_LINK_TABLE . "
                    SET episode_id = " . intval($keeper['id']) . "
                    WHERE episode_id = " . intval($dup['id']);
            $this->query($sql);
            $this->deleteEpisodeById($dup['id']);
        } else {
            $keepUsers = [];
            $sql       = "SELECT media_app_user_id
                    FROM " . USER_MOVIE_LINK_TABLE . "
                    WHERE movie_id = " . intval($keeper['id']);
            $res       = $this->query($sql);
            while ($userRow = $this->fetchAssoc($res)) {
                $keepUsers[] = intval($userRow['media_app_user_id']);
            }
            if ($keepUsers) {
                $sql = "DELETE FROM " . USER_MOVIE_LINK_TABLE . "
                        WHERE movie_id = " . intval($dup['id']) . "
                          AND media_app_user_id IN (" . implode(',', $keepUsers) . ")";
                $this->query($sql);
            }
            $sql = "UPDATE " . USER_MOVIE_LINK_TABLE . "
                    SET movie_id = " . intval($keeper['id']) . "
                    WHERE movie_id = " . intval($dup['id']);
            $this->query($sql);
            $this->deleteMovieById($dup['id']);
        }

        return 1;
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
        if ($table != EPISODE_TABLE && intval($year) > 0) {
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
        if ($table != EPISODE_TABLE && $year > 0) {
            $sets[] = "`year` = IF(`year` <= 0, " . $year . ", `year`)";
        }
        if ($poster != '') {
            $sets[] = "`poster` = IF(`poster` = '', '" . $this->prepare($poster) . "', `poster`)";
        }
        if (!$sets) {
            return 0;
        }

        $blankCheck = $table == EPISODE_TABLE
            ? "(`title` = '' OR `poster` = '')"
            : "(`title` = '' OR `year` <= 0 OR `poster` = '')";
        $sql        = "UPDATE " . $table . "
                SET " . implode(', ', $sets) . "
                WHERE (" . $where . ")
                AND " . $blankCheck;
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
            $sql = "SELECT id
                    FROM " . $table . "
                    LIMIT 1";
            $res = $this->query($sql);
            if ($this->fetchAssoc($res)) {
                return true;
            }
        }

        return false;
    }

    public function rootFolder($path, $type)
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

        if ($type == 'episode' && $parts) {
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

        $types = [
            'movie'   => MOVIE_TABLE,
            'series'  => SERIES_TABLE,
            'episode' => EPISODE_TABLE,
        ];
        $seen  = [];
        foreach ($types as $type => $table) {
            $sql = "SELECT path
                    FROM " . $table . "
                    WHERE " . $field . " != ''
                      AND path != ''";
            $res = $this->query($sql);
            while ($row = $this->fetchAssoc($res)) {
                $root = $this->rootFolder($row['path'] ?? '', $type);
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
