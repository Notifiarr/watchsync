var libraryState = {
    type: 'all',
    libraryKey: '',
    userId: 0,
    watched: 'all',
    letter: '',
    loading: false,
    doneDown: false,
    doneUp: true,
    seq: 0,
    observers: []
};

function libraryFilters()
{
    return {
        type: $('#libraryType').val() || 'all',
        libraryKey: String($('#libraryLibrary').val() || ''),
        userId: String($('#libraryUser').val() || '0'),
        watched: $('#libraryWatched').val() || 'all'
    };
}
// ---------------------------------------------------------------------------------------------
function libraryRequest(event, extra)
{
    let data = '&event=' + encodeURIComponent(event)
        + '&type=' + encodeURIComponent(libraryState.type)
        + '&libraryKey=' + encodeURIComponent(libraryState.libraryKey)
        + '&userId=' + encodeURIComponent(libraryState.userId)
        + '&watched=' + encodeURIComponent(libraryState.watched);
    extra = extra || {};
    Object.keys(extra).forEach(function (key) {
        if (extra[key] != '' && extra[key] != undefined && extra[key] != null) {
            data += '&' + encodeURIComponent(key) + '=' + encodeURIComponent(extra[key]);
        }
    });
    return data;
}
// ---------------------------------------------------------------------------------------------
function libraryCardHtml(item)
{
    let type = item.type == 'series' ? 'series' : 'movie';
    let card = $('<div class="card border shadow-sm h-100 library-card"></div>').attr({
        'data-type': type,
        'data-id': item.id,
        'data-title': item.title || '',
        'data-letter': item.letter || '',
        'data-label': item.label || ''
    });
    let watchers = parseInt(item.watchers || 0, 10) || 0;
    let banner = $('<div class="library-type-banner library-type-' + type + '"></div>');
    banner.append($('<span class="library-type-label"></span>').text(
        type == 'series' ? translate('series') : translate('movie')
    ));
    banner.append($('<span class="library-type-watchers"></span>').text(String(watchers)));
    card.append(banner);
    let poster = $('<div class="library-poster-wrap"></div>');
    if (item.poster) {
        poster.append($('<img class="library-poster" alt="" loading="lazy">').attr('src', BASE_URL + item.poster));
    } else {
        poster.append($('<div class="library-poster-placeholder"></div>'));
    }
    card.append(poster);
    card.append($('<div class="card-body p-2"></div>').append(
        $('<div class="library-card-title"></div>').text(item.label || '')
    ));
    return card.prop('outerHTML');
}
// ---------------------------------------------------------------------------------------------
function libraryBindObservers()
{
    libraryState.observers.forEach(function (observer) {
        observer.disconnect();
    });
    libraryState.observers = [];

    let root = document.getElementById('libraryListWrap');
    if (!root) {
        return;
    }

    [['librarySentinelBottom', 'down'], ['librarySentinelTop', 'up']].forEach(function (pair) {
        let node = document.getElementById(pair[0]);
        if (!node) {
            return;
        }
        let observer = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (entry.isIntersecting) {
                    libraryLoadPage(pair[1]);
                }
            });
        }, { root: root, rootMargin: '80px', threshold: 0 });
        observer.observe(node);
        libraryState.observers.push(observer);
    });
}
// ---------------------------------------------------------------------------------------------
function librarySetLetters(letters)
{
    letters = letters || {};
    $('#libraryAz .library-az-letter').each(function () {
        let letter = String($(this).data('letter') || '');
        let count = parseInt(letters[letter] || 0, 10);
        $(this).toggleClass('disabled', count < 1);
        $(this).toggleClass('active', libraryState.letter != '' && letter == libraryState.letter);
    });
}
// ---------------------------------------------------------------------------------------------
function libraryResetList()
{
    $('#libraryList').empty();
    $('#libraryEmpty').addClass('d-none');
    libraryState.doneDown = false;
    libraryState.doneUp = libraryState.letter == '' || libraryState.letter == '#';
}
// ---------------------------------------------------------------------------------------------
function libraryLoadLetters()
{
    let seq = libraryState.seq;
    $.ajax({
        url: BASE_URL + 'ajax/library.php',
        type: 'post',
        dataType: 'json',
        data: libraryRequest('letters'),
        success: function (response) {
            if (seq != libraryState.seq) {
                return;
            }
            librarySetLetters(response && response.letters ? response.letters : {});
        }
    });
}
// ---------------------------------------------------------------------------------------------
function libraryLoadPage(direction)
{
    if (!$('#libraryList').length || libraryState.loading) {
        return;
    }
    if (direction == 'up' && libraryState.doneUp) {
        return;
    }
    if (direction != 'up' && libraryState.doneDown) {
        return;
    }

    let extra = { direction: direction, letter: libraryState.letter };
    let rows = $('#libraryList .library-card');
    if (rows.length) {
        let edge = direction == 'up' ? rows.first() : rows.last();
        extra.title = edge.attr('data-title') || '';
        extra.itemType = edge.attr('data-type') || '';
        extra.id = edge.attr('data-id') || '';
    }

    libraryState.loading = true;
    let seq = libraryState.seq;
    $.ajax({
        url: BASE_URL + 'ajax/library.php',
        type: 'post',
        dataType: 'json',
        data: libraryRequest('items', extra),
        success: function (response) {
            if (seq != libraryState.seq) {
                return;
            }
            let items = response && response.items ? response.items : [];
            if (!items.length) {
                if (direction == 'up') {
                    libraryState.doneUp = true;
                } else {
                    libraryState.doneDown = true;
                    if (!$('#libraryList .library-card').length) {
                        $('#libraryEmpty').removeClass('d-none');
                    }
                }
                return;
            }
            let html = items.map(libraryCardHtml).join('');
            let wrap = document.getElementById('libraryListWrap');
            let before = wrap ? wrap.scrollHeight : 0;
            if (direction == 'up') {
                $('#libraryList').prepend(html);
                if (wrap) {
                    wrap.scrollTop += wrap.scrollHeight - before;
                }
            } else {
                $('#libraryList').append(html);
            }
            if (response.done) {
                if (direction == 'up') {
                    libraryState.doneUp = true;
                } else {
                    libraryState.doneDown = true;
                }
            }
            if ($('#libraryList .library-card').length) {
                $('#libraryEmpty').addClass('d-none');
            }
        },
        complete: function () {
            if (seq == libraryState.seq) {
                libraryState.loading = false;
            }
        }
    });
}
// ---------------------------------------------------------------------------------------------
function libraryReload()
{
    let filters = libraryFilters();
    libraryState.seq++;
    libraryState.loading = false;
    libraryState.type = filters.type;
    libraryState.libraryKey = filters.libraryKey;
    libraryState.userId = filters.userId;
    libraryState.watched = filters.watched;
    libraryState.letter = '';
    libraryResetList();
    libraryLoadLetters();
    libraryLoadPage('down');
}
// ---------------------------------------------------------------------------------------------
function openLibraryItem(type, id, title)
{
    pageLoadingStart();
    $.ajax({
        url: BASE_URL + 'ajax/library.php',
        type: 'post',
        data: '&event=itemWatch&type=' + encodeURIComponent(type) + '&id=' + encodeURIComponent(id),
        success: function (response) {
            dialogOpen({
                id: 'library-item-watch',
                title: title,
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
            toast(translate('library'), translate('unableToLoadPage'), 'error');
        }
    });
}
// ---------------------------------------------------------------------------------------------
function openLibraryType(type)
{
    window.libraryPendingType = type == 'series' ? 'series' : 'movie';
    loadPage('library');
}
// ---------------------------------------------------------------------------------------------
$(document).on('page:loaded', function (event, pageKey) {
    if (pageKey != 'library') {
        libraryState.observers.forEach(function (observer) {
            observer.disconnect();
        });
        libraryState.observers = [];
        return;
    }
    if (window.libraryPendingType) {
        $('#libraryType').val(window.libraryPendingType);
        window.libraryPendingType = '';
    }
    libraryBindObservers();
    libraryReload();
});
// ---------------------------------------------------------------------------------------------
$(document).on('change', '#libraryType, #libraryLibrary, #libraryUser, #libraryWatched', function () {
    if ($('#libraryList').length) {
        libraryReload();
    }
});
// ---------------------------------------------------------------------------------------------
$(document).on('click', '.library-az-letter:not(.disabled)', function () {
    let letter = String($(this).data('letter') || '');
    libraryState.seq++;
    libraryState.loading = false;
    libraryState.letter = letter;
    $('#libraryAz .library-az-letter').removeClass('active');
    $(this).addClass('active');
    libraryResetList();
    libraryLoadPage('down');
});
// ---------------------------------------------------------------------------------------------
$(document).on('click', '#libraryStatsTable th.library-stats-sort', function () {
    let $th = $(this);
    let $table = $th.closest('table');
    let $tbody = $table.find('tbody');
    let col = $th.index();
    let type = $th.attr('data-sort') || 'text';
    let dir = $th.hasClass('sort-asc') ? 'desc' : 'asc';

    $table.find('th.library-stats-sort').removeClass('sort-asc sort-desc').attr('aria-sort', 'none');
    $table.find('th.library-stats-sort .library-stats-sort-icon')
        .removeClass('fa-sort-up fa-sort-down')
        .addClass('fa-sort');
    $th.addClass(dir == 'asc' ? 'sort-asc' : 'sort-desc').attr('aria-sort', dir == 'asc' ? 'ascending' : 'descending');
    $th.find('.library-stats-sort-icon')
        .removeClass('fa-sort')
        .addClass(dir == 'asc' ? 'fa-sort-up' : 'fa-sort-down');

    let rows = $tbody.find('tr').get();
    rows.sort(function (a, b) {
        let aVal = $(a).children().eq(col).attr('data-value');
        let bVal = $(b).children().eq(col).attr('data-value');
        if (type == 'number') {
            aVal = parseFloat(aVal) || 0;
            bVal = parseFloat(bVal) || 0;
            return dir == 'asc' ? aVal - bVal : bVal - aVal;
        }
        aVal = String(aVal || '').toLowerCase();
        bVal = String(bVal || '').toLowerCase();
        if (aVal < bVal) {
            return dir == 'asc' ? -1 : 1;
        }
        if (aVal > bVal) {
            return dir == 'asc' ? 1 : -1;
        }
        return 0;
    });
    $.each(rows, function (_, row) {
        $tbody.append(row);
    });
});
// ---------------------------------------------------------------------------------------------
$(document).on('click', '.library-card', function () {
    openLibraryItem($(this).attr('data-type'), $(this).attr('data-id'), $(this).attr('data-label'));
});
// ---------------------------------------------------------------------------------------------
$(document).on('click', '.library-series-cell', function (event) {
    event.preventDefault();
    event.stopPropagation();
    openLibrarySeriesWatch($(this));
});
// ---------------------------------------------------------------------------------------------
function openLibrarySeriesWatch(cell)
{
    pageLoadingStart();
    $.ajax({
        url: BASE_URL + 'ajax/library.php',
        type: 'post',
        data: '&event=seriesWatch&seriesId=' + encodeURIComponent(cell.attr('data-series-id') || '')
            + '&userId=' + encodeURIComponent(cell.attr('data-user-id') || '')
            + '&appId=' + encodeURIComponent(cell.attr('data-app-id') || ''),
        success: function (response) {
            pageLoadingStop();
            let header = (cell.attr('data-username') || '') + ' - ' + (cell.attr('data-app-name') || '');
            popoutOpen({
                header: header,
                content: response,
                position: 'right',
                width: 813
            });
        },
        error: function () {
            pageLoadingStop();
            toast(translate('library'), translate('unableToLoadPage'), 'error');
        }
    });
}
