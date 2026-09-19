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
    protected $plexTvCache         = [];
    protected $plexDeletedCache    = [];

    public function plexTvGet($url, $headers = [])
    {
        $url    = rtrim(trim(strval($url)), '/');
        $accept = 'application/json';
        $token  = '';
        foreach ($headers as $header) {
            $lower = strtolower($header);
            if (str_starts_with($lower, 'accept:')) {
                $accept = trim(substr($header, 7));
            }
            if (str_starts_with($lower, 'x-plex-token:')) {
                $token = trim(substr($header, 13));
            }
        }
        $key = strtolower($url) . "\n" . strtolower($accept) . "\n" . $token;
        if (array_key_exists($key, $this->plexTvCache)) {
            return $this->plexTvCache[$key];
        }

        $curl                    = curl($url, $headers, 'GET');
        $this->plexTvCache[$key] = $curl;

        return $curl;
    }

    public function plexTvForget()
    {
        $this->plexTvCache         = [];
        $this->plexDeletedCache    = [];
        $this->plexSharedLibraries = null;
        $this->plexTvSections      = null;
        $this->deletedUserCache    = null;
        $this->plexDeletedChecked  = false;
    }

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
            $movies   = 0;
            $series   = 0;
            $episodes = 0;
            if ($library['type'] == 'movie') {
                foreach ($this->plexSectionItems($url, $token, $library['key'], 1, $library['title'] ?? '', '', $since) as $item) {
                    $item['library']   = $library['title'] ?? '';
                    $items['movies'][] = $item;
                    $movies++;
                }
            } else {
                foreach ($this->plexSectionItems($url, $token, $library['key'], 2, $library['title'] ?? '', '', $since) as $item) {
                    $item['library']   = $library['title'] ?? '';
                    $items['series'][] = $item;
                    $series++;
                }
                foreach ($this->plexSectionItems($url, $token, $library['key'], 4, $library['title'] ?? '', '', $since) as $item) {
                    $item['library']     = $library['title'] ?? '';
                    $items['episodes'][] = $item;
                    $episodes++;
                }
            }
            if (!empty($cron)) {
                $cron->log('overview ' . ($library['title'] ?? $library['key']) . ' movies=' . $movies . ' series=' . $series . ' episodes=' . $episodes);
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

    public function plexUserIsDeleted($user)
    {
        $deleted = strtolower(trim(strval($this->plexValue(is_array($user) ? $user : [], 'deleted'))));

        return $deleted == '1' || $deleted == 'true';
    }

    public function plexDeletedIdents($url, $token, $serverId = '')
    {
        $url      = rtrim(trim($url), '/');
        $token    = trim(strval($token ?? ''));
        $serverId = trim(strval($serverId ?? ''));
        $cacheKey = $token . "\n" . $serverId . "\n" . $url;
        if (isset($this->plexDeletedCache[$cacheKey])) {
            $this->plexDeletedChecked = !empty($this->plexDeletedCache[$cacheKey]['checked']);

            return $this->plexDeletedCache[$cacheKey]['deleted'];
        }

        $headers = $this->plexHeaders($token, $serverId);
        $live    = [];
        $deleted = [];
        $sawLive = false;
        $mark    = function (&$bucket, $idents) {
            foreach ($idents as $ident) {
                $ident = strtolower(trim(strval($ident)));
                if ($ident != '' && $ident != '0') {
                    $bucket[$ident] = true;
                }
            }
        };

        $curl     = $this->plexTvGet(MediaAppEndpoints::ENDPOINT_PLEX_TV_HOME_USERS, $headers);
        $response = is_array($curl['response']) ? $curl['response'] : json_decode($curl['response'], true);
        if ($curl['code'] >= 200 && $curl['code'] <= 299 && is_array($response['users'] ?? null)) {
            $sawLive = true;
            foreach ($response['users'] as $user) {
                $idents = array_merge($this->plexUserMatchNames($user), [strval($user['uuid'] ?? ''), strval($user['id'] ?? '')]);
                if ($this->plexUserIsDeleted($user)) {
                    $mark($deleted, $idents);
                    continue;
                }
                $mark($live, $idents);
            }
        }

        $curl = $this->plexTvGet(MediaAppEndpoints::ENDPOINT_PLEX_TV_USERS, $this->plexHeaders($token, $serverId, 'application/xml'));
        if ($curl['code'] >= 200 && $curl['code'] <= 299 && $curl['response']) {
            $xml = $this->plexLoadXml($curl['response']);
            if ($xml) {
                $sawLive = true;
                foreach ($xml->User as $xmlUser) {
                    $shared = [];
                    foreach ($xmlUser->attributes() as $key => $val) {
                        $shared[$key] = strval($val);
                    }
                    $remoteId = $shared['id'] ?? '';
                    if (preg_match('/\/users\/(.+?)\/avatar/', $shared['thumb'] ?? '', $matches)) {
                        $remoteId = $matches[1];
                    }
                    $idents = array_merge($this->plexUserMatchNames($shared), [$remoteId, $shared['id'] ?? '', $shared['email'] ?? '']);
                    if ($this->plexUserIsDeleted($shared)) {
                        $mark($deleted, $idents);
                        continue;
                    }
                    $mark($live, $idents);
                }
            }
        }

        if ($serverId) {
            $curl = $this->plexTvGet(sprintf(MediaAppEndpoints::ENDPOINT_PLEX_TV_SHARED_SERVERS, rawurlencode($serverId)), $this->plexHeaders($token, $serverId, 'application/xml'));
            if ($curl['code'] >= 200 && $curl['code'] <= 299 && $curl['response']) {
                $xml = $this->plexLoadXml($curl['response']);
                if ($xml) {
                    $sawLive = true;
                    $servers = $xml->SharedServer ?? [];
                    if (!$servers && isset($xml->Server)) {
                        $servers = $xml->Server->SharedServer ?? [];
                    }
                    foreach ($servers as $sharedServer) {
                        $shared = [];
                        foreach ($sharedServer->attributes() as $key => $val) {
                            $shared[$key] = strval($val);
                        }
                        $remoteId = $shared['userID'] ?? ($shared['invitedId'] ?? '');
                        if (preg_match('/\/users\/(.+?)\/avatar/', $shared['thumb'] ?? '', $matches)) {
                            $remoteId = $matches[1];
                        }
                        $idents = array_merge($this->plexUserMatchNames($shared), [$remoteId, $shared['userID'] ?? '', $shared['invitedId'] ?? '', $shared['email'] ?? '']);
                        if ($this->plexUserIsDeleted($shared)) {
                            $mark($deleted, $idents);
                            continue;
                        }
                        $mark($live, $idents);
                    }
                }
            }
        }

        if ($sawLive) {
            foreach ($this->plexServerAccounts($url, $token) as $account) {
                $id   = strval($this->plexValue($account, 'id'));
                $name = $this->plexValue($account, 'name');
                $uuid = $this->plexValue($account, 'uuid');
                if ($uuid == '' && preg_match('/\/users\/(.+?)\/avatar/', $this->plexValue($account, 'thumb'), $matches)) {
                    $uuid = $matches[1];
                }
                $idents  = [$id, $name, $uuid];
                $matched = false;
                foreach ($idents as $ident) {
                    $ident = strtolower(trim(strval($ident)));
                    if ($ident != '' && $ident != '0' && !empty($live[$ident])) {
                        $matched = true;
                        break;
                    }
                }
                if ($this->plexUserIsDeleted($account) || !$matched) {
                    $mark($deleted, $idents);
                }
            }
        }

        foreach (array_keys($live) as $ident) {
            unset($deleted[$ident]);
        }
        $this->plexDeletedChecked          = $sawLive;
        $this->plexDeletedCache[$cacheKey] = [
            'checked' => $sawLive,
            'deleted' => $deleted,
        ];

        return $deleted;
    }

    public function plexUserMatchesDeleted($user, $deleted)
    {
        if (!$user || !is_array($deleted) || !$deleted) {
            return false;
        }

        $idents = array_merge(
            $user['match_names'] ?? [],
            $user['match_ids'] ?? [],
            [strval($user['username'] ?? ''), strval($user['email'] ?? ''), strval($user['remote_id'] ?? '')],
        );
        foreach ($idents as $ident) {
            $ident = strtolower(trim(strval($ident)));
            if ($ident != '' && $ident != '0' && !empty($deleted[$ident])) {
                return true;
            }
        }

        return false;
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

    public function plexGetWatchStatus($mediaApp, $remoteId, $username = '', $userId = 0, $user = [])
    {
        $url        = $mediaApp['url'] ?? '';
        $adminToken = $mediaApp['token'] ?? '';
        $userId     = intval($userId ?: ($user['id'] ?? 0));
        if ($userId && !array_key_exists('token', $user)) {
            $loaded = $this->database->getMediaAppUser($userId);
            if ($loaded) {
                $user = array_merge($user, $loaded);
            }
        }

        $userToken  = trim(strval($user['token'] ?? ''));
        $missingPin = !empty($user['pin_required']) && trim(strval($user['pin'] ?? '')) == '';
        $scopeKeys  = $this->historyLibraryKeys(intval($mediaApp['id'] ?? 0));
        $status     = [];
        if ($userToken != '' && !$missingPin) {
            $library = $this->plexGetWatchStatusFromLibrary($url, $userToken, $username, $scopeKeys);
            if (!empty($library['auth'])) {
                if ($userId) {
                    $this->database->lockMediaAppUserToken($userId);
                }
                $this->plexWatchLog('token rejected ' . ($username ?: $userId) . ', using history');
            } else if (empty($library['error'])) {
                $status = [
                    'movies'   => $library['movies'] ?? [],
                    'episodes' => $library['episodes'] ?? [],
                ];
            } else {
                $this->plexWatchLog('library watch failed ' . ($username ?: $userId) . ', using history');
            }
        }

        $history  = $this->plexGetWatchStatusFromHistory($url, $adminToken, $remoteId, $username, $userId);
        $lastSeen = intval($history['last_seen'] ?? 0);
        unset($history['last_seen']);
        if (empty($status)) {
            $this->plexStoreUserLastSeen($userId, $lastSeen);
            return $history;
        }

        if (!$scopeKeys) {
            foreach (['movies', 'episodes'] as $kind) {
                foreach ($history[$kind] ?? [] as $itemId => $watch) {
                    $status[$kind][$itemId] = mergeWatchState($status[$kind][$itemId] ?? [], $watch);
                }
            }
        }
        $this->plexStoreUserLastSeen($userId, $lastSeen);

        return $status;
    }

    public function historyLibraryKeys($mediaAppId = 0)
    {
        global $cron;

        $list = !empty($cron) ? ($cron->sidecar['history_libraries'] ?? []) : [];
        if (!$list) {
            return [];
        }
        $keys = [];
        foreach ($list as $library) {
            if ($mediaAppId && intval($library['media_app_id'] ?? 0) != $mediaAppId) {
                continue;
            }
            $key = strval($library['key'] ?? '');
            if ($key != '') {
                $keys[$key] = true;
            }
        }

        return $keys;
    }

    public function plexStoreUserLastSeen($userId, $seen)
    {
        global $cron;

        $userId = intval($userId);
        $seen   = intval($seen);
        if (!$userId || $seen <= 0) {
            return;
        }
        if (!empty($cron) && $cron->isDryRun()) {
            return;
        }

        $this->database->updateMediaAppUserLastSeen($userId, $seen);
    }

    public function plexViewedAt($row)
    {
        $seen = $this->plexTimestamp($this->plexValue($row, 'viewedAt'));
        if (!$seen) {
            $seen = $this->plexTimestamp($this->plexValue($row, 'lastViewedAt'));
        }
        if ($seen > 20000000000) {
            $seen = intval($seen / 1000);
        }

        return $seen;
    }

    public function plexWatchLog($message)
    {
        global $cron;

        if (!empty($cron)) {
            $cron->log($message);
        }
    }

    public function plexGetWatchStatusFromLibrary($url, $token, $username = '', $scopeKeys = [])
    {
        $url     = rtrim(trim($url), '/');
        $headers = $this->plexHeaders($token);
        $curl    = curl(sprintf(MediaAppEndpoints::ENDPOINT_PLEX_LIBRARIES, $url), $headers, 'GET');
        $code    = intval($curl['code'] ?? 0);
        $empty   = [
            'movies'   => [],
            'episodes' => [],
            'auth'     => false,
            'error'    => true,
        ];
        if ($code == 401 || $code == 403) {
            $empty['auth'] = true;
            return $empty;
        }
        if ($code < 200 || $code > 299) {
            return $empty;
        }

        $response    = $this->parsePlexResponse($curl);
        $container   = $response['MediaContainer'] ?? $response;
        $directories = $container['Directory'] ?? ($response['Directory'] ?? []);
        if ($directories && !isset($directories[0])) {
            $directories = [$directories];
        }
        $usable = isset($response['MediaContainer']) || isset($response['size']) || isset($response['Directory']) || isset($response['@attributes']['size']) || $directories;
        if (!$usable) {
            $empty['auth'] = true;
            return $empty;
        }

        $status = [
            'movies'   => [],
            'episodes' => [],
            'auth'     => false,
            'error'    => false,
        ];
        if (!is_array($directories)) {
            return $status;
        }

        foreach ($directories as $directory) {
            $type = $this->plexValue($directory, 'type');
            if ($type != 'movie' && $type != 'show') {
                continue;
            }
            $key = $this->plexValue($directory, 'key');
            if ($key == '') {
                continue;
            }
            if ($scopeKeys && empty($scopeKeys[$key])) {
                continue;
            }
            $kinds           = $type == 'movie' ? [1] : [4];
            $sectionMovies   = 0;
            $sectionEpisodes = 0;
            foreach ($kinds as $itemType) {
                $section = $this->plexWatchSection($url, $token, $key, $itemType, $this->plexValue($directory, 'title'));
                if (!empty($section['auth']) || !empty($section['error'])) {
                    return [
                        'movies'   => [],
                        'episodes' => [],
                        'auth'     => !empty($section['auth']),
                        'error'    => true,
                    ];
                }
                $bucket = $itemType == 4 ? 'episodes' : 'movies';
                if ($itemType == 4) {
                    $sectionEpisodes = count($section['items'] ?? []);
                } else {
                    $sectionMovies = count($section['items'] ?? []);
                }
                foreach ($section['items'] ?? [] as $ratingKey => $watch) {
                    $status[$bucket][$ratingKey] = mergeWatchState($status[$bucket][$ratingKey] ?? [], $watch);
                }
            }
            $this->plexWatchLog('overview ' . ($username ?: 'user') . ' ' . $this->plexValue($directory, 'title') . ' movies=' . $sectionMovies . ' episodes=' . $sectionEpisodes);
        }

        return $status;
    }

    public function plexWatchSection($url, $token, $key, $type, $title = '')
    {
        $headers = $this->plexHeaders($token);
        $items   = [];
        $start   = 0;
        $size    = 500;

        while (true) {
            global $cron;
            if (!empty($cron)) {
                $cron->stopIfCancelled();
            }
            $request = sprintf(MediaAppEndpoints::ENDPOINT_PLEX_LIBRARY_ITEMS, $url, $key, intval($type), $start, $size);
            $curl    = curl($request, $headers, 'GET');
            $code    = intval($curl['code'] ?? 0);
            if ($code == 401 || $code == 403) {
                return ['auth' => true, 'error' => true, 'items' => []];
            }
            if ($code < 200 || $code > 299) {
                return ['auth' => false, 'error' => true, 'items' => []];
            }

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
                $watch = $this->plexWatchStateFromItem($row);
                if (!$watch) {
                    continue;
                }
                $ratingKey = $this->plexValue($row, 'ratingKey');
                if ($ratingKey == '') {
                    continue;
                }
                $items[$ratingKey] = mergeWatchState($items[$ratingKey] ?? [], $watch);
            }
            if (count($rows) < $size) {
                break;
            }
            $start += $size;
        }

        return ['auth' => false, 'error' => false, 'items' => $items];
    }

    public function plexWatchStateFromItem($row)
    {
        $viewCount  = intval($this->plexValue($row, 'viewCount'));
        $viewOffset = intval($this->plexValue($row, 'viewOffset'));
        $inprogress = $viewOffset > 0 ? intval($viewOffset / 1000) : 0;
        $runtime    = $this->plexRuntimeSeconds($row);
        if ($viewCount > 0) {
            return [
                'started'    => 1,
                'inprogress' => 0,
                'finished'   => watchFinishedSeconds(true, $runtime),
            ];
        }
        if ($inprogress > 0) {
            return [
                'started'    => 1,
                'inprogress' => $inprogress,
                'finished'   => 0,
            ];
        }

        return [];
    }

    public function plexGetWatchStatusFromHistory($url, $token, $remoteId, $username = '', $userId = 0)
    {
        $accountId = $this->plexAccountId($url, $token, $remoteId, $username);
        $status    = [
            'movies'    => [],
            'episodes'  => [],
            'last_seen' => 0,
        ];
        if (!$accountId) {
            return $status;
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
                $viewedAt = $this->plexViewedAt($row);
                if ($viewedAt > intval($status['last_seen'])) {
                    $status['last_seen'] = $viewedAt;
                }
                $page[]                      = $ratingKey . ':' . $viewedAt;
                $bucket                      = $type == 'episode' ? 'episodes' : 'movies';
                $viewOffset                  = intval($this->plexValue($row, 'viewOffset'));
                $inprogress                  = $viewOffset > 0 ? intval($viewOffset / 1000) : 0;
                $runtime                     = $this->plexRuntimeSeconds($row);
                $isFinished                  = (intval($this->plexValue($row, 'viewCount')) > 0 || (!$inprogress && $viewedAt));
                $finished                    = watchFinishedSeconds($isFinished, $runtime);
                $status[$bucket][$ratingKey] = mergeWatchState($status[$bucket][$ratingKey] ?? [], [
                    'started'    => 1,
                    'inprogress' => $finished ? 0 : $inprogress,
                    'finished'   => $finished,
                ]);
            }

            $totalSize = intval($this->plexValue($container, 'totalSize'));

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

        if (!empty($cron)) {
            $cron->log('overview ' . ($username ?: $accountId) . ' history movies=' . count($status['movies']) . ' episodes=' . count($status['episodes']));
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

        if ($started && $inprogress) {
            curl(sprintf(MediaAppEndpoints::ENDPOINT_PLEX_PROGRESS, $url, $key, intval($inprogress * 1000)), $headers, 'GET');
            return;
        }

        curl(sprintf(MediaAppEndpoints::ENDPOINT_PLEX_UNSCROBBLE, $url, $key), $headers, 'GET');
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
            if ($id == '' || $id == '0' || $name == '' || $name == '0' || $name == $id || $this->plexUserIsDeleted($account)) {
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

    public function plexGetUsers($url, $token, $serverId = '', $local = false, $fetchHomeTokens = true, $manualTokenNames = [])
    {
        if ($local) {
            return $this->plexGetLocalUsers($url, $token);
        }

        $url            = rtrim(trim($url), '/');
        $headers        = $this->plexHeaders($token, $serverId);
        $users          = [];
        $seen           = [];
        $accountAtIndex = [];

        $curl     = $this->plexTvGet(MediaAppEndpoints::ENDPOINT_PLEX_TV_HOME_USERS, $headers);
        $response = is_array($curl['response']) ? $curl['response'] : json_decode($curl['response'], true);
        if ($curl['code'] >= 200 && $curl['code'] <= 299 && is_array($response['users'] ?? null)) {
            foreach ($response['users'] as $user) {
                if ($this->plexUserIsDeleted($user)) {
                    continue;
                }
                $names    = $this->plexUserMatchNames($user);
                $username = $names[0] ?? '';
                $remoteId = strval($user['id'] ?? '');
                $uuid     = strval($user['uuid'] ?? '');
                if ($remoteId == '' || $remoteId == '0') {
                    $remoteId = $uuid;
                }
                if ($username == '' || $remoteId == '' || $remoteId == '0') {
                    continue;
                }
                $index   = count($users);
                $users[] = [
                    'remote_id'    => $remoteId,
                    'username'     => $username,
                    'email'        => $user['email'] ?? '',
                    'user_type'    => !empty($user['guest']) ? 'guest' : 'home',
                    'is_admin'     => !empty($user['admin']) ? 1 : 0,
                    'last_seen'    => 0,
                    'token'        => '',
                    'token_age'    => 0,
                    'pin_required' => ($user['pin'] ?? null) != null && strval($user['pin']) != '' ? 1 : 0,
                    'plex_uuid'    => strval($user['uuid'] ?? ''),
                    'match_ids'    => array_values(array_filter([$remoteId, strval($user['uuid'] ?? ''), strval($user['id'] ?? '')])),
                    'match_names'  => $names,
                ];
                foreach (array_merge($names, [$remoteId, strval($user['id'] ?? '')]) as $ident) {
                    $ident = strtolower(trim(strval($ident)));
                    if ($ident != '' && $ident != '0') {
                        $seen[$ident] = $index;
                    }
                }
            }
        }

        $curl = $this->plexTvGet(MediaAppEndpoints::ENDPOINT_PLEX_TV_USERS, $this->plexHeaders($token, $serverId, 'application/xml'));
        if ($curl['code'] >= 200 && $curl['code'] <= 299 && $curl['response']) {
            $xml = $this->plexLoadXml($curl['response']);
            if ($xml) {
                foreach ($xml->User as $xmlUser) {
                    $shared = [];
                    foreach ($xmlUser->attributes() as $key => $val) {
                        $shared[$key] = strval($val);
                    }
                    if ($this->plexUserIsDeleted($shared)) {
                        continue;
                    }

                    $names    = $this->plexUserMatchNames($shared);
                    $username = $names[0] ?? '';
                    $remoteId = strval($shared['id'] ?? '');
                    $uuid     = '';
                    if (preg_match('/\/users\/(.+?)\/avatar/', $shared['thumb'] ?? '', $matches)) {
                        $uuid = $matches[1];
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
                        'token'       => '',
                        'token_age'   => 0,
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
            $curl = $this->plexTvGet(sprintf(MediaAppEndpoints::ENDPOINT_PLEX_TV_SHARED_SERVERS, rawurlencode($serverId)), $this->plexHeaders($token, $serverId, 'application/xml'));
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
                        if ($this->plexUserIsDeleted($shared)) {
                            continue;
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
                        $accessToken = trim(strval($shared['accessToken'] ?? ''));
                        $existing    = null;
                        foreach (array_merge($names, [$remoteId, $shared['userID'] ?? '', $shared['invitedId'] ?? '']) as $ident) {
                            $ident = strtolower(trim(strval($ident)));
                            if ($ident != '' && isset($seen[$ident])) {
                                $existing = $seen[$ident];
                                break;
                            }
                        }
                        $manualToken = false;
                        foreach (array_merge([$username], $names) as $name) {
                            $name = strtolower(trim(strval($name)));
                            if ($name != '' && !empty($manualTokenNames[$name])) {
                                $manualToken = true;
                                break;
                            }
                        }
                        if ($existing != null) {
                            $users[$existing]['match_ids']   = array_values(array_unique(array_filter(array_merge($users[$existing]['match_ids'] ?? [], [$remoteId, $shared['userID'] ?? '', $shared['invitedId'] ?? '']))));
                            $users[$existing]['match_names'] = array_values(array_unique(array_merge($users[$existing]['match_names'] ?? [], $names)));
                            if ($accessToken != '' && !$manualToken) {
                                $users[$existing]['token']     = $accessToken;
                                $users[$existing]['token_age'] = time();
                            }
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
                            'token'       => (!$manualToken && $accessToken != '') ? $accessToken : '',
                            'token_age'   => (!$manualToken && $accessToken != '') ? time() : 0,
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
            if ($this->plexUserIsDeleted($account)) {
                continue;
            }
            if ($existing != null) {
                $users[$existing]['match_ids']   = array_values(array_unique(array_filter(array_merge($users[$existing]['match_ids'] ?? [], [$id, $uuid]))));
                $users[$existing]['match_names'] = array_values(array_unique(array_merge($users[$existing]['match_names'] ?? [], [$name])));
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
            $accountAtIndex[$index]     = $accountId;
        }

        $historyLastSeen = $this->plexHistoryLastSeen($url, $headers);
        foreach ($accountAtIndex as $index => $accountId) {
            if (!empty($historyLastSeen[$accountId])) {
                $users[$index]['last_seen'] = $historyLastSeen[$accountId];
            }
        }

        $this->plexFillUserTokens($token, $serverId, $users, $fetchHomeTokens, $manualTokenNames);

        return ['error' => false, 'users' => $users];
    }

    public function plexFillUserTokens($adminToken, $serverId, &$users, $fetchHomeTokens = true, $manualTokenNames = [])
    {
        $adminToken = trim(strval($adminToken ?? ''));
        $serverId   = trim(strval($serverId ?? ''));
        if ($adminToken == '' || !$users) {
            return;
        }

        foreach ($users as $index => $user) {
            $manualToken = false;
            foreach (array_merge([strval($user['username'] ?? '')], $user['match_names'] ?? []) as $name) {
                $name = strtolower(trim(strval($name)));
                if ($name != '' && !empty($manualTokenNames[$name])) {
                    $manualToken = true;
                    break;
                }
            }
            if ($manualToken) {
                continue;
            }
            if (trim(strval($user['token'] ?? '')) != '') {
                continue;
            }
            if (!empty($user['is_admin'])) {
                $users[$index]['token']     = $adminToken;
                $users[$index]['token_age'] = time();
                continue;
            }
            if (!$fetchHomeTokens) {
                continue;
            }
            $userType = strval($user['user_type'] ?? '');
            if ($userType != 'home' && $userType != 'guest') {
                continue;
            }
            $uuid = trim(strval($user['plex_uuid'] ?? ''));
            if ($uuid == '') {
                foreach ($user['match_ids'] ?? [] as $ident) {
                    $ident = trim(strval($ident));
                    if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $ident)) {
                        $uuid = $ident;
                        break;
                    }
                }
            }
            if ($uuid == '' || $serverId == '') {
                continue;
            }
            $minted = $this->plexMintHomeUserToken($adminToken, $serverId, $uuid);
            if ($minted != '') {
                $users[$index]['token']     = $minted;
                $users[$index]['token_age'] = time();
            }
        }
    }

    public function plexTokenCanReadLibraries($url, $token)
    {
        $url   = rtrim(trim(strval($url)), '/');
        $token = trim(strval($token ?? ''));
        if ($url == '' || $token == '') {
            return false;
        }

        $curl = curl(sprintf(MediaAppEndpoints::ENDPOINT_PLEX_LIBRARIES, $url), $this->plexHeaders($token), 'GET');
        if ($curl['code'] < 200 || $curl['code'] > 299) {
            return false;
        }

        $response = $this->parsePlexResponse($curl);
        return isset($response['MediaContainer']) || isset($response['size']) || isset($response['Directory']) || isset($response['@attributes']['size']);
    }

    public function plexFindUserUuid($url, $adminToken, $remoteId, $username)
    {
        $remoteId = trim(strval($remoteId ?? ''));
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $remoteId)) {
            return $remoteId;
        }

        $username = strtolower(trim(strval($username ?? '')));
        foreach ($this->plexServerAccounts($url, $adminToken) as $account) {
            $uuid = $this->plexValue($account, 'uuid');
            if ($uuid == '' && preg_match('/\/users\/(.+?)\/avatar/', $this->plexValue($account, 'thumb'), $matches)) {
                $uuid = $matches[1];
            }
            if ($uuid == '') {
                continue;
            }
            $id   = strval($this->plexValue($account, 'id'));
            $name = strtolower($this->plexValue($account, 'name'));
            if (($remoteId != '' && $remoteId != '0' && $remoteId == $id) || ($username != '' && $username == $name)) {
                return $uuid;
            }
        }

        return '';
    }

    public function plexSwitchHomeUser($adminToken, $serverId, $userUuid, $pin = '')
    {
        $adminToken = trim(strval($adminToken ?? ''));
        $userUuid   = trim(strval($userUuid ?? ''));
        $pin        = trim(strval($pin ?? ''));
        if ($adminToken == '' || $userUuid == '') {
            return '';
        }

        $result = $this->plexSwitchHomeUserResult($adminToken, $serverId, $userUuid, $pin);

        return $result['token'] ?? '';
    }

    public function plexFindHomeUser($adminToken, $serverId, $user)
    {
        $curl      = $this->plexTvGet(MediaAppEndpoints::ENDPOINT_PLEX_TV_HOME_USERS, $this->plexHeaders($adminToken, $serverId));
        $response  = is_array($curl['response']) ? $curl['response'] : json_decode(strval($curl['response'] ?? ''), true);
        $homeUsers = $response['users'] ?? [];
        if ($curl['code'] < 200 || $curl['code'] > 299 || !is_array($homeUsers)) {
            return [];
        }

        $remoteId = trim(strval($user['remote_id'] ?? ''));
        $username = strtolower(trim(strval($user['username'] ?? '')));
        $email    = strtolower(trim(strval($user['email'] ?? '')));
        foreach ($homeUsers as $homeUser) {
            if (!is_array($homeUser)) {
                continue;
            }
            $ids   = [strval($homeUser['id'] ?? ''), strval($homeUser['uuid'] ?? '')];
            $names = [];
            foreach ($this->plexUserMatchNames($homeUser) as $name) {
                $name = strtolower(trim(strval($name)));
                if ($name != '') {
                    $names[] = $name;
                }
            }
            if (($remoteId != '' && $remoteId != '0' && in_array($remoteId, $ids, true)) || ($username != '' && in_array($username, $names, true)) || ($email != '' && in_array($email, $names, true))) {
                return $homeUser;
            }
        }
        if (!empty($user['is_admin'])) {
            foreach ($homeUsers as $homeUser) {
                if (is_array($homeUser) && !empty($homeUser['admin'])) {
                    return $homeUser;
                }
            }
        }

        return [];
    }

    public function plexSwitchPin($adminToken, $serverId, $userId, $pin)
    {
        $adminToken = trim(strval($adminToken ?? ''));
        $userId     = trim(strval($userId ?? ''));
        $pin        = trim(strval($pin ?? ''));
        if ($adminToken == '' || $userId == '' || $userId == '0' || $pin == '') {
            return ['ok' => false, 'rejected' => false];
        }

        $isUuid = (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $userId);
        $url    = $isUuid
            ? sprintf(MediaAppEndpoints::ENDPOINT_PLEX_TV_HOME_USER_SWITCH, rawurlencode($userId))
            : sprintf(MediaAppEndpoints::ENDPOINT_PLEX_TV_HOME_USER_SWITCH_ID, rawurlencode($userId));
        $curl   = curl($url . '?pin=' . rawurlencode($pin), $this->plexHeaders($adminToken, $serverId), 'POST');
        if ($this->plexSwitchSucceeded($curl)) {
            return ['ok' => true, 'rejected' => false];
        }

        return ['ok' => false, 'rejected' => $this->plexPinIncorrect($curl)];
    }

    public function plexSwitchSucceeded($curl)
    {
        if (!is_array($curl) || $this->plexPinIncorrect($curl)) {
            return false;
        }
        $code = intval($curl['code'] ?? 0);
        if ($code >= 200 && $code <= 299) {
            return true;
        }

        return $this->plexSwitchAuthToken($curl) != '';
    }

    public function plexPinIncorrect($curl)
    {
        if (!is_array($curl)) {
            return false;
        }

        $body = $curl['response'] ?? '';
        if (is_array($body)) {
            $body = json_encode($body);
        }
        $body = strtolower(strval($body));

        return str_contains_any($body, ['incorrect pin', 'invalid pin']);
    }

    public function plexSwitchHomeUserResult($adminToken, $serverId, $userUuid, $pin = '')
    {
        $adminToken = trim(strval($adminToken ?? ''));
        $userUuid   = trim(strval($userUuid ?? ''));
        $pin        = trim(strval($pin ?? ''));
        if ($adminToken == '' || $userUuid == '') {
            return ['ok' => false, 'token' => '', 'rejected' => false];
        }

        $url      = sprintf(MediaAppEndpoints::ENDPOINT_PLEX_TV_HOME_USER_SWITCH, rawurlencode($userUuid));
        $headers  = $this->plexHeaders($adminToken, $serverId);
        $curl     = curl($url, $headers, 'POST', $pin == '' ? '' : json_encode(['pin' => $pin]));
        $token    = $this->plexSwitchAuthToken($curl);
        $rejected = $token == '' && $pin != '' && $this->plexPinRejected($curl);
        if ($token != '' || $pin == '' || $rejected) {
            return ['ok' => $token != '', 'token' => $token, 'rejected' => $rejected];
        }

        $curl  = curl($url . '?pin=' . rawurlencode($pin), $headers, 'POST');
        $token = $this->plexSwitchAuthToken($curl);

        return ['ok' => $token != '', 'token' => $token, 'rejected' => $this->plexPinRejected($curl)];
    }

    public function plexSwitchHomeUserById($adminToken, $serverId, $userId, $pin = '')
    {
        $adminToken = trim(strval($adminToken ?? ''));
        $userId     = trim(strval($userId ?? ''));
        $pin        = trim(strval($pin ?? ''));
        if ($adminToken == '' || $userId == '' || $userId == '0' || !ctype_digit($userId)) {
            return '';
        }

        $result = $this->plexSwitchHomeUserByIdResult($adminToken, $serverId, $userId, $pin);

        return $result['token'] ?? '';
    }

    public function plexSwitchHomeUserByIdResult($adminToken, $serverId, $userId, $pin = '')
    {
        $adminToken = trim(strval($adminToken ?? ''));
        $userId     = trim(strval($userId ?? ''));
        $pin        = trim(strval($pin ?? ''));
        if ($adminToken == '' || $userId == '' || $userId == '0' || !ctype_digit($userId)) {
            return ['ok' => false, 'token' => '', 'rejected' => false];
        }

        $url = sprintf(MediaAppEndpoints::ENDPOINT_PLEX_TV_HOME_USER_SWITCH_ID, rawurlencode($userId));
        if ($pin != '') {
            $url .= '?pin=' . rawurlencode($pin);
        }
        $curl  = curl($url, $this->plexHeaders($adminToken, $serverId), 'POST');
        $token = $this->plexSwitchAuthToken($curl);

        return [
            'ok'       => $token != '',
            'token'    => $token,
            'rejected' => $token == '' && $pin != '' && $this->plexPinRejected($curl),
        ];
    }

    public function plexPinRejected($curl)
    {
        if (!is_array($curl)) {
            return false;
        }

        $body = $curl['response'] ?? '';
        if (is_array($body)) {
            $body = json_encode($body);
        }
        $body = strtolower(strval($body));

        return str_contains_any($body, ['incorrect pin', 'invalid pin', 'pin required', 'missing pin']);
    }

    public function plexSwitchAuthToken($curl)
    {
        if (!is_array($curl) || intval($curl['code'] ?? 0) < 200 || intval($curl['code'] ?? 0) > 299) {
            return '';
        }

        $response = $this->parsePlexResponse($curl);
        $token    = $this->plexAuthTokenFromResponse($response);
        if ($token != '') {
            return $token;
        }
        if (isset($response['user']) && is_array($response['user'])) {
            return $this->plexAuthTokenFromResponse($response['user']);
        }

        return '';
    }

    public function plexAuthTokenFromResponse($response)
    {
        if (!is_array($response)) {
            return '';
        }

        $token = trim(strval($response['authToken'] ?? $response['authenticationToken'] ?? ''));
        if ($token != '') {
            return $token;
        }
        $attributes = $response['@attributes'] ?? [];
        if (!is_array($attributes)) {
            return '';
        }

        return trim(strval($attributes['authToken'] ?? $attributes['authenticationToken'] ?? ''));
    }

    public function plexMintHomeUserToken($adminToken, $serverId, $userUuid)
    {
        $adminToken = trim(strval($adminToken ?? ''));
        $serverId   = trim(strval($serverId ?? ''));
        $userUuid   = trim(strval($userUuid ?? ''));
        if ($adminToken == '' || $serverId == '' || $userUuid == '') {
            return '';
        }

        $curl = curl(
            sprintf(MediaAppEndpoints::ENDPOINT_PLEX_TV_HOME_USER_SWITCH, rawurlencode($userUuid)),
            $this->plexHeaders($adminToken, $serverId),
            'POST',
        );
        if ($curl['code'] < 200 || $curl['code'] > 299) {
            return '';
        }
        $response  = is_array($curl['response']) ? $curl['response'] : json_decode(strval($curl['response']), true);
        $tempToken = trim(strval($response['authToken'] ?? ''));
        if ($tempToken == '') {
            return '';
        }

        $curl = curl(
            MediaAppEndpoints::ENDPOINT_PLEX_TV_RESOURCES,
            $this->plexHeaders($tempToken, $serverId),
            'GET',
        );
        if ($curl['code'] < 200 || $curl['code'] > 299) {
            return '';
        }
        $resources = is_array($curl['response']) ? $curl['response'] : json_decode(strval($curl['response']), true);
        if (!is_array($resources)) {
            return '';
        }

        foreach ($resources as $server) {
            if (!is_array($server)) {
                continue;
            }
            if (($server['provides'] ?? '') != 'server') {
                continue;
            }
            if (strval($server['clientIdentifier'] ?? '') != $serverId) {
                continue;
            }
            return trim(strval($server['accessToken'] ?? ''));
        }

        return '';
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

        $this->plexTvForget();

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
        $curl                 = $this->plexTvGet(sprintf(MediaAppEndpoints::ENDPOINT_PLEX_TV_SERVER, rawurlencode($serverId)), $this->plexHeaders($token, $serverId, 'application/xml'));
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
        $curl     = $this->plexTvGet(MediaAppEndpoints::ENDPOINT_PLEX_TV_HOME_USERS, $this->plexHeaders($token, $serverId));
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
        $curl = $this->plexTvGet(sprintf(MediaAppEndpoints::ENDPOINT_PLEX_TV_SHARED_SERVERS, rawurlencode($serverId)), $this->plexHeaders($token, $serverId, 'application/xml'));
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
            $curl = curl(sprintf(MediaAppEndpoints::ENDPOINT_PLEX_TV_SHARED_SERVER, rawurlencode($serverId), rawurlencode($shareId)), $headers, 'DELETE');
            $this->plexTvForget();
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

        $this->plexTvForget();
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

        return $value == '1' || $value == 'true';
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
            $curl = $this->plexTvGet(sprintf(MediaAppEndpoints::ENDPOINT_PLEX_TV_SHARED_SERVERS, rawurlencode($serverId)), $xmlHeaders);
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

        $curl = $this->plexTvGet(MediaAppEndpoints::ENDPOINT_PLEX_TV_USERS, $xmlHeaders);
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

        $this->plexLinkHomeUserShares($token, $serverId);
        if (!$this->plexSharedLibraries) {
            $this->plexLinkHomeUsersXml($token, $serverId, $sectionMap);
            $this->plexLinkHomeUserShares($token, $serverId);
        }

        return $this->plexSharedLibraries;
    }

    public function plexSharedServerDetail($token, $serverId, $shareId, $sectionMap)
    {
        $empty = ['all' => false, 'keys' => []];
        $curl  = $this->plexTvGet(sprintf(MediaAppEndpoints::ENDPOINT_PLEX_TV_SHARED_SERVER, rawurlencode($serverId), rawurlencode($shareId)), $this->plexHeaders($token, $serverId, 'application/xml'));
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

        $curl = $this->plexTvGet(sprintf(MediaAppEndpoints::ENDPOINT_PLEX_TV_SHARED_SERVER_V2, rawurlencode($shareId)), $this->plexHeaders($token, $serverId));
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

        $curl = $this->plexTvGet(MediaAppEndpoints::ENDPOINT_PLEX_TV_HOME_USERS_XML, $this->plexHeaders($token, $serverId, 'application/xml'));
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
        $curl     = $this->plexTvGet(MediaAppEndpoints::ENDPOINT_PLEX_TV_HOME_USERS, $this->plexHeaders($token, $serverId));
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
