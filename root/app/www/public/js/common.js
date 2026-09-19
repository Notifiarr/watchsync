var BASE_URL = window.APP_BASE || '';

$(function () {
    const savedTheme = localStorage.getItem("theme");
    const initialTheme = savedTheme || "dark";

    function applyTheme(themeName) {
        $("html").attr("data-bs-theme", themeName);
        window.UI_MODE = themeName;
    }

    applyTheme(initialTheme);
    $("#themeToggle").prop("checked", initialTheme == "dark");

    $("#themeToggle").on("change", function () {
        const nextTheme = $(this).is(":checked") ? "dark" : "light";
        applyTheme(nextTheme);
        localStorage.setItem("theme", nextTheme);
    });

    if (typeof initializeSSE == 'function') {
        initializeSSE();
    }
});
// ---------------------------------------------------------------------------------------------
function toast(title, message, type)
{
    const uniqueId = Date.now() + Math.floor(Math.random() * 1000);

    let toast = '';
    let border = 'info';

    if (type == 'error') {
        border = 'danger';
    }
    if (type == 'success') {
        border = 'success';
    }

    toast += '<div id="toast-' + uniqueId + '" class="toast text-white bg-' + border + '" data-autohide="false">';
    toast += '  <div class="toast-header text-white bg-' + border + '">';
    toast += '      <i class="far fa-bell text-white me-2"></i>';
    toast += '      <strong class="me-auto">' + title + '</strong>';
    toast += '      <small>' + type + '</small>';
    toast += '      <button type="button" class="btn-close btn-close-white" data-bs-dismiss="toast"></button>';
    toast += '  </div>';
    toast += '  <div class="toast-body">' + message + '</div>';
    toast += '</div>';

    $('.toast-container').appendTo('body').css({
        'z-index': '9999999',
        'position': 'fixed'
    });
    $('.toast-container').append(toast);
    if (!$('#toast-' + uniqueId).length) {
        return;
    }

    bootstrap.Toast.getOrCreateInstance($('#toast-' + uniqueId), {
        autohide: false
    }).show();

    setTimeout(function () {
        $('#toast-' + uniqueId).remove();
    }, 10000);
}
// ---------------------------------------------------------------------------------------------
function clipboard(elm, elmType)
{
    let txt = '';

    switch (elmType) {
        case 'html':
            txt = $('#' + elm).html();
            break;
        case 'raw':
            txt = elm;
            break;
        case 'val':
            txt = $('#' + elm).val();
            break;
        case 'log': {
            let lines = $('#' + elm).find('.log-viewer-text, .sync-log-text');
            txt = lines.length
                ? lines.map(function () { return $(this).text(); }).get().join('\n')
                : ($('#' + elm).text() || '');
            break;
        }
    }

    if (!txt) {
        toast('Copy Failed', 'Nothing found to copy with element "' + elm + '"', 'error');
        return;
    }

    function copied()
    {
        toast('Copied', 'Contents copied to clipboard', 'success');
    }

    function failed()
    {
        toast('Copy Failed', 'Contents failed to copy to clipboard', 'error');
    }

    function fallback()
    {
        let host = document.querySelector('.modal.show .modal-content') || document.body;
        let area = document.createElement('textarea');
        area.value = txt;
        area.setAttribute('readonly', '');
        area.style.position = 'absolute';
        area.style.top = '0';
        area.style.left = '0';
        area.style.width = '1px';
        area.style.height = '1px';
        area.style.padding = '0';
        area.style.border = 'none';
        area.style.outline = 'none';
        area.style.boxShadow = 'none';
        area.style.opacity = '0';
        host.appendChild(area);
        area.focus({ preventScroll: true });
        area.select();
        area.setSelectionRange(0, area.value.length);
        let ok = false;
        try {
            ok = document.execCommand('copy');
        } catch (error) {
            ok = false;
        }
        host.removeChild(area);
        if (ok) {
            copied();
        } else {
            failed();
        }
    }

    if (!window.isSecureContext || !navigator.clipboard || typeof navigator.clipboard.writeText != 'function') {
        fallback();
        return;
    }

    navigator.clipboard.writeText(txt).then(copied, function () {
        fallback();
    });
}
// ---------------------------------------------------------------------------------------------
function initDataTable(selector, options)
{
    if (typeof $.fn.dataTable == 'undefined' || !$(selector).length) {
        return null;
    }
    if ($.fn.dataTable.isDataTable(selector)) {
        $(selector).DataTable().destroy();
    }

    return $(selector).dataTable($.extend(true, {
        pageLength: 50,
        lengthMenu: [25, 50, 100, 250],
        paging: true,
        ordering: true,
        order: [],
        autoWidth: false,
        columnDefs: [{
            targets: 'no-sort',
            orderable: false
        }],
        initComplete: function () {
            $(selector + '_filter input').attr('placeholder', 'Search');
            $(selector + ' .sorting_disabled').removeClass('sorting_asc');
        }
    }, options || {}));
}
// ---------------------------------------------------------------------------------------------
function pageLoadingStart()
{
    $('#loading-modal .btn-close').hide();
    bootstrap.Modal.getOrCreateInstance($('#loading-modal'), {
        keyboard: false,
        backdrop: 'static'
    }).show();

    $('#loading-modal').off('dblclick.loadingModal').on('dblclick.loadingModal', function () {
        $('#loading-modal .btn-close').show();
    });
}
// ---------------------------------------------------------------------------------------------
function pageLoadingStop()
{
    setTimeout(async function () {
        while (true) {
            if ($('#loading-modal').length) {
                bootstrap.Modal.getOrCreateInstance($('#loading-modal')).hide();
            }
            if ($('#loading-modal:visible').length == 0) {
                break;
            }

            await new Promise(r => setTimeout(r, 25));
        }
    }, 300);
}
// ---------------------------------------------------------------------------------------------
function popoutOpen(p)
{
    const header = p.header ? p.header : '&nbsp;';
    const content = p.content ? p.content : '&nbsp;';
    const duration = p.duration ? p.duration : 500;
    const classes = p.classes ? p.classes : 'bg-body bg-opacity-95 p-2';
    const position = p.position ? p.position : 'left';

    const popupOptions = {
        header: header,
        content: content,
        duration: duration,
        classes: classes,
    };
    if (p.width != undefined) {
        popupOptions.width = p.width;
    }
    if (p.height != undefined) {
        popupOptions.height = p.height;
    }

    switch (position) {
        case 'left':
            new popup('#popout-slider', popupOptions).popupLeft();
            break;
        case 'right':
            new popup('#popout-slider', popupOptions).popupRight();
            break;
        case 'top':
            break;
    }
}
// ---------------------------------------------------------------------------------------------
function dialogOpen(p)
{
    const id     = p.id;
    const title  = p.title == false ? false : (p.title ? p.title : '&nbsp;');
    const body   = p.body ? p.body : '&nbsp;';
    const footer = p.footer == false ? false : (p.footer ? p.footer : '&nbsp;');
    const close  = typeof p.close == 'undefined' ? true : p.close;
    const size     = p.size ? p.size : '';
    const escape   = typeof p.escape == 'undefined' ? false : p.escape;
    const minimize = typeof p.minimize == 'undefined' ? false : p.minimize;

    if (typeof id == 'undefined') {
        console.log('Error: Called dialogOpen with no id parameter');
        return;
    }

    if (id != 'dialog-modal' && $('#' + id).length) {
        if (bootstrap.Modal.getInstance($('#' + id))) {
            bootstrap.Modal.getInstance($('#' + id)).hide();
        }
        $('#' + id).remove();
    }

    $('#dialog-modal').clone().appendTo('#dialog-modal-container').prop('id', id);

    bootstrap.Modal.getOrCreateInstance($('#' + id), {
        keyboard: false,
        backdrop: 'static'
    });

    if (p.zindex) {
        $('#' + id).css('z-index', p.zindex);
        $('#' + id).one('shown.bs.modal', function () {
            $('.modal-backdrop').last().css('z-index', Number(p.zindex) - 1);
        });
    }

    if (escape) {
        $('#' + id).attr('data-escape-close', 'true');
    }

    if (title == false) {
        $('#' + id + ' .modal-title').hide().empty();
    } else {
        $('#' + id + ' .modal-title').show().html(title);
    }
    $('#' + id + ' .modal-body').html(body);
    if (footer == false) {
        $('#' + id + ' .modal-footer').empty().hide();
    } else {
        $('#' + id + ' .modal-footer').show().html(footer);
    }

    if (!close) {
        $('#' + id + ' .btn-close').hide();

        $('#' + id + ' .modal-header').dblclick(function () {
            $('#' + id + ' .btn-close').show();
        });
    }

    if (minimize) {
        const closeBtn = $('#' + id + ' .btn-close').clone();

        $('#' + id + ' .btn-close').remove();
        $('#' + id + ' .modal-header').append('<div style="float: right;" class="dialog-btn-container"></div>');
        $('#' + id + ' .modal-header .dialog-btn-container').append('<i onclick="bootstrap.Modal.getInstance($(\'#' + id + '\')).hide(); $(\'#' + id + '-minimized\').show();" class="fa-solid fa-window-minimize" style="cursor: pointer;"></i>').append(closeBtn);

        let minimizeDiv = '<div id="' + id + '-minimized" style="position: fixed; bottom: 0; right: 0; z-index: 10001; display: none; margin-right: 6em;">';
        minimizeDiv    += '    <div class="card bg-theme border-theme bg-opacity-75 mb-3">';
        minimizeDiv    += '        <div class="card-header border-theme fw-bold small text-inverse">' + $('#' + id + ' .modal-header .modal-title').text() + ' <i style="cursor: pointer;" onclick="bootstrap.Modal.getOrCreateInstance($(\'#' + id + '\')).show(); $(\'#' + id + '-minimized\').hide();" class="fa-regular fa-window-restore"></i></div>';
        minimizeDiv    += '        <div>';
        minimizeDiv    += '            <div class="card-arrow-bottom-left"></div>';
        minimizeDiv    += '            <div class="card-arrow-bottom-right"></div>';
        minimizeDiv    += '        </div>';
        minimizeDiv    += '    </div>';
        minimizeDiv    += '</div>';

        $('body').append(minimizeDiv);
    }

    bootstrap.Modal.getOrCreateInstance($('#' + id)).show();

    if (size) {
        $('#' + id + ' .modal-dialog').addClass('modal-' + size);
    }

    if (typeof p.onOpen != 'undefined') {
        const onOpenFunction = p.onOpen;
        function onOpenCallback(callback)
        {
            callback();
        }
        onOpenCallback(onOpenFunction);
    }

    if (typeof p.onClose != 'undefined') {
        const onCloseFunction = p.onClose;
        function onCloseCallback(callback)
        {
            callback();
        }

        $('#' + id + ' .btn-close').attr('onclick', '');
        $('#' + id + ' .btn-close').bind('click', function () {
            onCloseCallback(onCloseFunction);
            dialogClose(id);
        });
    }
}
// ---------------------------------------------------------------------------------------------
function dialogClose(elm)
{
    if (!elm) {
        console.log('Error: Called dialogClose on no elm');
        return;
    }

    let id = elm;
    if (typeof elm == 'object') {
        id = $('#dialog-modal-container').find('.modal').find(elm).closest('.modal').attr('id');
    }

    if (!$('#' + id).length) {
        return;
    }

    if (bootstrap.Modal.getInstance($('#' + id))) {
        bootstrap.Modal.getInstance($('#' + id)).hide();
    }
    $('#' + id).click();
    $('#' + id).remove();
}
var lastShiftCheckbox = {};
// ---------------------------------------------------------------------------------------------
$(document).on('click', '.sync-library, .sync-user, .sync-parity-library, .sync-history-user, .notification-trigger', function (event) {
    let groups = ['.sync-library', '.sync-user', '.sync-parity-library', '.sync-history-user', '.notification-trigger'];
    let group  = '';
    for (let i = 0; i < groups.length; i++) {
        if ($(this).hasClass(groups[i].substring(1))) {
            group = groups[i];
            break;
        }
    }
    if (!group) {
        return;
    }

    if (event.shiftKey && lastShiftCheckbox[group]) {
        let start = $(group).index(lastShiftCheckbox[group]);
        let end   = $(group).index(this);
        if (start > -1 && end > -1) {
            $(group).slice(Math.min(start, end), Math.max(start, end) + 1).prop('checked', $(this).prop('checked'));
        }
    }

    lastShiftCheckbox[group] = this;
});
