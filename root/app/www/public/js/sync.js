var syncLogLoading = false;
var syncLogFollow = true;
var syncLogPinTimers = [];

function closeSyncLogSource()
{
    stopSSEPoll();
    initializeSSE();
}
// -------------------------------------------------------------------------------------------
function clearSyncLogPinTimers()
{
    for (let i = 0; i < syncLogPinTimers.length; i++) {
        clearTimeout(syncLogPinTimers[i]);
    }
    syncLogPinTimers = [];
}
// -------------------------------------------------------------------------------------------
function syncLogAtBottom()
{
    let body = document.querySelector('#sync-log-dialog .modal-body');
    if (!body) {
        return true;
    }

    return body.scrollTop + body.clientHeight >= body.scrollHeight - 40;
}
// -------------------------------------------------------------------------------------------
function scrollSyncLogToBottom()
{
    if (!syncLogFollow) {
        return;
    }

    let box = document.querySelector('#sync-log-dialog #syncLogLines');
    if (!box) {
        return;
    }

    function pin()
    {
        if (!syncLogFollow) {
            return;
        }
        let node = box;
        while (node) {
            if (node.scrollHeight > node.clientHeight) {
                node.scrollTop = node.scrollHeight;
            }
            if (node.id == 'sync-log-dialog') {
                break;
            }
            node = node.parentElement;
        }
        box.scrollTop = box.scrollHeight;
        let body = document.querySelector('#sync-log-dialog .modal-body');
        if (body) {
            body.scrollTop = body.scrollHeight;
        }
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
// -------------------------------------------------------------------------------------------
function openSyncLibrary()
{
    pageLoadingStart();
    $.ajax({
        url: BASE_URL + 'ajax/sync.php',
        type: 'post',
        data: '&event=libraryForm',
        success: function (html) {
            dialogOpen({
                id: 'sync-library-form',
                title: translate('library'),
                body: html,
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
// -------------------------------------------------------------------------------------------
function openSyncUsers()
{
    pageLoadingStart();
    $.ajax({
        url: BASE_URL + 'ajax/sync.php',
        type: 'post',
        data: '&event=usersForm',
        success: function (html) {
            dialogOpen({
                id: 'sync-users-form',
                title: translate('parity'),
                body: html,
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
// -------------------------------------------------------------------------------------------
function openSyncHistory()
{
    pageLoadingStart();
    $.ajax({
        url: BASE_URL + 'ajax/sync.php',
        type: 'post',
        data: '&event=historyForm',
        success: function (html) {
            dialogOpen({
                id: 'sync-history-form',
                title: translate('history'),
                body: html,
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
// -------------------------------------------------------------------------------------------
function startLibrarySync()
{
    let libraries = [];
    $('.sync-library:checked').each(function () {
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
// -------------------------------------------------------------------------------------------
function startHistorySync()
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
        data: '&event=startHistory&syncMode=' + encodeURIComponent($('#syncHistoryMode').val() || '') + '&users=' + encodeURIComponent(users.join(',')),
        success: function (response) {
            syncJobStarted(response, 'sync-history-form');
        },
        error: function () {
            pageLoadingStop();
            toast(translate('sync'), translate('couldNotSaveSettings'), 'error');
        }
    });
}
// -------------------------------------------------------------------------------------------
var paritySelected = '';

function parityClearSelect()
{
    $('.parity-item').removeClass('border border-primary rounded px-1');
    paritySelected = '';
}

function parityMasterId(item)
{
    let $item = $(item);
    if ($item.attr('data-master') === '1') {
        if ($item.attr('data-kind') === 'library') {
            return $item.attr('data-app') + ':' + $item.attr('data-id');
        }
        return $item.attr('data-id');
    }

    return $item.attr('data-link') || '';
}

function parityAddLinkIcon($item, color, appId, id)
{
    if (!color || !$item.length) {
        return;
    }
    $item.find('.parity-unlink').filter(function () {
        return String($(this).attr('data-app')) === String(appId) && String($(this).attr('data-id')) === String(id);
    }).remove();
    $item.find('.parity-name').after($('<i class="fas fa-link small parity-unlink ms-1"></i>').css('color', color).attr('title', translate('unlink')).attr('data-app', appId).attr('data-id', id));
    parityPlaceItem($item);
}

function parityPlaceItem($item)
{
    let linked = $item.find('.parity-unlink').length > 0;
    let $group = $item.closest('.col').find('.parity-group[data-linked="' + (linked ? '1' : '0') + '"]');
    if (!$group.length) {
        return;
    }

    let sort = parseInt($item.attr('data-sort') || '0', 10);
    let $before = $();
    $group.children('.parity-item').each(function () {
        if (this === $item[0]) {
            return;
        }
        if (parseInt($(this).attr('data-sort') || '0', 10) > sort) {
            $before = $(this);
            return false;
        }
    });
    if ($before.length) {
        $item.insertBefore($before);
        return;
    }
    $group.append($item);
}

function parityListenerCount(kind)
{
    let apps = {};
    $('.parity-item[data-kind="' + kind + '"][data-master="0"]').each(function () {
        apps[$(this).attr('data-app')] = true;
    });

    return Object.keys(apps).length;
}

function parityUpdateMasterCheckbox($item)
{
    let $box = $item.find('input[type="checkbox"]');
    if (!$box.length || $item.attr('data-master') !== '1') {
        return;
    }

    let kind = $item.attr('data-kind');
    let cls = kind === 'library' ? 'sync-parity-library' : 'sync-user';
    let listeners = parityListenerCount(kind);
    let linkedAll = listeners > 0 && $item.find('.parity-unlink').length >= listeners;
    $box.prop('disabled', linkedAll);
    $box.prop('checked', !linkedAll);
    $box.toggleClass(cls, !linkedAll);
}

function paritySelect(item)
{
    if (paritySelected && paritySelected === item) {
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
    if ($first.attr('data-kind') !== $second.attr('data-kind')) {
        toast(translate('parity'), translate('mustLinkMainSource'), 'error');
        return;
    }
    if ($first.attr('data-app') === $second.attr('data-app')) {
        $first.removeClass('border border-primary rounded px-1');
        $second.addClass('border border-primary rounded px-1');
        paritySelected = item;
        return;
    }

    let masterId = parityMasterId($first);
    let listener = $second;
    if ($first.attr('data-master') !== '1' && $second.attr('data-master') === '1') {
        masterId = parityMasterId($second);
        listener = $first;
    } else if ($first.attr('data-master') !== '1' && $second.attr('data-master') !== '1') {
        if (!masterId) {
            masterId = parityMasterId($second);
        }
        if ($second.attr('data-link') && $second.attr('data-link') === masterId) {
            listener = $first;
        }
    }

    if (!masterId || listener.attr('data-master') === '1') {
        toast(translate('parity'), translate('mustLinkMainSource'), 'error');
        return;
    }

    let kind = $first.attr('data-kind');
    let payload = '';
    if (kind === 'user') {
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
                if ($item.attr('data-master') === '1' && String(parityMasterId($item)) === String(group)) {
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

function parityUnlink(kind, appId, id, isMaster)
{
    let payload = '';
    if (kind === 'user') {
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
                return String($(this).attr('data-app')) === String(appId) && String($(this).attr('data-id')) === String(id);
            }).each(function () {
                $moved = $moved.add($(this).closest('.parity-item'));
            }).remove();
            $('.parity-item[data-kind="' + kind + '"][data-master="0"]').filter(function () {
                return String($(this).attr('data-app')) === String(appId) && String($(this).attr('data-id')) === String(id);
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

$(document).on('click', '.parity-unlink', function (event) {
    event.stopPropagation();
    let $icon = $(this);
    let $item = $icon.closest('.parity-item');
    parityUnlink($item.attr('data-kind'), $icon.attr('data-app'), $icon.attr('data-id'), 0);
});

$(document).on('click', '.parity-item', function (event) {
    if ($(event.target).closest('.form-check').length || $(event.target).closest('.parity-unlink').length) {
        return;
    }
    paritySelect(this);
});
// -------------------------------------------------------------------------------------------
function startParityLibrariesSync()
{
    let libraries = [];
    $('.sync-parity-library:checked').each(function () {
        libraries.push($(this).val());
    });

    pageLoadingStart();
    $.ajax({
        url: BASE_URL + 'ajax/sync.php',
        type: 'post',
        dataType: 'json',
        data: '&event=startLibraries&libraries=' + encodeURIComponent(libraries.join(',')),
        success: function (response) {
            syncJobStarted(response, 'sync-users-form');
        },
        error: function () {
            pageLoadingStop();
            toast(translate('sync'), translate('couldNotSaveSettings'), 'error');
        }
    });
}
// -------------------------------------------------------------------------------------------
function startUsersSync()
{
    let users = [];
    $('.sync-user:checked').each(function () {
        users.push($(this).val());
    });

    pageLoadingStart();
    $.ajax({
        url: BASE_URL + 'ajax/sync.php',
        type: 'post',
        dataType: 'json',
        data: '&event=startUsers&users=' + encodeURIComponent(users.join(',')),
        success: function (response) {
            syncJobStarted(response, 'sync-users-form');
        },
        error: function () {
            pageLoadingStop();
            toast(translate('sync'), translate('couldNotSaveSettings'), 'error');
        }
    });
}
// -------------------------------------------------------------------------------------------
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
    if (response.id && response.status === 'running') {
        openSyncLog(response.id);
    }
}
// -------------------------------------------------------------------------------------------
function refreshSyncHistory()
{
    $.ajax({
        url: BASE_URL + 'ajax/sync.php',
        type: 'post',
        data: '&event=history',
        success: function (html) {
            $('#syncHistory').html(html);
        }
    });
}
// -------------------------------------------------------------------------------------------
function openSyncLog(id)
{
    stopSSEPoll();
    if (typeof sseSource !== 'undefined' && sseSource) {
        sseSource.close();
        sseSource = '';
    }
    pageLoadingStart();
    $.ajax({
        url: BASE_URL + 'ajax/sync.php',
        type: 'post',
        data: '&event=logViewer&id=' + encodeURIComponent(id),
        success: function (html) {
            dialogOpen({
                id: 'sync-log-dialog',
                title: translate('logTitle', [id + '.log']),
                body: html,
                footer: false,
                size: 'xxl',
                onOpen: function () {
                    pageLoadingStop();
                    syncLogFollow = true;
                    scrollSyncLogToBottom();
                    $('#sync-log-dialog').one('shown.bs.modal', function () {
                        scrollSyncLogToBottom();
                    });
                    setTimeout(function () {
                        $('#sync-log-dialog .modal-body').on('scroll', function () {
                            if (syncLogAtBottom()) {
                                syncLogFollow = true;
                            } else {
                                syncLogFollow = false;
                                clearSyncLogPinTimers();
                                if (this.scrollTop <= 40) {
                                    loadSyncLogChunk();
                                }
                            }
                        });
                        $('#sync-log-dialog .modal-body').on('wheel', function (event) {
                            if ($('#sync-log-dialog #syncLogLines').attr('data-status') === 'running') {
                                return;
                            }
                            if (event.originalEvent.deltaY < 0 && this.scrollTop <= 0) {
                                syncLogFollow = false;
                                clearSyncLogPinTimers();
                                loadSyncLogChunk();
                            }
                        });
                    }, 200);
                    if ($('#sync-log-dialog #syncLogLines').attr('data-status') === 'running') {
                        initializeSSE(id);
                    }
                },
                onClose: function () {
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
// -------------------------------------------------------------------------------------------
function openSyncLogStream(id)
{
    initializeSSE(id);
}
// -------------------------------------------------------------------------------------------
function deleteLog(id)
{
    if (!id) {
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
            if ($('#sync-log-dialog #syncLogLines').attr('data-id') === id) {
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
// -------------------------------------------------------------------------------------------
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
// -------------------------------------------------------------------------------------------
function loadSyncLogChunk()
{
    if (syncLogLoading || !$('#syncLogLines').length) {
        return;
    }
    if ($('#syncLogLines').attr('data-done') === '1') {
        return;
    }
    if ($('#syncLogLines').attr('data-status') === 'running') {
        return;
    }

    let first  = typeof $('#syncLogLines').attr('data-before') === 'undefined';
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

            let box  = $('#sync-log-dialog #syncLogLines');
            let body = document.querySelector('#sync-log-dialog .modal-body');
            let top    = body ? body.scrollTop : 0;
            let height = body ? body.scrollHeight : 0;

            if (first) {
                box.html(response.html || '');
            } else {
                box.prepend(response.html || '');
            }
            box.attr('data-before', response.before);
            if (response.done) {
                box.attr('data-done', '1');
            }

            if (first && syncLogFollow) {
                scrollSyncLogToBottom();
                return;
            }

            if (body) {
                body.scrollTop = body.scrollHeight - height + top;
            }
        },
        error: function () {
            syncLogLoading = false;
        }
    });
}
// -------------------------------------------------------------------------------------------
function toggleSyncHistoryList(el)
{
    let wrap = el && el.closest ? el.closest('.sync-history-details') : null;
    let list = wrap ? wrap.querySelector('.sync-history-list') : null;
    if (!list) {
        return;
    }
    list.hidden = !list.hidden;
}

$(document).on('click', '.sync-history-toggle', function (event) {
    event.preventDefault();
    event.stopPropagation();
    event.stopImmediatePropagation();
    toggleSyncHistoryList(this);
    return false;
});

$(document).on('click', '.sync-job-row', function (event) {
    if ($(this).attr('data-status') === 'queued') {
        return;
    }
    if ($(event.target).closest('.sync-history-toggle, .sync-history-list').length) {
        return;
    }
    openSyncLog($(this).attr('data-id'));
});
// -------------------------------------------------------------------------------------------
$(document).on('page:loaded', function (event, pageKey) {
    closeSyncLogSource();
});
// -------------------------------------------------------------------------------------------
