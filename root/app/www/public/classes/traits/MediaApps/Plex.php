<?php

/*
----------------------------------
------  Created: 091026   ------
------  Austin Best       ------
----------------------------------
*/

trait Plex
{
    protected $plexSharedLibraries;
    protected $plexTvSections;
    protected $plexAccounts;
    public function plexIsOnline($url)
    {
        $url  = rtrim(trim($url), '/');
        $curl = curl(sprintf(MediaAppEndpoints::ENDPOINT_PLEX_ONLINE, $url), $this->plexHeaders(''), 'GET', '', [], 3);

        return $curl['code'] >= 200 && $curl['code'] <= 299;
    }

    public function plexTestConnection($url, $token)
    {
        $url = rtrim(trim($url), '/');
        if (!$url || !$token) {
            return ['error' => true, 'message' => translate('missingConnectionFields')];
        }

        $headers  = $this->plexHeaders($token);
        $curl     = curl(sprintf(MediaAppEndpoints::ENDPOINT_PLEX_SERVER, $url), $headers, 'GET');
        $response = $this->parsePlexResponse($curl);

        if ($curl['code'] == 401) {
            return ['error' => true, 'message' => translate('mediaAppInvalidToken')];
        }
        if ($curl['code'] == 404) {
            return ['error' => true, 'message' => translate('mediaAppInvalidUrl')];
        }
        if ($curl['code'] < 200 || $curl['code'] > 299 || !$response) {
            return ['error' => true, 'message' => $curl['error'] ?: translate('mediaAppConnectionFailed')];
        }

        $container  = $response['MediaContainer'] ?? $response;
        $serverId   = $container['machineIdentifier'] ?? '';
        $serverName = $container['friendlyName'] ?? '';
        if (!$serverId) {
            return ['error' => true, 'message' => translate('mediaAppConnectionFailed')];
        }

        $users = $this->plexGetUsers($url, $token, $serverId);
        if ($users['error']) {
            return $users;
        }

        return [
            'error'       => false,
            'server_id'   => $serverId,
            'server_name' => $serverName,
            'users'       => $users['users'],
        ];
    }

    public function plexGetLibraries($url, $token)
    {
        $url         = rtrim(trim($url), '/');
        $headers     = $this->plexHeaders($token);
        $curl        = curl(sprintf(MediaAppEndpoints::ENDPOINT_PLEX_LIBRARIES, $url), $headers, 'GET');
        $response    = $this->parsePlexResponse($curl);
        $directories = $response['MediaContainer']['Directory'] ?? [];
        if ($directories && !isset($directories[0])) {
            $directories = [$directories];
        }

        $libraries = [];
        if (!is_array($directories)) {
            return $libraries;
        }

        foreach ($directories as $directory) {
            $type = $this->plexValue($directory, 'type');
            if ($type != 'movie' && $type != 'show') {
                continue;
            }
            $locations = $directory['Location'] ?? [];
            if ($locations && !isset($locations[0])) {
                $locations = [$locations];
            }
            $paths = [];
            if (is_array($locations)) {
                foreach ($locations as $location) {
                    $path = $this->plexValue($location, 'path');
                    if ($path != '') {
                        $paths[] = $path;
                    }
                }
            }
            $libraries[] = [
                'key'        => $this->plexValue($directory, 'key'),
                'title'      => $this->plexValue($directory, 'title'),
                'type'       => $type,
                'paths'      => $paths,
                'updated_at' => intval($this->plexValue($directory, 'updatedAt')),
                'scanned_at' => intval($this->plexValue($directory, 'scannedAt')),
            ];
        }

        return $libraries;
    }

    public function plexGetItems($url, $token, $libraryKeys = [], $seriesRemoteId = '', $seriesTitle = '', $since = 0)
    {
        $items = [
            'movies'   => [],
            'series'   => [],
            'episodes' => [],
        ];

        global $cron;

        $libraryTitle = '';
        $libraryKey   = '';
        foreach ($libraryKeys as $entry) {
            if (is_array($entry) && !empty($entry['key'])) {
                $libraryTitle = $entry['title'] ?? $libraryTitle;
                $libraryKey   = $entry['key'];
            } else if (!is_array($entry) && $entry != '' && $entry != null) {
                $libraryKey = $entry;
            }
        }

        if ($seriesRemoteId) {
            $episodeTitle = $seriesTitle ?: $seriesRemoteId;
            if (!empty($cron)) {
                $cron->stopIfCancelled();
                $cron->log('fetching ' . $episodeTitle . ' episodes');
            }
            foreach ($this->plexSectionItems($url, $token, $libraryKey, 4, $episodeTitle, $seriesRemoteId, $since) as $item) {
                $item['library'] = $libraryTitle;
                if (!($item['series'] ?? '')) {
                    $item['series'] = $seriesTitle;
                }
                if (!($item['series_remote_id'] ?? '')) {
                    $item['series_remote_id'] = $seriesRemoteId;
                }
                $items['episodes'][] = $item;
            }
            return $items;
        }

        $libraries = [];
        foreach ($libraryKeys as $entry) {
            if (is_array($entry) && !empty($entry['key'])) {
                $libraries[] = [
                    'key'   => $entry['key'],
                    'title' => $entry['title'] ?? '',
                    'type'  => $entry['type'] ?? '',
                ];
            } else if (!is_array($entry) && $entry != '' && $entry != null) {
                $libraries[] = [
                    'key'   => $entry,
                    'title' => '',
                    'type'  => '',
                ];
            }
        }

        $needList = !$libraries;
        foreach ($libraries as $library) {
            if (!$library['type']) {
                $needList = true;
                break;
            }
        }
        if ($needList) {
            if (!empty($cron)) {
                $cron->log('listing libraries');
            }
            $listed = [];
            foreach ($this->plexGetLibraries($url, $token) as $library) {
                if ($library['key']) {
                    $listed[$library['key']] = $library;
                }
            }
            if (!$libraries) {
                $libraries = array_values($listed);
            } else {
                foreach ($libraries as $index => $library) {
                    if (!empty($listed[$library['key']])) {
                        $libraries[$index] = $listed[$library['key']];
                    }
                }
            }
        }

        foreach ($libraries as $library) {
            if (!$library['key']) {
                continue;
            }
            if (!empty($cron)) {
                $cron->stopIfCancelled();
                $cron->log('fetching ' . ($library['title'] ?? $library['key']));
            }
            if ($library['type'] == 'movie') {
                foreach ($this->plexSectionItems($url, $token, $library['key'], 1, $library['title'] ?? '', '', $since) as $item) {
                    $item['library']   = $library['title'] ?? '';
                    $items['movies'][] = $item;
                }
            } else {
                foreach ($this->plexSectionItems($url, $token, $library['key'], 2, $library['title'] ?? '', '', $since) as $item) {
                    $item['library']   = $library['title'] ?? '';
                    $items['series'][] = $item;
                }
                foreach ($this->plexSectionItems($url, $token, $library['key'], 4, $library['title'] ?? '', '', $since) as $item) {
                    $item['library']     = $library['title'] ?? '';
                    $items['episodes'][] = $item;
                }
            }
        }

        return $items;
    }

