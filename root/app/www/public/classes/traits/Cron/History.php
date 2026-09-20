<?php

/*
----------------------------------
------  Created: 091126   ------
------  Austin Best       ------
----------------------------------
*/

trait History
{
    public function watchApps()
    {
        $mediaAppId = intval($this->sidecar['media_app_id'] ?? 0);
        $webhook    = intval($this->sidecar['trigger'] ?? 0) == MediaSyncTriggers::WEBHOOK;
        $apps       = [];
        foreach ($this->database->getMediaApps() as $mediaApp) {
            if ($mediaAppId && !$webhook) {
                if (intval($mediaApp['id']) == $mediaAppId) {
                    $apps[] = $mediaApp;
                }
                continue;
            }
            if (!$mediaApp['active'] && (!$mediaAppId || intval($mediaApp['id']) != $mediaAppId)) {
                continue;
            }
            $apps[] = $mediaApp;
        }

        return $apps;
    }

    public function isDryRun()
    {
        return !empty($this->sidecar['dry_run']);
    }

    public function isAutomaticJob()
    {
        return intval($this->sidecar['trigger'] ?? 0) == MediaSyncTriggers::AUTOMATIC;
    }

    public function syncWatch($mode)
    {
        if (!$this->hasLibraryData()) {
            logger($this->logfile, translate('historyNeedsLibraryData'));
            return;
        }

        $mode    = intval($mode);
        $minimum = $mode == MediaSyncModes::BOTH ? 2 : 1;
        $apps    = $this->onlineMediaApps($this->watchApps(), $minimum);
        if (!$apps) {
            return;
        }

        $master = [];
        foreach ($apps as $mediaApp) {
            if (intval($mediaApp['role'] ?? 0) == MediaAppRoles::MASTER) {
                $master = $mediaApp;
                break;
            }
        }
        if ($master) {
            global $mediaApps;
            $mediaApps->refreshUsers($master['id']);
            if ($this->isAutomaticJob() && $this->database->settingEnabled('syncHistoryNewUsers')) {
                $ids = [];
                foreach ($this->database->getMediaAppUsers($master['id']) as $user) {
                    $ids[] = intval($user['id']);
                }
                if ($ids) {
                    $this->sidecar['user_ids'] = $ids;
                }
            }
        }

        if ($mode == MediaSyncModes::PUSH) {
            $this->pushWatch($apps);
            return;
        }

        $index   = $this->watchItemIndex($apps);
        $masters = [];
        foreach ($this->database->getMediaAppUserLinks() as $link) {
            $masters[intval($link['linked_media_app_user_id'])] = intval($link['media_app_user_id']);
        }

        $groups = [];
        foreach ($apps as $mediaApp) {
            foreach ($this->selectedUsers($mediaApp['id']) as $user) {
                $userId              = intval($user['id']);
                $masterId            = $masters[$userId] ?? $userId;
                $groups[$masterId][] = [
                    'app'  => $mediaApp,
                    'user' => $user,
                ];
            }
        }

        $dbRows   = [];
        $pushRows = [];
        $dryPlans = [];
        $drySkips = [];
        $both     = $mode == MediaSyncModes::BOTH;
        $dry      = $this->isDryRun();
        $debug    = $this->isDebugLog();
        foreach ($groups as $members) {
            $this->stopIfCancelled();
            $this->syncWatchUser($members, $index, $both, $dry, $debug, $dbRows, $pushRows, $dryPlans, $drySkips);
        }

        if ($dry) {
            $this->logDryRunSummary($dryPlans, $mode, $drySkips);
            return;
        }

        $tables = [
            'dbRows'   => array_values($dbRows),
            'pushRows' => array_values($pushRows),
        ];
        $totals = $this->historyTableTotals($tables['dbRows']);
        logger($this->logfile, 'watch changed=' . $totals['changed'] . ' unchanged=' . $totals['unchanged']);
        $this->storeHistorySummary($tables, $mode);
    }

    public function historyUserMissingPin($user)
    {
        return !empty($user['pin_required']) && trim(strval($user['pin'] ?? '')) == '';
    }

    public function logHistoryMissingPin($app, $user)
    {
        $key = intval($app['id'] ?? 0) . ':' . intval($user['id'] ?? 0);
        if (!empty($this->sidecar['stats']['_pinSkip'][$key])) {
            return;
        }
        $this->sidecar['stats']['_pinSkip'][$key] = true;
        logger($this->logfile, translate('historySkipMissingPin', [strval($user['username'] ?? '')]));
    }

    public function watchMasterUserId($userId)
    {
        $userId = intval($userId);
        $link   = $this->database->getMediaAppUserLink($userId);
        if ($link) {
            return intval($link['media_app_user_id']);
        }

        return $userId;
    }

    public function appRoleLabel($app)
    {
        return intval($app['role'] ?? 0) == MediaAppRoles::MASTER ? translate('mainSource') : translate('listener');
    }

    public function watchItemIndex($apps)
    {
        $platforms = [
            MediaPlatforms::PLEX     => 'plex',
            MediaPlatforms::EMBY     => 'emby',
            MediaPlatforms::JELLYFIN => 'jellyfin',
        ];
        $libraries = [];
        foreach ($apps as $app) {
            $libraries[intval($app['id'])] = $this->database->getMediaAppLibraries($app['id']);
        }

        $series = [];
        foreach ($this->database->getSeriesRows() as $row) {
            $series[intval($row['id'])] = [
                'title' => trim(strval($row['title'] ?? '')),
                'path'  => strval($row['path'] ?? ''),
            ];
        }

        $index = [
            'libraries' => $libraries,
            'movie'     => ['id' => [], 'remote' => []],
            'episode'   => ['id' => [], 'remote' => []],
        ];
        foreach ($this->database->getMovies() as $row) {
            $item                              = $this->watchIndexItem('movie', $row, $platforms, $series);
            $index['movie']['id'][$item['id']] = $item;
            foreach ($platforms as $platform => $flag) {
                $remote = $item['remote'][$platform] ?? '';
                if ($remote != '') {
                    $index['movie']['remote'][$platform][$remote] = $item;
                }
            }
        }
        foreach ($this->database->getEpisodes() as $row) {
            $item                                = $this->watchIndexItem('episode', $row, $platforms, $series);
            $index['episode']['id'][$item['id']] = $item;
            foreach ($platforms as $platform => $flag) {
                $remote = $item['remote'][$platform] ?? '';
                if ($remote != '') {
                    $index['episode']['remote'][$platform][$remote] = $item;
                }
            }
        }
        $this->sidecar['watch_index'] = $index;

        return $index;
    }

    public function watchIndexItem($type, $row, $platforms, $series)
    {
        $remote = [];
        $flag   = [];
        foreach ($platforms as $platform => $name) {
            $flag[$platform]   = intval($row[$name] ?? 0);
            $remote[$platform] = trim(strval($row[$name . '_remote_id'] ?? ''));
        }
        $id         = intval($row['id']);
        $title      = trim(strval($row['title'] ?? ''));
        $label      = $title;
        $seriesId   = 0;
        $seriesPath = '';
        $misplaced  = false;
        if ($type == 'movie') {
            $year = intval($row['year'] ?? 0);
            if ($label == '') {
                $label = 'movie #' . $id;
            } else if ($year) {
                $label .= ' (' . $year . ')';
            }
        } else {
            $seriesId   = intval($row['series_id'] ?? 0);
            $seriesMeta = $series[$seriesId] ?? [];
            if (is_array($seriesMeta)) {
                $show       = trim(strval($seriesMeta['title'] ?? ''));
                $seriesPath = strval($seriesMeta['path'] ?? '');
            } else {
                $show = trim(strval($seriesMeta));
            }
            if ($show == '') {
                $show = 'series #' . $seriesId;
            }
            $code  = 'S' . str_pad(strval(intval($row['season'] ?? 0)), 2, '0', STR_PAD_LEFT)
                . 'E' . str_pad(strval(intval($row['episode'] ?? 0)), 2, '0', STR_PAD_LEFT);
            $label = $show . ' ' . $code;
            if ($title != '') {
                $label .= ' ' . $title;
            }
            $misplaced = $this->watchEpisodeMisplaced(strval($row['path'] ?? ''), $seriesPath, $seriesId);
        }

        return [
            'id'          => $id,
            'path'        => strval($row['path'] ?? ''),
            'label'       => $label,
            'flag'        => $flag,
            'remote'      => $remote,
            'series_id'   => $seriesId,
            'series_path' => $seriesPath,
            'misplaced'   => $misplaced ? 1 : 0,
        ];
    }

