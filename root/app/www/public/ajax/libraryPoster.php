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

$type = $_GET['type'] ?? '';
if ($type != 'movie' && $type != 'series') {
    http_response_code(404);
    exit;
}

$id   = intval($_GET['id'] ?? 0);
$file = $mediaApps->libraryPosterPath($type, $id);
if (!$id) {
    http_response_code(404);
    exit;
}

if ((!is_file($file) || !filesize($file)) && !$mediaApps->ensureLibraryPosterCached($type, $id)) {
    http_response_code(404);
    exit;
}

$bytes = file_get_contents($file);
if ($bytes == false || $bytes == '') {
    http_response_code(404);
    exit;
}

$contentType = 'image/jpeg';
if (str_starts_with($bytes, "\x89PNG")) {
    $contentType = 'image/png';
} else if (str_starts_with($bytes, 'GIF')) {
    $contentType = 'image/gif';
} else if (str_starts_with($bytes, 'RIFF') && str_contains(substr($bytes, 0, 16), 'WEBP')) {
    $contentType = 'image/webp';
}

header('Content-Type: ' . $contentType);
header('Content-Length: ' . strlen($bytes));
header('Cache-Control: public, max-age=86400');
echo $bytes;
exit;
