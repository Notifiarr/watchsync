<?php

/*
----------------------------------
------  Created: 091026   ------
------  Austin Best       ------
----------------------------------
*/

trait Plex
{
    public function plexIsOnline($url)
    {
        $url  = rtrim(trim($url), '/');
        $curl = curl($url . '/identity', ['Accept: application/json'], 'GET', '', [], 3);

        return $curl['code'] >= 200 && $curl['code'] <= 299;
    }

    public function plexTestConnection($url, $token)
    {
        $url = rtrim(trim($url), '/');
        if (!$url || !$token) {
            return ['error' => true, 'message' => translate('missingConnectionFields')];
        }

        $headers  = $this->plexHeaders($token);
        $curl     = curl($url . '/', $headers, 'GET');
        $response = $this->decodePlexResponse($curl);

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
        $curl        = curl($url . '/library/sections', $headers, 'GET');
        $response    = $this->decodePlexResponse($curl);
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
                    if ($path !== '') {
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
            } else if (!is_array($entry) && $entry !== '' && $entry !== null) {
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
            } else if (!is_array($entry) && $entry !== '' && $entry !== null) {
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
                    $item['library'] = $library['title'] ?? '';
                    $items['movies'][] = $item;
                }
            } else {
                foreach ($this->plexSectionItems($url, $token, $library['key'], 2, $library['title'] ?? '', '', $since) as $item) {
                    $item['library'] = $library['title'] ?? '';
                    $items['series'][] = $item;
                }
                foreach ($this->plexSectionItems($url, $token, $library['key'], 4, $library['title'] ?? '', '', $since) as $item) {
                    $item['library'] = $library['title'] ?? '';
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
                $request = $url . '/library/metadata/' . rawurlencode($seriesRemoteId) . '/allLeaves?X-Plex-Container-Start=' . $start . '&X-Plex-Container-Size=' . $size;
            } else {
                $request = $url . '/library/sections/' . $key . '/all?type=' . intval($type) . '&X-Plex-Container-Start=' . $start . '&X-Plex-Container-Size=' . $size;
            }
            if (intval($since) > 0) {
                $request .= '&addedAt>=' . intval($since);
            }
            $curl      = curl($request, $headers, 'GET');
            $response  = $this->decodePlexResponse($curl);
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
            'series_remote_id' => $row['grandparentRatingKey'] ?? ($row['parentRatingKey'] ?? ''),
            'series'           => $row['grandparentTitle'] ?? '',
            'season'           => intval($row['parentIndex'] ?? 0),
            'episode'          => intval($row['index'] ?? 0),
            'updated_at'       => intval($this->plexValue($row, 'updatedAt')),
        ];

        if (intval($type) == 2) {
            $item['series_remote_id'] = $row['ratingKey'] ?? '';
            $item['series']           = $row['title'] ?? '';
            $item['season']           = 0;
            $item['episode']          = 0;
        }