    public function watchEpisodeMisplaced($episodePath, $seriesPath, $seriesId = 0)
    {
        $episodePath = $this->database->normalizeLibraryPath($episodePath);
        $seriesPath  = $this->database->normalizeLibraryPath($seriesPath);
        $seriesId    = intval($seriesId);
        if ($episodePath == '' || $seriesPath == '' || !$seriesId) {
            return false;
        }
        if ($this->database->pathUnderRoot($episodePath, $seriesPath)) {
            return false;
        }

        $showPath = $this->database->mediaLibraryShowPath($episodePath);
        if (
            $showPath != '' && (
                strcasecmp($showPath, $seriesPath) == 0
                || $this->database->pathSlashlessKey($showPath) == $this->database->pathSlashlessKey($seriesPath)
            )
        ) {
            return false;
        }

        $targetId = $this->database->findSeriesIdForEpisodePath($episodePath);
        if (!$targetId || $targetId == $seriesId) {
            return false;
        }

        return true;
    }

    public function watchLibraryTitle($libraries, $path)
    {
        $path = strtolower($this->database->normalizeLibraryPath($path));
        $best = '';
        $len  = 0;
        foreach ($libraries as $library) {
            foreach ($library['paths'] ?? [] as $raw) {
                $folder = strtolower($this->database->normalizeLibraryPath($raw));
                if ($folder == '') {
                    continue;
                }
                if ($path == $folder || str_starts_with($path, $folder . '/')) {
                    if (strlen($folder) > $len) {
                        $len  = strlen($folder);
                        $best = trim(strval($library['title'] ?? ''));
                    }
                }
            }
        }

        return $best;
    }

    public function historyPlexApp()
    {
        $plex = [];
        foreach ($this->database->getMediaApps() as $app) {
            if (intval($app['platform'] ?? 0) != MediaPlatforms::PLEX || empty($app['active'])) {
                continue;
            }
            if (intval($app['role'] ?? 0) == MediaAppRoles::MASTER) {
                return $app;
            }
            if (!$plex) {
                $plex = $app;
            }
        }

        return $plex;
    }

    public function historyRepairUnlinkedItem($type, $item, $listenerApp, &$index)
    {
        global $mediaApps;

        $itemId           = intval($item['id'] ?? 0);
        $listenerPlatform = intval($listenerApp['platform'] ?? 0);
        if (!$itemId || !$listenerPlatform) {
            return [];
        }
        if (!empty($item['flag'][$listenerPlatform]) && trim(strval($item['remote'][$listenerPlatform] ?? '')) != '') {
            return [];
        }

        $plexApp    = $this->historyPlexApp();
        $plexRemote = trim(strval($item['remote'][MediaPlatforms::PLEX] ?? ''));
        if (!$plexApp || $plexRemote == '') {
            return [];
        }

        if (!isset($this->plexCheckFilesCache)) {
            $this->plexCheckFilesCache = [];
        }
        if (!array_key_exists($plexRemote, $this->plexCheckFilesCache)) {
            $this->plexCheckFilesCache[$plexRemote] = $mediaApps->plexResolvePathWithCheckFiles(
                strval($plexApp['url'] ?? ''),
                strval($plexApp['token'] ?? ''),
                $plexRemote,
            );
        }
        $fixedPath = strval($this->plexCheckFilesCache[$plexRemote] ?? '');
        if ($fixedPath == '') {
            return [];
        }

        $pathType  = $type == 'movie' ? 'movie' : 'episode';
        $fixedPath = $this->database->normalizePath($fixedPath, $pathType);
        if ($fixedPath == '') {
            return [];
        }

        $currentPath = $this->database->normalizeLibraryPath($item['path'] ?? '');
        $table       = $type == 'movie' ? MOVIE_TABLE : EPISODE_TABLE;
        $pathChanged = strcasecmp($fixedPath, $currentPath) != 0;
        if ($pathChanged) {
            $this->database->forceLibraryItemPath($table, $itemId, $fixedPath, $pathType);
            $item['path'] = $fixedPath;
            logger($this->logfile, 'history path repair id=' . $itemId . ' type=' . $type
                . ' from=' . $currentPath . ' to=' . $fixedPath);
            loggerFlush($this->logfile);
        }

        $liveRemote = $this->historyFindListenerRemote($type, $item, $listenerApp, $fixedPath);
        if ($liveRemote == '') {
            $index[$type]['id'][$itemId] = $item;
            if ($pathChanged) {
                $this->recordHistoryLibraryRepair([
                    'label'        => strval($item['label'] ?? ($type . ' #' . $itemId)),
                    'from'         => $currentPath,
                    'to'           => $fixedPath,
                    'app'          => strval($listenerApp['name'] ?? ''),
                    'path_changed' => true,
                    'linked'       => false,
                    'remote'       => '',
                ]);
            }
            return [];
        }

        $this->database->setMediaLibraryPlatformById($table, $listenerPlatform, $liveRemote, $fixedPath, $itemId);
        $item['flag'][$listenerPlatform]                        = 1;
        $item['remote'][$listenerPlatform]                      = $liveRemote;
        $index[$type]['id'][$itemId]                            = $item;
        $index[$type]['remote'][$listenerPlatform][$liveRemote] = $item;
        logger($this->logfile, 'history link repair id=' . $itemId . ' type=' . $type
            . ' app=' . strval($listenerApp['name'] ?? '') . ' remote=' . $liveRemote);
        loggerFlush($this->logfile);
        $this->recordHistoryLibraryRepair([
            'label'        => strval($item['label'] ?? ($type . ' #' . $itemId)),
            'from'         => $currentPath,
            'to'           => $fixedPath,
            'app'          => strval($listenerApp['name'] ?? ''),
            'path_changed' => $pathChanged,
            'linked'       => true,
            'remote'       => $liveRemote,
        ]);

        return $item;
    }

    public function recordHistoryLibraryRepair($repair)
    {
        if (empty($this->sidecar['library_repairs'])) {
            $this->sidecar['library_repairs'] = [];
        }
        $key = strval($repair['label'] ?? '') . "\0" . strval($repair['app'] ?? '') . "\0" . strval($repair['to'] ?? '');
        foreach ($this->sidecar['library_repairs'] as $existing) {
            $existingKey = strval($existing['label'] ?? '') . "\0" . strval($existing['app'] ?? '') . "\0" . strval($existing['to'] ?? '');
            if ($existingKey == $key) {
                return;
            }
        }
        $this->sidecar['library_repairs'][] = $repair;
    }

    public function historyLibraryRepairLines()
    {
        $lines = [];
        foreach ($this->sidecar['library_repairs'] ?? [] as $repair) {
            $label = strval($repair['label'] ?? '');
            $app   = strval($repair['app'] ?? '');
            $from  = strval($repair['from'] ?? '');
            $to    = strval($repair['to'] ?? '');
            if (!empty($repair['path_changed']) && !empty($repair['linked'])) {
                $lines[] = 'Fixed path for ' . $label . ': ' . $from . ' -> ' . $to
                    . '; linked on ' . $app . ' (remote ' . strval($repair['remote'] ?? '') . ')';
            } else if (!empty($repair['path_changed'])) {
                $lines[] = 'Fixed path for ' . $label . ': ' . $from . ' -> ' . $to
                    . '; listener ' . $app . ' still not linked';
            } else if (!empty($repair['linked'])) {
                $lines[] = 'Linked ' . $label . ' on ' . $app . ' using checked path ' . $to
                    . ' (remote ' . strval($repair['remote'] ?? '') . ')';
            }
        }

        return $lines;
    }

