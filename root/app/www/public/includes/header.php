<?php

/*
----------------------------------
------  Created: 091026   ------
------  Austin Best       ------
----------------------------------
*/

?>

<!doctype html>
<html lang="<?= htmlEscape(CURRENT_LOCALE) ?>">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title><?= htmlEscape(APP_NAME) ?></title>
    <link rel="icon" href="<?= RELATIVE_PATH ?>favicon.ico?t=<?= filemtime(RELATIVE_PATH . 'favicon.ico') ?>" type="image/x-icon" sizes="16x16 32x32 48x48 64x64" />
    <link rel="shortcut icon" href="<?= RELATIVE_PATH ?>favicon.ico?t=<?= filemtime(RELATIVE_PATH . 'favicon.ico') ?>" type="image/x-icon" />
    <link href="libraries/bootstrap/css/bootstrap.min.css?t=<?= filemtime(RELATIVE_PATH . 'libraries/bootstrap/css/bootstrap.min.css') ?>" rel="stylesheet" />
    <link href="libraries/jquery/css/jquery-ui.min.css?t=<?= filemtime(RELATIVE_PATH . 'libraries/jquery/css/jquery-ui.min.css') ?>" rel="stylesheet" />
    <link href="libraries/fontawesome/css/all.min.css?t=<?= filemtime(RELATIVE_PATH . 'libraries/fontawesome/css/all.min.css') ?>" rel="stylesheet" />
    <link href="css/styles.css?t=<?= filemtime(RELATIVE_PATH . 'css/styles.css') ?>" rel="stylesheet" />
    <script type="text/javascript">
        (function () {
            var t = 'dark';
            try {
                t = localStorage.getItem('theme') || 'dark';
            } catch (e) {}
            document.documentElement.setAttribute('data-bs-theme', t);
            window.UI_MODE = t;
        })();
        window.APP_LOCALE = <?= json_encode(CURRENT_LOCALE) ?>;
        window.APP_TRANSLATIONS = <?= json_encode($localization->loadTranslations()) ?>;
    </script>