    public function plexSectionItems($url, $token, $key, $type, $title = '', $seriesRemoteId = '', $since = 0)
    {
        global $cron;

        $url     = rtrim(trim($url), '/');
        $headers = $this->plexHeaders($token);
        $items   = [];
        $start   = 0;
        $size    = 500;
        $kind    = 'movies';
        if (intval($type) == 2) {
            $kind = 'series';
        } else if (intval($type) == 4) {
            $kind = 'episodes';
        }

        while (true) {
            if (!empty($cron)) {
                $cron->stopIfCancelled();
            }
            if ($seriesRemoteId && intval($type) == 4) {
                $request = sprintf(MediaAppEndpoints::ENDPOINT_PLEX_SERIES_EPISODES, $url, rawurlencode($seriesRemoteId), $start, $size);
            } else {
                $request = sprintf(MediaAppEndpoints::ENDPOINT_PLEX_LIBRARY_ITEMS, $url, $key, intval($type), $start, $size);
            }
            if (intval($since) > 0) {
                $request .= '&addedAt>=' . intval($since);
            }
            $curl      = curl($request, $headers, 'GET');
            $response  = $this->parsePlexResponse($curl);
            $container = $response['MediaContainer'] ?? $response;
            $rows      = $container['Metadata'] ?? ($container['Video'] ?? ($container['Directory'] ?? []));
            if ($rows && !isset($rows[0])) {
                $rows = [$rows];
            }
            if (!is_array($rows) || !$rows) {
                break;
            }

            foreach ($rows as $row) {
                $items[] = $this->plexLibraryItem($row, $type);
            }
            if (!empty($cron)) {
                $cron->log(($title ?: $key) . ' ' . $kind . ' ' . count($items));
            }

            if (count($rows) < $size) {
                break;
            }
            $start += $size;
        }

        return $items;
    }

    public function plexLibraryItem($row, $type)
    {
        $item = [
            'remote_id'        => $row['ratingKey'] ?? '',
            'title'            => $row['title'] ?? '',
            'year'             => intval($row['year'] ?? 0),
            'path'             => $this->plexItemPath($row),
            'series_remote_id' => $this->plexValue($row, 'grandparentRatingKey'),
            'series'           => $this->plexValue($row, 'grandparentTitle'),
            'season'           => intval($row['parentIndex'] ?? 0),
            'episode'          => intval($row['index'] ?? 0),
            'updated_at'       => intval($this->plexValue($row, 'updatedAt')),
            'poster'           => intval($type) == 4 ? '' : $this->plexValue($row, 'thumb'),
            'series_poster'    => $this->plexValue($row, 'grandparentThumb'),
            'duration'         => $this->plexRuntimeSeconds($row),
        ];

        if (intval($type) == 2) {
            $item['series_remote_id'] = $row['ratingKey'] ?? '';
            $item['series']           = $row['title'] ?? '';
            $item['season']           = 0;
            $item['episode']          = 0;
        }

        return $item;
    }

    public function plexUserMatchNames($user)
    {
        $names = [];
        foreach (['friendlyName', 'username', 'title', 'name', 'email'] as $key) {
            $name = trim(strval($user[$key] ?? ''));
            if ($name != '' && $name != '0' && !in_array($name, $names, true)) {
                $names[] = $name;
            }
        }

        return $names;
    }

    public function plexValue($row, $name)
    {
        if (!is_array($row)) {
            return '';
        }
        if (isset($row[$name]) && !is_array($row[$name]) && $row[$name] != '') {
            return $row[$name];
        }
        $attrs = $row['@attributes'] ?? [];
        if (is_array($attrs) && isset($attrs[$name]) && !is_array($attrs[$name]) && $attrs[$name] != '') {
            return $attrs[$name];
        }

        return '';
    }

    public function plexItemPath($item)
    {
        $media = $item['Media'] ?? [];
        if ($media && !isset($media[0])) {
            $media = [$media];
        }
        if (is_array($media)) {
            foreach ($media as $medium) {
                $parts = $medium['Part'] ?? [];
                if ($parts && !isset($parts[0])) {
                    $parts = [$parts];
                }
                if (!is_array($parts)) {
                    continue;
                }
                foreach ($parts as $part) {
                    $file = $this->plexValue($part, 'file');
                    if ($file != '') {
                        return $file;
                    }
                }
            }
        }

        $locations = $item['Location'] ?? [];
        if ($locations && !isset($locations[0])) {
            $locations = [$locations];
        }
        if (is_array($locations)) {
            foreach ($locations as $location) {
                $path = $this->plexValue($location, 'path');
                if ($path != '') {
                    return $path;
                }
            }
        }

        return '';
    }

    public function plexGetWatchStatus($url, $token, $remoteId, $username = '', $userId = 0)
    {
        $accountId = $this->plexAccountId($url, $token, $remoteId, $username);
        $status    = [
            'movies'   => [],
            'episodes' => [],
        ];
        if (!$accountId) {
            return $status;
        }
        if (intval($userId) && strval($accountId) != strval($remoteId)) {
            $this->database->updateMediaAppUserRemoteId($userId, $accountId);
        }

        global $cron;

        $url      = rtrim(trim($url), '/');
        $headers  = $this->plexHeaders($token);
        $size     = 500;
        $start    = 0;
        $lastPage = '';

        while (true) {
            if (!empty($cron)) {
                $cron->stopIfCancelled();
            }
            $curl      = curl(sprintf(MediaAppEndpoints::ENDPOINT_PLEX_HISTORY, $url, rawurlencode($accountId), $start, $size), $headers, 'GET');
            $response  = $this->parsePlexResponse($curl);
            $container = $response['MediaContainer'] ?? $response;
            $found     = $container['Metadata'] ?? [];
            if (!$found) {
                $found = $container['Video'] ?? [];
            }
            if ($found && !isset($found[0])) {
                $found = [$found];
            }
            $rows = is_array($found) ? $found : [];
            if (!$rows) {
                break;
            }

            $page = [];
            $kept = 0;
            foreach ($rows as $row) {
                $rowAccount = $this->plexValue($row, 'accountID');
                if ($rowAccount == '') {
                    $rowAccount = $this->plexValue($row, 'accountId');
                }
                if ($rowAccount == '' || strval($rowAccount) != strval($accountId)) {
                    continue;
                }
                $type = $this->plexValue($row, 'type');
                if ($type != 'movie' && $type != 'episode') {
                    continue;
                }
                $ratingKey = $this->plexValue($row, 'ratingKey');
                if ($ratingKey == '') {
                    $key = $this->plexValue($row, 'key');
                    if (preg_match('#/library/metadata/(\d+)#', $key, $matches)) {
                        $ratingKey = $matches[1];
                    }
                }
                if ($ratingKey == '') {
                    continue;
                }
                $kept++;
                $viewedAt   = $this->plexTimestamp($this->plexValue($row, 'viewedAt'));
                $page[]     = $ratingKey . ':' . $viewedAt;
                $bucket     = $type == 'episode' ? 'episodes' : 'movies';
                $viewOffset = intval($this->plexValue($row, 'viewOffset'));
                $inprogress = $viewOffset > 0 ? intval($viewOffset / 1000) : 0;
                $runtime    = $this->plexRuntimeSeconds($row);
                $isFinished = (intval($this->plexValue($row, 'viewCount')) > 0 || (!$inprogress && $viewedAt));
                $finished   = watchFinishedSeconds($isFinished, $runtime);
                $status[$bucket][$ratingKey] = mergeWatchState($status[$bucket][$ratingKey] ?? [], [
                    'started'    => 1,
                    'inprogress' => $finished ? 0 : $inprogress,
                    'finished'   => $finished,
                ]);
            }

            $totalSize = intval($this->plexValue($container, 'totalSize'));
            $seen      = $start + count($rows);
            if (!empty($cron)) {
                $cron->log('history ' . ($username ?: $accountId) . ' ' . $seen . ($totalSize ? '/' . $totalSize : '') . ' plays movies ' . count($status['movies']) . ' episodes ' . count($status['episodes']));
            }

            $pageMark = implode('|', $page);
            if (!$kept || $pageMark == '' || $pageMark == $lastPage) {
                break;
            }
            $lastPage  = $pageMark;
            $start    += count($rows);
            if (count($rows) < $size || ($totalSize && $start >= $totalSize)) {
                break;
            }
        }

        return $status;
    }

