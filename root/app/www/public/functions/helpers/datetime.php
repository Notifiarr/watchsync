<?php

/*
----------------------------------
------  Created: 091126   ------
------  Austin Best       ------
----------------------------------
*/

function relativeBetweenDates($startDatetime, $endDatetime, $full = false)
{
    if (!$endDatetime) {
        $endDatetime = time();
    }

    if (is_numeric($startDatetime)) {
        $startDatetime = date('Y-m-d H:i:s', $startDatetime);
    }
    if (is_numeric($endDatetime)) {
        $endDatetime = date('Y-m-d H:i:s', $endDatetime);
    }

    if (substr($startDatetime, -1) == 'Z') {
        $explodeDatetime = explode('.', $startDatetime);
        $startDatetime   = reset($explodeDatetime) . '+00:00';
    }

    if (str_contains($startDatetime, '.')) {
        $explodeDatetime = explode('.', $startDatetime);
        $start           = reset($explodeDatetime);
        $offset          = substr($startDatetime, -6);
        $startDatetime   = $start . $offset;
    }
    $start = new DateTime($startDatetime);

    if ($endDatetime) {
        if (substr($endDatetime, -1) == 'Z') {
            $explodeDatetime = explode('.', $endDatetime);
            $endDatetime     = reset($explodeDatetime) . '+00:00';
        }

        if (str_contains($endDatetime, '.')) {
            $explodeDatetime = explode('.', $endDatetime);
            $start           = reset($explodeDatetime);
            $offset          = substr($endDatetime, -6);
            $endDatetime     = $start . $offset;
        }

        $end = new DateTime($endDatetime);
    } else {
        $end = new DateTime();
    }

    $interval   = $end->diff($start);
    $diff       = [
        'y' => $interval->y,
        'm' => $interval->m,
        'd' => $interval->d,
        'h' => $interval->h,
        'i' => $interval->i,
        's' => $interval->s,
    ];
    $diff['w']  = floor($diff['d'] / 7);
    $diff['d'] -= $diff['w'] * 7;

    $string = [
        'y' => 'y',
        'm' => 'm',
        'w' => 'w',
        'd' => 'd',
        'h' => 'h',
        'i' => 'm',
        's' => 's',
    ];
    foreach ($string as $k => &$v) {
        if ($diff[$k]) {
            $v = $diff[$k] . $v;
        } else {
            unset($string[$k]);
        }
    }

    if (!$full) {
        $string = array_slice($string, 0, 1);
    }

    return $string ? implode(' ', $string) : translate('justNow');
}

function normalizeBackupTime($time)
{
    if (!preg_match('/^(\d{1,2}):(\d{2})/', (string) $time, $match)) {
        return '03:00';
    }

    $hour   = min(23, max(0, intval($match[1])));
    $minute = intval(round(min(59, max(0, intval($match[2]))) / 10) * 10);
    if ($minute >= 60) {
        $minute = 0;
        $hour   = ($hour + 1) % 24;
    }

    return sprintf('%02d:%02d', $hour, $minute);
}

function daysBetweenDates($ymdStart, $ymdEnd)
{
    $start = new DateTime($ymdStart . ' 12:00:00');
    $end   = new DateTime($ymdEnd . '12:00:00');
    $diff  = $end->diff($start)->format('%a');

    return $diff;
}

function formatWatchDuration($seconds)
{
    $seconds = max(0, intval($seconds));
    $days    = intval(floor($seconds / 86400));
    $hours   = intval(floor(($seconds % 86400) / 3600));
    $minutes = intval(floor(($seconds % 3600) / 60));
    $remain  = $seconds % 60;

    $parts = [];
    if ($days) {
        $parts[] = $days . 'd';
    }
    if ($hours) {
        $parts[] = $hours . 'h';
    }
    if ($minutes) {
        $parts[] = $minutes . 'm';
    }
    if ($remain || !$parts) {
        $parts[] = $remain . 's';
    }

    return implode(' ', $parts);
}

function watchFinishedSeconds($isFinished, $runtimeSeconds)
{
    if (!$isFinished) {
        return 0;
    }

    $runtimeSeconds = intval($runtimeSeconds);
    return $runtimeSeconds > 0 ? $runtimeSeconds : 1;
}

function mergeWatchState($current, $incoming)
{
    $incoming   = is_array($incoming) ? $incoming : [];
    $finished   = intval($incoming['finished'] ?? 0);
    $inprogress = intval($incoming['inprogress'] ?? 0);
    $started    = intval($incoming['started'] ?? 0) ? 1 : 0;

    if (!is_array($current) || !$current) {
        return [
            'started'    => ($started || $finished || $inprogress) ? 1 : 0,
            'inprogress' => $finished ? 0 : $inprogress,
            'finished'   => $finished,
        ];
    }

    $result = [
        'started'    => (intval($current['started'] ?? 0) || $started) ? 1 : 0,
        'inprogress' => intval($current['inprogress'] ?? 0),
        'finished'   => intval($current['finished'] ?? 0),
    ];

    if ($finished > $result['finished']) {
        $result['finished']   = $finished;
        $result['inprogress'] = 0;
    } else if (!$result['finished'] && $inprogress > $result['inprogress']) {
        $result['inprogress'] = $inprogress;
    }

    if ($result['finished']) {
        $result['inprogress'] = 0;
    }
    if ($result['finished'] || $result['inprogress']) {
        $result['started'] = 1;
    }

    return $result;
}

function watchStatesEqual($a, $b)
{
    return intval($a['started'] ?? 0) == intval($b['started'] ?? 0)
        && intval($a['inprogress'] ?? 0) == intval($b['inprogress'] ?? 0)
        && intval($a['finished'] ?? 0) == intval($b['finished'] ?? 0);
}

function watchStateSatisfies($have, $want)
{
    $have = is_array($have) ? $have : [];
    $want = is_array($want) ? $want : [];

    $wantFinished = intval($want['finished'] ?? 0) > 0;
    $haveFinished = intval($have['finished'] ?? 0) > 0;
    if ($wantFinished) {
        return $haveFinished;
    }
    if ($haveFinished) {
        return true;
    }

    $wantProgress = intval($want['inprogress'] ?? 0);
    $haveProgress = intval($have['inprogress'] ?? 0);
    if ($wantProgress > 0) {
        return $haveProgress >= $wantProgress;
    }
    if (!(intval($want['started'] ?? 0))) {
        return true;
    }

    return intval($have['started'] ?? 0) > 0 || $haveProgress > 0;
}

function mediaRuntimeSeconds($value, $unit = 's')
{
    $value = intval($value);
    if ($value <= 0) {
        return 0;
    }
    if ($unit == 'ms') {
        return intval($value / 1000);
    }
    if ($unit == 'ticks') {
        return intval($value / 10000000);
    }

    return $value;
}