    public function historyFindListenerRemote($type, $item, $listenerApp, $path)
    {
        global $mediaApps;

        $platform = intval($listenerApp['platform'] ?? 0);
        if (!$mediaApps->isOnline($listenerApp)) {
            return '';
        }

        $pathKey = $this->database->pathSlashlessKey($path);
        if ($type == 'movie') {
            $items = $mediaApps->getItems($listenerApp, []);
            foreach ($items['movies'] ?? [] as $live) {
                $livePath = $this->database->normalizeLibraryPath($live['path'] ?? '');
                if (
                    $livePath != '' && (
                        strcasecmp($livePath, $path) == 0
                        || ($pathKey != '' && $this->database->pathSlashlessKey($livePath) == $pathKey)
                    )
                ) {
                    return trim(strval($live['remote_id'] ?? ''));
                }
            }

            return '';
        }

        $episode = $this->database->getEpisode(intval($item['id'] ?? 0));
        if (!$episode) {
            return '';
        }
        $season       = intval($episode['season'] ?? 0);
        $epnum        = intval($episode['episode'] ?? 0);
        $series       = $this->database->getSeries(intval($episode['series_id'] ?? 0));
        $flag         = $this->database->mediaLibraryFlag($platform);
        $field        = $this->database->mediaLibraryRemoteField($platform);
        $seriesRemote = '';
        if ($series) {
            if (intval($series[$flag] ?? 0) && trim(strval($series[$field] ?? '')) != '') {
                $seriesRemote = trim(strval($series[$field]));
            }
        }
        if ($seriesRemote == '') {
            return '';
        }

        $items  = $mediaApps->getItems($listenerApp, [], [
            'remote_id' => $seriesRemote,
            'title'     => strval($series['title'] ?? ''),
        ]);
        $byCode = [];
        foreach ($items['episodes'] ?? [] as $live) {
            $livePath = $this->database->normalizeLibraryPath($live['path'] ?? '');
            if (
                $livePath != '' && (
                    strcasecmp($livePath, $path) == 0
                    || ($pathKey != '' && $this->database->pathSlashlessKey($livePath) == $pathKey)
                )
            ) {
                return trim(strval($live['remote_id'] ?? ''));
            }
            if (intval($live['season'] ?? 0) == $season && intval($live['episode'] ?? 0) == $epnum) {
                $byCode = $live;
            }
        }
        if ($byCode) {
            return trim(strval($byCode['remote_id'] ?? ''));
        }

        return '';
    }

    public function syncWatchUser($members, $index, $both, $dry, $debug, &$dbRows, &$pushRows, &$dryPlans, &$drySkips)
    {
        global $mediaApps;

        $existing = [];
        $incoming = [];
        foreach ($members as &$member) {
            $this->stopIfCancelled();
            $app  = $member['app'];
            $user = $member['user'];
            $name = strval($user['username'] ?? '');
            if ($this->historyUserMissingPin($user)) {
                logger($this->logfile, translate('historyFallbackMissingPin', [$name]));
                loggerFlush($this->logfile);
            }
            logger($this->logfile, $app['name'] . ' ' . $name);
            loggerFlush($this->logfile);

            $state = $this->database->getUserWatchState($user['id'], $app['platform']);
            if (!empty($state['user'])) {
                $user = array_merge($user, $state['user']);
            }
            $member['user']                = $user;
            $existing[intval($user['id'])] = $state;

            $status                 = $mediaApps->getWatchStatus($app, $user['remote_id'], $name, $user['id'], $user);
            $platform               = intval($app['platform']);
            $member['watch_error']  = !empty($status['error']);
            $member['watch_remote'] = [
                'movie'   => $status['movies'] ?? [],
                'episode' => $status['episodes'] ?? [],
            ];
            foreach (['movies' => 'movie', 'episodes' => 'episode'] as $bucket => $type) {
                foreach ($status[$bucket] ?? [] as $remoteId => $watch) {
                    if (!is_array($watch)) {
                        continue;
                    }
                    $item = $index[$type]['remote'][$platform][strval($remoteId)] ?? [];
                    if (!$item) {
                        continue;
                    }
                    $key       = $type . ':' . intval($item['id']);
                    $sourceKey = intval($app['id']) . ':' . intval($user['id']);
                    $watch     = [
                        'started'    => intval($watch['started'] ?? 0) ? 1 : 0,
                        'inprogress' => intval($watch['inprogress'] ?? 0),
                        'finished'   => intval($watch['finished'] ?? 0),
                    ];
                    if (empty($incoming[$key]['sources'][$sourceKey])) {
                        $incoming[$key]['item']                = $item;
                        $incoming[$key]['type']                = $type;
                        $incoming[$key]['sources'][$sourceKey] = $watch;
                    } else {
                        $incoming[$key]['sources'][$sourceKey] = mergeWatchState($incoming[$key]['sources'][$sourceKey], $watch);
                    }
                    $this->addStat('pulled', 1, $app);
                }
            }
        }
        unset($member);

        $counts = [];
        foreach ($incoming as $incomingKey => $itemIncoming) {
            $this->stopIfCancelled();
            $type   = $itemIncoming['type'];
            $item   = $itemIncoming['item'];
            $merged = [];
            foreach ($members as $member) {
                $row = $existing[intval($member['user']['id'])][$type][intval($item['id'])] ?? [];
                if ($row) {
                    $merged = mergeWatchState($merged, $row);
                }
            }
            foreach ($itemIncoming['sources'] as $sourceWatch) {
                $merged = mergeWatchState($merged, $sourceWatch);
            }

            foreach ($members as $member) {
                $app       = $member['app'];
                $user      = $member['user'];
                $userId    = intval($user['id']);
                $platform  = intval($app['platform']);
                $remote    = $item['remote'][$platform] ?? '';
                $sourceKey = intval($app['id']) . ':' . $userId;
                $gathered  = $itemIncoming['sources'][$sourceKey] ?? null;
                $library   = $this->watchLibraryTitle($index['libraries'][intval($app['id'])] ?? [], $item['path']);
                if ($library == '') {
                    $library = strval($app['name'] ?? '');
                }
                $countKey = intval($app['id']) . "\0" . $library . "\0" . strval($user['username'] ?? '');
                if (empty($counts[$countKey])) {
                    $counts[$countKey] = [
                        'app'               => $app,
                        'library'           => $library,
                        'user'              => strval($user['username'] ?? ''),
                        'role'              => $this->appRoleLabel($app),
                        'movies_finished'   => 0,
                        'movies_progress'   => 0,
                        'episodes_finished' => 0,
                        'episodes_progress' => 0,
                        'unchanged'         => 0,
                        'skipped'           => 0,
                        'cleared'           => 0,
                        'cascade_pushed'    => 0,
                    ];
                }
                if ($remote == '' || empty($item['flag'][$platform])) {
                    $repaired = $this->historyRepairUnlinkedItem($type, $item, $app, $index);
                    if ($repaired) {
                        $item                           = $repaired;
                        $incoming[$incomingKey]['item'] = $repaired;
                        $remote                         = trim(strval($item['remote'][$platform] ?? ''));
                    }
                }
                if ($remote == '' || empty($item['flag'][$platform])) {
                    if (!watchStateSatisfies($gathered ?? [], $merged)) {
                        $counts[$countKey]['skipped']++;
                        if ($dry) {
                            $drySkips[] = [
                                'type'    => $type,
                                'item_id' => intval($item['id']),
                                'label'   => $item['label'],
                                'app'     => $app,
                                'user'    => $user,
                                'watch'   => $merged,
                                'library' => $library,
                                'reason'  => 'not linked on this app library in Watchsync',
                            ];
                        }
                    }
                    continue;
                }

                if (
                    intval($app['role'] ?? 0) == MediaAppRoles::MASTER
                    && empty($member['watch_error'])
                    && $gathered == null
                    && !array_key_exists(strval($remote), $member['watch_remote'][$type] ?? [])
                ) {
                    continue;
                }

                $row       = $existing[$userId][$type][intval($item['id'])] ?? [];
                $dbChanged = !$row || !watchStatesEqual($row, $merged);
                $needsPush = $both && !watchStateSatisfies($gathered ?? [], $merged);
                if ($dbChanged && !$dry) {
                    $saved                                         = $type == 'movie'
                        ? $this->database->saveUserMovieLink($row, $item['id'], $userId, $platform, $merged['started'], $merged['inprogress'], $merged['finished'])
                        : $this->database->saveUserEpisodeLink($row, $item['id'], $userId, $platform, $merged['started'], $merged['inprogress'], $merged['finished']);
                    $existing[$userId][$type][intval($item['id'])] = [
                        'id'         => intval($saved['id'] ?? 0),
                        'item_id'    => intval($item['id']),
                        'started'    => intval($merged['started'] ?? 0),
                        'inprogress' => intval($merged['inprogress'] ?? 0),
                        'finished'   => intval($merged['finished'] ?? 0),
                    ];
                    $this->logWatchSetAction($type, intval($item['id']), $merged, $user['username'] ?? '', $app['name'] ?? '', APP_NAME, false);
                }
                if ($needsPush && !$dry) {
                    $this->logWatchSetAction($type, intval($item['id']), $merged, $user['username'] ?? '', APP_NAME, $app['name'] ?? '', false);
                    $mediaApps->setWatchStatus($app, $user, $remote, $merged['started'], $merged['inprogress'], $merged['finished']);
                    $this->addStat('pushed', 1, $app);
                }
                if ($dry && ($dbChanged || $needsPush)) {
                    $dryPlans[] = [
                        'type'       => $type,
                        'item_id'    => intval($item['id']),
                        'label'      => $item['label'],
                        'app'        => $app,
                        'user'       => $user,
                        'watch'      => $merged,
                        'db_changed' => $dbChanged,
                        'needs_push' => $needsPush,
                    ];
                }

                $status = $this->watchResultStatus($merged);
                if ($status == '' || $status == 'started') {
                    $status = 'inProgress';
                }
                if (!$dbChanged) {
                    $counts[$countKey]['unchanged']++;
                    $this->addHistoryResult($app, $user['username'] ?? '', $type, 'unchanged');
                    $this->addStat('unchanged', 1, $app);
                } else {
                    $this->addHistoryResult($app, $user['username'] ?? '', $type, $status);
                    if ($type == 'movie') {
                        $counts[$countKey][$status == 'finished' ? 'movies_finished' : 'movies_progress']++;
                    } else {
                        $counts[$countKey][$status == 'finished' ? 'episodes_finished' : 'episodes_progress']++;
                    }
                }

                $summaryKey = strval($app['name'] ?? '') . "\0" . $this->appRoleLabel($app) . "\0" . strval($user['username'] ?? '');
                $dbRows     = $this->historySummaryRow($dbRows, $summaryKey, $app['name'] ?? '', $this->appRoleLabel($app), $user['username'] ?? '', true);
                if ($dbChanged) {
                    $dbRows[$summaryKey][$this->historyChangeColumn($type, $merged)]++;
                } else {
                    $dbRows[$summaryKey][7]++;
                }
                if ($both) {
                    $pushRows = $this->historySummaryRow($pushRows, $summaryKey, $app['name'] ?? '', $this->appRoleLabel($app), $user['username'] ?? '', false);
                    if ($needsPush) {
                        $pushRows[$summaryKey][$this->historyChangeColumn($type, $merged)]++;
                    } else {
                        $pushRows[$summaryKey][7]++;
                    }
                }
            }
        }

        $this->clearAbsentLocalWatchLinks($members, $index, $existing, $incoming, $dry, $debug, $dbRows, $pushRows, $dryPlans, $counts);

        if (!$debug) {
            foreach ($counts as $count) {
                logger($this->logfile, $count['app']['name'] . ' / ' . $count['library'] . ' / ' . $count['user']
                    . ' movies finished=' . $count['movies_finished']
                    . ' in progress=' . $count['movies_progress']
                    . ' episodes finished=' . $count['episodes_finished']
                    . ' in progress=' . $count['episodes_progress']
                    . ' unchanged=' . $count['unchanged']
                    . ' skipped=' . $count['skipped']
                    . ' cleared=' . intval($count['cleared'] ?? 0)
                    . ' cascade_pushed=' . intval($count['cascade_pushed'] ?? 0));
                loggerFlush($this->logfile);
            }
        }
    }