    public function plexRuntimeSeconds($row)
    {
        return mediaRuntimeSeconds($this->plexValue($row, 'duration'), 'ms');
    }

    public function plexSetWatchStatus($url, $token, $remoteId, $started, $inprogress, $finished)
    {
        $url     = rtrim(trim($url), '/');
        $headers = $this->plexHeaders($token);
        $key     = rawurlencode($remoteId);

        if ($finished) {
            curl(sprintf(MediaAppEndpoints::ENDPOINT_PLEX_SCROBBLE, $url, $key), $headers, 'GET');
            return;
        }

        curl(sprintf(MediaAppEndpoints::ENDPOINT_PLEX_UNSCROBBLE, $url, $key), $headers, 'GET');
        if ($started && $inprogress) {
            curl(sprintf(MediaAppEndpoints::ENDPOINT_PLEX_PROGRESS, $url, $key, intval($inprogress * 1000)), $headers, 'GET');
        }
    }

    public function plexAccountId($url, $token, $remoteId, $username)
    {
        $remoteId = trim(strval($remoteId ?? ''));
        $username = trim($username ?? '');
        $idents   = [];
        if ($remoteId != '') {
            $idents[] = strtolower($remoteId);
        }
        if ($username != '') {
            $idents[] = strtolower($username);
        }
        if (!$idents) {
            return '';
        }

        foreach ($this->plexServerAccounts($url, $token) as $account) {
            $id = strval($this->plexValue($account, 'id'));
            if ($id == '' || $id == '0') {
                continue;
            }
            $uuid = $this->plexValue($account, 'uuid');
            if ($uuid == '' && preg_match('/\/users\/(.+?)\/avatar/', $this->plexValue($account, 'thumb'), $matches)) {
                $uuid = $matches[1];
            }
            $haystack = [
                strtolower($id),
                strtolower($this->plexValue($account, 'name')),
                strtolower($uuid),
            ];
            foreach ($idents as $ident) {
                if ($ident != '' && in_array($ident, $haystack, true)) {
                    return $id;
                }
            }
        }

        return '';
    }

    public function plexServerAccounts($url, $token)
    {
        if (is_array($this->plexAccounts)) {
            return $this->plexAccounts;
        }

        $url       = rtrim(trim($url), '/');
        $curl      = curl(sprintf(MediaAppEndpoints::ENDPOINT_PLEX_ACCOUNTS, $url), $this->plexHeaders($token), 'GET');
        $response  = $this->parsePlexResponse($curl);
        $container = $response['MediaContainer'] ?? $response;
        $accounts  = $container['Account'] ?? [];
        if ($accounts && !isset($accounts[0])) {
            $accounts = [$accounts];
        }
        $this->plexAccounts = is_array($accounts) ? $accounts : [];

        return $this->plexAccounts;
    }

    public function plexGetLocalUsers($url, $token)
    {
        $users = [];
        $seen  = [];
        foreach ($this->plexServerAccounts($url, $token) as $account) {
            $id   = strval($this->plexValue($account, 'id'));
            $name = $this->plexValue($account, 'name');
            $uuid = $this->plexValue($account, 'uuid');
            if ($uuid == '' && preg_match('/\/users\/(.+?)\/avatar/', $this->plexValue($account, 'thumb'), $matches)) {
                $uuid = $matches[1];
            }
            if ($id == '' || $id == '0' || $name == '' || $name == '0' || $name == $id) {
                continue;
            }

            $existing = null;
            foreach ([$name, $id, $uuid] as $ident) {
                $ident = strtolower(trim(strval($ident)));
                if ($ident != '' && isset($seen[$ident])) {
                    $existing = $seen[$ident];
                    break;
                }
            }
            if ($existing != null) {
                $users[$existing]['match_ids']   = array_values(array_unique(array_filter(array_merge($users[$existing]['match_ids'] ?? [], [$id, $uuid]))));
                $users[$existing]['match_names'] = array_values(array_unique(array_merge($users[$existing]['match_names'] ?? [], [$name])));
                if (!empty($this->plexValue($account, 'default')) && $this->plexValue($account, 'default') != '0') {
                    $users[$existing]['is_admin'] = 1;
                }
                foreach ([$name, $id, $uuid] as $ident) {
                    $ident = strtolower(trim(strval($ident)));
                    if ($ident != '' && $ident != '0') {
                        $seen[$ident] = $existing;
                    }
                }
                continue;
            }

            $index   = count($users);
            $users[] = [
                'remote_id'   => $id,
                'username'    => $name,
                'email'       => '',
                'user_type'   => 'shared',
                'is_admin'    => $this->plexValue($account, 'default') != '' && $this->plexValue($account, 'default') != '0' ? 1 : 0,
                'last_seen'   => 0,
                'match_ids'   => array_values(array_filter([$id, $uuid])),
                'match_names' => [$name],
            ];
            foreach ([$name, $id, $uuid] as $ident) {
                $ident = strtolower(trim(strval($ident)));
                if ($ident != '' && $ident != '0') {
                    $seen[$ident] = $index;
                }
            }
        }

        if (!$users) {
            return ['error' => true, 'message' => translate('mediaAppUsersFailed')];
        }

        return ['error' => false, 'users' => $users];
    }

