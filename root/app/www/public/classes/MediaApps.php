<?php

/*
----------------------------------
------  Created: 091026   ------
------  Austin Best       ------
----------------------------------
*/

loadClassTraits(RELATIVE_PATH . 'classes/traits/MediaApps/');

class MediaApps
{
    use Plex;
    use Emby;
    use Jellyfin;
    use Parity;

    protected $database;
    protected $librariesCache   = [];
    protected $parityStateCache = [];
    protected $onlineCache      = [];

    public function __construct()
    {
        global $database;

        $this->database = $database;
    }

    public function getPlatformName($platform)
    {
        switch (intval($platform)) {
            case MediaPlatforms::PLEX:
                return translate('plex');
            case MediaPlatforms::EMBY:
                return translate('emby');
            case MediaPlatforms::JELLYFIN:
                return translate('jellyfin');
            default:
                return '';
        }
    }

    public function getPlatformLogo($platform)
    {
        switch (intval($platform)) {
            case MediaPlatforms::PLEX:
                return 'images/logo/plex.png';
            case MediaPlatforms::EMBY:
                return 'images/logo/emby.png';
            case MediaPlatforms::JELLYFIN:
                return 'images/logo/jellyfin.png';
            default:
                return '';
        }
    }

    public function getItemWebUrl($mediaApp, $remoteId)
    {
        $remoteId = trim(strval($remoteId ?? ''));
        $url      = rtrim(trim($mediaApp['url'] ?? ''), '/');
        $serverId = trim(strval($mediaApp['server_id'] ?? ''));
        if ($remoteId == '' || $url == '') {
            return '';
        }

        switch (intval($mediaApp['platform'] ?? 0)) {
            case MediaPlatforms::PLEX:
                if ($serverId == '') {
                    return '';
                }

                return $url . '/web/index.html#!/server/' . rawurlencode($serverId) . '/details?key=' . rawurlencode('/library/metadata/' . $remoteId);
            case MediaPlatforms::EMBY:
                $link = $url . '/web/index.html#!/item?id=' . rawurlencode($remoteId);
                if ($serverId != '') {
                    $link .= '&serverId=' . rawurlencode($serverId);
                }

                return $link;
            case MediaPlatforms::JELLYFIN:
                return $url . '/web/index.html#!/details?id=' . rawurlencode($remoteId);
            default:
                return '';
        }
    }

    public function getRoleName($role)
    {
        if (intval($role) == MediaAppRoles::MASTER) {
            return translate('mainSource');
        }

        return translate('listener');
    }

    public function getSyncModeName($syncMode)
    {
        switch (intval($syncMode)) {
            case MediaSyncModes::PUSH:
                return translate('pushOnly');
            case MediaSyncModes::PULL:
                return translate('pullOnly');
            default:
                return translate('pushAndPull');
        }
    }

    public function getJobModeName($job)
    {
        return $this->getSyncModeName($job['sync_mode'] ?? MediaSyncModes::PULL);
    }

    public function getLibraryScanName($scan)
    {
        if (intval($scan) == MediaLibraryScans::FULL) {
            return translate('fullLibraryScan');
        }

        return translate('sinceLastScan');
    }

    public function getSyncTypeName($syncType, $syncAccounts = 0)
    {
        if (intval($syncType) == MediaSyncTypes::LIBRARY) {
            return translate('library');
        }
        if (intval($syncType) == MediaSyncTypes::HISTORY) {
            return translate('history');
        }
        if (intval($syncType) == MediaSyncTypes::LIBRARIES) {
            return translate('parityLibraries');
        }
        if (!empty($syncAccounts)) {
            return translate('parityUsers');
        }

        return translate('users');
    }

    public function getTriggerName($trigger)
    {
        if (intval($trigger) == MediaSyncTriggers::AUTOMATIC) {
            return translate('automatic');
        }

        return translate('manual');
    }

    public function isOnline($mediaApp)
    {
        $id  = intval($mediaApp['id'] ?? 0);
        $url = rtrim(trim($mediaApp['url'] ?? ''), '/');
        $key = $id ? ('id:' . $id) : ('url:' . intval($mediaApp['platform'] ?? 0) . ':' . $url);
        if (array_key_exists($key, $this->onlineCache)) {
            return $this->onlineCache[$key];
        }

        if (!$url) {
            return $this->onlineCache[$key] = false;
        }

        switch (intval($mediaApp['platform'])) {
            case MediaPlatforms::PLEX:
                $online = $this->plexIsOnline($url);
                break;
            case MediaPlatforms::EMBY:
                $online = $this->embyIsOnline($url);
                break;
            case MediaPlatforms::JELLYFIN:
                $online = $this->jellyfinIsOnline($url);
                break;
            default:
                $online = false;
                break;
        }

        return $this->onlineCache[$key] = $online;
    }

    public function activeOnlineCount()
    {
        $count = 0;
        foreach ($this->database->getMediaApps() as $mediaApp) {
            if (!$mediaApp['active']) {
                continue;
            }
            if ($this->isOnline($mediaApp)) {
                $count++;
            }
        }

        return $count;
    }

