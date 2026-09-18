<?php

/*
----------------------------------
------  Created: 091126   ------
------  Austin Best       ------
----------------------------------
*/

trait Jobs
{
    protected $jobLockHandle = null;

    public function jobs()
    {
        $jobs = [];
        foreach ($this->database->getSyncHistoryJobs() as $row) {
            $job = $this->jobFromRow($row);
            if ($job) {
                if (($job['status'] ?? '') == 'running' && $this->recoverStaleRunningJob($job)) {
                    $job = $this->job($job['id']);
                }
                if ($job) {
                    $jobs[] = $job;
                }
            }
        }

        return $jobs;
    }

    public function job($id)
    {
        if (!$this->validJobId($id)) {
            return [];
        }

        return $this->jobFromRow($this->database->getSyncHistoryJob($id));
    }

    public function jobFromRow($row)
    {
        if (!$row) {
            return [];
        }

        $job = json_decode($row['payload'] ?? '', true);
        if (!is_array($job)) {
            $job = [];
        }

        $results = json_decode($row['results'] ?? '', true);
        if (!is_array($results)) {
            $results = [];
        }

        $payloadFinished = intval($job['finished'] ?? 0);
        $job['id']       = $row['job_id'] ?? ($job['id'] ?? '');
        $job['status']   = $row['status'] ?? ($job['status'] ?? '');
        $job['queued']   = intval($row['queued'] ?? 0);
        $job['started']  = intval($row['started'] ?? 0);
        $job['finished'] = intval($row['finished'] ?? 0) ?: $payloadFinished;
        $job['log_file'] = $row['log_file'] ?? '';
        if ($results) {
            $job['stats'] = [
                'added'     => intval($results['added'] ?? ($job['stats']['added'] ?? 0)),
                'updated'   => intval($results['updated'] ?? ($job['stats']['updated'] ?? 0)),
                'unchanged' => intval($results['unchanged'] ?? ($job['stats']['unchanged'] ?? 0)),
                'pulled'    => intval($results['pulled'] ?? ($job['stats']['pulled'] ?? 0)),
                'pushed'    => intval($results['pushed'] ?? ($job['stats']['pushed'] ?? 0)),
                'created'   => intval($results['created'] ?? ($job['stats']['created'] ?? 0)),
                'linked'    => intval($results['linked'] ?? ($job['stats']['linked'] ?? 0)),
            ];
        }

        return $this->formatJob($job);
    }

    public function formatJob($job)
    {
        $file    = $job['log_file'] ?? '';
        $queued  = intval($job['queued'] ?? 0);
        $started = intval($job['started'] ?? 0);
        $ended   = intval($job['finished'] ?? 0);
        $status  = $job['status'] ?? '';
        if ($status == 'running') {
            $ended = time();
        } else if ($ended < $started) {
            $ended = $started;
        }

        $job['size'] = 0;
        if ($file) {
            clearstatcache(true, $file);
            if (is_file($file)) {
                $job['size'] = intval(filesize($file));
            }
        }
        $job['runtime']     = ($status == 'queued' || !$started || $ended <= $started) ? '0s' : relativeBetweenDates($started, $ended, true);
        $job['queued_wait'] = ($status == 'queued' && $queued) ? relativeBetweenDates($queued, time(), true) : '';

        return $job;
    }

    public function logFitsViewer($file)
    {
        if (!$file || !is_file($file)) {
            return true;
        }

        clearstatcache(true, $file);
        $size = filesize($file);
        if ($size == false) {
            return true;
        }

        return intval($size) < 2097152;
    }

    public function logLines($id, $before, $limit, $after = null)
    {
        $job = $this->job($id);
        if (!$job) {
            return ['lines' => [], 'before' => 0, 'done' => true, 'end' => 0, 'tail' => true];
        }

        $file    = $job['log_file'] ?? '';
        $running = ($job['status'] ?? '') == 'running';
        if (!$file || !is_file($file)) {
            return ['lines' => [], 'before' => 0, 'done' => !$running, 'end' => 0, 'tail' => !$running];
        }

        $all   = $this->logContentLines($id);
        $total = count($all);
        $limit = intval($limit) ?: 50;

        if (!is_null($after)) {
            $start = max(0, intval($after));
            if ($start >= $total) {
                return ['lines' => [], 'before' => $start, 'end' => $start, 'done' => $start <= 0, 'tail' => true];
            }
            $slice = array_slice($all, $start, $limit);
            $end   = $start + count($slice);

            return [
                'lines'  => $slice,
                'before' => $start,
                'end'    => $end,
                'done'   => false,
                'tail'   => $end >= $total,
            ];
        }

        $before = intval($before);
        if ($before < 0) {
            $start = max(0, $total - $limit);
        } else if ($before == 0) {
            return ['lines' => [], 'before' => 0, 'done' => true, 'end' => 0, 'tail' => $total <= 0];
        } else {
            $start = max(0, $before - $limit);
        }
        $end   = $before < 0 ? $total : $before;
        $slice = array_slice($all, $start, max(0, $end - $start));

        return [
            'lines'  => $slice,
            'before' => $start,
            'end'    => $start + count($slice),
            'done'   => $start <= 0,
            'tail'   => ($start + count($slice)) >= $total,
        ];
    }

    public function logContentLines($id)
    {
        $job  = $this->job($id);
        $file = $job['log_file'] ?? '';
        if (!$file || !is_file($file)) {
            return [];
        }

        $all = file($file, FILE_IGNORE_NEW_LINES);
        if ($all == false) {
            return [];
        }
        if ($all && $this->parseJobLine($all[0])) {
            array_shift($all);
        }

        return $all;
    }