    public function plexGetUsers($url, $token, $serverId = '', $local = false)
    {
        if ($local) {
            return $this->plexGetLocalUsers($url, $token);
        }

        $url            = rtrim(trim($url), '/');
        $headers        = $this->plexHeaders($token, $serverId);
        $users          = [];
        $seen           = [];
        $accountAtIndex = [];

        $curl     = curl(MediaAppEndpoints::ENDPOINT_PLEX_TV_HOME_USERS, $headers, 'GET');
        $response = is_array($curl['response']) ? $curl['response'] : json_decode($curl['response'], true);
        if ($curl['code'] >= 200 && $curl['code'] <= 299 && is_array($response['users'] ?? null)) {
            foreach ($response['users'] as $user) {
                $names    = $this->plexUserMatchNames($user);
                $username = $names[0] ?? '';
                $remoteId = $user['uuid'] ?: ($user['id'] ?? '');
                if ($username == '' || $remoteId == '' || $remoteId == '0') {
                    continue;
                }
                $index   = count($users);
                $users[] = [
                    'remote_id'   => $remoteId,
                    'username'    => $username,
                    'email'       => $user['email'] ?? '',
                    'user_type'   => !empty($user['guest']) ? 'guest' : 'home',
                    'is_admin'    => !empty($user['admin']) ? 1 : 0,
                    'last_seen'   => 0,
                    'match_ids'   => array_values(array_filter([$remoteId, strval($user['uuid'] ?? ''), strval($user['id'] ?? '')])),
                    'match_names' => $names,
                ];
                foreach (array_merge($names, [$remoteId, strval($user['id'] ?? '')]) as $ident) {
                    $ident = strtolower(trim(strval($ident)));
                    if ($ident != '' && $ident != '0') {
                        $seen[$ident] = $index;
                    }
                }
            }
        }

        $curl = curl(MediaAppEndpoints::ENDPOINT_PLEX_TV_USERS, $this->plexHeaders($token, $serverId, 'application/xml'), 'GET');
        if ($curl['code'] >= 200 && $curl['code'] <= 299 && $curl['response']) {
            $xml = $this->plexLoadXml($curl['response']);
            if ($xml) {
                foreach ($xml->User as $xmlUser) {
                    $shared = [];
                    foreach ($xmlUser->attributes() as $key => $val) {
                        $shared[$key] = strval($val);
                    }

                    $names    = $this->plexUserMatchNames($shared);
                    $username = $names[0] ?? '';
                    $remoteId = $shared['id'] ?? '';
                    if (preg_match('/\/users\/(.+?)\/avatar/', $shared['thumb'] ?? '', $matches)) {
                        $remoteId = $matches[1];
                    }
                    $sharedId = $shared['id'] ?? '';
                    $existing = null;
                    foreach (array_merge($names, [$remoteId, $sharedId]) as $ident) {
                        $ident = strtolower(trim(strval($ident)));
                        if ($ident != '' && isset($seen[$ident])) {
                            $existing = $seen[$ident];
                            break;
                        }
                    }
                    if ($existing != null) {
                        $users[$existing]['match_ids']   = array_values(array_unique(array_filter(array_merge($users[$existing]['match_ids'] ?? [], [$remoteId, $sharedId]))));
                        $users[$existing]['match_names'] = array_values(array_unique(array_merge($users[$existing]['match_names'] ?? [], $names)));
                        if ($sharedId != '') {
                            $accountAtIndex[$existing] = $sharedId;
                        }
                        if (($shared['email'] ?? '') != '' && ($users[$existing]['email'] ?? '') == '') {
                            $users[$existing]['email'] = $shared['email'];
                        }
                        foreach (array_merge($names, [$remoteId, $sharedId]) as $ident) {
                            $ident = strtolower(trim(strval($ident)));
                            if ($ident != '' && $ident != '0') {
                                $seen[$ident] = $existing;
                            }
                        }
                        continue;
                    }
                    if ($username == '' || $remoteId == '' || $remoteId == '0') {
                        continue;
                    }

                    $index   = count($users);
                    $users[] = [
                        'remote_id'   => $remoteId,
                        'username'    => $username,
                        'email'       => $shared['email'] ?? '',
                        'user_type'   => (!empty($shared['home']) && $shared['home'] != '0') ? 'home' : 'shared',
                        'is_admin'    => 0,
                        'last_seen'   => 0,
                        'match_ids'   => array_values(array_filter([$remoteId, $sharedId])),
                        'match_names' => $names,
                    ];
                    foreach (array_merge($names, [$remoteId, $sharedId]) as $ident) {
                        $ident = strtolower(trim(strval($ident)));
                        if ($ident != '' && $ident != '0') {
                            $seen[$ident] = $index;
                        }
                    }
                    if ($sharedId != '') {
                        $accountAtIndex[$index] = $sharedId;
                    }
                }
            }
        }

        if ($serverId) {
            $curl = curl(sprintf(MediaAppEndpoints::ENDPOINT_PLEX_TV_SHARED_SERVERS, rawurlencode($serverId)), $this->plexHeaders($token, $serverId, 'application/xml'), 'GET');
            if ($curl['code'] >= 200 && $curl['code'] <= 299 && $curl['response']) {
                $xml = $this->plexLoadXml($curl['response']);
                if ($xml) {
                    $servers = $xml->SharedServer ?? [];
                    if (!$servers && isset($xml->Server)) {
                        $servers = $xml->Server->SharedServer ?? [];
                    }
                    foreach ($servers as $sharedServer) {
                        $shared = [];
                        foreach ($sharedServer->attributes() as $key => $val) {
                            $shared[$key] = strval($val);
                        }
                        $names    = $this->plexUserMatchNames($shared);
                        $username = $names[0] ?? '';
                        $remoteId = $shared['userID'] ?? ($shared['invitedId'] ?? '');
                        if (preg_match('/\/users\/(.+?)\/avatar/', $shared['thumb'] ?? '', $matches)) {
                            $remoteId = $matches[1];
                        }
                        if ($username == '' || $remoteId == '' || $remoteId == '0') {
                            continue;
                        }
                        $existing = null;
                        foreach (array_merge($names, [$remoteId, $shared['userID'] ?? '', $shared['invitedId'] ?? '']) as $ident) {
                            $ident = strtolower(trim(strval($ident)));
                            if ($ident != '' && isset($seen[$ident])) {
                                $existing = $seen[$ident];
                                break;
                            }
                        }
                        if ($existing != null) {
                            $users[$existing]['match_ids']   = array_values(array_unique(array_filter(array_merge($users[$existing]['match_ids'] ?? [], [$remoteId, $shared['userID'] ?? '', $shared['invitedId'] ?? '']))));
                            $users[$existing]['match_names'] = array_values(array_unique(array_merge($users[$existing]['match_names'] ?? [], $names)));
                            continue;
                        }
                        $index   = count($users);
                        $users[] = [
                            'remote_id'   => $remoteId,
                            'username'    => $username,
                            'email'       => $shared['email'] ?? '',
                            'user_type'   => 'shared',
                            'is_admin'    => 0,
                            'last_seen'   => 0,
                            'match_ids'   => array_values(array_filter([$remoteId, $shared['userID'] ?? '', $shared['invitedId'] ?? ''])),
                            'match_names' => $names,
                        ];
                        foreach (array_merge($names, [$remoteId, $shared['userID'] ?? '', $shared['invitedId'] ?? '']) as $ident) {
                            $ident = strtolower(trim(strval($ident)));
                            if ($ident != '' && $ident != '0') {
                                $seen[$ident] = $index;
                            }
                        }
                    }
                }
            }
        }

        foreach ($this->plexServerAccounts($url, $token) as $account) {
            $id   = strval($this->plexValue($account, 'id'));
            $name = $this->plexValue($account, 'name');
            $uuid = $this->plexValue($account, 'uuid');
            if ($uuid == '' && preg_match('/\/users\/(.+?)\/avatar/', $this->plexValue($account, 'thumb'), $matches)) {
                $uuid = $matches[1];
            }
            if ($id == '' || $id == '0' || $name == '' || $name == '0' || $name == $id) {
                continue;
            }
            $existing = null;
            foreach ([$name, $id, $uuid] as $ident) {
                $ident = strtolower(trim(strval($ident)));
                if ($ident != '' && isset($seen[$ident])) {
                    $existing = $seen[$ident];
                    break;
                }
            }
            if ($existing != null) {
                $users[$existing]['match_ids']   = array_values(array_unique(array_filter(array_merge($users[$existing]['match_ids'] ?? [], [$id, $uuid]))));
                $users[$existing]['match_names'] = array_values(array_unique(array_merge($users[$existing]['match_names'] ?? [], [$name])));
                continue;
            }
            $index   = count($users);
            $users[] = [
                'remote_id'   => $id,
                'username'    => $name,
                'email'       => '',
                'user_type'   => 'shared',
                'is_admin'    => $this->plexValue($account, 'default') != '' && $this->plexValue($account, 'default') != '0' ? 1 : 0,
                'last_seen'   => 0,
                'match_ids'   => array_values(array_filter([$id, $uuid])),
                'match_names' => [$name],
            ];
            foreach ([$name, $id, $uuid] as $ident) {
                $ident = strtolower(trim(strval($ident)));
                if ($ident != '' && $ident != '0') {
                    $seen[$ident] = $index;
                }
            }
        }

        if (!$users) {
            return ['error' => true, 'message' => translate('mediaAppUsersFailed')];
        }

        foreach ($users as $index => $user) {
            $accountId = '';
            foreach (array_merge([$user['remote_id']], $user['match_ids'] ?? [], $user['match_names'] ?? []) as $ident) {
                $accountId = $this->plexAccountId($url, $token, $ident, $ident);
                if ($accountId != '' && $accountId != '0') {
                    break;
                }
            }
            if ($accountId == '' || $accountId == '0') {
                continue;
            }
            $users[$index]['match_ids'] = array_values(array_unique(array_filter(array_merge($user['match_ids'] ?? [], [$user['remote_id'], $accountId]))));
            $users[$index]['remote_id'] = $accountId;
            $accountAtIndex[$index]     = $accountId;
        }

        $historyLastSeen = $this->plexHistoryLastSeen($url, $headers);
        foreach ($accountAtIndex as $index => $accountId) {
            if (!empty($historyLastSeen[$accountId])) {
                $users[$index]['last_seen'] = $historyLastSeen[$accountId];
            }
        }

        return ['error' => false, 'users' => $users];
    }

