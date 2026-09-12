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
function saveSyncSettings()
{
    let payload = '&event=saveSyncSettings';
    payload += '&syncParityAutoUsers=' + ($('#syncParityAutoUsers').prop('checked') ? '1' : '');
    payload += '&syncParityAutoLibraries=' + ($('#syncParityAutoLibraries').prop('checked') ? '1' : '');
    payload += '&syncLibraryAutoMeta=' + ($('#syncLibraryAutoMeta').prop('checked') ? '1' : '');
    payload += '&syncHistoryNewUsers=' + ($('#syncHistoryNewUsers').prop('checked') ? '1' : '');
    payload += '&syncHistoryNewLibraries=' + ($('#syncHistoryNewLibraries').prop('checked') ? '1' : '');

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
function saveLoginSettings()
{
    let payload = '&event=saveLoginSettings';
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
function runBackup()
{
    let table   = $('#backupList tbody');
    let now     = new Date();
    let hours   = now.getHours();
    let ampm    = hours >= 12 ? 'PM' : 'AM';
    hours       = hours % 12 || 12;
    let date    = String(now.getMonth() + 1).padStart(2, '0') + '/' + String(now.getDate()).padStart(2, '0') + '/' + now.getFullYear();
    let time    = hours + ':' + String(now.getMinutes()).padStart(2, '0') + ':' + String(now.getSeconds()).padStart(2, '0') + ' ' + ampm;
    let running = '<i class="fas fa-spinner fa-spin me-1"></i>' + translate('running');

    table.find('.backup-empty').remove();
    table.prepend(
        '<tr class="backup-running-row">' +
            '<td>' + date + '</td>' +
            '<td>' + time + '</td>' +
            '<td>' + translate('manual') + '</td>' +
            '<td></td>' +
            '<td class="backup-actions">' + running + '</td>' +
        '</tr>'
    );

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
}
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
function downloadBackup(folder, run)
{
    window.location = BASE_URL + 'ajax/settings.php?event=downloadBackup&folder=' + encodeURIComponent(folder) + '&run=' + encodeURIComponent(run);
}
function resetWatchHistory()
{
    let users = [];
    $('.reset-history-user:checked').each(function () {
        users.push($(this).val());
    });
    if (!users.length) {
        toast(translate('reset'), translate('missingSyncUsers'), 'error');
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
                toast(translate('reset'), (response && response.message) || translate('couldNotSaveSettings'), 'error');
                return;
            }
            toast(translate('reset'), response.message || translate('resetHistoryComplete'), 'success');
            loadResetUsers();
        },
        error: function () {
            pageLoadingStop();
            toast(translate('reset'), translate('couldNotSaveSettings'), 'error');
        }
    });
}
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
