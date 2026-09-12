<?php

/*
----------------------------------
------  Created: 091326   ------
------  Austin Best       ------
----------------------------------
*/

trait Dispatcher
{
    protected $dispatchOpened = false;

    public function dispatch()
    {
        $lock = $this->setLockFile('dispatcher');
        if (!$lock) {
            return;
        }

        try {
            $this->queueAutomaticSync();
            $this->processQueue();
            $this->dispatchBackup();
            $this->dispatchHousekeeper();
        } finally {
            $this->removeLockFile($lock);
            $this->dispatchLogClose();
        }
    }

    public function dispatchLog($msg)
    {
        if (!$this->dispatchOpened) {
            logger(CRON_DISPATCHER_LOG, 'dispatcher ->');
            $this->dispatchOpened = true;
        }
        logger(CRON_DISPATCHER_LOG, $msg);
    }

    public function dispatchLogClose()
    {
        if (!$this->dispatchOpened) {
            return;
        }

        logger(CRON_DISPATCHER_LOG, 'dispatcher <-');
        $this->dispatchOpened = false;
    }

    public function dispatchBackup()
    {
        $queued = $this->database->getSetting('backupQueued');
        if (!$queued && intval(date('i')) % 10 != 0) {
            return 'wait';
        }

        $time = normalizeBackupTime($this->database->getSetting('backupTime') ?: '03:00');
        if ($queued) {
            $result = $this->hasLockFile('backup') ? 'running' : 'manual';
        } else if (date('H:i') != $time) {
            $result = 'skip';
        } else if ($this->hasLockFile('backup')) {
            $result = 'running';
        } else if ($this->automaticBackupExists()) {
            $result = 'exists';
        } else {
            $result = 'automatic';
        }

        $this->dispatchLog('backup=' . $result);
        if ($result == 'manual' || $result == 'automatic') {
            $this->spawnScript('backup.php', '', CRON_DISPATCHER_LOG);
        }

        return $result;
    }

    public function dispatchHousekeeper()
    {
        if (intval(date('i')) % 10 != 0) {
            return 'wait';
        }
        $result = $this->hasLockFile('housekeeper') ? 'running' : 'started';
        $this->dispatchLog('housekeeper=' . $result);
        if ($result == 'started') {
            $this->spawnScript('housekeeper.php', '', CRON_DISPATCHER_LOG);
        }

        return $result;
    }

    public function runBackup($type = '')
    {
        logger(CRON_BACKUP_LOG, 'backup ->');
        $lock = false;
        try {
            if ($type == 'manual') {
                logger(CRON_BACKUP_LOG, 'type=manual');
                if (!is_dir(BACKUP_PATH)) {
                    mkdir(BACKUP_PATH, 0755, true);
                }
                $started = time();
                $path    = $this->database->mysqli_backup('manual');
                $success = (bool) $path;
                logger(CRON_BACKUP_LOG, 'result=' . ($success ? 'success' : 'failed') . ($success ? ' path=' . $path : ''));
                $this->notifyBackup('manual', $success, $success ? $path : '', $started);
                return $success;
            }

            $lock = $this->setLockFile('backup');
            if (!$lock) {
                logger(CRON_BACKUP_LOG, 'already running');
                return false;
            }

            $queued = $this->database->getSetting('backupQueued');
            $time   = normalizeBackupTime($this->database->getSetting('backupTime') ?: '03:00');
            $kind   = $queued ? 'manual' : 'automatic';
            logger(CRON_BACKUP_LOG, 'type=' . $kind);
            if (!$queued && date('H:i') != $time) {
                logger(CRON_BACKUP_LOG, 'skip');
                return false;
            }
            if (!$queued && $this->automaticBackupExists()) {
                logger(CRON_BACKUP_LOG, 'already exists');
                return false;
            }

            if (!is_dir(BACKUP_PATH)) {
                mkdir(BACKUP_PATH, 0755, true);
            }

            $started = time();
            $path    = $this->database->mysqli_backup($kind);
            $success = (bool) $path;
            if ($success && $queued) {
                $this->database->setSetting('backupQueued', '');
            }
            logger(CRON_BACKUP_LOG, 'result=' . ($success ? 'success' : 'failed') . ($success ? ' path=' . $path : ''));
            $this->notifyBackup($kind, $success, $success ? $path : '', $started);
            return $success;
        } finally {
            $this->removeLockFile($lock);
            logger(CRON_BACKUP_LOG, 'backup <-');
        }
    }

