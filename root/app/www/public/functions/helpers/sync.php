<?php

/*
----------------------------------
------  Created: 091526   ------
------  Austin Best       ------
----------------------------------
*/

function syncJobStatusHtml($status, $id = '')
{
    $status = strval($status ?? '');
    $id     = strval($id ?? '');

    if ($status == 'finished') {
        return '<i class="fas fa-check text-success" title="' . htmlEscape(translate('finished')) . '"></i>';
    }
    if ($status == 'cancelled') {
        return '<i class="fas fa-exclamation text-warning" title="' . htmlEscape(translate('cancelled')) . '"></i>';
    }
    if ($status == 'error') {
        return '<i class="fas fa-exclamation text-danger" title="' . htmlEscape(translate('error')) . '"></i>';
    }
    if ($status == 'queued') {
        return '<i class="fas fa-clock" title="' . htmlEscape(translate('queued')) . '"></i>'
            . '<i class="fas fa-xmark text-danger ms-2" style="cursor: pointer;" title="' . htmlEscape(translate('cancelSync')) . '" onclick="event.stopPropagation(); cancelSync(\'' . htmlEscape($id) . '\')"></i>';
    }

    return '<i class="fas fa-spinner fa-spin" title="' . htmlEscape(translate('running')) . '"></i>'
        . '<i class="fas fa-xmark text-danger ms-2" style="cursor: pointer;" title="' . htmlEscape(translate('cancelSync')) . '" onclick="event.stopPropagation(); cancelSync(\'' . htmlEscape($id) . '\')"></i>';
}

function syncJobStartedHtml($status, $started = 0)
{
    if ($status == 'queued') {
        return htmlEscape(translate('queued')) . '<br><span class="sync-history-queued-wait"></span>';
    }

    $started = intval($started);
    if (!$started) {
        return '';
    }

    return htmlEscape(date('n/j/Y', $started)) . '<br>' . htmlEscape(date('g:i A', $started));
}

function syncJobSsePayload($job)
{
    $status  = strval($job['status'] ?? '');
    $id      = strval($job['id'] ?? '');
    $started = intval($job['started'] ?? 0);

    return [
        'id'           => $id,
        'status'       => $status,
        'runtime'      => $job['runtime'] ?? '',
        'queued_wait'  => $job['queued_wait'] ?? '',
        'started'      => $started,
        'size'         => is_string($job['size'] ?? null) ? $job['size'] : byteConversion($job['size'] ?? 0),
        'status_html'  => syncJobStatusHtml($status, $id),
        'started_html' => syncJobStartedHtml($status, $started),
    ];
}