    public function logSearch($id, $query)
    {
        $query = trim(strval($query));
        if ($query == '') {
            return ['matches' => [], 'query' => $query, 'total' => 0];
        }

        $matches = [];
        foreach ($this->logContentLines($id) as $index => $line) {
            if (is_int(mb_stripos((string) $line, $query))) {
                $matches[] = intval($index);
            }
        }

        return [
            'matches' => array_values($matches),
            'query'   => $query,
            'total'   => count($matches),
        ];
    }

    public function logSearchChunk($id, $query, $before, $limit = 100, $after = null)
    {
        $query   = trim(strval($query));
        $limit   = max(1, intval($limit) ?: 100);
        $search  = $this->logSearch($id, $query);
        $matches = $search['matches'];
        $total   = count($matches);
        if (!$query || !$total) {
            return [
                'lines'  => [],
                'html'   => '',
                'before' => 0,
                'end'    => 0,
                'done'   => true,
                'tail'   => true,
                'total'  => 0,
                'query'  => $query,
            ];
        }

        if (!is_null($after)) {
            $start = max(0, intval($after));
            if ($start >= $total) {
                return [
                    'lines'  => [],
                    'html'   => '',
                    'before' => $start,
                    'end'    => $start,
                    'done'   => $start <= 0,
                    'tail'   => true,
                    'total'  => $total,
                    'query'  => $query,
                ];
            }
            $slice = array_slice($matches, $start, $limit);
            $end   = $start + count($slice);
        } else {
            $before = intval($before);
            if ($before < 0) {
                $start = max(0, $total - $limit);
                $end   = $total;
            } else if ($before <= 0) {
                return [
                    'lines'  => [],
                    'html'   => '',
                    'before' => 0,
                    'end'    => 0,
                    'done'   => true,
                    'tail'   => $total <= 0,
                    'total'  => $total,
                    'query'  => $query,
                ];
            } else {
                $start = max(0, $before - $limit);
                $end   = $before;
            }
            $slice = array_slice($matches, $start, max(0, $end - $start));
            $end   = $start + count($slice);
        }

        $all   = $this->logContentLines($id);
        $lines = [];
        foreach ($slice as $lineIndex) {
            $lines[] = [
                'line' => intval($lineIndex),
                'text' => strval($all[$lineIndex] ?? ''),
            ];
        }

        return [
            'lines'  => $lines,
            'html'   => $this->renderLogMatchHtml($lines, $query),
            'before' => $start,
            'end'    => $end,
            'done'   => $start <= 0,
            'tail'   => $end >= $total,
            'total'  => $total,
            'query'  => $query,
        ];
    }

    public function highlightLogQuery($text, $query)
    {
        $text  = strval($text);
        $query = strval($query);
        if ($query == '') {
            return htmlEscape($text);
        }

        $pattern = '/' . preg_quote($query, '/') . '/iu';
        if (!preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE)) {
            return htmlEscape($text);
        }

        $html   = '';
        $offset = 0;
        foreach ($matches[0] as $match) {
            $pos = intval($match[1]);
            $len = strlen($match[0]);
            if ($pos > $offset) {
                $html .= htmlEscape(substr($text, $offset, $pos - $offset));
            }
            $html   .= '<mark class="sync-log-mark">' . htmlEscape($match[0]) . '</mark>';
            $offset  = $pos + $len;
        }
        if ($offset < strlen($text)) {
            $html .= htmlEscape(substr($text, $offset));
        }

