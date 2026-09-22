<?php

/*
----------------------------------
------  Created: 091126   ------
------  Austin Best       ------
----------------------------------
*/

trait Library
{
    public function platformFlags($platform, $remoteId)
    {
        $flags = [
            'plex'               => 0,
            'emby'               => 0,
            'jellyfin'           => 0,
            'plex_remote_id'     => '',
            'emby_remote_id'     => '',
            'jellyfin_remote_id' => '',
        ];

        switch (intval($platform)) {
            case MediaPlatforms::EMBY:
                $flags['emby']           = 1;
                $flags['emby_remote_id'] = $remoteId;
                break;
            case MediaPlatforms::JELLYFIN:
                $flags['jellyfin']           = 1;
                $flags['jellyfin_remote_id'] = $remoteId;
                break;
            default:
                $flags['plex']           = 1;
                $flags['plex_remote_id'] = $remoteId;
                break;
        }

        return $flags;
    }

    public function targetApps()
    {
        $mediaAppId = intval($this->currentJob['media_app_id'] ?? 0);
        if ($mediaAppId) {
            $mediaApp = $this->database->getMediaApp($mediaAppId);
            if ($mediaApp) {
                return [$mediaApp];
            }

            return [];
        }

        $apps      = [];
        $master    = [];
        $listeners = [];

        foreach ($this->database->getMediaApps() as $mediaApp) {
            if (!$mediaApp['active']) {
                continue;
            }
            if ($mediaApp['role'] == MediaAppRoles::MASTER) {
                $master = $mediaApp;
            } else {
                $listeners[] = $mediaApp;
            }
        }

        if ($master) {
            $apps[] = $master;
        }

        $libraryAppIds = $this->libraryAppIds();
        foreach ($listeners as $listener) {
            if ($libraryAppIds && !in_array(intval($listener['id']), $libraryAppIds)) {
                continue;
            }
            if (!$mediaAppId || $listener['id'] == $mediaAppId) {
                $apps[] = $listener;
            }
        }

        if ($libraryAppIds && $master && !in_array(intval($master['id']), $libraryAppIds)) {
            $filtered = [];
            foreach ($apps as $mediaApp) {
                if ($mediaApp['role'] == MediaAppRoles::MASTER) {
                    continue;
                }
                $filtered[] = $mediaApp;
            }
            $apps = $filtered;
        }

        return $apps;
    }

    public function libraryAppIds()
    {
        $ids = [];
        foreach ($this->currentJob['libraries'] ?? [] as $library) {
            if (!empty($library['media_app_id'])) {
                $ids[] = intval($library['media_app_id']);
            }
        }

        return array_values(array_unique($ids));
    }

    public function libraryKeys($mediaAppId)
    {
        $keys = [];
        foreach ($this->currentJob['libraries'] ?? [] as $library) {
            if (intval($library['media_app_id'] ?? 0) == intval($mediaAppId) && ($library['key'] ?? '') != '') {
                $keys[] = $library['key'];
            }
        }

        return $keys;
    }

    public function fetchItems($mediaApp)
    {
        global $mediaApps;

        return $mediaApps->getItems($mediaApp, $this->libraryKeys($mediaApp['id']));
    }

    public function selectedLibraries($mediaApp)
    {
        global $mediaApps;

        $keys = $this->libraryKeys($mediaApp['id']);
        if (!$keys && !empty($this->currentJob['libraries'])) {
            return [];
        }
        if (!$keys) {
            foreach ($mediaApps->selectedScanLibraries() as $library) {
                if (intval($library['media_app_id'] ?? 0) == intval($mediaApp['id']) && ($library['key'] ?? '') != '') {
                    $keys[] = $library['key'];
                }
            }
        }
        if (!$keys) {
            return [];
        }

        $libraries = [];
        foreach ($mediaApps->getLibraries($mediaApp) as $library) {
            if (!$library['key'] || !in_array($library['key'], $keys)) {
                continue;
            }
            $libraries[] = $library;
        }

        return $libraries;
    }

