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
    use Notify;
    use Dispatcher;

    protected $database;
    protected $logfile  = '';
    protected $sidecar  = [];

    public function __construct()
    {
        global $database;

        $this->database = $database;
    }

    public function start($mediaAppId, $userIds, $syncMode, $syncType, $libraries = [], $syncAccounts = 0, $scan = 0, $dryRun = 0)
    {
        if (intval($syncType) == MediaSyncTypes::HISTORY && !$this->hasLibraryData()) {
            return ['error' => true, 'message' => translate('historyNeedsLibraryData')];
        }

        $job = $this->createJob($mediaAppId, $userIds, $syncMode, $syncType, $libraries, $syncAccounts, MediaSyncTriggers::MANUAL, $scan, $dryRun);
        if (!$job) {
            return ['error' => true, 'message' => translate('couldNotQueueSync')];
        }

        return ['error' => false, 'message' => !empty($dryRun) ? translate('dryRunQueued') : translate('syncQueued'), 'id' => $job['id'], 'status' => 'queued'];
    }

    public function run($jobId = '')
    {
        if (!$jobId) {
            logger(CRON_SYNC_LOG, 'sync ->');
            logger(CRON_SYNC_LOG, 'missing job id');
            logger(CRON_SYNC_LOG, 'sync <-');
            return;
        }

        $this->sidecar = $this->job($jobId);
        if (!$this->sidecar) {
            logger(CRON_SYNC_LOG, 'sync ->');
            logger(CRON_SYNC_LOG, 'invalid job id');
            logger(CRON_SYNC_LOG, 'sync <-');
            return;
        }

        $this->logfile             = CRON_LOGS_PATH . $this->sidecar['id'] . '.log';
        $this->sidecar['log_file'] = $this->logfile;
        $log                       = $this->logfile;
        logger($log, 'sync ->');
        loggerFlush($log);
        try {
            $syncType = intval($this->sidecar['sync_type'] ?? MediaSyncTypes::USERS);
            $typeName = 'users';
            if ($syncType == MediaSyncTypes::LIBRARY) {
                $typeName = 'library';
            } else if ($syncType == MediaSyncTypes::HISTORY) {
                $typeName = 'history';
            } else if ($syncType == MediaSyncTypes::LIBRARIES) {
                $typeName = 'libraries';
            } else if (!empty($this->sidecar['sync_accounts'])) {
                $typeName = 'parity';
            }
            logger($log, 'type=' . $typeName);
            logger($log, 'trigger=' . (intval($this->sidecar['trigger'] ?? 0) == MediaSyncTriggers::AUTOMATIC ? 'automatic' : 'manual'));
            logger($log, 'dry_run=' . (!empty($this->sidecar['dry_run']) ? '1' : '0'));

            if (intval($this->sidecar['trigger'] ?? 0) == MediaSyncTriggers::AUTOMATIC) {
                $this->cancel($jobId);
                logger($log, 'automatic blocked');
                return;
            }

            if (!$this->acquireLock($this->sidecar['id'])) {
                if (($this->sidecar['status'] ?? '') == 'running') {
                    $this->sidecar['status']  = 'queued';
                    $this->sidecar['started'] = 0;
                    $this->writeJobHeader();
                }
                logger($log, 'lock active');
                return;
            }
            if (($this->sidecar['status'] ?? '') != 'running' || empty($this->sidecar['started'])) {
                $this->sidecar['status']  = 'running';
                $this->sidecar['started'] = time();
            }
            $this->writeJobHeader();
            $this->notifySync('syncStart');

            $error     = false;
            $cancelled = $this->cancelled();
            if ($cancelled) {
                logger($log, 'cancelled');
            }

            try {
                if ($cancelled) {
                    throw new Exception('cancelled');
                }
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
                    $this->syncWatch($syncMode);
                }
            } catch (Exception $e) {
                if ($e->getMessage() == 'cancelled') {
                    $cancelled = true;
                    logger($log, 'cancelled');
                } else {
                    $error = true;
                    logger($log, $e->getMessage());
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
            $this->notifySync('syncEnd');
            $type = $this->jobLockType($this->sidecar);
            $this->releaseLock();

            $next = $this->nextQueuedJob($type);
            if ($next && intval($next['trigger'] ?? 0) != MediaSyncTriggers::AUTOMATIC) {
                if ($type != 'history' || !empty($this->canStartHistoryJob($next)['ok'])) {
                    $this->startQueuedJob($next);
                }
            }

            if ($type == 'library') {
                $history = $this->nextQueuedJob('history');
                if ($history && intval($history['trigger'] ?? 0) != MediaSyncTriggers::AUTOMATIC && !empty($this->canStartHistoryJob($history)['ok'])) {
                    $this->startQueuedJob($history);
                }
            }
        } finally {
            logger($log, 'sync <-');
            loggerFlush($log);
            if (!empty($this->sidecar['dry_run_summary']) && is_array($this->sidecar['dry_run_summary'])) {
                loggerBlock($log, $this->sidecar['dry_run_summary']);
                unset($this->sidecar['dry_run_summary']);
            }
        }
    }

    public function log($msg)
    {
        logger($this->logfile, $msg);
    }

    public function spawn($jobId)
    {
        if (!$this->validJobId($jobId)) {
            logger($this->logfile ?: CRON_SYNC_LOG, 'invalid job id');
            return;
        }

        $this->spawnScript('sync.php', $jobId, $this->logfile ?: CRON_SYNC_LOG);
    }
}
