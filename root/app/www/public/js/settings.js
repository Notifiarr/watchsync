function saveBackupSettings()
{
    let payload = '&event=saveBackupSettings';
    payload += '&backupTime=' + encodeURIComponent($('#backupTime').val() || '');
    payload += '&backupKeep=' + encodeURIComponent($('#backupKeep').val() || '');

    pageLoadingStart();
    $.ajax({
        url: BASE_URL + 'ajax/settings.php',
        type: 'post',
        dataType: 'json',
        data: payload,
        success: function (response) {
            pageLoadingStop();

            if (!response || response.error) {
                toast(translate('settings'), (response && response.message) || translate('couldNotSaveSettings'), 'error');
                return;
            }
            toast(translate('settings'), response.message || translate('saved'), 'success');
        },
        error: function () {
            pageLoadingStop();
            toast(translate('settings'), translate('couldNotSaveSettings'), 'error');
        }
    });
}
// ---------------------------------------------------------------------------------------------
function syncIntervalSeconds(hoursId, minutesId)
{
    let hours = parseInt($(hoursId).val(), 10) || 0;
    let minutes = parseInt($(minutesId).val(), 10) || 0;
    if (hours >= 24) {
        hours = 24;
        minutes = 0;
    }
    if (hours == 0 && minutes == 0) {
        minutes = 15;
    }
    let seconds = (hours * 3600) + (minutes * 60);
    if (seconds < 900) {
        seconds = 900;
    }
    if (seconds > 86400) {
        seconds = 86400;
    }
    return seconds;
}
// ---------------------------------------------------------------------------------------------
function updateSyncIntervalMinutes(hoursEl)
{
    let minutes = $($(hoursEl).data('minutes'));
    if (!minutes.length) {
        return;
    }
    if (parseInt($(hoursEl).val(), 10) >= 24) {
        minutes.val('0').prop('disabled', true);
        return;
    }
    minutes.prop('disabled', false);
    if (parseInt($(hoursEl).val(), 10) == 0 && parseInt(minutes.val(), 10) == 0) {
        minutes.val('15');
    }
}
// ---------------------------------------------------------------------------------------------
$(document).on('change', '.sync-interval-hours', function () {
    updateSyncIntervalMinutes(this);
});
// ---------------------------------------------------------------------------------------------
function saveSyncSettings()
{
    let payload = '&event=saveSyncSettings';
    payload += '&syncParityAutoUsers=' + ($('#syncParityAutoUsers').prop('checked') ? '1' : '');
    payload += '&syncParityAutoLibraries=' + ($('#syncParityAutoLibraries').prop('checked') ? '1' : '');
    payload += '&syncLibraryAutoMeta=' + ($('#syncLibraryAutoMeta').prop('checked') ? '1' : '');
    payload += '&syncHistoryNewUsers=' + ($('#syncHistoryNewUsers').prop('checked') ? '1' : '');
    payload += '&syncHistoryNewLibraries=' + ($('#syncHistoryNewLibraries').prop('checked') ? '1' : '');
    payload += '&automaticParity=' + ($('#automaticParity').prop('checked') ? '1' : '');
    payload += '&automaticLibrary=' + ($('#automaticLibrary').prop('checked') ? '1' : '');
    payload += '&automaticHistory=' + ($('#automaticHistory').prop('checked') ? '1' : '');
    payload += '&automaticParityInterval=' + syncIntervalSeconds('#automaticParityHours', '#automaticParityMinutes');
    payload += '&automaticLibraryInterval=' + syncIntervalSeconds('#automaticLibraryHours', '#automaticLibraryMinutes');
    payload += '&automaticHistoryInterval=' + syncIntervalSeconds('#automaticHistoryHours', '#automaticHistoryMinutes');

    pageLoadingStart();
    $.ajax({
        url: BASE_URL + 'ajax/settings.php',
        type: 'post',
        dataType: 'json',
        data: payload,
        success: function (response) {
            pageLoadingStop();

            if (!response || response.error) {
                toast(translate('settings'), (response && response.message) || translate('couldNotSaveSettings'), 'error');
                return;
            }
            toast(translate('settings'), response.message || translate('saved'), 'success');
        },
        error: function () {
            pageLoadingStop();
            toast(translate('settings'), translate('couldNotSaveSettings'), 'error');
        }
    });
}
// ---------------------------------------------------------------------------------------------
function saveLogSettings()
{
    let payload = '&event=saveLogSettings';
    payload += '&logLevel=' + encodeURIComponent($('#logLevel').val() || 'info');
    payload += '&cronLogLength=' + encodeURIComponent($('#cronLogLength').val() || '1');
    payload += '&systemLogLength=' + encodeURIComponent($('#systemLogLength').val() || '1');
    payload += '&webhookLogLength=' + encodeURIComponent($('#webhookLogLength').val() || '1');

    pageLoadingStart();
    $.ajax({
        url: BASE_URL + 'ajax/settings.php',
        type: 'post',
        dataType: 'json',
        data: payload,
        success: function (response) {
            pageLoadingStop();

            if (!response || response.error) {
                toast(translate('settings'), (response && response.message) || translate('couldNotSaveSettings'), 'error');
                return;
            }
            toast(translate('settings'), response.message || translate('saved'), 'success');
        },
        error: function () {
            pageLoadingStop();
            toast(translate('settings'), translate('couldNotSaveSettings'), 'error');
        }
    });
}
// ---------------------------------------------------------------------------------------------
function toggleLoginTrustSettings()
{
    $('#loginTrustSettings').toggle(String($('#loginMode').val() || '') == 'bypass');
}
// ---------------------------------------------------------------------------------------------
function saveLoginSettings()
{
    let payload = '&event=saveLoginSettings';
    payload += '&loginMode=' + encodeURIComponent($('#loginMode').val() || 'required');
    payload += '&loginAllowLoopback=' + ($('#loginAllowLoopback').is(':checked') ? '1' : '');
    payload += '&loginAllowPrivate=' + ($('#loginAllowPrivate').is(':checked') ? '1' : '');
    payload += '&loginUpstreams=' + encodeURIComponent($('#loginUpstreams').val() || '');
    payload += '&loginAuthHeader=' + encodeURIComponent($('#loginAuthHeader').val() || '');
    payload += '&username=' + encodeURIComponent($('#userSettingsUsername').val() || '');
    payload += '&current_password=' + encodeURIComponent($('#userSettingsCurrentPassword').val() || '');
    payload += '&new_password=' + encodeURIComponent($('#userSettingsNewPassword').val() || '');
    payload += '&new_password_confirm=' + encodeURIComponent($('#userSettingsNewPasswordConfirm').val() || '');

    pageLoadingStart();
    $.ajax({
        url: BASE_URL + 'ajax/settings.php',
        type: 'post',
        dataType: 'json',
        data: payload,
        success: function (response) {
            pageLoadingStop();

            if (!response || response.error) {
                toast(translate('settings'), (response && response.message) || translate('couldNotSaveSettings'), 'error');
                return;
            }
            toast(translate('settings'), response.message || translate('saved'), 'success');
            $('#userSettingsCurrentPassword').val('');
            $('#userSettingsNewPassword').val('');
            $('#userSettingsNewPasswordConfirm').val('');
        },
        error: function () {
            pageLoadingStop();
            toast(translate('settings'), translate('couldNotSaveSettings'), 'error');
        }
    });
}
// ---------------------------------------------------------------------------------------------
function runBackup()
{
    $.ajax({
        url: BASE_URL + 'ajax/settings.php',
        type: 'post',
        dataType: 'json',
        data: '&event=backupRunningRow',
        success: function (response) {
            if (response && !response.error && response.html) {
                $('#backupList tbody').find('.backup-empty').remove();
                $('#backupList tbody').prepend(response.html);
            }
            $.ajax({
                url: BASE_URL + 'ajax/settings.php',
                type: 'post',
                dataType: 'json',
                data: '&event=runBackup',
                success: function (response) {
                    if (!response || response.error) {
                        toast(translate('backups'), (response && response.message) || translate('couldNotRunBackup'), 'error');
                        loadBackupList();
                        return;
                    }
                    toast(translate('backups'), response.message || translate('backupComplete'), 'success');
                    loadBackupList();
                },
                error: function () {
                    toast(translate('backups'), translate('couldNotRunBackup'), 'error');
                    loadBackupList();
                }
            });
        },
        error: function () {
            toast(translate('backups'), translate('couldNotRunBackup'), 'error');
        }
    });
}
// ---------------------------------------------------------------------------------------------
function loadBackupList()
{
    $.ajax({
        url: BASE_URL + 'ajax/settings.php',
        type: 'post',
        dataType: 'json',
        data: '&event=listBackups',
        success: function (response) {
            if (!response || response.error) {
                return;
            }
            $('#backupList').html(response.html);
        }
    });
}
// ---------------------------------------------------------------------------------------------
function downloadBackup(folder, run)
{
    window.location = BASE_URL + 'ajax/settings.php?event=downloadBackup&folder=' + encodeURIComponent(folder) + '&run=' + encodeURIComponent(run);
}
// ---------------------------------------------------------------------------------------------
function resetWatchHistory()
{
    let users = [];
    $('.reset-history-user:checked').each(function () {
        users.push($(this).val());
    });
    if (!users.length) {
        toast(translate('history'), translate('missingSyncUsers'), 'error');
        return;
    }
    if (!confirm(translate('resetHistoryConfirm'))) {
        return;
    }

    pageLoadingStart();
    $.ajax({
        url: BASE_URL + 'ajax/settings.php',
        type: 'post',
        dataType: 'json',
        data: '&event=resetHistory&users=' + encodeURIComponent(users.join(',')),
        success: function (response) {
            pageLoadingStop();

            if (!response || response.error) {
                toast(translate('history'), (response && response.message) || translate('couldNotSaveSettings'), 'error');
                return;
            }
            toast(translate('history'), response.message || translate('resetHistoryComplete'), 'success');
            loadResetUsers();
        },
        error: function () {
            pageLoadingStop();
            toast(translate('history'), translate('couldNotSaveSettings'), 'error');
        }
    });
}
// ---------------------------------------------------------------------------------------------
function loadResetUsers()
{
    $.ajax({
        url: BASE_URL + 'ajax/settings.php',
        type: 'post',
        dataType: 'json',
        data: '&event=listResetUsers',
        success: function (response) {
            if (!response || response.error) {
                return;
            }
            $('#resetUserList').html(response.html);
        }
    });
}
// ---------------------------------------------------------------------------------------------
function deleteLocalLibraries()
{
    let libraries = [];
    $('.reset-local-library:checked').each(function () {
        libraries.push($(this).val());
    });
    if (!libraries.length) {
        toast(translate('library'), translate('missingLocalLibraries'), 'error');
        return;
    }
    if (!confirm(translate('deleteLocalLibraryConfirm'))) {
        return;
    }

    pageLoadingStart();
    $.ajax({
        url: BASE_URL + 'ajax/settings.php',
        type: 'post',
        dataType: 'json',
        data: '&event=deleteLocalLibraries&libraries=' + encodeURIComponent(libraries.join(',')),
        success: function (response) {
            pageLoadingStop();

            if (!response || response.error) {
                toast(translate('library'), (response && response.message) || translate('couldNotSaveSettings'), 'error');
                return;
            }
            toast(translate('library'), response.message || translate('deleteLocalLibraryComplete'), 'success');
            loadResetLibraries();
        },
        error: function () {
            pageLoadingStop();
            toast(translate('library'), translate('couldNotSaveSettings'), 'error');
        }
    });
}
// ---------------------------------------------------------------------------------------------
function loadResetLibraries()
{
    $.ajax({
        url: BASE_URL + 'ajax/settings.php',
        type: 'post',
        dataType: 'json',
        data: '&event=listResetLibraries',
        success: function (response) {
            if (!response || response.error) {
                return;
            }
            $('#resetLibraryList').html(response.html);
        }
    });
}
// ---------------------------------------------------------------------------------------------
function deleteBackup(folder, run)
{
    if (!confirm(translate('deleteBackupConfirm'))) {
        return;
    }

    $.ajax({
        url: BASE_URL + 'ajax/settings.php',
        type: 'post',
        dataType: 'json',
        data: '&event=deleteBackup&folder=' + encodeURIComponent(folder) + '&run=' + encodeURIComponent(run),
        success: function (response) {
            if (!response || response.error) {
                toast(translate('backups'), (response && response.message) || translate('couldNotSaveSettings'), 'error');
                return;
            }
            toast(translate('backups'), response.message || translate('removed'), 'success');
            loadBackupList();
        },
        error: function () {
            toast(translate('backups'), translate('couldNotSaveSettings'), 'error');
        }
    });
}
// ---------------------------------------------------------------------------------------------
function restoreBackup(folder, run)
{
    if (!confirm(translate('restoreBackupConfirm'))) {
        return;
    }

    pageLoadingStart();
    $.ajax({
        url: BASE_URL + 'ajax/settings.php',
        type: 'post',
        dataType: 'json',
        data: '&event=restoreBackup&folder=' + encodeURIComponent(folder) + '&run=' + encodeURIComponent(run),
        success: function (response) {
            pageLoadingStop();

            if (!response || response.error) {
                toast(translate('backups'), (response && response.message) || translate('couldNotRestoreBackup'), 'error');
                return;
            }
            toast(translate('backups'), response.message || translate('backupRestored'), 'success');
            setTimeout(function () {
                window.location.reload();
            }, 800);
        },
        error: function () {
            pageLoadingStop();
            toast(translate('backups'), translate('couldNotRestoreBackup'), 'error');
        }
    });
}
// ---------------------------------------------------------------------------------------------
var browseDatabaseTable = '';
var browseDatabasePage = 1;
var browseDatabasePages = 1;
// ---------------------------------------------------------------------------------------------
function viewDatabaseBrowse(table, page)
{
    table = table || browseDatabaseTable || '';
    if (!table) {
        return;
    }

    page = parseInt(page, 10) || 1;
    if (page < 1) {
        page = 1;
    }

    browseDatabaseTable = table;
    pageLoadingStart();
    $.ajax({
        url: BASE_URL + 'ajax/settings.php',
        type: 'post',
        dataType: 'json',
        data: '&event=browseDatabase&view=table&table=' + encodeURIComponent(table) + '&page=' + encodeURIComponent(page),
        success: function (response) {
            pageLoadingStop();

            if (!response || response.error) {
                toast(translate('browse'), (response && response.message) || translate('browseDatabaseFailed'), 'error');
                return;
            }

            browseDatabasePage = parseInt(response.page, 10) || 1;
            browseDatabasePages = parseInt(response.pages, 10) || 1;

            if ($('#browse-database-dialog').length && $('#browse-database-dialog').is(':visible')) {
                $('#browse-database-dialog .modal-title').text(table);
                $('#browse-database-dialog .modal-body').html(response.html || '');
                return;
            }

            dialogOpen({
                id: 'browse-database-dialog',
                title: table,
                body: response.html || '',
                footer: false,
                size: 'xxl',
                onOpen: function () {
                    $('#browse-database-dialog .modal-dialog').removeClass('modal-dialog-scrollable');
                    $('#browse-database-dialog .modal-body').addClass('browse-database-modal-body');
                }
            });
        },
        error: function () {
            pageLoadingStop();
            toast(translate('browse'), translate('browseDatabaseFailed'), 'error');
        }
    });
}
// ---------------------------------------------------------------------------------------------
function viewDatabaseSchema(table)
{
    table = table || '';
    if (!table) {
        return;
    }

    pageLoadingStart();
    $.ajax({
        url: BASE_URL + 'ajax/settings.php',
        type: 'post',
        dataType: 'json',
        data: '&event=browseDatabase&view=schema&table=' + encodeURIComponent(table),
        success: function (response) {
            pageLoadingStop();

            if (!response || response.error) {
                toast(translate('schema'), (response && response.message) || translate('browseDatabaseSchemaFailed'), 'error');
                return;
            }

            dialogOpen({
                id: 'browse-database-schema-dialog',
                title: translate('schema') + ': ' + table,
                body: response.html || '',
                footer: false,
                size: 'lg'
            });
        },
        error: function () {
            pageLoadingStop();
            toast(translate('schema'), translate('browseDatabaseSchemaFailed'), 'error');
        }
    });
}
// ---------------------------------------------------------------------------------------------
function browseDatabasePrev()
{
    if (browseDatabasePage <= 1) {
        return;
    }
    viewDatabaseBrowse(browseDatabaseTable, browseDatabasePage - 1);
}
// ---------------------------------------------------------------------------------------------
function browseDatabaseNext()
{
    if (browseDatabasePage >= browseDatabasePages) {
        return;
    }
    viewDatabaseBrowse(browseDatabaseTable, browseDatabasePage + 1);
}
// ---------------------------------------------------------------------------------------------
function runDatabaseQuery()
{
    let sql = ($('#browseDatabaseQuery').val() || '').trim();
    if (!sql) {
        toast(translate('query'), translate('browseDatabaseQueryRequired'), 'error');
        return;
    }

    pageLoadingStart();
    $.ajax({
        url: BASE_URL + 'ajax/settings.php',
        type: 'post',
        dataType: 'json',
        data: '&event=browseDatabase&view=query&sql=' + encodeURIComponent(sql),
        success: function (response) {
            pageLoadingStop();

            if (!response || response.error) {
                toast(translate('query'), (response && response.message) || translate('browseDatabaseQueryFailed'), 'error');
                return;
            }

            $('#browseDatabaseQueryResult').html(response.html || '');
        },
        error: function () {
            pageLoadingStop();
            toast(translate('query'), translate('browseDatabaseQueryFailed'), 'error');
        }
    });
}
