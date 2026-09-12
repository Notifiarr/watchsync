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
