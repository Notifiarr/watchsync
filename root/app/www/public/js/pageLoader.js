$(function () {
    function setActiveMenu(pageKey) {
        $('.menu-link[data-page]').removeClass('active');
        $('.menu-link[data-page="' + pageKey + '"]').addClass('active');
    }

    function loadPage(pageKey) {
        pageLoadingStart();
        $.ajax({
            url: BASE_URL + 'pages/index.php',
            type: 'POST',
            data: { page: pageKey },
            cache: false,
            success: function (html) {
                $('#mainContent').html(html);
                setActiveMenu(pageKey);
                $(document).trigger('page:loaded', [pageKey]);
                pageLoadingStop();
            },
            error: function () {
                var msg = typeof translate == 'function' ? translate('unableToLoadPage') : 'Unable to load page.';
                $('#mainContent').html(
                    '<div class="alert alert-danger mb-0" role="alert">' + msg + '</div>'
                );
                pageLoadingStop();
            }
        });
    }

    window.loadPage = loadPage;

    $(document).on('click', '.menu-link[data-page]', function (event) {
        event.preventDefault();
        loadPage($(this).data('page'));
    });

    const initialPage = $('.menu-link.active[data-page]').first().data('page') || 'mediaApps';
    loadPage(initialPage);
});
