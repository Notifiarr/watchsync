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

require RELATIVE_PATH . 'loader.php';

if (IS_GUEST) {
    echo '<div class="alert alert-danger mb-0" role="alert">' . htmlEscape(translate('notSignedIn')) . '</div>';
    exit();
}

switch ($_POST['page'] ?? '') {
    case 'mediaApps':
        require RELATIVE_PATH . 'pages/mediaApps/index.php';
        break;
    case 'sync':
        require RELATIVE_PATH . 'pages/sync/index.php';
        break;
    case 'library':
        require RELATIVE_PATH . 'pages/library/index.php';
        break;
    case 'notifications':
        require RELATIVE_PATH . 'pages/notifications/index.php';
        break;
    case 'webhooks':
        require RELATIVE_PATH . 'pages/webhooks/index.php';
        break;
    case 'logs':
        require RELATIVE_PATH . 'pages/logs/index.php';
        break;
    case 'settings':
        require RELATIVE_PATH . 'pages/settings/index.php';
        break;
    default:
        ?>
                                                        <div class="row">
                                                            <div class="col-12">
                                                                <h1 class="h3 mb-3"><?= htmlEscape(translate('pageNotFound')) ?></h1>
                                                                <p class="mb-0"><?= htmlEscape(translate('pageNotAvailable')) ?></p>
                                                            </div>
                                                        </div>
                                                        <?php
                                                        break;
}
