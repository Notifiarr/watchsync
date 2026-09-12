<?php

/*
----------------------------------
------  Created: 091226   ------
------  Austin Best       ------
----------------------------------
*/

trait Notify
{
    public function addStat($key, $amount = 1, $mediaApp = null)
    {
        $amount = intval($amount);
        $this->sidecar['stats'][$key] = intval($this->sidecar['stats'][$key] ?? 0) + $amount;

        $name = $this->statAppName($mediaApp);
        if ($name == '') {
            return;
        }

        $this->ensureAppBuckets($name);
        $this->sidecar['stats']['apps'][$name]['media'][$key] = intval($this->sidecar['stats']['apps'][$name]['media'][$key] ?? 0) + $amount;
    }

    public function statAppName($mediaApp)
    {
        if (is_array($mediaApp)) {
            return trim(strval($mediaApp['name'] ?? ''));
        }
        if ($mediaApp != null && $mediaApp != '') {
            return trim(strval($mediaApp));
        }

        return '';
    }

    public function ensureAppBuckets($name)
    {
        if (!isset($this->sidecar['stats']['apps']) || !is_array($this->sidecar['stats']['apps'])) {
            $this->sidecar['stats']['apps'] = [];
        }
        if (!isset($this->sidecar['stats']['apps'][$name]) || !is_array($this->sidecar['stats']['apps'][$name])) {
            $this->sidecar['stats']['apps'][$name] = [];
        }
        if (!isset($this->sidecar['stats']['apps'][$name]['media']) || !is_array($this->sidecar['stats']['apps'][$name]['media'])) {
            $this->sidecar['stats']['apps'][$name]['media'] = [];
        }
        if (!isset($this->sidecar['stats']['apps'][$name]['users']) || !is_array($this->sidecar['stats']['apps'][$name]['users'])) {
            $this->sidecar['stats']['apps'][$name]['users'] = [];
        }
    }

    public function watchResultStatus($watch)
    {
        $watch = is_array($watch) ? $watch : [];
        if (intval($watch['finished'] ?? 0) > 0) {
            return 'finished';
        }
        if (intval($watch['inprogress'] ?? 0) > 0) {
            return 'inProgress';
        }
        if (intval($watch['started'] ?? 0)) {
            return 'started';
        }

        return '';
    }

    public function addHistoryResult($mediaApp, $username, $kind, $status, $amount = 1)
    {
        $name     = $this->statAppName($mediaApp);
        $username = trim(strval($username));
        $status   = trim(strval($status));
        $amount   = intval($amount);
        if ($name == '' || $username == '' || $status == '' || !$amount) {
            return;
        }

        $kind = ($kind == 'episode' || $kind == 'episodes') ? 'episodes' : 'movies';
        $this->ensureAppBuckets($name);
        if (!isset($this->sidecar['stats']['apps'][$name]['users'][$username]) || !is_array($this->sidecar['stats']['apps'][$name]['users'][$username])) {
            $this->sidecar['stats']['apps'][$name]['users'][$username] = [];
        }
        if (!isset($this->sidecar['stats']['apps'][$name]['users'][$username][$kind]) || !is_array($this->sidecar['stats']['apps'][$name]['users'][$username][$kind])) {
            $this->sidecar['stats']['apps'][$name]['users'][$username][$kind] = [];
        }
        $this->sidecar['stats']['apps'][$name]['users'][$username][$kind][$status] = intval($this->sidecar['stats']['apps'][$name]['users'][$username][$kind][$status] ?? 0) + $amount;
    }

    public function addLibraryResult($mediaApp, $kind, $action, $amount = 1)
    {
        $name   = $this->statAppName($mediaApp);
        $kind   = trim(strval($kind));
        $action = trim(strval($action));
        $amount = intval($amount);
        if ($name == '' || $kind == '' || $action == '' || !$amount) {
            return;
        }

        $this->ensureAppBuckets($name);
        if (!isset($this->sidecar['stats']['apps'][$name]['media'][$kind]) || !is_array($this->sidecar['stats']['apps'][$name]['media'][$kind])) {
            $this->sidecar['stats']['apps'][$name]['media'][$kind] = [];
        }
        $this->sidecar['stats']['apps'][$name]['media'][$kind][$action] = intval($this->sidecar['stats']['apps'][$name]['media'][$kind][$action] ?? 0) + $amount;
    }

    public function addParityResult($mediaApp, $group, $action, $label)
    {
        $name   = $this->statAppName($mediaApp);
        $group  = trim(strval($group));
        $action = trim(strval($action));
        $label  = trim(strval($label));
        if ($name == '' || $group == '' || $action == '' || $label == '') {
            return;
        }

        $this->ensureAppBuckets($name);
        $bucket = ($group == 'users') ? 'users' : 'media';
        if (!isset($this->sidecar['stats']['apps'][$name][$bucket][$action]) || !is_array($this->sidecar['stats']['apps'][$name][$bucket][$action])) {
            $this->sidecar['stats']['apps'][$name][$bucket][$action] = [];
        }
        $this->sidecar['stats']['apps'][$name][$bucket][$action][] = $label;
    }

