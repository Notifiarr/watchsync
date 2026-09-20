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
// ---------------------------------------------------------------------------------------------
function appendSyncLogLines(lines)
{
    if (!$('#sync-log-dialog #syncLogLines').length || !lines || !lines.length) {
        return;
    }
    $('#sync-log-dialog #syncLogLines').find('.sync-log-waiting').remove();
    let end = parseInt($('#sync-log-dialog #syncLogLines').attr('data-end') || '0', 10);
    if (isNaN(end)) {
        end = 0;
    }
        for (let i = 0; i < lines.length; i++) {
            $('#sync-log-dialog #syncLogLines').append(
                $('<div class="sync-log-line"></div>').attr('data-line', end).append(
                    $('<span class="sync-log-num"></span>').text(end + 1),
                    $('<span class="sync-log-text"></span>').text(lines[i])
                )
            );
            end++;
        }
    $('#sync-log-dialog #syncLogLines').attr('data-end', end);
    $('#sync-log-dialog #syncLogLines').attr('data-tail', '1');
}
// ---------------------------------------------------------------------------------------------
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
        if (!$('#syncHistory .sync-job-row[data-id="' + job.id + '"]').length) {
            continue;
        }
        if (job.status && $('#syncHistory .sync-job-row[data-id="' + job.id + '"]').attr('data-status') != job.status) {
            $('#syncHistory .sync-job-row[data-id="' + job.id + '"]').attr('data-status', job.status);
            if (job.status_html) {
                $('#syncHistory .sync-job-row[data-id="' + job.id + '"]').find('.sync-history-status').html(job.status_html);
            }
            if (job.started_html) {
                $('#syncHistory .sync-job-row[data-id="' + job.id + '"]').find('.sync-history-started').html(job.started_html);
            }
        }
        if (job.status == 'queued' && typeof job.queued_wait != 'undefined') {
            $('#syncHistory .sync-job-row[data-id="' + job.id + '"]').find('.sync-history-queued-wait').text(job.queued_wait);
        }
        if (typeof job.runtime != 'undefined') {
            $('#syncHistory .sync-job-row[data-id="' + job.id + '"]').find('.sync-history-runtime').text(job.runtime);
        }
        if (typeof job.size != 'undefined') {
            $('#syncHistory .sync-job-row[data-id="' + job.id + '"]').find('.sync-history-size').text(job.size);
        }
    }
}
// ---------------------------------------------------------------------------------------------
function applySyncLogStatus(status)
{
    if (!status || !$('#sync-log-dialog #syncLogLines').length) {
        return;
    }
    let previous = $('#sync-log-dialog #syncLogLines').attr('data-status') || '';
    $('#sync-log-dialog #syncLogLines').attr('data-status', status);
    if (status != 'running') {
        if (previous == 'running') {
            pollSyncLogFinal();
            return;
        }
        stopSSEPoll();
        if ($('#syncHistory').length && typeof refreshSyncHistory == 'function') {
            refreshSyncHistory();
        }
    }
}
// ---------------------------------------------------------------------------------------------
function pollSyncLogFinal()
{
    stopSSEPoll();
    $.ajax({
        url: (typeof BASE_URL != 'undefined' ? BASE_URL : '') + 'ajax/sync.php',
        type: 'post',
        dataType: 'json',
        data: '&event=logTail&id=' + encodeURIComponent($('#sync-log-dialog #syncLogLines').attr('data-id') || '') + '&offset=' + encodeURIComponent($('#sync-log-dialog #syncLogLines').attr('data-offset') || '0'),
        complete: function () {
            if ($('#syncHistory').length && typeof refreshSyncHistory == 'function') {
                refreshSyncHistory();
            }
        },
        success: function (response) {
            if (!response || response.error) {
                return;
            }
            if (typeof response.offset != 'undefined') {
                $('#sync-log-dialog #syncLogLines').attr('data-offset', response.offset);
            }
            if (response.lines && response.lines.length) {
                appendSyncLogLines(response.lines);
            }
            if (response.status) {
                $('#sync-log-dialog #syncLogLines').attr('data-status', response.status);
            }
            if (typeof syncLogFollow != 'undefined' && syncLogFollow && typeof scrollSyncLogToBottom == 'function') {
                scrollSyncLogToBottom();
            }
        }
    });
}
// ---------------------------------------------------------------------------------------------
function pollSyncLog()
{
    if (!$('#sync-log-dialog #syncLogLines').length || $('#sync-log-dialog #syncLogLines').attr('data-status') != 'running') {
        stopSSEPoll();
        return;
    }

    $.ajax({
        url: (typeof BASE_URL != 'undefined' ? BASE_URL : '') + 'ajax/sync.php',
        type: 'post',
        dataType: 'json',
        data: '&event=logTail&id=' + encodeURIComponent($('#sync-log-dialog #syncLogLines').attr('data-id') || '') + '&offset=' + encodeURIComponent($('#sync-log-dialog #syncLogLines').attr('data-offset') || '0'),
        success: function (response) {
            if (!response || response.error) {
                return;
            }
            if (typeof response.offset != 'undefined') {
                $('#sync-log-dialog #syncLogLines').attr('data-offset', response.offset);
            }
            if (response.lines && response.lines.length) {
                appendSyncLogLines(response.lines);
            } else if (response.html) {
                $('#sync-log-dialog #syncLogLines').find('.sync-log-waiting').remove();
                $('#sync-log-dialog #syncLogLines').append(response.html);
            }
            applySyncLogStatus(response.status);
            if (response.jobs) {
                applySyncHistory(response.jobs);
            } else if (response.id || $('#sync-log-dialog #syncLogLines').attr('data-id')) {
                applySyncHistory([{
                    id: response.id || $('#sync-log-dialog #syncLogLines').attr('data-id'),
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
// ---------------------------------------------------------------------------------------------
function startSSEPoll()
{
    if (ssePollTimer) {
        return;
    }
    ssePollTimer = setInterval(pollSyncLog, 1000);
}
// ---------------------------------------------------------------------------------------------
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
    }
    sseSource = new EventSource(url);
    console.log('SSE: Started');

    sseSource.onmessage = function (event) {
        stopSSEPoll();
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
                if ($('.sync-automatic').length && typeof refreshAllSyncAutomaticCountdowns == 'function') {
                    refreshAllSyncAutomaticCountdowns();
                }
            }
        }

        if ($('#sync-log-dialog #syncLogLines').length && typeof syncLogFollow != 'undefined' && syncLogFollow && typeof scrollSyncLogToBottom == 'function') {
            scrollSyncLogToBottom();
        }

        if (sseLogId && payload.status && payload.status != 'running') {
            if ($('.sync-automatic').length && typeof refreshAllSyncAutomaticCountdowns == 'function') {
                refreshAllSyncAutomaticCountdowns();
            }
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