    public function canSyncApps($minimum = 2)
    {
        return $this->activeOnlineCount() >= intval($minimum);
    }

    public function validate($platform, $url, $token, $apikey)
    {
        $url = rtrim(trim($url), '/');
        if (!$this->isOnline(['platform' => intval($platform), 'url' => $url])) {
            return ['error' => true, 'message' => translate('offline')];
        }

        switch (intval($platform)) {
            case MediaPlatforms::PLEX:
                return $this->plexTestConnection($url, $token);
            case MediaPlatforms::EMBY:
                return $this->embyTestConnection($url, $apikey);
            case MediaPlatforms::JELLYFIN:
                return $this->jellyfinTestConnection($url, $apikey);
            default:
                return ['error' => true, 'message' => translate('missingRequiredField', [translate('platform')])];
        }
    }

    public function save($id, $name, $platform, $url, $token, $apikey, $isMaster, $syncMode, $active)
    {
        $name     = trim($name);
        $url      = rtrim(trim($url), '/');
        $platform = intval($platform);
        $syncMode = intval($syncMode);
        $active   = $active ? 1 : 0;
        $id       = intval($id);

        if (!$name) {
            return ['error' => true, 'message' => translate('missingRequiredField', [translate('name')])];
        }
        if (!$platform) {
            return ['error' => true, 'message' => translate('missingRequiredField', [translate('platform')])];
        }
        if (!$url) {
            return ['error' => true, 'message' => translate('missingRequiredField', [translate('url')])];
        }
        if ($platform == MediaPlatforms::PLEX && !$token) {
            return ['error' => true, 'message' => translate('missingRequiredField', [translate('plexToken')])];
        }
        if ($platform != MediaPlatforms::PLEX && !$apikey) {
            return ['error' => true, 'message' => translate('missingRequiredField', [translate('apiKey')])];
        }
        if (!in_array($syncMode, [MediaSyncModes::BOTH, MediaSyncModes::PUSH, MediaSyncModes::PULL])) {
            $syncMode = MediaSyncModes::BOTH;
        }

        $existing  = $id ? $this->database->getMediaApp($id) : [];
        $mediaApps = $this->database->getMediaApps();
        $hasMaster = false;
        foreach ($mediaApps as $mediaApp) {
            if ($mediaApp['role'] == MediaAppRoles::MASTER && (!$id || $mediaApp['id'] != $id)) {
                $hasMaster = true;
                break;
            }
        }

        if (!$mediaApps) {
            $isMaster = true;
        }

        if (!$isMaster && !$hasMaster) {
            if ($existing && $existing['role'] == MediaAppRoles::MASTER) {
                return ['error' => true, 'message' => translate('mustHaveMainSource')];
            }
            $isMaster = true;
        }

        $validated = $this->validate($platform, $url, $token, $apikey);
        if ($validated['error']) {
            return $validated;
        }

        if (!empty($validated['apikey'])) {
            $apikey = $validated['apikey'];
        }

        $role = $isMaster ? MediaAppRoles::MASTER : MediaAppRoles::LISTENER;

        if ($id) {
            $this->database->updateMediaApp($id, $name, $platform, $url, $token, $apikey, $role, $syncMode, $active, $validated['server_id'], $validated['server_name']);
        } else {
            $id = $this->database->addMediaApp($name, $platform, $url, $token, $apikey, $role, $syncMode, $active, $validated['server_id'], $validated['server_name']);
            if (!$id) {
                return ['error' => true, 'message' => translate('couldNotSaveSettings')];
            }
        }

        if ($isMaster) {
            $this->database->setAllMediaAppsListener();
            $this->database->setMediaAppRole($id, MediaAppRoles::MASTER);
        }

        $this->database->replaceMediaAppUsers($id, $validated['users']);
        $this->refreshStoredLibraries($this->database->getMediaApp($id));

        return ['error' => false, 'message' => translate('mediaAppSaved'), 'id' => $id];
    }

    public function getAppLibraries()
    {
        $this->applyNewScanLibraries();
        $master = [];
        foreach ($this->database->getMediaApps() as $mediaApp) {
            if ($mediaApp['active'] && intval($mediaApp['role']) == MediaAppRoles::MASTER) {
                $master = $mediaApp;
                break;
            }
        }
        $masterLibraries = $master ? $this->getLibraries($master) : [];
        $apps            = [];
        foreach ($this->database->getMediaApps() as $mediaApp) {
            if (!$mediaApp['active']) {
                continue;
            }
            $isMaster  = intval($mediaApp['role']) == MediaAppRoles::MASTER;
            $libraries = $isMaster || !$masterLibraries ? $this->getLibraries($mediaApp) : $this->getLibraries($mediaApp, $masterLibraries);
            foreach ($libraries as &$library) {
                $library['sync'] = $this->scanLibrarySelected(intval($mediaApp['id']) . ':' . ($library['key'] ?? ''));
            }
            unset($library);
            $apps[] = [
                'id'        => $mediaApp['id'],
                'name'      => $mediaApp['name'],
                'platform'  => $mediaApp['platform'],
                'role'      => $mediaApp['role'],
                'online'    => $this->isOnline($mediaApp),
                'libraries' => $libraries,
            ];
        }

        return $apps;
    }

