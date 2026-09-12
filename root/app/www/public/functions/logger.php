<?php

/*
----------------------------------
------  Created: 091126   ------
------  Austin Best       ------
----------------------------------
*/

function logger($logfile, $msg)
{
    if (!$logfile) {
        return;
    }

    static $registered = false;
    if (!$registered) {
        register_shutdown_function('loggerFlush');
        $registered = true;
    }

    // $backtrace = debug_backtrace();
    // $line      = $backtrace[0]['line'];
    // $file      = $backtrace[0]['file'];
    // $fileParts = explode(DIRECTORY_SEPARATOR, $file);
    // $fileName  = $fileParts[count($fileParts) - 1];
    // $folder    = $fileParts[count($fileParts) - 2] ?? '';
    // $file      = $folder ? $folder . '/' . $fileName : $fileName;
    $log = date('Y-m-d g:i:s') . ' :: ' . (is_array($msg) || is_object($msg) ? json_encode($msg) : $msg) . "\n";

    $GLOBALS['loggerQueue'][$logfile][] = $log;
    if (empty($GLOBALS['loggerFlushAt'][$logfile])) {
        $GLOBALS['loggerFlushAt'][$logfile] = time();
    }
    if (time() - $GLOBALS['loggerFlushAt'][$logfile] >= 2) {
        loggerFlush($logfile);
    }
}

function loggerFlush($logfile = '')
{
    $queues = $GLOBALS['loggerQueue'] ?? [];
    $files  = $logfile ? [$logfile] : array_keys($queues);
    foreach ($files as $file) {
        $lines = $queues[$file] ?? [];
        if (!$lines) {
            continue;
        }

        $logDir = dirname($file);
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }

        $fp = fopen($file, 'ab');
        if (!$fp) {
            continue;
        }

        flock($fp, LOCK_EX);
        fwrite($fp, implode('', $lines));
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);

        $GLOBALS['loggerQueue'][$file]   = [];
        $GLOBALS['loggerFlushAt'][$file] = time();
    }
}
