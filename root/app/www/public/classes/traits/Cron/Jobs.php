<?php

/*
----------------------------------
------  Created: 091126   ------
------  Austin Best       ------
----------------------------------
*/

trait Jobs
{
    public function jobs()
    {
        $jobs = [];
        if (!is_dir(CRON_LOGS_PATH)) {
            return $jobs;
        }

        $dir = opendir(CRON_LOGS_PATH);
        while ($file = readdir($dir)) {
            if ($file[0] == '.' || !str_ends_with($file, '.log')) {
                continue;
            }
            $id = substr($file, 0, -4);
            $job = $this->job($id);
            if ($job) {
                $jobs[] = $job;
            }
        }
        closedir($dir);

        usort($jobs, function ($a, $b) {
            return intval($b['started'] ?? 0) - intval($a['started'] ?? 0);
        });

        return $jobs;
    }

    public function job($id)
    {
        if (!$this->validJobId($id)) {
            return [];
        }

        $file = CRON_LOGS_PATH . $id . '.log';
        if (!is_file($file)) {
            return [];
        }

        $fp = fopen($file, 'r');
        if (!$fp) {
            return [];
        }

        $line = fgets($fp);
        fclose($fp);

        $job = $this->parseJobLine($line);
        if (!$job) {
            $json = CRON_LOGS_PATH . $id . '.json';
            if (!is_file($json)) {
                return [];
            }

            $job = json_decode(file_get_contents($json), true);
            if (!is_array($job)) {
                return [];
            }
        }

        $needsSave = false;
        if (($job['status'] ?? '') == 'running') {
            $jobFile = $this->jobFile($id);
            clearstatcache(true, $jobFile);
            if (!$jobFile || !is_file($jobFile)) {
                $job['status'] = 'cancelled';
                $needsSave = true;
            }
        }
        if (($job['status'] ?? '') != 'running' && ($job['status'] ?? '') != 'queued' && intval($job['finished'] ?? 0) <= intval($job['started'] ?? 0)) {
            $job['finished'] = $this->jobFinishedAt($file, intval($job['started'] ?? 0));
            $needsSave = true;
        }
        if ($needsSave) {
            $previousSidecar = $this->sidecar;
            $this->sidecar = $job;
            unset($this->sidecar['runtime'], $this->sidecar['size']);
            $this->writeJobHeader();
            $this->sidecar = $previousSidecar;
        }

        $ended = intval($job['finished'] ?? 0);
        if (($job['status'] ?? '') == 'running' || $ended <= intval($job['started'] ?? 0)) {
            $ended = time();
        }

        $job['size'] = is_file($file) ? filesize($file) : 0;
        $job['runtime'] = (($job['status'] ?? '') == 'queued') ? '0s' : (!empty($job['started']) ? relativeBetweenDates(intval($job['started']), $ended, true) : '');

        return $job;
    }

    public function jobFinishedAt($file, $started)
    {
        $lines = is_file($file) ? file($file, FILE_IGNORE_NEW_LINES) : [];
        if ($lines && count($lines) > 1) {
            $last = trim($lines[count($lines) - 1]);
            if (preg_match('/^(\d{4}-\d{2}-\d{2} \d{1,2}:\d{2}:\d{2})/', $last, $match)) {
                $time = strtotime($match[1]);
                if ($time && $time > $started) {
                    return $time;
                }
            }
        }

        return time();
    }

    public function logLines($id, $before, $limit)
    {
        $empty = ['lines' => [], 'before' => 0, 'done' => true];
        $job   = $this->job($id);
        if (!$job) {
            return $empty;
        }

        $file = CRON_LOGS_PATH . $id . '.log';
        if (!is_file($file)) {
            return $empty;
        }

        $all = file($file, FILE_IGNORE_NEW_LINES);
        if ($all && $this->parseJobLine($all[0])) {
            array_shift($all);
        }
        $total = count($all);
        $limit = intval($limit) ?: 50;
        $before = intval($before);
        if ($before < 0) {
            $start = max(0, $total - $limit);
        } else if ($before == 0) {
            return $empty;
        } else {
            $start = max(0, $before - $limit);
        }
        $end   = $before < 0 ? $total : $before;
        $slice = array_slice($all, $start, max(0, $end - $start));

        return [
            'lines'  => $slice,
            'before' => $start,
            'done'   => $start <= 0,
        ];
    }

