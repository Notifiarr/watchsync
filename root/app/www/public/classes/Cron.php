<?php

/*
----------------------------------
------  Created: 091126   ------
------  Austin Best       ------
----------------------------------
*/

loadClassTraits(RELATIVE_PATH . 'classes/traits/Cron/');

class Cron
{
    use Jobs;
    use Library;
    use Accounts;
    use History;

    protected $database;
    protected $logfile  = '';
    protected $sidecar  = [];

    public function __construct()
    {
        global $database;

        $this->database = $database;
    }

    public function start($mediaAppId, $userIds, $syncMode, $syncType, $libraries = [], $syncAccounts = 0, $scan = 0)
    {
        $job = $this->createJob($mediaAppId, $userIds, $syncMode, $syncType, $libraries, $syncAccounts, MediaSyncTriggers::MANUAL, $scan);
        if ($this->acquireLock($job['id'])) {
            $this->sidecar['status']  = 'running';
            $this->sidecar['started'] = time();
            $this->writeJobHeader();
            $this->writeJobFile($job['id']);

            if (session_status() == PHP_SESSION_ACTIVE) {
                session_write_close();
            }

            $this->spawn($job['id']);

            return ['error' => false, 'message' => translate('syncStarted'), 'id' => $job['id'], 'status' => 'running'];
        }

        return ['error' => false, 'message' => translate('syncQueued'), 'id' => $job['id'], 'status' => 'queued'];
    }

    public function run($jobId = '')
    {
        if ($jobId) {
            $this->sidecar = $this->job($jobId);
            if (!$this->sidecar) {
                return;
            }
        } else {
            $this->sidecar = $this->createJob(0, [], MediaSyncModes::PULL, MediaSyncTypes::USERS, [], 0, MediaSyncTriggers::AUTOMATIC);
        }

        $this->logfile = CRON_LOGS_PATH . $this->sidecar['id'] . '.log';
        if (($this->sidecar['status'] ?? '') == 'queued') {
            $this->sidecar['status']  = 'running';
            $this->sidecar['started'] = time();
            $this->writeJobHeader();
        }
        if (!$this->acquireLock($this->sidecar['id'])) {
            $this->sidecar['status'] = 'queued';
            $this->writeJobHeader();
            $this->removeJobFile($this->sidecar['id']);
            logger($this->logfile, 'lock active, queued');
            return;
        }
        $this->writeJobFile($this->sidecar['id']);

        logger($this->logfile, 'run ->');
        $error     = false;
        $cancelled = $this->cancelled();
        if ($cancelled) {
            logger($this->logfile, 'cancelled');
        }

        try {
            if ($cancelled) {
                throw new Exception('cancelled');
            }
            $syncType = intval($this->sidecar['sync_type'] ?? MediaSyncTypes::USERS);
            $typeName = 'users';
            if ($syncType == MediaSyncTypes::LIBRARY) {
                $typeName = 'library';
            } else if ($syncType == MediaSyncTypes::HISTORY) {
                $typeName = 'history';
            } else if ($syncType == MediaSyncTypes::LIBRARIES) {
                $typeName = 'libraries';
            }
            logger($this->logfile, 'sync type ' . $typeName);
            if ($syncType == MediaSyncTypes::LIBRARY) {
                $this->pullMediaLibrary();
            } else if ($syncType == MediaSyncTypes::LIBRARIES) {
                $this->syncLibraries();
            } else if (!empty($this->sidecar['sync_accounts'])) {
                $this->syncAccounts();
            } else {
                if ($syncType != MediaSyncTypes::HISTORY) {
                    $this->pullMediaLibrary();
                }
                $syncMode = intval($this->sidecar['sync_mode']);
                if ($syncMode == MediaSyncModes::PULL || $syncMode == MediaSyncModes::BOTH) {
                    $this->pullWatch();
                }
                if ($syncMode == MediaSyncModes::PUSH || $syncMode == MediaSyncModes::BOTH) {
                    $this->pushWatch();
                }
            }
        } catch (Exception $e) {
            if ($e->getMessage() == 'cancelled') {
                $cancelled = true;
                logger($this->logfile, 'cancelled');
            } else {
                $error = true;
                logger($this->logfile, $e->getMessage());
            }
        }

        if ($cancelled || $this->cancelled()) {
            $this->sidecar['status'] = 'cancelled';
        } else if ($error) {
            $this->sidecar['status'] = 'error';
        } else {
            $this->sidecar['status'] = 'finished';
        }
        $this->sidecar['finished'] = time();
        $this->writeJobHeader();
        $this->removeJobFile($this->sidecar['id'] ?? '');
        logger($this->logfile, 'run <-');
        $type = $this->jobLockType($this->sidecar);
        $this->releaseLock();
        $this->startNextQueued($type);
    }

    public function log($msg)
    {
        logger($this->logfile, $msg);
    }

    public function spawn($jobId)
    {
        global $shell;

        if (!$this->validJobId($jobId)) {
            logger($this->logfile, 'invalid job id');
            return;
        }

        $script = str_replace('\\', '/', dirname(__DIR__)) . '/crons/cron.pull.php';
        if (!is_file($script)) {
            logger($this->logfile, 'cron.pull.php not found');
            return;
        }

        $cmd = '/usr/bin/php ' . $script . ' ' . $jobId;
        logger($this->logfile, 'spawn ' . $cmd);
        $pid = $shell->background($cmd);
        logger($this->logfile, 'spawn pid ' . ($pid ?: '0'));
    }
}
