var syncLogLoading = false;
var syncLogFollow = true;
var syncLogPinTimers = [];
var syncLogFindState = {
    query: '',
    total: 0,
    loading: false,
    timer: null
};
var syncLogMatchesLoading = false;

function closeSyncLogSource()
{
    stopSSEPoll();
    initializeSSE();
}
// ---------------------------------------------------------------------------------------------
function clearSyncLogPinTimers()
{
    for (let i = 0; i < syncLogPinTimers.length; i++) {
        clearTimeout(syncLogPinTimers[i]);
    }
    syncLogPinTimers = [];
}
// ---------------------------------------------------------------------------------------------
function syncLogPaneAtBottom(pane)
{
    if (!$(pane).length) {
        return true;
    }

    return $(pane).scrollTop() + $(pane).prop('clientHeight') >= $(pane).prop('scrollHeight') - 40;
}
// ---------------------------------------------------------------------------------------------
function syncLogAtBottom()
{
    return syncLogPaneAtBottom($('#sync-log-dialog #syncLogMainPane'));
}
// ---------------------------------------------------------------------------------------------
function scrollSyncLogToBottom()
{
    if (!syncLogFollow) {
        return;
    }

    if (!$('#sync-log-dialog #syncLogMainPane').length) {
        return;
    }

    function pin()
    {
        if (!syncLogFollow || !$('#sync-log-dialog #syncLogMainPane').length) {
            return;
        }
        $('#sync-log-dialog #syncLogMainPane').scrollTop($('#sync-log-dialog #syncLogMainPane').prop('scrollHeight'));
    }

    clearSyncLogPinTimers();
    pin();
    requestAnimationFrame(function () {
        pin();
        syncLogPinTimers.push(setTimeout(pin, 0));
        syncLogPinTimers.push(setTimeout(pin, 50));
        syncLogPinTimers.push(setTimeout(pin, 150));
    });
}
// ---------------------------------------------------------------------------------------------
function scrollSyncLogMatchesToBottom()
{
    if (!$('#sync-log-dialog #syncLogMatchesPane').length) {
        return;
    }
    $('#sync-log-dialog #syncLogMatchesPane').scrollTop($('#sync-log-dialog #syncLogMatchesPane').prop('scrollHeight'));
    requestAnimationFrame(function () {
        $('#sync-log-dialog #syncLogMatchesPane').scrollTop($('#sync-log-dialog #syncLogMatchesPane').prop('scrollHeight'));
    });
}
// ---------------------------------------------------------------------------------------------
function formatSyncCountdown(seconds)
{
    seconds = Math.max(0, Math.floor(seconds || 0));
    let hours = Math.floor(seconds / 3600);
    let minutes = Math.floor((seconds % 3600) / 60);
    let remain = seconds % 60;
    let parts = [];
    if (hours) {
        parts.push(hours + 'h');
    }
    if (hours || minutes) {
        parts.push(minutes + 'm');
    }
    parts.push(remain + 's');
    return parts.join(' ');
}
// ---------------------------------------------------------------------------------------------
var syncAutomaticRefreshPending = {};
var syncAutomaticRefreshTimers = {};
function refreshSyncAutomaticCountdown($el)
{
    let key = $el.attr('data-key') || '';
    if (!key || syncAutomaticRefreshPending[key]) {
        return;
    }
    syncAutomaticRefreshPending[key] = true;
    $.ajax({
        url: BASE_URL + 'ajax/sync.php',
        type: 'post',
        dataType: 'json',
        data: '&event=automaticNext&key=' + encodeURIComponent(key),
        complete: function () {
            syncAutomaticRefreshPending[key] = false;
        },
        success: function (response) {
            if (!response || response.error || !$el.closest('body').length) {
                return;
            }
            setSyncAutomaticCountdown($el, !!response.enabled, response.next_at, response.until);
            let nextAt = parseInt(response.next_at, 10) || 0;
            let now = Math.floor(Date.now() / 1000);
            if (response.enabled && nextAt && nextAt <= now) {
                if (syncAutomaticRefreshTimers[key]) {
                    clearTimeout(syncAutomaticRefreshTimers[key]);
                }
                syncAutomaticRefreshTimers[key] = setTimeout(function () {
                    delete syncAutomaticRefreshTimers[key];
                    refreshSyncAutomaticCountdown($el);
                }, 5000);
            } else if (syncAutomaticRefreshTimers[key]) {
                clearTimeout(syncAutomaticRefreshTimers[key]);
                delete syncAutomaticRefreshTimers[key];
            }
        }
    });
}
// ---------------------------------------------------------------------------------------------
function refreshAllSyncAutomaticCountdowns()
{
    $('.sync-automatic').each(function () {
        let $el = $(this);
        if (!(parseInt($el.attr('data-next-at'), 10) || 0) && !$el.find('.sync-automatic-next').text()) {
            return;
        }
        refreshSyncAutomaticCountdown($el);
    });
}
// ---------------------------------------------------------------------------------------------
function updateSyncAutomaticCountdowns()
{
    let now = Math.floor(Date.now() / 1000);
    $('.sync-automatic').each(function () {
        let $el = $(this);
        let nextAt = parseInt($el.attr('data-next-at'), 10) || 0;
        let next = $el.find('.sync-automatic-next');
        if (!nextAt) {
            next.text('');
            return;
        }
        let remain = nextAt - now;
        if (remain <= 0) {
            let key = $el.attr('data-key') || '';
            next.text(translate('automationNextCountdown', [formatSyncCountdown(0)]));
            if (key && !syncAutomaticRefreshPending[key] && !syncAutomaticRefreshTimers[key]) {
                refreshSyncAutomaticCountdown($el);
            }
            return;
        }
        next.text(translate('automationNextCountdown', [formatSyncCountdown(remain)]));
    });
}
// ---------------------------------------------------------------------------------------------
var syncAutomaticCountdownTimer = null;
function initSyncAutomaticCountdowns()
{
    updateSyncAutomaticCountdowns();
    if (syncAutomaticCountdownTimer) {
        clearInterval(syncAutomaticCountdownTimer);
    }
    syncAutomaticCountdownTimer = setInterval(updateSyncAutomaticCountdowns, 1000);
}
// ---------------------------------------------------------------------------------------------
function setSyncAutomaticCountdown($el, enabled, nextAt, until)
{
    nextAt = enabled ? (parseInt(nextAt, 10) || 0) : 0;
    $el.attr('data-next-at', nextAt);
    if (!enabled || !nextAt) {
        $el.find('.sync-automatic-next').text('');
        return;
    }
    let text = until || formatSyncCountdown(nextAt - Math.floor(Date.now() / 1000));
    $el.find('.sync-automatic-next').text(translate('automationNextCountdown', [text]));
}
// ---------------------------------------------------------------------------------------------
function toggleSyncAutomatic(el)
{
    let $el = $(el);
    let key = $el.attr('data-key') || $el.data('key') || '';
    if (!key) {
        return;
    }

    pageLoadingStart();
    $.ajax({
        url: BASE_URL + 'ajax/sync.php',
        type: 'post',
        dataType: 'json',
        data: '&event=toggleAutomatic&key=' + encodeURIComponent(key),
        success: function (response) {
            pageLoadingStop();
            if (!response || response.error) {
                toast(translate('sync'), (response && response.message) || translate('couldNotSaveSettings'), 'error');
                return;
            }
            let icon = $el.find('i.fa-clock');
            icon.removeClass('text-success text-danger');
            icon.addClass(response.enabled ? 'text-success' : 'text-danger');
            $el.attr('title', response.title || '');
            if (typeof response.label != 'undefined') {
                $el.find('.sync-automatic-label').text(response.label || '');
            }
            setSyncAutomaticCountdown($el, !!response.enabled, response.next_at, response.until);
            initSyncAutomaticCountdowns();
            toast(translate('sync'), response.message || translate('saved'), 'success');
        },
        error: function () {
            pageLoadingStop();
            toast(translate('sync'), translate('couldNotSaveSettings'), 'error');
        }
    });
}
// ---------------------------------------------------------------------------------------------
function openSyncLibrary()
{
    pageLoadingStart();
    $.ajax({
        url: BASE_URL + 'ajax/sync.php',
        type: 'post',
        data: '&event=libraryForm',
        success: function (response) {
            dialogOpen({
                id: 'sync-library-form',
                title: translate('library'),
                body: response,
                footer: false,
                size: 'lg',
                onOpen: function () {
                    pageLoadingStop();
                }
            });
        },
        error: function () {
            pageLoadingStop();
            toast(translate('sync'), translate('unableToLoadPage'), 'error');
        }
    });
}
// ---------------------------------------------------------------------------------------------
function openSyncUsers()
{
    pageLoadingStart();
    $.ajax({
        url: BASE_URL + 'ajax/sync.php',
        type: 'post',
        data: '&event=usersForm',
        success: function (response) {
            dialogOpen({
                id: 'sync-users-form',
                title: translate('parity'),
                body: response,
                footer: false,
                size: 'xl',
                onOpen: function () {
                    pageLoadingStop();
                }
            });
        },
        error: function () {
            pageLoadingStop();
            toast(translate('sync'), translate('unableToLoadPage'), 'error');
        }
    });
}
// ---------------------------------------------------------------------------------------------
function openSyncHistory()
{
    pageLoadingStart();
    $.ajax({
        url: BASE_URL + 'ajax/sync.php',
        type: 'post',
        data: '&event=historyForm',
        success: function (response) {
            dialogOpen({
                id: 'sync-history-form',
                title: translate('history'),
                body: response,
                footer: false,
                size: 'xl',
                onOpen: function () {
                    pageLoadingStop();
                }
            });
        },
        error: function () {
            pageLoadingStop();
            toast(translate('sync'), translate('unableToLoadPage'), 'error');
        }
    });
}
// ---------------------------------------------------------------------------------------------
function libraryScanItems()
{
    let items = {};
    $('#sync-library-form .sync-library').each(function () {
        items[$(this).val()] = $(this).prop('checked') ? 1 : 0;
    });

    return items;
}
// ---------------------------------------------------------------------------------------------
function libraryScanUpdateSelectAll()
{
    $('[id^="syncLibraryAll-"]').each(function () {
        let $boxes = $(this).closest('.col').find('.sync-library');
        $(this).prop('checked', $boxes.length > 0 && $boxes.filter(':checked').length == $boxes.length);
    });
}
// ---------------------------------------------------------------------------------------------
function saveLibraryScan()
{
    pageLoadingStart();
    $.ajax({
        url: BASE_URL + 'ajax/sync.php',
        type: 'post',
        dataType: 'json',
        data: '&event=saveLibraryScan&items=' + encodeURIComponent(JSON.stringify(libraryScanItems())),
        success: function (response) {
            pageLoadingStop();
            if (!response || response.error) {
                toast(translate('library'), (response && response.message) || translate('couldNotSaveSettings'), 'error');
                return;
            }
            toast(translate('library'), response.message || translate('saved'), 'success');
        },
        error: function () {
            pageLoadingStop();
            toast(translate('library'), translate('couldNotSaveSettings'), 'error');
        }
    });
}
// ---------------------------------------------------------------------------------------------
$(document).on('change', '.sync-library', function () {
    libraryScanUpdateSelectAll();
});
// ---------------------------------------------------------------------------------------------
$(document).on('change', '[id^="syncLibraryAll-"]', function () {
    $(this).closest('.col').find('.sync-library').prop('checked', $(this).prop('checked'));
});
// ---------------------------------------------------------------------------------------------
function startLibrarySync()
{
    let libraries = [];
    $('#sync-library-form .sync-library:checked').each(function () {
        libraries.push($(this).val());
    });

    pageLoadingStart();
    $.ajax({
        url: BASE_URL + 'ajax/sync.php',
        type: 'post',
        dataType: 'json',
        data: '&event=startLibrary&libraries=' + encodeURIComponent(libraries.join(',')) + '&scan=' + encodeURIComponent($('#syncLibraryScan').val() || ''),
        success: function (response) {
            syncJobStarted(response, 'sync-library-form');
        },
        error: function () {
            pageLoadingStop();
            toast(translate('sync'), translate('couldNotSaveSettings'), 'error');
        }
    });
}
// ---------------------------------------------------------------------------------------------
function startHistorySync(dryRun)
{
    let users = [];
    $('.sync-history-user:checked').each(function () {
        users.push($(this).val());
    });

    pageLoadingStart();
    $.ajax({
        url: BASE_URL + 'ajax/sync.php',
        type: 'post',
        dataType: 'json',
        data: '&event=startHistory&syncMode=' + encodeURIComponent($('#syncHistoryMode').val() || '') + '&users=' + encodeURIComponent(users.join(',')) + '&dryRun=' + (dryRun ? '1' : ''),
        success: function (response) {
            syncJobStarted(response, 'sync-history-form');
        },
        error: function () {
            pageLoadingStop();
            toast(translate('sync'), translate('couldNotSaveSettings'), 'error');
        }
    });
}
var paritySelected = '';
// ---------------------------------------------------------------------------------------------
function parityClearSelect()
{
    $('.parity-item').removeClass('border border-primary rounded px-1');
    paritySelected = '';
}
// ---------------------------------------------------------------------------------------------
function parityMasterId(item)
{
    let $item = $(item);
    if ($item.attr('data-master') == '1') {
        if ($item.attr('data-kind') == 'library') {
            return $item.attr('data-app') + ':' + $item.attr('data-id');
        }
        return $item.attr('data-id');
    }

    return $item.attr('data-link') || '';
}
// ---------------------------------------------------------------------------------------------
function parityAddLinkIcon($item, color, appId, id)
{
    if (!color || !$item.length) {
        return;
    }
    $item.find('.parity-unlink').filter(function () {
        return String($(this).attr('data-app')) == String(appId) && String($(this).attr('data-id')) == String(id);
    }).remove();
    $item.find('.parity-name').after(
        $('<i class="fas fa-link small parity-unlink ms-1"></i>')
            .css('color', color)
            .attr('title', translate('unlink'))
            .attr('data-app', appId)
            .attr('data-id', id)
    );
    parityPlaceItem($item);
}
// ---------------------------------------------------------------------------------------------
function parityPlaceItem($item)
{
    let $table = $item.closest('table.parity-table');
    if (!$table.length || !$item.is('td')) {
        return;
    }

    let colIndex = $item[0].cellIndex;
    let $rows = $table.children('tbody').children('tr');
    let cells = [];
    $rows.each(function () {
        cells.push($(this).children('td').eq(colIndex));
    });
    cells.sort(function (a, b) {
        return (parseInt($(a).attr('data-sort'), 10) || 0) - (parseInt($(b).attr('data-sort'), 10) || 0);
    });
    $rows.each(function (i) {
        let $row = $(this);
        if (colIndex == 0) {
            $row.prepend(cells[i]);
            return;
        }
        $row.children('td').eq(colIndex - 1).after(cells[i]);
    });
}
// ---------------------------------------------------------------------------------------------
function parityItemClass(kind)
{
    return kind == 'library' ? '.sync-parity-library' : '.sync-user';
}
// ---------------------------------------------------------------------------------------------
function parityItemMasterValue($item)
{
    if ($item.attr('data-kind') == 'library') {
        return String(parityMasterId($item) || '');
    }
    if ($item.attr('data-master') == '1') {
        return String($item.attr('data-id') || '');
    }

    return String($item.attr('data-link') || '');
}
// ---------------------------------------------------------------------------------------------
function parityUpdateMasterCheckbox($item)
{
    parityMirrorChecks($item);
}
// ---------------------------------------------------------------------------------------------
function parityMirrorChecks($item)
{
    let kind = $item.attr('data-kind');
    let group = parityItemMasterValue($item);
    let $box = $item.find(parityItemClass(kind));
    if (!$box.length) {
        parityUpdateSelectAll(kind);
        return;
    }
    let checked = $box.prop('checked');
    if (group) {
        $('.parity-item[data-kind="' + kind + '"]').each(function () {
            if (parityItemMasterValue($(this)) != group) {
                return;
            }
            $(this).find(parityItemClass(kind)).prop('checked', checked);
        });
    }
    parityUpdateSelectAll(kind);
}
// ---------------------------------------------------------------------------------------------
function parityUpdateSelectAll(kind)
{
    let cls = parityItemClass(kind);
    $('.parity-select-all[data-kind="' + kind + '"]').each(function () {
        let app = $(this).attr('data-app');
        let $boxes = $('.parity-item[data-kind="' + kind + '"][data-app="' + app + '"]').find(cls);
        $(this).prop('checked', $boxes.length > 0 && $boxes.filter(':checked').length == $boxes.length);
    });
}
// ---------------------------------------------------------------------------------------------
function paritySyncItems(kind)
{
    let items = {};
    let cls = parityItemClass(kind);
    $(cls).each(function () {
        if ($(this).closest('.parity-item').attr('data-master') != '1') {
            return;
        }
        items[$(this).val()] = $(this).prop('checked') ? 1 : 0;
    });

    return items;
}
// ---------------------------------------------------------------------------------------------
function saveParitySync(kind)
{
    pageLoadingStart();
    $.ajax({
        url: BASE_URL + 'ajax/sync.php',
        type: 'post',
        dataType: 'json',
        data: '&event=saveParitySync&kind=' + encodeURIComponent(kind) + '&items=' + encodeURIComponent(JSON.stringify(paritySyncItems(kind))),
        success: function (response) {
            pageLoadingStop();
            if (!response || response.error) {
                toast(translate('parity'), (response && response.message) || translate('couldNotSaveSettings'), 'error');
                return;
            }
            toast(translate('parity'), response.message || translate('saved'), 'success');
        },
        error: function () {
            pageLoadingStop();
            toast(translate('parity'), translate('couldNotSaveSettings'), 'error');
        }
    });
}
// ---------------------------------------------------------------------------------------------
function saveParityUsers()
{
    saveParitySync('user');
}
// ---------------------------------------------------------------------------------------------
function saveParityLibraries()
{
    saveParitySync('library');
}
// ---------------------------------------------------------------------------------------------
function paritySelect(item)
{
    if (paritySelected && paritySelected == item) {
        parityClearSelect();
        return;
    }

    if (!paritySelected) {
        parityClearSelect();
        $(item).addClass('border border-primary rounded px-1');
        paritySelected = item;
        return;
    }

    let $first = $(paritySelected);
    let $second = $(item);
    if ($first.attr('data-kind') != $second.attr('data-kind')) {
        toast(translate('parity'), translate('mustLinkMainSource'), 'error');
        return;
    }
    if ($first.attr('data-app') == $second.attr('data-app')) {
        $first.removeClass('border border-primary rounded px-1');
        $second.addClass('border border-primary rounded px-1');
        paritySelected = item;
        return;
    }

    let masterId = parityMasterId($first);
    let listener = $second;
    if ($first.attr('data-master') != '1' && $second.attr('data-master') == '1') {
        masterId = parityMasterId($second);
        listener = $first;
    } else if ($first.attr('data-master') != '1' && $second.attr('data-master') != '1') {
        if (!masterId) {
            masterId = parityMasterId($second);
        }
        if ($second.attr('data-link') && $second.attr('data-link') == masterId) {
            listener = $first;
        }
    }

    if (!masterId || listener.attr('data-master') == '1') {
        toast(translate('parity'), translate('mustLinkMainSource'), 'error');
        return;
    }

    let kind = $first.attr('data-kind');
    let payload = '';
    if (kind == 'user') {
        payload = '&event=linkUser&masterUserId=' + encodeURIComponent(masterId) + '&linkedUserId=' + encodeURIComponent(listener.attr('data-id'));
    } else {
        let parts = String(masterId).split(':');
        payload = '&event=linkLibrary&mediaAppId=' + encodeURIComponent(parts[0] || '') + '&libraryKey=' + encodeURIComponent(parts.slice(1).join(':')) + '&linkedMediaAppId=' + encodeURIComponent(listener.attr('data-app')) + '&linkedLibraryKey=' + encodeURIComponent(listener.attr('data-id'));
    }

    pageLoadingStart();
    $.ajax({
        url: BASE_URL + 'ajax/sync.php',
        type: 'post',
        dataType: 'json',
        data: payload,
        success: function (response) {
            pageLoadingStop();
            if (!response || response.error) {
                toast(translate('parity'), (response && response.message) || translate('couldNotSaveSettings'), 'error');
                return;
            }
            let color = response.color || '';
            let group = response.masterId || masterId;
            let linkedApp = String(response.linkedAppId || listener.attr('data-app'));
            let linkedId = String(response.linkedId || listener.attr('data-id'));
            listener.attr('data-link', group);
            $('.parity-item[data-kind="' + kind + '"]').each(function () {
                let $item = $(this);
                if ($item.attr('data-master') == '1' && String(parityMasterId($item)) == String(group)) {
                    parityAddLinkIcon($item, color, linkedApp, linkedId);
                    parityUpdateMasterCheckbox($item);
                }
            });
            parityAddLinkIcon(listener, color, linkedApp, linkedId);
            parityClearSelect();
        },
        error: function () {
            pageLoadingStop();
            toast(translate('parity'), translate('couldNotSaveSettings'), 'error');
        }
    });
}
// ---------------------------------------------------------------------------------------------
function parityUnlink(kind, appId, id, isMaster)
{
    let payload = '';
    if (kind == 'user') {
        payload = '&event=unlinkUser&userId=' + encodeURIComponent(id) + '&master=' + (isMaster ? '1' : '');
    } else {
        payload = '&event=unlinkLibrary&mediaAppId=' + encodeURIComponent(appId) + '&libraryKey=' + encodeURIComponent(id) + '&master=' + (isMaster ? '1' : '');
    }

    pageLoadingStart();
    $.ajax({
        url: BASE_URL + 'ajax/sync.php',
        type: 'post',
        dataType: 'json',
        data: payload,
        success: function (response) {
            pageLoadingStop();
            if (!response || response.error) {
                toast(translate('parity'), (response && response.message) || translate('couldNotSaveSettings'), 'error');
                return;
            }
            let $moved = $();
            $('.parity-unlink').filter(function () {
                return String($(this).attr('data-app')) == String(appId) && String($(this).attr('data-id')) == String(id);
            }).each(function () {
                $moved = $moved.add($(this).closest('.parity-item'));
            }).remove();
            $('.parity-item[data-kind="' + kind + '"][data-master="0"]').filter(function () {
                return String($(this).attr('data-app')) == String(appId) && String($(this).attr('data-id')) == String(id);
            }).attr('data-link', '').each(function () {
                $moved = $moved.add(this);
            });
            $moved.each(function () {
                let $item = $(this);
                parityPlaceItem($item);
                parityUpdateMasterCheckbox($item);
            });
            parityClearSelect();
        },
        error: function () {
            pageLoadingStop();
            toast(translate('parity'), translate('couldNotSaveSettings'), 'error');
        }
    });
}
// ---------------------------------------------------------------------------------------------
$(document).on('click', '.parity-unlink', function (event) {
    event.stopPropagation();
    let $icon = $(this);
    let $item = $icon.closest('.parity-item');
    parityUnlink($item.attr('data-kind'), $icon.attr('data-app'), $icon.attr('data-id'), 0);
});
// ---------------------------------------------------------------------------------------------
$(document).on('click', '.parity-item', function (event) {
    if ($(event.target).closest('.form-check').length || $(event.target).closest('.parity-unlink').length) {
        return;
    }
    if ($(this).attr('data-offline') == '1' || $(this).hasClass('parity-item-empty')) {
        return;
    }
    paritySelect(this);
});
// ---------------------------------------------------------------------------------------------
$(document).on('change', '.sync-user, .sync-parity-library', function () {
    parityMirrorChecks($(this).closest('.parity-item'));
});
// ---------------------------------------------------------------------------------------------
$(document).on('change', '.parity-select-all', function () {
    let kind = $(this).attr('data-kind');
    let app = $(this).attr('data-app');
    let cls = parityItemClass(kind);
    let checked = $(this).prop('checked');
    $('.parity-item[data-kind="' + kind + '"][data-app="' + app + '"]').find(cls).each(function () {
        $(this).prop('checked', checked);
        parityMirrorChecks($(this).closest('.parity-item'));
    });
    parityUpdateSelectAll(kind);
});
// ---------------------------------------------------------------------------------------------
function startParityLibrariesSync()
{
    pageLoadingStart();
    $.ajax({
        url: BASE_URL + 'ajax/sync.php',
        type: 'post',
        dataType: 'json',
        data: '&event=startLibraries&items=' + encodeURIComponent(JSON.stringify(paritySyncItems('library'))),
        success: function (response) {
            syncJobStarted(response, 'sync-users-form');
        },
        error: function () {
            pageLoadingStop();
            toast(translate('sync'), translate('couldNotSaveSettings'), 'error');
        }
    });
}
// ---------------------------------------------------------------------------------------------
function startUsersSync()
{
    pageLoadingStart();
    $.ajax({
        url: BASE_URL + 'ajax/sync.php',
        type: 'post',
        dataType: 'json',
        data: '&event=startUsers',
        success: function (response) {
            syncJobStarted(response, 'sync-users-form');
        },
        error: function () {
            pageLoadingStop();
            toast(translate('sync'), translate('couldNotSaveSettings'), 'error');
        }
    });
}
// ---------------------------------------------------------------------------------------------
function syncJobStarted(response, dialogId)
{
    pageLoadingStop();
    if (!response || response.error) {
        toast(translate('sync'), (response && response.message) || translate('couldNotSaveSettings'), 'error');
        return;
    }
    if (dialogId) {
        dialogClose(dialogId);
    }
    toast(translate('sync'), response.message || translate('syncStarted'), 'success');
    refreshSyncHistory();
    if (response.id && response.status == 'running') {
        openSyncLog(response.id);
    }
}
// ---------------------------------------------------------------------------------------------
function refreshSyncHistory()
{
    if ($.fn.dataTable && $.fn.dataTable.isDataTable('#sync-history-table')) {
        $('#sync-history-table').DataTable().destroy();
    }
    $.ajax({
        url: BASE_URL + 'ajax/sync.php',
        type: 'post',
        data: '&event=history',
        success: function (response) {
            $('#syncHistory').html(response);
            initSyncHistoryTable();
        }
    });
}
// ---------------------------------------------------------------------------------------------
function initSyncHistoryTable()
{
    initDataTable('#sync-history-table', {
        language: {
            emptyTable: translate('noSyncHistory')
        }
    });
}
// ---------------------------------------------------------------------------------------------
function openSyncLog(id)
{
    stopSSEPoll();
    if (typeof sseSource != 'undefined' && sseSource) {
        sseSource.close();
        sseSource = '';
    }
    pageLoadingStart();
    $.ajax({
        url: BASE_URL + 'ajax/sync.php',
        type: 'post',
        data: '&event=logViewer&id=' + encodeURIComponent(id),
        success: function (response) {
            dialogOpen({
                id: 'sync-log-dialog',
                title: translate('logTitle', [id + '.log']),
                body: response,
                footer: false,
                size: 'xxl',
                onOpen: function () {
                    pageLoadingStop();
                    syncLogFollow = true;
                    $('#sync-log-dialog .modal-dialog').removeClass('modal-dialog-scrollable');
                    $('#sync-log-dialog .modal-body').addClass('sync-log-modal-body');
                    mountSyncLogFind();
                    resetSyncLogFind();
                    scrollSyncLogToBottom();
                    $('#sync-log-dialog').one('shown.bs.modal', function () {
                        scrollSyncLogToBottom();
                    });
                    setTimeout(function () {
                        $('#sync-log-dialog #syncLogMainPane').off('scroll.syncLog wheel.syncLog');
                        $('#sync-log-dialog #syncLogMatchesPane').off('scroll.syncLogMatches wheel.syncLogMatches');
                        $('#sync-log-dialog #syncLogMainPane').on('scroll.syncLog', function () {
                            if (syncLogAtBottom()) {
                                syncLogFollow = true;
                                loadSyncLogNewer();
                            } else {
                                syncLogFollow = false;
                                clearSyncLogPinTimers();
                                if ($(this).scrollTop() <= 40) {
                                    loadSyncLogOlder();
                                }
                            }
                        });
                        $('#sync-log-dialog #syncLogMainPane').on('wheel.syncLog', function (event) {
                            if ($('#sync-log-dialog #syncLogLines').attr('data-status') == 'running') {
                                return;
                            }
                            if (event.originalEvent.deltaY < 0 && $(this).scrollTop() <= 0) {
                                syncLogFollow = false;
                                clearSyncLogPinTimers();
                                loadSyncLogOlder();
                            }
                        });
                        $('#sync-log-dialog #syncLogMatchesPane').on('scroll.syncLogMatches', function () {
                            if ($(this).scrollTop() <= 40) {
                                loadSyncLogMatchesOlder();
                            } else if (syncLogPaneAtBottom($(this))) {
                                loadSyncLogMatchesNewer();
                            }
                        });
                        $('#sync-log-dialog #syncLogMatchesPane').on('wheel.syncLogMatches', function (event) {
                            if (event.originalEvent.deltaY < 0 && $(this).scrollTop() <= 0) {
                                loadSyncLogMatchesOlder();
                            }
                        });
                        scrollSyncLogToBottom();
                    }, 200);
                    let status = $('#sync-log-dialog #syncLogLines').attr('data-status');
                    if (status == 'running' || status == 'queued') {
                        initializeSSE(id);
                    }
                },
                onClose: function () {
                    resetSyncLogFind();
                    closeSyncLogSource();
                }
            });
        },
        error: function () {
            pageLoadingStop();
            toast(translate('sync'), translate('unableToLoadPage'), 'error');
        }
    });
}
// ---------------------------------------------------------------------------------------------
function openSyncLogStream(id)
{
    initializeSSE(id);
}
// ---------------------------------------------------------------------------------------------
function deleteLog(id)
{
    if (!id) {
        return;
    }

    if (!confirm(translate('deleteSyncHistoryConfirm'))) {
        return;
    }

    pageLoadingStart();
    $.ajax({
        url: BASE_URL + 'ajax/sync.php',
        type: 'post',
        dataType: 'json',
        data: '&event=delete&id=' + encodeURIComponent(id),
        success: function (response) {
            pageLoadingStop();
            if (!response || response.error) {
                toast(translate('sync'), (response && response.message) || translate('couldNotSaveSettings'), 'error');
                return;
            }
            if ($('#sync-log-dialog #syncLogLines').attr('data-id') == id) {
                dialogClose('sync-log-dialog');
            }
            toast(translate('sync'), response.message || translate('removed'), 'success');
            refreshSyncHistory();
        },
        error: function () {
            pageLoadingStop();
            toast(translate('sync'), translate('couldNotSaveSettings'), 'error');
        }
    });
}
// ---------------------------------------------------------------------------------------------
function deleteAllSyncHistory()
{
    if (!confirm(translate('clearSyncHistoryConfirm'))) {
        return;
    }

    pageLoadingStart();
    $.ajax({
        url: BASE_URL + 'ajax/sync.php',
        type: 'post',
        dataType: 'json',
        data: '&event=deleteAll',
        success: function (response) {
            pageLoadingStop();
            if (!response || response.error) {
                toast(translate('sync'), (response && response.message) || translate('couldNotSaveSettings'), 'error');
                return;
            }
            if ($('#sync-log-dialog').length) {
                dialogClose('sync-log-dialog');
            }
            toast(translate('sync'), response.message || translate('syncHistoryCleared'), 'success');
            refreshSyncHistory();
        },
        error: function () {
            pageLoadingStop();
            toast(translate('sync'), translate('couldNotSaveSettings'), 'error');
        }
    });
}
// ---------------------------------------------------------------------------------------------
function requeueSync(id)
{
    if (!id) {
        return;
    }

    pageLoadingStart();
    $.ajax({
        url: BASE_URL + 'ajax/sync.php',
        type: 'post',
        dataType: 'json',
        data: '&event=requeue&id=' + encodeURIComponent(id),
        success: function (response) {
            pageLoadingStop();
            if (!response || response.error) {
                toast(translate('sync'), (response && response.message) || translate('couldNotQueueSync'), 'error');
                return;
            }
            if ($('#sync-log-dialog #syncLogLines').attr('data-id') == id) {
                dialogClose('sync-log-dialog');
            }
            toast(translate('sync'), response.message || translate('syncQueued'), 'success');
            refreshSyncHistory();
        },
        error: function () {
            pageLoadingStop();
            toast(translate('sync'), translate('couldNotQueueSync'), 'error');
        }
    });
}
// ---------------------------------------------------------------------------------------------
function cancelSync(id)
{
    if (!id) {
        return;
    }

    pageLoadingStart();
    $.ajax({
        url: BASE_URL + 'ajax/sync.php',
        type: 'post',
        dataType: 'json',
        data: '&event=cancel&id=' + encodeURIComponent(id),
        success: function (response) {
            pageLoadingStop();
            if (!response || response.error) {
                toast(translate('sync'), (response && response.message) || translate('couldNotSaveSettings'), 'error');
                return;
            }
            toast(translate('sync'), response.message || translate('syncCancelled'), 'success');
            refreshSyncHistory();
        },
        error: function () {
            pageLoadingStop();
            toast(translate('sync'), translate('couldNotSaveSettings'), 'error');
        }
    });
}
// ---------------------------------------------------------------------------------------------
function loadSyncLogOlder()
{
    if (syncLogLoading || !$('#syncLogLines').length) {
        return;
    }
    if ($('#syncLogLines').attr('data-done') == '1') {
        return;
    }
    if ($('#syncLogLines').attr('data-status') == 'running') {
        return;
    }

    let first  = typeof $('#syncLogLines').attr('data-before') == 'undefined';
    let before = first ? -1 : $('#syncLogLines').attr('data-before');
    syncLogLoading = true;
    $.ajax({
        url: BASE_URL + 'ajax/sync.php',
        type: 'post',
        dataType: 'json',
        data: '&event=log&id=' + encodeURIComponent($('#syncLogLines').attr('data-id')) + '&before=' + encodeURIComponent(before),
        success: function (response) {
            syncLogLoading = false;
            if (!response || response.error) {
                return;
            }

            let top    = $('#sync-log-dialog #syncLogMainPane').scrollTop() || 0;
            let height = $('#sync-log-dialog #syncLogMainPane').prop('scrollHeight') || 0;

            if (first) {
                $('#sync-log-dialog #syncLogLines').html(response.html || '');
                if (typeof response.end != 'undefined') {
                    $('#sync-log-dialog #syncLogLines').attr('data-end', response.end);
                }
            } else {
                $('#sync-log-dialog #syncLogLines').prepend(response.html || '');
            }
            $('#sync-log-dialog #syncLogLines').attr('data-before', response.before);
            applySyncLogWindowFlags($('#sync-log-dialog #syncLogLines'), response, first ? 'both' : 'before');

            if (first && syncLogFollow) {
                scrollSyncLogToBottom();
                return;
            }

            if ($('#sync-log-dialog #syncLogMainPane').length) {
                $('#sync-log-dialog #syncLogMainPane').scrollTop($('#sync-log-dialog #syncLogMainPane').prop('scrollHeight') - height + top);
            }
        },
        error: function () {
            syncLogLoading = false;
        }
    });
}
// ---------------------------------------------------------------------------------------------
function loadSyncLogNewer()
{
    if (syncLogLoading || !$('#syncLogLines').length) {
        return;
    }
    if ($('#syncLogLines').attr('data-tail') == '1') {
        return;
    }
    if ($('#syncLogLines').attr('data-status') == 'running') {
        return;
    }

    let after = $('#syncLogLines').attr('data-end');
    if (typeof after == 'undefined' || after == '') {
        return;
    }

    syncLogLoading = true;
    $.ajax({
        url: BASE_URL + 'ajax/sync.php',
        type: 'post',
        dataType: 'json',
        data: '&event=log&id=' + encodeURIComponent($('#syncLogLines').attr('data-id')) + '&after=' + encodeURIComponent(after),
        success: function (response) {
            syncLogLoading = false;
            if (!response || response.error || !response.html) {
                if (response && response.tail) {
                    applySyncLogWindowFlags($('#sync-log-dialog #syncLogLines'), response, 'after');
                }
                return;
            }

            $('#sync-log-dialog #syncLogLines').append(response.html || '');
            if (typeof response.end != 'undefined') {
                $('#sync-log-dialog #syncLogLines').attr('data-end', response.end);
            }
            applySyncLogWindowFlags($('#sync-log-dialog #syncLogLines'), response, 'after');
            if ($('#sync-log-dialog #syncLogMainPane').length && syncLogAtBottom() && $('#sync-log-dialog #syncLogLines').attr('data-tail') != '1') {
                loadSyncLogNewer();
            }
        },
        error: function () {
            syncLogLoading = false;
        }
    });
}
// ---------------------------------------------------------------------------------------------
function applySyncLogWindowFlags(box, response, mode)
{
    if (!box || !box.length || !response) {
        return;
    }
    mode = mode || 'both';
    if (mode == 'both' || mode == 'before') {
        if (response.done) {
            box.attr('data-done', '1');
        } else if (typeof response.done != 'undefined') {
            box.removeAttr('data-done');
        }
    }
    if (mode == 'both' || mode == 'after') {
        if (response.tail) {
            box.attr('data-tail', '1');
        } else if (typeof response.tail != 'undefined') {
            box.removeAttr('data-tail');
        }
    }
}
// ---------------------------------------------------------------------------------------------
function loadSyncLogChunk()
{
    loadSyncLogOlder();
}
// ---------------------------------------------------------------------------------------------
function mountSyncLogFind()
{
    $('#sync-log-dialog .modal-header').addClass('sync-log-modal-header');
    $('#sync-log-dialog .modal-header').find('.sync-log-find, .sync-log-copy').remove();
    $('#sync-log-dialog .modal-header').find('.modal-title').after(
        $('<i class="fas fa-copy text-primary sync-log-copy" style="cursor: pointer;" title="' + translate('copy') + '" onclick="clipboard(\'syncLogLines\', \'log\');"></i>')
    );
    if (!$('#sync-log-dialog #syncLogFindTemplate').length) {
        return;
    }
    $('#sync-log-dialog .modal-header').find('.sync-log-copy').after($('#sync-log-dialog #syncLogFindTemplate').html());
}
// ---------------------------------------------------------------------------------------------
function resetSyncLogFind()
{
    if (syncLogFindState.timer) {
        clearTimeout(syncLogFindState.timer);
    }
    syncLogFindState = {
        query: '',
        total: 0,
        loading: false,
        timer: null
    };
    syncLogMatchesLoading = false;
    $('#sync-log-dialog #syncLogFindCount').text('');
    $('#sync-log-dialog #syncLogSplit').removeClass('is-find');
    $('#sync-log-dialog #syncLogMatchesPane').addClass('d-none');
    $('#sync-log-dialog #syncLogMatches').empty().removeAttr('data-before data-end data-done data-tail');
}
// ---------------------------------------------------------------------------------------------
function submitSyncLogFind()
{
    let query = String($('#sync-log-dialog #syncLogFind').val() || '').trim();
    runSyncLogFind(query);
}
// ---------------------------------------------------------------------------------------------
$(document).off('input.syncLogFind keydown.syncLogFind click.syncLogFind');
// ---------------------------------------------------------------------------------------------
$(document).on('keydown.syncLogFind', '#sync-log-dialog #syncLogFind', function (event) {
    if (event.key == 'Enter') {
        event.preventDefault();
        submitSyncLogFind();
    } else if (event.key == 'Escape') {
        $(this).val('');
        resetSyncLogFind();
    }
});
// ---------------------------------------------------------------------------------------------
$(document).on('click.syncLogFind', '#sync-log-dialog #syncLogFindSearch', function (event) {
    event.preventDefault();
    submitSyncLogFind();
});
// ---------------------------------------------------------------------------------------------
$(document).on('click.syncLogFind', '#sync-log-dialog #syncLogFindClear', function (event) {
    event.preventDefault();
    $('#sync-log-dialog #syncLogFind').val('').trigger('focus');
    resetSyncLogFind();
});
// ---------------------------------------------------------------------------------------------
$(document).on('keydown.syncLogFind', function (event) {
    if (!$('#sync-log-dialog').hasClass('show')) {
        return;
    }
    if ((event.ctrlKey || event.metaKey) && String(event.key).toLowerCase() == 'f') {
        event.preventDefault();
        $('#sync-log-dialog #syncLogFind').trigger('focus').trigger('select');
    }
});
// ---------------------------------------------------------------------------------------------
function updateSyncLogFindCount(total)
{
    if (!syncLogFindState.query) {
        $('#sync-log-dialog #syncLogFindCount').text('');
        return;
    }
    total = typeof total == 'undefined' ? syncLogFindState.total : total;
    if (!total) {
        $('#sync-log-dialog #syncLogFindCount').text(translate('findNoMatches'));
        return;
    }
    $('#sync-log-dialog #syncLogFindCount').text(translate('findMatchCount', [total]));
}
// ---------------------------------------------------------------------------------------------
function applySyncLogMatchesFlags(box, response, mode)
{
    if (!box || !box.length || !response) {
        return;
    }
    mode = mode || 'both';
    if (mode == 'both' || mode == 'before') {
        if (response.done) {
            box.attr('data-done', '1');
        } else if (typeof response.done != 'undefined') {
            box.removeAttr('data-done');
        }
    }
    if (mode == 'both' || mode == 'after') {
        if (response.tail) {
            box.attr('data-tail', '1');
        } else if (typeof response.tail != 'undefined') {
            box.removeAttr('data-tail');
        }
    }
}
// ---------------------------------------------------------------------------------------------
function runSyncLogFind(query)
{
    if (!$('#sync-log-dialog #syncLogLines').length || !$('#sync-log-dialog #syncLogFind').length) {
        return;
    }
    query = String(query || '').trim();
    if (query == '') {
        resetSyncLogFind();
        return;
    }

    let id = $('#sync-log-dialog #syncLogFind').attr('data-id') || $('#sync-log-dialog #syncLogLines').attr('data-id') || '';
    syncLogFindState.query = query;
    syncLogFindState.total = 0;
    $('#sync-log-dialog #syncLogFindCount').text('...');
    $('#sync-log-dialog #syncLogSplit').addClass('is-find');
    $('#sync-log-dialog #syncLogMatchesPane').removeClass('d-none');
    $('#sync-log-dialog #syncLogMatches').empty().removeAttr('data-before data-end data-done data-tail');

    loadSyncLogMatchesChunk(id, query, -1, true);
}
// ---------------------------------------------------------------------------------------------
function loadSyncLogMatchesOlder()
{
    if (!$('#sync-log-dialog #syncLogMatches').length || $('#sync-log-dialog #syncLogMatches').attr('data-done') == '1' || !syncLogFindState.query) {
        return;
    }
    let before = $('#sync-log-dialog #syncLogMatches').attr('data-before');
    if (typeof before == 'undefined') {
        return;
    }
    let id = $('#sync-log-dialog #syncLogFind').attr('data-id') || $('#syncLogLines').attr('data-id') || '';
    loadSyncLogMatchesChunk(id, syncLogFindState.query, before, false);
}
// ---------------------------------------------------------------------------------------------
function loadSyncLogMatchesNewer()
{
    if (!$('#sync-log-dialog #syncLogMatches').length || $('#sync-log-dialog #syncLogMatches').attr('data-tail') == '1' || !syncLogFindState.query) {
        return;
    }
    let after = $('#sync-log-dialog #syncLogMatches').attr('data-end');
    if (typeof after == 'undefined' || after == '') {
        return;
    }
    let id = $('#sync-log-dialog #syncLogFind').attr('data-id') || $('#syncLogLines').attr('data-id') || '';
    loadSyncLogMatchesChunk(id, syncLogFindState.query, after, false, 'after');
}
// ---------------------------------------------------------------------------------------------
function loadSyncLogMatchesChunk(id, query, cursor, reset, direction)
{
    if (syncLogMatchesLoading) {
        return;
    }
    direction = direction || 'before';
    syncLogMatchesLoading = true;

    let data = {
        event: 'logSearch',
        id: id,
        query: query
    };
    if (direction == 'after') {
        data.after = cursor;
    } else {
        data.before = cursor;
    }

    $.ajax({
        url: BASE_URL + 'ajax/sync.php',
        type: 'post',
        dataType: 'json',
        data: data,
        success: function (response) {
            syncLogMatchesLoading = false;
            if (String($('#sync-log-dialog #syncLogFind').val() || '').trim() != query) {
                return;
            }
            if (!response || response.error) {
                syncLogFindState.total = 0;
                updateSyncLogFindCount(0);
                return;
            }

            let top = $('#sync-log-dialog #syncLogMatchesPane').scrollTop() || 0;
            let height = $('#sync-log-dialog #syncLogMatchesPane').prop('scrollHeight') || 0;
            let total = parseInt(response.total, 10);
            if (isNaN(total)) {
                total = 0;
            }

            syncLogFindState.query = query;
            syncLogFindState.total = total;
            updateSyncLogFindCount(total);

            if (reset) {
                $('#sync-log-dialog #syncLogMatches').html(response.html || '');
                $('#sync-log-dialog #syncLogMatches').attr('data-before', response.before);
                $('#sync-log-dialog #syncLogMatches').attr('data-end', response.end);
                applySyncLogMatchesFlags($('#sync-log-dialog #syncLogMatches'), response, 'both');
                scrollSyncLogMatchesToBottom();
                return;
            }

            if (direction == 'after') {
                if (response.html) {
                    $('#sync-log-dialog #syncLogMatches').append(response.html);
                }
                if (typeof response.end != 'undefined') {
                    $('#sync-log-dialog #syncLogMatches').attr('data-end', response.end);
                }
                applySyncLogMatchesFlags($('#sync-log-dialog #syncLogMatches'), response, 'after');
                if ($('#sync-log-dialog #syncLogMatchesPane').length && syncLogPaneAtBottom($('#sync-log-dialog #syncLogMatchesPane')) && $('#sync-log-dialog #syncLogMatches').attr('data-tail') != '1') {
                    loadSyncLogMatchesNewer();
                }
                return;
            }

            $('#sync-log-dialog #syncLogMatches').prepend(response.html || '');
            $('#sync-log-dialog #syncLogMatches').attr('data-before', response.before);
            applySyncLogMatchesFlags($('#sync-log-dialog #syncLogMatches'), response, 'before');
            if ($('#sync-log-dialog #syncLogMatchesPane').length) {
                $('#sync-log-dialog #syncLogMatchesPane').scrollTop($('#sync-log-dialog #syncLogMatchesPane').prop('scrollHeight') - height + top);
            }
        },
        error: function () {
            syncLogMatchesLoading = false;
            if (String($('#sync-log-dialog #syncLogFind').val() || '').trim() != query) {
                return;
            }
            syncLogFindState.total = 0;
            updateSyncLogFindCount(0);
        }
    });
}
// ---------------------------------------------------------------------------------------------
function toggleSyncHistoryList(el)
{
    if (!$(el).closest('.sync-history-details').find('.sync-history-list').length) {
        return;
    }
    $(el).closest('.sync-history-details').find('.sync-history-list').prop('hidden', !$(el).closest('.sync-history-details').find('.sync-history-list').prop('hidden'));
}
// ---------------------------------------------------------------------------------------------
$(document).on('click', '.sync-history-toggle', function (event) {
    event.preventDefault();
    event.stopPropagation();
    event.stopImmediatePropagation();
    toggleSyncHistoryList(this);
    return false;
});
// ---------------------------------------------------------------------------------------------
$(document).on('click', '.sync-job-row', function (event) {
    if ($(this).attr('data-status') == 'queued') {
        return;
    }
    if ($(event.target).closest('.sync-history-toggle, .sync-history-list').length) {
        return;
    }
    openSyncLog($(this).attr('data-id'));
});
// ---------------------------------------------------------------------------------------------
$(document).on('page:loaded', function (event, pageKey) {
    closeSyncLogSource();
    if (pageKey == 'sync') {
        initSyncHistoryTable();
    }
});
