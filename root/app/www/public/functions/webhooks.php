<?php

/*
----------------------------------
------  Created: 091626   ------
------  Austin Best       ------
----------------------------------
*/

function webhookMergeRequest()
{
    global $webhookRawBody;

    $raw            = file_get_contents('php://input');
    $webhookRawBody = is_string($raw) ? $raw : '';
    $json           = json_decode($raw, true);
    if (is_array($json)) {
        $_POST = array_merge(is_array($_POST) ? $_POST : [], $json);
    }

    if (!empty($_POST['payload']) && is_string($_POST['payload'])) {
        $payload = json_decode($_POST['payload'], true);
        if (is_array($payload)) {
            unset($_POST['payload']);
            $_POST = array_merge($_POST, $payload);
        }
    }
    if (!empty($_POST['payload']) && is_array($_POST['payload'])) {
        $payload = $_POST['payload'];
        unset($_POST['payload']);
        $_POST = array_merge($_POST, $payload);
    }

    if (!empty($_POST['data']) && is_string($_POST['data'])) {
        $data = json_decode($_POST['data'], true);
        if (is_array($data)) {
            unset($_POST['data']);
            $_POST = array_merge($_POST, $data);
        }
    }
}

function webhookDecoded($value)
{
    if (is_array($value)) {
        return $value;
    }
    if (!is_string($value) || trim($value) == '') {
        return [];
    }

    $json = json_decode($value, true);

    return is_array($json) ? $json : [];
}

function webhookBody($post)
{
    $post = is_array($post) ? $post : [];
    foreach (['payload', 'data'] as $key) {
        $nested = webhookDecoded($post[$key] ?? null);
        if ($nested) {
            $post = array_merge($post, $nested);
        }
    }

    return $post;
}

function webhookPlexAccount($post)
{
    $post    = webhookBody($post);
    $account = $post['Account'] ?? ($post['account'] ?? []);
    if (is_string($account)) {
        $account = webhookDecoded($account);
    }

    return is_array($account) ? $account : [];
}

function webhookAccountName($post)
{
    $post    = webhookBody($post);
    $account = webhookPlexAccount($post);
    $name    = trim(strval($account['title'] ?? ($account['Title'] ?? '')));
    if ($name != '') {
        return $name;
    }

    $user = is_array($post['User'] ?? null) ? $post['User'] : [];
    $name = trim(strval($user['Name'] ?? ''));
    if ($name != '') {
        return $name;
    }

    return webhookFirstString($post, ['NotificationUsername', 'Username', 'UserName']);
}

function webhookContains($value, $needle)
{
    return is_string($value) && $value != '' && str_contains(strtolower($value), strtolower($needle));
}

function webhookNamesApp($slug, $agent, $post)
{
    $agent = strval($agent);
    $post  = is_array($post) ? $post : [];

    switch ($slug) {
        case 'jellyfin':
            if (webhookContains($agent, 'jellyfin')) {
                return true;
            }
            if (webhookContains($post['ClientName'] ?? '', 'jellyfin')) {
                return true;
            }
            if (webhookContains($post['Client'] ?? '', 'jellyfin')) {
                return true;
            }

            return !empty($post['NotificationType']);
        case 'emby':
            if (str_starts_with(strtolower($agent), 'emby server')) {
                return true;
            }
            $session = $post['Session'] ?? [];
            $client  = is_array($session) ? ($session['Client'] ?? '') : '';
            if (webhookContains($client, 'emby')) {
                return true;
            }

            return webhookContains($post['Title'] ?? '', 'emby');
        case 'plex':
            $player = $post['Player'] ?? [];
            $player = is_array($player) ? $player : [];
            if (webhookContains($player['product'] ?? '', 'plex') || webhookContains($player['title'] ?? '', 'plex')) {
                return true;
            }
            $account = $post['Account'] ?? [];
            $thumb   = is_array($account) ? ($account['thumb'] ?? '') : '';
            if (webhookContains($thumb, 'plex.tv')) {
                return true;
            }
            if (webhookContains($agent, 'plex')) {
                return true;
            }
            foreach (webhookGuidValues($post) as $guid) {
                $guid = strtolower($guid);
                if (str_contains_any($guid, ['plex://', 'com.plexapp'])) {
                    return true;
                }
            }

            return false;
    }

    return false;
}