    public function pullMediaLibrary()
    {
        global $mediaApps;

        $apps = $this->targetApps();
        if (!$apps) {
            logger($this->logfile, 'no active media apps');
            return;
        }

        $scan = intval($this->currentJob['scan'] ?? MediaLibraryScans::LAST_SCAN);

        $knownBefore = [];
        foreach ($apps as $mediaApp) {
            $this->stopIfCancelled();
            if (!$this->mediaAppOnline($mediaApp)) {
                continue;
            }
            foreach ($mediaApps->getLibraries($mediaApp, [], false) as $library) {
                $id = intval($mediaApp['id']) . ':' . strval($library['key'] ?? '');
                if ($id != '0:') {
                    $knownBefore[$id] = true;
                }
            }
            $mediaApps->refreshStoredLibraries($mediaApp);
        }

        if ($this->isAutomaticJob() && $this->database->settingEnabled('syncLibraryAutoMeta')) {
            $state = $mediaApps->scanLibraryState();
            if ($state) {
                $changed      = false;
                $jobLibraries = is_array($this->currentJob['libraries'] ?? null) ? $this->currentJob['libraries'] : [];
                $have         = [];
                foreach ($jobLibraries as $library) {
                    $id = intval($library['media_app_id'] ?? 0) . ':' . strval($library['key'] ?? '');
                    if ($id != '0:') {
                        $have[$id] = true;
                    }
                }
                foreach ($apps as $mediaApp) {
                    if (!$mediaApp['active']) {
                        continue;
                    }
                    foreach ($mediaApps->getLibraries($mediaApp, [], false) as $library) {
                        $key = strval($library['key'] ?? '');
                        if ($key == '') {
                            continue;
                        }
                        $id = intval($mediaApp['id']) . ':' . $key;
                        if (!empty($knownBefore[$id]) || array_key_exists($id, $state)) {
                            continue;
                        }
                        if (!$mediaApps->libraryAllowedByParity(intval($mediaApp['id']), $key)) {
                            continue;
                        }
                        $state[$id] = 1;
                        $changed    = true;
                        if (empty($have[$id])) {
                            $jobLibraries[] = ['media_app_id' => intval($mediaApp['id']), 'key' => $key];
                            $have[$id]      = true;
                        }
                    }
                }
                if ($changed) {
                    $mediaApps->setScanLibraries($state);
                }
                $this->currentJob['libraries'] = $jobLibraries;
            }
        }

        foreach ($apps as $mediaApp) {
            $this->stopIfCancelled();
            if (!$this->mediaAppOnline($mediaApp)) {
                continue;
            }
            $libraries = $this->selectedLibraries($mediaApp);
            logger($this->logfile, 'media library ' . $mediaApp['name'] . ' libraries ' . count($libraries));
            $platformName = $mediaApps->getPlatformName($mediaApp['platform']);
            $added        = ['movies' => 0, 'series' => 0, 'episodes' => 0];
            $updated      = ['movies' => 0, 'series' => 0, 'episodes' => 0];
            $unchanged    = ['movies' => 0, 'series' => 0, 'episodes' => 0];
            $totals       = ['movies' => 0, 'series' => 0, 'episodes' => 0];
            $this->warmLibraryImportIndex($mediaApp['platform']);

            $since     = $scan == MediaLibraryScans::FULL ? 0 : intval($mediaApp['last_scan'] ?? 0);
            $scannedAt = time();
            $seenIds   = [];
            logger($this->logfile, 'media library ' . $mediaApp['name'] . ' ' . ($since ? 'since last scan' : 'full library scan'));
            foreach ($libraries as $library) {
                $this->stopIfCancelled();
                $label = 'media library ' . $mediaApp['name'] . ' ' . ($library['title'] ?? $library['key']);
                if ($since) {
                    logger($this->logfile, $label . ' changes since ' . date('Y-m-d H:i:s', $since));
                } else {
                    logger($this->logfile, $label);
                }
                $items               = $mediaApps->getItems($mediaApp, [$library], '', $since);
                $totals['movies']   += count($items['movies'] ?? []);
                $totals['series']   += count($items['series'] ?? []);
                $totals['episodes'] += count($items['episodes'] ?? []);
                if (!$since) {
                    foreach (['movies', 'series', 'episodes'] as $type) {
                        foreach ($items[$type] ?? [] as $item) {
                            $remoteId = trim(strval($item['remote_id'] ?? ''));
                            if ($remoteId != '') {
                                $seenIds[$remoteId] = true;
                            }
                        }
                    }
                }
                $addedBefore = $added['movies'] + $added['series'] + $added['episodes'];
                $this->importLibraryItems($mediaApp, $platformName, [
                    'movies'   => $items['movies'] ?? [],
                    'series'   => $items['series'] ?? [],
                    'episodes' => $items['episodes'] ?? [],
                ], $added, $updated, $unchanged);
                if (($added['movies'] + $added['series'] + $added['episodes']) > $addedBefore && ($library['key'] ?? '') != '') {
                    $this->currentJob['history_libraries'][intval($mediaApp['id']) . ':' . $library['key']] = [
                        'media_app_id' => intval($mediaApp['id']),
                        'key'          => strval($library['key']),
                    ];
                }
            }

            if (!$since && $seenIds && $this->scannedAllAppLibraries($mediaApp, $libraries)) {
                $cleared = $this->database->clearUnseenMediaLibraryRemotes($mediaApp['platform'], array_keys($seenIds));
                if ($cleared) {
                    logger($this->logfile, 'media library ' . $mediaApp['name'] . ' cleared stale remote ids ' . $cleared);
                }
            }

            $this->database->setMediaAppLastScan($mediaApp['id'], $scannedAt);
            $this->currentJob['stats']['added']     = intval($this->currentJob['stats']['added'] ?? 0) + $added['movies'] + $added['series'] + $added['episodes'];
            $this->currentJob['stats']['updated']   = intval($this->currentJob['stats']['updated'] ?? 0) + $updated['movies'] + $updated['series'] + $updated['episodes'];
            $this->currentJob['stats']['unchanged'] = intval($this->currentJob['stats']['unchanged'] ?? 0) + $unchanged['movies'] + $unchanged['series'] + $unchanged['episodes'];
            foreach (['movies', 'series', 'episodes'] as $type) {
                $this->addLibraryResult($mediaApp, $type, 'added', $added[$type]);
                $this->addLibraryResult($mediaApp, $type, 'updated', $updated[$type]);
                $this->addLibraryResult($mediaApp, $type, 'unchanged', $unchanged[$type]);
                $this->currentJob['stats']['library'][$type] = intval($this->currentJob['stats']['library'][$type] ?? 0) + $totals[$type];
            }
            logger($this->logfile, 'media library ' . $mediaApp['name'] . ' movies: added ' . $added['movies'] . ', updated: ' . $updated['movies'] . ', unchanged: ' . $unchanged['movies'] . ', total: ' . $totals['movies'] . ' | series: added ' . $added['series'] . ', updated: ' . $updated['series'] . ', unchanged: ' . $unchanged['series'] . ', total: ' . $totals['series'] . ' | episodes: added ' . $added['episodes'] . ', updated: ' . $updated['episodes'] . ', unchanged: ' . $unchanged['episodes'] . ', total: ' . $totals['episodes']);
            $this->libraryImportIndex = null;
        }

        $deduped = $this->database->dedupeLibrary();
        if (($deduped['movies'] + $deduped['series'] + intval($deduped['episodes'] ?? 0)) > 0) {
            logger($this->logfile, 'media library dedupeLibrary movies=' . $deduped['movies'] . ' series=' . $deduped['series'] . ' episodes=' . intval($deduped['episodes'] ?? 0));
        }
        $rehomed = $this->database->rehomeMisplacedEpisodes();
        if (intval($rehomed['moved'] ?? 0) > 0) {
            logger($this->logfile, 'media library rehomeMisplacedEpisodes checked=' . intval($rehomed['checked'] ?? 0) . ' moved=' . intval($rehomed['moved'] ?? 0));
        }
        $purged = $mediaApps->purgeMedia();
        if (($purged['movies'] + $purged['series'] + $purged['episodes']) > 0) {
            logger($this->logfile, 'media library purgeMedia movies=' . $purged['movies'] . ' series=' . $purged['series'] . ' episodes=' . $purged['episodes']);
        }
    }

    public function scannedAllAppLibraries($mediaApp, $libraries)
    {
        global $mediaApps;

        $selected = [];
        foreach ($libraries as $library) {
            $key = strval($library['key'] ?? '');
            if ($key != '') {
                $selected[$key] = true;
            }
        }
        if (!$selected) {
            return false;
        }

        foreach ($mediaApps->getLibraries($mediaApp) as $library) {
            $key = strval($library['key'] ?? '');
            if ($key != '' && empty($selected[$key])) {
                return false;
            }
        }

        return true;
    }

    public function libraryImportBucket($table)
    {
        if ($table == MOVIE_TABLE) {
            return 'movies';
        }
        if ($table == SERIES_TABLE) {
            return 'series';
        }

        return 'episodes';
    }

    public function warmLibraryImportIndex($platform)
    {
        $platform = intval($platform);
        if ($this->libraryImportIndex && intval($this->libraryImportIndex['platform'] ?? 0) == $platform) {
            return;
        }

        $field = $this->database->mediaLibraryRemoteField($platform);
        $index = [
            'platform' => $platform,
            'remote'   => ['movies' => [], 'series' => [], 'episodes' => []],
            'path'     => ['movies' => [], 'series' => [], 'episodes' => []],
            'slash'    => ['movies' => [], 'series' => [], 'episodes' => []],
            'identity' => ['movies' => [], 'series' => [], 'episodes' => []],
            'code'     => ['episodes' => []],
        ];

        $tables = [
            'movies'   => $this->database->getMovies(),
            'series'   => $this->database->getSeriesRows(),
            'episodes' => $this->database->getEpisodes(),
        ];
        foreach ($tables as $bucket => $rows) {
            foreach ($rows as $row) {
                $this->indexLibraryImportRow($index, $bucket, $row, $field);
            }
        }

        $this->libraryImportIndex = $index;
        logger($this->logfile, 'media library import index movies=' . count($index['path']['movies']) . ' series=' . count($index['path']['series']) . ' episodes=' . count($index['path']['episodes']));
    }

    public function libraryImportIdentityKey($bucket, $row)
    {
        if ($bucket == 'movies' || $bucket == 'series') {
            $title = trim(strval($row['title'] ?? ''));
            if ($title == '') {
                return '';
            }

            return strtolower($title) . ':' . intval($row['year'] ?? 0);
        }
        if ($bucket == 'episodes') {
            $title    = trim(strval($row['title'] ?? ''));
            $seriesId = intval($row['series_id'] ?? 0);
            if ($title == '' || $seriesId <= 0) {
                return '';
            }

            return $seriesId . ':' . intval($row['season'] ?? 0) . ':' . intval($row['episode'] ?? 0) . ':' . strtolower($title);
        }

        return '';
    }

