<?php

/*
----------------------------------
------  Created: 091026   ------
------  Austin Best       ------
----------------------------------
*/

trait Jellyfin
{
    public function jellyfinIsOnline($url)
    {
        $url  = rtrim(trim($url), '/');
        $curl = curl(sprintf(MediaAppEndpoints::ENDPOINT_JELLYFIN_ONLINE, $url), ['Accept: application/json'], 'GET', '', [], 3);

        return $curl['code'] >= 200 && $curl['code'] <= 299;
    }

    public function jellyfinGetLibraries($url, $apikey, $full = true)
    {
        $url       = rtrim(trim($url), '/');
        $headers   = $this->jellyfinHeaders($apikey);
        $curl      = curl(sprintf(MediaAppEndpoints::ENDPOINT_JELLYFIN_LIBRARIES, $url), $headers, 'GET');
        $response  = is_array($curl['response']) ? $curl['response'] : json_decode($curl['response'], true);
        $folders   = $response['Items'] ?? [];
        $libraries = [];
        if (!is_array($folders)) {
            return $libraries;
        }

        foreach ($folders as $folder) {
            $path        = $folder['Path'] ?? '';
            $libraries[] = [
                'key'        => $folder['Id'] ?? '',
                'title'      => $folder['Name'] ?? '',
                'type'       => $folder['CollectionType'] ?? '',
                'paths'      => $path != '' ? [$path] : [],
                'updated_at' => 0,
                'scanned_at' => 0,
            ];
        }

        if (!$full) {
            return $libraries;
        }

        $virtualCurl     = curl(sprintf(MediaAppEndpoints::ENDPOINT_JELLYFIN_VIRTUAL_FOLDERS, $url), $headers, 'GET');
        $virtualResponse = is_array($virtualCurl['response']) ? $virtualCurl['response'] : json_decode($virtualCurl['response'], true);
        $virtualFolders  = [];
        if (is_array($virtualResponse)) {
            $virtualFolders = isset($virtualResponse[0]) || !$virtualResponse ? $virtualResponse : ($virtualResponse['Items'] ?? []);
        }
        if (is_array($virtualFolders)) {
            $pathsById   = [];
            $pathsByName = [];
            foreach ($virtualFolders as $folder) {
                $locations = $folder['Locations'] ?? [];
                if (!is_array($locations) || !$locations) {
                    continue;
                }
                $id   = $folder['ItemId'] ?? ($folder['Guid'] ?? '');
                $name = $folder['Name'] ?? '';
                if ($id) {
                    $pathsById[$id] = $locations;
                }
                if ($name) {
                    $pathsByName[$name] = $locations;
                }
            }
            foreach ($libraries as $index => $library) {
                if (!empty($pathsById[$library['key']])) {
                    $libraries[$index]['paths'] = $pathsById[$library['key']];
                } else if (!empty($pathsByName[$library['title']])) {
                    $libraries[$index]['paths'] = $pathsByName[$library['title']];
                }
            }
        }

        return $libraries;
    }

