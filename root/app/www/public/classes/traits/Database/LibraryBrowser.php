<?php

/*
----------------------------------
------  Created: 091326   ------
------  Austin Best       ------
----------------------------------
*/

trait LibraryBrowser
{
    public function libraryBrowserLetter($title)
    {
        $first = strtoupper(substr(trim(strval($title)), 0, 1));
        if (strlen($first) && $first >= 'A' && $first <= 'Z') {
            return $first;
        }

        return '#';
    }

    public function libraryBrowserUserIds($mediaAppUserId)
    {
        $id = intval($mediaAppUserId);
        if (!$id) {
            return [];
        }

        $ids = [$id];
        foreach ($this->getMediaAppUserLinks($id) as $link) {
            $ids[] = intval($link['linked_media_app_user_id']);
        }

        $parent = $this->getMediaAppUserLink($id);
        if ($parent) {
            $masterId = intval($parent['media_app_user_id']);
            if ($masterId) {
                $ids[] = $masterId;
                foreach ($this->getMediaAppUserLinks($masterId) as $link) {
                    $ids[] = intval($link['linked_media_app_user_id']);
                }
            }
        }

        return array_values(array_unique(array_filter($ids)));
    }

    public function libraryBrowserKinds($type)
    {
        if ($type == 'movie') {
            return ['movie'];
        }
        if ($type == 'series') {
            return ['series'];
        }

        return ['movie', 'series'];
    }

    public function libraryBrowserWatchedWhere($kind, $userIds)
    {
        if (!$userIds) {
            return '';
        }

        $ids   = implode(',', array_map('intval', $userIds));
        $watch = '(started = 1 OR finished > 0 OR inprogress > 0)';
        if ($kind == 'movie') {
            return "id IN (SELECT movie_id FROM " . USER_MOVIE_LINK_TABLE . " WHERE media_app_user_id IN (" . $ids . ") AND " . $watch . ")";
        }

        return "id IN (SELECT e.series_id FROM " . EPISODE_TABLE . " e INNER JOIN " . USER_EPISODE_LINK_TABLE . " l ON l.episode_id = e.id WHERE l.media_app_user_id IN (" . $ids . ") AND (l.started = 1 OR l.finished > 0 OR l.inprogress > 0))";
    }

    public function libraryBrowserCursorWhere($cursor, $direction)
    {
        if (!$cursor || (($cursor['title'] ?? '') == '' && empty($cursor['kind']) && empty($cursor['id']))) {
            return '';
        }

        $title = $this->prepare($cursor['title'] ?? '');
        $kind  = $this->prepare($cursor['kind'] ?? '');
        $id    = intval($cursor['id'] ?? 0);
        if ($direction == 'up') {
            return "(title < '" . $title . "' OR (title = '" . $title . "' AND kind < '" . $kind . "') OR (title = '" . $title . "' AND kind = '" . $kind . "' AND id < " . $id . "))";
        }

        return "(title > '" . $title . "' OR (title = '" . $title . "' AND kind > '" . $kind . "') OR (title = '" . $title . "' AND kind = '" . $kind . "' AND id > " . $id . "))";
    }