    public function indexLibraryImportRow(&$index, $bucket, $row, $field = '')
    {
        if (!$row || !is_array($row)) {
            return;
        }
        if ($field == '' && !empty($index['platform'])) {
            $field = $this->database->mediaLibraryRemoteField($index['platform']);
        }
        if ($field) {
            $remoteId = trim(strval($row[$field] ?? ''));
            if ($remoteId != '') {
                $index['remote'][$bucket][$remoteId] = $row;
            }
        }
        $path = $this->database->normalizeLibraryPath($row['path'] ?? '');
        if ($path != '') {
            $index['path'][$bucket][strtolower($path)] = $row;
            $slash                                     = $this->database->pathSlashlessKey($path);
            if ($slash != '') {
                $index['slash'][$bucket][$slash] = $row;
            }
        }
        if ($bucket == 'movies' || $bucket == 'series' || $bucket == 'episodes') {
            $identity = $this->libraryImportIdentityKey($bucket, $row);
            if ($identity != '') {
                $index['identity'][$bucket][$identity] = $row;
            }
        }
        if ($bucket == 'episodes') {
            $seriesId = intval($row['series_id'] ?? 0);
            if ($seriesId > 0) {
                $code     = $seriesId . ':' . intval($row['season'] ?? 0) . ':' . intval($row['episode'] ?? 0);
                $existing = $index['code']['episodes'][$code] ?? [];
                if (!$existing || $this->database->pathLooksMangled($existing['path'] ?? '')) {
                    $index['code']['episodes'][$code] = $row;
                } else if (!$this->database->pathLooksMangled($row['path'] ?? '')) {
                    $index['code']['episodes'][$code] = $row;
                }
            }
        }
    }

    public function rememberLibraryImportItem($table, $row)
    {
        if (!$this->libraryImportIndex || !$row) {
            return;
        }
        $this->indexLibraryImportRow($this->libraryImportIndex, $this->libraryImportBucket($table), $row);
    }

    public function libraryImportRemoteField($platform)
    {
        return $this->database->mediaLibraryRemoteField($platform);
    }

    public function libraryImportRowMatchesRemote($row, $platform, $remoteId)
    {
        $remoteId = trim(strval($remoteId));
        if ($remoteId == '' || !$row) {
            return true;
        }

        $field = $this->libraryImportRemoteField($platform);
        if ($field == '') {
            return true;
        }

        $rowRemote = trim(strval($row[$field] ?? ''));
        if ($rowRemote == '' || $rowRemote == $remoteId) {
            return true;
        }

        return false;
    }

    public function findLibraryImportPathHit($table, $platform, $path)
    {
        $this->warmLibraryImportIndex($platform);
        $bucket = $this->libraryImportBucket($table);
        $path   = $this->database->normalizeLibraryPath($path);
        if ($path == '') {
            return [];
        }

        $row = $this->libraryImportIndex['path'][$bucket][strtolower($path)] ?? [];
        if ($row) {
            return $row;
        }
        $slash = $this->database->pathSlashlessKey($path);
        if ($slash != '') {
            return $this->libraryImportIndex['slash'][$bucket][$slash] ?? [];
        }

        return [];
    }

    public function findLibraryImportItem($table, $platform, $remoteId, $path)
    {
        $this->warmLibraryImportIndex($platform);
        $bucket   = $this->libraryImportBucket($table);
        $remoteId = trim(strval($remoteId));
        if ($remoteId != '') {
            $row = $this->libraryImportIndex['remote'][$bucket][$remoteId] ?? [];
            if ($row) {
                return $row;
            }
        }

        $row = $this->findLibraryImportPathHit($table, $platform, $path);
        if ($row && $this->libraryImportRowMatchesRemote($row, $platform, $remoteId)) {
            return $row;
        }

        return [];
    }

    public function findLibraryImportIdentityHit($table, $platform, $remoteId, $identityRow)
    {
        $this->warmLibraryImportIndex($platform);
        $bucket = $this->libraryImportBucket($table);
        if ($bucket != 'movies' && $bucket != 'series' && $bucket != 'episodes') {
            return [];
        }
        $identity = $this->libraryImportIdentityKey($bucket, $identityRow);
        if ($identity == '') {
            return [];
        }

        return $this->libraryImportIndex['identity'][$bucket][$identity] ?? [];
    }

    public function findLibraryImportEpisodeCodeHit($platform, $seriesId, $season, $episode)
    {
        $this->warmLibraryImportIndex($platform);
        $seriesId = intval($seriesId);
        if ($seriesId <= 0) {
            return [];
        }
        $code = $seriesId . ':' . intval($season) . ':' . intval($episode);

        return $this->libraryImportIndex['code']['episodes'][$code] ?? [];
    }

    public function findLibraryImportEpisodePathHit($platform, $remoteId, $path, $season, $episode)
    {
        $pathHit = $this->findLibraryImportPathHit(EPISODE_TABLE, $platform, $path);
        if (!$pathHit) {
            return [];
        }
        if (intval($pathHit['season'] ?? -1) != intval($season) || intval($pathHit['episode'] ?? -1) != intval($episode)) {
            return [];
        }

        return $pathHit;
    }

    public function findLibraryImportSeriesRow($mediaApp, $item, $episodePath)
    {
        $platform   = $mediaApp['platform'];
        $seriesPath = $this->database->normalizePath($item['path'] ?? $episodePath, 'series');
        $remoteId   = $item['series_remote_id'] ?? '';
        $row        = $this->findLibraryImportItem(SERIES_TABLE, $platform, $remoteId, $seriesPath);
        if ($row) {
            return $row;
        }

        return $this->findLibraryImportIdentityHit(SERIES_TABLE, $platform, $remoteId, [
            'title' => strval($item['series'] ?? ''),
            'year'  => intval($item['series_year'] ?? ($item['year'] ?? 0)),
        ]);
    }

    public function importLibraryItems($mediaApp, $platformName, $items, &$added, &$updated, &$unchanged)
    {
        $movieTotal   = count($items['movies'] ?? []);
        $seriesTotal  = count($items['series'] ?? []);
        $episodeTotal = count($items['episodes'] ?? []);
        $movieIndex   = 0;
        $seriesIndex  = 0;
        $episodeIndex = 0;

        foreach ($items['series'] ?? [] as $item) {
            $this->stopIfCancelled();
            $seriesIndex++;
            $success = false;
            $action  = 'updating';
            $error   = '';
            if ($item['path'] || $item['remote_id']) {
                try {
                    $this->database->setLastError('');
                    $action  = $this->upsertLibraryItem($mediaApp, SERIES_TABLE, 'series', $item, $added, $updated, $unchanged);
                    $success = $action != '';
                    if (!$success) {
                        $error = $this->database->getLastError() ?: 'series upsert failed';
                    }
                } catch (Exception $e) {
                    if ($e->getMessage() == 'cancelled') {
                        throw $e;
                    }
                    $error = $e->getMessage();
                }
            } else {
                $error = 'missing path and remote id';
            }
            $this->logLibraryItem('series', $seriesIndex, $seriesTotal, $platformName, $item, $success, $action, $error);
        }

        foreach ($items['movies'] ?? [] as $item) {
            $this->stopIfCancelled();
            $movieIndex++;
            $success = false;
            $action  = 'updating';
            $error   = '';
            if ($item['path'] || $item['remote_id']) {
                try {
                    $this->database->setLastError('');
                    $action  = $this->upsertLibraryItem($mediaApp, MOVIE_TABLE, 'movie', $item, $added, $updated, $unchanged);
                    $success = $action != '';
                    if (!$success) {
                        $error = $this->database->getLastError() ?: 'movie upsert failed';
                    }
                } catch (Exception $e) {
                    if ($e->getMessage() == 'cancelled') {
                        throw $e;
                    }
                    $error = $e->getMessage();
                }
            } else {
                $error = 'missing path and remote id';
            }
            $this->logLibraryItem('movie', $movieIndex, $movieTotal, $platformName, $item, $success, $action, $error);
        }

        foreach ($items['episodes'] ?? [] as $item) {
            $this->stopIfCancelled();
            $episodeIndex++;
            $success = false;
            $action  = 'updating';
            $error   = '';
            if ($item['path'] || $item['remote_id']) {
                try {
                    $this->database->setLastError('');
                    $action  = $this->saveLibraryEpisode($mediaApp, $item, $added, $updated, $unchanged);
                    $success = $action != '';
                    if (!$success) {
                        $error = $this->database->getLastError() ?: 'episode upsert failed';
                    }
                } catch (Exception $e) {
                    if ($e->getMessage() == 'cancelled') {
                        throw $e;
                    }
                    $error = $e->getMessage();
                }
            } else {
                $error = 'missing path and remote id';
            }
            $this->logLibraryItem('episode', $episodeIndex, $episodeTotal, $platformName, $item, $success, $action, $error);
        }
    }