    public function jellyfinGetItems($url, $apikey, $libraryKeys = [], $seriesRemoteId = '', $seriesTitle = '', $since = 0)
    {
        $items = [
            'movies'   => [],
            'series'   => [],
            'episodes' => [],
        ];

        global $cron;

        $keys          = [];
        $libraryTitles = [];
        $libraryTitle  = '';
        foreach ($libraryKeys as $entry) {
            if (is_array($entry) && !empty($entry['key'])) {
                $keys[]                       = $entry['key'];
                $libraryTitles[$entry['key']] = $entry['title'] ?? '';
                $libraryTitle                 = $entry['title'] ?? $libraryTitle;
            } else if (!is_array($entry) && $entry != '' && $entry != null) {
                $keys[] = $entry;
            }
        }

        if ($seriesRemoteId) {
            $episodeTitle = $seriesTitle ?: $seriesRemoteId;
            if (!empty($cron)) {
                $cron->stopIfCancelled();
                $cron->log('fetching ' . $episodeTitle . ' episodes');
            }
            foreach ($this->jellyfinItems($url, $apikey, 'Episode', '', '', $seriesRemoteId, $since) as $row) {
                $row['_library']     = $libraryTitle;
                $items['episodes'][] = [
                    'remote_id'        => $row['Id'] ?? '',
                    'title'            => $row['Name'] ?? '',
                    'year'             => intval($row['ProductionYear'] ?? 0),
                    'path'             => $this->jellyfinItemPath($row),
                    'series_remote_id' => $row['SeriesId'] ?? $seriesRemoteId,
                    'series'           => $row['SeriesName'] ?? $seriesTitle,
                    'season'           => intval($row['ParentIndexNumber'] ?? 0),
                    'episode'          => intval($row['IndexNumber'] ?? 0),
                    'library'          => $libraryTitle,
                    'updated_at'       => $this->jellyfinDate($row['DateCreated'] ?? ''),
                    'duration'         => $this->jellyfinRuntimeSeconds($row),
                ];
            }
            return $items;
        }

        if (!$keys) {
            foreach ($this->jellyfinGetLibraries($url, $apikey) as $library) {
                if ($library['key']) {
                    $keys[]                         = $library['key'];
                    $libraryTitles[$library['key']] = $library['title'] ?? '';
                }
            }
        }

        $rows = [];
        foreach ($keys as $libraryKey) {
            if (!empty($cron)) {
                $cron->stopIfCancelled();
                $cron->log('fetching ' . ($libraryTitles[$libraryKey] ?? $libraryKey));
            }
            $includeTypes = 'Movie,Series,Episode';
            foreach ($this->jellyfinItems($url, $apikey, $includeTypes, '', '', $libraryKey, $since) as $row) {
                $row['_library'] = $libraryTitles[$libraryKey] ?? '';
                $rows[]          = $row;
            }
        }

        foreach ($rows as $row) {
            $type = $row['Type'] ?? '';
            $item = [
                'remote_id'        => $row['Id'] ?? '',
                'title'            => $row['Name'] ?? '',
                'year'             => intval($row['ProductionYear'] ?? 0),
                'path'             => $this->jellyfinItemPath($row),
                'series_remote_id' => $row['SeriesId'] ?? '',
                'series'           => $row['SeriesName'] ?? '',
                'season'           => intval($row['ParentIndexNumber'] ?? 0),
                'episode'          => intval($row['IndexNumber'] ?? 0),
                'library'          => $row['_library'] ?? '',
                'updated_at'       => $this->jellyfinDate($row['DateCreated'] ?? ''),
                'poster'           => $type == 'Episode' ? '' : $this->jellyfinPosterTag($row),
                'series_poster'    => strval($row['SeriesPrimaryImageTag'] ?? ''),
                'duration'         => $this->jellyfinRuntimeSeconds($row),
            ];
            if ($type == 'Movie') {
                $items['movies'][] = $item;
            } else if ($type == 'Series') {
                $items['series'][] = $item;
            } else if ($type == 'Episode') {
                $items['episodes'][] = $item;
            }
        }

        return $items;
    }

    public function jellyfinItems($url, $apikey, $includeTypes, $userId = '', $filters = '', $parentId = '', $since = 0)
    {
        global $cron;

        $url     = rtrim(trim($url), '/');
        $headers = $this->jellyfinHeaders($apikey);
        $rows    = [];
        $start   = 0;
        $limit   = 500;
        while (true) {
            if (!empty($cron)) {
                $cron->stopIfCancelled();
            }
            $query = 'Recursive=true&IncludeItemTypes=' . rawurlencode($includeTypes) . '&Fields=Path,MediaSources,ProviderIds,ParentId,IndexNumber,ParentIndexNumber,ProductionYear,SeriesId,SeriesPrimaryImageTag,UserData,DateCreated,ImageTags,RunTimeTicks&StartIndex=' . $start . '&Limit=' . $limit;
            if ($filters) {
                $query .= '&Filters=' . rawurlencode($filters);
            }
            if ($parentId) {
                $query .= '&ParentId=' . rawurlencode($parentId);
            }
            if (intval($since) > 0) {
                $query .= '&MinDateCreated=' . rawurlencode(gmdate('Y-m-d\TH:i:s\Z', intval($since)));
            }
            $request  = $userId ? sprintf(MediaAppEndpoints::ENDPOINT_JELLYFIN_USER_ITEMS, $url, $userId, $query) : sprintf(MediaAppEndpoints::ENDPOINT_JELLYFIN_ITEMS, $url, $query);
            $curl     = curl($request, $headers, 'GET');
            $response = is_array($curl['response']) ? $curl['response'] : json_decode($curl['response'], true);
            $found    = $response['Items'] ?? [];
            if (!is_array($found) || !$found) {
                break;
            }
            foreach ($found as $row) {
                $rows[] = $row;
            }
            if (!empty($cron) && !$userId) {
                $cron->log(($parentId ?: 'library') . ' items ' . count($rows));
            }
            $total  = intval($response['TotalRecordCount'] ?? count($found));
            $start += $limit;
            if ($start >= $total || count($found) < $limit) {
                break;
            }
        }

        return $rows;
    }

