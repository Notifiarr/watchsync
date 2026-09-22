function openMediaAppForm(id)
{
    pageLoadingStart();
    $.ajax({
        url: BASE_URL + 'ajax/mediaApps.php',
        type: 'post',
        data: '&event=form&id=' + encodeURIComponent(id || 0),
        success: function (response) {
            dialogOpen({
                id: 'media-app-form',
                title: id ? translate('edit') : translate('addMediaApp'),
                body: response,
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
// ---------------------------------------------------------------------------------------------
function toggleMediaAppAuth()
{
    let plex = $('#mediaAppPlatform').val() == $('#mediaAppPlatform').attr('data-plex');
    $('#mediaAppTokenGroup').toggleClass('d-none', !plex);
    $('#mediaAppApikeyGroup').toggleClass('d-none', plex);
}
// ---------------------------------------------------------------------------------------------
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
// ---------------------------------------------------------------------------------------------
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
// ---------------------------------------------------------------------------------------------
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
// ---------------------------------------------------------------------------------------------
function openMediaAppUsers(id)
{
    pageLoadingStart();
    $.ajax({
        url: BASE_URL + 'ajax/mediaApps.php',
        type: 'post',
        data: '&event=users&id=' + encodeURIComponent(id),
        success: function (response) {
            dialogOpen({
                id: 'media-app-users',
                title: translate('users'),
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
            toast(translate('mediaApps'), translate('unableToLoadPage'), 'error');
        }
    });
}
// ---------------------------------------------------------------------------------------------
function editMediaAppUserToken(userId)
{
    pageLoadingStart();
    $.ajax({
        url: BASE_URL + 'ajax/mediaApps.php',
        type: 'post',
        data: '&event=userToken&id=' + encodeURIComponent(userId),
        success: function (response) {
            dialogOpen({
                id: 'media-app-user-token',
                title: translate('editToken'),
                body: response,
                size: 'md',
                zindex: 10050,
                footer: false,
                onOpen: function () {
                    pageLoadingStop();
                }
            });
        },
        error: function () {
            pageLoadingStop();
            toast(translate('token'), translate('unableToLoadPage'), 'error');
        }
    });
}
// ---------------------------------------------------------------------------------------------
function validateMediaAppUserToken(userId)
{
    pageLoadingStart();
    $.ajax({
        url: BASE_URL + 'ajax/mediaApps.php',
        type: 'post',
        dataType: 'json',
        data: '&event=validateUserToken&id=' + encodeURIComponent(userId) + '&token=' + encodeURIComponent($('#mediaAppUserToken').val() || '') + '&pin=' + encodeURIComponent($('#mediaAppUserPin').val() || ''),
        success: function (response) {
            pageLoadingStop();
            if (!response) {
                toast(translate('token'), translate('couldNotSaveSettings'), 'error');
                return;
            }
            if (response.pin_cleared) {
                $('#mediaAppUserPin').val('');
            }
            if (response.icon_html && $('#media-app-users .media-app-user-token-wrap[data-user-id="' + userId + '"]').length) {
                $('#media-app-users .media-app-user-token-wrap[data-user-id="' + userId + '"]').replaceWith(response.icon_html);
            }
            toast(translate('token'), response.message || translate('saved'), response.error ? 'error' : 'success');
        },
        error: function () {
            pageLoadingStop();
            toast(translate('token'), translate('couldNotSaveSettings'), 'error');
        }
    });
}
// ---------------------------------------------------------------------------------------------
function saveMediaAppUserToken(userId)
{
    pageLoadingStart();
    $.ajax({
        url: BASE_URL + 'ajax/mediaApps.php',
        type: 'post',
        dataType: 'json',
        data: '&event=saveUserToken&id=' + encodeURIComponent(userId) + '&token=' + encodeURIComponent($('#mediaAppUserToken').val() || '') + '&pin=' + encodeURIComponent($('#mediaAppUserPin').val() || ''),
        success: function (response) {
            pageLoadingStop();
            if (response && response.pin_cleared) {
                $('#mediaAppUserPin').val('');
            }
            if (response && response.icon_html && $('#media-app-users .media-app-user-token-wrap[data-user-id="' + userId + '"]').length) {
                $('#media-app-users .media-app-user-token-wrap[data-user-id="' + userId + '"]').replaceWith(response.icon_html);
            }
            if (!response || response.error) {
                toast(translate('token'), (response && response.message) || translate('couldNotSaveSettings'), 'error');
                return;
            }
            dialogClose('media-app-user-token');
            toast(translate('token'), response.message || translate('saved'), 'success');
        },
        error: function () {
            pageLoadingStop();
            toast(translate('token'), translate('couldNotSaveSettings'), 'error');
        }
    });
}
// ---------------------------------------------------------------------------------------------
function refreshMediaAppUserTokens(mediaAppId)
{
    pageLoadingStart();
    $.ajax({
        url: BASE_URL + 'ajax/mediaApps.php',
        type: 'post',
        dataType: 'json',
        data: '&event=refreshUserTokens&id=' + encodeURIComponent(mediaAppId),
        success: function (response) {
            pageLoadingStop();
            if (!response || response.error) {
                toast(translate('token'), (response && response.message) || translate('couldNotSaveSettings'), 'error');
                return;
            }
            if (response.icons) {
                $.each(response.icons, function (userId, iconHtml) {
                    if (iconHtml && $('#media-app-users .media-app-user-token-wrap[data-user-id="' + userId + '"]').length) {
                        $('#media-app-users .media-app-user-token-wrap[data-user-id="' + userId + '"]').replaceWith(iconHtml);
                    }
                });
            }
            toast(translate('token'), response.message || translate('saved'), response.updated ? 'success' : 'info');
        },
        error: function () {
            pageLoadingStop();
            toast(translate('token'), translate('couldNotSaveSettings'), 'error');
        }
    });
}
// ---------------------------------------------------------------------------------------------
function openMediaAppRootFolders(id)
{
    pageLoadingStart();
    $.ajax({
        url: BASE_URL + 'ajax/mediaApps.php',
        type: 'post',
        data: '&event=rootFolders&id=' + encodeURIComponent(id),
        success: function (response) {
            dialogOpen({
                id: 'media-app-root-folders',
                title: translate('rootFolders'),
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
            toast(translate('mediaApps'), translate('unableToLoadPage'), 'error');
        }
    });
}