    public function scanLibraryState()
    {
        $state = json_decode($this->database->getSetting('libraryScanSync'), true);

        return is_array($state) ? $state : [];
    }

    public function scanLibrarySelected($id)
    {
        $state = $this->scanLibraryState();

        return !empty($state[$id]);
    }

    public function setScanLibraries($items)
    {
        $state = [];
        foreach ($items as $id => $enabled) {
            $key = trim($id);
            if ($key == '' || empty($enabled)) {
                continue;
            }
            $state[$key] = 1;
        }
        $this->database->setSetting('libraryScanSync', json_encode($state));
    }

    public function selectedScanLibraries()
    {
        $libraries = [];
        foreach ($this->scanLibraryState() as $id => $enabled) {
            if (empty($enabled)) {
                continue;
            }
            $parts = explode(':', $id, 2);
            if (count($parts) != 2 || !intval($parts[0]) || $parts[1] == '') {
                continue;
            }
            $libraries[] = [
                'media_app_id' => intval($parts[0]),
                'key'          => $parts[1],
            ];
        }

        return $this->labelAppLibraries($libraries);
    }

    public function fetchLiveLibraries($mediaApp, $full = true)
    {
        if (!$this->isOnline($mediaApp)) {
            return [];
        }

        switch (intval($mediaApp['platform'] ?? 0)) {
            case MediaPlatforms::PLEX:
                return $this->plexGetLibraries($mediaApp['url'], $mediaApp['token']);
            case MediaPlatforms::EMBY:
                return $this->embyGetLibraries($mediaApp['url'], $mediaApp['apikey'], $full);
            case MediaPlatforms::JELLYFIN:
                return $this->jellyfinGetLibraries($mediaApp['url'], $mediaApp['apikey'], $full);
            default:
                return [];
        }
    }

    public function refreshStoredLibraries($mediaApp, $full = true)
    {
        $id = intval($mediaApp['id'] ?? 0);
        unset($this->librariesCache[$id]);

        return $this->getLibraries($mediaApp, [], true, $full);
    }

    public function getLibraries($mediaApp, $alignLibraries = [], $refresh = true, $full = true)
    {
        $id = intval($mediaApp['id'] ?? 0);
        if ($id && isset($this->librariesCache[$id])) {
            $libraries = $this->librariesCache[$id];
        } else {
            $libraries = [];
            if ($id && !$refresh) {
                $libraries = $this->database->getMediaAppLibraries($id);
            }
            if (!$libraries) {
                $live = $this->fetchLiveLibraries($mediaApp, $full);
                if ($live) {
                    $libraries = $live;
                    if ($id) {
                        $this->database->replaceMediaAppLibraries($id, $libraries);
                    }
                } else if ($id) {
                    $libraries = $this->database->getMediaAppLibraries($id);
                }
            }

            foreach ($libraries as &$library) {
                $library['media_app_id'] = $id;
            }
            unset($library);

            $libraries = $this->sortLibraries($libraries);
            if ($id) {
                $this->librariesCache[$id] = $libraries;
            }
        }

        if (!$alignLibraries) {
            return $libraries;
        }

        $byKey   = [];
        $byTitle = [];
        foreach ($libraries as $library) {
            $key = strval($library['key'] ?? '');
            if ($key != '') {
                $byKey[$key] = $library;
            }
            $title = strtolower(trim($library['title'] ?? ''));
            if ($title != '') {
                $byTitle[$title] = $library;
            }
        }

        $aligned = [];
        $seen    = [];
        $appId   = $id;
        $linkMap = [];
        foreach ($this->database->getMediaAppLibraryLinks() as $link) {
            if (intval($link['linked_media_app_id']) != $appId) {
                continue;
            }
            $linkMap[intval($link['media_app_id']) . ':' . $link['library_key']] = $link['linked_library_key'];
        }
        foreach ($alignLibraries as $match) {
            $picked      = [];
            $masterAppId = intval($match['media_app_id'] ?? 0);
            $masterKey   = strval($match['key'] ?? '');
            if ($masterAppId && $masterKey != '') {
                $linkedKey = strval($linkMap[$masterAppId . ':' . $masterKey] ?? '');
                if ($linkedKey != '' && !empty($byKey[$linkedKey])) {
                    $picked = $byKey[$linkedKey];
                }
            }
            if (!$picked) {
                $title = strtolower(trim($match['title'] ?? ''));
                if ($title != '' && !empty($byTitle[$title])) {
                    $picked = $byTitle[$title];
                }
            }
            if (!$picked) {
                $aligned[] = [];
                continue;
            }
            $key = strval($picked['key'] ?? '');
            if ($key == '' || !empty($seen[$key])) {
                $aligned[] = [];
                continue;
            }
            $seen[$key] = true;
            $aligned[]  = $picked;
        }

        return $aligned;
    }

