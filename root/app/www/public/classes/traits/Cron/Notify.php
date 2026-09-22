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
        $amount                          = intval($amount);
        $this->currentJob['stats'][$key] = intval($this->currentJob['stats'][$key] ?? 0) + $amount;

        $name = $this->statAppName($mediaApp);
        if ($name == '') {
            return;
        }

        $this->ensureAppBuckets($name);
        $this->currentJob['stats']['apps'][$name]['media'][$key] = intval($this->currentJob['stats']['apps'][$name]['media'][$key] ?? 0) + $amount;
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
        if (!isset($this->currentJob['stats']['apps']) || !is_array($this->currentJob['stats']['apps'])) {
            $this->currentJob['stats']['apps'] = [];
        }
        if (!isset($this->currentJob['stats']['apps'][$name]) || !is_array($this->currentJob['stats']['apps'][$name])) {
            $this->currentJob['stats']['apps'][$name] = [];
        }
        if (!isset($this->currentJob['stats']['apps'][$name]['media']) || !is_array($this->currentJob['stats']['apps'][$name]['media'])) {
            $this->currentJob['stats']['apps'][$name]['media'] = [];
        }
        if (!isset($this->currentJob['stats']['apps'][$name]['users']) || !is_array($this->currentJob['stats']['apps'][$name]['users'])) {
            $this->currentJob['stats']['apps'][$name]['users'] = [];
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

    public function addHistoryResult($mediaApp, $username, $type, $status, $amount = 1)
    {
        $name     = $this->statAppName($mediaApp);
        $username = trim(strval($username));
        $status   = trim(strval($status));
        $amount   = intval($amount);
        if ($name == '' || $username == '' || $status == '' || !$amount) {
            return;
        }

        $type = ($type == 'episode' || $type == 'episodes') ? 'episodes' : 'movies';
        $this->ensureAppBuckets($name);
        if (!isset($this->currentJob['stats']['apps'][$name]['users'][$username]) || !is_array($this->currentJob['stats']['apps'][$name]['users'][$username])) {
            $this->currentJob['stats']['apps'][$name]['users'][$username] = [];
        }
        if (!isset($this->currentJob['stats']['apps'][$name]['users'][$username][$type]) || !is_array($this->currentJob['stats']['apps'][$name]['users'][$username][$type])) {
            $this->currentJob['stats']['apps'][$name]['users'][$username][$type] = [];
        }
        $this->currentJob['stats']['apps'][$name]['users'][$username][$type][$status] = intval($this->currentJob['stats']['apps'][$name]['users'][$username][$type][$status] ?? 0) + $amount;
    }

    public function addLibraryResult($mediaApp, $type, $action, $amount = 1)
    {
        $name   = $this->statAppName($mediaApp);
        $type   = trim(strval($type));
        $action = trim(strval($action));
        $amount = intval($amount);
        if ($name == '' || $type == '' || $action == '') {
            return;
        }

        $this->ensureAppBuckets($name);
        if (!isset($this->currentJob['stats']['apps'][$name]['media'][$type]) || !is_array($this->currentJob['stats']['apps'][$name]['media'][$type])) {
            $this->currentJob['stats']['apps'][$name]['media'][$type] = [];
        }
        $this->currentJob['stats']['apps'][$name]['media'][$type][$action] = intval($this->currentJob['stats']['apps'][$name]['media'][$type][$action] ?? 0) + $amount;
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
        if (!isset($this->currentJob['stats']['apps'][$name][$bucket][$action]) || !is_array($this->currentJob['stats']['apps'][$name][$bucket][$action])) {
            $this->currentJob['stats']['apps'][$name][$bucket][$action] = [];
        }
        $this->currentJob['stats']['apps'][$name][$bucket][$action][] = $label;
    }

    public function syncNotifyLog()
    {
        $log = strval($this->logfile ?? '');
        if ($log != '') {
            return $log;
        }
        $log = strval($this->currentJob['log_file'] ?? '');
        if ($log != '') {
            return $log;
        }
        $id = strval($this->currentJob['id'] ?? '');
        if ($id != '' && $this->validJobId($id)) {
            return CRON_LOGS_PATH . $id . '.log';
        }

        return CRON_SYNC_LOG;
    }

    public function notifySync($trigger)
    {
        global $notifications, $mediaApps;

        $log    = $this->syncNotifyLog();
        $status = strval($this->currentJob['status'] ?? '');
        $jobId  = strval($this->currentJob['id'] ?? '');
        if ($status != 'finished' && $status != 'cancelled' && $status != 'error') {
            logger($log, 'notification ' . $trigger . ' skipped: status=' . ($status != '' ? $status : 'empty') . ($jobId != '' ? ' id=' . $jobId : ''));
            return;
        }

        if ($status == 'finished' && !$this->syncHadChanges()) {
            logger($log, 'notification ' . $trigger . ' skipped: nothing changed' . ($jobId != '' ? ' id=' . $jobId : ''));
            return;
        }

        $job       = $this->currentJob;
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
        $started = intval($job['started'] ?? 0);
        $ended   = intval($job['finished'] ?? 0) ?: time();
        if ($started > 0 && $ended >= $started) {
            $runtime = $ended > $started ? relativeBetweenDates($started, $ended, true) : '1s';
        } else {
            $runtime = '0s';
        }
        $payload = [
            'event'     => $trigger,
            'id'        => $job['id'] ?? '',
            'type'      => $mediaApps->getSyncTypeName($job['sync_type'] ?? 0, $job['sync_accounts'] ?? 0, $job['trigger'] ?? 0, $job['webhook_event'] ?? ''),
            'trigger'   => $mediaApps->getTriggerName($job['trigger'] ?? 0),
            'mode'      => $mediaApps->getJobModeName($job),
            'libraries' => $libraryLabel,
            'users'     => $userLabel,
            'status'    => $status,
            'runtime'   => $runtime,
        ];
        if ($syncType == MediaSyncTypes::LIBRARY) {
            $payload['scan'] = $mediaApps->getLibraryScanName($job['scan'] ?? MediaLibraryScans::LAST_SCAN);
        }

        if ($trigger == 'syncWebhook') {
            $payload['mediaApps'] = $apps ? implode(', ', $apps) : translate('allMediaApps');
        } else {
            if (isset($this->currentJob['stats']['_historySeen'])) {
                unset($this->currentJob['stats']['_historySeen']);
                $stats = $this->currentJob['stats'];
            }
            $payload['mediaApps'] = $this->syncEndMediaApps($stats, $apps);
        }

        $libraryTotals = is_array($stats['library'] ?? null) ? $stats['library'] : [];
        if ($libraryTotals) {
            $payload['library'] = [
                'movies'   => intval($libraryTotals['movies'] ?? 0),
                'series'   => intval($libraryTotals['series'] ?? 0),
                'episodes' => intval($libraryTotals['episodes'] ?? 0),
            ];
        }

        logger($log, 'notification ' . $trigger . ' sending' . ($jobId != '' ? ' id=' . $jobId : '') . ' type=' . ($payload['type'] ?? '') . ' runtime=' . $runtime);
        $result = $notifications->notify(0, $trigger, $payload);
        if (!empty($result['skipped'])) {
            logger($log, 'notification ' . $trigger . ' skipped: ' . strval($result['reason'] ?? 'no links'));
            return;
        }
        if (!empty($result['error'])) {
            logger($log, 'notification ' . $trigger . ' failed: ' . strval($result['error']));
            return;
        }
        logger($log, 'notification ' . $trigger . ' sent');
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
            $media            = is_array($appStats['media'] ?? null) ? $appStats['media'] : [];
            $users            = is_array($appStats['users'] ?? null) ? $appStats['users'] : [];
            $mediaApps[$name] = [
                'media' => $media,
                'users' => $users,
            ];
        }

        return $this->stripSyncNotificationNoise($mediaApps);
    }

    public function stripSyncNotificationNoise($data)
    {
        if (!is_array($data)) {
            return $data;
        }

        $clean = [];
        foreach ($data as $key => $value) {
            if ($key == 'unchanged' || $key == 'pulled') {
                continue;
            }
            if (is_array($value)) {
                $value = $this->stripSyncNotificationNoise($value);
                if ($value == []) {
                    continue;
                }
                $clean[$key] = $value;
                continue;
            }
            if (is_numeric($value) && intval($value) == 0) {
                continue;
            }
            $clean[$key] = $value;
        }

        return $clean;
    }

    public function syncHadChanges()
    {
        $stats = is_array($this->currentJob['stats'] ?? null) ? $this->currentJob['stats'] : [];
        foreach (['changed', 'added', 'updated', 'pushed', 'created', 'linked', 'removed', 'passwords'] as $key) {
            if (intval($stats[$key] ?? 0) > 0) {
                return true;
            }
        }

        foreach ($this->librarySummaryRows() as $row) {
            if (intval($row[2] ?? 0) || intval($row[3] ?? 0)) {
                return true;
            }
        }

        foreach ($this->userParitySummaryRows() as $row) {
            if (intval($row[1] ?? 0) || intval($row[2] ?? 0) || intval($row[3] ?? 0)) {
                return true;
            }
        }

        if ($this->paritySummaryRows()) {
            return true;
        }

        $apps = $stats['apps'] ?? [];
        if (!is_array($apps)) {
            return false;
        }
        foreach ($apps as $appStats) {
            if (!is_array($appStats)) {
                continue;
            }
            foreach (['media', 'users'] as $bucketName) {
                $bucket = $appStats[$bucketName] ?? [];
                if (!is_array($bucket)) {
                    continue;
                }
                foreach ($bucket as $key => $entry) {
                    if (!is_array($entry)) {
                        if ($key == 'unchanged' || $key == 'pulled') {
                            continue;
                        }
                        if (intval($entry) > 0) {
                            return true;
                        }
                        continue;
                    }
                    if (array_keys($entry) == range(0, count($entry) - 1)) {
                        if ($entry) {
                            return true;
                        }
                        continue;
                    }
                    foreach ($entry as $status => $amount) {
                        if (is_array($amount)) {
                            if (array_keys($amount) == range(0, count($amount) - 1)) {
                                if ($amount) {
                                    return true;
                                }
                                continue;
                            }
                            foreach ($amount as $innerStatus => $innerAmount) {
                                if ($innerStatus == 'unchanged' || $innerStatus == 'pulled') {
                                    continue;
                                }
                                if (intval($innerAmount) > 0) {
                                    return true;
                                }
                            }
                            continue;
                        }
                        if ($status == 'unchanged' || $status == 'pulled') {
                            continue;
                        }
                        if (intval($amount) > 0) {
                            return true;
                        }
                    }
                }
            }
        }

        return false;
    }

    public function buildSyncLogSummary()
    {
        $sections = [];
        $library  = $this->librarySummaryRows();
        if ($library) {
            $sections[] = ['LIBRARY', ['App', 'Type', 'Added', 'Updated'], $library];
        }
        $parity = [];
        if (empty($this->currentJob['sync_accounts'])) {
            $parity = $this->paritySummaryRows();
        }
        if ($parity) {
            $sections[] = ['PARITY', ['App', 'Group', 'Created', 'Linked', 'Removed'], $parity];
        }
        if (!empty($this->currentJob['sync_accounts'])) {
            $sections[] = ['USERS', ['App', 'Added', 'Removed', 'Linked', 'Unchanged'], $this->userParitySummaryRows()];
        }

        $lines   = [];
        $lines[] = '';
        $lines[] = str_repeat('=', 72);
        $lines[] = 'SUMMARY';
        $lines[] = str_repeat('=', 72);
        $lines[] = '';
        foreach ($this->syncSummarySettingLines() as $line) {
            $lines[] = $line;
        }
        if (!$sections && empty($this->currentJob['sync_summary']) && empty($this->currentJob['sync_accounts'])) {
            $stats   = is_array($this->currentJob['stats'] ?? null) ? $this->currentJob['stats'] : [];
            $lines[] = '';
            foreach (asciiTable(['Added', 'Updated', 'Unchanged', 'Pulled', 'Pushed', 'Created', 'Linked'], [[
                intval($stats['added'] ?? 0),
                intval($stats['updated'] ?? 0),
                intval($stats['unchanged'] ?? 0),
                intval($stats['pulled'] ?? 0),
                intval($stats['pushed'] ?? 0),
                intval($stats['created'] ?? 0),
                intval($stats['linked'] ?? 0),
            ]]) as $line) {
                $lines[] = $line;
            }
        }
        foreach ($sections as $section) {
            $lines[] = '';
            $lines[] = $section[0];
            foreach (asciiTable($section[1], $section[2]) as $line) {
                $lines[] = $line;
            }
        }
        foreach ($this->currentJob['sync_summary'] ?? [] as $line) {
            $lines[] = $line;
        }
        $lines[] = str_repeat('=', 72);
        $lines[] = '';

        return $lines;
    }

    public function syncSummarySettingLines()
    {
        global $mediaApps;

        $job       = $this->currentJob;
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

        $libraryLabel = $libraries ? implode(', ', $libraries) : translate('allLibraries');
        $userLabel    = $users ? implode(', ', $users) : translate('allUsers');
        if ($syncType == MediaSyncTypes::LIBRARY || $syncType == MediaSyncTypes::LIBRARIES) {
            $userLabel = '-';
        }

        $lines   = [];
        $lines[] = 'SYNC SETTINGS';
        $lines[] = 'Type: ' . $mediaApps->getSyncTypeName($job['sync_type'] ?? 0, $job['sync_accounts'] ?? 0, $job['trigger'] ?? 0, $job['webhook_event'] ?? '');
        $lines[] = 'Trigger: ' . $mediaApps->getTriggerName($job['trigger'] ?? 0);
        $lines[] = 'Mode: ' . $mediaApps->getJobModeName($job);
        if ($syncType == MediaSyncTypes::LIBRARY) {
            $lines[] = 'Scan: ' . $mediaApps->getLibraryScanName($job['scan'] ?? MediaLibraryScans::LAST_SCAN);
        }
        $lines[] = 'Libraries: ' . $libraryLabel;
        $lines[] = 'Users: ' . $userLabel;
        $lines[] = 'Media apps: ' . ($apps ? implode(', ', $apps) : translate('allMediaApps'));

        return $lines;
    }

    public function librarySummaryRows()
    {
        $rows  = [];
        $apps  = $this->currentJob['stats']['apps'] ?? [];
        $types = ['movies', 'series', 'episodes'];
        if (!is_array($apps)) {
            return $rows;
        }
        foreach ($apps as $name => $appStats) {
            $media = is_array($appStats['media'] ?? null) ? $appStats['media'] : [];
            foreach ($types as $type) {
                $bucket = $media[$type] ?? null;
                if (!is_array($bucket) || array_keys($bucket) == range(0, count($bucket) - 1)) {
                    continue;
                }
                $added   = intval($bucket['added'] ?? 0);
                $updated = intval($bucket['updated'] ?? 0);
                if (!$added && !$updated) {
                    continue;
                }
                $rows[] = [$name, ucfirst($type), $added, $updated];
            }
        }

        return $rows;
    }

    public function paritySummaryRows()
    {
        $rows = [];
        $apps = $this->currentJob['stats']['apps'] ?? [];
        if (!is_array($apps)) {
            return $rows;
        }
        foreach ($apps as $name => $appStats) {
            foreach (['users', 'media'] as $bucketName) {
                $bucket = $appStats[$bucketName] ?? null;
                if (!is_array($bucket)) {
                    continue;
                }
                $created = $bucket['created'] ?? null;
                $linked  = $bucket['linked'] ?? null;
                $removed = $bucket['removed'] ?? null;
                if (!is_array($created) && !is_array($linked) && !is_array($removed)) {
                    continue;
                }
                $created = is_array($created) ? count($created) : 0;
                $linked  = is_array($linked) ? count($linked) : 0;
                $removed = is_array($removed) ? count($removed) : 0;
                if (!$created && !$linked && !$removed) {
                    continue;
                }
                $group  = $bucketName == 'users' ? 'Users' : 'Libraries';
                $rows[] = [$name, $group, $created, $linked, $removed];
            }
        }

        return $rows;
    }

    public function recordUserParitySummary($app, $added, $removed, $linked, $unchanged)
    {
        if (!isset($this->currentJob['stats']['user_parity']) || !is_array($this->currentJob['stats']['user_parity'])) {
            $this->currentJob['stats']['user_parity'] = [];
        }
        $this->currentJob['stats']['user_parity'][] = [
            $app != '' ? $app : '-',
            intval($added),
            intval($removed),
            intval($linked),
            intval($unchanged),
        ];
    }

    public function userParitySummaryRows()
    {
        $rows = $this->currentJob['stats']['user_parity'] ?? [];

        return is_array($rows) ? $rows : [];
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