function webhookGuidValues($post)
{
    $metadata = $post['Metadata'] ?? [];
    if (!is_array($metadata)) {
        return [];
    }

    $guids = [
        $metadata['guid'] ?? '',
        $metadata['grandparentGuid'] ?? '',
        $metadata['parentGuid'] ?? '',
    ];
    foreach ($metadata['Guid'] ?? [] as $guid) {
        if (is_array($guid)) {
            $guids[] = $guid['id'] ?? '';
        } else {
            $guids[] = $guid;
        }
    }

    $values = [];
    foreach ($guids as $guid) {
        $guid = strval($guid);
        if ($guid != '') {
            $values[] = $guid;
        }
    }

    return $values;
}

function webhookTruthy($value)
{
    return $value == 1 || $value == 'true';
}

function webhookUserSegment($value)
{
    $value = preg_replace('/[^A-Za-z0-9-]/', '', strval($value));

    return $value != '' ? $value : '-';
}

function webhookEventSegment($value)
{
    $value = preg_replace('/[^A-Za-z0-9.-]/', '', strval($value));

    return $value != '' ? $value : 'event';
}

function webhookParse($slug, $post)
{
    $post   = is_array($post) ? $post : [];
    $parsed = [
        'event'        => '',
        'action'       => '',
        'userId'       => '',
        'username'     => '',
        'serverId'     => '',
        'itemId'       => '',
        'itemType'     => '',
        'progress'     => 0,
        'runtime'      => 0,
        'progressOnly' => false,
    ];

    switch ($slug) {
        case 'plex':
            $post               = webhookBody($post);
            $account            = webhookPlexAccount($post);
            $server             = is_array($post['Server'] ?? null) ? $post['Server'] : [];
            $metadata           = is_array($post['Metadata'] ?? null) ? $post['Metadata'] : [];
            $parsed['event']    = strval($post['event'] ?? '');
            $parsed['userId']   = strval($account['id'] ?? '');
            $parsed['username'] = strval($account['title'] ?? '');
            $parsed['serverId'] = strval($server['uuid'] ?? '');
            $parsed['itemId']   = strval($metadata['ratingKey'] ?? '');
            $parsed['itemType'] = strtolower(strval($metadata['type'] ?? ''));
            $parsed['progress'] = mediaRuntimeSeconds($metadata['viewOffset'] ?? 0, 'ms');
            $parsed['runtime']  = mediaRuntimeSeconds($metadata['duration'] ?? 0, 'ms');
            $parsed['action']   = webhookPlexAction($parsed['event']);
            break;
        case 'emby':
            $user               = is_array($post['User'] ?? null) ? $post['User'] : [];
            $server             = is_array($post['Server'] ?? null) ? $post['Server'] : [];
            $item               = is_array($post['Item'] ?? null) ? $post['Item'] : [];
            $playback           = is_array($post['PlaybackInfo'] ?? null) ? $post['PlaybackInfo'] : [];
            $event              = strval($post['Event'] ?? '');
            $parsed['event']    = $event;
            $parsed['userId']   = strval($user['Id'] ?? '');
            $parsed['username'] = strval($user['Name'] ?? '');
            $parsed['serverId'] = strval($server['Id'] ?? '');
            $parsed['itemId']   = strval($item['Id'] ?? '');
            $parsed['itemType'] = strtolower(strval($item['Type'] ?? ''));
            $parsed['progress'] = mediaRuntimeSeconds($playback['PositionTicks'] ?? 0, 'ticks');
            $parsed['runtime']  = mediaRuntimeSeconds($item['RunTimeTicks'] ?? 0, 'ticks');
            if ($event == 'playback.stop' && webhookTruthy($playback['PlayedToCompletion'] ?? false)) {
                $event = 'playback.scrobble';
            }
            $parsed['action'] = webhookEmbyAction($event);
            break;
        case 'jellyfin':
            $event              = strval($post['NotificationType'] ?? '');
            $parsed['event']    = $event;
            $parsed['userId']   = strval($post['UserId'] ?? '');
            $parsed['username'] = webhookFirstString($post, ['NotificationUsername', 'Username', 'UserName']);
            $parsed['serverId'] = strval($post['ServerId'] ?? '');
            $parsed['itemId']   = strval($post['ItemId'] ?? '');
            $parsed['itemType'] = strtolower(strval($post['ItemType'] ?? ''));
            $parsed['progress'] = mediaRuntimeSeconds($post['PlaybackPositionTicks'] ?? 0, 'ticks');
            $parsed['runtime']  = mediaRuntimeSeconds($post['RunTimeTicks'] ?? 0, 'ticks');
            if ($event == 'PlaybackStop' && webhookTruthy($post['PlayedToCompletion'] ?? false)) {
                $event = 'PlaybackFinished';
            }
            $parsed['action'] = webhookJellyfinAction($event, $post);
            if ($parsed['event'] == 'PlaybackProgress' && $parsed['action'] == '') {
                $parsed['progressOnly'] = true;
            }
            break;
    }

    return $parsed;
}