    public function sortLibraries($libraries)
    {
        usort($libraries, function ($a, $b) {
            return strcasecmp($a['title'] ?? '', $b['title'] ?? '');
        });

        return $libraries;
    }

    public function libraryPathKey($path)
    {
        $path = trim(str_replace('\\', '/', $path));
        $path = rtrim($path, '/');
        if ($path == '' || $path == '/' || preg_match('/^[a-zA-Z]:$/', $path)) {
            return '';
        }

        return strtolower($path);
    }

    public function getRootFolders($mediaApp)
    {
        $roots = [];
        foreach ($this->getLibraries($mediaApp) as $library) {
            foreach ($library['paths'] ?? [] as $path) {
                $key = $this->libraryPathKey($path);
                if ($key != '' && !isset($roots[$key])) {
                    $roots[$key] = rtrim(str_replace('\\', '/', trim($path)), '/');
                }
            }
        }

        $folders = array_values($roots);
        natcasesort($folders);

        return array_values($folders);
    }

    public function getItems($mediaApp, $libraryKeys = [], $seriesRemoteId = '', $since = 0)
    {
        if (!$this->isOnline($mediaApp)) {
            return ['movies' => [], 'series' => [], 'episodes' => []];
        }

        $seriesTitle = '';
        if (is_array($seriesRemoteId)) {
            $seriesTitle    = $seriesRemoteId['title'] ?? '';
            $seriesRemoteId = $seriesRemoteId['remote_id'] ?? '';
        }

        switch (intval($mediaApp['platform'])) {
            case MediaPlatforms::PLEX:
                return $this->plexGetItems($mediaApp['url'], $mediaApp['token'], $libraryKeys, $seriesRemoteId, $seriesTitle, $since);
            case MediaPlatforms::EMBY:
                return $this->embyGetItems($mediaApp['url'], $mediaApp['apikey'], $libraryKeys, $seriesRemoteId, $seriesTitle, $since);
            case MediaPlatforms::JELLYFIN:
                return $this->jellyfinGetItems($mediaApp['url'], $mediaApp['apikey'], $libraryKeys, $seriesRemoteId, $seriesTitle, $since);
            default:
                return ['movies' => [], 'series' => [], 'episodes' => []];
        }
    }

    public function getWatchStatus($mediaApp, $remoteId, $username = '', $userId = 0)
    {
        if (!$this->isOnline($mediaApp)) {
            return ['movies' => [], 'episodes' => []];
        }

        switch (intval($mediaApp['platform'])) {
            case MediaPlatforms::PLEX:
                return $this->plexGetWatchStatus($mediaApp['url'], $mediaApp['token'], $remoteId, $username, $userId);
            case MediaPlatforms::EMBY:
                return $this->embyGetWatchStatus($mediaApp['url'], $mediaApp['apikey'], $remoteId);
            case MediaPlatforms::JELLYFIN:
                return $this->jellyfinGetWatchStatus($mediaApp['url'], $mediaApp['apikey'], $remoteId);
            default:
                return ['movies' => [], 'episodes' => []];
        }
    }

    public function setWatchStatus($mediaApp, $user, $remoteId, $started, $inprogress, $finished)
    {
        if (!$this->isOnline($mediaApp)) {
            return;
        }

        switch (intval($mediaApp['platform'])) {
            case MediaPlatforms::PLEX:
                $this->plexSetWatchStatus($mediaApp['url'], $mediaApp['token'], $remoteId, $started, $inprogress, $finished);
                return;
            case MediaPlatforms::EMBY:
                $this->embySetWatchStatus($mediaApp['url'], $mediaApp['apikey'], $user['remote_id'], $remoteId, $started, $inprogress, $finished);
                return;
            case MediaPlatforms::JELLYFIN:
                $this->jellyfinSetWatchStatus($mediaApp['url'], $mediaApp['apikey'], $user['remote_id'], $remoteId, $started, $inprogress, $finished);
                return;
        }
    }

    public function libraryTypeForPlatform($type, $platform)
    {
        $type  = strtolower(trim($type));
        $movie = in_array($type, ['movie', 'movies']);
        $show  = in_array($type, ['show', 'shows', 'tvshows', 'series', 'tv']);
        if (intval($platform) == MediaPlatforms::PLEX) {
            if ($movie) {
                return 'movie';
            }
            if ($show) {
                return 'show';
            }

            return '';
        }
        if ($movie) {
            return 'movies';
        }
        if ($show) {
            return 'tvshows';
        }

        return $type;
    }

