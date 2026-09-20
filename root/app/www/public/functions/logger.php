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

    $log = date('Y-m-d g:i:s') . ' :: ' . (is_array($msg) || is_object($msg) ? json_encode($msg) : $msg) . "\n";

    $GLOBALS['loggerQueue'][$logfile][] = $log;
    if (empty($GLOBALS['loggerFlushAt'][$logfile])) {
        $GLOBALS['loggerFlushAt'][$logfile] = time();
    }

    if (time() - $GLOBALS['loggerFlushAt'][$logfile] >= 1) {
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

function loggerBlock($logfile, $lines)
{
    if (!$logfile) {
        return;
    }

    loggerFlush($logfile);

    $logDir = dirname($logfile);
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }

    if (!is_array($lines)) {
        $lines = explode("\n", strval($lines));
    }

    $body = '';
    foreach ($lines as $line) {
        $body .= rtrim(strval($line), "\r\n") . "\n";
    }

    $fp = fopen($logfile, 'ab');
    if (!$fp) {
        return;
    }

    flock($fp, LOCK_EX);
    fwrite($fp, $body);
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
}

function asciiTable($headers, $rows)
{
    $headers = array_values($headers);
    $widths  = [];
    foreach ($headers as $i => $header) {
        $widths[$i] = strlen(strval($header));
    }
    $normalized = [];
    foreach ($rows as $row) {
        $row = array_values($row);
        foreach ($headers as $i => $header) {
            $cell       = strval($row[$i] ?? '');
            $row[$i]    = $cell;
            $widths[$i] = max($widths[$i], strlen($cell));
        }
        $normalized[] = $row;
    }

    $line = '+';
    foreach ($widths as $width) {
        $line .= str_repeat('-', $width + 2) . '+';
    }

    $format = function ($cols) use ($widths) {
        $out = '|';
        foreach ($widths as $i => $width) {
            $out .= ' ' . str_pad(strval($cols[$i] ?? ''), $width, ' ', STR_PAD_RIGHT) . ' |';
        }

        return $out;
    };

    $table   = [];
    $table[] = $line;
    $table[] = $format($headers);
    $table[] = $line;
    if (!$normalized) {
        $empty    = array_fill(0, count($headers), '');
        $empty[0] = '(none)';
        $table[]  = $format($empty);
    } else {
        foreach ($normalized as $row) {
            $table[] = $format($row);
        }
    }
    $table[] = $line;

    return $table;
}
