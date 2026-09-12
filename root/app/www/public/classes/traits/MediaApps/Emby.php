<?php

/*
----------------------------------
------  Created: 091026   ------
------  Austin Best       ------
----------------------------------
*/

trait Emby
{
    public function embyIsOnline($url)
    {
        $url  = rtrim(trim($url), '/');
        $curl = curl($url . '/System/Info/Public', ['Accept: application/json'], 'GET', '', [], 3);

        return $curl['code'] >= 200 && $curl['code'] <= 299;
    }

    public function embyGetLibraries($url, $apikey)
    {
        $url      = rtrim(trim($url), '/');
        $headers  = $this->embyJellyfinHeaders($apikey);
        $curl     = curl($url . '/Library/MediaFolders', $headers, 'GET');
        $response = is_array($curl['response']) ? $curl['response'] : json_decode($curl['response'], true);
        $folders  = $response['Items'] ?? [];
        $libraries = [];
        if (!is_array($folders)) {
            return $libraries;
        }

        foreach ($folders as $folder) {
            $path = $folder['Path'] ?? '';
            $libraries[] = [
                'key'        => $folder['Id'] ?? '',
                'title'      => $folder['Name'] ?? '',
                'type'       => $folder['CollectionType'] ?? '',
                'paths'      => $path !== '' ? [$path] : [],
                'updated_at' => 0,
                'scanned_at' => 0,
            ];
        }

        $virtualCurl     = curl($url . '/Library/VirtualFolders', $headers, 'GET');
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
                $keys[] = $entry['key'];
                $libraryTitles[$entry['key']] = $entry['title'] ?? '';
                $libraryTitle = $entry['title'] ?? $libraryTitle;
            } else if (!is_array($entry) && $entry !== '' && $entry !== null) {
                $keys[] = $entry;
            }
        }

        if ($seriesRemoteId) {
            $episodeTitle = $seriesTitle ?: $seriesRemoteId;
            if (!empty($cron)) {
                $cron->stopIfCancelled();
                $cron->log('fetching ' . $episodeTitle . ' episodes');
            }
            foreach ($this->embyJellyfinItems($url, $apikey, 'Episode', '', '', $seriesRemoteId, $since) as $row) {
                $row['_library'] = $libraryTitle;
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
                ];
            }
            return $items;
        }

        if (!$keys) {
            foreach ($this->embyGetLibraries($url, $apikey) as $library) {
                if ($library['key']) {
                    $keys[] = $library['key'];
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
            foreach ($this->embyJellyfinItems($url, $apikey, $includeTypes, '', '', $libraryKey, $since) as $row) {
                $row['_library'] = $libraryTitles[$libraryKey] ?? '';
                $rows[] = $row;
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

    public function embyJellyfinItems($url, $apikey, $includeTypes, $userId = '', $filters = '', $parentId = '', $since = 0)
    {
        global $cron;

        $url     = rtrim(trim($url), '/');
        $headers = $this->embyJellyfinHeaders($apikey);
        $rows    = [];
        $start   = 0;
        $limit   = 500;
        $path    = $userId ? '/Users/' . $userId . '/Items' : '/Items';

        while (true) {
            if (!empty($cron)) {
                $cron->stopIfCancelled();
            }
            $query = 'Recursive=true&IncludeItemTypes=' . rawurlencode($includeTypes) . '&Fields=Path,MediaSources,ProviderIds,ParentId,IndexNumber,ParentIndexNumber,ProductionYear,SeriesId,UserData,DateCreated&StartIndex=' . $start . '&Limit=' . $limit;
            if ($filters) {
                $query .= '&Filters=' . rawurlencode($filters);
            }
            if ($parentId) {
                $query .= '&ParentId=' . rawurlencode($parentId);
            }
            if (intval($since) > 0) {
                $query .= '&MinDateCreated=' . rawurlencode(gmdate('Y-m-d\TH:i:s\Z', intval($since)));
            }
            $curl     = curl($url . $path . '?' . $query, $headers, 'GET');
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
            $total = intval($response['TotalRecordCount'] ?? count($found));
            $start += $limit;
            if ($start >= $total || count($found) < $limit) {
                break;
            }
        }

        return $rows;
    }

    public function embyDate($value)
    {
        $value = trim((string) $value);
        if ($value === '' || str_starts_with($value, '0001-')) {
            return 0;
        }

        $time = strtotime($value);
        return $time ?: 0;
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

    public function embyGetWatchStatus($url, $apikey, $remoteId, $username = '')
    {
        $status = [
            'movies'   => [],
            'episodes' => [],
        ];
        if (!$remoteId) {
            return $status;
        }

        $played    = $this->embyJellyfinItems($url, $apikey, 'Movie,Episode', $remoteId, 'IsPlayed');
        $resumable = $this->embyJellyfinItems($url, $apikey, 'Movie,Episode', $remoteId, 'IsResumable');
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
            $entry      = [
                'started'    => ($playedFlag || $inprogress) ? 1 : 0,
                'inprogress' => $playedFlag ? 0 : $inprogress,
                'finished'   => $playedFlag,
            ];
            if (($row['Type'] ?? '') == 'Episode') {
                $status['episodes'][$id] = $entry;
            } else {
                $status['movies'][$id] = $entry;
            }
        }

        return $status;
    }

    public function embySetWatchStatus($url, $apikey, $userId, $remoteId, $started, $inprogress, $finished)
    {
        $url     = rtrim(trim($url), '/');
        $headers = $this->embyJellyfinHeaders($apikey);
        $userId  = rawurlencode($userId);
        $itemId  = rawurlencode($remoteId);

        if ($finished) {
            curl($url . '/Users/' . $userId . '/PlayedItems/' . $itemId, $headers, 'POST');
            return;
        }

        curl($url . '/Users/' . $userId . '/PlayedItems/' . $itemId, $headers, 'DELETE');
        if ($started && $inprogress) {
            $payload = json_encode([
                'PlaybackPositionTicks' => intval($inprogress) * 10000000,
                'Played'                => false,
            ]);
            curl($url . '/Users/' . $userId . '/Items/' . $itemId . '/UserData', $headers, 'POST', $payload);
        }
    }

    public function testEmbyJellyfinConnection($url, $apikey)
    {
        $url = rtrim(trim($url), '/');
        if (!$url || !$apikey) {
            return ['error' => true, 'message' => translate('missingConnectionFields')];
        }

        if (str_contains($apikey, ':')) {
            $generated = $this->generateAccessToken($url, $apikey);
            if ($generated['error']) {
                return $generated;
            }
            $apikey = $generated['apikey'];
        }

        $headers  = $this->embyJellyfinHeaders($apikey);
        $curl     = curl($url . '/System/Info', $headers, 'GET');
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

        $users = $this->getEmbyJellyfinUsers($url, $apikey);
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

    public function getEmbyJellyfinUsers($url, $apikey)
    {
        $url      = rtrim(trim($url), '/');
        $headers  = $this->embyJellyfinHeaders($apikey);
        $curl     = curl($url . '/Users', $headers, 'GET');
        $response = is_array($curl['response']) ? $curl['response'] : json_decode($curl['response'], true);

        if ($curl['code'] < 200 || $curl['code'] > 299 || !is_array($response)) {
            return ['error' => true, 'message' => translate('mediaAppUsersFailed')];
        }

        $users = [];
        foreach ($response as $user) {
            $users[] = [
                'remote_id'  => $user['Id'] ?? '',
                'username'   => $user['Name'] ?? ($user['Id'] ?? ''),
                'email'      => '',
                'user_type'  => '',
                'is_admin'   => !empty($user['Policy']['IsAdministrator']) ? 1 : 0,
                'last_seen'  => $this->embyTimestamp($user['LastActivityDate'] ?? ''),
            ];
        }

        if (!$users) {
            return ['error' => true, 'message' => translate('mediaAppUsersFailed')];
        }

        return ['error' => false, 'users' => $users];
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
            $query      .= '&paths=' . rawurlencode($path);
            $pathInfos[] = ['Path' => $path];
        }
        $payload = json_encode(['LibraryOptions' => ['PathInfos' => $pathInfos]]);
        $curl    = curl($url . '/Library/VirtualFolders?' . $query, $this->embyJellyfinHeaders($apikey), 'POST', $payload);
        if ($curl['code'] < 200 || $curl['code'] > 299) {
            return ['error' => true, 'message' => $curl['error'] ?: translate('couldNotSaveSettings')];
        }

        return ['error' => false];
    }

    public function embyCreateUser($url, $apikey, $username)
    {
        $url      = rtrim(trim($url), '/');
        $headers  = $this->embyJellyfinHeaders($apikey);
        $payload  = json_encode(['Name' => $username]);
        $curl     = curl($url . '/Users/New', $headers, 'POST', $payload);
        $response = is_array($curl['response']) ? $curl['response'] : json_decode($curl['response'], true);
        $remoteId = $response['Id'] ?? '';
        if ($curl['code'] < 200 || $curl['code'] > 299 || !$remoteId) {
            return ['error' => true, 'message' => $curl['error'] ?: translate('mediaAppUsersFailed')];
        }

        return ['error' => false, 'remote_id' => $remoteId];
    }

    public function embyUpdateUser($url, $apikey, $remoteId, $username)
    {
        $url      = rtrim(trim($url), '/');
        $headers  = $this->embyJellyfinHeaders($apikey);
        $curl     = curl($url . '/Users/' . rawurlencode($remoteId), $headers, 'GET');
        $response = is_array($curl['response']) ? $curl['response'] : json_decode($curl['response'], true);
        if ($curl['code'] < 200 || $curl['code'] > 299 || !is_array($response)) {
            return ['error' => true, 'message' => $curl['error'] ?: translate('mediaAppUsersFailed')];
        }

        $response['Name'] = $username;
        $payload = json_encode($response);
        $curl    = curl($url . '/Users/' . rawurlencode($remoteId), $headers, 'POST', $payload);
        if ($curl['code'] < 200 || $curl['code'] > 299) {
            return ['error' => true, 'message' => $curl['error'] ?: translate('mediaAppUsersFailed')];
        }

        return ['error' => false];
    }

    public function embyDeleteUser($url, $apikey, $remoteId)
    {
        $url     = rtrim(trim($url), '/');
        $headers = $this->embyJellyfinHeaders($apikey);
        $curl    = curl($url . '/Users/' . rawurlencode($remoteId), $headers, 'DELETE');
        if ($curl['code'] < 200 || $curl['code'] > 299) {
            return ['error' => true, 'message' => $curl['error'] ?: translate('mediaAppUsersFailed')];
        }

        return ['error' => false];
    }

    public function embyTimestamp($value)
    {
        if ($value === '' || $value === null) {
            return 0;
        }
        $time = strtotime($value);
        return $time ?: 0;
    }

    public function generateAccessToken($url, $credentials)
    {
        $parts = explode(':', $credentials, 2);
        $username = $parts[0] ?? '';
        $password = $parts[1] ?? '';
        if (!$username || $password === '') {
            return ['error' => true, 'message' => translate('mediaAppInvalidToken')];
        }

        $headers = [
            'Accept: application/json',
            'Authorization: MediaBrowser Client="' . APP_NAME . '", Device="' . APP_NAME . '", DeviceId="' . md5(APP_NAME) . '", Version="1.0"',
        ];
        $payload = json_encode([
            'Username' => $username,
            'Pw'       => $password,
            'Password' => $password,
        ]);
        $curl     = curl($url . '/Users/AuthenticateByName', $headers, 'POST', $payload);
        $response = is_array($curl['response']) ? $curl['response'] : json_decode($curl['response'], true);
        $apikey   = $response['AccessToken'] ?? '';

        if ($curl['code'] < 200 || $curl['code'] > 299 || !$apikey) {
            return ['error' => true, 'message' => translate('mediaAppInvalidToken')];
        }

        return ['error' => false, 'apikey' => $apikey];
    }

    public function embyJellyfinHeaders($apikey)
    {
        return [
            'Accept: application/json',
            'X-Emby-Token: ' . $apikey,
            'Authorization: MediaBrowser Token="' . $apikey . '", Client="' . APP_NAME . '", Device="' . APP_NAME . '", DeviceId="' . md5(APP_NAME) . '", Version="1.0"',
        ];
    }
}
