var browseDatabaseTable = '';
var browseDatabasePage = 1;
var browseDatabasePages = 1;

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

            $('#browseDatabaseQuery').val('');
            $('#browseDatabaseQueryResult').html(response.html || '');
        },
        error: function () {
            pageLoadingStop();
            toast(translate('query'), translate('browseDatabaseQueryFailed'), 'error');
        }
    });
}