    public function runHousekeeper()
    {
        global $shell;

        logger(CRON_HOUSEKEEPER_LOG, 'housekeeper ->');
        $lock = $this->setLockFile('housekeeper');
        try {
            if (!$lock) {
                logger(CRON_HOUSEKEEPER_LOG, 'already running');
                return;
            }

            $dirs = [
                LOGS_PATH . 'crons',
                LOGS_PATH . 'system',
                JOBS_PATH,
                BACKUP_PATH,
            ];
            foreach ($dirs as $dir) {
                if (!is_dir($dir)) {
                    mkdir($dir, 0755, true);
                }
            }

            $keep    = intval($this->database->getSetting('backupKeep') ?: 7);
            $removed = 0;
            $dir     = opendir(BACKUP_PATH);
            while ($backup = readdir($dir)) {
                if (!is_dir(BACKUP_PATH . $backup)) {
                    logger(CRON_HOUSEKEEPER_LOG, 'removing=' . BACKUP_PATH . $backup);
                    $shell->exec('rm -rf ' . BACKUP_PATH . $backup);
                    $removed++;
                } else if ($backup[0] != '.') {
                    $daysBetween = daysBetweenDates($backup, date('Ymd'));
                    if ($daysBetween >= $keep) {
                        logger(CRON_HOUSEKEEPER_LOG, 'removing=' . BACKUP_PATH . $backup);
                        $shell->exec('rm -rf ' . BACKUP_PATH . $backup);
                        $removed++;
                    }
                }
            }
            closedir($dir);
            if ($removed) {
                logger(CRON_HOUSEKEEPER_LOG, 'backups removed=' . $removed);
            }
            $this->purgeDeletedUsers();
        } finally {
            $this->removeLockFile($lock);
            logger(CRON_HOUSEKEEPER_LOG, 'housekeeper <-');
        }
    }

    public function purgeDeletedUsers()
    {
        global $mediaApps;

        $mediaApps->purgeDeletedUsers();
    }

    public function spawnScript($script, $arg = '', $log = '')
    {
        global $shell;

        $log    = $log ?: ($this->logfile ?: CRON_DISPATCHER_LOG);
        $script = basename($script);
        $file   = str_replace('\\', '/', ABSOLUTE_PATH) . 'crons/' . $script;
        if (!str_ends_with($script, '.php') || !is_file($file)) {
            $this->cronLine($log, $script . ' not found');
            return;
        }

        $cmd = '/usr/bin/php ' . $file . ($arg != '' ? ' ' . $arg : '');
        $this->cronLine($log, 'spawn=' . $script . ($arg != '' ? ' ' . $arg : ''));
        $pid = $shell->background($cmd);
        $this->cronLine($log, 'pid=' . ($pid ?: '0'));
    }

    public function cronLine($log, $msg)
    {
        if ($log == CRON_DISPATCHER_LOG) {
            $this->dispatchLog($msg);
            return;
        }

        logger($log, $msg);
    }

    public function automaticBackupExists()
    {
        $dateDir = BACKUP_PATH . date('Ymd');
        if (!is_dir($dateDir)) {
            return false;
        }

        $dir = opendir($dateDir);
        while ($run = readdir($dir)) {
            if (str_ends_with($run, '_automatic')) {
                closedir($dir);
                return true;
            }
        }
        closedir($dir);

        return false;
    }

    public function getLockFile($name)
    {
        return CRON_LOGS_PATH . $name . '.lock';
    }

    public function setLockFile($name)
    {
        if (!is_dir(CRON_LOGS_PATH)) {
            mkdir(CRON_LOGS_PATH, 0755, true);
        }

        $fp = fopen($this->getLockFile($name), 'c');
        if (!$fp || !flock($fp, LOCK_EX | LOCK_NB)) {
            if ($fp) {
                fclose($fp);
            }
            return false;
        }

        return $fp;
    }

    public function removeLockFile($fp)
    {
        if (!$fp) {
            return;
        }

        flock($fp, LOCK_UN);
        fclose($fp);
    }

    public function hasLockFile($name)
    {
        $fp = fopen($this->getLockFile($name), 'c');
        if (!$fp) {
            return false;
        }

        $locked = !flock($fp, LOCK_EX | LOCK_NB);
        if (!$locked) {
            flock($fp, LOCK_UN);
        }
        fclose($fp);

        return $locked;
    }
}
