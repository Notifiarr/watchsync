function collectNotificationPayload()
{
    let payload = '';

    $('.notification-trigger').each(function () {
        payload += '&' + $(this).attr('id') + '=' + ($(this).is(':checked') ? '1' : '');
    });

    $('[id^="notificationPlatformParameter-"]').each(function () {
        payload += '&' + $(this).attr('id') + '=' + encodeURIComponent($(this).val() || '');
    });

    return payload;
}
// -------------------------------------------------------------------------------------------
function openNotificationTriggers(platformId, linkId)
{
    pageLoadingStart();
    $.ajax({
        url: BASE_URL + 'ajax/notifications.php',
        type: 'post',
        data: '&event=openTriggers&platformId=' + encodeURIComponent(platformId) + '&linkId=' + encodeURIComponent(linkId || 0),
        success: function (html) {
            dialogOpen({
                id: 'notification-triggers',
                title: translate('notifications'),
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
            toast(translate('notifications'), translate('unableToLoadPage'), 'error');
        }
    });
}
// -------------------------------------------------------------------------------------------
function addNotification(platformId)
{
    pageLoadingStart();
    $.ajax({
        url: BASE_URL + 'ajax/notifications.php',
        type: 'post',
        dataType: 'json',
        data: '&event=add&platformId=' + encodeURIComponent(platformId) + collectNotificationPayload(),
        success: function (response) {
            pageLoadingStop();
            if (!response || response.error) {
                toast(translate('notifications'), (response && response.message) || translate('couldNotSaveSettings'), 'error');
                return;
            }
            dialogClose('notification-triggers');
            toast(translate('notifications'), translate('saved'), 'success');
            if (typeof loadPage === 'function') {
                loadPage('notifications');
            }
        },
        error: function () {
            pageLoadingStop();
            toast(translate('notifications'), translate('couldNotSaveSettings'), 'error');
        }
    });
}
// -------------------------------------------------------------------------------------------
function saveNotification(platformId, linkId)
{
    pageLoadingStart();
    $.ajax({
        url: BASE_URL + 'ajax/notifications.php',
        type: 'post',
        dataType: 'json',
        data: '&event=save&platformId=' + encodeURIComponent(platformId) + '&linkId=' + encodeURIComponent(linkId) + collectNotificationPayload(),
        success: function (response) {
            pageLoadingStop();
            if (!response || response.error) {
                toast(translate('notifications'), (response && response.message) || translate('couldNotSaveSettings'), 'error');
                return;
            }
            dialogClose('notification-triggers');
            toast(translate('notifications'), translate('saved'), 'success');
            if (typeof loadPage === 'function') {
                loadPage('notifications');
            }
        },
        error: function () {
            pageLoadingStop();
            toast(translate('notifications'), translate('couldNotSaveSettings'), 'error');
        }
    });
}
// -------------------------------------------------------------------------------------------
function deleteNotification(linkId)
{
    pageLoadingStart();
    $.ajax({
        url: BASE_URL + 'ajax/notifications.php',
        type: 'post',
        dataType: 'json',
        data: '&event=delete&linkId=' + encodeURIComponent(linkId),
        success: function (response) {
            pageLoadingStop();
            if (!response || response.error) {
                toast(translate('notifications'), (response && response.message) || translate('couldNotSaveSettings'), 'error');
                return;
            }
            dialogClose('notification-triggers');
            toast(translate('notifications'), translate('saved'), 'success');
            if (typeof loadPage === 'function') {
                loadPage('notifications');
            }
        },
        error: function () {
            pageLoadingStop();
            toast(translate('notifications'), translate('couldNotSaveSettings'), 'error');
        }
    });
}
// -------------------------------------------------------------------------------------------
function testNotify(linkId, name)
{
    pageLoadingStart();
    $.ajax({
        url: BASE_URL + 'ajax/notifications.php',
        type: 'post',
        dataType: 'json',
        data: '&event=test&linkId=' + encodeURIComponent(linkId) + '&name=' + encodeURIComponent(name),
        success: function (response) {
            pageLoadingStop();
            if (!response || response.error) {
                toast(translate('notifications'), (response && response.message) || translate('testNotificationFailed'), 'error');
                return;
            }
            toast(translate('notifications'), response.message || translate('testNotificationSent'), 'success');
        },
        error: function () {
            pageLoadingStop();
            toast(translate('notifications'), translate('testNotificationFailed'), 'error');
        }
    });
}
// -------------------------------------------------------------------------------------------
