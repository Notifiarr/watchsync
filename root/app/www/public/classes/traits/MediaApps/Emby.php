<?php

/*
----------------------------------
------  Created: 091026   ------
------  Austin Best       ------
----------------------------------
*/

trait Emby
{
    protected $embyApiCache = [];

    public function embyApiGet($url, $headers = [])
    {
        $key = $url . "\n" . implode("\n", $headers);
        if (array_key_exists($key, $this->embyApiCache)) {
            return $this->embyApiCache[$key];
        }

        $curl                     = curl($url, $headers, 'GET');
        $this->embyApiCache[$key] = $curl;

        return $curl;
    }

    public function embyApiForget()
    {
        $this->embyApiCache = [];
    }

    public function embyIsOnline($url)
    {
        $url  = rtrim(trim($url), '/');
        $curl = curl(sprintf(MediaAppEndpoints::ENDPOINT_EMBY_ONLINE, $url), ['Accept: application/json'], 'GET', '', [], 3);

        return $curl['code'] >= 200 && $curl['code'] <= 299;
    }

    public function embyGetLibraries($url, $apikey, $full = true)
    {
        $url       = rtrim(trim($url), '/');
        $headers   = $this->embyHeaders($apikey);
        $curl      = curl(sprintf(MediaAppEndpoints::ENDPOINT_EMBY_LIBRARIES, $url), $headers, 'GET');
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

        $virtualCurl     = curl(sprintf(MediaAppEndpoints::ENDPOINT_EMBY_VIRTUAL_FOLDERS, $url), $headers, 'GET');
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

        foreach ($this->embySelectableFolders($url, $apikey) as $folder) {
            $guid  = $folder['Guid'] ?? '';
            $id    = $folder['Id'] ?? '';
            $name  = $folder['Name'] ?? '';
            $paths = [];
            foreach ($folder['SubFolders'] ?? [] as $sub) {
                $path = $sub['Path'] ?? '';
                if ($path != '') {
                    $paths[] = $path;
                }
            }
            foreach ($libraries as $index => $library) {
                if (($id != '' && $library['key'] == $id) || ($name != '' && $library['title'] == $name)) {
                    if ($guid != '') {
                        $libraries[$index]['guid'] = $guid;
                    }
                    if ($paths) {
                        $libraries[$index]['paths'] = $paths;
                    }
                    break;
                }
            }
        }

        return $libraries;
    }

    public function embySelectableFolders($url, $apikey)
    {
        $curl     = curl(sprintf(MediaAppEndpoints::ENDPOINT_EMBY_SELECTABLE_FOLDERS, rtrim(trim($url), '/')), $this->embyHeaders($apikey), 'GET');
        $response = is_array($curl['response']) ? $curl['response'] : json_decode($curl['response'], true);
        if (is_array($response['Items'] ?? null)) {
            $response = $response['Items'];
        }
        if ($response && is_array($response) && !isset($response[0]) && isset($response['Name'])) {
            $response = [$response];
        }

        return is_array($response) ? $response : [];
    }

