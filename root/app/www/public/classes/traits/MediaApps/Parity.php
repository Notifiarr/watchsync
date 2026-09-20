<?php

/*
----------------------------------
------  Created: 091226   ------
------  Austin Best       ------
----------------------------------
*/

trait Parity
{
    public function paritySyncSetting($type)
    {
        return $type == 'library' ? 'parityLibrarySync' : 'parityUserSync';
    }

    public function paritySyncState($type)
    {
        if (isset($this->parityStateCache[$type])) {
            return $this->parityStateCache[$type];
        }

        $state                         = json_decode($this->database->getSetting($this->paritySyncSetting($type)), true);
        $state                         = is_array($state) ? $state : [];
        $this->parityStateCache[$type] = $state;

        return $state;
    }

    public function parityItemSelected($type, $id)
    {
        $state = $this->paritySyncState($type);

        return !empty($state[$id]);
    }

    public function setParitySync($type, $items)
    {
        if ($type != 'user' && $type != 'library') {
            return;
        }

        $state = [];
        foreach ($items as $id => $enabled) {
            $key = trim(strval($id));
            if ($key == '') {
                continue;
            }
            $state[$key] = empty($enabled) ? 0 : 1;
        }
        $this->database->setSetting($this->paritySyncSetting($type), json_encode($state));
        $this->parityStateCache[$type] = $state;
        if ($type == 'library') {
            $this->purgeMedia();
        }
    }

    public function parityMasterKeyForLibrary($mediaAppId, $libraryKey)
    {
        $master = $this->masterMediaApp();
        if (!$master) {
            return '';
        }

        $mediaAppId = intval($mediaAppId);
        $libraryKey = strval($libraryKey);
        if (!$mediaAppId || $libraryKey == '') {
            return '';
        }
        if ($mediaAppId == intval($master['id'])) {
            return $mediaAppId . ':' . $libraryKey;
        }

        $link = $this->database->getMediaAppLibraryLink($mediaAppId, $libraryKey);
        if ($link && intval($link['media_app_id']) == intval($master['id'])) {
            return intval($link['media_app_id']) . ':' . strval($link['library_key']);
        }

        return '';
    }

    public function libraryAllowedByParity($mediaAppId, $libraryKey, $parityState = null)
    {
        if ($parityState === null) {
            $parityState = $this->paritySyncState('library');
        }
        if (!$parityState) {
            return true;
        }

        $masterKey = $this->parityMasterKeyForLibrary($mediaAppId, $libraryKey);
        if ($masterKey == '') {
            return false;
        }

        return !empty($parityState[$masterKey]);
    }

    public function scanKeysForParityLibrary($masterAppId, $masterLibraryKey)
    {
        $masterAppId      = intval($masterAppId);
        $masterLibraryKey = strval($masterLibraryKey);
        $keys             = [];
        if (!$masterAppId || $masterLibraryKey == '') {
            return $keys;
        }

        $keys[] = $masterAppId . ':' . $masterLibraryKey;
        foreach ($this->database->getMediaAppLibraryLinks($masterAppId, $masterLibraryKey) as $link) {
            $linkedApp = intval($link['linked_media_app_id'] ?? 0);
            $linkedKey = strval($link['linked_library_key'] ?? '');
            if ($linkedApp && $linkedKey != '') {
                $keys[] = $linkedApp . ':' . $linkedKey;
            }
        }

        return $keys;
    }

    public function disableScanLibrariesOutsideParity($parityState = null)
    {
        if ($parityState === null) {
            $parityState = $this->paritySyncState('library');
        }
        if (!$parityState) {
            return ['movies' => 0, 'series' => 0, 'episodes' => 0];
        }

        $scan      = $this->scanLibraryState();
        $pruneKeys = [];
        $changed   = false;

        foreach ($scan as $id => $enabled) {
            $parts = explode(':', trim(strval($id)), 2);
            if (count($parts) != 2) {
                continue;
            }
            if ($this->libraryAllowedByParity(intval($parts[0]), $parts[1], $parityState)) {
                continue;
            }
            $pruneKeys[] = $id;
            if (intval($scan[$id] ?? 0) != 0) {
                $scan[$id] = 0;
                $changed   = true;
            }
        }

        foreach ($parityState as $masterKey => $enabled) {
            if (!empty($enabled)) {
                continue;
            }
            $parts = explode(':', trim(strval($masterKey)), 2);
            if (count($parts) != 2) {
                continue;
            }
            foreach ($this->scanKeysForParityLibrary(intval($parts[0]), $parts[1]) as $id) {
                $pruneKeys[] = $id;
                if (!array_key_exists($id, $scan) || intval($scan[$id]) != 0) {
                    $scan[$id] = 0;
                    $changed   = true;
                }
            }
        }

        $pruneKeys = array_values(array_unique($pruneKeys));
        if ($changed) {
            $this->database->setSetting('libraryScanSync', json_encode($scan));
        }

        return $pruneKeys;
    }

    public function parityDisabledLibraryRoots($parityState = null)
    {
        if ($parityState === null) {
            $parityState = $this->paritySyncState('library');
        }
        if (!$parityState) {
            return [];
        }

        $keys = [];
        foreach ($parityState as $masterKey => $enabled) {
            if (!empty($enabled)) {
                continue;
            }
            $parts = explode(':', trim(strval($masterKey)), 2);
            if (count($parts) != 2 || !intval($parts[0]) || $parts[1] == '') {
                continue;
            }
            foreach ($this->scanKeysForParityLibrary(intval($parts[0]), $parts[1]) as $id) {
                $keys[] = $id;
            }
        }

        return $this->libraryRootsForScanKeys(array_values(array_unique($keys)));
    }

    public function masterMediaApp()
    {
        foreach ($this->database->getMediaApps() as $mediaApp) {
            if ($mediaApp['active'] && $mediaApp['role'] == MediaAppRoles::MASTER) {
                return $mediaApp;
            }
        }

        return [];
    }

    public function selectedParityUserIds($linkedOnly = true)
    {
        $master = $this->masterMediaApp();
        if (!$master) {
            return [];
        }

        $ids = [];
        foreach ($this->database->getMediaAppUsers($master['id']) as $user) {
            if ($this->userIsDeleted($user)) {
                if ($this->parityItemSelected('user', $user['id'])) {
                    global $cron;
                    if (!empty($cron)) {
                        $cron->logDeletedSyncUser($user);
                    }
                }
                continue;
            }
            $linked = $this->database->getMediaAppUserLinks($user['id']) ? true : false;
            if ($linkedOnly && !$linked) {
                continue;
            }
            if ($this->parityItemSelected('user', $user['id'])) {
                $ids[] = intval($user['id']);
            }
        }

        return $ids;
    }

    public function selectedParityLibraries($linkedOnly = true)
    {
        $master = $this->masterMediaApp();
        if (!$master) {
            return [];
        }

        $libraries = [];
        foreach ($this->getLibraries($master) as $library) {
            $key = $library['key'] ?? '';
            if ($key == '') {
                continue;
            }
            $linked = $this->database->getMediaAppLibraryLinks($master['id'], $key) ? true : false;
            if ($linkedOnly && !$linked) {
                continue;
            }
            if ($this->parityItemSelected('library', intval($master['id']) . ':' . $key)) {
                $libraries[] = [
                    'media_app_id' => intval($master['id']),
                    'key'          => $key,
                ];
            }
        }

        return $this->labelAppLibraries($libraries);
    }
}
