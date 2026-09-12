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
            if ($this->database->settingEnabled('syncHistoryNewUsers')) {
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
            $series[intval($row['id'])] = trim(strval($row['title'] ?? ''));
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

    public function watchIndexItem($kind, $row, $platforms, $series)
    {
        $remote = [];
        $flag   = [];
        foreach ($platforms as $platform => $name) {
            $flag[$platform]   = intval($row[$name] ?? 0);
            $remote[$platform] = trim(strval($row[$name . '_remote_id'] ?? ''));
        }
        $id    = intval($row['id']);
        $title = trim(strval($row['title'] ?? ''));
        $label = $title;
        if ($kind == 'movie') {
            $year = intval($row['year'] ?? 0);
            if ($label == '') {
                $label = 'movie #' . $id;
            } else if ($year) {
                $label .= ' (' . $year . ')';
            }
        } else {
            $show = $series[intval($row['series_id'] ?? 0)] ?? '';
            if ($show == '') {
                $show = 'series #' . intval($row['series_id'] ?? 0);
            }
            $code  = 'S' . str_pad(strval(intval($row['season'] ?? 0)), 2, '0', STR_PAD_LEFT)
                . 'E' . str_pad(strval(intval($row['episode'] ?? 0)), 2, '0', STR_PAD_LEFT);
            $label = $show . ' ' . $code;
            if ($title != '') {
                $label .= ' ' . $title;
            }
        }

        return [
            'id'     => $id,
            'path'   => strval($row['path'] ?? ''),
            'label'  => $label,
            'flag'   => $flag,
            'remote' => $remote,
        ];
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

            $status   = $mediaApps->getWatchStatus($app, $user['remote_id'], $name, $user['id'], $user);
            $platform = intval($app['platform']);
            foreach (['movies' => 'movie', 'episodes' => 'episode'] as $bucket => $kind) {
                foreach ($status[$bucket] ?? [] as $remoteId => $watch) {
                    $item = $index[$kind]['remote'][$platform][strval($remoteId)] ?? [];
                    if (!$item) {
                        continue;
                    }
                    $key       = $kind . ':' . intval($item['id']);
                    $sourceKey = intval($app['id']) . ':' . intval($user['id']);
                    $watch     = [
                        'started'    => intval($watch['started'] ?? 0) ? 1 : 0,
                        'inprogress' => intval($watch['inprogress'] ?? 0),
                        'finished'   => intval($watch['finished'] ?? 0),
                    ];
                    if (empty($incoming[$key]['sources'][$sourceKey])) {
                        $incoming[$key]['item']                = $item;
                        $incoming[$key]['kind']                = $kind;
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
        foreach ($incoming as $itemIncoming) {
            $this->stopIfCancelled();
            $kind   = $itemIncoming['kind'];
            $item   = $itemIncoming['item'];
            $merged = [];
            foreach ($members as $member) {
                $row = $existing[intval($member['user']['id'])][$kind][intval($item['id'])] ?? [];
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
                    ];
                }
                if ($remote == '' || empty($item['flag'][$platform])) {
                    if (!watchStateSatisfies($gathered ?? [], $merged)) {
                        $counts[$countKey]['skipped']++;
                        if ($dry) {
                            $drySkips[] = [
                                'kind'    => $kind,
                                'item_id' => intval($item['id']),
                                'label'   => $item['label'],
                                'app'     => $app,
                                'user'    => $user,
                                'watch'   => $merged,
                                'reason'  => 'not linked on this app library in Watchsync',
                            ];
                        }
                    }
                    continue;
                }

                $row       = $existing[$userId][$kind][intval($item['id'])] ?? [];
                $dbChanged = !$row || !watchStatesEqual($row, $merged);
                $needsPush = $both && !watchStateSatisfies($gathered ?? [], $merged);
                if ($dbChanged && !$dry) {
                    $saved                                         = $kind == 'movie'
                        ? $this->database->saveUserMovieLink($row, $item['id'], $userId, $platform, $merged['started'], $merged['inprogress'], $merged['finished'])
                        : $this->database->saveUserEpisodeLink($row, $item['id'], $userId, $platform, $merged['started'], $merged['inprogress'], $merged['finished']);
                    $existing[$userId][$kind][intval($item['id'])] = [
                        'id'         => intval($saved['id'] ?? 0),
                        'item_id'    => intval($item['id']),
                        'started'    => intval($merged['started'] ?? 0),
                        'inprogress' => intval($merged['inprogress'] ?? 0),
                        'finished'   => intval($merged['finished'] ?? 0),
                    ];
                    $this->logWatchSetAction($kind, intval($item['id']), $merged, $user['username'] ?? '', $app['name'] ?? '', APP_NAME, false);
                }
                if ($needsPush && !$dry) {
                    $this->logWatchSetAction($kind, intval($item['id']), $merged, $user['username'] ?? '', APP_NAME, $app['name'] ?? '', false);
                    $mediaApps->setWatchStatus($app, $user, $remote, $merged['started'], $merged['inprogress'], $merged['finished']);
                    $this->addStat('pushed', 1, $app);
                }
                if ($dry && ($dbChanged || $needsPush)) {
                    $dryPlans[] = [
                        'kind'       => $kind,
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
                    $this->addHistoryResult($app, $user['username'] ?? '', $kind, 'unchanged');
                    $this->addStat('unchanged', 1, $app);
                } else {
                    $this->addHistoryResult($app, $user['username'] ?? '', $kind, $status);
                    if ($kind == 'movie') {
                        $counts[$countKey][$status == 'finished' ? 'movies_finished' : 'movies_progress']++;
                    } else {
                        $counts[$countKey][$status == 'finished' ? 'episodes_finished' : 'episodes_progress']++;
                    }
                }

                $summaryKey = strval($app['name'] ?? '') . "\0" . $this->appRoleLabel($app) . "\0" . strval($user['username'] ?? '');
                $dbRows     = $this->historySummaryRow($dbRows, $summaryKey, $app['name'] ?? '', $this->appRoleLabel($app), $user['username'] ?? '', true);
                if ($dbChanged) {
                    $dbRows[$summaryKey][$this->historyChangeColumn($kind, $merged)]++;
                } else {
                    $dbRows[$summaryKey][7]++;
                }
                if ($both) {
                    $pushRows = $this->historySummaryRow($pushRows, $summaryKey, $app['name'] ?? '', $this->appRoleLabel($app), $user['username'] ?? '', false);
                    if ($needsPush) {
                        $pushRows[$summaryKey][$this->historyChangeColumn($kind, $merged)]++;
                    } else {
                        $pushRows[$summaryKey][7]++;
                    }
                }
            }
        }

        if (!$debug) {
            foreach ($counts as $count) {
                logger($this->logfile, $count['app']['name'] . ' / ' . $count['library'] . ' / ' . $count['user']
                    . ' movies finished=' . $count['movies_finished']
                    . ' in progress=' . $count['movies_progress']
                    . ' episodes finished=' . $count['episodes_finished']
                    . ' in progress=' . $count['episodes_progress']
                    . ' unchanged=' . $count['unchanged']
                    . ' skipped=' . $count['skipped']);
                loggerFlush($this->logfile);
            }
        }
    }

    public function dryRunItemLabel($kind, $itemId)
    {
        $item = $this->sidecar['watch_index'][$kind]['id'][intval($itemId)] ?? [];
        if (!empty($item['label'])) {
            return $item['label'];
        }

        return ($kind == 'movie' ? 'movie #' : 'episode #') . intval($itemId);
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

    public function logWatchSetAction($kind, $itemId, $watch, $username = '', $fromApp = '', $toApp = '', $unchanged = false)
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

        $label = $this->dryRunItemLabel($kind, $itemId);
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
            $labelKey = ($plan['kind'] ?? '') . ':' . intval($plan['item_id'] ?? 0);
            if (!isset($labels[$labelKey])) {
                $labels[$labelKey] = $this->dryRunItemLabel($plan['kind'] ?? '', intval($plan['item_id'] ?? 0));
            }
            $itemLabel  = $labels[$labelKey];
            $watchLabel = $this->dryRunWatchLabel($plan['watch'] ?? []);

            if (!empty($plan['db_changed'])) {
                $changeLines[] = 'Save to Watchsync for ' . $user . ' on ' . $app . ' (' . $role . '): set ' . $itemLabel . ' to ' . $watchLabel;
            }

            if ($mode == MediaSyncModes::BOTH && !empty($plan['needs_push'])) {
                $changeLines[] = 'Push to ' . $app . ' (' . $role . ') for ' . $user . ': set ' . $itemLabel . ' to ' . $watchLabel;
            }
        }

        foreach ($skips as $skip) {
            $labelKey = ($skip['kind'] ?? '') . ':' . intval($skip['item_id'] ?? 0);
            if (!isset($labels[$labelKey])) {
                $labels[$labelKey] = $this->dryRunItemLabel($skip['kind'] ?? '', intval($skip['item_id'] ?? 0));
            }
            $app         = $skip['app']['name'] ?? '';
            $role        = $this->appRoleLabel($skip['app'] ?? []);
            $user        = $skip['user']['username'] ?? '';
            $skipLines[] = 'Skipped ' . $labels[$labelKey] . ' for ' . $user . ' on ' . $app . ' (' . $role . '): ' . ($skip['reason'] ?? 'unavailable');
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
        $lines[] = 'No changes were written to Watchsync or any media app.';
        $lines[] = '';
        $lines[] = 'CHANGES';
        if (!$changeLines) {
            $lines[] = '- No changes.';
        } else {
            foreach ($changeLines as $changeLine) {
                $lines[] = '- ' . $changeLine;
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

        if ($mode == MediaSyncModes::BOTH) {
            $lines[] = '';
            $lines[] = 'APP PUSHES (writes to main source / listeners)';
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

    public function historyChangeColumn($kind, $watch)
    {
        $finished = intval($watch['finished'] ?? 0) > 0;
        if (($kind ?? '') == 'movie') {
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
        $lines   = [];
        $lines[] = '';
        $lines[] = 'WATCHSTATE (Watchsync database updates)';
        foreach (asciiTable($this->historyTableHeaders(true), $tables['dbRows']) as $line) {
            $lines[] = $line;
        }

        if (intval($mode) == MediaSyncModes::BOTH || $tables['pushRows']) {
            $lines[] = '';
            $lines[] = 'APP PUSHES (writes to main source / listeners)';
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
            $column = $this->historyChangeColumn($plan['kind'] ?? '', $plan['watch'] ?? []);
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
            $this->logWatchSetAction($change['kind'] ?? '', intval($change['item_id'] ?? 0), [
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
                foreach (['movie' => 'movies', 'episode' => 'episodes'] as $kind => $bucket) {
                    foreach ($state[$kind] as $itemId => $link) {
                        $this->stopIfCancelled();
                        $item   = $index[$kind]['id'][intval($itemId)] ?? [];
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
                            if ($kind == 'movie') {
                                $unchangedM++;
                            } else {
                                $unchangedE++;
                            }
                            continue;
                        }
                        if (!$dry) {
                            $this->logWatchSetAction($kind, intval($itemId), $link, $user['username'] ?? '', APP_NAME, $mediaApp['name'] ?? '');
                            $mediaApps->setWatchStatus($mediaApp, $user, $remote, $link['started'], $link['inprogress'], $link['finished']);
                            $statusName = $this->watchResultStatus($link);
                            if ($statusName == '' || $statusName == 'started') {
                                $statusName = 'inProgress';
                            }
                            $this->addHistoryResult($mediaApp, $user['username'] ?? '', $kind, $statusName);
                        }
                        $finishedKey = $kind == 'movie' ? 'movies_finished' : 'episodes_finished';
                        $progressKey = $kind == 'movie' ? 'movies_progress' : 'episodes_progress';
                        if (intval($link['finished'] ?? 0) > 0) {
                            $libraries[$library][$finishedKey]++;
                            if ($kind == 'movie') {
                                $moviesFin++;
                            } else {
                                $episodesFin++;
                            }
                        } else {
                            $libraries[$library][$progressKey]++;
                            if ($kind == 'movie') {
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