function webhookFirstString($post, $keys)
{
    foreach ($keys as $key) {
        $value = trim(strval($post[$key] ?? ''));
        if ($value != '') {
            return $value;
        }
    }

    return '';
}

function webhookPlexAction($event)
{
    switch ($event) {
        case 'library.new':
            return 'new';
        case 'media.play':
        case 'media.resume':
            return 'play';
        case 'media.pause':
            return 'pause';
        case 'media.stop':
            return 'stop';
        case 'media.scrobble':
            return 'scrobble';
    }

    return '';
}

function webhookEmbyAction($event)
{
    switch ($event) {
        case 'library.new':
            return 'new';
        case 'playback.start':
        case 'playback.unpause':
            return 'play';
        case 'playback.pause':
            return 'pause';
        case 'playback.stop':
            return 'stop';
        case 'playback.scrobble':
            return 'scrobble';
    }

    return '';
}

function webhookJellyfinAction($event, $post)
{
    switch ($event) {
        case 'ItemAdded':
            return 'new';
        case 'PlaybackStart':
            return 'play';
        case 'PlaybackProgress':
            return webhookTruthy($post['IsPaused'] ?? false) ? 'pause' : '';
        case 'PlaybackStop':
            return 'stop';
        case 'PlaybackFinished':
            return 'scrobble';
    }

    return '';
}

function webhookLogPayload($post)
{
    global $webhookRawBody;

    if (is_array($post) && $post) {
        return $post;
    }

    $raw  = is_string($webhookRawBody ?? null) ? $webhookRawBody : '';
    $json = json_decode($raw, true);
    if (is_array($json)) {
        return $json;
    }
    if (trim($raw) != '') {
        return ['raw' => $raw];
    }

    return new stdClass();
}

function webhookWriteLog($slug, $userId, $event, $post, $code)
{
    if (!is_dir(WEBHOOK_LOGS_PATH)) {
        mkdir(WEBHOOK_LOGS_PATH, 0755, true);
    }
    if (!is_dir(WEBHOOK_LOGS_PATH)) {
        return '';
    }

    $slug  = in_array($slug, ['plex', 'emby', 'jellyfin'], true) ? $slug : '-';
    $event = trim(strval($event)) == '' ? '-' : webhookEventSegment($event);
    $code  = str_pad(strval(intval($code)), 3, '0', STR_PAD_LEFT);
    $stamp = str_replace('.', '', sprintf('%.6f', microtime(true)));
    $name  = $slug . '_' . webhookUserSegment($userId) . '_' . $event . '_' . $code . '_' . $stamp . '.log';
    $path  = WEBHOOK_LOGS_PATH . $name;
    $json  = json_encode(webhookLogPayload($post), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json == false) {
        $json = '{}';
    }
    file_put_contents($path, $json);

    return $name;
}

function webhookPlatformId($slug)
{
    switch ($slug) {
        case 'emby':
            return MediaPlatforms::EMBY;
        case 'jellyfin':
            return MediaPlatforms::JELLYFIN;
        default:
            return MediaPlatforms::PLEX;
    }
}

