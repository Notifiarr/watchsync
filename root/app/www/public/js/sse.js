const sseTimer = 57;
const sseInterval = 3;
let sseCountdown = 57;
let sseUpdated = 0;
let sseSource = '';
let sseLogId = '';
let ssePollTimer = '';

function stopSSEPoll()
{
    if (ssePollTimer) {
        clearInterval(ssePollTimer);
        ssePollTimer = '';
    }
}

function appendSyncLogLines(lines)
{
    let box = $('#sync-log-dialog #syncLogLines');
    if (!box.length || !lines || !lines.length) {
        return;
    }
    box.find('.sync-log-waiting').remove();
    let end = parseInt(box.attr('data-end') || '0', 10);
    if (isNaN(end)) {
        end = 0;
    }
    for (let i = 0; i < lines.length; i++) {
        let row = document.createElement('div');
        row.className = 'sync-log-line';
        row.setAttribute('data-line', String(end));
        row.textContent = lines[i];
        box.append(row);
        end++;
    }
    box.attr('data-end', end);
    box.attr('data-tail', '1');
}

function syncJobStatusHtml(status, id)
{
    if (status == 'finished') {
        return '<i class="fas fa-check text-success" title="' + translate('finished') + '"></i>';
    }
    if (status == 'cancelled') {
        return '<i class="fas fa-exclamation text-warning" title="' + translate('cancelled') + '"></i>';
    }
    if (status == 'error') {
        return '<i class="fas fa-exclamation text-danger" title="' + translate('error') + '"></i>';
    }
    if (status == 'queued') {
        return '<i class="fas fa-clock" title="' + translate('queued') + '"></i>' +
            '<i class="fas fa-xmark text-danger ms-2" style="cursor: pointer;" title="' + translate('cancelSync') + '" onclick="event.stopPropagation(); cancelSync(\'' + (id || '').replace(/'/g, '') + '\')"></i>';
    }

    return '<i class="fas fa-spinner fa-spin" title="' + translate('running') + '"></i>' +
        '<i class="fas fa-xmark text-danger ms-2" style="cursor: pointer;" title="' + translate('cancelSync') + '" onclick="event.stopPropagation(); cancelSync(\'' + (id || '').replace(/'/g, '') + '\')"></i>';
}

function applySyncHistory(jobs)
{
    if (!jobs || !$('#syncHistory').length) {
        return;
    }

    for (let i = 0; i < jobs.length; i++) {
        let job = jobs[i];
        if (!job || !job.id) {
            continue;
        }
        let row = $('#syncHistory .sync-job-row[data-id="' + job.id + '"]');
        if (!row.length) {
            continue;
        }
        if (job.status && row.attr('data-status') != job.status) {
            row.attr('data-status', job.status);
            row.find('.sync-history-status').html(syncJobStatusHtml(job.status, job.id));
            if (job.status == 'queued') {
                row.find('.sync-history-started').html(
                    translate('queued') + '<br><span class="sync-history-queued-wait"></span>'
                );
            } else if (job.started) {
                let started = new Date(job.started * 1000);
                row.find('.sync-history-started').html(
                    started.toLocaleDateString() + '<br>' + started.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' })
                );
            }
        }
        if (job.status == 'queued' && typeof job.queued_wait != 'undefined') {
            row.find('.sync-history-queued-wait').text(job.queued_wait);
        }
        if (typeof job.runtime != 'undefined') {
            row.find('.sync-history-runtime').text(job.runtime);
        }
        if (typeof job.size != 'undefined') {
            row.find('.sync-history-size').text(job.size);
        }
    }
}

function applySyncLogStatus(status)
{
    if (!status || !$('#sync-log-dialog #syncLogLines').length) {
        return;
    }
    $('#sync-log-dialog #syncLogLines').attr('data-status', status);
    if (status != 'running') {
        stopSSEPoll();
        if ($('#syncHistory').length && typeof refreshSyncHistory == 'function') {
            refreshSyncHistory();
        }
    }
}

function pollSyncLog()
{
    let box = $('#sync-log-dialog #syncLogLines');
    if (!box.length || box.attr('data-status') != 'running') {
        stopSSEPoll();
        return;
    }

    $.ajax({
        url: (typeof BASE_URL != 'undefined' ? BASE_URL : '') + 'ajax/sync.php',
        type: 'post',
        dataType: 'json',
        data: '&event=logTail&id=' + encodeURIComponent(box.attr('data-id') || '') + '&offset=' + encodeURIComponent(box.attr('data-offset') || '0'),
        success: function (response) {
            if (!response || response.error) {
                return;
            }
            if (typeof response.offset != 'undefined') {
                box.attr('data-offset', response.offset);
            }
            if (response.lines && response.lines.length) {
                appendSyncLogLines(response.lines);
            } else if (response.html) {
                box.find('.sync-log-waiting').remove();
                box.append(response.html);
            }
            applySyncLogStatus(response.status);
            if (response.jobs) {
                applySyncHistory(response.jobs);
            } else if (response.id || box.attr('data-id')) {
                applySyncHistory([{
                    id: response.id || box.attr('data-id'),
                    runtime: response.runtime,
                    size: response.size
                }]);
            }
            if (typeof syncLogFollow != 'undefined' && syncLogFollow && typeof scrollSyncLogToBottom == 'function') {
                scrollSyncLogToBottom();
            }
        }
    });
}

function startSSEPoll()
{
    if (ssePollTimer) {
        return;
    }
    ssePollTimer = setInterval(pollSyncLog, 1000);
}

function initializeSSE(logId)
{
    if (sseSource) {
        sseSource.close();
        sseSource = '';
    }
    stopSSEPoll();

    sseLogId = logId || '';
    console.log('SSE: Starting...');
    let url = (typeof BASE_URL != 'undefined' ? BASE_URL : '') + 'sse.php';
    if (sseLogId) {
        url += '?log=' + encodeURIComponent(sseLogId);
        let offset = $('#sync-log-dialog #syncLogLines').attr('data-offset');
        if (typeof offset != 'undefined') {
            url += '&offset=' + encodeURIComponent(offset);
        }
        startSSEPoll();
    }
    sseSource = new EventSource(url);
    console.log('SSE: Started');

    sseSource.onmessage = function (event) {
        let payload = {};
        try {
            payload = JSON.parse(event.data);
        } catch (e) {
            return;
        }

        if (typeof payload.offset != 'undefined') {
            $('#sync-log-dialog #syncLogLines').attr('data-offset', payload.offset);
        }
        appendSyncLogLines(payload.lines);
        applySyncLogStatus(payload.status);
        if (payload.jobs) {
            applySyncHistory(payload.jobs);
        } else if (payload.id && (typeof payload.runtime != 'undefined' || typeof payload.size != 'undefined')) {
            applySyncHistory([{
                id: payload.id,
                runtime: payload.runtime,
                size: payload.size
            }]);
        }

        if (payload.updated != sseUpdated) {
            if (sseUpdated == 0) {
                sseUpdated = payload.updated;
            } else {
                sseUpdated = payload.updated;
                if ($('#syncHistory').length && typeof refreshSyncHistory == 'function') {
                    refreshSyncHistory();
                }
            }
        }

        if ($('#sync-log-dialog #syncLogLines').length && typeof syncLogFollow != 'undefined' && syncLogFollow && typeof scrollSyncLogToBottom == 'function') {
            scrollSyncLogToBottom();
        }

        if (sseLogId && payload.status && payload.status != 'running') {
            initializeSSE();
        }
    };

    sseSource.onerror = function () {
        if (sseLogId) {
            startSSEPoll();
            return;
        }
        if ($('#syncHistory .sync-job-row[data-status="running"]').length && typeof refreshSyncHistory == 'function') {
            refreshSyncHistory();
        }
    };
}