    public function applyLibraryImportRowUpdate($table, $platform, $remoteId, $path, $row, $meta = [])
    {
        if (!$row || !intval($row['id'] ?? 0)) {
            return [];
        }

        $flag  = $this->database->mediaLibraryFlag($platform);
        $field = $this->database->mediaLibraryRemoteField($platform);
        $id    = intval($row['id']);
        $path  = $this->database->normalizeLibraryPath($path);
        $sets  = [];

        if ($flag) {
            if (intval($row[$flag] ?? 0) == 0) {
                $sets[]     = "`" . $flag . "` = 1";
                $row[$flag] = 1;
            }
        }
        if ($field && $remoteId != '' && $remoteId != null) {
            $rowRemote = trim(strval($row[$field] ?? ''));
            if ($rowRemote == '') {
                $sets[]      = "`" . $field . "` = " . $this->database->sqlStringOrNull($remoteId);
                $row[$field] = $remoteId;
            }
        }

        $currentPath = $this->database->normalizeLibraryPath($row['path'] ?? '');
        if ($path != '' && !$this->database->pathLooksMangled($path) && ($currentPath == '' || $this->database->pathLooksMangled($row['path'] ?? ''))) {
            $sets[]      = "`path` = '" . $this->database->prepare($path) . "'";
            $row['path'] = $path;
        }

        if ($table != EPISODE_TABLE && is_array($meta)) {
            $title = trim(strval($meta['title'] ?? ''));
            if ($title != '' && trim(strval($row['title'] ?? '')) == '') {
                $sets[]       = "`title` = '" . $this->database->prepare($title) . "'";
                $row['title'] = $title;
            }
            $year = intval($meta['year'] ?? 0);
            if ($year > 0 && intval($row['year'] ?? 0) <= 0) {
                $sets[]      = "`year` = " . $year;
                $row['year'] = $year;
            }
            $poster = trim(strval($meta['poster'] ?? ''));
            if ($poster != '' && trim(strval($row['poster'] ?? '')) == '') {
                $sets[]        = "`poster` = '" . $this->database->prepare($poster) . "'";
                $row['poster'] = $poster;
            }
        }

        if ($sets) {
            $sql = "UPDATE " . $table . "
                    SET " . implode(', ', $sets) . "
                    WHERE id = " . $id;
            $this->database->query($sql);
        }

        $this->rememberLibraryImportItem($table, $row);

        return $row;
    }

    public function resolveEpisodeSeriesId($mediaApp, $item, $episodePath)
    {
        $seriesPath = $this->database->normalizePath($item['path'] ?? $episodePath, 'series');
        $seriesYear = intval($item['series_year'] ?? ($item['year'] ?? 0));
        $platform   = $mediaApp['platform'];
        $remoteId   = $item['series_remote_id'] ?? '';

        $seriesRow = $this->findLibraryImportItem(SERIES_TABLE, $platform, $remoteId, $seriesPath);
        if ($seriesRow) {
            $outcome = $this->libraryItemOutcomeFromRow($seriesRow, $platform, $remoteId, $seriesPath);
            if ($outcome == 'updated') {
                $this->applyLibraryImportRowUpdate(SERIES_TABLE, $platform, $remoteId, $seriesPath, $seriesRow, [
                    'title'  => $item['series'] ?? '',
                    'year'   => $seriesYear,
                    'poster' => $item['series_poster'] ?? '',
                ]);
            }
            return intval($seriesRow['id']);
        }

        if ($seriesPath == '') {
            $this->database->setLastError('empty series path for episode');
            return 0;
        }

        $flags    = $this->platformFlags($platform, $remoteId);
        $seriesId = $this->database->addSeries($item['series'] ?? '', $seriesYear, $seriesPath, $flags['plex'], $flags['emby'], $flags['jellyfin'], $flags['plex_remote_id'], $flags['emby_remote_id'], $flags['jellyfin_remote_id'], $item['series_poster'] ?? '');
        if ($seriesId) {
            $this->rememberLibraryImportItem(SERIES_TABLE, [
                'id'                 => intval($seriesId),
                'title'              => strval($item['series'] ?? ''),
                'year'               => $seriesYear,
                'path'               => $seriesPath,
                'plex'               => intval($flags['plex']),
                'emby'               => intval($flags['emby']),
                'jellyfin'           => intval($flags['jellyfin']),
                'plex_remote_id'     => $flags['plex_remote_id'],
                'emby_remote_id'     => $flags['emby_remote_id'],
                'jellyfin_remote_id' => $flags['jellyfin_remote_id'],
                'poster'             => strval($item['series_poster'] ?? ''),
            ]);
            return intval($seriesId);
        }

        $seriesRow = $this->findLibraryImportItem(SERIES_TABLE, $platform, $remoteId, $seriesPath);
        if ($seriesRow) {
            $this->applyLibraryImportRowUpdate(SERIES_TABLE, $platform, $remoteId, $seriesPath, $seriesRow, [
                'title'  => $item['series'] ?? '',
                'year'   => $seriesYear,
                'poster' => $item['series_poster'] ?? '',
            ]);
            $this->database->setLastError('');
            return intval($seriesRow['id']);
        }

        if ($this->database->getLastError() == '') {
            $this->database->setLastError('could not resolve series for episode');
        }

        return 0;
    }

    public function rehomeEpisodeAfterMatch($mediaApp, $item, $episodePath)
    {
        $episodePath = $this->database->normalizeLibraryPath($episodePath);
        if ($episodePath == '') {
            return false;
        }

        $row = [];
        if (trim(strval($item['remote_id'] ?? '')) != '') {
            $row = $this->database->getEpisodeByRemoteId($mediaApp['platform'], $item['remote_id']);
        }
        if (!$row) {
            $sql = "SELECT id, series_id, season, episode, title, path, plex, emby, jellyfin, plex_remote_id, emby_remote_id, jellyfin_remote_id, poster
                    FROM " . EPISODE_TABLE . "
                    WHERE `path` = '" . $this->database->prepare($episodePath) . "'
                    LIMIT 1";
            $row = $this->database->fetchAssoc($this->database->query($sql)) ?: [];
        }
        if (!$row) {
            return false;
        }

        $targetId = $this->database->findSeriesIdForEpisodePath($episodePath);
        if (!$targetId) {
            $targetId = $this->resolveEpisodeSeriesId($mediaApp, $item, $episodePath);
        }
        if (!$targetId || $targetId == intval($row['series_id'] ?? 0)) {
            return false;
        }

        $sql = "UPDATE " . EPISODE_TABLE . "
                SET series_id = " . intval($targetId) . "
                WHERE id = " . intval($row['id']);
        $this->database->query($sql);

        return $this->database->matchedRows() > 0;
    }

