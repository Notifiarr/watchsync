function setLogHeader(header)
{
    $('#logHeader').empty();
    if (!header) {
        return;
    }

    $('#logHeader').append(document.createTextNode(header + ' '));
    $('#logHeader').append($('<i class="fas fa-copy text-primary" style="cursor: pointer;" title="' + translate('copy') + '" onclick="clipboard(\'logViewer\', \'log\');"></i>'));
}
// ---------------------------------------------------------------------------------------------
function viewLog(name, hash)
{
    pageLoadingStart();

    $('[id^=logList-]').removeClass('text-warning').addClass('text-secondary');
    $('#logList-' + hash).removeClass('text-secondary').addClass('text-warning');
    $('#logViewer').text(translate('fetchingLog'));
    setLogHeader('');

    $.ajax({
        url: BASE_URL + 'ajax/logs.php',
        type: 'post',
        dataType: 'json',
        data: '&event=viewLog&name=' + encodeURIComponent(name),
        success: function (response) {
            pageLoadingStop();
            if (!response || response.error) {
                setLogHeader('');
                $('#logViewer').text((response && response.message) || translate('couldNotLoadLog'));
                return;
            }
            setLogHeader(response.header || '');
            $('#logViewer').html(response.log || '');
            $('#logViewer').scrollTop($('#logViewer')[0].scrollHeight);
        },
        error: function () {
            pageLoadingStop();
            setLogHeader('');
            $('#logViewer').text(translate('couldNotLoadLog'));
        }
    });
}
// ---------------------------------------------------------------------------------------------
function downloadLog(name)
{
    window.location = BASE_URL + 'ajax/logs.php?event=downloadLog&name=' + encodeURIComponent(name);
}
// ---------------------------------------------------------------------------------------------
function deleteLog(name)
{
    pageLoadingStart();
    $.ajax({
        url: BASE_URL + 'ajax/logs.php',
        type: 'post',
        dataType: 'json',
        data: '&event=deleteLog&name=' + encodeURIComponent(name),
        success: function (response) {
            pageLoadingStop();
            if (!response || response.error) {
                toast(translate('logs'), (response && response.message) || translate('couldNotLoadLog'), 'error');
                return;
            }
            toast(translate('logs'), response.message || translate('logDeleted'), 'success');
            loadPage('logs');
        },
        error: function () {
            pageLoadingStop();
            toast(translate('logs'), translate('couldNotLoadLog'), 'error');
        }
    });
}
// ---------------------------------------------------------------------------------------------
function purgeLogs(group)
{
    pageLoadingStart();
    $.ajax({
        url: BASE_URL + 'ajax/logs.php',
        type: 'post',
        dataType: 'json',
        data: '&event=purgeLogs&group=' + encodeURIComponent(group),
        success: function (response) {
            pageLoadingStop();
            if (!response || response.error) {
                toast(translate('logs'), (response && response.message) || translate('couldNotLoadLog'), 'error');
                return;
            }
            toast(translate('logs'), response.message || translate('logsPurged'), 'success');
            loadPage('logs');
        },
        error: function () {
            pageLoadingStop();
            toast(translate('logs'), translate('couldNotLoadLog'), 'error');
        }
    });
}