    public function notifySync($trigger)
    {
        global $notifications, $mediaApps;

        $job       = $this->sidecar;
        $syncType  = intval($job['sync_type'] ?? MediaSyncTypes::USERS);
        $libraries = [];
        foreach ($job['libraries'] ?? [] as $library) {
            $title = $library['title'] ?? '';
            if ($title == '') {
                $title = $library['name'] ?? '';
            }
            if ($title == '') {
                $title = $library['key'] ?? '';
            }
            if ($title != '') {
                $libraries[] = $title;
            }
        }

        $users = $job['users'] ?? [];
        $apps  = [];
        if (!empty($job['media_app_id'])) {
            $mediaApp = $this->database->getMediaApp($job['media_app_id']);
            if ($mediaApp) {
                $apps[] = $mediaApp['name'];
            }
        } else {
            foreach ($this->database->getMediaApps() as $mediaApp) {
                if ($mediaApp['active']) {
                    $apps[] = $mediaApp['name'];
                }
            }
        }

        $libraryLabel = '';
        if ($syncType == MediaSyncTypes::LIBRARY || $syncType == MediaSyncTypes::LIBRARIES || $libraries) {
            $libraryLabel = $libraries ? implode(', ', $libraries) : translate('allLibraries');
        }

        $userLabel = '';
        if ($syncType != MediaSyncTypes::LIBRARY && $syncType != MediaSyncTypes::LIBRARIES) {
            $userLabel = $users ? implode(', ', $users) : translate('allUsers');
        }

        $stats   = $job['stats'] ?? [];
        $ended   = intval($job['finished'] ?? 0) ?: time();
        $payload = [
            'event'     => $trigger,
            'id'        => $job['id'] ?? '',
            'type'      => $mediaApps->getSyncTypeName($job['sync_type'] ?? 0, $job['sync_accounts'] ?? 0),
            'trigger'   => $mediaApps->getTriggerName($job['trigger'] ?? 0),
            'mode'      => $mediaApps->getJobModeName($job),
            'libraries' => $libraryLabel,
            'users'     => $userLabel,
            'status'    => $job['status'] ?? '',
            'runtime'   => (!empty($job['started']) && $ended > intval($job['started'])) ? relativeBetweenDates(intval($job['started']), $ended, true) : '0s',
        ];
        if ($syncType == MediaSyncTypes::LIBRARY) {
            $payload['scan'] = $mediaApps->getLibraryScanName($job['scan'] ?? MediaLibraryScans::LAST_SCAN);
        }

        if ($trigger == 'syncStart') {
            $payload['mediaApps'] = $apps ? implode(', ', $apps) : translate('allMediaApps');
        } else {
            if (isset($this->sidecar['stats']['_historySeen'])) {
                unset($this->sidecar['stats']['_historySeen']);
                $stats = $this->sidecar['stats'];
            }
            $payload['mediaApps'] = $this->syncEndMediaApps($stats, $apps);
        }

        $notifications->notify(0, $trigger, $payload);
    }

    public function syncEndMediaApps($stats, $appNames = [])
    {
        $mediaApps = [];
        $tracked   = $stats['apps'] ?? [];
        if (!is_array($tracked)) {
            $tracked = [];
        }

        foreach ($appNames as $name) {
            $name = trim(strval($name));
            if ($name == '') {
                continue;
            }
            $mediaApps[$name] = is_array($tracked[$name] ?? null) ? $tracked[$name] : [];
        }
        foreach ($tracked as $name => $appStats) {
            $name = trim(strval($name));
            if ($name == '' || isset($mediaApps[$name]) || !is_array($appStats)) {
                continue;
            }
            $mediaApps[$name] = $appStats;
        }

        foreach ($mediaApps as $name => $appStats) {
            $media = is_array($appStats['media'] ?? null) ? $appStats['media'] : [];
            $users = is_array($appStats['users'] ?? null) ? $appStats['users'] : [];
            $mediaApps[$name] = [
                'media' => $media,
                'users' => $users,
            ];
        }

        return $mediaApps;
    }

    public function notifyBackup($type, $success, $path = '', $started = 0)
    {
        global $notifications;

        $size = 0;
        if ($success && $path && is_dir($path)) {
            $handle = opendir($path);
            while ($file = readdir($handle)) {
                if ($file[0] == '.' || !is_file($path . '/' . $file)) {
                    continue;
                }
                $size += filesize($path . '/' . $file);
            }
            closedir($handle);
        }

        $ended   = time();
        $started = intval($started) ?: $ended;
        $payload = [
            'event'   => 'backup',
            'type'    => ($type == 'manual') ? 'manual' : 'automatic',
            'status'  => $success ? 'success' : 'failed',
            'runtime' => ($ended > $started) ? relativeBetweenDates($started, $ended, true) : '0s',
            'size'    => $size ? byteConversion($size) : '',
        ];

        $notifications->notify(0, 'backup', $payload);
    }
}
