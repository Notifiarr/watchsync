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

    //-- HANDLE TIMESTAMPS FIRST
    if (is_numeric($startDatetime)) {
        $startDatetime = date('Y-m-d H:i:s', $startDatetime);
    }
    if (is_numeric($endDatetime)) {
        $endDatetime = date('Y-m-d H:i:s', $endDatetime);
    }

    //-- HANDLE DATE TIME WITH TIMEZONE
    if (substr($startDatetime, -1) == 'Z') {
        $explodeDatetime = explode('.', $startDatetime);
        $startDatetime   = reset($explodeDatetime) . '+00:00';
    }

    //-- HANDLE DATE TIME WITH TIMEZONE OFFSET
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
        $end = new DateTime(); //-- NOW
    }

    $diff       = (array) $end->diff($start);
    $diff['w']  = floor($diff['d'] / 7);
    $diff['d'] -= $diff['w'] * 7;

    $string = [
        'y' => 'y',
        'm' => 'm',
        'w' => 'w',
        'd' => 'd',
        'h' => 'h',
        'i' => 'm',
        's' => 's'
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
