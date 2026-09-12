<?php

/*
----------------------------------
------  Created: 091226   ------
------  Austin Best       ------
----------------------------------
*/

trait SyncHistory
{
    public function ensureSyncHistoryTable()
    {
        if ($this->mysqliTableExists(SYNC_HISTORY_TABLE)) {
            return;
        }

        $this->query("CREATE TABLE IF NOT EXISTS `" . SYNC_HISTORY_TABLE . "` (
            `id` int NOT NULL AUTO_INCREMENT,
            `job_id` varchar(64) NOT NULL,
            `status` varchar(20) NOT NULL,
            `lock_type` varchar(20) NOT NULL,
            `queued` int NOT NULL DEFAULT 0,
            `started` int NOT NULL DEFAULT 0,
            `finished` int NOT NULL DEFAULT 0,
            `log_file` varchar(1024) NOT NULL DEFAULT '',
            `payload` text NOT NULL,
            `results` text NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `job_id` (`job_id`),
            KEY `status_lock` (`status`, `lock_type`),
            KEY `queued` (`queued`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    public function getSyncHistoryJobs()
    {
        $this->ensureSyncHistoryTable();
        $jobs = [];

        $sql = "SELECT id, job_id, status, lock_type, queued, started, finished, log_file, payload, results
                FROM " . SYNC_HISTORY_TABLE . "
                ORDER BY queued DESC, id DESC";
        $res = $this->query($sql);
        while ($row = $this->fetchAssoc($res)) {
            $jobs[] = $row;
        }

        return $jobs;
    }

    public function getSyncHistoryJob($jobId)
    {
        $this->ensureSyncHistoryTable();
        $sql = "SELECT id, job_id, status, lock_type, queued, started, finished, log_file, payload, results
                FROM " . SYNC_HISTORY_TABLE . "
                WHERE job_id = '" . $this->prepare($jobId) . "'";
        $res = $this->query($sql);
        $row = $this->fetchAssoc($res);

        return $row ?: [];
    }

    public function getRunningSyncHistoryJob($lockType)
    {
        $sql = "SELECT id, job_id, status, lock_type, queued, started, finished, log_file, payload, results
                FROM " . SYNC_HISTORY_TABLE . "
                WHERE status = 'running'
                AND lock_type = '" . $this->prepare($lockType) . "'
                ORDER BY started ASC, id ASC
                LIMIT 1";
        $res = $this->query($sql);
        $row = $this->fetchAssoc($res);

        return $row ?: [];
    }

    public function getNextQueuedSyncHistoryJob($lockType)
    {
        $sql = "SELECT id, job_id, status, lock_type, queued, started, finished, log_file, payload, results
                FROM " . SYNC_HISTORY_TABLE . "
                WHERE status = 'queued'
                AND lock_type = '" . $this->prepare($lockType) . "'
                ORDER BY queued ASC, id ASC
                LIMIT 1";
        $res = $this->query($sql);
        $row = $this->fetchAssoc($res);

        return $row ?: [];
    }

    public function getLastSyncHistoryJob($lockTypes)
    {
        $types = [];
        foreach ($lockTypes as $lockType) {
            if ($lockType != '') {
                $types[] = "'" . $this->prepare($lockType) . "'";
            }
        }
        if (!$types) {
            return [];
        }

        $sql = "SELECT id, job_id, status, lock_type, queued, started, finished, log_file, payload, results
                FROM " . SYNC_HISTORY_TABLE . "
                WHERE lock_type IN (" . implode(',', $types) . ")
                ORDER BY queued DESC, id DESC
                LIMIT 1";
        $res = $this->query($sql);
        $row = $this->fetchAssoc($res);

        return $row ?: [];
    }

    public function addSyncHistory($jobId, $status, $lockType, $queued, $started, $finished, $logFile, $payload, $results)
    {
        $this->ensureSyncHistoryTable();
        $sql = "INSERT INTO " . SYNC_HISTORY_TABLE . "
                (`job_id`, `status`, `lock_type`, `queued`, `started`, `finished`, `log_file`, `payload`, `results`)
                VALUES
                ('" . $this->prepare($jobId) . "', '" . $this->prepare($status) . "', '" . $this->prepare($lockType) . "', " . intval($queued) . ", " . intval($started) . ", " . intval($finished) . ", '" . $this->prepare($logFile) . "', '" . $this->prepare($this->syncHistoryJson($payload)) . "', '" . $this->prepare($this->syncHistoryJson($results)) . "')";

        return $this->query($sql);
    }

    public function updateSyncHistory($jobId, $status, $started, $finished, $logFile, $payload, $results, $queued = null)
    {
        $sql = "UPDATE " . SYNC_HISTORY_TABLE . "
                SET status = '" . $this->prepare($status) . "',
                    started = " . intval($started) . ",
                    finished = " . intval($finished) . ",
                    log_file = '" . $this->prepare($logFile) . "',
                    payload = '" . $this->prepare($this->syncHistoryJson($payload)) . "',
                    results = '" . $this->prepare($this->syncHistoryJson($results)) . "'";
        if ($queued !== null) {
            $sql .= ",
                    queued = " . intval($queued);
        }
        $sql .= "
                WHERE job_id = '" . $this->prepare($jobId) . "'";

        return $this->query($sql);
    }

    public function deleteSyncHistory($jobId)
    {
        $sql = "DELETE FROM " . SYNC_HISTORY_TABLE . "
                WHERE job_id = '" . $this->prepare($jobId) . "'";

        return $this->query($sql);
    }

    public function deleteAllSyncHistory()
    {
        $this->ensureSyncHistoryTable();

        return $this->query("DELETE FROM " . SYNC_HISTORY_TABLE);
    }

    public function syncHistoryJson($value)
    {
        if (is_string($value)) {
            return $value;
        }

        return json_encode($value ?: new stdClass(), JSON_UNESCAPED_SLASHES);
    }
}