    public function tailLog($id, $offset)
    {
        $empty = ['lines' => [], 'offset' => 0, 'status' => ''];
        if (!$this->validJobId($id)) {
            return $empty;
        }

        $file   = CRON_LOGS_PATH . $id . '.log';
        $offset = intval($offset);
        $lines  = [];
        clearstatcache(true, $file);
        if (is_file($file)) {
            $fp = fopen($file, 'r');
            if ($fp) {
                if ($offset == 0) {
                    $first = fgets($fp);
                    if ($first !== false && !$this->parseJobLine($first)) {
                        $lines[] = rtrim($first, "\r\n");
                    }
                    $offset = ftell($fp);
                } else {
                    fseek($fp, $offset);
                }
                while (($line = fgets($fp)) !== false) {
                    $lines[] = rtrim($line, "\r\n");
                }
                $offset = ftell($fp);
                fclose($fp);
            }
        }

        $job = $this->job($id);
        return [
            'lines'  => $lines,
            'offset' => $offset,
            'status' => $job['status'] ?? '',
        ];
    }

    public function logFile($id)
    {
        if (!$this->validJobId($id)) {
            return '';
        }

        $file = CRON_LOGS_PATH . $id . '.log';
        return is_file($file) ? $file : '';
    }

    public function validJobId($id)
    {
        return $id && preg_match('/^pull-\d{8}-\d{6}(-\d+)?$/', $id);
    }

    public function jobFile($id)
    {
        if (!$this->validJobId($id)) {
            return '';
        }

        return JOBS_PATH . $id;
    }

    public function writeJobFile($id)
    {
        $file = $this->jobFile($id);
        if (!$file) {
            return;
        }

        if (!is_dir(JOBS_PATH)) {
            mkdir(JOBS_PATH, 0755, true);
        }

        file_put_contents($file, $id);
    }

    public function removeJobFile($id)
    {
        $file = $this->jobFile($id);
        if ($file && is_file($file)) {
            unlink($file);
        }
    }

    public function cancelled()
    {
        $id = $this->sidecar['id'] ?? '';
        $file = $this->jobFile($id);
        if (!$file) {
            return true;
        }

        clearstatcache(true, $file);
        return !is_file($file);
    }