    public function getLibraryBrowserItems($type, $userIds, $cursor, $direction, $letter, $limit)
    {
        $items     = [];
        $limit     = max(1, min(100, intval($limit) ?: 50));
        $direction = $direction == 'up' ? 'up' : 'down';
        $kinds     = $this->libraryBrowserKinds($type);
        $parts     = [];

        foreach ($kinds as $kind) {
            $table = $kind == 'movie' ? MOVIE_TABLE : SERIES_TABLE;
            $sql   = "SELECT id, title, year, poster, '" . $kind . "' AS kind FROM " . $table;
            $watch = $this->libraryBrowserWatchedWhere($kind, $userIds);
            if ($watch) {
                $sql .= " WHERE " . $watch;
            }
            $parts[] = $sql;
        }
        if (!$parts) {
            return $items;
        }

        $union  = count($parts) > 1 ? implode(' UNION ALL ', $parts) : $parts[0];
        $where  = [];
        $letter = strtoupper(trim(strval($letter)));
        if ($direction == 'down' && $letter != '' && $letter != '#') {
            $where[] = "title >= '" . $this->prepare($letter) . "'";
        }
        $cursorWhere = $this->libraryBrowserCursorWhere($cursor, $direction);
        if ($cursorWhere) {
            $where[] = $cursorWhere;
        }

        $order = $direction == 'up' ? 'title DESC, kind DESC, id DESC' : 'title ASC, kind ASC, id ASC';
        $sql   = "SELECT id, title, year, poster, kind FROM (" . $union . ") library_items";
        if ($where) {
            $sql .= " WHERE " . implode(' AND ', $where);
        }
        $sql .= " ORDER BY " . $order . " LIMIT " . $limit;
        $res  = $this->query($sql);
        while ($row = $this->fetchAssoc($res)) {
            $items[] = $row;
        }
        if ($direction == 'up') {
            $items = array_reverse($items);
        }

        return $this->attachLibraryBrowserHistoryCounts($items);
    }

    public function attachLibraryBrowserHistoryCounts($items)
    {
        if (!$items) {
            return $items;
        }

        $movieIds  = [];
        $seriesIds = [];
        foreach ($items as $row) {
            $id = intval($row['id'] ?? 0);
            if (!$id) {
                continue;
            }
            if (($row['kind'] ?? '') == 'series') {
                $seriesIds[] = $id;
            } else {
                $movieIds[] = $id;
            }
        }

        $movieUsers  = $this->libraryBrowserHistoryUserMap('movie', $movieIds);
        $seriesUsers = $this->libraryBrowserHistoryUserMap('series', $seriesIds);
        foreach ($items as &$row) {
            $id = intval($row['id'] ?? 0);
            if (($row['kind'] ?? '') == 'series') {
                $row['watchers'] = count($seriesUsers[$id] ?? []);
            } else {
                $row['watchers'] = count($movieUsers[$id] ?? []);
            }
        }
        unset($row);

        return $items;
    }

    public function libraryBrowserHistoryUserMap($kind, $itemIds)
    {
        $map = [];
        $ids = array_values(array_unique(array_filter(array_map('intval', $itemIds))));
        if (!$ids) {
            return $map;
        }

        $master = [];
        foreach ($this->getMediaApps() as $mediaApp) {
            if ($mediaApp['active'] && intval($mediaApp['role']) == MediaAppRoles::MASTER) {
                $master = $mediaApp;
                break;
            }
        }
        if (!$master) {
            return $map;
        }

        $users = $this->getMediaAppUsers($master['id']);
        if (!$users) {
            return $map;
        }

        $appUserToMaster = [];
        foreach ($users as $user) {
            $masterUserId                   = intval($user['id']);
            $appUserToMaster[$masterUserId] = $masterUserId;
            foreach ($this->getMediaAppUserLinks($masterUserId) as $link) {
                $linkedId = intval($link['linked_media_app_user_id'] ?? 0);
                if ($linkedId) {
                    $appUserToMaster[$linkedId] = $masterUserId;
                }
            }
        }

        $idList = implode(',', $ids);
        $watch  = '(started = 1 OR finished > 0 OR inprogress > 0)';
        if ($kind == 'movie') {
            $sql = "SELECT movie_id AS item_id, media_app_user_id
                    FROM " . USER_MOVIE_LINK_TABLE . "
                    WHERE movie_id IN (" . $idList . ")
                    AND " . $watch;
        } else {
            $sql = "SELECT e.series_id AS item_id, l.media_app_user_id
                    FROM " . USER_EPISODE_LINK_TABLE . " l
                    INNER JOIN " . EPISODE_TABLE . " e ON e.id = l.episode_id
                    WHERE e.series_id IN (" . $idList . ")
                    AND (l.started = 1 OR l.finished > 0 OR l.inprogress > 0)";
        }
        $res = $this->query($sql);
        while ($row = $this->fetchAssoc($res)) {
            $itemId       = intval($row['item_id']);
            $appUserId    = intval($row['media_app_user_id']);
            $masterUserId = intval($appUserToMaster[$appUserId] ?? 0);
            if (!$itemId || !$masterUserId) {
                continue;
            }
            if (!isset($map[$itemId])) {
                $map[$itemId] = [];
            }
            $map[$itemId][$masterUserId] = true;
        }

        return $map;
    }