        return $html;
    }

    public function renderLogMatchHtml($lines, $query = '')
    {
        $html = '';
        foreach ($lines as $row) {
            $line  = intval($row['line'] ?? 0);
            $text  = strval($row['text'] ?? '');
            $html .= $this->logLineHtml($line, $this->highlightLogQuery($text, $query), 'sync-log-match-line');
        }

        return $html;
    }

    public function logWindow($id, $center, $limit = 50)
    {
        $all   = $this->logContentLines($id);
        $total = count($all);
        if (!$total) {
            return ['lines' => [], 'before' => 0, 'end' => 0, 'done' => true, 'total' => 0, 'tail' => true];
        }

        $limit  = max(1, intval($limit) ?: 50);
        $center = max(0, min(intval($center), $total - 1));
        $half   = intval(floor($limit / 2));
        $start  = max(0, $center - $half);
        if ($start + $limit > $total) {
            $start = max(0, $total - $limit);
        }
        $slice = array_slice($all, $start, $limit);

        return [
            'lines'  => $slice,
            'before' => $start,
            'end'    => $start + count($slice),
            'done'   => $start <= 0,
            'total'  => $total,
            'tail'   => ($start + count($slice)) >= $total,
        ];
    }

    public function logLineHtml($line, $body, $class = '')
    {
        $extra = trim($class) != '' ? ' ' . trim($class) : '';

        return '<div class="sync-log-line' . $extra . '" data-line="' . intval($line) . '"><span class="sync-log-num">' . (intval($line) + 1) . '</span><span class="sync-log-text">' . $body . '</span></div>';
    }

    public function renderLogHtml($lines, $start = 0)
    {
        $html  = '';
        $start = intval($start);
        foreach ($lines as $index => $line) {
            $html .= $this->logLineHtml($start + intval($index), htmlEscape($line));
        }

        return $html;
    }

    public function tailLog($id, $offset)
    {
        $empty = ['lines' => [], 'offset' => 0, 'status' => ''];
        if (!$this->validJobId($id)) {
            return $empty;
        }

        $job    = $this->job($id);
        $file   = $job['log_file'] ?? '';
        $offset = intval($offset);
        $lines  = [];
        clearstatcache(true, $file);
        if ($file && is_file($file)) {
            $fp = fopen($file, 'r');
            if ($fp) {
                if ($offset == 0) {
                    $first = fgets($fp);
                    if ($first != false && !$this->parseJobLine($first)) {
                        $lines[] = rtrim($first, "\r\n");
                    }
                    $offset = ftell($fp);
                } else {
                    fseek($fp, $offset);
                }
                while (($line = fgets($fp)) != false) {
                    $lines[] = rtrim($line, "\r\n");
                }
                $offset = ftell($fp);
                fclose($fp);
            }
        }

        return [
            'lines'  => $lines,
            'offset' => $offset,
            'status' => $job['status'] ?? '',
        ];
    }

    public function logFile($id)
    {
        $job  = $this->job($id);
        $file = $job['log_file'] ?? '';

        return ($file && is_file($file)) ? $file : '';
    }

    public function validJobId($id)
    {
        return $id && preg_match('/^(pull|push|both|webhook)-\d{8}-\d{6}(-\d+)?$/', $id);
    }

    public function cancelled()
    {
        $id = $this->sidecar['id'] ?? '';
        if (!$id) {
            return true;
        }

        $row = $this->database->getSyncHistoryJob($id);

        return !$row || ($row['status'] ?? '') == 'cancelled';
    }

    public function stopIfCancelled()
    {
        static $checked = 0;
        if ($checked && time() == $checked) {
            return;
        }
        $checked = time();
        if ($this->cancelled()) {
            throw new Exception('cancelled');
        }
    }

    public function cancel($id)
    {
        if (!$this->validJobId($id)) {
            return ['error' => true, 'message' => translate('pageNotFound')];
        }

        $job = $this->job($id);
        if (!$job) {
            return ['error' => true, 'message' => translate('pageNotFound')];
        }

        if (($job['status'] ?? '') == 'running' || ($job['status'] ?? '') == 'queued' || !intval($job['finished'] ?? 0)) {
            $this->sidecar             = $job;
            $this->sidecar['status']   = 'cancelled';
            $this->sidecar['finished'] = intval($job['finished'] ?? 0) ?: time();
            unset($this->sidecar['runtime'], $this->sidecar['size'], $this->sidecar['queued_wait']);
            $this->writeJobHeader();
        }

        return ['error' => false, 'message' => translate('syncCancelled')];
    }

    public function deleteLog($id)
    {
        if (!$this->validJobId($id)) {
            return ['error' => true, 'message' => translate('pageNotFound')];
        }

        $job = $this->job($id);
        if (!$job) {
            return ['error' => true, 'message' => translate('pageNotFound')];
        }

        if (($job['status'] ?? '') == 'running' || ($job['status'] ?? '') == 'queued') {
            $this->cancel($id);
        }

        $log = $job['log_file'] ?? '';
        if ($log && is_file($log)) {
            unlink($log);
        }

        $this->database->deleteSyncHistory($id);

        return ['error' => false, 'message' => translate('removed')];
    }

    public function requeue($id)
    {
        if (!$this->validJobId($id)) {
            return ['error' => true, 'message' => translate('pageNotFound')];
        }

        $job = $this->job($id);
        if (!$job) {
            return ['error' => true, 'message' => translate('pageNotFound')];
        }

        $status = $job['status'] ?? '';
        if ($status == 'running' || $status == 'queued') {
            return ['error' => true, 'message' => translate('syncAlreadyRunning')];
        }

        if (intval($job['sync_type'] ?? 0) == MediaSyncTypes::HISTORY && !$this->hasLibraryData()) {
            return ['error' => true, 'message' => translate('historyNeedsLibraryData')];
        }

        $log = $job['log_file'] ?? '';
        if ($log && is_file($log)) {
            unlink($log);
        }
        $defaultLog = CRON_LOGS_PATH . $id . '.log';
        if ((!$log || $log != $defaultLog) && is_file($defaultLog)) {
            unlink($defaultLog);
        }

        $this->sidecar             = $job;
        $this->sidecar['status']   = 'queued';
        $this->sidecar['queued']   = time();
        $this->sidecar['started']  = 0;
        $this->sidecar['finished'] = 0;
        $this->sidecar['log_file'] = '';
        $this->sidecar['trigger']  = MediaSyncTriggers::MANUAL;
        unset($this->sidecar['stats'], $this->sidecar['runtime'], $this->sidecar['size'], $this->sidecar['queued_wait'], $this->sidecar['dry_run_summary'], $this->sidecar['sync_summary']);

        if (!$this->writeJobHeader()) {
            return ['error' => true, 'message' => translate('couldNotQueueSync')];
        }

        return ['error' => false, 'message' => translate('syncQueued'), 'id' => $id, 'status' => 'queued'];
    }

    public function deleteAllLogs()
    {
        foreach ($this->database->getSyncHistoryJobs() as $row) {
            $job = $this->jobFromRow($row);
            if (!$job || empty($job['id'])) {
                continue;
            }

            $status = $job['status'] ?? '';
            if ($status == 'running' || $status == 'queued') {
                $this->cancel($job['id']);
            }

            $log = $job['log_file'] ?? '';
            if ($log && is_file($log)) {
                unlink($log);
            }

            $defaultLog = CRON_LOGS_PATH . $job['id'] . '.log';
            if ((!$log || $log != $defaultLog) && is_file($defaultLog)) {
                unlink($defaultLog);
            }

            $this->releaseLock($job['id']);
        }

        if (is_dir(CRON_LOGS_PATH)) {
            foreach (['pull', 'push', 'both', 'webhook'] as $prefix) {
                foreach (glob(CRON_LOGS_PATH . $prefix . '-*.log') ?: [] as $file) {
                    if (is_file($file)) {
                        unlink($file);
                    }
                }
            }
        }

        $this->database->deleteAllSyncHistory();

        return ['error' => false, 'message' => translate('syncHistoryCleared')];
    }

    public function jobLockTypes()
    {
        return ['library', 'libraries', 'parity', 'history', 'users'];
    }

    public function hasLibraryData()
    {
        return $this->database->hasMediaLibraryData();
    }

    public function librarySyncBlocking()
    {
        return $this->runningJob('library') || $this->nextQueuedJob('library') ? true : false;
    }

    public function canStartHistoryJob($job = [])
    {
        if (!$this->hasLibraryData()) {
            return ['ok' => false, 'wait' => false, 'message' => translate('historyNeedsLibraryData')];
        }
        if ($this->librarySyncBlocking()) {
            return ['ok' => false, 'wait' => true, 'message' => translate('historyWaitingForLibrary')];
        }

        return ['ok' => true, 'wait' => false, 'message' => ''];
    }

    public function jobLockType($job)
    {
        $syncType = intval($job['sync_type'] ?? MediaSyncTypes::USERS);
        if ($syncType == MediaSyncTypes::LIBRARY) {
            return 'library';
        }
        if ($syncType == MediaSyncTypes::LIBRARIES) {
            return 'libraries';
        }
        if (!empty($job['sync_accounts'])) {
            return 'parity';
        }
        if ($syncType == MediaSyncTypes::HISTORY) {
            return 'history';
        }

        return 'users';
    }

    public function lockFile($job = [])
    {
        $type = $this->jobLockType($job ?: $this->sidecar);
        if ($type == '') {
            return '';
        }

        return CRON_LOGS_PATH . 'pull-' . $type . '.lock';
    }

    public function runningJob($type = '')
    {
        if ($type != '') {
            return $this->jobFromRow($this->database->getRunningSyncHistoryJob($type));
        }

        foreach ($this->jobLockTypes() as $lockType) {
            $job = $this->jobFromRow($this->database->getRunningSyncHistoryJob($lockType));
            if ($job) {
                return $job;
            }
        }

        return [];
    }

    public function isLocked($job = [])
    {
        $type    = $this->jobLockType($job ?: $this->sidecar);
        $running = $this->runningJob($type);
        if (!$running) {
            return false;
        }

        $id = $job['id'] ?? $this->sidecar['id'] ?? '';
        return ($running['id'] ?? '') != $id;
    }

    public function jobIdPrefix($syncMode, $trigger = MediaSyncTriggers::MANUAL)
    {
        if (intval($trigger) == MediaSyncTriggers::WEBHOOK) {
            return 'webhook';
        }

        switch (intval($syncMode)) {
            case MediaSyncModes::PUSH:
                return 'push';
            case MediaSyncModes::BOTH:
                return 'both';
            default:
                return 'pull';
        }
    }

    public function createJob($mediaAppId, $userIds, $syncMode, $syncType, $libraries = [], $syncAccounts = 0, $trigger = MediaSyncTriggers::MANUAL, $scan = 0, $dryRun = 0)
    {
        if (intval($syncType) == MediaSyncTypes::HISTORY && !$this->hasLibraryData()) {
            return [];
        }

        if (!is_dir(CRON_LOGS_PATH)) {
            mkdir(CRON_LOGS_PATH, 0755, true);
        }

        $syncMode = intval($syncMode) ?: MediaSyncModes::PULL;
        $trigger  = intval($trigger) ?: MediaSyncTriggers::MANUAL;
        $id       = $this->jobIdPrefix($syncMode, $trigger) . '-' . date('Ymd-His');
        if ($this->database->getSyncHistoryJob($id)) {
            $id .= '-' . getmypid();
        }

        global $mediaApps;

        $mediaAppId   = intval($mediaAppId);
        $syncType     = intval($syncType) ?: MediaSyncTypes::USERS;
        $mediaAppName = '';
        $users        = [];
        $ids          = [];
        if (!empty($syncAccounts) && !$userIds) {
            $userIds = $mediaApps->selectedParityUserIds(false);
        }
        if ($syncType == MediaSyncTypes::LIBRARIES && !$libraries) {
            $libraries = $mediaApps->selectedParityLibraries(false);
        }

        if ($mediaAppId) {
            $mediaApp     = $this->database->getMediaApp($mediaAppId);
            $mediaAppName = $mediaApp['name'] ?? '';
        } else if (($syncType == MediaSyncTypes::LIBRARY || $syncType == MediaSyncTypes::LIBRARIES) && $libraries) {
            $titles = [];
            foreach ($libraries as $library) {
                $titles[] = ($library['name'] ?? '') . ($library['title'] ? ' / ' . $library['title'] : '');
            }
            $mediaAppName = implode(', ', $titles);
        }

        if ($syncType != MediaSyncTypes::LIBRARY && $syncType != MediaSyncTypes::LIBRARIES) {
            if ($userIds) {
                foreach ($userIds as $userId) {
                    $user = $this->database->getMediaAppUser(intval($userId));
                    if ($user) {
                        $ids[]   = intval($user['id']);
                        $users[] = $user['username'];
                    }
                }
            } else if ($mediaAppId) {
                foreach ($this->database->getMediaAppUsers($mediaAppId) as $user) {
                    $ids[]   = intval($user['id']);
                    $users[] = $user['username'];
                }
            } else if (empty($syncAccounts)) {
                foreach ($this->database->getMediaApps() as $mediaApp) {
                    if (!$mediaApp['active']) {
                        continue;
                    }
                    foreach ($this->database->getMediaAppUsers($mediaApp['id']) as $user) {
                        $ids[]   = intval($user['id']);
                        $users[] = $user['username'];
                    }
                }
            }
        }

        $job = [
            'id'             => $id,
            'status'         => 'queued',
            'queued'         => time(),
            'started'        => 0,
            'finished'       => 0,
            'log_file'       => '',
            'media_app_id'   => $mediaAppId,
            'media_app_name' => $mediaAppName,
            'sync_type'      => $syncType,
            'sync_mode'      => $syncMode,
            'sync_accounts'  => $syncAccounts ? 1 : 0,
            'trigger'        => $trigger,
            'dry_run'        => !empty($dryRun) ? 1 : 0,
            'libraries'      => $libraries ?: [],
            'user_ids'       => $ids,
            'users'          => $users,
        ];
        if ($syncType == MediaSyncTypes::LIBRARY) {
            $job['scan'] = intval($scan) ?: MediaLibraryScans::LAST_SCAN;
        }

        $previousSidecar = $this->sidecar;
        $previousLogfile = $this->logfile;
        $this->sidecar   = $job;
        $this->logfile   = '';
        $saved           = $this->writeJobHeader();
        $this->sidecar   = $previousSidecar;
        $this->logfile   = $previousLogfile;
        if (!$saved) {
            return [];
        }

        return $this->formatJob($job);
    }

    public function createWebhookJob($kind, $mediaAppId = 0, $userId = 0)
    {
        global $mediaApps;

        $mediaAppId = intval($mediaAppId);
        $userId     = intval($userId);

        if ($kind == 'library') {
            if ($this->runningJob('library') || $this->nextQueuedJob('library')) {
                return [];
            }

            $libraries = [];
            foreach ($mediaApps->selectedScanLibraries() as $library) {
                if (intval($library['media_app_id'] ?? 0) == $mediaAppId) {
                    $libraries[] = $library;
                }
            }
            if (!$libraries && $mediaAppId) {
                $stored = [];
                foreach ($this->database->getMediaAppLibraries($mediaAppId) as $library) {
                    $key = strval($library['key'] ?? '');
                    if ($key == '') {
                        continue;
                    }
                    $stored[] = [
                        'media_app_id' => $mediaAppId,
                        'key'          => $key,
                    ];
                }
                $libraries = $mediaApps->labelAppLibraries($stored);
            }

            $job = $this->createJob($mediaAppId, [], MediaSyncModes::PULL, MediaSyncTypes::LIBRARY, $libraries, 0, MediaSyncTriggers::WEBHOOK, MediaLibraryScans::LAST_SCAN);
        } else {
            if ($kind != 'history' || !$userId) {
                return [];
            }

            $userId = $this->watchMasterUserId($userId);
            if ($this->webhookHistoryPending($userId)) {
                return [];
            }

            $master = $mediaApps->masterMediaApp();
            $mode   = $master ? intval($master['sync_mode'] ?: MediaSyncModes::BOTH) : MediaSyncModes::BOTH;
            $job    = $this->createJob($mediaAppId, [$userId], $mode, MediaSyncTypes::HISTORY, [], 0, MediaSyncTriggers::WEBHOOK);
        }

        if ($job) {
            $this->processQueue();
        }

        return $job ?: [];
    }

    public function queueWebhookSync($mediaAppId, $userId, $event, $item, $type, $state)
    {
        $job = $this->createJob($mediaAppId, [$userId], MediaSyncModes::PUSH, MediaSyncTypes::HISTORY, [], 0, MediaSyncTriggers::WEBHOOK);
        if (!$job) {
            return [];
        }

        $this->sidecar                  = $job;
        $this->sidecar['webhook_event'] = strval($event);
        $this->sidecar['webhook_item']  = [
            'type'         => strval($type),
            'item_id'      => intval($item['id'] ?? 0),
            'user_id'      => intval($userId),
            'media_app_id' => intval($mediaAppId),
            'started'      => intval($state['started'] ?? 0),
            'inprogress'   => intval($state['inprogress'] ?? 0),
            'finished'     => intval($state['finished'] ?? 0),
        ];
        if (!$this->writeJobHeader()) {
            return [];
        }

        $this->processQueue();

        return $this->formatJob($this->sidecar);
    }

    public function webhookHistoryPending($userId)
    {
        $userId = intval($userId);
        foreach ($this->database->getSyncHistoryJobs() as $row) {
            $status = $row['status'] ?? '';
            if ($status != 'queued' && $status != 'running') {
                continue;
            }
            if (($row['lock_type'] ?? '') != 'history') {
                continue;
            }

            $job = $this->jobFromRow($row);
            $ids = $job['user_ids'] ?? [];
            if (!$ids) {
                return true;
            }
            foreach ($ids as $id) {
                if (intval($id) == $userId) {
                    return true;
                }
            }
        }

        return false;
    }

    public function nextQueuedJob($type = '')
    {
        if ($type != '') {
            return $this->jobFromRow($this->database->getNextQueuedSyncHistoryJob($type));
        }

        $queued = [];
        foreach ($this->jobLockTypes() as $lockType) {
            $job = $this->jobFromRow($this->database->getNextQueuedSyncHistoryJob($lockType));
            if ($job) {
                $queued[] = $job;
            }
        }
        usort($queued, function ($a, $b) {
            return intval($a['queued'] ?? 0) - intval($b['queued'] ?? 0);
        });

        return $queued[0] ?? [];
    }

    public function startQueuedJob($job)
    {
        if (!$job || empty($job['id'])) {
            return false;
        }

        $running = $this->runningJob($this->jobLockType($job));
        if ($running && ($running['id'] ?? '') != $job['id']) {
            return false;
        }

        if ($this->jobLockType($job) == 'history') {
            $canStart = $this->canStartHistoryJob($job);
            if (empty($canStart['ok'])) {
                return false;
            }
        }

        $parentLog                 = $this->logfile ?: CRON_DISPATCHER_LOG;
        $savedLog                  = $this->logfile;
        $this->sidecar             = $job;
        $this->sidecar['status']   = 'running';
        $this->sidecar['started']  = time();
        $this->sidecar['finished'] = 0;
        $this->sidecar['log_file'] = CRON_LOGS_PATH . $job['id'] . '.log';
        unset($this->sidecar['runtime'], $this->sidecar['size'], $this->sidecar['queued_wait']);
        $this->logfile = $this->sidecar['log_file'];
        $this->writeJobHeader();
        logger($this->logfile, 'starting');
        loggerFlush($this->logfile);
        $this->spawnScript('sync.php', $job['id'], $parentLog);
        $this->logfile = $savedLog;

        return true;
    }

    public function recoverStaleRunningJob($job)
    {
        if (($job['status'] ?? '') != 'running' || empty($job['id'])) {
            return false;
        }

        $started = intval($job['started'] ?? 0);
        $age     = $started ? time() - $started : 0;
        if ($age < 45) {
            return false;
        }

        $file = $job['log_file'] ?? '';
        clearstatcache(true, $file);
        $missing = !$file || !is_file($file);
        $mtime   = (!$missing && is_file($file)) ? intval(filemtime($file)) : 0;
        $stale   = $missing || ($mtime && time() - $mtime > 120);
        if (!$stale) {
            return false;
        }

        if ($this->logShowsFinished($file)) {
            $this->sidecar             = $job;
            $this->sidecar['status']   = 'finished';
            $this->sidecar['finished'] = intval($job['finished'] ?? 0) ?: $mtime ?: time();
            unset($this->sidecar['runtime'], $this->sidecar['size'], $this->sidecar['queued_wait']);
            $this->logfile = $file;
            $this->writeJobHeader();
            $this->releaseLock($job['id']);

            return true;
        }

        $this->sidecar             = $job;
        $this->sidecar['status']   = 'error';
        $this->sidecar['finished'] = time();
        unset($this->sidecar['runtime'], $this->sidecar['size'], $this->sidecar['queued_wait']);
        $this->logfile = $file ?: (CRON_LOGS_PATH . $job['id'] . '.log');
        logger($this->logfile, 'marked stale: no recent log output');
        loggerFlush($this->logfile);
        $this->writeJobHeader();
        $this->releaseLock($job['id']);

        return true;
    }

    public function logShowsFinished($file)
    {
        if (!$file || !is_file($file)) {
            return false;
        }

        $size = filesize($file);
        if (!$size) {
            return false;
        }

        $fp = fopen($file, 'rb');
        if (!$fp) {
            return false;
        }

        $read = min($size, 65536);
        fseek($fp, -$read, SEEK_END);
        $tail = fread($fp, $read);
        fclose($fp);

        return is_string($tail) && str_contains($tail, 'sync <-');
    }

    public function automaticSchedules()
    {
        return [
            'parity'  => [
                'interval' => MediaSyncIntervals::PARITY,
                'types'    => ['parity', 'libraries'],
            ],
            'library' => [
                'interval' => MediaSyncIntervals::LIBRARY,
                'types'    => ['library'],
            ],
            'history' => [
                'interval' => MediaSyncIntervals::HISTORY,
                'types'    => ['history'],
            ],
        ];
    }

    public function automaticSetting($key)
    {
        $names = [
            'parity'  => 'automaticParity',
            'library' => 'automaticLibrary',
            'history' => 'automaticHistory',
        ];

        return $names[$key] ?? '';
    }

    public function automaticIntervalSetting($key)
    {
        $names = [
            'parity'  => 'automaticParityInterval',
            'library' => 'automaticLibraryInterval',
            'history' => 'automaticHistoryInterval',
        ];

        return $names[$key] ?? '';
    }

    public function automaticEnabled($key)
    {
        $name = $this->automaticSetting($key);

        return $name != '' && $this->database->getSetting($name) == '1';
    }

    public function setAutomaticEnabled($key, $enabled)
    {
        $name = $this->automaticSetting($key);
        if ($name == '') {
            return false;
        }

        $this->database->setSetting($name, $enabled ? '1' : '');

        return true;
    }

    public function clampAutomaticInterval($seconds)
    {
        $seconds = intval($seconds);
        if ($seconds < 900) {
            return 900;
        }
        if ($seconds > 86400) {
            return 86400;
        }

        return $seconds;
    }

    public function automaticIntervalParts($seconds)
    {
        $seconds = $this->clampAutomaticInterval($seconds);
        $hours   = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $minutes = intdiv($minutes, 15) * 15;
        if ($hours >= 24) {
            return [24, 0];
        }
        if ($hours == 0 && $minutes == 0) {
            $minutes = 15;
        }

        return [$hours, $minutes];
    }

    public function automaticInterval($key)
    {
        $schedule = $this->automaticSchedules()[$key] ?? [];
        $name     = $this->automaticIntervalSetting($key);
        $fallback = intval($schedule['interval'] ?? 900);
        if ($name == '') {
            return $this->clampAutomaticInterval($fallback);
        }

        $value = intval($this->database->getSetting($name));
        if ($value <= 0) {
            return $this->clampAutomaticInterval($fallback);
        }

        return $this->clampAutomaticInterval($value);
    }

    public function syncLastFinishedCacheKey($lockType)
    {
        return 'syncLastFinished:' . $lockType;
    }

    public function getSyncLastFinished($lockType)
    {
        if ($lockType == '') {
            return 0;
        }

        $cached = memcacheGet($this->syncLastFinishedCacheKey($lockType));
        if ($cached != null && $cached != false) {
            return intval($cached);
        }

        $row      = $this->database->getLastFinishedSyncHistoryJob($lockType);
        $finished = intval($row['finished'] ?? 0);
        memcacheSet($this->syncLastFinishedCacheKey($lockType), $finished);

        return $finished;
    }

    public function setSyncLastFinished($lockType, $finished)
    {
        if ($lockType == '') {
            return;
        }

        memcacheSet($this->syncLastFinishedCacheKey($lockType), intval($finished));
    }

    public function automaticIntervalLabel($seconds)
    {
        list($hours, $minutes) = $this->automaticIntervalParts($seconds);
        if ($hours && $minutes) {
            return $hours . 'h ' . $minutes . 'm';
        }
        if ($hours) {
            return $hours . 'h';
        }

        return $minutes . 'm';
    }

    public function getSyncLastFinishedCached($lockType)
    {
        if ($lockType == '') {
            return 0;
        }

        $cached = memcacheGet($this->syncLastFinishedCacheKey($lockType));
        if ($cached != null && $cached != false) {
            return intval($cached);
        }

        return 0;
    }

    public function automaticCountdownLabel($seconds)
    {
        $seconds = max(0, intval($seconds));
        $hours   = intval(floor($seconds / 3600));
        $minutes = intval(floor(($seconds % 3600) / 60));
        $remain  = $seconds % 60;
        $parts   = [];
        if ($hours) {
            $parts[] = $hours . 'h';
        }
        if ($hours || $minutes) {
            $parts[] = $minutes . 'm';
        }
        $parts[] = $remain . 's';

        return implode(' ', $parts);
    }

    public function automaticNextRun($key)
    {
        $enabled  = $this->automaticEnabled($key);
        $interval = $this->automaticInterval($key);
        $label    = $this->automaticIntervalLabel($interval);
        $types    = $this->automaticSchedules()[$key]['types'] ?? [];
        $lastAt   = 0;
        foreach ($types as $lockType) {
            $finished = $this->getSyncLastFinishedCached($lockType);
            if ($finished > $lastAt) {
                $lastAt = $finished;
            }
        }
        $nextAt = $lastAt ? ($lastAt + $interval) : time();
        $remain = $nextAt > time() ? ($nextAt - time()) : 0;
        $until  = $this->automaticCountdownLabel($remain);

        return [
            'enabled' => $enabled,
            'label'   => $label,
            'until'   => $until,
            'next_at' => $enabled ? $nextAt : 0,
            'title'   => $enabled ? translate('automatedRunInterval', [$until]) : translate('automationDisabled'),
        ];
    }

    public function queueAutomaticSync()
    {
        global $mediaApps;

        foreach ($this->automaticSchedules() as $key => $schedule) {
            if (!$this->automaticEnabled($key)) {
                continue;
            }

            $interval = $this->automaticInterval($key);

            if ($key == 'parity') {
                foreach (['parity', 'libraries'] as $lockType) {
                    if ($this->runningJob($lockType) || $this->nextQueuedJob($lockType)) {
                        continue;
                    }
                    $lastAt = $this->getSyncLastFinished($lockType);
                    if ($lastAt && (time() - $lastAt) < $interval) {
                        continue;
                    }
                    if ($lockType == 'parity') {
                        $userIds = $mediaApps->selectedParityUserIds(!$this->database->settingEnabled('syncParityAutoUsers'));
                        if ($userIds) {
                            $this->createJob(0, $userIds, MediaSyncModes::PUSH, MediaSyncTypes::USERS, [], 1, MediaSyncTriggers::AUTOMATIC);
                        }
                        continue;
                    }
                    $libraries = $mediaApps->selectedParityLibraries(!$this->database->settingEnabled('syncParityAutoLibraries'));
                    if ($libraries) {
                        $this->createJob(0, [], MediaSyncModes::PUSH, MediaSyncTypes::LIBRARIES, $libraries, 0, MediaSyncTriggers::AUTOMATIC);
                    }
                }
                continue;
            }

            $lockType = $schedule['types'][0] ?? '';
            if ($lockType == '' || $this->runningJob($lockType) || $this->nextQueuedJob($lockType)) {
                continue;
            }
            $lastAt = $this->getSyncLastFinished($lockType);
            if ($lastAt && (time() - $lastAt) < $interval) {
                continue;
            }

            if ($key == 'library') {
                $libraries = $mediaApps->selectedScanLibraries();
                if ($libraries) {
                    $this->createJob(0, [], MediaSyncModes::PULL, MediaSyncTypes::LIBRARY, $libraries, 0, MediaSyncTriggers::AUTOMATIC, MediaLibraryScans::LAST_SCAN);
                }
                continue;
            }

            $master = $mediaApps->masterMediaApp();
            if (!$master) {
                continue;
            }
            $userIds = [];
            foreach ($this->database->getMediaAppUsers($master['id']) as $user) {
                $userIds[] = intval($user['id']);
            }
            if ($userIds) {
                $this->createJob(0, $userIds, intval($master['sync_mode'] ?: MediaSyncModes::BOTH), MediaSyncTypes::HISTORY, [], 0, MediaSyncTriggers::AUTOMATIC);
            }
        }
    }

    public function queueHistoryAfterLibraryFinish()
    {
        global $mediaApps;

        if (!$this->database->settingEnabled('syncHistoryNewLibraries')) {
            return false;
        }

        $master = $mediaApps->masterMediaApp();
        if (!$master) {
            return false;
        }
        if ($this->runningJob('history') || $this->nextQueuedJob('history')) {
            return false;
        }

        $userIds = [];
        foreach ($this->database->getMediaAppUsers($master['id']) as $user) {
            $userIds[] = intval($user['id']);
        }
        if (!$userIds) {
            return false;
        }

        return $this->createJob(0, $userIds, intval($master['sync_mode'] ?: MediaSyncModes::BOTH), MediaSyncTypes::HISTORY, [], 0, MediaSyncTriggers::AUTOMATIC) ? true : false;
    }

    public function processQueue()
    {
        $empty = ['started' => 0];
        if (!is_dir(CRON_LOGS_PATH)) {
            mkdir(CRON_LOGS_PATH, 0755, true);
        }

        $lock = fopen(CRON_LOGS_PATH . 'sync.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
            if ($lock) {
                fclose($lock);
            }
            return $empty;
        }

        $started = 0;
        foreach ($this->jobLockTypes() as $type) {
            $running = $this->runningJob($type);
            if ($running) {
                if ($this->recoverStaleRunningJob($running)) {
                    $this->dispatchLog('stale=' . $type . ' ' . ($running['id'] ?? ''));
                    $running = [];
                } else {
                    continue;
                }
            }

            $next = $this->nextQueuedJob($type);
            if (!$next) {
                continue;
            }

            if ($type == 'history') {
                $canStart = $this->canStartHistoryJob($next);
                if (empty($canStart['ok'])) {
                    $this->dispatchLog((!empty($canStart['wait']) ? 'wait' : 'skip') . '=history ' . ($canStart['message'] ?? ''));
                    continue;
                }
            }

            if ($this->startQueuedJob($next)) {
                $this->dispatchLog('started=' . $type . ' ' . $next['id']);
                $started++;
            } else {
                $this->dispatchLog('failed=' . $next['id']);
            }
        }

        flock($lock, LOCK_UN);
        fclose($lock);

        return ['started' => $started];
    }

    public function parseJobLine($line)
    {
        $job = json_decode(trim($line), true);
        if (!is_array($job) || empty($job['id'])) {
            return [];
        }

        return $job;
    }

    public function writeJobHeader()
    {
        if (empty($this->sidecar['id'])) {
            return;
        }

        $payload = $this->sidecar;
        unset($payload['runtime'], $payload['size'], $payload['queued_wait'], $payload['dry_run_summary'], $payload['sync_summary'], $payload['watch_index']);
        if (is_array($payload['stats'] ?? null)) {
            foreach (array_keys($payload['stats']) as $key) {
                if (is_string($key) && $key != '' && $key[0] == '_') {
                    unset($payload['stats'][$key]);
                }
            }
        }

        $status   = $payload['status'] ?? 'queued';
        $queued   = intval($payload['queued'] ?? 0) ?: time();
        $started  = intval($payload['started'] ?? 0);
        $finished = intval($payload['finished'] ?? 0);
        $logFile  = $payload['log_file'] ?? $this->logfile ?? '';
        $stats    = $payload['stats'] ?? [];
        $results  = [];
        if (!in_array($status, ['queued', 'running'])) {
            $ended   = $finished ?: time();
            $results = [
                'runtime'   => ($started && $ended >= $started) ? ($ended > $started ? relativeBetweenDates($started, $ended, true) : '1s') : '0s',
                'added'     => intval($stats['added'] ?? 0),
                'updated'   => intval($stats['updated'] ?? 0),
                'unchanged' => intval($stats['unchanged'] ?? 0),
                'pulled'    => intval($stats['pulled'] ?? 0),
                'pushed'    => intval($stats['pushed'] ?? 0),
                'created'   => intval($stats['created'] ?? 0),
                'linked'    => intval($stats['linked'] ?? 0),
            ];
        }

        $existing = $this->database->getSyncHistoryJob($payload['id']);
        if ($existing) {
            $saved = $this->database->updateSyncHistory($payload['id'], $status, $started, $finished, $logFile, $payload, $results, $queued);
        } else {
            $saved = $this->database->addSyncHistory($payload['id'], $status, $this->jobLockType($payload), $queued, $started, $finished, $logFile, $payload, $results);
        }

        return $saved && $this->database->getSyncHistoryJob($payload['id']) ? true : false;
    }

    public function acquireLock($jobId)
    {
        $job = (($this->sidecar['id'] ?? '') == $jobId) ? $this->sidecar : $this->job($jobId);
        if (!$job) {
            return false;
        }

        $running = $this->runningJob($this->jobLockType($job));
        if ($running && ($running['id'] ?? '') != $jobId) {
            return false;
        }

        $path = $this->lockFile($job);
        if ($path == '') {
            return false;
        }
        if (!is_dir(CRON_LOGS_PATH)) {
            mkdir(CRON_LOGS_PATH, 0755, true);
        }

        $fp = fopen($path, 'c+');
        if (!$fp || !flock($fp, LOCK_EX | LOCK_NB)) {
            if ($fp) {
                fclose($fp);
            }
            return false;
        }

        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, strval($jobId));
        fflush($fp);
        $this->jobLockHandle = $fp;

        return true;
    }

    public function releaseLock($jobId = '')
    {
        $id = $jobId ?: ($this->sidecar['id'] ?? '');
        if ($this->jobLockHandle) {
            flock($this->jobLockHandle, LOCK_UN);
            fclose($this->jobLockHandle);
            $this->jobLockHandle = null;
        }

        $job  = ($id && ($this->sidecar['id'] ?? '') == $id) ? $this->sidecar : ($id ? $this->job($id) : $this->sidecar);
        $lock = $this->lockFile($job);
        if (!$lock || !is_file($lock)) {
            return;
        }
        $holder = trim(strval(file_get_contents($lock)));
        if ($id != '' && $holder != '' && $holder != $id) {
            return;
        }

        unlink($lock);
    }

    public function mediaAppOnline($mediaApp)
    {
        global $mediaApps;

        if ($mediaApps->isOnline($mediaApp)) {
            return true;
        }

        logger($this->logfile, ($mediaApp['name'] ?? 'media app') . ' offline');

        return false;
    }

    public function onlineMediaApps($apps, $minimum = 0)
    {
        $online = [];
        foreach ($apps as $mediaApp) {
            if ($this->mediaAppOnline($mediaApp)) {
                $online[] = $mediaApp;
            }
        }

        if ($minimum > 0 && count($online) < $minimum) {
            logger($this->logfile, 'need at least ' . $minimum . ' online media apps');
            return [];
        }

        return $online;
    }
}
