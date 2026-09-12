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

if (!isset($_SESSION['userdata']) || !$_SESSION['userdata']) {
    require RELATIVE_PATH . 'login.php';
    exit();
}

require RELATIVE_PATH . 'includes/header.php';

?>
<main class="content-wrapper">
    <div class="container-fluid" id="mainContent"></div>
</main>

<?php
require RELATIVE_PATH . 'includes/footer.php';