    public function getLibraryBrowserLetters($type, $userIds)
    {
        $counts = [];
        $kinds  = $this->libraryBrowserKinds($type);
        $parts  = [];

        foreach ($kinds as $kind) {
            $table = $kind == 'movie' ? MOVIE_TABLE : SERIES_TABLE;
            $sql   = "SELECT CASE WHEN UPPER(LEFT(title, 1)) BETWEEN 'A' AND 'Z' THEN UPPER(LEFT(title, 1)) ELSE '#' END AS letter, COUNT(*) AS total FROM " . $table;
            $watch = $this->libraryBrowserWatchedWhere($kind, $userIds);
            if ($watch) {
                $sql .= " WHERE " . $watch;
            }
            $sql     .= " GROUP BY letter";
            $parts[]  = $sql;
        }
        if (!$parts) {
            return $counts;
        }

        $union = count($parts) > 1 ? implode(' UNION ALL ', $parts) : $parts[0];
        $sql   = "SELECT letter, SUM(total) AS total FROM (" . $union . ") library_letters GROUP BY letter";
        $res   = $this->query($sql);
        while ($row = $this->fetchAssoc($res)) {
            $counts[$row['letter']] = intval($row['total']);
        }

        return $counts;
    }

    public function getLibraryItem($kind, $id)
    {
        if ($kind == 'movie') {
            return $this->getMovie($id);
        }
        if ($kind == 'series') {
            return $this->getSeries($id);
        }

        return [];
    }

    public function getLibraryItemWatchMatrix($kind, $itemId)
    {
        $itemId = intval($itemId);
        $apps   = [];
        $master = [];
        foreach ($this->getMediaApps() as $mediaApp) {
            if (!$mediaApp['active']) {
                continue;
            }
            $apps[] = $mediaApp;
            if (intval($mediaApp['role']) == MediaAppRoles::MASTER) {
                $master = $mediaApp;
            }
        }

        $users = $master ? $this->getMediaAppUsers($master['id']) : [];
        $cells = [];
        if ($kind == 'movie') {
            $cells = $this->libraryMovieWatchCells($itemId);
        } else if ($kind == 'series') {
            $cells = $this->librarySeriesWatchCells($itemId);
        }

        $matrix = [];
        foreach ($users as $user) {
            $row = [];
            foreach ($apps as $app) {
                $appUserId = intval($user['id']);
                if (intval($user['media_app_id']) != intval($app['id'])) {
                    $link      = $this->getMediaAppUserLinkForApp(intval($user['id']), $app['id']);
                    $appUserId = intval($link['linked_media_app_user_id'] ?? 0);
                }
                $row[intval($app['id'])] = $appUserId && isset($cells[$appUserId]) ? $cells[$appUserId] : [];
            }
            $matrix[intval($user['id'])] = $row;
        }

        $withHistory = [];
        foreach ($users as $user) {
            $hasHistory = false;
            foreach ($matrix[intval($user['id'])] ?? [] as $cell) {
                if ($this->libraryWatchCellHasHistory($cell)) {
                    $hasHistory = true;
                    break;
                }
            }
            if ($hasHistory) {
                $withHistory[] = $user;
            }
        }

        return [
            'apps'      => $apps,
            'users'     => $withHistory,
            'noHistory' => count($users) - count($withHistory),
            'matrix'    => $matrix,
        ];
    }

    public function libraryWatchCellHasHistory($cell)
    {
        if (!$cell || !is_array($cell)) {
            return false;
        }

        return !empty($cell['finished'])
            || !empty($cell['inprogress'])
            || !empty($cell['started'])
            || !empty($cell['watched']);
    }

