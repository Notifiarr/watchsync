<?php

/*
----------------------------------
------  Created: 091026   ------
------  Austin Best       ------
----------------------------------
*/

if (!defined('RELATIVE_PATH')) {
    switch (true) {
        case file_exists('loader.php'):
            define('RELATIVE_PATH', './');
            break;
        case file_exists('../loader.php'):
            define('RELATIVE_PATH', '../');
            break;
        case file_exists('../../loader.php'):
            define('RELATIVE_PATH', '../../');
            break;
    }
}

if (!isset($user) || !$user) {
    require RELATIVE_PATH . 'loader.php';
}

if (($_GET['event'] ?? '') == 'logout') {
    $user->logout();
    echo '<script>window.location.href = ' . json_encode(RELATIVE_PATH . 'login.php') . ';</script>';
    exit();
}

if (isset($_SESSION['userdata']) && $_SESSION['userdata']) {
    echo '<script>window.location.href = ' . json_encode(RELATIVE_PATH . 'index.php') . ';</script>';
    exit();
}
?>
<!doctype html>
<html lang="<?= htmlEscape(CURRENT_LOCALE) ?>" data-bs-theme="dark">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title><?= htmlEscape(translate('loginWithAppName', [APP_NAME])) ?></title>
    <link rel="icon" href="<?= RELATIVE_PATH ?>favicon.ico?t=<?= filemtime(RELATIVE_PATH . 'favicon.ico') ?>" type="image/x-icon" sizes="16x16 32x32 48x48 64x64" />
    <link rel="shortcut icon" href="<?= RELATIVE_PATH ?>favicon.ico?t=<?= filemtime(RELATIVE_PATH . 'favicon.ico') ?>" type="image/x-icon" />
    <link href="<?= RELATIVE_PATH ?>libraries/bootstrap/css/bootstrap.min.css?t=<?= filemtime(RELATIVE_PATH . 'libraries/bootstrap/css/bootstrap.min.css') ?>" rel="stylesheet" />
    <link href="<?= RELATIVE_PATH ?>libraries/fontawesome/css/all.min.css?t=<?= filemtime(RELATIVE_PATH . 'libraries/fontawesome/css/all.min.css') ?>" rel="stylesheet" />
    <link href="<?= RELATIVE_PATH ?>css/styles.css?t=<?= filemtime(RELATIVE_PATH . 'css/styles.css') ?>" rel="stylesheet" />
</head>
<body class="bg-dark text-light">
    <main class="d-flex align-items-center justify-content-center min-vh-100 p-3">
        <div class="card w-100" style="max-width: 420px;">
            <div class="card-body">
                <div class="text-center mb-3">
                    <img src="<?= RELATIVE_PATH ?>images/watchsync-logo.png" alt="" class="app-logo-login" />
                </div>
                <h1 class="h4 mb-3 text-center"><?= htmlEscape(translate('watchsyncLogin')) ?></h1>

                <div class="mb-3">
                    <label class="form-label" for="loginUsername"><?= htmlEscape(translate('username')) ?></label>
                    <input type="text" class="form-control" id="loginUsername" autocomplete="username">
                </div>
                <div class="mb-3">
                    <label class="form-label" for="loginPassword"><?= htmlEscape(translate('password')) ?></label>
                    <input type="password" class="form-control" id="loginPassword" autocomplete="current-password">
                </div>
                <button type="button" class="btn btn-primary w-100" id="loginSubmitBtn" onclick="runLogin()"><?= htmlEscape(translate('login')) ?></button>
            </div>
        </div>
    </main>

    <div class="toast-container bottom-0 end-0 p-3" style="z-index: 10001 !important; position: fixed;"></div>

    <script>window.APP_TRANSLATIONS = <?= json_encode($localization->loadTranslations()) ?>;</script>
    <script src="<?= RELATIVE_PATH ?>libraries/jquery/js/jquery.min.js?t=<?= filemtime(RELATIVE_PATH . 'libraries/jquery/js/jquery.min.js') ?>"></script>
    <script src="<?= RELATIVE_PATH ?>libraries/bootstrap/js/bootstrap.bundle.min.js?t=<?= filemtime(RELATIVE_PATH . 'libraries/bootstrap/js/bootstrap.bundle.min.js') ?>"></script>
    <script src="<?= RELATIVE_PATH ?>js/common.js?t=<?= filemtime(RELATIVE_PATH . 'js/common.js') ?>"></script>
    <script src="<?= RELATIVE_PATH ?>js/localization.js?t=<?= filemtime(RELATIVE_PATH . 'js/localization.js') ?>"></script>
    <script src="<?= RELATIVE_PATH ?>js/login.js?t=<?= filemtime(RELATIVE_PATH . 'js/login.js') ?>"></script>
</body>
</html>