    public function jellyfinDate($value)
    {
        $value = trim($value);
        if ($value == '' || str_starts_with($value, '0001-')) {
            return 0;
        }

        $time = strtotime($value);
        return $time ?: 0;
    }

    public function jellyfinItemPath($item)
    {
        if (!empty($item['Path'])) {
            return $item['Path'];
        }

        $sources = $item['MediaSources'] ?? [];
        if ($sources && !isset($sources[0])) {
            $sources = [$sources];
        }
        if (is_array($sources)) {
            foreach ($sources as $source) {
                if (!empty($source['Path'])) {
                    return $source['Path'];
                }
            }
        }

        return '';
    }

    public function jellyfinRuntimeSeconds($row)
    {
        $ticks = intval($row['RunTimeTicks'] ?? 0);
        if ($ticks <= 0) {
            $sources = $row['MediaSources'] ?? [];
            if ($sources && !isset($sources[0])) {
                $sources = [$sources];
            }
            if (is_array($sources)) {
                foreach ($sources as $source) {
                    $ticks = intval($source['RunTimeTicks'] ?? 0);
                    if ($ticks > 0) {
                        break;
                    }
                }
            }
        }

        return mediaRuntimeSeconds($ticks, 'ticks');
    }

    public function jellyfinGetWatchStatus($url, $apikey, $remoteId)
    {
        $status = [
            'movies'   => [],
            'episodes' => [],
        ];
        if (!$remoteId) {
            return $status;
        }

        $played    = $this->jellyfinItems($url, $apikey, 'Movie,Episode', $remoteId, 'IsPlayed');
        $resumable = $this->jellyfinItems($url, $apikey, 'Movie,Episode', $remoteId, 'IsResumable');
        $rows      = array_merge($played, $resumable);

        foreach ($rows as $row) {
            $id = $row['Id'] ?? '';
            if (!$id) {
                continue;
            }
            $userData     = $row['UserData'] ?? [];
            $playedFlag   = !empty($userData['Played']) ? 1 : 0;
            $ticks        = intval($userData['PlaybackPositionTicks'] ?? 0);
            $inprogress   = $ticks > 0 ? intval($ticks / 10000000) : 0;
            $runtime      = $this->jellyfinRuntimeSeconds($row);
            $entry        = [
                'started'    => ($playedFlag || $inprogress) ? 1 : 0,
                'inprogress' => $playedFlag ? 0 : $inprogress,
                'finished'   => watchFinishedSeconds($playedFlag, $runtime),
            ];
            if (($row['Type'] ?? '') == 'Episode') {
                $status['episodes'][$id] = $entry;
            } else {
                $status['movies'][$id] = $entry;
            }
        }

        return $status;
    }