function webhookFindApp($slug, $serverId)
{
    global $database;

    $serverId = trim(strval($serverId));
    if ($serverId == '') {
        return [];
    }

    $platform = webhookPlatformId($slug);
    foreach ($database->getMediaApps() as $app) {
        if (intval($app['platform'] ?? 0) != $platform) {
            continue;
        }
        if (strcasecmp(trim(strval($app['server_id'] ?? '')), $serverId) == 0) {
            return $app;
        }
    }

    return [];
}

function webhookFindUser($mediaApp, $userId, $username)
{
    global $database;

    $users  = $database->getMediaAppUsers($mediaApp['id']);
    $userId = trim(strval($userId));
    if ($userId != '' && $userId != '-') {
        foreach ($users as $user) {
            if (strval($user['remote_id'] ?? '') == $userId) {
                return $user;
            }
        }
    }

    $username = strtolower(trim(strval($username)));
    if ($username != '') {
        foreach ($users as $user) {
            if (strtolower(trim(strval($user['username'] ?? ''))) == $username) {
                return $user;
            }
        }
    }

    return [];
}

function webhookWatchState($action, $progress, $runtime, $existing)
{
    $finished   = 0;
    $inprogress = intval($progress);
    if ($action == 'scrobble') {
        $finished   = watchFinishedSeconds(true, $runtime);
        $inprogress = 0;
    }

    return mergeWatchState($existing, [
        'started'    => 1,
        'inprogress' => $inprogress,
        'finished'   => $finished,
    ]);
}

function webhookNewItem($slug, $post)
{
    global $mediaApps;

    $post = is_array($post) ? $post : [];
    if ($slug == 'plex') {
        $post = webhookBody($post);
        $row  = is_array($post['Metadata'] ?? null) ? $post['Metadata'] : [];
        $type = strtolower(strval($row['type'] ?? ''));
        if ($type == 'episode') {
            $type = 'episode';
        } else if ($type == 'show') {
            $type = 'series';
        } else if ($type == 'movie') {
            $type = 'movie';
        } else {
            return ['type' => ''];
        }

        return [
            'type' => $type,
            'item' => $mediaApps->plexLibraryItem($row, $type == 'series' ? 2 : 0),
        ];
    }

    $row  = is_array($post['Item'] ?? null) ? $post['Item'] : $post;
    $type = strtolower(strval($row['Type'] ?? ($row['ItemType'] ?? ($post['ItemType'] ?? ''))));
    if ($type == 'episode') {
        $type = 'episode';
    } else if ($type == 'series') {
        $type = 'series';
    } else if ($type == 'movie') {
        $type = 'movie';
    } else {
        return ['type' => ''];
    }

    $path = $slug == 'jellyfin' ? $mediaApps->jellyfinItemPath($row) : $mediaApps->embyItemPath($row);
    if ($path == '') {
        $path = strval($row['Path'] ?? ($post['Path'] ?? ''));
    }

    return [
        'type' => $type,
        'item' => [
            'remote_id'        => strval($row['Id'] ?? ($row['ItemId'] ?? ($post['ItemId'] ?? ''))),
            'title'            => strval($row['Name'] ?? ($post['Name'] ?? '')),
            'year'             => intval($row['ProductionYear'] ?? ($row['Year'] ?? ($post['Year'] ?? 0))),
            'path'             => $path,
            'poster'           => $slug == 'jellyfin' ? $mediaApps->jellyfinPosterTag($row) : $mediaApps->embyPosterTag($row),
            'series_remote_id' => strval($row['SeriesId'] ?? ($post['SeriesId'] ?? '')),
            'series'           => strval($row['SeriesName'] ?? ($post['SeriesName'] ?? '')),
            'season'           => intval($row['ParentIndexNumber'] ?? ($row['SeasonNumber'] ?? ($post['SeasonNumber'] ?? 0))),
            'episode'          => intval($row['IndexNumber'] ?? ($row['EpisodeNumber'] ?? ($post['EpisodeNumber'] ?? 0))),
            'series_poster'    => strval($row['SeriesPrimaryImageTag'] ?? ''),
        ],
    ];
}

