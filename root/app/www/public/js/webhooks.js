$(document).on('page:loaded', function (event, pageKey) {
    if (pageKey == 'webhooks') {
        updateWebhookUrl();
    }
});
// ---------------------------------------------------------------------------------------------
function viewWebhookLog(name)
{
    pageLoadingStart();

    $.ajax({
        url: BASE_URL + 'ajax/logs.php',
        type: 'post',
        dataType: 'json',
        data: '&event=viewLog&name=' + encodeURIComponent(name),
        success: function (response) {
            pageLoadingStop();
            if (!response || response.error) {
                toast(translate('webhooks'), (response && response.message) || translate('couldNotLoadLog'), 'error');
                return;
            }
            dialogOpen({
                id: 'webhook-log-dialog',
                title: response.header || name,
                body: '<div class="sync-log-pane" id="webhookLogPane"><pre class="mb-0 small sync-log-lines">' + (response.log || '') + '</pre></div>',
                footer: false,
                size: 'xxl',
                onOpen: function () {
                    $('#webhook-log-dialog .modal-dialog').removeClass('modal-dialog-scrollable');
                    $('#webhook-log-dialog .modal-body').addClass('sync-log-modal-body');
                    var pane = $('#webhook-log-dialog #webhookLogPane');
                    pane.scrollTop(pane.prop('scrollHeight'));
                }
            });
        },
        error: function () {
            pageLoadingStop();
            toast(translate('webhooks'), translate('couldNotLoadLog'), 'error');
        }
    });
}
// ---------------------------------------------------------------------------------------------
function webhookPageUrl()
{
    var path = window.location.pathname.replace(/index\.php\/?$/, '');
    if (path.slice(-1) != '/') {
        path += '/';
    }

    return window.location.origin + path;
}
// ---------------------------------------------------------------------------------------------
function updateWebhookUrl()
{
    var key = $('#webhookApiKey').val() || '';
    $('#webhookUrl').val(webhookPageUrl() + 'api/?apikey=' + encodeURIComponent(key));
}
// ---------------------------------------------------------------------------------------------
function copyWebhookUrl()
{
    var url = $('#webhookUrl').val() || '';
    if (!url || !navigator.clipboard) {
        return;
    }

    navigator.clipboard.writeText(url).then(function () {
        toast(translate('webhooks'), translate('copied'), 'success');
    });
}
// ---------------------------------------------------------------------------------------------
function generateWebhookApiKey()
{
    pageLoadingStart();

    $.ajax({
        url: BASE_URL + 'ajax/webhooks.php',
        type: 'post',
        dataType: 'json',
        data: { event: 'generateApiKey' },
        success: function (response) {
            pageLoadingStop();
            if (!response || response.error || !response.key) {
                toast(translate('webhooks'), (response && response.message) || translate('couldNotSaveWebhooks'), 'error');
                return;
            }
            $('#webhookApiKey').val(response.key);
            updateWebhookUrl();
        },
        error: function () {
            pageLoadingStop();
            toast(translate('webhooks'), translate('couldNotSaveWebhooks'), 'error');
        }
    });
}
// ---------------------------------------------------------------------------------------------
function saveWebhooks()
{
    pageLoadingStart();

    var apps = {};
    $('.webhook-app').each(function () {
        apps[$(this).val()] = $(this).is(':checked') ? 1 : 0;
    });

    $.ajax({
        url: BASE_URL + 'ajax/webhooks.php',
        type: 'post',
        dataType: 'json',
        data: {
            event: 'saveWebhooks',
            apiKey: $('#webhookApiKey').val() || '',
            apps: apps
        },
        success: function (response) {
            pageLoadingStop();
            if (!response || response.error) {
                toast(translate('webhooks'), (response && response.message) || translate('couldNotSaveWebhooks'), 'error');
                return;
            }
            toast(translate('webhooks'), response.message || translate('saved'), 'success');
            updateWebhookUrl();
        },
        error: function () {
            pageLoadingStop();
            toast(translate('webhooks'), translate('couldNotSaveWebhooks'), 'error');
        }
    });
}
// ---------------------------------------------------------------------------------------------
function deleteWebhookLog(file)
{
    if (!file) {
        return;
    }
    if (!confirm(translate('deleteWebhookLogConfirm'))) {
        return;
    }

    pageLoadingStart();
    $.ajax({
        url: BASE_URL + 'ajax/webhooks.php',
        type: 'post',
        dataType: 'json',
        data: {
            event: 'deleteWebhookLog',
            file: file
        },
        success: function (response) {
            pageLoadingStop();
            if (!response || response.error) {
                toast(translate('webhooks'), (response && response.message) || translate('couldNotLoadLog'), 'error');
                return;
            }
            if ($('#webhook-log-dialog').length) {
                dialogClose('webhook-log-dialog');
            }
            toast(translate('webhooks'), response.message || translate('removed'), 'success');
            if (typeof loadPage == 'function') {
                loadPage('webhooks');
            }
        },
        error: function () {
            pageLoadingStop();
            toast(translate('webhooks'), translate('couldNotLoadLog'), 'error');
        }
    });
}
// ---------------------------------------------------------------------------------------------
function deleteAllWebhookLogs()
{
    if (!confirm(translate('clearWebhookLogsConfirm'))) {
        return;
    }

    pageLoadingStart();
    $.ajax({
        url: BASE_URL + 'ajax/webhooks.php',
        type: 'post',
        dataType: 'json',
        data: {
            event: 'deleteAllWebhookLogs'
        },
        success: function (response) {
            pageLoadingStop();
            if (!response || response.error) {
                toast(translate('webhooks'), (response && response.message) || translate('couldNotLoadLog'), 'error');
                return;
            }
            if ($('#webhook-log-dialog').length) {
                dialogClose('webhook-log-dialog');
            }
            toast(translate('webhooks'), response.message || translate('webhookLogsCleared'), 'success');
            if (typeof loadPage == 'function') {
                loadPage('webhooks');
            }
        },
        error: function () {
            pageLoadingStop();
            toast(translate('webhooks'), translate('couldNotLoadLog'), 'error');
        }
    });
}