        return $item;
    }

    public function plexValue($row, $name)
    {
        if (!is_array($row)) {
            return '';
        }
        if (isset($row[$name]) && !is_array($row[$name]) && $row[$name] !== '') {
            return $row[$name];
        }
        $attrs = $row['@attributes'] ?? [];
        if (is_array($attrs) && isset($attrs[$name]) && !is_array($attrs[$name]) && $attrs[$name] !== '') {
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
                    if ($file !== '') {
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
                if ($path !== '') {
                    return $path;
                }
            }
        }

        return '';
    }

    public function plexGetWatchStatus($url, $token, $remoteId, $username = '')
    {
        $accountId = $this->plexAccountId($url, $token, $remoteId, $username);
        $status    = [
            'movies'   => [],
            'episodes' => [],
        ];
        if (!$accountId) {
            return $status;
        }

        $url     = rtrim(trim($url), '/');
        $headers = $this->plexHeaders($token);
        $start   = 0;
        $size    = 500;

        while (true) {
            $curl      = curl($url . '/status/sessions/history/all?accountID=' . rawurlencode($accountId) . '&sort=viewedAt:desc&X-Plex-Container-Start=' . $start . '&X-Plex-Container-Size=' . $size, $headers, 'GET');
            $response  = $this->decodePlexResponse($curl);
            $container = $response['MediaContainer'] ?? $response;
            $rows      = [];
            foreach (['Metadata', 'Video'] as $key) {
                $found = $container[$key] ?? [];
                if ($found && !isset($found[0])) {
                    $found = [$found];
                }
                if (is_array($found)) {
                    foreach ($found as $row) {
                        $rows[] = $row;
                    }
                }
            }
            if (!$rows) {
                break;
            }

            foreach ($rows as $row) {
                $ratingKey = $row['ratingKey'] ?? '';
                if (!$ratingKey) {
                    continue;
                }
                $viewOffset = intval($row['viewOffset'] ?? 0);
                $viewCount  = intval($row['viewCount'] ?? 0);
                $type       = $row['type'] ?? '';
                $inprogress = $viewOffset > 0 ? intval($viewOffset / 1000) : 0;
                $finished   = ($viewCount > 0 || (!$inprogress && !empty($row['viewedAt']))) ? 1 : 0;
                $started    = 1;
                $entry      = [
                    'started'    => $started,
                    'inprogress' => $finished ? 0 : $inprogress,
                    'finished'   => $finished,
                ];
                if ($type == 'episode') {
                    $status['episodes'][$ratingKey] = $entry;
                } else {
                    $status['movies'][$ratingKey] = $entry;
                }
            }

            if (count($rows) < $size) {
                break;
            }
            $start += $size;
        }

        return $status;
    }

    public function plexSetWatchStatus($url, $token, $remoteId, $started, $inprogress, $finished)
    {
        $url     = rtrim(trim($url), '/');
        $headers = $this->plexHeaders($token);
        $key     = rawurlencode($remoteId);
        $base    = $url . '/:/';

        if ($finished) {
            curl($base . 'scrobble?identifier=com.plexapp.plugins.library&key=' . $key, $headers, 'GET');
            return;
        }

        curl($base . 'unscrobble?identifier=com.plexapp.plugins.library&key=' . $key, $headers, 'GET');
        if ($started && $inprogress) {
            curl($base . 'progress?identifier=com.plexapp.plugins.library&key=' . $key . '&time=' . intval($inprogress * 1000) . '&state=stopped', $headers, 'GET');
        }
    }

    public function plexAccountId($url, $token, $remoteId, $username)
    {
        if ($remoteId !== '' && $remoteId !== null && ctype_digit(strval($remoteId))) {
            return $remoteId;
        }

        $url      = rtrim(trim($url), '/');
        $headers  = $this->plexHeaders($token);
        $curl     = curl($url . '/accounts', $headers, 'GET');
        $response = $this->decodePlexResponse($curl);
        $accounts = $response['MediaContainer']['Account'] ?? [];
        if ($accounts && !isset($accounts[0])) {
            $accounts = [$accounts];
        }
        if (!is_array($accounts)) {
            return '';
        }

        foreach ($accounts as $account) {
            if ($remoteId && (($account['id'] ?? '') == $remoteId)) {
                return $account['id'];
            }
            if ($username && strcasecmp($account['name'] ?? '', $username) == 0) {
                return $account['id'];
            }
        }

        return '';
    }

    public function plexGetUsers($url, $token, $serverId = '')
    {
        $url            = rtrim(trim($url), '/');
        $headers        = $this->plexHeaders($token, $serverId);
        $users          = [];
        $seen           = [];
        $accountAtIndex = [];

        $curl     = curl('https://plex.tv/api/v2/home/users/', $headers, 'GET');
        $response = is_array($curl['response']) ? $curl['response'] : json_decode($curl['response'], true);
        if ($curl['code'] >= 200 && $curl['code'] <= 299 && is_array($response['users'] ?? null)) {
            foreach ($response['users'] as $user) {
                $username                    = $user['friendlyName'] ?: ($user['username'] ?: ($user['title'] ?: ($user['email'] ?: $user['id'])));
                $remoteId                    = $user['uuid'] ?: $user['id'];
                $index                       = count($users);
                $users[]                     = [
                    'remote_id' => $remoteId,
                    'username'  => $username,
                    'email'     => $user['email'] ?? '',
                    'user_type' => !empty($user['guest']) ? 'guest' : 'home',
                    'is_admin'  => !empty($user['admin']) ? 1 : 0,
                    'last_seen' => 0,
                ];
                $seen[strtolower($username)] = $index;
                $seen[$remoteId]             = $index;
                if ($user['id']) {
                    $seen[$user['id']]      = $index;
                    $accountAtIndex[$index] = $user['id'];
                }
            }
        }

        $sharedHeaders = [
            'Accept: application/xml',
            'X-Plex-Token: ' . $token,
            'X-Plex-Product: ' . APP_NAME,
            'X-Plex-Version: 1.0',
            'X-Plex-Client-Identifier: ' . ($serverId ?: md5(APP_NAME)),
        ];
        $curl          = curl('https://plex.tv/api/users/', $sharedHeaders, 'GET');
        if ($curl['code'] >= 200 && $curl['code'] <= 299 && $curl['response']) {
            $xml = @simplexml_load_string($curl['response']);
            if ($xml) {
                foreach ($xml->User as $xmlUser) {
                    $shared = [];
                    foreach ($xmlUser->attributes() as $key => $val) {
                        $shared[$key] = (string) $val;
                    }

                    $username = $shared['username'] ?: ($shared['title'] ?: ($shared['email'] ?: ($shared['id'] ?? '')));
                    $remoteId = $shared['id'] ?? '';
                    if (preg_match('/\/users\/(.+?)\/avatar/', $shared['thumb'] ?? '', $matches)) {
                        $remoteId = $matches[1];
                    }

                    $sharedId = $shared['id'] ?? '';
                    $existing = $seen[strtolower($username)] ?? ($seen[$remoteId] ?? ($sharedId && isset($seen[$sharedId]) ? $seen[$sharedId] : null));
                    if ($existing !== null) {
                        if ($sharedId) {
                            $accountAtIndex[$existing] = $sharedId;
                        }
                        if (($shared['email'] ?? '') && !$users[$existing]['email']) {
                            $users[$existing]['email'] = $shared['email'];
                        }
                        continue;
                    }

                    if (!$username) {
                        continue;
                    }

                    $index                       = count($users);
                    $users[]                     = [
                        'remote_id' => $remoteId,
                        'username'  => $username,
                        'email'     => $shared['email'] ?? '',
                        'user_type' => (!empty($shared['home']) && $shared['home'] != '0') ? 'home' : 'shared',
                        'is_admin'  => 0,
                        'last_seen' => 0,
                    ];
                    $seen[strtolower($username)] = $index;
                    $seen[$remoteId]             = $index;
                    if ($sharedId) {
                        $accountAtIndex[$index] = $sharedId;
                    }
                }
            }
        }

        if (!$users) {
            $curl     = curl($url . '/accounts', $headers, 'GET');
            $response = $this->decodePlexResponse($curl);
            $accounts = $response['MediaContainer']['Account'] ?? [];
            if ($accounts && !isset($accounts[0])) {
                $accounts = [$accounts];
            }
            if (is_array($accounts)) {
                foreach ($accounts as $account) {
                    $index   = count($users);
                    $users[] = [
                        'remote_id' => $account['id'] ?? '',
                        'username'  => $account['name'] ?? ($account['id'] ?? ''),
                        'email'     => '',
                        'user_type' => '',
                        'is_admin'  => !empty($account['default']) ? 1 : 0,
                        'last_seen' => 0,
                    ];
                    if ($account['id']) {
                        $accountAtIndex[$index] = $account['id'];
                    }
                }
            }
        }

        if (!$users) {
            return ['error' => true, 'message' => translate('mediaAppUsersFailed')];
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
        $curl      = curl($url . '/status/sessions/history/all?sort=viewedAt:desc&X-Plex-Container-Start=0&X-Plex-Container-Size=500', $headers, 'GET');
        $response  = $this->decodePlexResponse($curl);
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
        if ($value === '' || $value === null) {
            return 0;
        }
        if (is_numeric($value)) {
            return intval($value);
        }
        $time = strtotime($value);
        return $time ?: 0;
    }

    public function plexHeaders($token, $clientId = '')
    {
        return [
            'Accept: application/json',
            'X-Plex-Token: ' . $token,
            'X-Plex-Product: ' . APP_NAME,
            'X-Plex-Version: 1.0',
            'X-Plex-Client-Identifier: ' . ($clientId ?: md5(APP_NAME)),
        ];
    }

    public function decodePlexResponse($curl)
    {
        if (is_array($curl['response'])) {
            return $curl['response'];
        }

        $decoded = json_decode($curl['response'], true);
        if (is_array($decoded)) {
            return $decoded;
        }

        if (!$curl['response'] || !str_contains($curl['response'], '<')) {
            return [];
        }

        $xml = @simplexml_load_string($curl['response']);
        if (!$xml) {
            return [];
        }

        return json_decode(json_encode($xml), true);
    }

    public function plexCreateLibrary($url, $token, $title, $type, $paths)
    {
        $url  = rtrim(trim($url), '/');
        $type = $type == 'show' ? 'show' : 'movie';
        $query = [
            'name'     => $title,
            'type'     => $type,
            'agent'    => $type == 'show' ? 'tv.plex.agents.series' : 'tv.plex.agents.movie',
            'scanner'  => $type == 'show' ? 'Plex TV Series' : 'Plex Movie',
            'language' => 'en-US',
        ];
        $qs = http_build_query($query);
        foreach ($paths as $path) {
            $qs .= '&location=' . rawurlencode($path);
        }
        $curl = curl($url . '/library/sections?' . $qs, $this->plexHeaders($token), 'POST');
        if ($curl['code'] < 200 || $curl['code'] > 299) {
            return ['error' => true, 'message' => $curl['error'] ?: translate('couldNotSaveSettings')];
        }

        return ['error' => false];
    }

    public function plexCreateUser($url, $token, $username)
    {
        return ['error' => true, 'message' => translate('plexUserSyncSkipped')];
    }

    public function plexUpdateUser($url, $token, $remoteId, $username)
    {
        return ['error' => true, 'message' => translate('plexUserSyncSkipped')];
    }

    public function plexDeleteUser($url, $token, $remoteId)
    {
        return ['error' => true, 'message' => translate('plexUserSyncSkipped')];
    }
}