function webhookSavePayloadItem($app, $slug)
{
    global $cron;

    $built = webhookNewItem($slug, $_POST);
    if (($built['type'] ?? '') == '') {
        return false;
    }
    $result = $cron->saveWebhookLibraryItem($app, $built['type'], $built['item']);

    return $result != '';
}

function webhookApply($slug, $parsed)
{
    global $database, $cron;

    $label = ucfirst($slug);
    $app   = webhookFindApp($slug, $parsed['serverId'] ?? '');
    if (!$app) {
        return 'Unmatched media app.';
    }
    if (empty($app['webhooks'])) {
        return 'Webhooks are disabled for this media app.';
    }

    $action    = $parsed['action'] ?? '';
    $savedItem = webhookSavePayloadItem($app, $slug);
    if ($action == '' || $action == 'play') {
        if ($action == 'play' || !empty($parsed['progressOnly'])) {
            return $savedItem ? $label . ' payload processed.' : $label . ' item ignored until library sync.';
        }

        return 'Unhandled event.';
    }

    if ($action == 'new') {
        return $savedItem ? $label . ' payload processed.' : $label . ' new item ignored until library sync.';
    }

    $type = strtolower(strval($parsed['itemType'] ?? ''));
    if (!in_array($type, ['movie', 'episode', 'season'])) {
        return 'Unknown item type.';
    }
    if ($type == 'season') {
        return 'Season playback skipped.';
    }

    $user = webhookFindUser($app, $parsed['userId'] ?? '', $parsed['username'] ?? '');
    if (!$user) {
        return 'Unmatched user.';
    }

    $remoteId = trim(strval($parsed['itemId'] ?? ''));
    $platform = intval($app['platform']);
    if ($type == 'movie') {
        $item = $database->getMovieByRemoteId($platform, $remoteId);
        if (!$item) {
            return 'Unmatched item ignored until library sync.';
        }
        $existing = $database->getUserMovieLink($item['id'], $user['id'], $platform);
        $state    = webhookWatchState($action, $parsed['progress'] ?? 0, $parsed['runtime'] ?? 0, $existing);
        $saved    = $database->upsertUserMovieLink($item['id'], $user['id'], $platform, $state['started'], $state['inprogress'], $state['finished']);
    } else {
        $item = $database->getEpisodeByRemoteId($platform, $remoteId);
        if (!$item) {
            return 'Unmatched item ignored until library sync.';
        }
        $existing = $database->getUserEpisodeLink($item['id'], $user['id'], $platform);
        $state    = webhookWatchState($action, $parsed['progress'] ?? 0, $parsed['runtime'] ?? 0, $existing);
        $saved    = $database->upsertUserEpisodeLink($item['id'], $user['id'], $platform, $state['started'], $state['inprogress'], $state['finished']);
    }

    if (in_array($action, ['pause', 'stop', 'scrobble'], true)) {
        $queued = $cron->queueWebhookSync(intval($app['id']), intval($user['id']), $action, $item, $type, $state);
        if (!$queued && !empty($saved['changed'])) {
            webhookPushWatch($app, $user, $item, $type, $state);
        }
    }

    return $label . ' payload processed.';
}

