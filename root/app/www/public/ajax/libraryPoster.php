<?php

/*
----------------------------------
------  Created: 091326   ------
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
    http_response_code(403);
    exit;
}

$kind = $_GET['kind'] ?? '';
if ($kind != 'movie' && $kind != 'series') {
    http_response_code(404);
    exit;
}

$id   = intval($_GET['id'] ?? 0);
$file = $mediaApps->libraryPosterPath($kind, $id);
if (!$id) {
    http_response_code(404);
    exit;
}

if ((!is_file($file) || !filesize($file)) && !$mediaApps->ensureLibraryPosterCached($kind, $id)) {
    http_response_code(404);
    exit;
}

$bytes = file_get_contents($file);
if ($bytes == false || $bytes == '') {
    http_response_code(404);
    exit;
}

$type = 'image/jpeg';
if (str_starts_with($bytes, "\x89PNG")) {
    $type = 'image/png';
} else if (str_starts_with($bytes, 'GIF')) {
    $type = 'image/gif';
} else if (str_starts_with($bytes, 'RIFF') && str_contains(substr($bytes, 0, 16), 'WEBP')) {
    $type = 'image/webp';
}

header('Content-Type: ' . $type);
header('Content-Length: ' . strlen($bytes));
header('Cache-Control: public, max-age=86400');
echo $bytes;
exit;
