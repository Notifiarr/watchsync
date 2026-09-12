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

try {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';

    $ok = $user->login($username, $password);
    if ($ok) {
        $notifications->notify(0, 'user_login', [
            'event'    => 'user_login',
            'username' => $_SESSION['userdata']['username'] ?? $username,
        ]);

        echo json_encode([
            'error'   => false,
            'message' => translate('loginSuccessful'),
        ]);
        exit();
    }

    echo json_encode([
        'error'   => true,
        'message' => translate('invalidUsernameOrPassword'),
    ]);
    exit();
} catch (Throwable $error) {
    echo json_encode([
        'error'   => true,
        'message' => $error->getMessage(),
    ]);
    exit();
}