function webhookPushWatch($sourceApp, $sourceUser, $item, $type, $state)
{
    global $database, $mediaApps, $cron;

    $pushed       = 0;
    $sourceUserId = intval($sourceUser['id'] ?? 0);
    $masterId     = $cron->watchMasterUserId($sourceUserId);
    $userIds      = [$masterId];
    foreach ($database->getMediaAppUserLinks($masterId) as $link) {
        $userIds[] = intval($link['linked_media_app_user_id'] ?? 0);
    }

    foreach (array_unique($userIds) as $userId) {
        if (!$userId || $userId == $sourceUserId) {
            continue;
        }
        $linkedUser = $database->getMediaAppUser($userId);
        if (!$linkedUser) {
            continue;
        }
        $linkedApp = $database->getMediaApp($linkedUser['media_app_id'] ?? 0);
        if (!$linkedApp || empty($linkedApp['active']) || intval($linkedApp['id']) == intval($sourceApp['id'])) {
            continue;
        }

        $platform = intval($linkedApp['platform']);
        $field    = $database->mediaLibraryRemoteField($platform);
        $remoteId = trim(strval($item[$field] ?? ''));
        if ($remoteId == '') {
            continue;
        }

        if ($type == 'movie') {
            $database->upsertUserMovieLink($item['id'], $userId, $platform, $state['started'], $state['inprogress'], $state['finished']);
        } else {
            $database->upsertUserEpisodeLink($item['id'], $userId, $platform, $state['started'], $state['inprogress'], $state['finished']);
        }
        $mediaApps->setWatchStatus($linkedApp, $linkedUser, $remoteId, $state['started'], $state['inprogress'], $state['finished']);
        $pushed++;
    }

    return $pushed;
}

function webhookPlexAccountIds($account)
{
    $ids = [];
    if (!is_array($account)) {
        return $ids;
    }

    $id = trim(strval($account['id'] ?? ''));
    if ($id != '' && $id != '0') {
        $ids[] = preg_replace('/[^A-Za-z0-9-]/', '', $id);
    }

    $thumb = strval($account['thumb'] ?? '');
    if (preg_match('#/users/([^/?]+)#', $thumb, $match)) {
        $uuid = preg_replace('/[^A-Za-z0-9-]/', '', $match[1]);
        if ($uuid != '') {
            $ids[] = $uuid;
        }
    }

    return $ids;
}

function webhookLogUsername($log, $users)
{
    $slug = strval($log['app'] ?? '');
    $id   = strval($log['user'] ?? '');
    $path = WEBHOOK_LOGS_PATH . strval($log['file'] ?? '');
    $json = [];
    if (is_file($path)) {
        $json = webhookDecoded(file_get_contents($path));
    }

    $body = webhookBody($json);
    $name = webhookAccountName($body != [] ? $body : $json);
    $ids  = $id != '' ? [$id] : [];
    if ($slug == 'plex' || $slug == '') {
        $ids = array_merge($ids, webhookPlexAccountIds(webhookPlexAccount($body)));
    }

    foreach ($ids as $remote) {
        if ($remote != '' && !empty($users[$slug][$remote])) {
            return $users[$slug][$remote];
        }
    }

    $named = strtolower($name);
    if ($named != '' && !empty($users[$slug]['name:' . $named])) {
        return $users[$slug]['name:' . $named];
    }

    return $name;
}

function webhookEventType($event)
{
    $event = strtolower(trim(strval($event)));
    switch ($event) {
        case 'pause':
        case 'media.pause':
        case 'playback.pause':
            return translate('webhookPause');
        case 'stop':
        case 'media.stop':
        case 'playback.stop':
        case 'playbackstop':
            return translate('webhookStop');
        case 'scrobble':
        case 'media.scrobble':
        case 'playback.scrobble':
            return translate('webhookFinish');
        case 'play':
        case 'media.play':
        case 'playback.start':
        case 'playbackstart':
            return translate('webhookPlay');
        case 'resume':
        case 'media.resume':
        case 'playback.unpause':
            return translate('webhookResume');
        case 'new':
        case 'library.new':
        case 'itemadded':
            return translate('webhookNew');
    }

    return '';
}