    public function deleteLibrary($mediaApp, $libraryKey)
    {
        $library = [];
        foreach ($this->getLibraries($mediaApp) as $item) {
            if (($item['key'] ?? '') == $libraryKey || ($item['guid'] ?? '') == $libraryKey) {
                $library = $item;
                break;
            }
        }
        if (!$library) {
            return ['error' => false, 'missing' => true];
        }
        if (!$this->isOnline($mediaApp)) {
            return ['error' => true, 'message' => translate('offline')];
        }

        switch (intval($mediaApp['platform'])) {
            case MediaPlatforms::PLEX:
                $result = $this->plexDeleteLibrary($mediaApp['url'], $mediaApp['token'], $library['key']);
                break;
            case MediaPlatforms::EMBY:
                $result = $this->embyDeleteLibrary($mediaApp['url'], $mediaApp['apikey'], $library);
                break;
            case MediaPlatforms::JELLYFIN:
                $result = $this->jellyfinDeleteLibrary($mediaApp['url'], $mediaApp['apikey'], $library);
                break;
            default:
                return ['error' => true, 'message' => translate('mediaAppNotFound')];
        }
        if (empty($result['error'])) {
            $this->refreshStoredLibraries($mediaApp);
        }

        return $result;
    }

    public function createLibrary($mediaApp, $sourceLibrary)
    {
        if (!$this->isOnline($mediaApp)) {
            return ['error' => true, 'message' => translate('offline')];
        }

        $title = trim($sourceLibrary['title'] ?? '');
        $paths = [];
        foreach ($sourceLibrary['paths'] ?? [] as $path) {
            if (trim($path) != '') {
                $paths[] = $path;
            }
        }
        $type = $this->libraryTypeForPlatform($sourceLibrary['type'] ?? '', $mediaApp['platform']);
        if ($title == '' || !$paths || $type == '') {
            return ['error' => true, 'message' => translate('couldNotSaveSettings')];
        }

        switch (intval($mediaApp['platform'])) {
            case MediaPlatforms::PLEX:
                $result = $this->plexCreateLibrary($mediaApp['url'], $mediaApp['token'], $title, $type, $paths);
                break;
            case MediaPlatforms::EMBY:
                $result = $this->embyCreateLibrary($mediaApp['url'], $mediaApp['apikey'], $title, $type, $paths);
                break;
            case MediaPlatforms::JELLYFIN:
                $result = $this->jellyfinCreateLibrary($mediaApp['url'], $mediaApp['apikey'], $title, $type, $paths);
                break;
            default:
                return ['error' => true, 'message' => translate('mediaAppNotFound')];
        }
        if (empty($result['error'])) {
            $this->refreshStoredLibraries($mediaApp);
        }

        return $result;
    }

    public function createUser($mediaApp, $username)
    {
        if (!$this->isOnline($mediaApp)) {
            return ['error' => true, 'message' => translate('offline')];
        }

        switch (intval($mediaApp['platform'])) {
            case MediaPlatforms::PLEX:
                return $this->plexCreateUser($mediaApp['token'], $mediaApp['server_id'] ?? '', $username);
            case MediaPlatforms::EMBY:
                return $this->embyCreateUser($mediaApp['url'], $mediaApp['apikey'], $username);
            case MediaPlatforms::JELLYFIN:
                return $this->jellyfinCreateUser($mediaApp['url'], $mediaApp['apikey'], $username);
            default:
                return ['error' => true, 'message' => translate('mediaAppNotFound')];
        }
    }

    public function getUserLibraryKeys($mediaApp, $user)
    {
        switch (intval($mediaApp['platform'])) {
            case MediaPlatforms::PLEX:
                return $this->plexGetUserLibraryKeys($mediaApp['token'], $mediaApp['server_id'] ?? '', $user);
            case MediaPlatforms::EMBY:
                return $this->embyGetUserLibraryKeys($mediaApp['url'], $mediaApp['apikey'], $user);
            case MediaPlatforms::JELLYFIN:
                return $this->jellyfinGetUserLibraryKeys($mediaApp['url'], $mediaApp['apikey'], $user);
            default:
                return ['all' => false, 'keys' => []];
        }
    }

    public function userLibraryAccessLabel($mediaApp, $user, $libraries = [])
    {
        if (!$libraries) {
            $libraries = $this->getLibraries($mediaApp);
        }

        $idsByLibrary = [];
        foreach ($libraries as $index => $library) {
            foreach ([$library['key'] ?? '', $library['guid'] ?? ''] as $id) {
                if ($id !== '') {
                    $idsByLibrary[$id] = $index;
                }
            }
        }

        $access = $this->getUserLibraryKeys($mediaApp, $user);
        if (!empty($access['all'])) {
            return 'all';
        }

        $matched = [];
        foreach ($access['keys'] ?? [] as $key) {
            if ($key !== '' && isset($idsByLibrary[$key])) {
                $matched[$idsByLibrary[$key]] = true;
            }
        }
        $matched = count($matched);

        if ($libraries && $matched >= count($libraries)) {
            return 'all';
        }

        return $matched;
    }