    public function jellyfinSetWatchStatus($url, $apikey, $userId, $remoteId, $started, $inprogress, $finished)
    {
        $url      = rtrim(trim($url), '/');
        $headers  = $this->jellyfinHeaders($apikey, $userId);
        $userPath = rawurlencode($userId);
        $itemPath = rawurlencode($remoteId);

        $itemCurl = curl(
            sprintf(MediaAppEndpoints::ENDPOINT_JELLYFIN_USER_ITEM, $url, $userPath, $itemPath) . '?fields=MediaSources&enableUserData=true&enableImages=false',
            $headers,
            'GET'
        );
        $itemCode = intval($itemCurl['code'] ?? 0);
        if ($itemCode < 200 || $itemCode >= 300) {
            global $cron;
            if ($itemCode == 404) {
                $cleared = $this->database->clearMediaLibraryRemote(MediaPlatforms::JELLYFIN, $remoteId);
                if (!empty($cron)) {
                    $cron->log('jellyfin skip setWatchStatus item=' . $remoteId . ' user=' . $userId . ' missing_or_inaccessible cleared=' . intval($cleared));
                }
            } else if (!empty($cron)) {
                $cron->log('jellyfin skip setWatchStatus item=' . $remoteId . ' user=' . $userId . ' missing_or_inaccessible');
            }
            return;
        }

        $item     = is_array($itemCurl['response'] ?? null) ? $itemCurl['response'] : [];
        $itemId   = strval($item['Id'] ?? $remoteId);
        $itemPath = rawurlencode($itemId);
        $playedUrl = sprintf(MediaAppEndpoints::ENDPOINT_JELLYFIN_PLAYED_ITEM, $url, $userPath, $itemPath)
            . '?' . http_build_query(['DatePlayed' => gmdate('c')]);

        if ($finished) {
            curl($playedUrl, $headers, 'POST');
            $payload = json_encode([
                'Played'                => true,
                'PlaybackPositionTicks' => '0',
                'LastPlayedDate'        => gmdate('c'),
            ]);
            curl(sprintf(MediaAppEndpoints::ENDPOINT_JELLYFIN_USER_DATA, $url, $userPath, $itemPath), $headers, 'POST', $payload);
            return;
        }

        curl(sprintf(MediaAppEndpoints::ENDPOINT_JELLYFIN_PLAYED_ITEM, $url, $userPath, $itemPath), $headers, 'DELETE');
        if ($started && $inprogress) {
            $payload = json_encode([
                'PlaybackPositionTicks' => strval(intval($inprogress) * 10000000),
                'Played'                => false,
                'LastPlayedDate'        => gmdate('c'),
            ]);
            curl(sprintf(MediaAppEndpoints::ENDPOINT_JELLYFIN_USER_DATA, $url, $userPath, $itemPath), $headers, 'POST', $payload);
        }
    }

    public function jellyfinTestConnection($url, $apikey)
    {
        $url = rtrim(trim($url), '/');
        if (!$url || !$apikey) {
            return ['error' => true, 'message' => translate('missingConnectionFields')];
        }

        if (str_contains($apikey, ':')) {
            $generated = $this->jellyfinGenerateAccessToken($url, $apikey);
            if ($generated['error']) {
                return $generated;
            }
            $apikey = $generated['apikey'];
        }

        $headers  = $this->jellyfinHeaders($apikey);
        $curl     = curl(sprintf(MediaAppEndpoints::ENDPOINT_JELLYFIN_INFO, $url), $headers, 'GET');
        $response = is_array($curl['response']) ? $curl['response'] : json_decode($curl['response'], true);

        if ($curl['code'] == 401) {
            return ['error' => true, 'message' => translate('mediaAppInvalidToken')];
        }
        if ($curl['code'] == 404) {
            return ['error' => true, 'message' => translate('mediaAppInvalidUrl')];
        }
        if ($curl['code'] < 200 || $curl['code'] > 299 || !is_array($response)) {
            return ['error' => true, 'message' => $curl['error'] ?: translate('mediaAppConnectionFailed')];
        }

        $serverId   = $response['Id'] ?? '';
        $serverName = $response['ServerName'] ?? '';
        if (!$serverId) {
            return ['error' => true, 'message' => translate('mediaAppConnectionFailed')];
        }

        $users = $this->jellyfinGetUsers($url, $apikey);
        if ($users['error']) {
            return $users;
        }

        return [
            'error'       => false,
            'server_id'   => $serverId,
            'server_name' => $serverName,
            'apikey'      => $apikey,
            'users'       => $users['users'],
        ];
    }

    public function jellyfinGetUsers($url, $apikey)
    {
        $url      = rtrim(trim($url), '/');
        $headers  = $this->jellyfinHeaders($apikey);
        $curl     = curl(sprintf(MediaAppEndpoints::ENDPOINT_JELLYFIN_USERS, $url), $headers, 'GET');
        $response = is_array($curl['response']) ? $curl['response'] : json_decode($curl['response'], true);

        if ($curl['code'] < 200 || $curl['code'] > 299 || !is_array($response)) {
            return ['error' => true, 'message' => translate('mediaAppUsersFailed')];
        }

        $users = [];
        foreach ($response as $user) {
            $users[] = [
                'remote_id' => $user['Id'] ?? '',
                'username'  => $user['Name'] ?? ($user['Id'] ?? ''),
                'email'     => '',
                'user_type' => '',
                'is_admin'  => !empty($user['Policy']['IsAdministrator']) ? 1 : 0,
                'last_seen' => $this->jellyfinTimestamp($user['LastActivityDate'] ?? ''),
            ];
        }

        if (!$users) {
            return ['error' => true, 'message' => translate('mediaAppUsersFailed')];
        }

        return ['error' => false, 'users' => $users, 'access' => $this->jellyfinAccessMapFromUsers($response)];
    }