    public function libraryItemOutcome($table, $platform, $remoteId, $path, $title = '', $year = 0)
    {
        $row = $this->database->findMediaLibraryItem($table, $platform, $remoteId, $path);

        return $this->libraryItemOutcomeFromRow($row, $platform, $remoteId, $path);
    }

    public function libraryItemOutcomeFromRow($row, $platform, $remoteId, $path)
    {
        if (!$row) {
            return 'added';
        }

        $flag  = $this->database->mediaLibraryFlag($platform);
        $field = $this->database->mediaLibraryRemoteField($platform);
        if (intval($row[$flag] ?? 0) == 0) {
            return 'updated';
        }

        $rowRemote = trim(strval($row[$field] ?? ''));
        $remoteId  = trim(strval($remoteId));
        if ($remoteId != '' && $rowRemote == '') {
            return 'updated';
        }
        if ($remoteId != '' && $rowRemote != '' && $rowRemote != $remoteId) {
            return 'unchanged';
        }

        $rowPath = $this->database->normalizeLibraryPath($row['path'] ?? '');
        $path    = $this->database->normalizeLibraryPath($path);
        if ($path != '' && ($rowPath == '' || $this->database->pathLooksMangled($row['path'] ?? '')) && !$this->database->pathLooksMangled($path)) {
            return 'updated';
        }

        return 'unchanged';
    }

    public function countLibraryOutcome($outcome, $bucket, &$added, &$updated, &$unchanged)
    {
        if ($outcome == 'unchanged') {
            $unchanged[$bucket]++;
            return 'skipping';
        }
        if ($outcome == 'updated') {
            $updated[$bucket]++;
            return 'updating';
        }

        return '';
    }

    public function resolvePlexCheckedPath($mediaApp, $remoteId, $currentPath, $pathType)
    {
        global $mediaApps;

        if (intval($mediaApp['platform'] ?? 0) != MediaPlatforms::PLEX) {
            return $currentPath;
        }
        $remoteId = trim(strval($remoteId));
        if ($remoteId == '') {
            return $currentPath;
        }

        $resolved = $mediaApps->plexResolvePathWithCheckFiles(
            strval($mediaApp['url'] ?? ''),
            strval($mediaApp['token'] ?? ''),
            $remoteId,
        );
        if ($resolved == '') {
            return $currentPath;
        }

        $normalized = $this->database->normalizePath($resolved, $pathType);
        if ($normalized == '' || strcasecmp($normalized, strval($currentPath)) == 0) {
            return $currentPath;
        }

        logger($this->logfile, 'plex checkFiles path remote=' . $remoteId . ' from=' . $currentPath . ' to=' . $normalized);

        return $normalized;
    }

    public function upsertLibraryItem($mediaApp, $table, $type, $item, &$added, &$updated, &$unchanged)
    {
        $platform = $mediaApp['platform'];
        $remoteId = $item['remote_id'] ?? '';
        $path     = $this->database->normalizePath($item['path'] ?? '', $type);
        $title    = $item['title'] ?? '';
        $year     = intval($item['year'] ?? 0);
        $poster   = trim(strval($item['poster'] ?? ''));
        $bucket   = $type == 'movie' ? 'movies' : 'series';
        $row      = $this->findLibraryImportItem($table, $platform, $remoteId, $path);
        if (!$row && ($type == 'movie' || $type == 'series')) {
            $row = $this->findLibraryImportIdentityHit($table, $platform, $remoteId, [
                'title' => $title,
                'year'  => $year,
            ]);
        }
        if (!$row) {
            $checked = $this->resolvePlexCheckedPath($mediaApp, $remoteId, $path, $type);
            if ($checked != $path) {
                $path = $checked;
                $row  = $this->findLibraryImportItem($table, $platform, $remoteId, $path);
                if (!$row && ($type == 'movie' || $type == 'series')) {
                    $row = $this->findLibraryImportIdentityHit($table, $platform, $remoteId, [
                        'title' => $title,
                        'year'  => $year,
                    ]);
                }
            }
        }
        $outcome = $this->libraryItemOutcomeFromRow($row, $platform, $remoteId, $path);

        if ($outcome == 'unchanged' && $row) {
            return $this->countLibraryOutcome($outcome, $bucket, $added, $updated, $unchanged);
        }

        if ($outcome == 'updated' && $row) {
            $this->applyLibraryImportRowUpdate($table, $platform, $remoteId, $path, $row, [
                'title'  => $title,
                'year'   => $year,
                'poster' => $poster,
            ]);
            return $this->countLibraryOutcome($outcome, $bucket, $added, $updated, $unchanged);
        }

        if ($path == '') {
            $this->database->setLastError('empty ' . $type . ' path');
            return '';
        }

        if ($this->database->pathLooksMangled($path)) {
            $this->database->setLastError('refusing mangled ' . $type . ' path');
            return '';
        }

        $flags = $this->platformFlags($platform, $remoteId);
        $id    = 0;
        if ($type == 'movie') {
            $id = $this->database->addMovie($title, $year, $path, $flags['plex'], $flags['emby'], $flags['jellyfin'], $flags['plex_remote_id'], $flags['emby_remote_id'], $flags['jellyfin_remote_id'], $poster);
        } else {
            $id = $this->database->addSeries($title, $year, $path, $flags['plex'], $flags['emby'], $flags['jellyfin'], $flags['plex_remote_id'], $flags['emby_remote_id'], $flags['jellyfin_remote_id'], $poster);
        }
        if (!$id) {
            $row = $this->findLibraryImportItem($table, $platform, $remoteId, $path);
            if (!$row) {
                $pathHit = $this->findLibraryImportPathHit($table, $platform, $path);
                if ($pathHit && !$this->libraryImportRowMatchesRemote($pathHit, $platform, $remoteId)) {
                    $this->database->setLastError('');
                    $unchanged[$bucket]++;
                    return 'skipping';
                }
                $row = $pathHit;
            }
            if (!$row && ($type == 'movie' || $type == 'series')) {
                $row = $this->findLibraryImportIdentityHit($table, $platform, $remoteId, [
                    'title' => $title,
                    'year'  => $year,
                ]);
            }
            $outcome = $this->libraryItemOutcomeFromRow($row, $platform, $remoteId, $path);
            if ($outcome != 'added' && $row) {
                $this->database->setLastError('');
                if ($outcome == 'unchanged') {
                    return $this->countLibraryOutcome($outcome, $bucket, $added, $updated, $unchanged);
                }
                $this->applyLibraryImportRowUpdate($table, $platform, $remoteId, $path, $row, [
                    'title'  => $title,
                    'year'   => $year,
                    'poster' => $poster,
                ]);
                return $this->countLibraryOutcome($outcome, $bucket, $added, $updated, $unchanged);
            }
            if ($this->database->getLastError() == '') {
                $this->database->setLastError($type . ' insert failed');
            }
            return '';
        }

        $this->rememberLibraryImportItem($table, [
            'id'                 => intval($id),
            'title'              => strval($title),
            'year'               => $year,
            'path'               => $path,
            'plex'               => intval($flags['plex']),
            'emby'               => intval($flags['emby']),
            'jellyfin'           => intval($flags['jellyfin']),
            'plex_remote_id'     => $flags['plex_remote_id'],
            'emby_remote_id'     => $flags['emby_remote_id'],
            'jellyfin_remote_id' => $flags['jellyfin_remote_id'],
            'poster'             => $poster,
        ]);
        $added[$bucket]++;
        return 'inserting';
    }