    public function libraryAccessMatches($access, $library)
    {
        if (!empty($access['all'])) {
            return true;
        }

        $ids = [];
        foreach ($access['keys'] ?? [] as $key) {
            if ($key != '') {
                $ids[$key] = true;
            }
        }
        foreach ([$library['key'] ?? '', $library['guid'] ?? ''] as $id) {
            if ($id != '' && !empty($ids[$id])) {
                return true;
            }
        }

        return false;
    }

    public function setUserLibraryAccess($mediaApp, $user, $keys, $all)
    {
        if (!$this->isOnline($mediaApp)) {
            return ['error' => true, 'message' => translate('offline')];
        }

        switch (intval($mediaApp['platform'])) {
            case MediaPlatforms::PLEX:
                return $this->plexSetUserLibraryAccess($mediaApp['token'], $mediaApp['server_id'] ?? '', $user, $keys, $all);
            case MediaPlatforms::EMBY:
                return $this->embySetUserLibraryAccess($mediaApp['url'], $mediaApp['apikey'], $user['remote_id'] ?? '', $keys, $all);
            case MediaPlatforms::JELLYFIN:
                return $this->jellyfinSetUserLibraryAccess($mediaApp['url'], $mediaApp['apikey'], $user['remote_id'] ?? '', $keys, $all);
            default:
                return ['error' => true, 'message' => translate('mediaAppNotFound')];
        }
    }

    public function syncUserLibraryAccess($master, $masterUser, $listener, $listenerUser, $selected = [])
    {
        if (!empty($masterUser['is_admin'])) {
            return $this->setUserLibraryAccess($listener, $listenerUser, [], true);
        }

        $source = $this->getUserLibraryKeys($master, $masterUser);
        if (!empty($source['all']) && !$selected) {
            return $this->setUserLibraryAccess($listener, $listenerUser, [], true);
        }

        $selectedIds = [];
        foreach ($selected as $library) {
            foreach ([$library['key'] ?? '', $library['guid'] ?? ''] as $id) {
                if ($id != '') {
                    $selectedIds[$id] = true;
                }
            }
        }

        $masterLibraries   = $this->getLibraries($master);
        $listenerLibraries = $this->getLibraries($listener);
        $wanted            = [];
        foreach ($masterLibraries as $masterLibrary) {
            $masterKey  = strval($masterLibrary['key'] ?? '');
            $masterGuid = strval($masterLibrary['guid'] ?? '');
            if ($selectedIds && empty($selectedIds[$masterKey]) && empty($selectedIds[$masterGuid])) {
                continue;
            }
            if (!$this->libraryAccessMatches($source, $masterLibrary)) {
                continue;
            }

            $found = $this->findListenerLibrary($master, $masterLibrary, $listener, $listenerLibraries);
            foreach ([$found['key'] ?? '', $found['guid'] ?? ''] as $id) {
                if ($id != '') {
                    $wanted[$id] = true;
                }
            }
        }

        return $this->setUserLibraryAccess($listener, $listenerUser, array_keys($wanted), false);
    }

    public function findListenerLibrary($master, $masterLibrary, $listener, $listenerLibraries = [])
    {
        if (!$listenerLibraries) {
            $listenerLibraries = $this->getLibraries($listener);
        }

        $masterKey = strval($masterLibrary['key'] ?? '');
        if ($masterKey != '') {
            $link      = $this->database->getMediaAppLibraryLinkForApp($master['id'], $masterKey, $listener['id']);
            $linkedKey = strval($link['linked_library_key'] ?? '');
            if ($linkedKey != '') {
                foreach ($listenerLibraries as $library) {
                    if (strval($library['key'] ?? '') == $linkedKey || strval($library['guid'] ?? '') == $linkedKey) {
                        return $library;
                    }
                }
            }
        }

        $title = strtolower(trim($masterLibrary['title'] ?? ''));
        if ($title != '') {
            foreach ($listenerLibraries as $library) {
                if (strtolower(trim($library['title'] ?? '')) == $title) {
                    return $library;
                }
            }
        }

        $masterPaths = $this->libraryPathSet($masterLibrary);
        if ($masterPaths) {
            foreach ($listenerLibraries as $library) {
                foreach (array_keys($this->libraryPathSet($library)) as $pathKey) {
                    if (!empty($masterPaths[$pathKey])) {
                        return $library;
                    }
                }
            }
        }

        return [];
    }

    public function libraryPathSet($library)
    {
        $paths = [];
        foreach ($library['paths'] ?? [] as $path) {
            $pathKey = $this->libraryPathKey($path);
            if ($pathKey != '') {
                $paths[$pathKey] = true;
            }
        }

        return $paths;
    }

    public function syncListenerSelectedAccess($master, $listener, $users, $selected)
    {
        $updated = 0;
        $failed  = 0;

        foreach ($users as $masterUser) {
            $link = $this->database->getMediaAppUserLinkForApp($masterUser['id'], $listener['id']);
            if (!$link) {
                continue;
            }
            $listenerUser = $this->database->getMediaAppUser($link['linked_media_app_user_id']);
            if (!$listenerUser) {
                continue;
            }
            $result = $this->syncUserLibraryAccess($master, $masterUser, $listener, $listenerUser, $selected);
            if (!empty($result['error'])) {
                $failed++;
                continue;
            }
            $updated++;
        }

        return ['error' => false, 'updated' => $updated, 'failed' => $failed];
    }