    public function plexHistoryLastSeen($url, $headers)
    {
        $lastSeen  = [];
        $curl      = curl(sprintf(MediaAppEndpoints::ENDPOINT_PLEX_HISTORY_RECENT, $url, 0, 500), $headers, 'GET');
        $response  = $this->parsePlexResponse($curl);
        $container = $response['MediaContainer'] ?? $response;

        foreach (['Video', 'Track', 'Metadata'] as $key) {
            $items = $container[$key] ?? [];
            if ($items && !isset($items[0])) {
                $items = [$items];
            }
            if (!is_array($items)) {
                continue;
            }

            foreach ($items as $item) {
                $accountId = $item['accountID'] ?? '';
                $viewedAt  = $this->plexTimestamp($item['viewedAt'] ?? 0);
                if (!$accountId || !$viewedAt) {
                    continue;
                }
                if (empty($lastSeen[$accountId]) || $viewedAt > $lastSeen[$accountId]) {
                    $lastSeen[$accountId] = $viewedAt;
                }
            }
        }

        return $lastSeen;
    }

    public function plexTimestamp($value)
    {
        if ($value == '' || $value == null) {
            return 0;
        }
        if (is_numeric($value)) {
            return intval($value);
        }
        $time = strtotime($value);
        return $time ?: 0;
    }

    public function plexPosterUrl($url, $token, $poster)
    {
        $url    = rtrim(trim($url), '/');
        $poster = trim(strval($poster));
        if ($poster == '') {
            return '';
        }

        $query = 'X-Plex-Token=' . rawurlencode($token) . '&width=80&height=120';
        if (preg_match('#^https?://#i', $poster)) {
            return $poster . (str_contains($poster, '?') ? '&' : '?') . $query;
        }
        if (!str_starts_with($poster, '/')) {
            $poster = '/' . $poster;
        }

        return $url . $poster . (str_contains($poster, '?') ? '&' : '?') . $query;
    }

    public function plexHeaders($token, $clientId = '', $accept = 'application/json')
    {
        return [
            'Accept: ' . $accept,
            'X-Plex-Token: ' . $token,
            'X-Plex-Product: ' . APP_NAME,
            'X-Plex-Version: 1.0',
            'X-Plex-Client-Identifier: ' . ($clientId ?: md5(APP_NAME)),
        ];
    }

    public function plexLoadXml($response)
    {
        if (!is_string($response) || $response == '' || !str_contains($response, '<')) {
            return false;
        }

        $previous = libxml_use_internal_errors(true);
        $xml      = simplexml_load_string($response);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $xml ?: false;
    }

    public function parsePlexResponse($curl)
    {
        if (is_array($curl['response'])) {
            return $curl['response'];
        }

        $parsed = json_decode($curl['response'], true);
        if (is_array($parsed)) {
            return $parsed;
        }

        $xml = $this->plexLoadXml($curl['response']);
        if (!$xml) {
            return [];
        }

        return json_decode(json_encode($xml), true);
    }

    public function plexCreateLibrary($url, $token, $title, $type, $paths)
    {
        $url   = rtrim(trim($url), '/');
        $type  = $type == 'show' ? 'show' : 'movie';
        $query = [
            'name'     => $title,
            'type'     => $type,
            'agent'    => $type == 'show' ? 'tv.plex.agents.series' : 'tv.plex.agents.movie',
            'scanner'  => $type == 'show' ? 'Plex TV Series' : 'Plex Movie',
            'language' => 'en-US',
        ];
        $qs    = http_build_query($query);
        foreach ($paths as $path) {
            $qs .= '&location=' . rawurlencode($path);
        }
        $curl = curl(sprintf(MediaAppEndpoints::ENDPOINT_PLEX_LIBRARIES_CREATE, $url, $qs), $this->plexHeaders($token), 'POST');
        if ($curl['code'] < 200 || $curl['code'] > 299) {
            return ['error' => true, 'message' => $curl['error'] ?: translate('couldNotSaveSettings')];
        }

        return ['error' => false];
    }

    public function plexDeleteLibrary($url, $token, $libraryKey)
    {
        $url  = rtrim(trim($url), '/');
        $curl = curl(sprintf(MediaAppEndpoints::ENDPOINT_PLEX_LIBRARY, $url, rawurlencode($libraryKey)), $this->plexHeaders($token), 'DELETE');
        if ($curl['code'] < 200 || $curl['code'] > 299) {
            return ['error' => true, 'message' => $curl['error'] ?: translate('couldNotSaveSettings')];
        }

        return ['error' => false];
    }

    public function plexCreateUser($token, $serverId, $username)
    {
        $username = trim($username);
        if ($username == '' || $serverId == '') {
            return ['error' => true, 'message' => translate('mediaAppUsersFailed')];
        }

        $url  = MediaAppEndpoints::ENDPOINT_PLEX_TV_HOME_USER_CREATE . '?' . http_build_query(['friendlyName' => $username]);
        $curl = curl($url, $this->plexHeaders($token, $serverId), 'POST');
        $data = is_array($curl['response']) ? $curl['response'] : $this->parsePlexResponse($curl);
        $user = is_array($data['user'] ?? null) ? $data['user'] : $data;
        if (is_array($user['@attributes'] ?? null)) {
            $user = $user['@attributes'];
        }
        $remoteId = $user['uuid'] ?? ($user['id'] ?? '');
        if ($curl['code'] < 200 || $curl['code'] > 299 || $remoteId == '') {
            return ['error' => true, 'message' => $curl['error'] ?: translate('mediaAppUsersFailed')];
        }

        $this->plexSharedLibraries = null;

        return ['error' => false, 'remote_id' => $remoteId];
    }

