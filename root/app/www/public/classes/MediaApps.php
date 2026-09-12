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

    protected $database;

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
        $syncType = intval($job['sync_type'] ?? 0);
        if ($syncType == MediaSyncTypes::LIBRARY) {
            return $this->getLibraryScanName($job['scan'] ?? MediaLibraryScans::LAST_SCAN);
        }
        if ($syncType == MediaSyncTypes::LIBRARIES || !empty($job['sync_accounts'])) {
            return translate('pushMainToListeners');
        }

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
        $url = rtrim(trim($mediaApp['url'] ?? ''), '/');
        if (!$url) {
            return false;
        }

        switch (intval($mediaApp['platform'])) {
            case MediaPlatforms::PLEX:
                return $this->plexIsOnline($url);
            case MediaPlatforms::EMBY:
            case MediaPlatforms::JELLYFIN:
                return $this->embyIsOnline($url);
            default:
                return false;
        }
    }

    public function validate($platform, $url, $token, $apikey)
    {
        $url = rtrim(trim($url), '/');

        switch (intval($platform)) {
            case MediaPlatforms::PLEX:
                return $this->plexTestConnection($url, $token);
            case MediaPlatforms::EMBY:
                return $this->testEmbyJellyfinConnection($url, $apikey);
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
        }

        if ($isMaster) {
            $this->database->setAllMediaAppsListener();
            $this->database->setMediaAppRole($id, MediaAppRoles::MASTER);
        }

        $this->database->replaceMediaAppUsers($id, $validated['users']);

        return ['error' => false, 'message' => translate('mediaAppSaved'), 'id' => $id];
    }

    public function getAppLibraries()
    {
        $apps = [];
        foreach ($this->database->getMediaApps() as $mediaApp) {
            if (!$mediaApp['active']) {
                continue;
            }
            $apps[] = [
                'id'        => $mediaApp['id'],
                'name'      => $mediaApp['name'],
                'platform'  => $mediaApp['platform'],
                'libraries' => $this->getLibraries($mediaApp),
            ];
        }

        return $apps;
    }

    public function getLibraries($mediaApp)
    {
        switch (intval($mediaApp['platform'])) {
            case MediaPlatforms::PLEX:
                $libraries = $this->plexGetLibraries($mediaApp['url'], $mediaApp['token']);
                break;
            case MediaPlatforms::EMBY:
                $libraries = $this->embyGetLibraries($mediaApp['url'], $mediaApp['apikey']);
                break;
            case MediaPlatforms::JELLYFIN:
                $libraries = $this->jellyfinGetLibraries($mediaApp['url'], $mediaApp['apikey']);
                break;
            default:
                return [];
        }

        return $this->sortLibraries($libraries);
    }

    public function sortLibraries($libraries)
    {
        usort($libraries, function ($a, $b) {
            return strcasecmp($a['title'] ?? '', $b['title'] ?? '');
        });

        return $libraries;
    }

    public function getRootFolders($mediaApp)
    {
        $roots = [];
        foreach ($this->getLibraries($mediaApp) as $library) {
            foreach ($library['paths'] ?? [] as $path) {
                $path = trim(str_replace('\\', '/', $path));
                $path = rtrim($path, '/');
                if ($path === '' || $path === '/' || preg_match('/^[a-zA-Z]:$/', $path)) {
                    continue;
                }
                $key = strtolower($path);
                if (!isset($roots[$key])) {
                    $roots[$key] = $path;
                }
            }
        }

        $folders = array_values($roots);
        natcasesort($folders);

        return array_values($folders);
    }

    public function getItems($mediaApp, $libraryKeys = [], $seriesRemoteId = '', $since = 0)
    {
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

    public function getWatchStatus($mediaApp, $remoteId, $username = '')
    {
        switch (intval($mediaApp['platform'])) {
            case MediaPlatforms::PLEX:
                return $this->plexGetWatchStatus($mediaApp['url'], $mediaApp['token'], $remoteId, $username);
            case MediaPlatforms::EMBY:
                return $this->embyGetWatchStatus($mediaApp['url'], $mediaApp['apikey'], $remoteId, $username);
            case MediaPlatforms::JELLYFIN:
                return $this->jellyfinGetWatchStatus($mediaApp['url'], $mediaApp['apikey'], $remoteId, $username);
            default:
                return ['movies' => [], 'episodes' => []];
        }
    }

    public function setWatchStatus($mediaApp, $user, $remoteId, $started, $inprogress, $finished)
    {
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
        $type = strtolower(trim($type));
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

    public function createLibrary($mediaApp, $sourceLibrary)
    {
        $title = trim($sourceLibrary['title'] ?? '');
        $paths = [];
        foreach ($sourceLibrary['paths'] ?? [] as $path) {
            if (trim($path) !== '') {
                $paths[] = $path;
            }
        }
        $type = $this->libraryTypeForPlatform($sourceLibrary['type'] ?? '', $mediaApp['platform']);
        if ($title === '' || !$paths || $type === '') {
            return ['error' => true, 'message' => translate('couldNotSaveSettings')];
        }

        switch (intval($mediaApp['platform'])) {
            case MediaPlatforms::PLEX:
                return $this->plexCreateLibrary($mediaApp['url'], $mediaApp['token'], $title, $type, $paths);
            case MediaPlatforms::EMBY:
                return $this->embyCreateLibrary($mediaApp['url'], $mediaApp['apikey'], $title, $type, $paths);
            case MediaPlatforms::JELLYFIN:
                return $this->jellyfinCreateLibrary($mediaApp['url'], $mediaApp['apikey'], $title, $type, $paths);
            default:
                return ['error' => true, 'message' => translate('mediaAppNotFound')];
        }
    }

    public function createUser($mediaApp, $username)
    {
        switch (intval($mediaApp['platform'])) {
            case MediaPlatforms::PLEX:
                return $this->plexCreateUser($mediaApp['url'], $mediaApp['token'], $username);
            case MediaPlatforms::EMBY:
                return $this->embyCreateUser($mediaApp['url'], $mediaApp['apikey'], $username);
            case MediaPlatforms::JELLYFIN:
                return $this->jellyfinCreateUser($mediaApp['url'], $mediaApp['apikey'], $username);
            default:
                return ['error' => true, 'message' => translate('mediaAppNotFound')];
        }
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

    public function refreshUsers($id)
    {
        $mediaApp = $this->database->getMediaApp($id);
        if (!$mediaApp) {
            return ['error' => true, 'message' => translate('mediaAppNotFound')];
        }

        $url = $mediaApp['url'];
        switch (intval($mediaApp['platform'])) {
            case MediaPlatforms::PLEX:
                $result = $this->plexGetUsers($url, $mediaApp['token'], $mediaApp['server_id']);
                break;
            case MediaPlatforms::EMBY:
                $result = $this->getEmbyJellyfinUsers($url, $mediaApp['apikey']);
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

        foreach ($listeners as $listener) {
            foreach ($this->database->getMediaAppUsers($listener['id']) as $user) {
                $username = strtolower(trim($user['username']));
                if (empty($masters[$username])) {
                    continue;
                }
                if ($this->database->getMediaAppUserLink($user['id'])) {
                    continue;
                }
                $this->database->addMediaAppUserLink($masters[$username]['id'], $user['id']);
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
        foreach ($this->getLibraries($master) as $library) {
            $title = strtolower(trim($library['title'] ?? ''));
            if ($title !== '' && ($library['key'] ?? '') !== '') {
                $masters[$title] = $library;
            }
        }

        foreach ($listeners as $listener) {
            foreach ($this->getLibraries($listener) as $library) {
                $title = strtolower(trim($library['title'] ?? ''));
                if ($title === '' || ($library['key'] ?? '') === '' || empty($masters[$title])) {
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
}