    public function syncListenerUserAccess($master, $listener, $users = [])
    {
        $updated = 0;
        $failed  = 0;
        if (!$users) {
            $users = $this->database->getMediaAppUsers($master['id']);
        }
        foreach ($users as $masterUser) {
            $link = $this->database->getMediaAppUserLinkForApp($masterUser['id'], $listener['id']);
            if (!$link) {
                continue;
            }
            $listenerUser = $this->database->getMediaAppUser($link['linked_media_app_user_id']);
            if (!$listenerUser) {
                continue;
            }
            $result = $this->syncUserLibraryAccess($master, $masterUser, $listener, $listenerUser);
            if (!empty($result['error'])) {
                $failed++;
                continue;
            }
            $updated++;
        }

        return ['error' => false, 'updated' => $updated, 'failed' => $failed];
    }

    public function labelAppLibraries($libraries)
    {
        $labeled = [];
        foreach ($libraries as $library) {
            $mediaApp = $this->database->getMediaApp($library['media_app_id']);
            if (!$mediaApp) {
                continue;
            }
            $title = $library['key'];
            foreach ($this->getLibraries($mediaApp) as $appLibrary) {
                if (($appLibrary['key'] ?? '') == $library['key']) {
                    $title = $appLibrary['title'] ?? $library['key'];
                    break;
                }
            }
            $labeled[] = [
                'media_app_id' => intval($mediaApp['id']),
                'key'          => $library['key'],
                'title'        => $title,
                'name'         => $mediaApp['name'],
            ];
        }

        return $labeled;
    }

    public function refreshUsers($id, $local = false)
    {
        $mediaApp = $this->database->getMediaApp($id);
        if (!$mediaApp) {
            return ['error' => true, 'message' => translate('mediaAppNotFound')];
        }
        if (!$this->isOnline($mediaApp)) {
            return ['error' => true, 'message' => translate('offline')];
        }

        $url = $mediaApp['url'];
        switch (intval($mediaApp['platform'])) {
            case MediaPlatforms::PLEX:
                $result = $this->plexGetUsers($url, $mediaApp['token'], $mediaApp['server_id'], $local);
                break;
            case MediaPlatforms::EMBY:
                $result = $this->embyGetUsers($url, $mediaApp['apikey']);
                break;
            case MediaPlatforms::JELLYFIN:
                $result = $this->jellyfinGetUsers($url, $mediaApp['apikey']);
                break;
            default:
                $result = ['error' => true, 'message' => translate('mediaAppNotFound')];
        }

        if ($result['error']) {
            return $result;
        }

        $this->database->replaceMediaAppUsers($id, $result['users']);

        return ['error' => false, 'users' => $this->database->getMediaAppUsers($id)];
    }

    public function refreshParity()
    {
        foreach ($this->database->getMediaApps() as $mediaApp) {
            if (!$mediaApp['active'] || !$this->isOnline($mediaApp)) {
                continue;
            }
            $this->refreshUsers($mediaApp['id'], true);
            $this->refreshStoredLibraries($mediaApp, true);
        }
        $this->applyNewParityUsers();
        $this->applyNewParityLibraries();
    }

    public function linkUsers()
    {
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
            return;
        }

        $masters = [];
        foreach ($this->database->getMediaAppUsers($master['id']) as $user) {
            $masters[strtolower(trim($user['username']))] = $user;
        }

        $linked = [];
        foreach ($this->database->getMediaAppUserLinks() as $link) {
            $linked[intval($link['linked_media_app_user_id'])] = true;
        }