    public function clearAbsentLocalWatchLinks($members, $index, &$existing, $incoming, $dry, $debug, &$dbRows, &$pushRows, &$dryPlans, &$counts)
    {
        $ordered = $members;
        usort($ordered, function ($a, $b) {
            $aMaster = intval($a['app']['role'] ?? 0) == MediaAppRoles::MASTER ? 0 : 1;
            $bMaster = intval($b['app']['role'] ?? 0) == MediaAppRoles::MASTER ? 0 : 1;

            return $aMaster <=> $bMaster;
        });

        foreach ($ordered as $member) {
            $this->stopIfCancelled();
            $app  = $member['app'];
            $user = $member['user'];
            if (!empty($member['watch_error'])) {
                logger($this->logfile, strval($app['name'] ?? '') . ' / ' . strval($user['username'] ?? '')
                    . ' skip local clear: watch pull not authoritative');
                loggerFlush($this->logfile);
                continue;
            }

            $userId   = intval($user['id'] ?? 0);
            $platform = intval($app['platform'] ?? 0);
            $isMaster = intval($app['role'] ?? 0) == MediaAppRoles::MASTER;
            if (!$userId || !$platform) {
                continue;
            }

            $sourceKey  = intval($app['id']) . ':' . $userId;
            $remoteMaps = $member['watch_remote'] ?? ['movie' => [], 'episode' => []];
            $clearedN   = 0;
            $cascadeN   = 0;
            foreach (['movie', 'episode'] as $type) {
                foreach ($existing[$userId][$type] ?? [] as $itemId => $row) {
                    $this->stopIfCancelled();
                    $itemId = intval($itemId);
                    if (!$itemId) {
                        continue;
                    }
                    if (!intval($row['started'] ?? 0) && !intval($row['finished'] ?? 0) && !intval($row['inprogress'] ?? 0)) {
                        continue;
                    }

                    $incomingKey = $type . ':' . $itemId;
                    if (!empty($incoming[$incomingKey]['sources'][$sourceKey])) {
                        continue;
                    }
                    if (!$isMaster && !empty($incoming[$incomingKey]['sources'])) {
                        continue;
                    }

                    $item   = $index[$type]['id'][$itemId] ?? [];
                    $remote = trim(strval($item['remote'][$platform] ?? ''));
                    $linked = !empty($item['flag'][$platform]) && $remote != '';
                    if ($linked) {
                        $map = $remoteMaps[$type] ?? [];
                        if (array_key_exists($remote, $map) || array_key_exists(strval($remote), $map)) {
                            continue;
                        }
                    }

                    $cleared = ['started' => 0, 'inprogress' => 0, 'finished' => 0];
                    $this->recordAbsentLocalClear(
                        $type,
                        $itemId,
                        $item,
                        $row,
                        $app,
                        $user,
                        $cleared,
                        $dry,
                        $existing,
                        $dbRows,
                        $dryPlans,
                        $counts,
                        $clearedN,
                    );

                    if ($isMaster) {
                        $cascadeN += $this->cascadeMasterAbsentClearToListeners(
                            $members,
                            $index,
                            $type,
                            $itemId,
                            $item,
                            $cleared,
                            $dry,
                            $existing,
                            $dbRows,
                            $pushRows,
                            $dryPlans,
                            $counts,
                        );
                    }
                }
            }
            logger($this->logfile, strval($app['name'] ?? '') . ' / ' . strval($user['username'] ?? '')
                . ' local absent cleared=' . $clearedN
                . ' cascade_pushed=' . $cascadeN
                . ' remote movies=' . count($remoteMaps['movie'] ?? [])
                . ' episodes=' . count($remoteMaps['episode'] ?? []));
            loggerFlush($this->logfile);
        }
    }