    public function jellyfinCreateLibrary($url, $apikey, $title, $type, $paths)
    {
        $url       = rtrim(trim($url), '/');
        $pathInfos = [];
        $query     = http_build_query([
            'name'           => $title,
            'collectionType' => $type ?: 'mixed',
            'refreshLibrary' => 'true',
        ]);
        foreach ($paths as $path) {
            $query       .= '&paths=' . rawurlencode($path);
            $pathInfos[]  = ['Path' => $path];
        }
        $payload = json_encode(['LibraryOptions' => ['PathInfos' => $pathInfos]]);
        $curl    = curl(sprintf(MediaAppEndpoints::ENDPOINT_JELLYFIN_VIRTUAL_FOLDERS_Q, $url, $query), $this->jellyfinHeaders($apikey), 'POST', $payload);
        if ($curl['code'] < 200 || $curl['code'] > 299) {
            return ['error' => true, 'message' => $curl['error'] ?: translate('couldNotSaveSettings')];
        }

        return ['error' => false];
    }

    public function jellyfinDeleteLibrary($url, $apikey, $library)
    {
        $url  = rtrim(trim($url), '/');
        $name = trim(is_array($library) ? ($library['title'] ?? '') : $library);
        if ($name == '') {
            return ['error' => true, 'message' => translate('couldNotSaveSettings')];
        }

        $last = [];
        foreach (['false', 'true'] as $refresh) {
            $query = http_build_query([
                'name'           => $name,
                'refreshLibrary' => $refresh,
            ]);
            $last  = curl(sprintf(MediaAppEndpoints::ENDPOINT_JELLYFIN_VIRTUAL_FOLDERS_Q, $url, $query), $this->jellyfinHeaders($apikey), 'DELETE');
            if ($last['code'] >= 200 && $last['code'] <= 299) {
                return ['error' => false];
            }
        }

        return ['error' => true, 'message' => $this->embyApiError($last) ?: translate('couldNotSaveSettings')];
    }

    public function jellyfinCreateUser($url, $apikey, $username)
    {
        $url      = rtrim(trim($url), '/');
        $headers  = $this->jellyfinHeaders($apikey);
        $payload  = json_encode(['Name' => $username]);
        $curl     = curl(sprintf(MediaAppEndpoints::ENDPOINT_JELLYFIN_USER_NEW, $url), $headers, 'POST', $payload);
        $response = is_array($curl['response']) ? $curl['response'] : json_decode($curl['response'], true);
        $remoteId = $response['Id'] ?? '';
        if ($curl['code'] < 200 || $curl['code'] > 299 || !$remoteId) {
            return ['error' => true, 'message' => $curl['error'] ?: translate('mediaAppUsersFailed')];
        }

        $this->jellyfinSetUserLibraryAccess($url, $apikey, $remoteId, [], false);

        return ['error' => false, 'remote_id' => $remoteId];
    }

    public function jellyfinAccessMapFromUsers($rows)
    {
        $map = [];
        if (!is_array($rows)) {
            return $map;
        }

        foreach ($rows as $user) {
            if (!is_array($user)) {
                continue;
            }
            $access = $this->jellyfinPolicyLibraryAccess($user['Policy'] ?? []);
            foreach ([$user['Id'] ?? '', $user['Name'] ?? ''] as $ident) {
                $ident = trim(strval($ident));
                if ($ident == '') {
                    continue;
                }
                $map[$ident] = $access;
                $lower       = strtolower($ident);
                if ($lower != $ident) {
                    $map[$lower] = $access;
                }
            }
        }

        return $map;
    }

    public function jellyfinUserAccessMap($url, $apikey)
    {
        $url  = rtrim(trim($url), '/');
        $curl = curl(sprintf(MediaAppEndpoints::ENDPOINT_JELLYFIN_USERS, $url), $this->jellyfinHeaders($apikey), 'GET');
        $rows = is_array($curl['response']) ? $curl['response'] : json_decode($curl['response'], true);
        if ($curl['code'] < 200 || $curl['code'] > 299 || !is_array($rows)) {
            return [];
        }

        return $this->jellyfinAccessMapFromUsers($rows);
    }