    public function saveLibraryEpisode($mediaApp, $item, &$added, &$updated, &$unchanged)
    {
        $platform = $mediaApp['platform'];
        $remoteId = $item['remote_id'] ?? '';
        $path     = $this->database->normalizePath($item['path'] ?? '', 'episode');
        $season   = intval($item['season'] ?? 0);
        $episode  = intval($item['episode'] ?? 0);
        $row      = $this->findLibraryImportItem(EPISODE_TABLE, $platform, $remoteId, $path);
        if (!$row) {
            $row = $this->findLibraryImportEpisodePathHit($platform, $remoteId, $path, $season, $episode);
        }
        if (!$row) {
            $seriesRow = $this->findLibraryImportSeriesRow($mediaApp, $item, $path);
            if ($seriesRow) {
                $row = $this->findLibraryImportIdentityHit(EPISODE_TABLE, $platform, $remoteId, [
                    'series_id' => intval($seriesRow['id']),
                    'season'    => $season,
                    'episode'   => $episode,
                    'title'     => strval($item['title'] ?? ''),
                ]);
            }
        }
        if (!$row && $season >= 0 && $episode > 0) {
            $seriesRow = $this->findLibraryImportSeriesRow($mediaApp, $item, $path);
            if ($seriesRow) {
                $row = $this->findLibraryImportEpisodeCodeHit($platform, intval($seriesRow['id']), $season, $episode);
            }
        }
        if (!$row) {
            $checked = $this->resolvePlexCheckedPath($mediaApp, $remoteId, $path, 'episode');
            if ($checked != $path) {
                $path = $checked;
                $row  = $this->findLibraryImportItem(EPISODE_TABLE, $platform, $remoteId, $path);
                if (!$row) {
                    $row = $this->findLibraryImportEpisodePathHit($platform, $remoteId, $path, $season, $episode);
                }
                if (!$row) {
                    $seriesRow = $this->findLibraryImportSeriesRow($mediaApp, $item, $path);
                    if ($seriesRow) {
                        $row = $this->findLibraryImportIdentityHit(EPISODE_TABLE, $platform, $remoteId, [
                            'series_id' => intval($seriesRow['id']),
                            'season'    => $season,
                            'episode'   => $episode,
                            'title'     => strval($item['title'] ?? ''),
                        ]);
                    }
                }
                if (!$row && $season >= 0 && $episode > 0) {
                    $seriesRow = $this->findLibraryImportSeriesRow($mediaApp, $item, $path);
                    if ($seriesRow) {
                        $row = $this->findLibraryImportEpisodeCodeHit($platform, intval($seriesRow['id']), $season, $episode);
                    }
                }
            }
        }
        $outcome = $this->libraryItemOutcomeFromRow($row, $platform, $remoteId, $path);

        if ($outcome == 'unchanged' && $row) {
            if ($path != '' && $this->database->pathLooksMangled($row['path'] ?? '') && !$this->database->pathLooksMangled($path)) {
                $this->applyLibraryImportRowUpdate(EPISODE_TABLE, $platform, $remoteId, $path, $row);
                $updated['episodes']++;
                return 'updating';
            }
            $unchanged['episodes']++;
            return 'skipping';
        }

        if ($outcome == 'updated' && $row) {
            $this->applyLibraryImportRowUpdate(EPISODE_TABLE, $platform, $remoteId, $path, $row);
            $updated['episodes']++;
            return 'updating';
        }

        if ($path == '') {
            $this->database->setLastError('empty episode path');
            return '';
        }

        if ($this->database->pathLooksMangled($path)) {
            $this->database->setLastError('refusing mangled episode path');
            return '';
        }

        $seriesId = $this->resolveEpisodeSeriesId($mediaApp, $item, $path);
        if (!$seriesId) {
            return '';
        }

        $flags = $this->platformFlags($platform, $remoteId);
        $id    = $this->database->addEpisode($seriesId, $item['season'], $item['episode'], $item['title'], $path, $flags['plex'], $flags['emby'], $flags['jellyfin'], $flags['plex_remote_id'], $flags['emby_remote_id'], $flags['jellyfin_remote_id'], '', '');
        if (!$id) {
            $row = $this->findLibraryImportItem(EPISODE_TABLE, $platform, $remoteId, $path);
            if (!$row) {
                $row = $this->findLibraryImportEpisodePathHit($platform, $remoteId, $path, $season, $episode);
            }
            if (!$row) {
                $pathHit = $this->findLibraryImportPathHit(EPISODE_TABLE, $platform, $path);
                if ($pathHit && intval($pathHit['season'] ?? -1) == $season && intval($pathHit['episode'] ?? -1) == $episode) {
                    $row = $pathHit;
                } else if ($pathHit && !$this->libraryImportRowMatchesRemote($pathHit, $platform, $remoteId)) {
                    $this->database->setLastError('');
                    $unchanged['episodes']++;
                    return 'skipping';
                }
            }
            if (!$row) {
                $row = $this->findLibraryImportIdentityHit(EPISODE_TABLE, $platform, $remoteId, [
                    'series_id' => intval($seriesId),
                    'season'    => $season,
                    'episode'   => $episode,
                    'title'     => strval($item['title'] ?? ''),
                ]);
            }
            if (!$row) {
                $row = $this->findLibraryImportEpisodeCodeHit($platform, intval($seriesId), $season, $episode);
            }
            $outcome = $this->libraryItemOutcomeFromRow($row, $platform, $remoteId, $path);
            if ($outcome != 'added' && $row) {
                $this->database->setLastError('');
                if ($outcome == 'unchanged') {
                    if ($this->database->pathLooksMangled($row['path'] ?? '') && !$this->database->pathLooksMangled($path)) {
                        $this->applyLibraryImportRowUpdate(EPISODE_TABLE, $platform, $remoteId, $path, $row);
                        $updated['episodes']++;
                        return 'updating';
                    }
                    $unchanged['episodes']++;
                    return 'skipping';
                }
                $this->applyLibraryImportRowUpdate(EPISODE_TABLE, $platform, $remoteId, $path, $row);
                $updated['episodes']++;
                return 'updating';
            }
            $recovered = $this->database->recoverEpisodeInsert($path, $flags['plex'], $flags['emby'], $flags['jellyfin'], $flags['plex_remote_id'], $flags['emby_remote_id'], $flags['jellyfin_remote_id']);
            if ($recovered) {
                $row = $this->findLibraryImportItem(EPISODE_TABLE, $platform, $remoteId, $path);
                if (!$row) {
                    $row = $this->findLibraryImportEpisodePathHit($platform, $remoteId, $path, $season, $episode);
                }
                if ($row) {
                    $this->rememberLibraryImportItem(EPISODE_TABLE, $row);
                    $outcome = $this->libraryItemOutcomeFromRow($row, $platform, $remoteId, $path);
                    if ($outcome == 'unchanged') {
                        $unchanged['episodes']++;
                        return 'skipping';
                    }
                    if ($outcome == 'updated') {
                        $this->applyLibraryImportRowUpdate(EPISODE_TABLE, $platform, $remoteId, $path, $row);
                        $updated['episodes']++;
                        return 'updating';
                    }
                }
                $this->rememberLibraryImportItem(EPISODE_TABLE, [
                    'id'                 => intval($recovered),
                    'series_id'          => intval($seriesId),
                    'season'             => $season,
                    'episode'            => $episode,
                    'title'              => strval($item['title'] ?? ''),
                    'path'               => $path,
                    'plex'               => intval($flags['plex']),
                    'emby'               => intval($flags['emby']),
                    'jellyfin'           => intval($flags['jellyfin']),
                    'plex_remote_id'     => $flags['plex_remote_id'],
                    'emby_remote_id'     => $flags['emby_remote_id'],
                    'jellyfin_remote_id' => $flags['jellyfin_remote_id'],
                    'poster'             => '',
                ]);
                $updated['episodes']++;
                return 'updating';
            }
            return '';
        }

        $this->rememberLibraryImportItem(EPISODE_TABLE, [
            'id'                 => intval($id),
            'series_id'          => intval($seriesId),
            'season'             => $season,
            'episode'            => $episode,
            'title'              => strval($item['title'] ?? ''),
            'path'               => $path,
            'plex'               => intval($flags['plex']),
            'emby'               => intval($flags['emby']),
            'jellyfin'           => intval($flags['jellyfin']),
            'plex_remote_id'     => $flags['plex_remote_id'],
            'emby_remote_id'     => $flags['emby_remote_id'],
            'jellyfin_remote_id' => $flags['jellyfin_remote_id'],
            'poster'             => '',
        ]);
        $added['episodes']++;
        return 'inserting';
    }