function webhookEventLabel($event)
{
    $key    = strtolower(trim(strval($event)));
    $labels = [
        'pause'                        => ['webhookMedia', 'webhookPause'],
        'stop'                         => ['webhookMedia', 'webhookStop'],
        'scrobble'                     => ['webhookMedia', 'webhookFinished'],
        'play'                         => ['webhookMedia', 'webhookPlay'],
        'resume'                       => ['webhookMedia', 'webhookResume'],
        'new'                          => ['webhookMedia', 'webhookNew'],
        'media.play'                   => ['webhookMedia', 'webhookPlay'],
        'media.pause'                  => ['webhookMedia', 'webhookPause'],
        'media.resume'                 => ['webhookMedia', 'webhookResume'],
        'media.stop'                   => ['webhookMedia', 'webhookStop'],
        'media.scrobble'               => ['webhookMedia', 'webhookFinished'],
        'media.rate'                   => ['webhookMedia', 'webhookRate'],
        'library.new'                  => ['library', 'webhookNew'],
        'library.deleted'              => ['library', 'webhookDeleted'],
        'library.on.deck'              => ['library', 'webhookOnDeck'],
        'admin.database.backup'        => ['webhookAdmin', 'webhookDatabaseBackup'],
        'admin.database.corrupted'     => ['webhookAdmin', 'webhookDatabaseCorrupted'],
        'device.new'                   => ['webhookDevice', 'webhookNew'],
        'playback.start'               => ['webhookPlayback', 'webhookStart'],
        'playback.pause'               => ['webhookPlayback', 'webhookPause'],
        'playback.unpause'             => ['webhookPlayback', 'webhookResume'],
        'playback.stop'                => ['webhookPlayback', 'webhookStop'],
        'playback.scrobble'            => ['webhookPlayback', 'webhookFinished'],
        'item.markplayed'              => ['webhookItem', 'webhookPlayed'],
        'item.markunplayed'            => ['webhookItem', 'webhookUnplayed'],
        'item.rate'                    => ['webhookItem', 'webhookFavorite'],
        'item.added'                   => ['webhookItem', 'webhookAdded'],
        'item.removed'                 => ['webhookItem', 'webhookDeleted'],
        'user.authenticated'           => ['webhookUser', 'webhookAuthenticated'],
        'system.webhooktest'           => ['webhookSystem', 'webhookTest'],
        'system.updateavailable'       => ['webhookSystem', 'webhookUpdateAvailable'],
        'system.serverrestartrequired' => ['webhookSystem', 'webhookRestartRequired'],
        'plugins.plugininstalled'      => ['webhookPlugin', 'webhookInstalled'],
        'plugins.pluginuninstalled'    => ['webhookPlugin', 'webhookUninstalled'],
        'plugins.pluginupdated'        => ['webhookPlugin', 'webhookUpdated'],
        'playbackstart'                => ['webhookPlayback', 'webhookStart'],
        'playbackstop'                 => ['webhookPlayback', 'webhookStop'],
        'playbackprogress'             => ['webhookPlayback', 'webhookProgress'],
        'itemadded'                    => ['webhookItem', 'webhookAdded'],
        'sessionstart'                 => ['webhookSession', 'webhookStart'],
        'pluginuninstalled'            => ['webhookPlugin', 'webhookUninstalled'],
        'plugininstalled'              => ['webhookPlugin', 'webhookInstalled'],
        'pluginupdated'                => ['webhookPlugin', 'webhookUpdated'],
        'plugininstalling'             => ['webhookPlugin', 'webhookInstalling'],
        'plugininstallationcancelled'  => ['webhookPlugin', 'webhookInstallationCancelled'],
        'plugininstallationfailed'     => ['webhookPlugin', 'webhookInstallationFailed'],
        'userpasswordchanged'          => ['webhookUser', 'webhookPasswordChanged'],
        'usercreated'                  => ['webhookUser', 'webhookCreated'],
        'userlockedout'                => ['webhookUser', 'webhookLockedOut'],
        'userdeleted'                  => ['webhookUser', 'webhookDeleted'],
        'subtitledownloadfailure'      => ['webhookSubtitle', 'webhookDownloadFailure'],
        'authenticationfailure'        => ['webhookAuthentication', 'webhookFailed'],
        'authenticationsuccess'        => ['webhookAuthentication', 'webhookSuccess'],
        'pendingrestart'               => ['webhookSystem', 'webhookRestartRequired'],
    ];
    if (!empty($labels[$key])) {
        return translate($labels[$key][0]) . ': ' . translate($labels[$key][1]);
    }

    return webhookEventFallback($key);
}