    public function recordAbsentLocalClear($type, $itemId, $item, $row, $app, $user, $cleared, $dry, &$existing, &$dbRows, &$dryPlans, &$counts, &$clearedN)
    {
        $userId  = intval($user['id'] ?? 0);
        $library = $this->watchLibraryTitle($this->sidecar['watch_index']['libraries'][intval($app['id'])] ?? [], $item['path'] ?? '');
        if ($library == '') {
            $library = strval($app['name'] ?? '');
        }
        $countKey = intval($app['id']) . "\0" . $library . "\0" . strval($user['username'] ?? '');
        if (empty($counts[$countKey])) {
            $counts[$countKey] = [
                'app'               => $app,
                'library'           => $library,
                'user'              => strval($user['username'] ?? ''),
                'role'              => $this->appRoleLabel($app),
                'movies_finished'   => 0,
                'movies_progress'   => 0,
                'episodes_finished' => 0,
                'episodes_progress' => 0,
                'unchanged'         => 0,
                'skipped'           => 0,
                'cleared'           => 0,
                'cascade_pushed'    => 0,
            ];
        }
        $counts[$countKey]['cleared']++;
        $clearedN++;

        if ($dry) {
            $platform   = intval($app['platform'] ?? 0);
            $remote     = trim(strval($item['remote'][$platform] ?? ''));
            $dryPlans[] = [
                'type'       => $type,
                'item_id'    => $itemId,
                'label'      => $item['label'] ?? '',
                'app'        => $app,
                'user'       => $user,
                'watch'      => $cleared,
                'db_changed' => true,
                'needs_push' => false,
                'cleared'    => true,
                'misplaced'  => !empty($item['misplaced']),
                'unlinked'   => empty($item['flag'][$platform]) || $remote == '',
            ];

            return;
        }

        $linkId = intval($row['id'] ?? 0);
        if ($type == 'movie') {
            if ($linkId) {
                $this->database->deleteUserMovieLinkById($linkId);
            }
        } else if ($linkId) {
            $this->database->deleteUserEpisodeLinkById($linkId);
        }
        unset($existing[$userId][$type][$itemId]);
        $this->addHistoryResult($app, $user['username'] ?? '', $type, 'cleared');
        $this->addStat('cleared', 1, $app);

        $summaryKey = strval($app['name'] ?? '') . "\0" . $this->appRoleLabel($app) . "\0" . strval($user['username'] ?? '');
        $dbRows     = $this->historySummaryRow($dbRows, $summaryKey, $app['name'] ?? '', $this->appRoleLabel($app), $user['username'] ?? '', true);
        $dbRows[$summaryKey][$this->historyChangeColumn($type, ['finished' => 0, 'inprogress' => 1])]++;
    }

    public function cascadeMasterAbsentClearToListeners($members, $index, $type, $itemId, $item, $cleared, $dry, &$existing, &$dbRows, &$pushRows, &$dryPlans, &$counts)
    {
        global $mediaApps;

        $pushed = 0;
        foreach ($members as $member) {
            $this->stopIfCancelled();
            $app = $member['app'];
            if (intval($app['role'] ?? 0) == MediaAppRoles::MASTER) {
                continue;
            }

            $user     = $member['user'];
            $userId   = intval($user['id'] ?? 0);
            $platform = intval($app['platform'] ?? 0);
            if (!$userId || !$platform) {
                continue;
            }

            $remote = trim(strval($item['remote'][$platform] ?? ''));
            if ($remote == '' || empty($item['flag'][$platform])) {
                continue;
            }

            $row = $existing[$userId][$type][$itemId] ?? [];
            if ($row && (intval($row['started'] ?? 0) || intval($row['finished'] ?? 0) || intval($row['inprogress'] ?? 0))) {
                $ignored = 0;
                $this->recordAbsentLocalClear($type, $itemId, $item, $row, $app, $user, $cleared, $dry, $existing, $dbRows, $dryPlans, $counts, $ignored);
            }

            $library = $this->watchLibraryTitle($index['libraries'][intval($app['id'])] ?? [], $item['path'] ?? '');
            if ($library == '') {
                $library = strval($app['name'] ?? '');
            }
            $countKey = intval($app['id']) . "\0" . $library . "\0" . strval($user['username'] ?? '');
            if (empty($counts[$countKey])) {
                $counts[$countKey] = [
                    'app'               => $app,
                    'library'           => $library,
                    'user'              => strval($user['username'] ?? ''),
                    'role'              => $this->appRoleLabel($app),
                    'movies_finished'   => 0,
                    'movies_progress'   => 0,
                    'episodes_finished' => 0,
                    'episodes_progress' => 0,
                    'unchanged'         => 0,
                    'skipped'           => 0,
                    'cleared'           => 0,
                    'cascade_pushed'    => 0,
                ];
            }
            $counts[$countKey]['cascade_pushed'] = intval($counts[$countKey]['cascade_pushed'] ?? 0) + 1;
            $pushed++;

            if ($dry) {
                $dryPlans[] = [
                    'type'         => $type,
                    'item_id'      => $itemId,
                    'label'        => $item['label'] ?? '',
                    'app'          => $app,
                    'user'         => $user,
                    'watch'        => $cleared,
                    'db_changed'   => false,
                    'needs_push'   => true,
                    'cleared'      => true,
                    'cascade_push' => true,
                ];
                continue;
            }

            $mediaApps->setWatchStatus($app, $user, $remote, 0, 0, 0);
            $this->addStat('pushed', 1, $app);
            $this->addHistoryResult($app, $user['username'] ?? '', $type, 'cascadeCleared');

            $summaryKey = strval($app['name'] ?? '') . "\0" . $this->appRoleLabel($app) . "\0" . strval($user['username'] ?? '');
            $pushRows   = $this->historySummaryRow($pushRows, $summaryKey, $app['name'] ?? '', $this->appRoleLabel($app), $user['username'] ?? '', false);
            $pushRows[$summaryKey][$this->historyChangeColumn($type, ['finished' => 0, 'inprogress' => 1])]++;
        }

        return $pushed;
    }

    public function dryRunItemLabel($type, $itemId)
    {
        $item = $this->sidecar['watch_index'][$type]['id'][intval($itemId)] ?? [];
        if (!empty($item['label'])) {
            return $item['label'];
        }

        return ($type == 'movie' ? 'movie #' : 'episode #') . intval($itemId);
    }

    public function dryRunSkipLine($skip)
    {
        $type    = ($skip['type'] ?? '') == 'movie' ? 'movie' : 'episode';
        $itemId  = intval($skip['item_id'] ?? 0);
        $app     = strval($skip['app']['name'] ?? '');
        $role    = $this->appRoleLabel($skip['app'] ?? []);
        $user    = strval($skip['user']['username'] ?? '');
        $library = trim(strval($skip['library'] ?? ''));
        $reason  = trim(strval($skip['reason'] ?? 'unavailable'));
        $parts   = [
            'User=' . $user,
            'Media App=' . $app,
            'Role=' . $role,
        ];
        if ($library != '') {
            $parts[] = 'Library=' . $library;
        }

        if ($type == 'movie') {
            $movie   = $itemId ? $this->database->getMovie($itemId) : [];
            $title   = trim(strval($movie['title'] ?? ($skip['label'] ?? '')));
            $year    = intval($movie['year'] ?? 0);
            $parts[] = 'Movie=' . ($title != '' ? $title : ('#' . $itemId));
            if ($year > 0) {
                $parts[] = 'Year=' . $year;
            }
        } else {
            $episode = $itemId ? $this->database->getEpisode($itemId) : [];
            $series  = $episode ? $this->database->getSeries(intval($episode['series_id'] ?? 0)) : [];
            $show    = trim(strval($series['title'] ?? ''));
            $season  = intval($episode['season'] ?? 0);
            $epnum   = intval($episode['episode'] ?? 0);
            $title   = trim(strval($episode['title'] ?? ''));
            if ($show == '' && !empty($skip['label'])) {
                if (preg_match('/^(.*?)\s+S\d+E\d+/i', strval($skip['label']), $match)) {
                    $show = trim($match[1]);
                }
            }
            $parts[] = 'Series=' . ($show != '' ? $show : ('#' . intval($episode['series_id'] ?? 0)));
            $parts[] = 'Episode=S' . str_pad(strval($season), 2, '0', STR_PAD_LEFT)
                . 'E' . str_pad(strval($epnum), 2, '0', STR_PAD_LEFT);
            if ($title != '') {
                $parts[] = 'Title=' . $title;
            }
        }
        $parts[] = 'ItemId=' . $itemId;
        $parts[] = 'Reason=' . $reason;

        return 'Skipped ' . implode(' | ', $parts);
    }