        foreach ($listeners as $listener) {
            foreach ($this->database->getMediaAppUsers($listener['id']) as $user) {
                $username = strtolower(trim($user['username']));
                if (empty($masters[$username]) || !empty($linked[intval($user['id'])])) {
                    continue;
                }
                $this->database->addMediaAppUserLink($masters[$username]['id'], $user['id']);
                $linked[intval($user['id'])] = true;
            }
        }
    }

    public function linkLibraries()
    {
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
            return;
        }

        $masters = [];
        foreach ($this->getLibraries($master, [], false) as $library) {
            $title = strtolower(trim($library['title'] ?? ''));
            if ($title != '' && ($library['key'] ?? '') != '') {
                $masters[$title] = $library;
            }
        }

        foreach ($listeners as $listener) {
            foreach ($this->getLibraries($listener, [], false) as $library) {
                $title = strtolower(trim($library['title'] ?? ''));
                if ($title == '' || ($library['key'] ?? '') == '' || empty($masters[$title])) {
                    continue;
                }
                if ($this->database->getMediaAppLibraryLink($listener['id'], $library['key'])) {
                    continue;
                }
                $this->database->setMediaAppLibraryLink($master['id'], $masters[$title]['key'], $listener['id'], $library['key']);
            }
        }
    }

    public function parityLinkColor($id)
    {
        return 'hsl(' . ((abs(intval($id)) * 137) % 360) . ', 70%, 55%)';
    }

    public function delete($id)
    {
        $mediaApp = $this->database->getMediaApp($id);
        if (!$mediaApp) {
            return ['error' => true, 'message' => translate('mediaAppNotFound')];
        }

        $wasMaster = $mediaApp['role'] == MediaAppRoles::MASTER;
        $this->database->deleteMediaApp($id);

        if ($wasMaster) {
            $mediaApps = $this->database->getMediaApps();
            foreach ($mediaApps as $remaining) {
                $this->database->setMediaAppRole($remaining['id'], MediaAppRoles::MASTER);
                break;
            }
        }

        return ['error' => false, 'message' => translate('saved')];
    }

    public function libraryPosterPath($kind, $itemId)
    {
        $kind = $kind == 'series' ? 'series' : 'movie';
        return POSTER_CACHE_PATH . $kind . '-' . intval($itemId);
    }

    public function libraryPosterExists($kind, $itemId)
    {
        $file = $this->libraryPosterPath($kind, $itemId);
        return is_file($file) && filesize($file) > 0;
    }

    public function libraryPosterUrl($mediaApp, $remoteId, $poster)
    {
        switch (intval($mediaApp['platform'] ?? 0)) {
            case MediaPlatforms::EMBY:
                return $this->embyPosterUrl($mediaApp['url'] ?? '', $mediaApp['apikey'] ?? '', $remoteId, $poster);
            case MediaPlatforms::JELLYFIN:
                return $this->jellyfinPosterUrl($mediaApp['url'] ?? '', $mediaApp['apikey'] ?? '', $remoteId, $poster);
            default:
                return $this->plexPosterUrl($mediaApp['url'] ?? '', $mediaApp['token'] ?? '', $poster);
        }
    }

    public function downloadLibraryPoster($mediaApp, $kind, $itemId, $remoteId, $poster)
    {
        if ($kind == 'episode') {
            return false;
        }
        if (!$this->isOnline($mediaApp)) {
            return false;
        }
        $file = $this->libraryPosterPath($kind, $itemId);
        $url  = $this->libraryPosterUrl($mediaApp, $remoteId, $poster);
        if ($url == '') {
            return false;
        }

        $headers = ['Accept: */*'];
        switch (intval($mediaApp['platform'] ?? 0)) {
            case MediaPlatforms::EMBY:
                $headers    = $this->embyHeaders($mediaApp['apikey'] ?? '');
                $headers[0] = 'Accept: */*';
                break;
            case MediaPlatforms::JELLYFIN:
                $headers    = $this->jellyfinHeaders($mediaApp['apikey'] ?? '');
                $headers[0] = 'Accept: */*';
                break;
            default:
                $headers = $this->plexHeaders($mediaApp['token'] ?? '', '', '*/*');
                break;
        }

        $curl = curl($url, $headers, 'GET', '', [], 20);
        if (intval($curl['code']) < 200 || intval($curl['code']) > 299 || !is_string($curl['response']) || $curl['response'] == '') {
            return false;
        }

        $dir = dirname($file);
        if ($dir && !is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        return file_put_contents($file, $curl['response']) != false;
    }

    public function ensureLibraryPosterCached($kind, $itemId)
    {
        $kind   = $kind == 'series' ? 'series' : 'movie';
        $itemId = intval($itemId);
        if (!$itemId || $this->libraryPosterExists($kind, $itemId)) {
            return $this->libraryPosterExists($kind, $itemId);
        }

        $row    = $kind == 'series' ? $this->database->getSeries($itemId) : $this->database->getMovie($itemId);
        $poster = trim(strval($row['poster'] ?? ''));
        if (!$row || $poster == '') {
            return false;
        }

        $apps = $this->database->getMediaApps();
        usort($apps, function ($a, $b) {
            $aMaster = intval($a['role'] ?? 0) == MediaAppRoles::MASTER ? 0 : 1;
            $bMaster = intval($b['role'] ?? 0) == MediaAppRoles::MASTER ? 0 : 1;
            return $aMaster <=> $bMaster;
        });
        foreach ($apps as $mediaApp) {
            if (!intval($mediaApp['active'] ?? 0)) {
                continue;
            }
            $field    = $this->database->mediaLibraryRemoteField($mediaApp['platform']);
            $remoteId = trim(strval($row[$field] ?? ''));
            if ($remoteId == '' && intval($mediaApp['platform']) != MediaPlatforms::PLEX) {
                continue;
            }
            if (intval($mediaApp['platform']) == MediaPlatforms::PLEX && $remoteId == '' && !str_starts_with($poster, '/') && !preg_match('#^https?://#i', $poster)) {
                continue;
            }
            if ($this->downloadLibraryPoster($mediaApp, $kind, $itemId, $remoteId, $poster)) {
                return true;
            }
        }

        return false;
    }
}
