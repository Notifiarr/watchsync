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
            if (intval($library['media_app_id'] ?? 0) == intval($mediaAppId) && ($library['key'] ?? '') !== '') {
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
        $libraries = [];
        foreach ($mediaApps->getLibraries($mediaApp) as $library) {
            if (!$library['key']) {
                continue;
            }
            if ($keys && !in_array($library['key'], $keys)) {
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

        foreach ($apps as $mediaApp) {
            $this->stopIfCancelled();
            $libraries = $this->selectedLibraries($mediaApp);
            logger($this->logfile, 'media library ' . $mediaApp['name'] . ' libraries ' . count($libraries));
            $platformName = $mediaApps->getPlatformName($mediaApp['platform']);
            $added        = ['movies' => 0, 'series' => 0, 'episodes' => 0];
            $updated      = ['movies' => 0, 'series' => 0, 'episodes' => 0];
            $totals       = ['movies' => 0, 'series' => 0, 'episodes' => 0];

            $scan      = intval($this->sidecar['scan'] ?? MediaLibraryScans::LAST_SCAN);
            $since     = $scan == MediaLibraryScans::FULL ? 0 : intval($mediaApp['last_scan'] ?? 0);
            $scannedAt = time();
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
                $this->importLibraryItems($mediaApp, $platformName, [
                    'movies'   => $items['movies'] ?? [],
                    'series'   => $items['series'] ?? [],
                    'episodes' => $items['episodes'] ?? [],
                ], $added, $updated);

            }

            $this->database->setMediaAppLastScan($mediaApp['id'], $scannedAt);
            logger($this->logfile, 'media library ' . $mediaApp['name'] . ' movies: added ' . $added['movies'] . ', updated: ' . $updated['movies'] . ', total: ' . $totals['movies'] . ' | series: added ' . $added['series'] . ', updated: ' . $updated['series'] . ', total: ' . $totals['series'] . ' | episodes: added ' . $added['episodes'] . ', updated: ' . $updated['episodes'] . ', total: ' . $totals['episodes']);
        }
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
            $ok     = false;
            $action = 'updating';
            if ($item['path'] || $item['remote_id']) {
                try {
                    if ($this->database->setSeriesPlatform($mediaApp['platform'], $item['remote_id'], $item['path']) > 0) {
                        $updated['series']++;
                        $ok = true;
                    } else {
                        $action = 'inserting';
                        $flags  = $this->platformFlags($mediaApp['platform'], $item['remote_id']);
                        $ok     = (bool) $this->database->addSeries($item['title'], $item['year'], $item['path'], $flags['plex'], $flags['emby'], $flags['jellyfin'], $flags['plex_remote_id'], $flags['emby_remote_id'], $flags['jellyfin_remote_id']);
                        if ($ok) {
                            $added['series']++;
                        }
                    }
                } catch (Exception $e) {
                    if ($e->getMessage() == 'cancelled') {
                        throw $e;
                    }
                }
            }
            $this->logLibraryItem('series', $seriesIndex, $seriesTotal, $platformName, $item, $ok, $action);
        }

        foreach ($items['movies'] ?? [] as $item) {
            $this->stopIfCancelled();
            $movieIndex++;
            $ok     = false;
            $action = 'updating';
            if ($item['path'] || $item['remote_id']) {
                try {
                    if ($this->database->setMoviePlatform($mediaApp['platform'], $item['remote_id'], $item['path']) > 0) {
                        $updated['movies']++;
                        $ok = true;
                    } else {
                        $action = 'inserting';
                        $flags  = $this->platformFlags($mediaApp['platform'], $item['remote_id']);
                        $ok     = (bool) $this->database->addMovie($item['title'], $item['year'], $item['path'], $flags['plex'], $flags['emby'], $flags['jellyfin'], $flags['plex_remote_id'], $flags['emby_remote_id'], $flags['jellyfin_remote_id']);
                        if ($ok) {
                            $added['movies']++;
                        }
                    }
                } catch (Exception $e) {
                    if ($e->getMessage() == 'cancelled') {
                        throw $e;
                    }
                }
            }
            $this->logLibraryItem('movie', $movieIndex, $movieTotal, $platformName, $item, $ok, $action);
        }

        foreach ($items['episodes'] ?? [] as $item) {
            $this->stopIfCancelled();
            $episodeIndex++;
            $ok     = false;
            $action = 'updating';
            if ($item['path'] || $item['remote_id']) {
                try {
                    if ($this->database->setEpisodePlatform($mediaApp['platform'], $item['remote_id'], $item['path']) > 0) {
                        $updated['episodes']++;
                        $ok = true;
                    } else {
                        $action      = 'inserting';
                        $seriesPath  = $item['path'] ? dirname($item['path']) : '';
                        $seriesId    = 0;
                        $seriesWhere = '';
                        if ($this->database->setSeriesPlatform($mediaApp['platform'], $item['series_remote_id'], $seriesPath) > 0) {
                            $seriesWhere = $this->database->mediaLibraryMatchWhere($mediaApp['platform'], $item['series_remote_id'], $seriesPath);
                        } else {
                            $flags    = $this->platformFlags($mediaApp['platform'], $item['series_remote_id']);
                            $seriesId = $this->database->addSeries($item['title'], $item['year'], $seriesPath, $flags['plex'], $flags['emby'], $flags['jellyfin'], $flags['plex_remote_id'], $flags['emby_remote_id'], $flags['jellyfin_remote_id']);
                        }
                        $flags = $this->platformFlags($mediaApp['platform'], $item['remote_id']);
                        $ok    = (bool) $this->database->addEpisode($seriesId, $item['season'], $item['episode'], $item['title'], $item['path'], $flags['plex'], $flags['emby'], $flags['jellyfin'], $flags['plex_remote_id'], $flags['emby_remote_id'], $flags['jellyfin_remote_id'], $seriesWhere);
                        if ($ok) {
                            $added['episodes']++;
                        }
                    }
                } catch (Exception $e) {
                    if ($e->getMessage() == 'cancelled') {
                        throw $e;
                    }
                }
            }
            $this->logLibraryItem('episode', $episodeIndex, $episodeTotal, $platformName, $item, $ok, $action);
        }
    }

    public function logLibraryItem($kind, $index, $total, $platformName, $item, $ok, $action = 'updating')
    {
        $parts = [$platformName];
        if (($item['library'] ?? '') !== '') {
            $parts[] = $item['library'];
        }
        if ($kind == 'episode' && ($item['series'] ?? '') !== '') {
            $parts[] = $item['series'];
        }
        if (($item['title'] ?? '') !== '') {
            $parts[] = $item['title'];
        }
        logger($this->logfile, $kind . ' ' . $index . '/' . $total . ' ' . $action . ': ' . implode(' -> ', $parts) . ' ' . ($ok ? 'complete' : 'failed'));
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

        $keep = [];
        foreach ($this->sidecar['libraries'] ?? [] as $library) {
            $keep[intval($library['media_app_id']) . ':' . ($library['key'] ?? '')] = true;
        }

        $selected = [];
        foreach ($mediaApps->getLibraries($master) as $library) {
            $key = intval($master['id']) . ':' . ($library['key'] ?? '');
            if ($keep && empty($keep[$key])) {
                continue;
            }
            $selected[] = $library;
        }

        logger($this->logfile, 'library sync master ' . $master['name'] . ' libraries=' . count($selected));

        foreach ($listeners as $listener) {
            $this->stopIfCancelled();
            logger($this->logfile, 'library sync ' . $listener['name']);
            $existing = [];
            foreach ($mediaApps->getLibraries($listener) as $library) {
                $title = strtolower(trim($library['title'] ?? ''));
                if ($title !== '') {
                    $existing[$title] = $library;
                }
            }

            $created = 0;
            $linked  = 0;
            $skipped = 0;

            foreach ($selected as $masterLibrary) {
                $this->stopIfCancelled();
                if ($this->database->getMediaAppLibraryLinkForApp($master['id'], $masterLibrary['key'], $listener['id'])) {
                    $skipped++;
                    continue;
                }
                $title = strtolower(trim($masterLibrary['title'] ?? ''));
                if ($title !== '' && !empty($existing[$title])) {
                    $this->database->setMediaAppLibraryLink($master['id'], $masterLibrary['key'], $listener['id'], $existing[$title]['key']);
                    $linked++;
                    continue;
                }

                $result = $mediaApps->createLibrary($listener, $masterLibrary);
                if (!empty($result['error'])) {
                    logger($this->logfile, 'create ' . ($masterLibrary['title'] ?? '') . ' ' . ($result['message'] ?? ''));
                    continue;
                }
                $created++;
                foreach ($mediaApps->getLibraries($listener) as $library) {
                    if (strtolower(trim($library['title'] ?? '')) !== $title || ($library['key'] ?? '') === '') {
                        continue;
                    }
                    $this->database->setMediaAppLibraryLink($master['id'], $masterLibrary['key'], $listener['id'], $library['key']);
                    $existing[$title] = $library;
                    $linked++;
                    break;
                }
            }

            $mediaApps->linkLibraries();
            if ($created || $linked) {
                $this->database->setMediaAppNeedsSync($listener['id']);
            }
            logger($this->logfile, 'library sync ' . $listener['name'] . ' created=' . $created . ' linked=' . $linked . ' skipped=' . $skipped);
        }
    }
}