    public function dryRunWatchLabel($watch)
    {
        $watch = is_array($watch) ? $watch : [];
        if (intval($watch['finished'] ?? 0) > 0) {
            return 'watched';
        }
        $progress = intval($watch['inprogress'] ?? 0);
        if ($progress > 0) {
            $hours   = intval($progress / 3600);
            $minutes = intval(($progress % 3600) / 60);
            $seconds = $progress % 60;
            if ($hours > 0) {
                return 'in progress at ' . $hours . ':' . str_pad(strval($minutes), 2, '0', STR_PAD_LEFT) . ':' . str_pad(strval($seconds), 2, '0', STR_PAD_LEFT);
            }

            return 'in progress at ' . $minutes . ':' . str_pad(strval($seconds), 2, '0', STR_PAD_LEFT);
        }
        if (intval($watch['started'] ?? 0)) {
            return 'started';
        }

        return 'not watched';
    }

    public function logWatchSetAction($type, $itemId, $watch, $username = '', $fromApp = '', $toApp = '', $unchanged = false)
    {
        if (!$this->isDebugLog()) {
            return;
        }

        $watch    = is_array($watch) ? $watch : [];
        $finished = intval($watch['finished'] ?? 0);
        $progress = intval($watch['inprogress'] ?? 0);
        if ($finished <= 0 && $progress <= 0) {
            return;
        }

        $label = $this->dryRunItemLabel($type, $itemId);
        if ($unchanged) {
            $media = $label . ' no changes found';
        } else if ($finished > 0) {
            $media = $label . ' is being set to complete';
        } else {
            $media = $label . ' is being set to inprogress with a position time of ' . formatWatchDuration($progress);
        }
        $username = trim(strval($username));
        $fromApp  = trim(strval($fromApp));
        $toApp    = trim(strval($toApp));
        if ($username == '') {
            $username = '-';
        }
        if ($fromApp == '') {
            $fromApp = '-';
        }
        if ($toApp == '') {
            $toApp = '-';
        }
        logger($this->logfile, 'user: ' . $username . ' | from: ' . $fromApp . ' | to: ' . $toApp . ' | media: ' . $media);
        loggerFlush($this->logfile);
    }

    public function isDebugLog()
    {
        return strtolower(trim(strval($this->database->getSetting('logLevel') ?: 'info'))) == 'debug';
    }

    public function logDryRunSummary($plans, $mode, $skips = [])
    {
        $changeLines = [];
        $skipLines   = [];
        $labels      = [];

        foreach ($plans as $plan) {
            $app      = $plan['app']['name'] ?? '';
            $role     = $this->appRoleLabel($plan['app']);
            $user     = $plan['user']['username'] ?? '';
            $labelKey = ($plan['type'] ?? '') . ':' . intval($plan['item_id'] ?? 0);
            if (!isset($labels[$labelKey])) {
                $labels[$labelKey] = $this->dryRunItemLabel($plan['type'] ?? '', intval($plan['item_id'] ?? 0));
            }
            $itemLabel  = $labels[$labelKey];
            $watchLabel = $this->dryRunWatchLabel($plan['watch'] ?? []);

            if (!empty($plan['db_changed'])) {
                if (!empty($plan['cleared'])) {
                    if (!empty($plan['misplaced'])) {
                        $changeLines[] = 'Remove from Watchsync for ' . $user . ' on ' . $app . ' (' . $role . '): clear ' . $itemLabel . ' (episode path under wrong series)';
                    } else if (!empty($plan['unlinked'])) {
                        $changeLines[] = 'Remove from Watchsync for ' . $user . ' on ' . $app . ' (' . $role . '): clear ' . $itemLabel . ' (no longer linked on this app)';
                    } else {
                        $changeLines[] = 'Remove from Watchsync for ' . $user . ' on ' . $app . ' (' . $role . '): clear ' . $itemLabel . ' (absent from remote history)';
                    }
                } else {
                    $changeLines[] = 'Save to Watchsync for ' . $user . ' on ' . $app . ' (' . $role . '): set ' . $itemLabel . ' to ' . $watchLabel;
                }
            }

            if (!empty($plan['cascade_push'])) {
                $changeLines[] = 'Push clear to ' . $app . ' (' . $role . ') for ' . $user . ': clear ' . $itemLabel . ' (absent from main source)';
            } else if ($mode == MediaSyncModes::BOTH && !empty($plan['needs_push'])) {
                $changeLines[] = 'Push to ' . $app . ' (' . $role . ') for ' . $user . ': set ' . $itemLabel . ' to ' . $watchLabel;
            }
        }

        foreach ($skips as $skip) {
            $skipLines[] = $this->dryRunSkipLine($skip);
        }

        $lines   = [];
        $lines[] = '';
        $lines[] = str_repeat('=', 72);
        $lines[] = 'DRY RUN SUMMARY - what a live run would have done';
        $lines[] = str_repeat('=', 72);
        $lines[] = '';
        foreach ($this->syncSummarySettingLines() as $line) {
            $lines[] = $line;
        }
        $lines[]     = '';
        $repairLines = $this->historyLibraryRepairLines();
        if ($repairLines) {
            $lines[] = 'Watch/history changes were not written to media apps.';
            $lines[] = 'Library path/link repairs listed below were applied to Watchsync.';
        } else {
            $lines[] = 'No changes were written to Watchsync or any media app.';
        }
        $lines[] = '';
        $lines[] = 'CHANGES';
        if (!$changeLines) {
            $lines[] = '- No changes.';
        } else {
            foreach ($changeLines as $changeLine) {
                $lines[] = '- ' . $changeLine;
            }
        }
        if ($repairLines) {
            $lines[] = '';
            $lines[] = 'LIBRARY UPDATES (path check / listener link)';
            foreach ($repairLines as $repairLine) {
                $lines[] = '- ' . $repairLine;
            }
        }
        if ($skipLines) {
            $lines[] = '';
            $lines[] = 'SKIPPED (would not sync)';
            foreach ($skipLines as $skipLine) {
                $lines[] = '- ' . $skipLine;
            }
        }
        $tables  = $this->historySummaryTables($plans, $mode, $skips);
        $lines[] = '';
        $lines[] = 'WATCHSTATE (Watchsync database updates)';
        foreach (asciiTable($this->historyTableHeaders(true), $tables['dbRows']) as $line) {
            $lines[] = $line;
        }

        if ($mode == MediaSyncModes::BOTH || $tables['pushRows']) {
            $lines[] = '';
            $lines[] = 'APP PUSHES (writes to listeners for main-source absences' . ($mode == MediaSyncModes::BOTH ? ' / normal both-mode sync' : '') . ')';
            foreach (asciiTable($this->historyTableHeaders(false), $tables['pushRows']) as $line) {
                $lines[] = $line;
            }
        }

        $lines[] = str_repeat('=', 72);
        $lines[] = '';

        $totals                            = $this->historyTableTotals($tables['dbRows']);
        $this->sidecar['dry_run_summary']  = $lines;
        $this->sidecar['stats']['changed'] = intval($this->sidecar['stats']['changed'] ?? 0) + $totals['changed'];
        if ($mode == MediaSyncModes::BOTH) {
            $pushTotals                         = $this->historyTableTotals($tables['pushRows']);
            $this->sidecar['stats']['changed'] += $pushTotals['changed'];
        }
    }