    public function embyGetItems($url, $apikey, $libraryKeys = [], $seriesRemoteId = '', $seriesTitle = '', $since = 0)
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
            foreach ($this->embyItems($url, $apikey, 'Episode', '', '', $seriesRemoteId, $since) as $row) {
                $row['_library']     = $libraryTitle;
                $items['episodes'][] = [
                    'remote_id'        => $row['Id'] ?? '',
                    'title'            => $row['Name'] ?? '',
                    'year'             => intval($row['ProductionYear'] ?? 0),
                    'path'             => $this->embyItemPath($row),
                    'series_remote_id' => $row['SeriesId'] ?? $seriesRemoteId,
                    'series'           => $row['SeriesName'] ?? $seriesTitle,
                    'season'           => intval($row['ParentIndexNumber'] ?? 0),
                    'episode'          => intval($row['IndexNumber'] ?? 0),
                    'library'          => $libraryTitle,
                    'updated_at'       => $this->embyDate($row['DateCreated'] ?? ''),
                    'duration'         => $this->embyRuntimeSeconds($row),
                ];
            }
            return $items;
        }

        if (!$keys) {
            foreach ($this->embyGetLibraries($url, $apikey) as $library) {
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
            $libraryRows  = $this->embyItems($url, $apikey, $includeTypes, '', '', $libraryKey, $since);
            $movies       = 0;
            $series       = 0;
            $episodes     = 0;
            foreach ($libraryRows as $row) {
                $row['_library'] = $libraryTitles[$libraryKey] ?? '';
                $rows[]          = $row;
                $type            = $row['Type'] ?? '';
                if ($type == 'Movie') {
                    $movies++;
                } else if ($type == 'Series') {
                    $series++;
                } else if ($type == 'Episode') {
                    $episodes++;
                }
            }
            if (!empty($cron)) {
                $cron->log('overview ' . ($libraryTitles[$libraryKey] ?? $libraryKey) . ' movies=' . $movies . ' series=' . $series . ' episodes=' . $episodes);
            }
        }

        foreach ($rows as $row) {
            $type = $row['Type'] ?? '';
            $item = [
                'remote_id'        => $row['Id'] ?? '',
                'title'            => $row['Name'] ?? '',
                'year'             => intval($row['ProductionYear'] ?? 0),
                'path'             => $this->embyItemPath($row),
                'series_remote_id' => $row['SeriesId'] ?? '',
                'series'           => $row['SeriesName'] ?? '',
                'season'           => intval($row['ParentIndexNumber'] ?? 0),
                'episode'          => intval($row['IndexNumber'] ?? 0),
                'library'          => $row['_library'] ?? '',
                'updated_at'       => $this->embyDate($row['DateCreated'] ?? ''),
                'poster'           => $type == 'Episode' ? '' : $this->embyPosterTag($row),
                'series_poster'    => strval($row['SeriesPrimaryImageTag'] ?? ''),
                'duration'         => $this->embyRuntimeSeconds($row),
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

    public function embyItems($url, $apikey, $includeTypes, $userId = '', $filters = '', $parentId = '', $since = 0)
    {
        global $cron;

        $url     = rtrim(trim($url), '/');
        $headers = $this->embyHeaders($apikey);
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
            $request  = $userId ? sprintf(MediaAppEndpoints::ENDPOINT_EMBY_USER_ITEMS, $url, $userId, $query) : sprintf(MediaAppEndpoints::ENDPOINT_EMBY_ITEMS, $url, $query);
            $curl     = curl($request, $headers, 'GET');
            $response = is_array($curl['response']) ? $curl['response'] : json_decode($curl['response'], true);
            $found    = $response['Items'] ?? [];
            if (!is_array($found) || !$found) {
                break;
            }
            foreach ($found as $row) {
                $rows[] = $row;
            }
            $total  = intval($response['TotalRecordCount'] ?? count($found));
            $start += $limit;
            if ($start >= $total || count($found) < $limit) {
                break;
            }
        }

        return $rows;
    }

    public function embyDate($value)
    {
        $value = trim($value);
        if ($value == '' || str_starts_with($value, '0001-')) {
            return 0;
        }

        $time = strtotime($value);
        return $time ?: 0;
    }

    public function embyPosterTag($row)
    {
        $tags = $row['ImageTags'] ?? [];
        if (is_array($tags) && !empty($tags['Primary'])) {
            return strval($tags['Primary']);
        }

        return '';
    }

    public function embyPosterUrl($url, $apikey, $remoteId, $poster)
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

    public function embyItemPath($item)
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

    public function embyRuntimeSeconds($row)
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

    public function embyGetWatchStatus($url, $apikey, $remoteId)
    {
        $status = [
            'movies'   => [],
            'episodes' => [],
        ];
        if (!$remoteId) {
            $status['error'] = true;

            return $status;
        }

        $url     = rtrim(trim($url), '/');
        $headers = $this->embyHeaders($apikey);
        $probe   = curl(sprintf(MediaAppEndpoints::ENDPOINT_EMBY_USER, $url, rawurlencode($remoteId)), $headers, 'GET');
        $code    = intval($probe['code'] ?? 0);
        if ($code < 200 || $code >= 300) {
            $status['error'] = true;

            return $status;
        }

        $played    = $this->embyItems($url, $apikey, 'Movie,Episode', $remoteId, 'IsPlayed');
        $resumable = $this->embyItems($url, $apikey, 'Movie,Episode', $remoteId, 'IsResumable');
        $rows      = array_merge($played, $resumable);

        foreach ($rows as $row) {
            $id = $row['Id'] ?? '';
            if (!$id) {
                continue;
            }
            $userData   = $row['UserData'] ?? [];
            $playedFlag = !empty($userData['Played']) ? 1 : 0;
            $ticks      = intval($userData['PlaybackPositionTicks'] ?? 0);
            $inprogress = $ticks > 0 ? intval($ticks / 10000000) : 0;
            $runtime    = $this->embyRuntimeSeconds($row);
            $entry      = [
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

        global $cron;
        if (!empty($cron)) {
            $cron->log('overview movies=' . count($status['movies']) . ' episodes=' . count($status['episodes']));
        }

        return $status;
    }

    public function embySetWatchStatus($url, $apikey, $userId, $remoteId, $started, $inprogress, $finished)
    {
        $url      = rtrim(trim($url), '/');
        $headers  = $this->embyHeaders($apikey, $userId);
        $userPath = rawurlencode($userId);
        $itemPath = rawurlencode($remoteId);

        $itemCurl = curl(
            sprintf(MediaAppEndpoints::ENDPOINT_EMBY_USER_ITEM, $url, $userPath, $itemPath) . '?enableImages=false',
            $headers,
            'GET',
        );
        $itemCode = intval($itemCurl['code'] ?? 0);
        if ($itemCode < 200 || $itemCode >= 300) {
            global $cron;
            if ($itemCode == 404) {
                $cleared = $this->database->clearMediaLibraryRemote(MediaPlatforms::EMBY, $remoteId);
                if (!empty($cron)) {
                    $cron->log('emby skip setWatchStatus item=' . $remoteId . ' user=' . $userId . ' missing_or_inaccessible cleared=' . intval($cleared));
                }
            } else if (!empty($cron)) {
                $cron->log('emby skip setWatchStatus item=' . $remoteId . ' user=' . $userId . ' missing_or_inaccessible');
            }
            return;
        }

        $item      = is_array($itemCurl['response'] ?? null) ? $itemCurl['response'] : [];
        $itemId    = strval($item['Id'] ?? $remoteId);
        $itemPath  = rawurlencode($itemId);
        $playedUrl = sprintf(MediaAppEndpoints::ENDPOINT_EMBY_PLAYED_ITEM, $url, $userPath, $itemPath)
            . '?' . http_build_query(['DatePlayed' => date('YmdHis')]);

        if ($finished) {
            curl($playedUrl, $headers, 'POST');
            $payload = json_encode([
                'Played'                => true,
                'PlaybackPositionTicks' => '0',
                'LastPlayedDate'        => gmdate('c'),
            ]);
            curl(sprintf(MediaAppEndpoints::ENDPOINT_EMBY_USER_DATA, $url, $userPath, $itemPath), $headers, 'POST', $payload);
            return;
        }

        if ($started && $inprogress) {
            $userData = is_array($item['UserData'] ?? null) ? $item['UserData'] : [];
            $payload  = json_encode([
                'PlaybackPositionTicks' => strval(intval($inprogress) * 10000000),
                'Played'                => !empty($userData['Played']),
                'LastPlayedDate'        => gmdate('c'),
            ]);
            curl(sprintf(MediaAppEndpoints::ENDPOINT_EMBY_USER_DATA, $url, $userPath, $itemPath), $headers, 'POST', $payload);
            return;
        }

        curl(sprintf(MediaAppEndpoints::ENDPOINT_EMBY_PLAYED_ITEM, $url, $userPath, $itemPath), $headers, 'DELETE');
    }

    public function embyTestConnection($url, $apikey)
    {
        $url = rtrim(trim($url), '/');
        if (!$url || !$apikey) {
            return ['error' => true, 'message' => translate('missingConnectionFields')];
        }

        if (str_contains($apikey, ':')) {
            $generated = $this->embyGenerateAccessToken($url, $apikey);
            if ($generated['error']) {
                return $generated;
            }
            $apikey = $generated['apikey'];
        }

        $headers  = $this->embyHeaders($apikey);
        $curl     = curl(sprintf(MediaAppEndpoints::ENDPOINT_EMBY_INFO, $url), $headers, 'GET');
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

        $users = $this->embyGetUsers($url, $apikey);
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

    public function embyGetUsers($url, $apikey)
    {
        $url      = rtrim(trim($url), '/');
        $headers  = $this->embyHeaders($apikey);
        $curl     = $this->embyApiGet(sprintf(MediaAppEndpoints::ENDPOINT_EMBY_USERS, $url), $headers);
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
                'last_seen' => $this->embyTimestamp($user['LastActivityDate'] ?? ''),
            ];
        }

        if (!$users) {
            return ['error' => true, 'message' => translate('mediaAppUsersFailed')];
        }

        return ['error' => false, 'users' => $users, 'access' => $this->embyAccessMapFromUsers($response)];
    }

    public function embyCreateLibrary($url, $apikey, $title, $type, $paths)
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
        $curl    = curl(sprintf(MediaAppEndpoints::ENDPOINT_EMBY_VIRTUAL_FOLDERS_Q, $url, $query), $this->embyHeaders($apikey), 'POST', $payload);
        if ($curl['code'] < 200 || $curl['code'] > 299) {
            return ['error' => true, 'message' => $curl['error'] ?: translate('couldNotSaveSettings')];
        }

        return ['error' => false];
    }

    public function embyVirtualFolders($url, $apikey)
    {
        $url  = rtrim(trim($url), '/');
        $curl = curl(sprintf(MediaAppEndpoints::ENDPOINT_EMBY_VIRTUAL_FOLDERS, $url), $this->embyHeaders($apikey), 'GET');
        $data = is_array($curl['response']) ? $curl['response'] : json_decode($curl['response'], true);
        if (!is_array($data)) {
            return [];
        }
        if (isset($data['Items']) && is_array($data['Items'])) {
            $data = $data['Items'];
        }
        if ($data && !isset($data[0]) && isset($data['Name'])) {
            $data = [$data];
        }

        return is_array($data) ? $data : [];
    }

    public function embyVirtualFolder($url, $apikey, $library)
    {
        $library = is_array($library) ? $library : ['title' => $library];
        $title   = trim($library['title'] ?? '');
        $key     = strval($library['key'] ?? '');
        $guid    = strval($library['guid'] ?? '');
        $paths   = $this->libraryPathSet($library);

        foreach ($this->embyVirtualFolders($url, $apikey) as $folder) {
            if (!is_array($folder)) {
                continue;
            }
            $name = trim($folder['Name'] ?? '');
            $id   = strval($folder['ItemId'] ?? ($folder['Guid'] ?? ($folder['Id'] ?? '')));
            if ($name == '' && $id == '') {
                continue;
            }
            if (($key != '' && $id == $key) || ($guid != '' && $id == $guid) || ($title != '' && $name != '' && strcasecmp($name, $title) == 0)) {
                return ['name' => $name ?: $title, 'id' => $id ?: $key];
            }
            foreach ($folder['Locations'] ?? [] as $path) {
                $pathKey = $this->libraryPathKey($path);
                if ($pathKey != '' && !empty($paths[$pathKey])) {
                    return ['name' => $name ?: $title, 'id' => $id ?: $key];
                }
            }
        }

        return ['name' => $title, 'id' => $key];
    }

    public function embyDeleteLibrary($url, $apikey, $library)
    {
        $url    = rtrim(trim($url), '/');
        $folder = $this->embyVirtualFolder($url, $apikey, $library);
        $name   = $folder['name'] ?? '';
        $id     = $folder['id'] ?? '';
        if ($name == '' && $id == '') {
            return ['error' => true, 'message' => translate('couldNotSaveSettings')];
        }

        $headers = $this->embyHeaders($apikey, $this->embyAdminUserId($url, $apikey));
        $last    = [];
        $tries   = [];
        if ($id != '') {
            $tries[] = [
                'url'     => sprintf(MediaAppEndpoints::ENDPOINT_EMBY_VIRTUAL_FOLDERS_DELETE, $url, http_build_query([
                    'Id'             => $id,
                    'name'           => $name,
                    'refreshLibrary' => 'false',
                ])),
                'method'  => 'POST',
                'payload' => json_encode(['Id' => $id]),
            ];
            $tries[] = [
                'url'     => sprintf(MediaAppEndpoints::ENDPOINT_EMBY_VIRTUAL_FOLDERS_DELETE, $url, http_build_query([
                    'id'             => $id,
                    'name'           => $name,
                    'refreshLibrary' => 'false',
                ])),
                'method'  => 'POST',
                'payload' => '',
            ];
        }
        if ($name != '') {
            $tries[] = [
                'url'     => sprintf(MediaAppEndpoints::ENDPOINT_EMBY_VIRTUAL_FOLDERS_Q, $url, http_build_query([
                    'name'           => $name,
                    'refreshLibrary' => 'false',
                ])),
                'method'  => 'DELETE',
                'payload' => '',
            ];
        }

        foreach ($tries as $try) {
            $last = curl($try['url'], $headers, $try['method'], $try['payload']);
            if ($last['code'] >= 200 && $last['code'] <= 299) {
                return ['error' => false];
            }
        }

        return ['error' => true, 'message' => $this->embyApiError($last) ?: translate('couldNotSaveSettings')];
    }

    public function embyApiError($curl)
    {
        $response = $curl['response'] ?? '';
        if (is_array($response)) {
            foreach (['message', 'Message', 'error', 'Error'] as $key) {
                if (!empty($response[$key]) && is_string($response[$key])) {
                    return $response[$key];
                }
            }
        }
        if (is_string($response) && $response != '' && strlen($response) < 300 && !str_starts_with(ltrim($response), '<')) {
            return $response;
        }
        if (!empty($curl['error']) && is_string($curl['error'])) {
            return $curl['error'];
        }

        return '';
    }

    public function embyCreateUser($url, $apikey, $username)
    {
        $url      = rtrim(trim($url), '/');
        $headers  = $this->embyHeaders($apikey);
        $payload  = json_encode(['Name' => $username]);
        $curl     = curl(sprintf(MediaAppEndpoints::ENDPOINT_EMBY_USER_NEW, $url), $headers, 'POST', $payload);
        $response = is_array($curl['response']) ? $curl['response'] : json_decode($curl['response'], true);
        $remoteId = $response['Id'] ?? '';
        if ($curl['code'] < 200 || $curl['code'] > 299 || !$remoteId) {
            return ['error' => true, 'message' => $curl['error'] ?: translate('mediaAppUsersFailed')];
        }

        $this->embySetUserLibraryAccess($url, $apikey, $remoteId, [], false);
        $this->embyApiForget();

        return ['error' => false, 'remote_id' => $remoteId];
    }

    public function embyAccessMapFromUsers($rows)
    {
        $map = [];
        if (!is_array($rows)) {
            return $map;
        }

        foreach ($rows as $user) {
            if (!is_array($user)) {
                continue;
            }
            $access = $this->embyPolicyLibraryAccess($user['Policy'] ?? []);
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

    public function embyUserAccessMap($url, $apikey)
    {
        $url  = rtrim(trim($url), '/');
        $curl = $this->embyApiGet(sprintf(MediaAppEndpoints::ENDPOINT_EMBY_USERS, $url), $this->embyHeaders($apikey));
        $rows = is_array($curl['response']) ? $curl['response'] : json_decode($curl['response'], true);
        if ($curl['code'] < 200 || $curl['code'] > 299 || !is_array($rows)) {
            return [];
        }

        return $this->embyAccessMapFromUsers($rows);
    }

    public function embyGetUserLibraryKeys($url, $apikey, $user)
    {
        $url      = rtrim(trim($url), '/');
        $remoteId = $user['remote_id'] ?? '';
        if ($remoteId == '') {
            return ['all' => false, 'keys' => []];
        }

        $curl     = curl(sprintf(MediaAppEndpoints::ENDPOINT_EMBY_USER, $url, rawurlencode($remoteId)), $this->embyHeaders($apikey), 'GET');
        $response = is_array($curl['response']) ? $curl['response'] : json_decode($curl['response'], true);
        $policy   = is_array($response['Policy'] ?? null) ? $response['Policy'] : [];

        return $this->embyPolicyLibraryAccess($policy);
    }

    public function embySetUserLibraryAccess($url, $apikey, $remoteId, $keys, $all)
    {
        $url = rtrim(trim($url), '/');
        if ($remoteId == '') {
            return ['error' => true, 'message' => translate('mediaAppUsersFailed')];
        }

        return $this->embyPostUserPolicy($url, $apikey, $remoteId, $keys, $all);
    }

    public function embyPostUserPolicy($url, $apikey, $remoteId, $keys, $all)
    {
        $headers  = $this->embyHeaders($apikey);
        $curl     = curl(sprintf(MediaAppEndpoints::ENDPOINT_EMBY_USER, $url, rawurlencode($remoteId)), $headers, 'GET');
        $response = is_array($curl['response']) ? $curl['response'] : json_decode($curl['response'], true);
        if ($curl['code'] < 200 || $curl['code'] > 299 || !is_array($response)) {
            return ['error' => true, 'message' => $curl['error'] ?: translate('mediaAppUsersFailed')];
        }

        $policy = is_array($response['Policy'] ?? null) ? $response['Policy'] : [];
        $policy = $this->embyUserPolicyFolders($policy, $keys, $all, $this->embySelectableFolders($url, $apikey));
        $body   = json_encode($policy);
        if ($body == false) {
            return ['error' => true, 'message' => translate('mediaAppUsersFailed')];
        }
        $curl = curl(sprintf(MediaAppEndpoints::ENDPOINT_EMBY_USER_POLICY, $url, rawurlencode($remoteId)), $headers, 'POST', $body);
        if ($curl['code'] < 200 || $curl['code'] > 299) {
            return ['error' => true, 'message' => $curl['error'] ?: translate('mediaAppUsersFailed')];
        }

        return ['error' => false];
    }

    public function embyUpdateUser($url, $apikey, $remoteId, $username)
    {
        $url      = rtrim(trim($url), '/');
        $headers  = $this->embyHeaders($apikey);
        $curl     = curl(sprintf(MediaAppEndpoints::ENDPOINT_EMBY_USER, $url, rawurlencode($remoteId)), $headers, 'GET');
        $response = is_array($curl['response']) ? $curl['response'] : json_decode($curl['response'], true);
        if ($curl['code'] < 200 || $curl['code'] > 299 || !is_array($response)) {
            return ['error' => true, 'message' => $curl['error'] ?: translate('mediaAppUsersFailed')];
        }

        $response['Name'] = $username;
        $payload          = json_encode($response);
        $curl             = curl(sprintf(MediaAppEndpoints::ENDPOINT_EMBY_USER, $url, rawurlencode($remoteId)), $headers, 'POST', $payload);
        if ($curl['code'] < 200 || $curl['code'] > 299) {
            return ['error' => true, 'message' => $curl['error'] ?: translate('mediaAppUsersFailed')];
        }

        return ['error' => false];
    }

    public function embyDeleteUser($url, $apikey, $remoteId)
    {
        $url     = rtrim(trim($url), '/');
        $headers = $this->embyHeaders($apikey);
        $curl    = curl(sprintf(MediaAppEndpoints::ENDPOINT_EMBY_USER, $url, rawurlencode($remoteId)), $headers, 'DELETE');
        if ($curl['code'] < 200 || $curl['code'] > 299) {
            return ['error' => true, 'message' => $curl['error'] ?: translate('mediaAppUsersFailed')];
        }

        return ['error' => false];
    }

    public function embyTimestamp($value)
    {
        if ($value == '' || $value == null) {
            return 0;
        }
        $time = strtotime($value);
        return $time ?: 0;
    }

    public function embyGenerateAccessToken($url, $credentials)
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
        $curl     = curl(sprintf(MediaAppEndpoints::ENDPOINT_EMBY_AUTH, $url), $headers, 'POST', $payload);
        $response = is_array($curl['response']) ? $curl['response'] : json_decode($curl['response'], true);
        $apikey   = $response['AccessToken'] ?? '';

        if ($curl['code'] < 200 || $curl['code'] > 299 || !$apikey) {
            return ['error' => true, 'message' => translate('mediaAppInvalidToken')];
        }

        return ['error' => false, 'apikey' => $apikey];
    }

    public function embyPolicyLibraryAccess($policy)
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

    public function embyUserPolicyFolders($policy, $keys, $all, $selectable = [])
    {
        $wanted = [];
        foreach ($keys as $key) {
            if ($key != '') {
                $wanted[$key] = true;
            }
        }
        $folders  = [];
        $excluded = [];
        foreach ($selectable as $folder) {
            $guid = $folder['Guid'] ?? '';
            $id   = $folder['Id'] ?? '';
            $name = $folder['Name'] ?? '';
            $use  = !empty($all) || !empty($wanted[$guid]) || !empty($wanted[$id]) || ($name != '' && !empty($wanted[$name]));
            if ($guid == '') {
                $guid = $id;
            }
            if ($use) {
                if ($guid != '') {
                    $folders[] = $guid;
                }
                continue;
            }
            foreach ($folder['SubFolders'] ?? [] as $sub) {
                $subId = $sub['Id'] ?? '';
                if ($guid != '' && $subId != '') {
                    $excluded[] = $guid . '_' . $subId;
                }
            }
        }
        if (!$selectable) {
            $folders = array_keys($wanted);
        }

        $body = [];
        foreach ($policy as $key => $value) {
            $name = $key == '' ? $key : strtoupper($key[0]) . substr($key, 1);
            if (str_equals_any($name, ['EnableAllFolders', 'EnabledFolders', 'BlockedMediaFolders', 'ExcludedSubFolders'])) {
                continue;
            }
            $body[$name] = $value;
        }
        $body['EnableAllFolders']   = !empty($all);
        $body['EnabledFolders']     = !empty($all) ? [] : array_values(array_unique($folders));
        $body['ExcludedSubFolders'] = !empty($all) ? [] : $excluded;

        return $body;
    }

    public function embyAdminUserId($url, $apikey)
    {
        $users = $this->embyGetUsers($url, $apikey);
        foreach ($users['users'] ?? [] as $user) {
            if (!empty($user['is_admin']) && ($user['remote_id'] ?? '') != '') {
                return $user['remote_id'];
            }
        }

        return '';
    }

    public function embyHeaders($apikey, $userId = '')
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