    public function plexGetUserLibraryKeys($token, $serverId, $user)
    {
        $shares = $this->plexSharedLibraryMap($token, $serverId);
        $idents = [
            strtolower(trim($user['username'] ?? '')),
            trim($user['remote_id'] ?? ''),
            strtolower(trim($user['email'] ?? '')),
        ];
        foreach ($idents as $ident) {
            if ($ident != '' && isset($shares[$ident])) {
                return $shares[$ident];
            }
        }

        if (!empty($user['is_admin'])) {
            return ['all' => true, 'keys' => []];
        }

        return ['all' => false, 'keys' => []];
    }

    public function plexTvSections($token, $serverId)
    {
        if (is_array($this->plexTvSections ?? null)) {
            return $this->plexTvSections;
        }

        $this->plexTvSections = ['map' => [], 'ids' => []];
        $curl                 = curl(sprintf(MediaAppEndpoints::ENDPOINT_PLEX_TV_SERVER, rawurlencode($serverId)), $this->plexHeaders($token, $serverId, 'application/xml'), 'GET');
        if ($curl['code'] < 200 || $curl['code'] > 299 || !$curl['response']) {
            return $this->plexTvSections;
        }

        $xml = $this->plexLoadXml($curl['response']);
        if (!$xml) {
            return $this->plexTvSections;
        }

        $sections = [];
        if (isset($xml->Section)) {
            foreach ($xml->Section as $section) {
                $sections[] = $section;
            }
        }
        if (isset($xml->Server)) {
            foreach ($xml->Server as $server) {
                if (isset($server->Section)) {
                    foreach ($server->Section as $section) {
                        $sections[] = $section;
                    }
                }
            }
        }

        foreach ($sections as $section) {
            $local = strval($section['key'] ?? '');
            $tvId  = strval($section['id'] ?? '');
            $title = strtolower(trim(strval($section['title'] ?? '')));
            if ($local == '') {
                continue;
            }
            $this->plexTvSections['map'][$local] = $local;
            if ($tvId != '') {
                $this->plexTvSections['map'][$tvId]  = $local;
                $this->plexTvSections['ids'][$local] = $tvId;
            }
            if ($title != '') {
                $this->plexTvSections['map'][$title] = $local;
            }
        }

        return $this->plexTvSections;
    }

    public function plexTvSectionMap($token, $serverId)
    {
        return $this->plexTvSections($token, $serverId)['map'];
    }

    public function plexShareSectionIds($token, $serverId, $keys, $all)
    {
        $ids = $this->plexTvSections($token, $serverId)['ids'];
        if ($all) {
            return array_values($ids);
        }

        $sectionIds = [];
        foreach ($keys as $key) {
            $key = strval($key);
            if ($key != '' && !empty($ids[$key])) {
                $sectionIds[] = $ids[$key];
            }
        }

        return array_values(array_unique($sectionIds));
    }

    public function plexHomeUserNumericId($token, $serverId, $user)
    {
        $curl     = curl(MediaAppEndpoints::ENDPOINT_PLEX_TV_HOME_USERS, $this->plexHeaders($token, $serverId), 'GET');
        $response = is_array($curl['response']) ? $curl['response'] : json_decode($curl['response'], true);
        if ($curl['code'] < 200 || $curl['code'] > 299 || !is_array($response['users'] ?? null)) {
            return '';
        }

        $idents = [
            strtolower(trim($user['username'] ?? '')),
            trim($user['remote_id'] ?? ''),
            strval($user['id'] ?? ''),
        ];
        foreach ($response['users'] as $homeUser) {
            $matches = [
                strtolower(trim($homeUser['friendlyName'] ?? '')),
                strtolower(trim($homeUser['username'] ?? '')),
                strtolower(trim($homeUser['title'] ?? '')),
                trim($homeUser['uuid'] ?? ''),
                strval($homeUser['id'] ?? ''),
            ];
            foreach ($idents as $ident) {
                if ($ident != '' && in_array($ident, $matches, true)) {
                    return strval($homeUser['id'] ?? '');
                }
            }
        }

        return '';
    }

    public function plexUserShareId($token, $serverId, $user, $invitedId = '')
    {
        $curl = curl(sprintf(MediaAppEndpoints::ENDPOINT_PLEX_TV_SHARED_SERVERS, rawurlencode($serverId)), $this->plexHeaders($token, $serverId, 'application/xml'), 'GET');
        $xml  = $this->plexLoadXml($curl['response']);
        if ($curl['code'] < 200 || $curl['code'] > 299 || !$xml) {
            return '';
        }

        $idents  = [
            strtolower(trim($user['username'] ?? '')),
            trim($user['remote_id'] ?? ''),
            strval($invitedId),
        ];
        $servers = $xml->SharedServer ?? [];
        if (!$servers && isset($xml->Server)) {
            $servers = $xml->Server->SharedServer ?? [];
        }
        foreach ($servers as $sharedServer) {
            $shared = [];
            foreach ($sharedServer->attributes() as $key => $val) {
                $shared[$key] = strval($val);
            }
            $matches = [
                strtolower(trim($shared['username'] ?? '')),
                strtolower(trim($shared['title'] ?? '')),
                strval($shared['userID'] ?? ''),
                strval($shared['invitedId'] ?? ''),
            ];
            foreach ($idents as $ident) {
                if ($ident != '' && in_array($ident, $matches, true)) {
                    return strval($shared['id'] ?? '');
                }
            }
        }

        return '';
    }

    public function plexSetUserLibraryAccess($token, $serverId, $user, $keys, $all)
    {
        if ($serverId == '') {
            return ['error' => true, 'message' => translate('mediaAppUsersFailed')];
        }

        $sectionIds = $this->plexShareSectionIds($token, $serverId, $keys, $all);
        $invitedId  = $this->plexHomeUserNumericId($token, $serverId, $user);
        $shareId    = $this->plexUserShareId($token, $serverId, $user, $invitedId);
        $headers    = $this->plexHeaders($token, $serverId);

        if (!$all && !$sectionIds) {
            if ($shareId == '') {
                return ['error' => false];
            }
            $curl                      = curl(sprintf(MediaAppEndpoints::ENDPOINT_PLEX_TV_SHARED_SERVER, rawurlencode($serverId), rawurlencode($shareId)), $headers, 'DELETE');
            $this->plexSharedLibraries = null;
            if ($curl['code'] < 200 || $curl['code'] > 299) {
                return ['error' => true, 'message' => $curl['error'] ?: translate('mediaAppUsersFailed')];
            }

            return ['error' => false];
        }

        if ($shareId != '') {
            $payload = json_encode([
                'server_id'     => $serverId,
                'shared_server' => ['library_section_ids' => $sectionIds],
            ]);
            $curl    = curl(sprintf(MediaAppEndpoints::ENDPOINT_PLEX_TV_SHARED_SERVER, rawurlencode($serverId), rawurlencode($shareId)), $headers, 'PUT', $payload);
        } else {
            if ($invitedId == '') {
                return ['error' => true, 'message' => translate('mediaAppUsersFailed')];
            }
            $payload = json_encode([
                'machineIdentifier' => $serverId,
                'librarySectionIds' => $sectionIds,
                'invitedId'         => intval($invitedId),
            ]);
            $curl    = curl(MediaAppEndpoints::ENDPOINT_PLEX_TV_SHARED_SERVERS_V2, $headers, 'POST', $payload);
            if ($curl['code'] == 422) {
                $shareId = $this->plexUserShareId($token, $serverId, $user, $invitedId);
                if ($shareId == '') {
                    return ['error' => true, 'message' => $curl['error'] ?: translate('mediaAppUsersFailed')];
                }
                $payload = json_encode([
                    'server_id'     => $serverId,
                    'shared_server' => ['library_section_ids' => $sectionIds],
                ]);
                $curl    = curl(sprintf(MediaAppEndpoints::ENDPOINT_PLEX_TV_SHARED_SERVER, rawurlencode($serverId), rawurlencode($shareId)), $headers, 'PUT', $payload);
            }
        }

        $this->plexSharedLibraries = null;
        if ($curl['code'] < 200 || $curl['code'] > 299) {
            return ['error' => true, 'message' => $curl['error'] ?: translate('mediaAppUsersFailed')];
        }

        return ['error' => false];
    }

