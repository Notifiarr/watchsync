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
    protected $logfile            = '';
    public    $currentJob         = [];
    protected $libraryImportIndex = null;

    public function __construct()
    {
        global $database;

        $this->database = $database;
    }

    public function start($mediaAppId, $userIds, $syncMode, $syncType, $libraries = [], $syncAccounts = 0, $scan = 0, $dryRun = 0, $extra = [])
    {
        if (intval($syncType) == MediaSyncTypes::HISTORY && !$this->hasLibraryData()) {
            return ['error' => true, 'message' => translate('historyNeedsLibraryData')];
        }

        $job = $this->createJob($mediaAppId, $userIds, $syncMode, $syncType, $libraries, $syncAccounts, MediaSyncTriggers::MANUAL, $scan, $dryRun, $extra);
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

        $this->currentJob = $this->job($jobId);
        if (!$this->currentJob) {
            logger(CRON_SYNC_LOG, 'sync ->');
            logger(CRON_SYNC_LOG, 'invalid job id');
            logger(CRON_SYNC_LOG, 'sync <-');
            return;
        }

        $this->logfile                = CRON_LOGS_PATH . $this->currentJob['id'] . '.log';
        $this->currentJob['log_file'] = $this->logfile;
        $log                          = $this->logfile;
        $persist                      = false;
        $type                         = '';
        logger($log, 'sync ->');
        loggerFlush($log);
        try {
            $syncType = intval($this->currentJob['sync_type'] ?? MediaSyncTypes::USERS);
            $typeName = 'users';
            if ($syncType == MediaSyncTypes::LIBRARY) {
                $typeName = 'library';
            } else if ($syncType == MediaSyncTypes::HISTORY) {
                $typeName = 'history';
            } else if ($syncType == MediaSyncTypes::LIBRARIES) {
                $typeName = 'libraries';
            } else if (!empty($this->currentJob['sync_accounts'])) {
                $typeName = 'parity';
            }
            logger($log, 'type=' . $typeName);
            $triggerName = 'manual';
            if (intval($this->currentJob['trigger'] ?? 0) == MediaSyncTriggers::AUTOMATIC) {
                $triggerName = 'automatic';
            } else if (intval($this->currentJob['trigger'] ?? 0) == MediaSyncTriggers::WEBHOOK) {
                $triggerName = 'webhook';
            }
            logger($log, 'trigger=' . $triggerName);
            logger($log, 'dry_run=' . (!empty($this->currentJob['dry_run']) ? '1' : '0'));
            $users = [];
            foreach ($this->currentJob['users'] ?? [] as $user) {
                $name = trim(strval($user));
                if ($name != '') {
                    $users[] = $name;
                }
            }
            if ($users) {
                logger($log, 'users=' . implode(',', $users));
            }
            $historyLibraries = [];
            foreach ($this->currentJob['history_libraries'] ?? [] as $library) {
                $key = strval($library['key'] ?? '');
                if ($key != '') {
                    $historyLibraries[] = $key;
                }
            }
            if ($historyLibraries) {
                logger($log, 'libraries=' . implode(',', $historyLibraries));
            }

            if (!$this->acquireLock($this->currentJob['id'])) {
                $running = $this->runningJob($this->jobLockType($this->currentJob));
                if (($running['id'] ?? '') == ($this->currentJob['id'] ?? '')) {
                    logger($log, 'lock active same job');
                    return;
                }
                if (($this->currentJob['status'] ?? '') == 'running') {
                    $this->currentJob['status']  = 'queued';
                    $this->currentJob['started'] = 0;
                    $this->writeJobHeader();
                }
                logger($log, 'lock active');
                return;
            }
            if (($this->currentJob['status'] ?? '') != 'running' || empty($this->currentJob['started'])) {
                $this->currentJob['status']  = 'running';
                $this->currentJob['started'] = time();
            }
            $this->writeJobHeader();

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
                } else if (!empty($this->currentJob['sync_accounts'])) {
                    $this->syncAccounts();
                } else if (!empty($this->currentJob['webhook_item'])) {
                    $this->syncWebhookItem();
                } else {
                    if ($syncType != MediaSyncTypes::HISTORY) {
                        $this->pullMediaLibrary();
                    }
                    $syncMode = intval($this->currentJob['sync_mode']);
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
                $this->currentJob['status'] = 'cancelled';
            } else if ($error) {
                $this->currentJob['status'] = 'error';
            } else {
                $this->currentJob['status'] = 'finished';
            }
            $this->currentJob['finished'] = time();
            $persist                      = true;
            if (($this->currentJob['status'] ?? '') == 'finished' && $this->shouldUpdateSyncSchedule($this->currentJob)) {
                $this->setSyncLastFinished($this->jobLockType($this->currentJob), intval($this->currentJob['finished']));
            }
            $notifyTrigger = intval($this->currentJob['trigger'] ?? 0) == MediaSyncTriggers::WEBHOOK ? 'syncWebhook' : 'syncOverview';
            $this->notifySync($notifyTrigger);
            $type = $this->jobLockType($this->currentJob);
            if (($this->currentJob['status'] ?? '') == 'finished' && intval($this->currentJob['sync_type'] ?? 0) == MediaSyncTypes::LIBRARY) {
                $this->queueHistoryAfterLibraryFinish();
            }
        } finally {
            logger($log, 'sync <-');
            loggerFlush($log);
            if (!empty($this->currentJob['dry_run_summary']) && is_array($this->currentJob['dry_run_summary'])) {
                loggerBlock($log, $this->currentJob['dry_run_summary']);
                unset($this->currentJob['dry_run_summary']);
            } else if ($persist) {
                $summary = $this->buildSyncLogSummary();
                if ($summary) {
                    loggerBlock($log, $summary);
                }
            }
            if ($persist) {
                $this->releaseLock();
            }
            if ($type != '') {
                $next = $this->nextQueuedJob($type);
                if ($next) {
                    if ($type != 'history' || !empty($this->canStartHistoryJob($next)['ok'])) {
                        $this->startQueuedJob($next);
                    }
                }

                if ($type == 'library') {
                    $history = $this->nextQueuedJob('history');
                    if ($history && !empty($this->canStartHistoryJob($history)['ok'])) {
                        $this->startQueuedJob($history);
                    }
                }
            }
            loggerFlush($log);
            if ($persist) {
                $this->writeJobHeader();
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