    public function saveWebhookLibraryItem($mediaApp, $type, $item)
    {
        global $mediaApps;

        if (!$mediaApps->scanLibraryItemAllowed($mediaApp, $item)) {
            return '';
        }

        $platform = $mediaApp['platform'];
        $remoteId = trim(strval($item['remote_id'] ?? ''));
        $pathType = $type == 'episode' ? 'episode' : ($type == 'series' ? 'series' : 'movie');
        $path     = $this->database->normalizePath($item['path'] ?? '', $pathType);
        $title    = trim(strval($item['title'] ?? ''));
        $year     = intval($item['year'] ?? 0);
        $poster   = trim(strval($item['poster'] ?? ''));

        if ($type == 'episode') {
            $row = [];
            if ($remoteId != '') {
                $row = $this->database->getEpisodeByRemoteId($platform, $remoteId);
            }
            if (!$row && $path != '') {
                $sql = "SELECT id, series_id, season, episode, title, path, plex, emby, jellyfin, plex_remote_id, emby_remote_id, jellyfin_remote_id, poster
                        FROM " . EPISODE_TABLE . "
                        WHERE `path` = '" . $this->database->prepare($path) . "'
                        LIMIT 1";
                $row = $this->database->fetchAssoc($this->database->query($sql)) ?: [];
            }
            if (!$row) {
                return '';
            }

            $this->database->setEpisodePlatform($platform, $remoteId, $path != '' ? $path : strval($row['path'] ?? ''));
            if ($path != '') {
                $this->rehomeEpisodeAfterMatch($mediaApp, $item, $path);
            }

            return 'updating';
        }

        if ($type != 'movie' && $type != 'series') {
            return '';
        }

        $table = $type == 'movie' ? MOVIE_TABLE : SERIES_TABLE;
        $row   = $this->database->findMediaLibraryItem($table, $platform, $remoteId, $path);
        if (!$row) {
            return '';
        }

        $this->database->setMediaLibraryPlatformById($table, $platform, $remoteId, $path, $row['id']);
        $this->database->mergeLibraryItemOnto($table, $row['id'], [
            'title'              => $title,
            'year'               => $year,
            'path'               => $path,
            'poster'             => $poster,
            'plex'               => 0,
            'emby'               => 0,
            'jellyfin'           => 0,
            'plex_remote_id'     => '',
            'emby_remote_id'     => '',
            'jellyfin_remote_id' => '',
        ]);
        if ($poster != '' || $title != '' || $year > 0) {
            $this->database->fillBlankLibraryMetadata($table, $platform, $remoteId, $path != '' ? $path : ($row['path'] ?? ''), $title, $year, $poster);
        }

        return 'updating';
    }

    public function logLibraryItem($type, $index, $total, $platformName, $item, $success, $action = 'updating', $error = '')
    {
        $parts = [$platformName];
        if (($item['library'] ?? '') != '') {
            $parts[] = $item['library'];
        }
        if ($type == 'episode' && ($item['series'] ?? '') != '') {
            $parts[] = $item['series'];
        }
        if (($item['title'] ?? '') != '') {
            $parts[] = $item['title'];
        }
        $line = $type . ' ' . $index . '/' . $total . ' ' . $action . ': ' . implode(' -> ', $parts);
        if ($action == 'inserting' || $action == 'updating') {
            $line .= ' ' . ($success ? 'success' : 'failed');
        }
        logger($this->logfile, $line);
        if (!$success && $action != 'skipping') {
            $detail = trim(strval($error));
            if ($detail == '') {
                $detail = $this->database->getLastError();
            }
            if ($detail != '') {
                logger($this->logfile, 'error: ' . preg_replace('/\s+/', ' ', $detail));
            }
            $path = trim(strval($item['path'] ?? ''));
            if ($path != '') {
                logger($this->logfile, 'path: ' . $path);
            }
            $remoteId = trim(strval($item['remote_id'] ?? ''));
            if ($remoteId != '') {
                logger($this->logfile, 'remote_id: ' . $remoteId);
            }
        }
    }