    public function plexLocalSectionKey($raw, $sectionMap)
    {
        $raw = trim(strval($raw));
        if ($raw == '') {
            return '';
        }
        if (isset($sectionMap[$raw])) {
            return $sectionMap[$raw];
        }
        $lower = strtolower($raw);
        if (isset($sectionMap[$lower])) {
            return $sectionMap[$lower];
        }
        if (!$sectionMap || in_array($raw, $sectionMap, true)) {
            return $raw;
        }

        return '';
    }

    public function plexShareIsAll($source)
    {
        if (is_array($source) || is_object($source)) {
            $value = $source['allLibraries'] ?? ($source['all_libraries'] ?? '');
        } else {
            $value = $source;
        }
        $value = strtolower(trim(strval($value)));

        return $value === '1' || $value === 'true';
    }

    public function plexShareSectionKeys($parent, $sectionMap = [])
    {
        $keys = [];
        if (is_array($parent)) {
            if (!empty($parent['libraries'])) {
                $libraries = $parent['libraries'];
                if (!isset($libraries[0])) {
                    $libraries = [$libraries];
                }
                foreach ($libraries as $library) {
                    if (!is_array($library)) {
                        $key = $this->plexLocalSectionKey($library, $sectionMap);
                    } else {
                        $key = $this->plexLocalSectionKey($library['key'] ?? ($library['id'] ?? ''), $sectionMap);
                        if ($key == '') {
                            $key = $this->plexLocalSectionKey($library['title'] ?? '', $sectionMap);
                        }
                    }
                    if ($key != '') {
                        $keys[] = $key;
                    }
                }

                return $keys;
            }

            $sections = $parent['Section'] ?? ($parent['librarySectionIds'] ?? ($parent['library_section_ids'] ?? []));
            if ($sections && !isset($sections[0]) && (isset($sections['key']) || isset($sections['id']) || isset($sections['title']))) {
                $sections = [$sections];
            }
            foreach ($sections as $section) {
                if (is_array($section) && array_key_exists('shared', $section) && !$this->plexShareIsAll(['allLibraries' => $section['shared']])) {
                    continue;
                }
                $raw = is_array($section) ? ($section['key'] ?? ($section['id'] ?? '')) : $section;
                $key = $this->plexLocalSectionKey($raw, $sectionMap);
                if ($key == '' && is_array($section)) {
                    $key = $this->plexLocalSectionKey($section['title'] ?? '', $sectionMap);
                }
                if ($key != '') {
                    $keys[] = $key;
                }
            }

            return $keys;
        }

        if (!isset($parent->Section)) {
            return $keys;
        }

        foreach ($parent->Section as $section) {
            $shared = $section['shared'] ?? '0';
            if ($shared != 1 && strtolower(strval($shared)) != 'true') {
                continue;
            }
            $key = $this->plexLocalSectionKey($section['key'] ?? ($section['id'] ?? ''), $sectionMap);
            if ($key == '') {
                $key = $this->plexLocalSectionKey($section['title'] ?? '', $sectionMap);
            }
            if ($key != '') {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    public function plexSharedLibraryMap($token, $serverId)
    {
        if (is_array($this->plexSharedLibraries ?? null)) {
            return $this->plexSharedLibraries;
        }

        $this->plexSharedLibraries = [];
        $xmlHeaders                = $this->plexHeaders($token, $serverId, 'application/xml');
        $sectionMap                = $serverId ? $this->plexTvSectionMap($token, $serverId) : [];

        if ($serverId) {
            $curl = curl(sprintf(MediaAppEndpoints::ENDPOINT_PLEX_TV_SHARED_SERVERS, rawurlencode($serverId)), $xmlHeaders, 'GET');
            if ($curl['code'] >= 200 && $curl['code'] <= 299 && $curl['response']) {
                $xml = $this->plexLoadXml($curl['response']);
                if ($xml) {
                    $servers = $xml->SharedServer ?? [];
                    if (!$servers && isset($xml->Server)) {
                        $servers = $xml->Server->SharedServer ?? [];
                    }
                    foreach ($servers as $sharedServer) {
                        $shared = [];
                        foreach ($sharedServer->attributes() as $key => $val) {
                            $shared[$key] = strval($val);
                        }
                        $shareId = $shared['id'] ?? '';
                        $all     = $this->plexShareIsAll($sharedServer);
                        $keys    = $this->plexShareSectionKeys($sharedServer, $sectionMap);
                        if (!$all && !$keys && $shareId != '') {
                            $detail = $this->plexSharedServerDetail($token, $serverId, $shareId, $sectionMap);
                            $all    = $detail['all'];
                            $keys   = $detail['keys'];
                        }
                        $this->plexRememberShare([
                            $shared['username'] ?? '',
                            $shared['title'] ?? '',
                            $shared['userID'] ?? '',
                            $shared['invitedId'] ?? '',
                            $shareId,
                            $shared['email'] ?? '',
                        ], $keys, $all);
                    }
                }
            }
        }

        $curl = curl(MediaAppEndpoints::ENDPOINT_PLEX_TV_USERS, $xmlHeaders, 'GET');
        if ($curl['code'] >= 200 && $curl['code'] <= 299 && $curl['response']) {
            $xml = $this->plexLoadXml($curl['response']);
            if ($xml) {
                foreach ($xml->User as $xmlUser) {
                    $shared = [];
                    foreach ($xmlUser->attributes() as $key => $val) {
                        $shared[$key] = strval($val);
                    }
                    $keys     = [];
                    $all      = false;
                    $onServer = false;
                    $idents   = [
                        $shared['username'] ?? '',
                        $shared['title'] ?? '',
                        $shared['id'] ?? '',
                        $shared['email'] ?? '',
                    ];
                    if (preg_match('/\/users\/(.+?)\/avatar/', $shared['thumb'] ?? '', $matches)) {
                        $idents[] = $matches[1];
                    }
                    foreach ($xmlUser->Server as $server) {
                        $machine = strval($server['machineIdentifier'] ?? '');
                        if ($serverId && $machine && $machine != $serverId) {
                            continue;
                        }
                        $onServer = true;
                        if ($this->plexShareIsAll($server)) {
                            $all = true;
                        }
                        foreach ($this->plexShareSectionKeys($server, $sectionMap) as $key) {
                            $keys[] = $key;
                        }
                        $shareId = strval($server['id'] ?? '');
                        if (!$all && !$keys && $shareId != '' && $serverId && !$this->plexShareKnown($idents)) {
                            $detail = $this->plexSharedServerDetail($token, $serverId, $shareId, $sectionMap);
                            $all    = $detail['all'] || $all;
                            $keys   = array_merge($keys, $detail['keys']);
                        }
                    }
                    if ($onServer && ($all || $keys)) {
                        $this->plexRememberShare($idents, $keys, $all);
                    }
                }
            }
        }

        $this->plexLinkHomeUsersXml($token, $serverId, $sectionMap);
        $this->plexLinkHomeUserShares($token, $serverId);

        return $this->plexSharedLibraries;
    }

    public function plexSharedServerDetail($token, $serverId, $shareId, $sectionMap)
    {
        $empty = ['all' => false, 'keys' => []];
        $curl  = curl(sprintf(MediaAppEndpoints::ENDPOINT_PLEX_TV_SHARED_SERVER, rawurlencode($serverId), rawurlencode($shareId)), $this->plexHeaders($token, $serverId, 'application/xml'), 'GET');
        if ($curl['code'] >= 200 && $curl['code'] <= 299) {
            $xml = $this->plexLoadXml($curl['response']);
            if ($xml) {
                $node = isset($xml->SharedServer) ? $xml->SharedServer : $xml;
                $all  = $this->plexShareIsAll($node);
                $keys = $this->plexShareSectionKeys($node, $sectionMap);
                if ($all || $keys) {
                    return ['all' => $all, 'keys' => $keys];
                }
            }
        }

        $curl = curl(sprintf(MediaAppEndpoints::ENDPOINT_PLEX_TV_SHARED_SERVER_V2, rawurlencode($shareId)), $this->plexHeaders($token, $serverId), 'GET');
        $data = is_array($curl['response']) ? $curl['response'] : json_decode($curl['response'], true);
        if ($curl['code'] < 200 || $curl['code'] > 299 || !is_array($data)) {
            return $empty;
        }

        $keys = $this->plexShareSectionKeys($data, $sectionMap);
        foreach ($data['libraries'] ?? [] as $library) {
            $key = $this->plexLocalSectionKey($library['key'] ?? ($library['id'] ?? ''), $sectionMap);
            if ($key != '') {
                $keys[] = $key;
            }
        }

        return [
            'all'  => $this->plexShareIsAll($data),
            'keys' => array_values(array_unique($keys)),
        ];
    }

    public function plexLinkHomeUsersXml($token, $serverId, $sectionMap)
    {
        if (!$serverId) {
            return;
        }

        $curl = curl(MediaAppEndpoints::ENDPOINT_PLEX_TV_HOME_USERS_XML, $this->plexHeaders($token, $serverId, 'application/xml'), 'GET');
        if ($curl['code'] < 200 || $curl['code'] > 299) {
            return;
        }
        $xml = $this->plexLoadXml($curl['response']);
        if (!$xml) {
            return;
        }

        foreach ($xml->User as $xmlUser) {
            $user = [];
            foreach ($xmlUser->attributes() as $key => $val) {
                $user[$key] = strval($val);
            }
            $uuid = '';
            if (preg_match('/\/users\/(.+?)\/avatar/', $user['thumb'] ?? '', $matches)) {
                $uuid = $matches[1];
            }
            $idents = [
                $user['title'] ?? '',
                $user['username'] ?? '',
                $user['id'] ?? '',
                $user['uuid'] ?? '',
                $uuid,
                $user['email'] ?? '',
            ];
            $keys   = [];
            $all    = false;
            foreach ($xmlUser->Server as $server) {
                $machine = strval($server['machineIdentifier'] ?? '');
                if ($machine && $machine != $serverId) {
                    continue;
                }
                if ($this->plexShareIsAll($server)) {
                    $all = true;
                }
                foreach ($this->plexShareSectionKeys($server, $sectionMap) as $key) {
                    $keys[] = $key;
                }
                $shareId = strval($server['id'] ?? '');
                if (!$all && !$keys && $shareId != '' && !$this->plexShareKnown($idents)) {
                    $detail = $this->plexSharedServerDetail($token, $serverId, $shareId, $sectionMap);
                    $all    = $detail['all'] || $all;
                    $keys   = array_merge($keys, $detail['keys']);
                }
            }
            if ($all || $keys) {
                $this->plexRememberShare($idents, $keys, $all);
            }
        }
    }

    public function plexLinkHomeUserShares($token, $serverId)
    {
        $curl     = curl(MediaAppEndpoints::ENDPOINT_PLEX_TV_HOME_USERS, $this->plexHeaders($token, $serverId), 'GET');
        $response = is_array($curl['response']) ? $curl['response'] : json_decode($curl['response'], true);
        if ($curl['code'] < 200 || $curl['code'] > 299 || !is_array($response['users'] ?? null)) {
            return;
        }

        foreach ($response['users'] as $user) {
            $idents = [
                $user['friendlyName'] ?? '',
                $user['username'] ?? '',
                $user['title'] ?? '',
                $user['uuid'] ?? '',
                $user['id'] ?? '',
                $user['email'] ?? '',
            ];
            $found  = [];
            foreach ($idents as $ident) {
                $ident = trim(strval($ident));
                if ($ident != '' && isset($this->plexSharedLibraries[$ident])) {
                    $found = $this->plexSharedLibraries[$ident];
                    break;
                }
                $lower = strtolower($ident);
                if ($lower != $ident && isset($this->plexSharedLibraries[$lower])) {
                    $found = $this->plexSharedLibraries[$lower];
                    break;
                }
            }
            if (!$found) {
                continue;
            }
            $this->plexRememberShare($idents, $found['keys'] ?? [], !empty($found['all']));
        }
    }

    public function plexShareKnown($idents)
    {
        foreach ($idents as $ident) {
            $ident = trim(strval($ident));
            if ($ident == '') {
                continue;
            }
            if (!empty($this->plexSharedLibraries[$ident]['all']) || !empty($this->plexSharedLibraries[$ident]['keys'])) {
                return true;
            }
            $lower = strtolower($ident);
            if ($lower != $ident && (!empty($this->plexSharedLibraries[$lower]['all']) || !empty($this->plexSharedLibraries[$lower]['keys']))) {
                return true;
            }
        }

        return false;
    }

    public function plexRememberShare($idents, $keys, $all = false)
    {
        $list = [];
        foreach ($idents as $ident) {
            $ident = trim(strval($ident));
            if ($ident == '') {
                continue;
            }
            $list[] = $ident;
            $lower  = strtolower($ident);
            if ($lower != $ident) {
                $list[] = $lower;
            }
        }
        $list   = array_values(array_unique($list));
        $merged = $keys;
        foreach ($list as $ident) {
            if (!isset($this->plexSharedLibraries[$ident])) {
                continue;
            }
            $merged = array_merge($merged, $this->plexSharedLibraries[$ident]['keys'] ?? []);
        }
        $share = ['all' => $all, 'keys' => $all ? [] : array_values(array_unique($merged))];
        foreach ($list as $ident) {
            $this->plexSharedLibraries[$ident] = $share;
        }
    }

}