    public function libraryMovieWatchCells($movieId)
    {
        $cells = [];
        $sql   = "SELECT media_app_user_id, platform, started, inprogress, finished
                FROM " . USER_MOVIE_LINK_TABLE . "
                WHERE movie_id = " . intval($movieId);
        $res   = $this->query($sql);
        while ($row = $this->fetchAssoc($res)) {
            $cells[intval($row['media_app_user_id'])] = [
                'started'    => intval($row['started']),
                'inprogress' => intval($row['inprogress']),
                'finished'   => intval($row['finished']),
                'watched'    => 0,
                'total'      => 0,
            ];
        }

        return $cells;
    }

    public function librarySeriesWatchCells($seriesId)
    {
        $total = 0;
        $sql   = "SELECT COUNT(*) AS total
                FROM " . EPISODE_TABLE . "
                WHERE series_id = " . intval($seriesId);
        $res   = $this->query($sql);
        $row   = $this->fetchAssoc($res);
        $total = intval($row['total'] ?? 0);

        $cells = [];
        $sql   = "SELECT l.media_app_user_id, l.started, l.inprogress, l.finished, e.season, e.episode
                FROM " . USER_EPISODE_LINK_TABLE . " l
                INNER JOIN " . EPISODE_TABLE . " e ON e.id = l.episode_id
                WHERE e.series_id = " . intval($seriesId);
        $res   = $this->query($sql);
        while ($row = $this->fetchAssoc($res)) {
            $userId = intval($row['media_app_user_id']);
            if (!isset($cells[$userId])) {
                $cells[$userId] = [
                    'started'    => 0,
                    'inprogress' => 0,
                    'season'     => 0,
                    'episode'    => 0,
                    'finished'   => 0,
                    'watched'    => 0,
                    'total'      => $total,
                ];
            }
            if (intval($row['started']) || intval($row['finished']) || intval($row['inprogress'])) {
                $cells[$userId]['started'] = 1;
            }
            if (intval($row['finished'])) {
                $cells[$userId]['watched']++;
            }
            if (!intval($row['finished']) && intval($row['inprogress']) > 0) {
                $season  = intval($row['season']);
                $episode = intval($row['episode']);
                $better  = $season > intval($cells[$userId]['season'])
                    || ($season == intval($cells[$userId]['season']) && $episode > intval($cells[$userId]['episode']))
                    || (intval($cells[$userId]['inprogress']) <= 0);
                if ($better) {
                    $cells[$userId]['inprogress'] = intval($row['inprogress']);
                    $cells[$userId]['season']     = $season;
                    $cells[$userId]['episode']    = $episode;
                }
            }
        }

        foreach ($cells as $userId => $cell) {
            $cells[$userId]['finished'] = ($total > 0 && intval($cell['watched']) >= $total) ? 1 : 0;
        }

        return $cells;
    }

    public function getLibrarySeriesUserEpisodes($seriesId, $masterUserId, $mediaAppId)
    {
        $seriesId     = intval($seriesId);
        $masterUserId = intval($masterUserId);
        $mediaAppId   = intval($mediaAppId);
        $user         = $this->getMediaAppUser($masterUserId);
        $app          = $this->getMediaApp($mediaAppId);
        if (!$user || !$app) {
            return [];
        }

        $appUserId = intval($user['id']);
        if (intval($user['media_app_id']) != $mediaAppId) {
            $link      = $this->getMediaAppUserLinkForApp($masterUserId, $mediaAppId);
            $appUserId = intval($link['linked_media_app_user_id'] ?? 0);
        }

        $episodes = [];
        $sql      = "SELECT e.id, e.season, e.episode, e.title, e.path, e.plex_remote_id, e.emby_remote_id, e.jellyfin_remote_id, l.started, l.inprogress, l.finished
                FROM " . EPISODE_TABLE . " e
                LEFT JOIN " . USER_EPISODE_LINK_TABLE . " l ON l.episode_id = e.id
                    AND l.media_app_user_id = " . intval($appUserId) . "
                WHERE e.series_id = " . $seriesId . "
                ORDER BY e.season ASC, e.episode ASC, e.id ASC";
        $res      = $this->query($sql);
        while ($row = $this->fetchAssoc($res)) {
            $episodes[] = [
                'id'                 => intval($row['id']),
                'season'             => intval($row['season']),
                'episode'            => intval($row['episode']),
                'title'              => $row['title'] ?? '',
                'path'               => strval($row['path'] ?? ''),
                'plex_remote_id'     => strval($row['plex_remote_id'] ?? ''),
                'emby_remote_id'     => strval($row['emby_remote_id'] ?? ''),
                'jellyfin_remote_id' => strval($row['jellyfin_remote_id'] ?? ''),
                'started'            => intval($row['started'] ?? 0),
                'inprogress'         => intval($row['inprogress'] ?? 0),
                'finished'           => intval($row['finished'] ?? 0),
            ];
        }

        return [
            'user'     => $user,
            'app'      => $app,
            'episodes' => $episodes,
        ];
    }