    public function historyTableHeaders($withSkipped = false)
    {
        $headers = ['App', 'Role', 'User', 'Movies finished', 'Movies in progress', 'Episodes finished', 'Episodes in progress', 'Unchanged'];
        if ($withSkipped) {
            $headers[] = 'Skipped';
        }

        return $headers;
    }

    public function historyChangeColumn($type, $watch)
    {
        $finished = intval($watch['finished'] ?? 0) > 0;
        if (($type ?? '') == 'movie') {
            return $finished ? 3 : 4;
        }

        return $finished ? 5 : 6;
    }

    public function historySummaryRow($rows, $key, $app, $role, $user, $withSkipped = false)
    {
        if (empty($rows[$key])) {
            $rows[$key] = [$app, $role, $user, 0, 0, 0, 0, 0];
            if ($withSkipped) {
                $rows[$key][] = 0;
            }
        }

        return $rows;
    }

    public function historyTableTotals($rows)
    {
        $changed   = 0;
        $unchanged = 0;
        foreach ($rows as $row) {
            $changed   += intval($row[3] ?? 0) + intval($row[4] ?? 0) + intval($row[5] ?? 0) + intval($row[6] ?? 0);
            $unchanged += intval($row[7] ?? 0);
        }

        return [
            'changed'   => $changed,
            'unchanged' => $unchanged,
        ];
    }

    public function storeHistorySummary($tables, $mode)
    {
        $lines       = [];
        $repairLines = $this->historyLibraryRepairLines();
        if ($repairLines) {
            $lines[] = '';
            $lines[] = 'LIBRARY UPDATES (path check / listener link)';
            foreach ($repairLines as $repairLine) {
                $lines[] = '- ' . $repairLine;
            }
        }
        $lines[] = '';
        $lines[] = 'WATCHSTATE (Watchsync database updates)';
        foreach (asciiTable($this->historyTableHeaders(true), $tables['dbRows']) as $line) {
            $lines[] = $line;
        }

        if (intval($mode) == MediaSyncModes::BOTH || $tables['pushRows']) {
            $lines[] = '';
            $lines[] = 'APP PUSHES (listener clears for main-source absences'
                . (intval($mode) == MediaSyncModes::BOTH ? ' / normal both-mode sync' : '')
                . ')';
            foreach (asciiTable($this->historyTableHeaders(false), $tables['pushRows']) as $line) {
                $lines[] = $line;
            }
        }

        $this->appendSyncSummary($lines);
    }

    public function historySummaryTables($plans, $mode, $skips = [])
    {
        $dbRows   = [];
        $pushRows = [];
        $both     = intval($mode) == MediaSyncModes::BOTH;

        foreach ($plans as $plan) {
            $app    = $plan['app']['name'] ?? '';
            $role   = $this->appRoleLabel($plan['app']);
            $user   = $plan['user']['username'] ?? '';
            $key    = $app . "\0" . $role . "\0" . $user;
            $column = $this->historyChangeColumn($plan['type'] ?? '', $plan['watch'] ?? []);

            if (!empty($plan['cascade_push'])) {
                $pushRows = $this->historySummaryRow($pushRows, $key, $app, $role, $user, false);
                $pushRows[$key][$column]++;
                continue;
            }

            $dbRows = $this->historySummaryRow($dbRows, $key, $app, $role, $user, true);
            if (!empty($plan['db_changed'])) {
                $dbRows[$key][$column]++;
            } else {
                $dbRows[$key][7]++;
            }

            if ($both) {
                $pushRows = $this->historySummaryRow($pushRows, $key, $app, $role, $user, false);
                if (!empty($plan['needs_push'])) {
                    $pushRows[$key][$column]++;
                } else {
                    $pushRows[$key][7]++;
                }
            }
        }

        foreach ($skips as $skip) {
            $app    = $skip['app']['name'] ?? '';
            $role   = $this->appRoleLabel($skip['app'] ?? []);
            $user   = $skip['user']['username'] ?? '';
            $key    = $app . "\0" . $role . "\0" . $user;
            $dbRows = $this->historySummaryRow($dbRows, $key, $app, $role, $user, true);
            $dbRows[$key][8]++;
        }

        return [
            'dbRows'   => array_values($dbRows),
            'pushRows' => array_values($pushRows),
        ];
    }

    public function appendSyncSummary($lines)
    {
        if (!isset($this->sidecar['sync_summary']) || !is_array($this->sidecar['sync_summary'])) {
            $this->sidecar['sync_summary'] = [];
        }
        foreach ($lines as $line) {
            $this->sidecar['sync_summary'][] = $line;
        }
    }

    public function storePushSummary($byApp)
    {
        if (!empty($this->sidecar['sync_summary'])) {
            return;
        }

        $rows = [];
        foreach ($byApp as $app) {
            foreach ($app['users'] as $username => $counts) {
                $row = [
                    $app['name'],
                    $app['role'],
                    $username,
                    intval($counts['movies_finished'] ?? 0),
                    intval($counts['movies_progress'] ?? 0),
                    intval($counts['episodes_finished'] ?? 0),
                    intval($counts['episodes_progress'] ?? 0),
                    intval($counts['unchanged'] ?? 0),
                ];
                if (!$row[3] && !$row[4] && !$row[5] && !$row[6] && !$row[7]) {
                    continue;
                }
                $rows[] = $row;
            }
        }

        $lines   = [];
        $lines[] = '';
        $lines[] = 'APP PUSHES (writes to main source / listeners)';
        foreach (asciiTable($this->historyTableHeaders(false), $rows) as $line) {
            $lines[] = $line;
        }
        $this->appendSyncSummary($lines);
    }

    public function logDryRunPushSummary($byApp)
    {
        $rows = [];
        foreach ($byApp as $app) {
            foreach ($app['users'] as $username => $counts) {
                $row = [
                    $app['name'],
                    $app['role'],
                    $username,
                    intval($counts['movies_finished'] ?? 0),
                    intval($counts['movies_progress'] ?? 0),
                    intval($counts['episodes_finished'] ?? 0),
                    intval($counts['episodes_progress'] ?? 0),
                    intval($counts['unchanged'] ?? 0),
                ];
                if (!$row[3] && !$row[4] && !$row[5] && !$row[6] && !$row[7]) {
                    continue;
                }
                $rows[] = $row;
            }
        }

        $lines   = [];
        $lines[] = '';
        $lines[] = str_repeat('=', 72);
        $lines[] = 'DRY RUN SUMMARY - what a live run would have done';
        $lines[] = str_repeat('=', 72);
        $lines[] = '';
        foreach ($this->syncSummarySettingLines() as $line) {
            $lines[] = $line;
        }
        $lines[] = '';
        $lines[] = 'No changes were written to any media app.';
        $lines[] = '';
        $lines[] = 'APP PUSHES (writes to main source / listeners)';
        foreach (asciiTable($this->historyTableHeaders(false), $rows) as $line) {
            $lines[] = $line;
        }
        $lines[] = str_repeat('=', 72);
        $lines[] = '';

        $totals                            = $this->historyTableTotals($rows);
        $this->sidecar['dry_run_summary']  = $lines;
        $this->sidecar['stats']['changed'] = intval($this->sidecar['stats']['changed'] ?? 0) + $totals['changed'];
    }