    public function stopIfCancelled()
    {
        static $checked = 0;
        if ($checked && time() === $checked) {
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

        $this->removeJobFile($id);
        if (($job['status'] ?? '') == 'running' || ($job['status'] ?? '') == 'queued' || !intval($job['finished'] ?? 0)) {
            $this->sidecar = $job;
            $this->sidecar['status'] = 'cancelled';
            $this->sidecar['finished'] = intval($job['finished'] ?? 0) ?: time();
            unset($this->sidecar['runtime'], $this->sidecar['size']);
            $this->writeJobHeader();
        }

        $lock = $this->lockFile($job);
        if ($lock && is_file($lock) && trim(file_get_contents($lock)) == $id) {
            $this->releaseLock($id);
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

        if (($job['status'] ?? '') == 'running') {
            $this->cancel($id);
        } else {
            $this->removeJobFile($id);
        }

        $log = CRON_LOGS_PATH . $id . '.log';
        if (is_file($log)) {
            unlink($log);
        }

        $json = CRON_LOGS_PATH . $id . '.json';
        if (is_file($json)) {
            unlink($json);
        }

        return ['error' => false, 'message' => translate('removed')];
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
        if ($type === '') {
            return '';
        }

        return CRON_LOGS_PATH . 'pull-' . $type . '.lock';
    }

    public function isLocked($job = [])
    {
        $lock = $this->lockFile($job);
        if (!$lock || !is_file($lock)) {
            return false;
        }

        $jobId = trim(file_get_contents($lock));
        $held  = $this->job($jobId);
        if ($held && ($held['status'] ?? '') == 'running') {
            return true;
        }

        return false;
    }

    public function createJob($mediaAppId, $userIds, $syncMode, $syncType, $libraries = [], $syncAccounts = 0, $trigger = MediaSyncTriggers::MANUAL, $scan = 0)
    {
        if (!is_dir(CRON_LOGS_PATH)) {
            mkdir(CRON_LOGS_PATH, 0755, true);
        }

        $id = 'pull-' . date('Ymd-His');
        if (is_file(CRON_LOGS_PATH . $id . '.log')) {
            $id .= '-' . getmypid();
        }

        $mediaAppId   = intval($mediaAppId);
        $syncMode     = intval($syncMode) ?: MediaSyncModes::PULL;
        $syncType     = intval($syncType) ?: MediaSyncTypes::USERS;
        $mediaAppName = '';
        $users        = [];
        $ids          = [];

        if ($mediaAppId) {
            $mediaApp = $this->database->getMediaApp($mediaAppId);
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
            } else {
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
            'started'        => time(),
            'finished'       => 0,
            'media_app_id'   => $mediaAppId,
            'media_app_name' => $mediaAppName,
            'sync_type'      => $syncType,
            'sync_mode'      => $syncMode,
            'sync_accounts'  => $syncAccounts ? 1 : 0,
            'trigger'        => intval($trigger) ?: MediaSyncTriggers::MANUAL,
            'libraries'      => $libraries ?: [],
            'user_ids'       => $ids,
            'users'          => $users,
            'scan'           => intval($scan) ?: MediaLibraryScans::LAST_SCAN,
        ];

        $this->sidecar = $job;
        $this->logfile = CRON_LOGS_PATH . $id . '.log';
        $this->writeJobHeader();

        return $job;
    }

    public function nextQueuedJob($type = '')
    {
        $queued = [];
        foreach ($this->jobs() as $job) {
            if (($job['status'] ?? '') != 'queued') {
                continue;
            }
            if ($type !== '' && $this->jobLockType($job) !== $type) {
                continue;
            }
            $queued[] = $job;
        }
        usort($queued, function ($a, $b) {
            return intval($a['started'] ?? 0) - intval($b['started'] ?? 0);
        });

        return $queued[0] ?? [];
    }

    public function startNextQueued($type = '')
    {
        if ($type === '') {
            return;
        }

        $next = $this->nextQueuedJob($type);
        if (!$next || !$this->acquireLock($next['id'])) {
            return;
        }

        $this->sidecar = $next;
        $this->sidecar['status']  = 'running';
        $this->sidecar['started'] = time();
        unset($this->sidecar['runtime'], $this->sidecar['size']);
        $this->logfile = CRON_LOGS_PATH . $next['id'] . '.log';
        $this->writeJobHeader();
        $this->writeJobFile($next['id']);
        $this->spawn($next['id']);
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

        if (!is_dir(CRON_LOGS_PATH)) {
            mkdir(CRON_LOGS_PATH, 0755, true);
        }

        $file   = CRON_LOGS_PATH . $this->sidecar['id'] . '.log';
        $json   = json_encode($this->sidecar, JSON_UNESCAPED_SLASHES);
        $header = str_pad($json, 8191, ' ') . "\n";
        $fp     = fopen($file, is_file($file) ? 'r+b' : 'wb');
        if (!$fp) {
            return;
        }

        flock($fp, LOCK_EX);
        fwrite($fp, $header);
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);
    }

    public function acquireLock($jobId)
    {
        if (!is_dir(CRON_LOGS_PATH)) {
            mkdir(CRON_LOGS_PATH, 0755, true);
        }

        $job  = (($this->sidecar['id'] ?? '') == $jobId) ? $this->sidecar : $this->job($jobId);
        $lock = $this->lockFile($job);
        if (!$lock) {
            return false;
        }
        if (is_file($lock)) {
            $current = trim(file_get_contents($lock));
            if ($current == $jobId) {
                return true;
            }
            $held = $this->job($current);
            if ($held && ($held['status'] ?? '') == 'running') {
                return false;
            }
        }

        file_put_contents($lock, $jobId);
        return true;
    }

    public function releaseLock($jobId = '')
    {
        $id   = $jobId ?: ($this->sidecar['id'] ?? '');
        $job  = ($id && ($this->sidecar['id'] ?? '') == $id) ? $this->sidecar : ($id ? $this->job($id) : $this->sidecar);
        $lock = $this->lockFile($job);
        if (!$lock || !is_file($lock)) {
            return;
        }
        if ($id && trim(file_get_contents($lock)) != $id) {
            return;
        }

        unlink($lock);
    }
}
