<?php

/*
----------------------------------
------  Created: 091226   ------
------  Austin Best       ------
----------------------------------
*/

trait Parity
{
    public function paritySyncSetting($kind)
    {
        return $kind == 'library' ? 'parityLibrarySync' : 'parityUserSync';
    }

    public function paritySyncState($kind)
    {
        if (isset($this->parityStateCache[$kind])) {
            return $this->parityStateCache[$kind];
        }

        $state                         = json_decode($this->database->getSetting($this->paritySyncSetting($kind)), true);
        $state                         = is_array($state) ? $state : [];
        $this->parityStateCache[$kind] = $state;

        return $state;
    }

    public function parityItemSelected($kind, $id)
    {
        $state = $this->paritySyncState($kind);

        return !empty($state[$id]);
    }

    public function setParitySync($kind, $items)
    {
        if ($kind != 'user' && $kind != 'library') {
            return;
        }

        $state = [];
        foreach ($items as $id => $enabled) {
            $key = trim(strval($id));
            if ($key == '' || empty($enabled)) {
                continue;
            }
            $state[$key] = 1;
        }
        $this->database->setSetting($this->paritySyncSetting($kind), json_encode($state));
        $this->parityStateCache[$kind] = $state;
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

    public function applyNewParityUsers()
    {
        $master = $this->masterMediaApp();
        if (!$master) {
            return;
        }

        $ids = [];
        foreach ($this->database->getMediaAppUsers($master['id']) as $user) {
            $id = strval($user['id'] ?? '');
            if ($id != '') {
                $ids[] = $id;
            }
        }

        if ($this->database->getSetting('syncSeenParityUsers') == '') {
            $this->rememberSeenIds('syncSeenParityUsers', $ids);
            return;
        }
        $seen = $this->database->getJsonSetting('syncSeenParityUsers');
        if ($this->database->settingEnabled('syncParityAutoUsers')) {
            $state = $this->paritySyncState('user');
            $added = false;
            foreach ($ids as $id) {
                if (!empty($seen[$id]) || $this->database->getMediaAppUserLinks(intval($id))) {
                    continue;
                }
                $state[$id] = 1;
                $added      = true;
            }
            if ($added) {
                $this->setParitySync('user', $state);
            }
        }
        $this->rememberSeenIds('syncSeenParityUsers', $ids);
    }

    public function applyNewParityLibraries()
    {
        $master = $this->masterMediaApp();
        if (!$master) {
            return;
        }

        $ids = [];
        foreach ($this->getLibraries($master, [], false) as $library) {
            $key = strval($library['key'] ?? '');
            if ($key != '') {
                $ids[] = intval($master['id']) . ':' . $key;
            }
        }

        if ($this->database->getSetting('syncSeenParityLibraries') == '') {
            $this->rememberSeenIds('syncSeenParityLibraries', $ids);
            return;
        }
        $seen = $this->database->getJsonSetting('syncSeenParityLibraries');
        if ($this->database->settingEnabled('syncParityAutoLibraries')) {
            $state = $this->paritySyncState('library');
            $added = false;
            foreach ($ids as $id) {
                $parts = explode(':', $id, 2);
                if (!empty($seen[$id]) || $this->database->getMediaAppLibraryLinks(intval($parts[0]), $parts[1] ?? '')) {
                    continue;
                }
                $state[$id] = 1;
                $added      = true;
            }
            if ($added) {
                $this->setParitySync('library', $state);
            }
        }
        $this->rememberSeenIds('syncSeenParityLibraries', $ids);
    }

    public function applyNewScanLibraries()
    {
        $ids = [];
        foreach ($this->database->getMediaApps() as $mediaApp) {
            if (!$mediaApp['active']) {
                continue;
            }
            foreach ($this->getLibraries($mediaApp, [], false) as $library) {
                $key = strval($library['key'] ?? '');
                if ($key != '') {
                    $ids[] = intval($mediaApp['id']) . ':' . $key;
                }
            }
        }

        if ($this->database->getSetting('syncSeenScanLibraries') == '') {
            $this->rememberSeenIds('syncSeenScanLibraries', $ids);
            return;
        }
        $seen = $this->database->getJsonSetting('syncSeenScanLibraries');
        if ($this->database->settingEnabled('syncLibraryAutoMeta')) {
            $state = $this->scanLibraryState();
            $added = false;
            foreach ($ids as $id) {
                if (!empty($seen[$id])) {
                    continue;
                }
                $state[$id] = 1;
                $added      = true;
            }
            if ($added) {
                $this->setScanLibraries($state);
            }
        }
        $this->rememberSeenIds('syncSeenScanLibraries', $ids);
    }

    public function applyNewHistoryUsers()
    {
        $master = $this->masterMediaApp();
        if (!$master || !$this->database->settingEnabled('syncHistoryNewUsers')) {
            return;
        }

        $this->refreshUsers($master['id']);
    }

    public function applySyncAutoSelections()
    {
        $this->applyNewParityUsers();
        $this->applyNewParityLibraries();
        $this->applyNewScanLibraries();
        $this->applyNewHistoryUsers();
    }

    protected function rememberSeenIds($name, $ids)
    {
        $state = [];
        foreach ($ids as $id) {
            $key = trim(strval($id));
            if ($key != '') {
                $state[$key] = 1;
            }
        }
        $this->database->setJsonSetting($name, $state);
    }
}