    public function pushWatchChanges($changes)
    {
        global $mediaApps;

        $pushed = 0;
        $byUser = [];
        foreach ($changes as $change) {
            $this->stopIfCancelled();
            if ($this->historyUserMissingPin($change['user'] ?? [])) {
                $this->logHistoryMissingPin($change['app'] ?? [], $change['user'] ?? []);
                continue;
            }
            $this->logWatchSetAction($change['type'] ?? '', intval($change['item_id'] ?? 0), [
                'started'    => $change['started'] ?? 0,
                'inprogress' => $change['inprogress'] ?? 0,
                'finished'   => $change['finished'] ?? 0,
            ], $change['user']['username'] ?? '', APP_NAME, $change['app']['name'] ?? '');
            $mediaApps->setWatchStatus(
                $change['app'],
                $change['user'],
                $change['remote_id'],
                $change['started'],
                $change['inprogress'],
                $change['finished'],
            );
            $pushed++;
            $this->addStat('pushed', 1, $change['app']);
            $key = intval($change['app']['id']) . ':' . intval($change['user']['id']);
            if (empty($byUser[$key])) {
                $byUser[$key] = [
                    'app'   => $change['app']['name'],
                    'role'  => $this->appRoleLabel($change['app']),
                    'user'  => $change['user']['username'],
                    'count' => 0,
                ];
            }
            $byUser[$key]['count']++;
        }

        foreach ($byUser as $row) {
            logger($this->logfile, 'push ' . $row['app'] . ' (' . $row['role'] . ') ' . $row['user'] . ' items=' . $row['count']);
        }
        logger($this->logfile, 'watch pushed=' . $pushed);
    }

    public function pullWatch()
    {
        $this->syncWatch(MediaSyncModes::PULL);
    }

    public function syncWebhookItem()
    {
        global $database;

        $payload = $this->sidecar['webhook_item'] ?? [];
        $type    = strval($payload['type'] ?? '');
        $itemId  = intval($payload['item_id'] ?? 0);
        $userId  = intval($payload['user_id'] ?? 0);
        $appId   = intval($payload['media_app_id'] ?? ($this->sidecar['media_app_id'] ?? 0));
        $state   = [
            'started'    => intval($payload['started'] ?? 0),
            'inprogress' => intval($payload['inprogress'] ?? 0),
            'finished'   => intval($payload['finished'] ?? 0),
        ];
        $app     = $appId ? $database->getMediaApp($appId) : [];
        $user    = $userId ? $database->getMediaAppUser($userId) : [];
        $item    = [];
        if ($type == 'movie' && $itemId) {
            $item = $database->getMovie($itemId);
        } else if ($type == 'episode' && $itemId) {
            $item = $database->getEpisode($itemId);
        }
        if (!$app || !$user || !$item) {
            logger($this->logfile, 'webhook item missing');
            return;
        }

        logger($this->logfile, 'webhook ' . strval($this->sidecar['webhook_event'] ?? '') . ' ' . strval($user['username'] ?? ''));
        $pushed = webhookPushWatch($app, $user, $item, $type, $state);
        $this->addStat('pushed', $pushed);
        logger($this->logfile, 'watch pushed=' . intval($pushed));
    }

    public function pushWatch($apps = null)
    {
        global $mediaApps;

        $dry   = $this->isDryRun();
        $debug = $this->isDebugLog();
        $byApp = [];
        if ($apps == null) {
            $apps = $this->watchApps();
        }
        $index = $this->watchItemIndex($apps);

        foreach ($apps as $mediaApp) {
            $this->stopIfCancelled();
            $appKey = intval($mediaApp['id']);
            if (empty($byApp[$appKey])) {
                $byApp[$appKey] = [
                    'name'  => $mediaApp['name'],
                    'role'  => $this->appRoleLabel($mediaApp),
                    'users' => [],
                ];
            }

            foreach ($this->selectedUsers($mediaApp['id']) as $user) {
                $this->stopIfCancelled();
                if ($this->historyUserMissingPin($user)) {
                    $this->logHistoryMissingPin($mediaApp, $user);
                    continue;
                }
                logger($this->logfile, ($dry ? 'would push ' : 'push ') . $mediaApp['name'] . ' ' . $user['username']);
                loggerFlush($this->logfile);
                $state = $this->database->getUserWatchState($user['id'], $mediaApp['platform']);
                if (!empty($state['user'])) {
                    $user = array_merge($user, $state['user']);
                }
                $status       = $mediaApps->getWatchStatus($mediaApp, $user['remote_id'], $user['username'], $user['id'], $user);
                $platform     = intval($mediaApp['platform']);
                $moviesFin    = 0;
                $moviesProg   = 0;
                $episodesFin  = 0;
                $episodesProg = 0;
                $unchangedM   = 0;
                $unchangedE   = 0;
                $libraries    = [];
                foreach (['movie' => 'movies', 'episode' => 'episodes'] as $type => $bucket) {
                    foreach ($state[$type] as $itemId => $link) {
                        $this->stopIfCancelled();
                        $item   = $index[$type]['id'][intval($itemId)] ?? [];
                        $remote = $item['remote'][$platform] ?? '';
                        if (!$item || empty($item['flag'][$platform]) || $remote == '') {
                            continue;
                        }
                        $library = $this->watchLibraryTitle($index['libraries'][$appKey] ?? [], $item['path']);
                        if ($library == '') {
                            $library = strval($mediaApp['name'] ?? '');
                        }
                        if (empty($libraries[$library])) {
                            $libraries[$library] = [
                                'movies_finished'   => 0,
                                'movies_progress'   => 0,
                                'episodes_finished' => 0,
                                'episodes_progress' => 0,
                                'unchanged'         => 0,
                            ];
                        }
                        if (watchStateSatisfies($status[$bucket][$remote] ?? [], $link)) {
                            $libraries[$library]['unchanged']++;
                            if ($type == 'movie') {
                                $unchangedM++;
                            } else {
                                $unchangedE++;
                            }
                            continue;
                        }
                        if (!$dry) {
                            $this->logWatchSetAction($type, intval($itemId), $link, $user['username'] ?? '', APP_NAME, $mediaApp['name'] ?? '');
                            $mediaApps->setWatchStatus($mediaApp, $user, $remote, $link['started'], $link['inprogress'], $link['finished']);
                            $statusName = $this->watchResultStatus($link);
                            if ($statusName == '' || $statusName == 'started') {
                                $statusName = 'inProgress';
                            }
                            $this->addHistoryResult($mediaApp, $user['username'] ?? '', $type, $statusName);
                        }
                        $finishedKey = $type == 'movie' ? 'movies_finished' : 'episodes_finished';
                        $progressKey = $type == 'movie' ? 'movies_progress' : 'episodes_progress';
                        if (intval($link['finished'] ?? 0) > 0) {
                            $libraries[$library][$finishedKey]++;
                            if ($type == 'movie') {
                                $moviesFin++;
                            } else {
                                $episodesFin++;
                            }
                        } else {
                            $libraries[$library][$progressKey]++;
                            if ($type == 'movie') {
                                $moviesProg++;
                            } else {
                                $episodesProg++;
                            }
                        }
                    }
                }
                if (!$dry && ($unchangedM || $unchangedE)) {
                    if ($unchangedM) {
                        $this->addHistoryResult($mediaApp, $user['username'] ?? '', 'movie', 'unchanged', $unchangedM);
                    }
                    if ($unchangedE) {
                        $this->addHistoryResult($mediaApp, $user['username'] ?? '', 'episode', 'unchanged', $unchangedE);
                    }
                    $this->addStat('unchanged', $unchangedM + $unchangedE, $mediaApp);
                }
                $pushed = $moviesFin + $moviesProg + $episodesFin + $episodesProg;
                $this->addStat('pushed', $pushed, $mediaApp);
                $byApp[$appKey]['users'][$user['username']] = [
                    'movies_finished'   => $moviesFin,
                    'movies_progress'   => $moviesProg,
                    'episodes_finished' => $episodesFin,
                    'episodes_progress' => $episodesProg,
                    'unchanged'         => $unchangedM + $unchangedE,
                ];
                if (!$debug) {
                    foreach ($libraries as $library => $counts) {
                        logger($this->logfile, ($dry ? 'would push ' : 'push ') . $mediaApp['name'] . ' / ' . $library . ' / ' . $user['username']
                            . ' movies finished=' . $counts['movies_finished']
                            . ' in progress=' . $counts['movies_progress']
                            . ' episodes finished=' . $counts['episodes_finished']
                            . ' in progress=' . $counts['episodes_progress']
                            . ' unchanged=' . $counts['unchanged']);
                        loggerFlush($this->logfile);
                    }
                }
            }
        }

        if ($dry) {
            $this->logDryRunPushSummary($byApp);
        } else {
            $this->storePushSummary($byApp);
        }
    }
}
