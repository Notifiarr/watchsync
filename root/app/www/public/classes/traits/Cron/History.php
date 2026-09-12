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
        $apps       = [];
        foreach ($this->database->getMediaApps() as $mediaApp) {
            if ($mediaAppId) {
                if ($mediaApp['id'] == $mediaAppId) {
                    $apps[] = $mediaApp;
                }
                continue;
            }
            if (!$mediaApp['active']) {
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

        $mode     = intval($mode);
        $minimum  = $mode == MediaSyncModes::BOTH ? 2 : 1;
        $apps     = $this->onlineMediaApps($this->watchApps(), $minimum);
        if (!$apps) {
            return;
        }

        if ($mode == MediaSyncModes::PUSH) {
            $this->pushWatch($apps);
            return;
        }

        $gathered = $this->gatherWatch($apps);
        $merged   = $this->mergeWatchGather($gathered);
        $planned  = $this->planWatchMerge($merged, $gathered['members']);
        $plans    = $planned['plans'];
        $skips    = $planned['skips'];
        logger($this->logfile, 'watch gather=' . intval($gathered['count'] ?? 0) . ' merged=' . count($merged));

        if ($this->isDryRun()) {
            $this->logDryRunSummary($plans, $mode, $skips);
            return;
        }

        $this->logWatchGatherActions($gathered);
        $changes = $this->applyWatchMerge($plans, $mode == MediaSyncModes::BOTH);
        logger($this->logfile, 'watch changed=' . count($changes));

        if ($mode == MediaSyncModes::BOTH) {
            $this->pushWatchChanges($changes);
        }
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

    public function gatherWatch($apps = null)
    {
        global $mediaApps;

        $observations = [];
        $members      = [];
        $count        = 0;
        if ($apps == null) {
            $apps = $this->watchApps();
        }

        foreach ($apps as $mediaApp) {
            $this->stopIfCancelled();
            foreach ($this->selectedUsers($mediaApp['id']) as $user) {
                $this->stopIfCancelled();
                $masterId  = $this->watchMasterUserId($user['id']);
                $memberKey = $masterId . ':' . intval($mediaApp['id']) . ':' . intval($user['id']);
                $members[$masterId][$memberKey] = [
                    'app'  => $mediaApp,
                    'user' => $user,
                ];

                logger($this->logfile, 'gather ' . $mediaApp['name'] . ' ' . $user['username']);
                $status   = $mediaApps->getWatchStatus($mediaApp, $user['remote_id'], $user['username'], $user['id']);
                $movies   = 0;
                $episodes = 0;

                foreach ($status['movies'] ?? [] as $remoteId => $watch) {
                    $this->stopIfCancelled();
                    $movie = $this->database->getMovieByRemoteId($mediaApp['platform'], $remoteId);
                    if (!$movie) {
                        continue;
                    }
                    $observations[] = [
                        'master_user_id' => $masterId,
                        'kind'           => 'movie',
                        'item_id'        => intval($movie['id']),
                        'app_id'         => intval($mediaApp['id']),
                        'app_name'       => strval($mediaApp['name'] ?? ''),
                        'user_id'        => intval($user['id']),
                        'username'       => strval($user['username'] ?? ''),
                        'platform'       => intval($mediaApp['platform']),
                        'remote_id'      => strval($remoteId),
                        'watch'          => [
                            'started'    => intval($watch['started'] ?? 0) ? 1 : 0,
                            'inprogress' => intval($watch['inprogress'] ?? 0),
                            'finished'   => intval($watch['finished'] ?? 0),
                        ],
                    ];
                    $movies++;
                    $count++;
                    $this->addStat('pulled', 1, $mediaApp);
                }

                foreach ($status['episodes'] ?? [] as $remoteId => $watch) {
                    $this->stopIfCancelled();
                    $episode = $this->database->getEpisodeByRemoteId($mediaApp['platform'], $remoteId);
                    if (!$episode) {
                        continue;
                    }
                    $observations[] = [
                        'master_user_id' => $masterId,
                        'kind'           => 'episode',
                        'item_id'        => intval($episode['id']),
                        'app_id'         => intval($mediaApp['id']),
                        'app_name'       => strval($mediaApp['name'] ?? ''),
                        'user_id'        => intval($user['id']),
                        'username'       => strval($user['username'] ?? ''),
                        'platform'       => intval($mediaApp['platform']),
                        'remote_id'      => strval($remoteId),
                        'watch'          => [
                            'started'    => intval($watch['started'] ?? 0) ? 1 : 0,
                            'inprogress' => intval($watch['inprogress'] ?? 0),
                            'finished'   => intval($watch['finished'] ?? 0),
                        ],
                    ];
                    $episodes++;
                    $count++;
                    $this->addStat('pulled', 1, $mediaApp);
                }

                logger($this->logfile, 'gather ' . $user['username'] . ' movies=' . $movies . ' episodes=' . $episodes);
            }
        }

        return [
            'observations' => $observations,
            'members'      => $members,
            'count'        => $count,
        ];
    }

    public function mergeWatchGather($gathered)
    {
        $items = [];
        foreach ($gathered['observations'] ?? [] as $observation) {
            $key = $observation['master_user_id'] . ':' . $observation['kind'] . ':' . $observation['item_id'];
            if (empty($items[$key])) {
                $items[$key] = [
                    'master_user_id' => $observation['master_user_id'],
                    'kind'           => $observation['kind'],
                    'item_id'        => $observation['item_id'],
                    'watch'          => [],
                    'sources'        => [],
                ];
            }
            $items[$key]['sources'][] = $observation;
        }

        foreach ($items as $key => &$item) {
            $this->stopIfCancelled();
            $watch = [];
            foreach ($gathered['members'][$item['master_user_id']] ?? [] as $member) {
                $existing = $item['kind'] == 'movie'
                    ? $this->database->getUserMovieLink($item['item_id'], $member['user']['id'], $member['app']['platform'])
                    : $this->database->getUserEpisodeLink($item['item_id'], $member['user']['id'], $member['app']['platform']);
                if ($existing) {
                    $watch = mergeWatchState($watch, $existing);
                }
            }
            foreach ($item['sources'] as $source) {
                $watch = mergeWatchState($watch, $source['watch']);
            }
            $item['watch'] = $watch;
        }
        unset($item);

        return $items;
    }

    public function planWatchMerge($merged, $members)
    {
        $plans = [];
        $skips = [];
        foreach ($merged as $item) {
            $this->stopIfCancelled();
            $sourceByTarget = [];
            foreach ($item['sources'] as $source) {
                $sourceKey = intval($source['app_id']) . ':' . intval($source['user_id']);
                if (empty($sourceByTarget[$sourceKey])) {
                    $sourceByTarget[$sourceKey] = $source['watch'];
                    continue;
                }
                $sourceByTarget[$sourceKey] = mergeWatchState($sourceByTarget[$sourceKey], $source['watch']);
            }

            foreach ($members[$item['master_user_id']] ?? [] as $member) {
                $app      = $member['app'];
                $user     = $member['user'];
                $platform = intval($app['platform']);
                $flag     = $this->database->mediaLibraryFlag($platform);
                $field    = $this->database->mediaLibraryRemoteField($platform);
                $library  = $item['kind'] == 'movie'
                    ? $this->database->getMovie($item['item_id'])
                    : $this->database->getEpisode($item['item_id']);
                if (!$library || empty($library[$flag]) || ($library[$field] ?? '') == '') {
                    $sourceKey = intval($app['id']) . ':' . intval($user['id']);
                    $gathered  = $sourceByTarget[$sourceKey] ?? null;
                    if (!watchStateSatisfies($gathered ?? [], $item['watch'])) {
                        $skips[] = [
                            'kind'    => $item['kind'],
                            'item_id' => $item['item_id'],
                            'app'     => $app,
                            'user'    => $user,
                            'watch'   => $item['watch'],
                            'reason'  => 'not linked on this app library in Watchsync',
                        ];
                    }
                    continue;
                }

                $existing = $item['kind'] == 'movie'
                    ? $this->database->getUserMovieLink($item['item_id'], $user['id'], $platform)
                    : $this->database->getUserEpisodeLink($item['item_id'], $user['id'], $platform);
                $dbChanged = !$existing || !watchStatesEqual($existing, $item['watch']);
                $sourceKey = intval($app['id']) . ':' . intval($user['id']);
                $gathered  = $sourceByTarget[$sourceKey] ?? null;
                $needsPush = !watchStateSatisfies($gathered ?? [], $item['watch']);

                $plans[] = [
                    'kind'       => $item['kind'],
                    'item_id'    => $item['item_id'],
                    'app'        => $app,
                    'user'       => $user,
                    'remote_id'  => strval($library[$field]),
                    'watch'      => $item['watch'],
                    'db_changed' => $dbChanged,
                    'needs_push' => $needsPush,
                ];
            }
        }

        return [
            'plans' => $plans,
            'skips' => $skips,
        ];
    }

    public function applyWatchMerge($plans, $willPush = false)
    {
        $changes = [];
        foreach ($plans as $plan) {
            $this->stopIfCancelled();
            if ($plan['kind'] == 'movie') {
                $this->database->upsertUserMovieLink($plan['item_id'], $plan['user']['id'], $plan['app']['platform'], $plan['watch']['started'], $plan['watch']['inprogress'], $plan['watch']['finished']);
            } else {
                $this->database->upsertUserEpisodeLink($plan['item_id'], $plan['user']['id'], $plan['app']['platform'], $plan['watch']['started'], $plan['watch']['inprogress'], $plan['watch']['finished']);
            }
            if (!empty($plan['db_changed']) || !empty($plan['needs_push'])) {
                $status = $this->watchResultStatus($plan['watch'] ?? []);
                if ($status != '') {
                    $appName = $plan['app']['name'] ?? '';
                    $seenKey = $appName . ':' . ($plan['user']['username'] ?? '') . ':' . $plan['kind'] . ':' . intval($plan['item_id']) . ':' . $status;
                    if (empty($this->sidecar['stats']['_historySeen'][$seenKey])) {
                        $this->sidecar['stats']['_historySeen'][$seenKey] = true;
                        $this->addHistoryResult($plan['app'], $plan['user']['username'] ?? '', $plan['kind'], $status);
                    }
                }
            }
            if (empty($plan['needs_push'])) {
                continue;
            }
            $changes[] = [
                'app'        => $plan['app'],
                'user'       => $plan['user'],
                'kind'       => $plan['kind'],
                'item_id'    => intval($plan['item_id']),
                'remote_id'  => $plan['remote_id'],
                'started'    => $plan['watch']['started'],
                'inprogress' => $plan['watch']['inprogress'],
                'finished'   => $plan['watch']['finished'],
            ];
        }

        return $changes;
    }

    public function dryRunItemLabel($kind, $itemId)
    {
        if ($kind == 'movie') {
            $movie = $this->database->getMovie($itemId);
            if (!$movie) {
                return 'movie #' . intval($itemId);
            }
            $title = trim($movie['title'] ?? '');
            $year  = intval($movie['year'] ?? 0);
            if ($title == '') {
                $title = 'movie #' . intval($itemId);
            }

            return $year ? ($title . ' (' . $year . ')') : $title;
        }

        $episode = $this->database->getEpisode($itemId);
        if (!$episode) {
            return 'episode #' . intval($itemId);
        }
        $series = $this->database->getSeries(intval($episode['series_id'] ?? 0));
        $show   = trim($series['title'] ?? '');
        if ($show == '') {
            $show = 'series #' . intval($episode['series_id'] ?? 0);
        }
        $season  = intval($episode['season'] ?? 0);
        $number  = intval($episode['episode'] ?? 0);
        $epTitle = trim($episode['title'] ?? '');
        $code    = 'S' . str_pad(strval($season), 2, '0', STR_PAD_LEFT) . 'E' . str_pad(strval($number), 2, '0', STR_PAD_LEFT);
        $label   = $show . ' ' . $code;
        if ($epTitle != '') {
            $label .= ' ' . $epTitle;
        }

        return $label;
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

    public function logWatchGatherActions($gathered)
    {
        $groups = [];
        foreach ($gathered['observations'] ?? [] as $observation) {
            $watch    = is_array($observation['watch'] ?? null) ? $observation['watch'] : [];
            $finished = intval($watch['finished'] ?? 0);
            $progress = intval($watch['inprogress'] ?? 0);
            if ($finished <= 0 && $progress <= 0) {
                continue;
            }

            $watchKey = $finished > 0 ? 'complete' : ('inprogress:' . $progress);
            $key      = intval($observation['master_user_id'] ?? 0)
                . ':' . ($observation['kind'] ?? '')
                . ':' . intval($observation['item_id'] ?? 0)
                . ':' . $watchKey;

            if (empty($groups[$key])) {
                $groups[$key] = [
                    'kind'     => $observation['kind'] ?? '',
                    'item_id'  => intval($observation['item_id'] ?? 0),
                    'watch'    => $watch,
                    'username' => strval($observation['username'] ?? ''),
                    'from'     => [],
                ];
            } else {
                $groups[$key]['watch'] = mergeWatchState($groups[$key]['watch'], $watch);
            }

            $appName = trim(strval($observation['app_name'] ?? ''));
            if ($appName != '' && !in_array($appName, $groups[$key]['from'], true)) {
                $groups[$key]['from'][] = $appName;
            }
        }

        foreach ($groups as $group) {
            $this->logWatchSetAction(
                $group['kind'],
                $group['item_id'],
                $group['watch'],
                $group['username'],
                implode(' + ', $group['from']),
                APP_NAME
            );
        }
    }

    public function logWatchSetAction($kind, $itemId, $watch, $username = '', $fromApp = '', $toApp = '')
    {
        $watch    = is_array($watch) ? $watch : [];
        $finished = intval($watch['finished'] ?? 0);
        $progress = intval($watch['inprogress'] ?? 0);
        if ($finished <= 0 && $progress <= 0) {
            return;
        }

        $label = $this->dryRunItemLabel($kind, $itemId);
        if ($finished > 0) {
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

    public function logDryRunSummary($plans, $mode, $skips = [])
    {
        global $mediaApps;

        $modeName   = $mediaApps->getSyncModeName($mode);
        $dbRows     = [];
        $pushRows   = [];
        $dbMovies   = 0;
        $dbEps      = 0;
        $pushMovies = 0;
        $pushEps    = 0;
        $changeLines = [];
        $skipLines   = [];
        $labels      = [];

        foreach ($plans as $plan) {
            $app     = $plan['app']['name'] ?? '';
            $role    = $this->appRoleLabel($plan['app']);
            $user    = $plan['user']['username'] ?? '';
            $key     = $app . "\0" . $role . "\0" . $user;
            $isMovie = ($plan['kind'] ?? '') == 'movie';
            $labelKey = ($plan['kind'] ?? '') . ':' . intval($plan['item_id'] ?? 0);
            if (!isset($labels[$labelKey])) {
                $labels[$labelKey] = $this->dryRunItemLabel($plan['kind'] ?? '', intval($plan['item_id'] ?? 0));
            }
            $itemLabel  = $labels[$labelKey];
            $watchLabel = $this->dryRunWatchLabel($plan['watch'] ?? []);

            if (!empty($plan['db_changed'])) {
                if (empty($dbRows[$key])) {
                    $dbRows[$key] = [$app, $role, $user, 0, 0];
                }
                if ($isMovie) {
                    $dbRows[$key][3]++;
                    $dbMovies++;
                } else {
                    $dbRows[$key][4]++;
                    $dbEps++;
                }
                $changeLines[] = 'Save to Watchsync for ' . $user . ' on ' . $app . ' (' . $role . '): set ' . $itemLabel . ' to ' . $watchLabel;
            }

            if ($mode == MediaSyncModes::BOTH && !empty($plan['needs_push'])) {
                if (empty($pushRows[$key])) {
                    $pushRows[$key] = [$app, $role, $user, 0, 0];
                }
                if ($isMovie) {
                    $pushRows[$key][3]++;
                    $pushMovies++;
                } else {
                    $pushRows[$key][4]++;
                    $pushEps++;
                }
                $changeLines[] = 'Push to ' . $app . ' (' . $role . ') for ' . $user . ': set ' . $itemLabel . ' to ' . $watchLabel;
            }
        }

        foreach ($skips as $skip) {
            $labelKey = ($skip['kind'] ?? '') . ':' . intval($skip['item_id'] ?? 0);
            if (!isset($labels[$labelKey])) {
                $labels[$labelKey] = $this->dryRunItemLabel($skip['kind'] ?? '', intval($skip['item_id'] ?? 0));
            }
            $app  = $skip['app']['name'] ?? '';
            $role = $this->appRoleLabel($skip['app'] ?? []);
            $user = $skip['user']['username'] ?? '';
            $skipLines[] = 'Skipped ' . $labels[$labelKey] . ' for ' . $user . ' on ' . $app . ' (' . $role . '): ' . ($skip['reason'] ?? 'unavailable');
        }

        $lines   = [];
        $lines[] = '';
        $lines[] = str_repeat('=', 72);
        $lines[] = 'DRY RUN SUMMARY - what a live run would have done';
        $lines[] = str_repeat('=', 72);
        $lines[] = 'Mode: ' . $modeName;
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
        $lines[] = '';
        $lines[] = 'WATCHSTATE (Watchsync database updates)';
        foreach (asciiTable(['App', 'Role', 'User', 'Movies', 'Episodes'], array_values($dbRows)) as $line) {
            $lines[] = $line;
        }
        $lines[] = 'Watchstate total: ' . ($dbMovies + $dbEps) . '  (movies=' . $dbMovies . ', episodes=' . $dbEps . ')';

        if ($mode == MediaSyncModes::BOTH) {
            $lines[] = '';
            $lines[] = 'APP PUSHES (writes to main source / listeners)';
            foreach (asciiTable(['App', 'Role', 'User', 'Movies', 'Episodes'], array_values($pushRows)) as $line) {
                $lines[] = $line;
            }
            $lines[] = 'App push total: ' . ($pushMovies + $pushEps) . '  (movies=' . $pushMovies . ', episodes=' . $pushEps . ')';
        }

        $lines[] = str_repeat('=', 72);
        $lines[] = '';

        $this->sidecar['dry_run_summary'] = $lines;
    }

    public function logDryRunPushSummary($byApp)
    {
        $rows    = [];
        $movies  = 0;
        $eps     = 0;
        foreach ($byApp as $app) {
            foreach ($app['users'] as $username => $counts) {
                $rowMovies = intval($counts['movies'] ?? 0);
                $rowEps    = intval($counts['episodes'] ?? 0);
                if (!$rowMovies && !$rowEps) {
                    continue;
                }
                $rows[] = [$app['name'], $app['role'], $username, $rowMovies, $rowEps];
                $movies += $rowMovies;
                $eps    += $rowEps;
            }
        }

        $lines   = [];
        $lines[] = '';
        $lines[] = str_repeat('=', 72);
        $lines[] = 'DRY RUN SUMMARY - what a live run would have done';
        $lines[] = str_repeat('=', 72);
        $lines[] = 'Mode: ' . translate('pushOnly');
        $lines[] = 'No changes were written to any media app.';
        $lines[] = '';
        $lines[] = 'APP PUSHES (writes to main source / listeners)';
        foreach (asciiTable(['App', 'Role', 'User', 'Movies', 'Episodes'], $rows) as $line) {
            $lines[] = $line;
        }
        $lines[] = 'App push total: ' . ($movies + $eps) . '  (movies=' . $movies . ', episodes=' . $eps . ')';
        $lines[] = str_repeat('=', 72);
        $lines[] = '';

        $this->sidecar['dry_run_summary'] = $lines;
    }

    public function pushWatchChanges($changes)
    {
        global $mediaApps;

        $pushed = 0;
        $byUser = [];
        foreach ($changes as $change) {
            $this->stopIfCancelled();
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
                $change['finished']
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

    public function pushWatch($apps = null)
    {
        global $mediaApps;

        $dry   = $this->isDryRun();
        $byApp = [];
        if ($apps == null) {
            $apps = $this->watchApps();
        }

        foreach ($apps as $mediaApp) {
            $this->stopIfCancelled();
            $flag   = $this->database->mediaLibraryFlag($mediaApp['platform']);
            $field  = $this->database->mediaLibraryRemoteField($mediaApp['platform']);
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
                logger($this->logfile, ($dry ? 'would push ' : 'push ') . $mediaApp['name'] . ' ' . $user['username']);
                $movies   = 0;
                $episodes = 0;
                foreach ($this->database->getUserMovieLinks($user['id'], $mediaApp['platform']) as $link) {
                    $this->stopIfCancelled();
                    $movie = $this->database->getMovie($link['movie_id']);
                    if (!$movie || empty($movie[$flag]) || $movie[$field] == '') {
                        continue;
                    }
                    if (!$dry) {
                        $this->logWatchSetAction('movie', intval($link['movie_id']), $link, $user['username'] ?? '', APP_NAME, $mediaApp['name'] ?? '');
                        $mediaApps->setWatchStatus($mediaApp, $user, $movie[$field], $link['started'], $link['inprogress'], $link['finished']);
                        $status = $this->watchResultStatus($link);
                        if ($status != '') {
                            $seenKey = ($mediaApp['name'] ?? '') . ':' . ($user['username'] ?? '') . ':movie:' . intval($link['movie_id']) . ':' . $status;
                            if (empty($this->sidecar['stats']['_historySeen'][$seenKey])) {
                                $this->sidecar['stats']['_historySeen'][$seenKey] = true;
                                $this->addHistoryResult($mediaApp, $user['username'] ?? '', 'movie', $status);
                            }
                        }
                    }
                    $movies++;
                }
                foreach ($this->database->getUserEpisodeLinks($user['id'], $mediaApp['platform']) as $link) {
                    $this->stopIfCancelled();
                    $episode = $this->database->getEpisode($link['episode_id']);
                    if (!$episode || empty($episode[$flag]) || $episode[$field] == '') {
                        continue;
                    }
                    if (!$dry) {
                        $this->logWatchSetAction('episode', intval($link['episode_id']), $link, $user['username'] ?? '', APP_NAME, $mediaApp['name'] ?? '');
                        $mediaApps->setWatchStatus($mediaApp, $user, $episode[$field], $link['started'], $link['inprogress'], $link['finished']);
                        $status = $this->watchResultStatus($link);
                        if ($status != '') {
                            $seenKey = ($mediaApp['name'] ?? '') . ':' . ($user['username'] ?? '') . ':episode:' . intval($link['episode_id']) . ':' . $status;
                            if (empty($this->sidecar['stats']['_historySeen'][$seenKey])) {
                                $this->sidecar['stats']['_historySeen'][$seenKey] = true;
                                $this->addHistoryResult($mediaApp, $user['username'] ?? '', 'episode', $status);
                            }
                        }
                    }
                    $episodes++;
                }
                $this->addStat('pushed', $movies + $episodes, $mediaApp);
                $byApp[$appKey]['users'][$user['username']] = [
                    'movies'   => $movies,
                    'episodes' => $episodes,
                ];
                logger($this->logfile, ($dry ? 'would push ' : 'push ') . $user['username'] . ' movies=' . $movies . ' episodes=' . $episodes);
            }
        }

        if ($dry) {
            $this->logDryRunPushSummary($byApp);
        }
    }
}