    public function jellyfinGetUserLibraryKeys($url, $apikey, $user)
    {
        $url      = rtrim(trim($url), '/');
        $remoteId = $user['remote_id'] ?? '';
        if ($remoteId == '') {
            return ['all' => false, 'keys' => []];
        }

        $curl     = curl(sprintf(MediaAppEndpoints::ENDPOINT_JELLYFIN_USER, $url, rawurlencode($remoteId)), $this->jellyfinHeaders($apikey), 'GET');
        $response = is_array($curl['response']) ? $curl['response'] : json_decode($curl['response'], true);
        $policy   = is_array($response['Policy'] ?? null) ? $response['Policy'] : [];

        return $this->jellyfinPolicyLibraryAccess($policy);
    }

    public function jellyfinSetUserLibraryAccess($url, $apikey, $remoteId, $keys, $all)
    {
        $url = rtrim(trim($url), '/');
        if ($remoteId == '') {
            return ['error' => true, 'message' => translate('mediaAppUsersFailed')];
        }

        return $this->jellyfinPostUserPolicy($url, $apikey, $remoteId, $keys, $all);
    }

    public function jellyfinPostUserPolicy($url, $apikey, $remoteId, $keys, $all)
    {
        $headers  = $this->jellyfinHeaders($apikey);
        $curl     = curl(sprintf(MediaAppEndpoints::ENDPOINT_JELLYFIN_USER, $url, rawurlencode($remoteId)), $headers, 'GET');
        $response = is_array($curl['response']) ? $curl['response'] : json_decode($curl['response'], true);
        if ($curl['code'] < 200 || $curl['code'] > 299 || !is_array($response)) {
            return ['error' => true, 'message' => $curl['error'] ?: translate('mediaAppUsersFailed')];
        }

        $policy = is_array($response['Policy'] ?? null) ? $response['Policy'] : [];
        $policy = $this->jellyfinUserPolicyFolders($policy, $keys, $all);
        $body   = json_encode($policy);
        if ($body == false) {
            return ['error' => true, 'message' => translate('mediaAppUsersFailed')];
        }
        $curl = curl(sprintf(MediaAppEndpoints::ENDPOINT_JELLYFIN_USER_POLICY, $url, rawurlencode($remoteId)), $headers, 'POST', $body);
        if ($curl['code'] < 200 || $curl['code'] > 299) {
            return ['error' => true, 'message' => $curl['error'] ?: translate('mediaAppUsersFailed')];
        }

        return ['error' => false];
    }

    public function jellyfinUpdateUser($url, $apikey, $remoteId, $username)
    {
        $url      = rtrim(trim($url), '/');
        $headers  = $this->jellyfinHeaders($apikey);
        $curl     = curl(sprintf(MediaAppEndpoints::ENDPOINT_JELLYFIN_USER, $url, rawurlencode($remoteId)), $headers, 'GET');
        $response = is_array($curl['response']) ? $curl['response'] : json_decode($curl['response'], true);
        if ($curl['code'] < 200 || $curl['code'] > 299 || !is_array($response)) {
            return ['error' => true, 'message' => $curl['error'] ?: translate('mediaAppUsersFailed')];
        }

        $response['Name'] = $username;
        $payload          = json_encode($response);
        $curl             = curl(sprintf(MediaAppEndpoints::ENDPOINT_JELLYFIN_USER, $url, rawurlencode($remoteId)), $headers, 'POST', $payload);
        if ($curl['code'] < 200 || $curl['code'] > 299) {
            return ['error' => true, 'message' => $curl['error'] ?: translate('mediaAppUsersFailed')];
        }

        return ['error' => false];
    }

    public function jellyfinDeleteUser($url, $apikey, $remoteId)
    {
        $url     = rtrim(trim($url), '/');
        $headers = $this->jellyfinHeaders($apikey);
        $curl    = curl(sprintf(MediaAppEndpoints::ENDPOINT_JELLYFIN_USER, $url, rawurlencode($remoteId)), $headers, 'DELETE');
        if ($curl['code'] < 200 || $curl['code'] > 299) {
            return ['error' => true, 'message' => $curl['error'] ?: translate('mediaAppUsersFailed')];
        }

        return ['error' => false];
    }

