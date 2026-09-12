<?php

/*
----------------------------------
------  Created: 091126   ------
------  Austin Best       ------
----------------------------------
*/

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('X-Accel-Buffering: no');

if (!defined('RELATIVE_PATH')) {
    switch (true) {
        case file_exists('loader.php'):
            define('RELATIVE_PATH', './');
            break;
        case file_exists('../loader.php'):
            define('RELATIVE_PATH', '../');
            break;
        case file_exists('../../loader.php'):
            define('RELATIVE_PATH', '../../');
            break;
    }
}

require RELATIVE_PATH . 'loader.php';

while (ob_get_level()) {
    ob_end_flush();
}
ob_implicit_flush(true);

if (IS_GUEST) {
    echo 'data: ' . json_encode([]) . "\n\n";
    flush();
    exit;
}

$jobId = $_GET['log'] ?? '';
if (!$jobId || !$cron->validJobId($jobId)) {
    set_time_limit(0);
    echo ':' . str_repeat(' ', 4096) . "\n\n";
    flush();
    echo 'retry: 3000' . "\n";

    while (true) {
        if (connection_aborted()) {
            break;
        }

        $jobs    = $cron->jobs();
        $updated = 0;
        $running = false;
        $payload = ['jobs' => [], 'updated' => 0];
        foreach ($jobs as $job) {
            $updated = max($updated, intval($job['started'] ?? 0), intval($job['finished'] ?? 0));
            if (($job['status'] ?? '') == 'running' || ($job['status'] ?? '') == 'queued') {
                $running = true;
            }
            $payload['jobs'][] = syncJobSsePayload([
                'id'          => $job['id'] ?? '',
                'status'      => $job['status'] ?? '',
                'runtime'     => $job['runtime'] ?? '',
                'queued_wait' => $job['queued_wait'] ?? '',
                'started'     => intval($job['started'] ?? 0),
                'size'        => byteConversion($job['size'] ?? 0),
            ]);
        }
        $payload['updated'] = $updated;
        echo 'data: ' . json_encode($payload) . "\n\n";
        flush();

        if (!$running) {
            break;
        }

        sleep(1);
    }
    exit;
}

set_time_limit(0);
echo ':' . str_repeat(' ', 4096) . "\n\n";
flush();

echo 'retry: 3000' . "\n";
$offset = intval($_SERVER['HTTP_LAST_EVENT_ID'] ?? ($_GET['offset'] ?? 0));
$tick   = 0;

while (true) {
    if (connection_aborted()) {
        break;
    }

    $tail   = $cron->tailLog($jobId, $offset);
    $offset = intval($tail['offset']);
    $status = $tail['status'] ?? '';
    $job    = $cron->job($jobId);
    if ($tail['lines'] || $status != 'running' || !($tick % 4)) {
        $jobs = [];
        foreach ($cron->jobs() as $row) {
            $jobs[] = syncJobSsePayload([
                'id'          => $row['id'] ?? '',
                'status'      => $row['status'] ?? '',
                'runtime'     => $row['runtime'] ?? '',
                'queued_wait' => $row['queued_wait'] ?? '',
                'started'     => intval($row['started'] ?? 0),
                'size'        => byteConversion($row['size'] ?? 0),
            ]);
        }
        echo 'id: ' . $offset . "\n";
        echo 'data: ' . json_encode([
            'id'      => $jobId,
            'status'  => $status,
            'updated' => intval($job['finished'] ?? 0) ?: intval($job['started'] ?? 0),
            'offset'  => $offset,
            'lines'   => $tail['lines'],
            'runtime' => $job['runtime'] ?? '',
            'size'    => byteConversion($job['size'] ?? 0),
            'jobs'    => $jobs,
        ]) . "\n\n";
        flush();
    } else {
        echo ':' . "\n\n";
        flush();
    }
    $tick++;

    if ($status != 'running') {
        break;
    }

    usleep(250000);
}