function webhookEventFallback($event)
{
    $event = strtolower(trim(strval($event)));
    if ($event == '' || $event == '-') {
        return '';
    }

    $actions    = [
        'markunplayed'          => 'webhookUnplayed',
        'markplayed'            => 'webhookPlayed',
        'unpause'               => 'webhookResume',
        'scrobble'              => 'webhookFinished',
        'ondeck'                => 'webhookOnDeck',
        'authenticationfailed'  => 'webhookFailed',
        'authenticated'         => 'webhookAuthenticated',
        'serverrestartrequired' => 'webhookRestartRequired',
        'updateavailable'       => 'webhookUpdateAvailable',
        'webhooktest'           => 'webhookTest',
        'plugininstalled'       => 'webhookInstalled',
        'pluginuninstalled'     => 'webhookUninstalled',
        'pluginupdated'         => 'webhookUpdated',
        'databasebackup'        => 'webhookDatabaseBackup',
        'databasecorrupted'     => 'webhookDatabaseCorrupted',
    ];
    $categories = [
        'item'           => 'webhookItem',
        'playback'       => 'webhookPlayback',
        'media'          => 'webhookMedia',
        'library'        => 'library',
        'user'           => 'webhookUser',
        'system'         => 'webhookSystem',
        'plugins'        => 'webhookPlugin',
        'plugin'         => 'webhookPlugin',
        'session'        => 'webhookSession',
        'device'         => 'webhookDevice',
        'admin'          => 'webhookAdmin',
        'subtitle'       => 'webhookSubtitle',
        'authentication' => 'webhookAuthentication',
    ];

    if (str_contains($event, '.')) {
        $parts    = explode('.', $event);
        $category = array_shift($parts);
        $token    = str_replace('.', '', implode('', $parts));
        if (!empty($actions[$token])) {
            $action = translate($actions[$token]);
        } else {
            $action = ucwords(implode(' ', array_filter($parts)));
        }
        $category = !empty($categories[$category]) ? translate($categories[$category]) : ucfirst($category);

        return $category . ': ' . $action;
    }

    $words = webhookEventWords($event);
    if (count($words) < 2) {
        return ucwords($event);
    }

    $category = strtolower($words[0]);
    $category = !empty($categories[$category]) ? translate($categories[$category]) : ucfirst($words[0]);
    $action   = implode('', array_map('strtolower', array_slice($words, 1)));
    $action   = !empty($actions[$action]) ? translate($actions[$action]) : ucwords(implode(' ', array_slice($words, 1)));

    return $category . ': ' . $action;
}

function webhookEventWords($value)
{
    $value = trim(strval($value));
    if ($value == '') {
        return [];
    }
    $value = preg_replace('/([a-z])([A-Z])/', '$1 $2', $value);
    $value = str_replace(['.', '-', '_'], ' ', $value);
    $words = preg_split('/\s+/', trim($value));

    return $words ?: [];
}

function webhookLogRows()
{
    $rows = [];
    if (!is_dir(WEBHOOK_LOGS_PATH)) {
        return $rows;
    }

    foreach (glob(WEBHOOK_LOGS_PATH . '*.log') ?: [] as $path) {
        $name = basename($path);
        $code = '';
        if (preg_match('/^(plex|emby|jellyfin|-)_([A-Za-z0-9-]*)_([^_]+)_(\d{3})_(\d+)\.log$/', $name, $match)) {
            $code   = $match[4];
            $digits = $match[5];
        } else if (preg_match('/^(plex|emby|jellyfin)_([A-Za-z0-9-]*)_([^_]+)_(\d+)\.log$/', $name, $match)) {
            $digits = $match[4];
        } else {
            continue;
        }

        $seconds = strlen($digits) > 6 ? intval(substr($digits, 0, -6)) : 0;
        $rows[]  = [
            'file'  => $name,
            'app'   => $match[1] == '-' ? '' : $match[1],
            'user'  => $match[2] == '-' ? '' : $match[2],
            'event' => $match[3] == '-' ? '' : $match[3],
            'code'  => $code,
            'time'  => $seconds,
        ];
    }

    usort($rows, function ($a, $b) {
        if ($a['time'] == $b['time']) {
            return strnatcasecmp($b['file'], $a['file']);
        }

        return $b['time'] <=> $a['time'];
    });

    return $rows;
}