    public function countLinkedUserItemWatchStats($userIds, $table, $itemField)
    {
        $stats = ['watched' => 0, 'started' => 0, 'seconds' => 0];
        if (!$userIds) {
            return $stats;
        }

        $ids = [];
        foreach ($userIds as $userId) {
            $id = intval($userId);
            if ($id) {
                $ids[$id] = $id;
            }
        }
        if (!$ids) {
            return $stats;
        }

        $sql = "SELECT
                    SUM(finished_flag) AS watched,
                    SUM(started_only) AS started,
                    SUM(watch_seconds) AS seconds
                FROM (
                    SELECT `" . $itemField . "` AS item_id,
                        CASE WHEN MAX(`finished`) > 0 THEN 1 ELSE 0 END AS finished_flag,
                        CASE
                            WHEN MAX(`finished`) = 0 AND (MAX(`started`) = 1 OR MAX(`inprogress`) > 0) THEN 1
                            ELSE 0
                        END AS started_only,
                        CASE
                            WHEN MAX(`finished`) > 0 THEN MAX(`finished`)
                            ELSE MAX(`inprogress`)
                        END AS watch_seconds
                    FROM " . $table . "
                    WHERE `media_app_user_id` IN (" . implode(',', $ids) . ")
                    GROUP BY `" . $itemField . "`
                ) AS watch_items";
        $res = $this->query($sql);
        $row = $this->fetchAssoc($res);

        return [
            'watched' => intval($row['watched'] ?? 0),
            'started' => intval($row['started'] ?? 0),
            'seconds' => intval($row['seconds'] ?? 0),
        ];
    }

    public function buildLibraryUserWatchStats($users)
    {
        $stats = [];
        foreach ($users as $user) {
            $userId   = intval($user['id'] ?? 0);
            $userIds  = $this->libraryBrowserUserIds($userId);
            $movies   = $this->countLinkedUserItemWatchStats($userIds, USER_MOVIE_LINK_TABLE, 'movie_id');
            $episodes = $this->countLinkedUserItemWatchStats($userIds, USER_EPISODE_LINK_TABLE, 'episode_id');
            $stats[]  = [
                'id'               => $userId,
                'username'         => $user['username'] ?? '',
                'is_admin'         => !empty($user['is_admin']) ? 1 : 0,
                'movies_watched'   => $movies['watched'],
                'movies_started'   => $movies['started'],
                'movies_seconds'   => $movies['seconds'],
                'episodes_watched' => $episodes['watched'],
                'episodes_started' => $episodes['started'],
                'episodes_seconds' => $episodes['seconds'],
                'watch_seconds'    => intval($movies['seconds']) + intval($episodes['seconds']),
            ];
        }

        return $stats;
    }

    public function getLibraryUserWatchStats($users)
    {
        $cached = memcacheGet('library-user-watch-stats');
        if (is_array($cached) && $this->libraryUserWatchStatsCacheValid($cached, $users)) {
            return $cached;
        }

        $stats = $this->buildLibraryUserWatchStats($users);
        memcacheSet('library-user-watch-stats', $stats);

        return $stats;
    }

    public function libraryUserWatchStatsCacheValid($cached, $users)
    {
        if (!is_array($cached) || count($cached) != count($users)) {
            return false;
        }

        foreach ($cached as $row) {
            if (!array_key_exists('watch_seconds', $row)) {
                return false;
            }
        }

        return true;
    }
}