    public function syncLibraries()
    {
        global $mediaApps;

        $master    = [];
        $listeners = [];
        foreach ($this->database->getMediaApps() as $mediaApp) {
            if (!$mediaApp['active']) {
                continue;
            }
            if ($mediaApp['role'] == MediaAppRoles::MASTER) {
                $master = $mediaApp;
            } else {
                $listeners[] = $mediaApp;
            }
        }
        if (!$master) {
            logger($this->logfile, 'no master for library sync');
            return;
        }

        $online = $this->onlineMediaApps(array_merge([$master], $listeners), 2);
        if (!$online) {
            return;
        }

        $listeners    = [];
        $masterOnline = false;
        foreach ($online as $mediaApp) {
            if (intval($mediaApp['id']) == intval($master['id'])) {
                $master       = $mediaApp;
                $masterOnline = true;
                continue;
            }
            $listeners[] = $mediaApp;
        }
        if (!$masterOnline) {
            logger($this->logfile, 'no online master for library sync');
            return;
        }

        $masterLibraries = $mediaApps->getLibraries($master, [], true);
        $masterByKey     = [];
        foreach ($masterLibraries as $library) {
            $key = strval($library['key'] ?? '');
            if ($key != '') {
                $masterByKey[$key] = $library;
            }
        }

        if ($this->isAutomaticJob() && $this->database->settingEnabled('syncParityAutoLibraries')) {
            $state = $mediaApps->paritySyncState('library');
            if ($state) {
                $changed = false;
                foreach ($masterLibraries as $library) {
                    $key = strval($library['key'] ?? '');
                    if ($key == '') {
                        continue;
                    }
                    $id = intval($master['id']) . ':' . $key;
                    if (array_key_exists($id, $state) || $this->database->getMediaAppLibraryLinks($master['id'], $key)) {
                        continue;
                    }
                    $state[$id] = 1;
                    $changed    = true;
                }
                if ($changed) {
                    $mediaApps->setParitySync('library', $state);
                    $this->currentJob['libraries'] = $mediaApps->selectedParityLibraries(false);
                }
            }
        }

        $keep     = [];
        $selected = [];
        foreach ($this->selectedLibraryRefs($master) as $library) {
            $key = strval($library['key'] ?? '');
            if ($key == '') {
                continue;
            }
            $keep[$this->libraryKeepId($master['id'], $key)] = true;
            if (!empty($masterByKey[$key])) {
                $selected[] = $masterByKey[$key];
            }
        }

        $automatic = $this->isAutomaticJob();
        logger($this->logfile, 'library sync master ' . $master['name'] . ' libraries=' . count($selected));

        foreach ($listeners as $listener) {
            $this->stopIfCancelled();
            logger($this->logfile, 'library sync ' . $listener['name']);
            $listenerLibraries = $mediaApps->getLibraries($listener);

            $created      = 0;
            $linked       = 0;
            $removed      = 0;
            $skipped      = 0;
            $removedKeys  = [];
            $createdNames = [];
            $linkedNames  = [];
            $removedNames = [];

            foreach ($masterLibraries as $masterLibrary) {
                $this->stopIfCancelled();
                $masterKey = strval($masterLibrary['key'] ?? '');
                if ($masterKey == '' || !empty($keep[$this->libraryKeepId($master['id'], $masterKey)])) {
                    continue;
                }

                $found       = $mediaApps->findListenerLibrary($master, $masterLibrary, $listener, $listenerLibraries);
                $listenerKey = strval($found['key'] ?? '');
                if ($listenerKey == '') {
                    continue;
                }
                $label  = $masterLibrary['title'] ?? $masterKey;
                $before = $removed;
                $this->removeListenerLibrary($mediaApps, $listener, $listenerKey, $label, $listenerLibraries, $removedKeys, $removed);
                if ($removed > $before) {
                    $removedNames[] = $label;
                }
            }

            foreach ($this->database->getMediaAppLibraryLinks($master['id']) as $link) {
                $this->stopIfCancelled();
                if (intval($link['linked_media_app_id']) != intval($listener['id'])) {
                    continue;
                }
                if (!empty($keep[$this->libraryKeepId($master['id'], $link['library_key'])])) {
                    continue;
                }

                $listenerKey = strval($link['linked_library_key'] ?? '');
                $masterKey   = strval($link['library_key'] ?? '');
                $label       = !empty($masterByKey[$masterKey]['title']) ? $masterByKey[$masterKey]['title'] : $masterKey;
                $before      = $removed;
                $this->removeListenerLibrary($mediaApps, $listener, $listenerKey, $label, $listenerLibraries, $removedKeys, $removed);
                if ($removed > $before) {
                    $removedNames[] = $label;
                }
            }

            foreach ($selected as $masterLibrary) {
                $this->stopIfCancelled();
                $label = $masterLibrary['title'] ?? $masterLibrary['key'] ?? '';
                $found = $mediaApps->findListenerLibrary($master, $masterLibrary, $listener, $listenerLibraries);
                if ($found && strval($found['key'] ?? '') != '') {
                    $hadLink = false;
                    foreach ($this->database->getMediaAppLibraryLinks($master['id']) as $link) {
                        if (intval($link['linked_media_app_id']) == intval($listener['id']) && strval($link['library_key']) == strval($masterLibrary['key'])) {
                            $hadLink = true;
                            break;
                        }
                    }
                    $this->database->setMediaAppLibraryLink($master['id'], $masterLibrary['key'], $listener['id'], $found['key']);
                    if (!$hadLink) {
                        $linked++;
                        if ($label != '') {
                            $linkedNames[] = $label;
                        }
                    }
                    continue;
                }
                if ($automatic && !$this->database->settingEnabled('syncParityAutoLibraries')) {
                    $skipped++;
                    logger($this->logfile, ($masterLibrary['title'] ?? $masterLibrary['key']) . ' no link, skip');
                    continue;
                }

                $result = $mediaApps->createLibrary($listener, $masterLibrary);
                if (!empty($result['error'])) {
                    logger($this->logfile, 'create ' . ($masterLibrary['title'] ?? '') . ' ' . ($result['message'] ?? ''));
                    continue;
                }
                $created++;
                if ($label != '') {
                    $createdNames[] = $label;
                }
                $listenerLibraries = $mediaApps->getLibraries($listener);
                $found             = $mediaApps->findListenerLibrary($master, $masterLibrary, $listener, $listenerLibraries);
                if ($found && strval($found['key'] ?? '') != '') {
                    $this->database->setMediaAppLibraryLink($master['id'], $masterLibrary['key'], $listener['id'], $found['key']);
                    $linked++;
                }
            }

            $mediaApps->linkLibraries();
            $users = [];
            foreach ($mediaApps->selectedParityUserIds() as $userId) {
                $user = $this->database->getMediaAppUser($userId);
                if ($user) {
                    $users[] = $user;
                }
            }
            $access = $mediaApps->syncListenerSelectedAccess($master, $listener, $users, $selected);
            if ($created || $linked || $removed) {
                $this->database->setMediaAppNeedsSync($listener['id']);
            }
            $this->currentJob['stats']['created'] = intval($this->currentJob['stats']['created'] ?? 0) + $created;
            $this->currentJob['stats']['linked']  = intval($this->currentJob['stats']['linked'] ?? 0) + $linked;
            $this->currentJob['stats']['removed'] = intval($this->currentJob['stats']['removed'] ?? 0) + $removed;
            $this->currentJob['stats']['access']  = intval($this->currentJob['stats']['access'] ?? 0) + intval($access['updated'] ?? 0);
            foreach ($createdNames as $name) {
                $this->addParityResult($listener, 'libraries', 'created', $name);
            }
            foreach ($linkedNames as $name) {
                $this->addParityResult($listener, 'libraries', 'linked', $name);
            }
            foreach ($removedNames as $name) {
                $this->addParityResult($listener, 'libraries', 'removed', $name);
            }
            logger($this->logfile, 'library sync ' . $listener['name'] . ' created=' . $created . ' linked=' . $linked . ' removed=' . $removed . ' skipped=' . $skipped . ' access=' . intval($access['updated'] ?? 0));
        }
    }

    public function selectedLibraryRefs($master)
    {
        $refs = [];
        foreach ($this->currentJob['libraries'] ?? [] as $library) {
            if (intval($library['media_app_id'] ?? 0) && intval($library['media_app_id']) != intval($master['id'])) {
                continue;
            }
            if (($library['key'] ?? '') != '') {
                $refs[] = $library;
            }
        }
        if ($refs) {
            return $refs;
        }

        global $mediaApps;

        return $mediaApps->selectedParityLibraries(false);
    }

    public function libraryKeepId($mediaAppId, $key)
    {
        return intval($mediaAppId) . ':' . strval($key);
    }

    public function removeListenerLibrary($mediaApps, $listener, $listenerKey, $label, &$listenerLibraries, &$removedKeys, &$removed)
    {
        $listenerKey = strval($listenerKey);
        if ($listenerKey == '' || !empty($removedKeys[$listenerKey])) {
            return $listenerKey != '' && !empty($removedKeys[$listenerKey]);
        }

        $result = $mediaApps->deleteLibrary($listener, $listenerKey);
        if (!empty($result['error'])) {
            logger($this->logfile, 'remove ' . $label . ' ' . ($result['message'] ?? ''));
            return false;
        }
        $this->database->deleteMediaAppLibraryLink($listener['id'], $listenerKey);
        $removedKeys[$listenerKey] = true;
        $kept                      = [];
        foreach ($listenerLibraries as $library) {
            if (strval($library['key'] ?? '') != $listenerKey) {
                $kept[] = $library;
            }
        }
        $listenerLibraries = $kept;
        $removed++;
        logger($this->logfile, 'remove ' . $label . ' complete');

        return true;
    }
}
