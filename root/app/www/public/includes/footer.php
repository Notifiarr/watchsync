<?php

/*
----------------------------------
------  Created: 091026   ------
------  Austin Best       ------
----------------------------------
*/

?>

<div id="popout-slider" style="display:none;"></div>

<div id="dialog-modal-container">
    <div class="modal fade overlay-modal" id="dialog-modal" style="z-index: 10000 !important;" data-bs-backdrop="static">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" onclick="dialogClose($(this))"></button>
                </div>
                <div class="modal-body"></div>
                <div class="modal-footer"></div>
            </div>
        </div>
    </div>
</div>

<div class="toast-container bottom-0 end-0 p-3" style="z-index: 9999999 !important; position: fixed;"></div>

<div class="modal fade overlay-modal overlay-loading" id="loading-modal" style="z-index: 10001 !important;" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            <div class="spinner-border text-info" role="status"></div>
            <div class="overlay-loading-text"><?= htmlEscape(translate('loadingMessage')) ?></div>
        </div>
    </div>
</div>

<script>window.APP_BASE = <?= json_encode(RELATIVE_PATH) ?>;</script>
<script src="libraries/jquery/js/jquery.min.js?t=<?= filemtime(RELATIVE_PATH . 'libraries/jquery/js/jquery.min.js') ?>"></script>
<script src="libraries/kpopup/kpopup.js?t=<?= filemtime(RELATIVE_PATH . 'libraries/kpopup/kpopup.js') ?>"></script>
<script src="libraries/jquery/js/jquery-ui.min.js?t=<?= filemtime(RELATIVE_PATH . 'libraries/jquery/js/jquery-ui.min.js') ?>"></script>
<script src="libraries/bootstrap/js/bootstrap.bundle.min.js?t=<?= filemtime(RELATIVE_PATH . 'libraries/bootstrap/js/bootstrap.bundle.min.js') ?>"></script>
<script src="libraries/datatable/datatables.min.js?t=<?= filemtime(RELATIVE_PATH . 'libraries/datatable/datatables.min.js') ?>"></script>
<?php
$jsDir   = opendir(RELATIVE_PATH . 'js/');
$jsFiles = [];
while ($file = readdir($jsDir)) {
    if ($file[0] != '.' && !is_dir(RELATIVE_PATH . 'js/' . $file) && str_contains($file, '.js')) {
        $jsFiles[] = RELATIVE_PATH . 'js/' . $file;
    }
}
closedir($jsDir);
sort($jsFiles, SORT_NATURAL | SORT_FLAG_CASE);
foreach ($jsFiles as $jsFile) {
    echo '<script src="' . $jsFile . '?t=' . filemtime($jsFile) . '"></script>';
}
?>
</body>

</html>