    public function jellyfinTimestamp($value)
    {
        if ($value == '' || $value == null) {
            return 0;
        }
        $time = strtotime($value);
        return $time ?: 0;
    }

    public function jellyfinGenerateAccessToken($url, $credentials)
    {
        $parts    = explode(':', $credentials, 2);
        $username = $parts[0] ?? '';
        $password = $parts[1] ?? '';
        if (!$username || $password == '') {
            return ['error' => true, 'message' => translate('mediaAppInvalidToken')];
        }

        $headers  = [
            'Accept: application/json',
            'Authorization: MediaBrowser Client="' . APP_NAME . '", Device="' . APP_NAME . '", DeviceId="' . md5(APP_NAME) . '", Version="1.0"',
        ];
        $payload  = json_encode([
            'Username' => $username,
            'Pw'       => $password,
            'Password' => $password,
        ]);
        $curl     = curl(sprintf(MediaAppEndpoints::ENDPOINT_JELLYFIN_AUTH, $url), $headers, 'POST', $payload);
        $response = is_array($curl['response']) ? $curl['response'] : json_decode($curl['response'], true);
        $apikey   = $response['AccessToken'] ?? '';

        if ($curl['code'] < 200 || $curl['code'] > 299 || !$apikey) {
            return ['error' => true, 'message' => translate('mediaAppInvalidToken')];
        }

        return ['error' => false, 'apikey' => $apikey];
    }

    public function jellyfinPolicyLibraryAccess($policy)
    {
        $admin = !empty($policy['IsAdministrator']) || !empty($policy['isAdministrator']);
        $all   = $policy['EnableAllFolders'] ?? ($policy['enableAllFolders'] ?? null);
        if ($admin || $all == true || $all == 1 || $all == '1') {
            return ['all' => true, 'keys' => []];
        }

        $blocked = [];
        foreach ($policy['BlockedMediaFolders'] ?? ($policy['blockedMediaFolders'] ?? []) as $key) {
            if ($key != '') {
                $blocked[$key] = true;
            }
        }

        $keys = [];
        foreach ($policy['EnabledFolders'] ?? ($policy['enabledFolders'] ?? []) as $key) {
            if ($key != '' && empty($blocked[$key])) {
                $keys[] = $key;
            }
        }

        return ['all' => false, 'keys' => $keys];
    }

    public function jellyfinUserPolicyFolders($policy, $keys, $all)
    {
        $folders = !empty($all) ? [] : array_values($keys);
        $enable  = !empty($all);
        unset(
            $policy['EnableAllFolders'],
            $policy['enableAllFolders'],
            $policy['EnabledFolders'],
            $policy['enabledFolders'],
            $policy['BlockedMediaFolders'],
            $policy['blockedMediaFolders']
        );
        $policy['enableAllFolders']    = $enable;
        $policy['enabledFolders']      = $folders;
        $policy['blockedMediaFolders'] = null;

        return $policy;
    }

    public function jellyfinPosterTag($row)
    {
        $tags = $row['ImageTags'] ?? [];
        if (is_array($tags) && !empty($tags['Primary'])) {
            return strval($tags['Primary']);
        }

        return '';
    }

    public function jellyfinPosterUrl($url, $apikey, $remoteId, $poster)
    {
        $url      = rtrim(trim($url), '/');
        $remoteId = trim(strval($remoteId));
        if ($url == '' || $remoteId == '') {
            return '';
        }

        $query = 'api_key=' . rawurlencode($apikey) . '&maxWidth=80&maxHeight=120';
        if (trim(strval($poster)) != '') {
            $query .= '&tag=' . rawurlencode($poster);
        }

        return $url . '/Items/' . rawurlencode($remoteId) . '/Images/Primary?' . $query;
    }

    public function jellyfinHeaders($apikey, $userId = '')
    {
        $auth = 'MediaBrowser Token="' . $apikey . '", Client="' . APP_NAME . '", Device="' . APP_NAME . '", DeviceId="' . md5(APP_NAME) . '", Version="1.0"';
        if ($userId != '') {
            $auth .= ', UserId="' . $userId . '"';
        }

        return [
            'Accept: application/json',
            'X-Emby-Token: ' . $apikey,
            'Authorization: ' . $auth,
        ];
    }
}
