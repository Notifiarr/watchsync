function openMediaAppForm(id)
{
    pageLoadingStart();
    $.ajax({
        url: BASE_URL + 'ajax/mediaApps.php',
        type: 'post',
        data: '&event=form&id=' + encodeURIComponent(id || 0),
        success: function (html) {
            dialogOpen({
                id: 'media-app-form',
                title: id ? translate('edit') : translate('addMediaApp'),
                body: html,
                footer: false,
                size: 'lg',
                onOpen: function () {
                    toggleMediaAppAuth();
                    pageLoadingStop();
                }
            });
        },
        error: function () {
            pageLoadingStop();
            toast(translate('mediaApps'), translate('unableToLoadPage'), 'error');
        }
    });
}
function toggleMediaAppAuth()
{
    let plex = $('#mediaAppPlatform').val() == $('#mediaAppPlatform').attr('data-plex');
    $('#mediaAppTokenGroup').toggleClass('d-none', !plex);
    $('#mediaAppApikeyGroup').toggleClass('d-none', plex);
}
function saveMediaApp(id)
{
    let payload = '&event=save';
    payload += '&id=' + encodeURIComponent(id || 0);
    payload += '&name=' + encodeURIComponent($('#mediaAppName').val() || '');
    payload += '&platform=' + encodeURIComponent($('#mediaAppPlatform').val() || '');
    payload += '&url=' + encodeURIComponent($('#mediaAppUrl').val() || '');
    payload += '&token=' + encodeURIComponent($('#mediaAppToken').val() || '');
    payload += '&apikey=' + encodeURIComponent($('#mediaAppApikey').val() || '');
    payload += '&syncMode=' + encodeURIComponent($('#mediaAppSyncMode').val() || '');
    payload += '&master=' + ($('#mediaAppMaster').is(':checked') ? '1' : '');
    payload += '&active=' + ($('#mediaAppActive').is(':checked') ? '1' : '');

    pageLoadingStart();
    $.ajax({
        url: BASE_URL + 'ajax/mediaApps.php',
        type: 'post',
        dataType: 'json',
        data: payload,
        success: function (response) {
            pageLoadingStop();
            if (!response || response.error) {
                toast(translate('mediaApps'), (response && response.message) || translate('mediaAppConnectionFailed'), 'error');
                return;
            }
            dialogClose('media-app-form');
            toast(translate('mediaApps'), response.message || translate('saved'), 'success');
            if (typeof loadPage == 'function') {
                loadPage('mediaApps');
            }
        },
        error: function () {
            pageLoadingStop();
            toast(translate('mediaApps'), translate('mediaAppConnectionFailed'), 'error');
        }
    });
}
function deleteMediaApp(id)
{
    pageLoadingStart();
    $.ajax({
        url: BASE_URL + 'ajax/mediaApps.php',
        type: 'post',
        dataType: 'json',
        data: '&event=delete&id=' + encodeURIComponent(id),
        success: function (response) {
            pageLoadingStop();
            if (!response || response.error) {
                toast(translate('mediaApps'), (response && response.message) || translate('couldNotSaveSettings'), 'error');
                return;
            }
            dialogClose('media-app-form');
            toast(translate('mediaApps'), response.message || translate('saved'), 'success');
            if (typeof loadPage == 'function') {
                loadPage('mediaApps');
            }
        },
        error: function () {
            pageLoadingStop();
            toast(translate('mediaApps'), translate('couldNotSaveSettings'), 'error');
        }
    });
}
function toggleMediaApp(id)
{
    pageLoadingStart();
    $.ajax({
        url: BASE_URL + 'ajax/mediaApps.php',
        type: 'post',
        dataType: 'json',
        data: '&event=toggle&id=' + encodeURIComponent(id) + '&active=' + ($('#mediaAppActive-' + id).is(':checked') ? '1' : ''),
        success: function (response) {
            pageLoadingStop();
            if (!response || response.error) {
                toast(translate('mediaApps'), (response && response.message) || translate('couldNotSaveSettings'), 'error');
                if (typeof loadPage == 'function') {
                    loadPage('mediaApps');
                }
                return;
            }
            if (typeof loadPage == 'function') {
                loadPage('mediaApps');
            }
        },
        error: function () {
            pageLoadingStop();
            toast(translate('mediaApps'), translate('couldNotSaveSettings'), 'error');
        }
    });
}
function openMediaAppUsers(id)
{
    pageLoadingStart();
    $.ajax({
        url: BASE_URL + 'ajax/mediaApps.php',
        type: 'post',
        data: '&event=users&id=' + encodeURIComponent(id),
        success: function (html) {
            dialogOpen({
                id: 'media-app-users',
                title: translate('users'),
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
            toast(translate('mediaApps'), translate('unableToLoadPage'), 'error');
        }
    });
}
function mediaAppSyncStarted(response)
{
    pageLoadingStop();
    if (!response || response.error) {
        toast(translate('sync'), (response && response.message) || translate('couldNotSaveSettings'), 'error');
        return;
    }
    toast(translate('sync'), response.message || translate('syncStarted'), 'success');
}
function startMediaAppLibrarySync(id)
{
    pageLoadingStart();
    $.ajax({
        url: BASE_URL + 'ajax/sync.php',
        type: 'post',
        dataType: 'json',
        data: '&event=startAppLibrary&id=' + encodeURIComponent(id),
        success: mediaAppSyncStarted,
        error: function () {
            pageLoadingStop();
            toast(translate('sync'), translate('couldNotSaveSettings'), 'error');
        }
    });
}
function startMediaAppHistorySync(id)
{
    pageLoadingStart();
    $.ajax({
        url: BASE_URL + 'ajax/sync.php',
        type: 'post',
        dataType: 'json',
        data: '&event=startAppHistory&id=' + encodeURIComponent(id),
        success: mediaAppSyncStarted,
        error: function () {
            pageLoadingStop();
            toast(translate('sync'), translate('couldNotSaveSettings'), 'error');
        }
    });
}
function openMediaAppRootFolders(id)
{
    pageLoadingStart();
    $.ajax({
        url: BASE_URL + 'ajax/mediaApps.php',
        type: 'post',
        data: '&event=rootFolders&id=' + encodeURIComponent(id),
        success: function (html) {
            dialogOpen({
                id: 'media-app-root-folders',
                title: translate('rootFolders'),
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
            toast(translate('mediaApps'), translate('unableToLoadPage'), 'error');
        }
    });
}