</head>
<body>
    <header class="app-header fixed-top border-bottom bg-body">
        <div class="container-fluid h-100 d-flex align-items-center justify-content-between">
            <div class="d-flex align-items-center gap-2">
                <button class="btn btn-outline-secondary d-lg-none" type="button" data-bs-toggle="offcanvas" data-bs-target="#mobileSidebar" aria-controls="mobileSidebar" aria-label="<?= htmlEscape(translate('openMenu')) ?>">
                    <i class="fa-solid fa-bars"></i>
                </button>
                <img src="images/watchsync-logo.png" alt="" class="app-logo" />
                <span class="fw-semibold"><?= htmlEscape(APP_NAME) ?></span>
            </div>

            <div class="form-check form-switch m-0" title="<?= htmlEscape(translate('toggleLightOrDarkMode')) ?>">
                <input class="form-check-input" type="checkbox" role="switch" id="themeToggle" />
                <label class="form-check-label" for="themeToggle">
                    <i class="fa-solid fa-moon"></i>
                </label>
            </div>
        </div>
    </header>

    <aside class="sidebar-desktop p-3">
        <nav class="nav nav-pills flex-column gap-2">
            <div class="nav-header text-center"><?= htmlEscape(translate('navigation')) ?></div>
            <a class="nav-link menu-link active" href="#" data-page="mediaApps">
                <i class="fa-solid fa-photo-film"></i> <?= htmlEscape(translate('mediaApps')) ?>
            </a>
            <a class="nav-link menu-link" href="#" data-page="sync">
                <i class="fa-solid fa-rotate"></i> <?= htmlEscape(translate('sync')) ?>
            </a>
            <a class="nav-link menu-link" href="#" data-page="library">
                <i class="fa-solid fa-book"></i> <?= htmlEscape(translate('library')) ?>
            </a>
            <a class="nav-link menu-link" href="#" data-page="notifications">
                <i class="fa-solid fa-bell"></i> <?= htmlEscape(translate('notifications')) ?>
            </a>
            <a class="nav-link menu-link" href="#" data-page="webhooks">
                <i class="fa-solid fa-tower-broadcast"></i> <?= htmlEscape(translate('webhooks')) ?>
            </a>
            <a class="nav-link menu-link" href="#" data-page="logs">
                <i class="fa-solid fa-file-lines"></i> <?= htmlEscape(translate('logs')) ?>
            </a>
            <a class="nav-link menu-link" href="#" data-page="settings">
                <i class="fa-solid fa-gear"></i> <?= htmlEscape(translate('settings')) ?>
            </a>
            <a class="nav-link" href="<?= RELATIVE_PATH ?>login.php?event=logout">
                <i class="fa-solid fa-right-from-bracket"></i> <?= htmlEscape(translate('logout')) ?>
            </a>
        </nav>
        <div class="nav-meta text-small mt-auto pt-3">
            <div class="text-center">
                <?= htmlEscape(translate('branch')) ?>: <?= htmlEscape(gitBranch()) ?>,
                <?= htmlEscape(translate('hash')) ?>: <a href="<?= htmlEscape(APP_GITHUB) ?>/commit/<?= htmlEscape(gitHash()) ?>" target="_blank" class="text-info"><?= htmlEscape(substr(gitHash(), 0, 7)) ?></a>
            </div>
            <div class="mt-2 d-flex justify-content-center gap-2">
                <a href="<?= htmlEscape(APP_GITHUB) ?>" title="<?= htmlEscape(translate('visitGithub', [APP_NAME])) ?>" target="_blank"><i class="fab fa-github fa-fw"></i></a>
                <a href="<?= htmlEscape(APP_DISCORD) ?>" title="<?= htmlEscape(translate('visitDiscord')) ?>" target="_blank"><i class="fab fa-discord fa-fw"></i></a>
            </div>
        </div>
    </aside>

    <div class="offcanvas offcanvas-start" tabindex="-1" id="mobileSidebar" aria-labelledby="mobileSidebarLabel">
        <div class="offcanvas-header">
            <h5 class="offcanvas-title" id="mobileSidebarLabel"><?= htmlEscape(translate('menu')) ?></h5>
            <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="<?= htmlEscape(translate('close')) ?>"></button>
        </div>
        <div class="offcanvas-body d-flex flex-column">
            <nav class="nav nav-pills flex-column gap-2">
                <div class="nav-header text-center"><?= htmlEscape(translate('navigation')) ?></div>
                <a class="nav-link menu-link active" href="#" data-page="mediaApps">
                    <i class="fa-solid fa-photo-film"></i> <?= htmlEscape(translate('mediaApps')) ?>
                </a>
                <a class="nav-link menu-link" href="#" data-page="sync">
                    <i class="fa-solid fa-rotate"></i> <?= htmlEscape(translate('sync')) ?>
                </a>
                <a class="nav-link menu-link" href="#" data-page="library">
                    <i class="fa-solid fa-book"></i> <?= htmlEscape(translate('library')) ?>
                </a>
                <a class="nav-link menu-link" href="#" data-page="notifications">
                    <i class="fa-solid fa-bell"></i> <?= htmlEscape(translate('notifications')) ?>
                </a>
                <a class="nav-link menu-link" href="#" data-page="webhooks">
                    <i class="fa-solid fa-tower-broadcast"></i> <?= htmlEscape(translate('webhooks')) ?>
                </a>
                <a class="nav-link menu-link" href="#" data-page="logs">
                    <i class="fa-solid fa-file-lines"></i> <?= htmlEscape(translate('logs')) ?>
                </a>
                <a class="nav-link menu-link" href="#" data-page="settings">
                    <i class="fa-solid fa-gear"></i> <?= htmlEscape(translate('settings')) ?>
                </a>
                <a class="nav-link" href="<?= RELATIVE_PATH ?>login.php?event=logout">
                    <i class="fa-solid fa-right-from-bracket"></i> <?= htmlEscape(translate('logout')) ?>
                </a>
            </nav>
            <div class="nav-meta text-small mt-auto pt-3">
                <div class="text-center">
                    <?= htmlEscape(translate('branch')) ?>: <?= htmlEscape(gitBranch()) ?>,
                    <?= htmlEscape(translate('hash')) ?>: <a href="<?= htmlEscape(APP_GITHUB) ?>/commit/<?= htmlEscape(gitHash()) ?>" target="_blank" class="text-info"><?= htmlEscape(substr(gitHash(), 0, 7)) ?></a>
                </div>
                <div class="mt-2 d-flex justify-content-center gap-2">
                    <a href="<?= htmlEscape(APP_GITHUB) ?>" title="<?= htmlEscape(translate('visitGithub', [APP_NAME])) ?>" target="_blank"><i class="fab fa-github fa-fw"></i></a>
                    <a href="<?= htmlEscape(APP_DISCORD) ?>" title="<?= htmlEscape(translate('visitDiscord')) ?>" target="_blank"><i class="fab fa-discord fa-fw"></i></a>
                </div>
            </div>
        </div>
    </div>
