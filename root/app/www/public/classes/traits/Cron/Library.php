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
        $mediaAppId = intval($this->sidecar['media_app_id'] ?? 0);
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
        foreach ($this->sidecar['libraries'] ?? [] as $library) {
            if (!empty($library['media_app_id'])) {
                $ids[] = intval($library['media_app_id']);
            }
        }

        return array_values(array_unique($ids));
    }

    public function libraryKeys($mediaAppId)
    {
        $keys = [];
        foreach ($this->sidecar['libraries'] ?? [] as $library) {
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

        $keys      = $this->libraryKeys($mediaApp['id']);
        $hasList   = !empty($this->sidecar['libraries']);
        $libraries = [];
        foreach ($mediaApps->getLibraries($mediaApp) as $library) {
            if (!$library['key']) {
                continue;
            }
            if ($hasList && !in_array($library['key'], $keys)) {
                continue;
            }
            $libraries[] = $library;
        }

        return $libraries;
    }

    public function pullMediaLibrary()
    {
        global $mediaApps;

        if (!intval($this->database->getSetting('library_dedupe_done'))) {
            $merged = $this->database->mergeDuplicateLibraryItems();
            $this->database->ensureLibraryRemoteUniqueIndexes();
            $this->database->setSetting('library_dedupe_done', '1');
            if ($merged) {
                logger($this->logfile, 'one-time library dedupe merged ' . $merged);
            }
            logger($this->logfile, 'one-time library remote unique indexes ensured');
        }

        $apps = $this->targetApps();
        if (!$apps) {
            logger($this->logfile, 'no active media apps');
            return;
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
            $totals       = ['movies' => 0, 'series' => 0, 'episodes' => 0];

            $scan      = intval($this->sidecar['scan'] ?? MediaLibraryScans::LAST_SCAN);
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
                    foreach (['movies', 'series', 'episodes'] as $kind) {
                        foreach ($items[$kind] ?? [] as $item) {
                            $remoteId = trim(strval($item['remote_id'] ?? ''));
                            if ($remoteId != '') {
                                $seenIds[$remoteId] = true;
                            }
                        }
                    }
                }
                $this->importLibraryItems($mediaApp, $platformName, [
                    'movies'   => $items['movies'] ?? [],
                    'series'   => $items['series'] ?? [],
                    'episodes' => $items['episodes'] ?? [],
                ], $added, $updated);

            }

            if (!$since && $seenIds && $this->scannedAllAppLibraries($mediaApp, $libraries)) {
                $cleared = $this->database->clearUnseenMediaLibraryRemotes($mediaApp['platform'], array_keys($seenIds));
                if ($cleared) {
                    logger($this->logfile, 'media library ' . $mediaApp['name'] . ' cleared stale remote ids ' . $cleared);
                }
            }

            $this->database->setMediaAppLastScan($mediaApp['id'], $scannedAt);
            $this->sidecar['stats']['added']   = intval($this->sidecar['stats']['added'] ?? 0) + $added['movies'] + $added['series'] + $added['episodes'];
            $this->sidecar['stats']['updated'] = intval($this->sidecar['stats']['updated'] ?? 0) + $updated['movies'] + $updated['series'] + $updated['episodes'];
            foreach (['movies', 'series', 'episodes'] as $kind) {
                $this->addLibraryResult($mediaApp, $kind, 'added', $added[$kind]);
                $this->addLibraryResult($mediaApp, $kind, 'updated', $updated[$kind]);
            }
            logger($this->logfile, 'media library ' . $mediaApp['name'] . ' movies: added ' . $added['movies'] . ', updated: ' . $updated['movies'] . ', total: ' . $totals['movies'] . ' | series: added ' . $added['series'] . ', updated: ' . $updated['series'] . ', total: ' . $totals['series'] . ' | episodes: added ' . $added['episodes'] . ', updated: ' . $updated['episodes'] . ', total: ' . $totals['episodes']);
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

    public function importLibraryItems($mediaApp, $platformName, $items, &$added, &$updated)
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
            if ($item['path'] || $item['remote_id']) {
                try {
                    $action  = $this->upsertLibraryItem($mediaApp, SERIES_TABLE, 'series', $item, $added, $updated);
                    $success = $action != '';
                } catch (Exception $e) {
                    if ($e->getMessage() == 'cancelled') {
                        throw $e;
                    }
                }
            }
            $this->logLibraryItem('series', $seriesIndex, $seriesTotal, $platformName, $item, $success, $action);
        }

        foreach ($items['movies'] ?? [] as $item) {
            $this->stopIfCancelled();
            $movieIndex++;
            $success = false;
            $action  = 'updating';
            if ($item['path'] || $item['remote_id']) {
                try {
                    $action  = $this->upsertLibraryItem($mediaApp, MOVIE_TABLE, 'movie', $item, $added, $updated);
                    $success = $action != '';
                } catch (Exception $e) {
                    if ($e->getMessage() == 'cancelled') {
                        throw $e;
                    }
                }
            }
            $this->logLibraryItem('movie', $movieIndex, $movieTotal, $platformName, $item, $success, $action);
        }

        foreach ($items['episodes'] ?? [] as $item) {
            $this->stopIfCancelled();
            $episodeIndex++;
            $success = false;
            $action  = 'updating';
            if ($item['path'] || $item['remote_id']) {
                try {
                    $path = $this->database->normalizePath($item['path'] ?? '', 'episode');
                    if ($this->database->setEpisodePlatform($mediaApp['platform'], $item['remote_id'], $path) > 0) {
                        $updated['episodes']++;
                        $success = true;
                    } else {
                        $action      = 'inserting';
                        $seriesPath  = $this->database->normalizePath($item['path'] ?? '', 'series');
                        $seriesId    = 0;
                        $seriesWhere = '';
                        if ($seriesPath != '') {
                            if ($this->database->setSeriesPlatform($mediaApp['platform'], $item['series_remote_id'] ?? '', $seriesPath) > 0) {
                                $seriesWhere = $this->database->mediaLibraryMatchWhere($mediaApp['platform'], $item['series_remote_id'] ?? '', $seriesPath);
                                if (trim(strval($item['series_poster'] ?? '')) != '') {
                                    $this->database->fillBlankLibraryMetadata(SERIES_TABLE, $mediaApp['platform'], $item['series_remote_id'] ?? '', $seriesPath, $item['series'] ?? '', 0, $item['series_poster']);
                                }
                            } else {
                                $flags    = $this->platformFlags($mediaApp['platform'], $item['series_remote_id'] ?? '');
                                $seriesId = $this->database->addSeries($item['series'] ?? '', 0, $seriesPath, $flags['plex'], $flags['emby'], $flags['jellyfin'], $flags['plex_remote_id'], $flags['emby_remote_id'], $flags['jellyfin_remote_id'], $item['series_poster'] ?? '');
                                if ($seriesId) {
                                    $seriesWhere = $this->database->mediaLibraryMatchWhere($mediaApp['platform'], $item['series_remote_id'] ?? '', $seriesPath);
                                }
                            }
                        }
                        $flags   = $this->platformFlags($mediaApp['platform'], $item['remote_id']);
                        $success = $this->database->addEpisode($seriesId, $item['season'], $item['episode'], $item['title'], $path, $flags['plex'], $flags['emby'], $flags['jellyfin'], $flags['plex_remote_id'], $flags['emby_remote_id'], $flags['jellyfin_remote_id'], $seriesWhere, '');
                        if ($success) {
                            $added['episodes']++;
                        }
                    }
                } catch (Exception $e) {
                    if ($e->getMessage() == 'cancelled') {
                        throw $e;
                    }
                }
            }
            $this->logLibraryItem('episode', $episodeIndex, $episodeTotal, $platformName, $item, $success, $action);
        }
    }

    public function upsertLibraryItem($mediaApp, $table, $kind, $item, &$added, &$updated)
    {
        $platform = $mediaApp['platform'];
        $remoteId = $item['remote_id'] ?? '';
        $path     = $this->database->normalizePath($item['path'] ?? '', $kind);
        $title    = $item['title'] ?? '';
        $year     = intval($item['year'] ?? 0);
        $poster   = trim(strval($item['poster'] ?? ''));
        $bucket   = $kind == 'movie' ? 'movies' : 'series';

        if ($this->database->setMediaLibraryPlatform($table, $platform, $remoteId, $path) > 0) {
            $updated[$bucket]++;
            if ($poster != '') {
                $this->database->fillBlankLibraryMetadata($table, $platform, $remoteId, $path, $title, $year, $poster);
            }
            return 'updating';
        }

        if ($path == '') {
            return '';
        }

        $flags = $this->platformFlags($platform, $remoteId);
        $id    = 0;
        if ($kind == 'movie') {
            $id = $this->database->addMovie($title, $year, $path, $flags['plex'], $flags['emby'], $flags['jellyfin'], $flags['plex_remote_id'], $flags['emby_remote_id'], $flags['jellyfin_remote_id'], $poster);
        } else {
            $id = $this->database->addSeries($title, $year, $path, $flags['plex'], $flags['emby'], $flags['jellyfin'], $flags['plex_remote_id'], $flags['emby_remote_id'], $flags['jellyfin_remote_id'], $poster);
        }
        if (!$id) {
            if ($this->database->setMediaLibraryPlatform($table, $platform, $remoteId, $path) > 0) {
                $updated[$bucket]++;
                if ($poster != '') {
                    $this->database->fillBlankLibraryMetadata($table, $platform, $remoteId, $path, $title, $year, $poster);
                }
                return 'updating';
            }
            return '';
        }

        $added[$bucket]++;
        return 'inserting';
    }

    public function logLibraryItem($kind, $index, $total, $platformName, $item, $success, $action = 'updating')
    {
        $parts = [$platformName];
        if (($item['library'] ?? '') != '') {
            $parts[] = $item['library'];
        }
        if ($kind == 'episode' && ($item['series'] ?? '') != '') {
            $parts[] = $item['series'];
        }
        if (($item['title'] ?? '') != '') {
            $parts[] = $item['title'];
        }
        logger($this->logfile, $kind . ' ' . $index . '/' . $total . ' ' . $action . ': ' . implode(' -> ', $parts) . ' ' . ($success ? 'success' : 'failed'));
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

        $listeners = [];
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

        $masterLibraries = $mediaApps->getLibraries($master);
        $masterByKey     = [];
        foreach ($masterLibraries as $library) {
            $key = strval($library['key'] ?? '');
            if ($key != '') {
                $masterByKey[$key] = $library;
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

        $automatic = intval($this->sidecar['trigger'] ?? 0) == MediaSyncTriggers::AUTOMATIC;
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
                $label = $masterLibrary['title'] ?? $masterKey;
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
                    $linked++;
                    if (!$hadLink && $label != '') {
                        $linkedNames[] = $label;
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
            $this->sidecar['stats']['created'] = intval($this->sidecar['stats']['created'] ?? 0) + $created;
            $this->sidecar['stats']['linked']  = intval($this->sidecar['stats']['linked'] ?? 0) + $linked;
            $this->sidecar['stats']['removed'] = intval($this->sidecar['stats']['removed'] ?? 0) + $removed;
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
        foreach ($this->sidecar['libraries'] ?? [] as $library) {
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
