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

loggedOutResponse();


function libraryBrowserRequest()
{
    global $database, $mediaApps;

    $type    = $_POST['type'] ?? 'all';
    $type    = in_array($type, ['movie', 'series'], true) ? $type : 'all';
    $userId  = intval($_POST['userId'] ?? 0);
    $watched = strtolower(trim(strval($_POST['watched'] ?? 'all')));
    if (!in_array($watched, ['never', 'started', 'completed'], true)) {
        $watched = 'all';
    }
    $userIds = $database->libraryBrowserFilterUserIds($userId, $watched);
    $letter  = strtoupper(trim(strval($_POST['letter'] ?? '')));
    if ($letter != '' && $letter != '#' && !preg_match('/^[A-Z]$/', $letter)) {
        $letter = '';
    }
    $libraryKey = trim(strval($_POST['libraryKey'] ?? ''));
    $roots      = [];
    if ($libraryKey != '') {
        $master = $mediaApps->masterMediaApp();
        if ($master) {
            $roots = $mediaApps->libraryPathsForKey(intval($master['id']), $libraryKey);
        }
    }

    return [
        'type'       => $type,
        'userIds'    => $userIds,
        'watched'    => $watched,
        'libraryKey' => $libraryKey,
        'roots'      => $roots,
        'letter'     => $letter,
        'limit'      => 50,
    ];
}

function libraryBrowserItemPayload($row)
{
    global $database, $mediaApps;

    $type  = ($row['type'] ?? '') == 'series' ? 'series' : 'movie';
    $id    = intval($row['id']);
    $title = $row['title'] ?? '';
    $year  = intval($row['year'] ?? 0);
    $label = $title;
    if ($year) {
        $label .= ' (' . $year . ')';
    }

    return [
        'id'       => $id,
        'type'     => $type,
        'title'    => $title,
        'year'     => $year,
        'letter'   => $database->libraryBrowserLetter($title),
        'label'    => $label,
        'watchers' => intval($row['watchers'] ?? 0),
        'poster'   => trim(strval($row['poster'] ?? '')) != '' || $mediaApps->libraryPosterExists($type, $id)
            ? 'ajax/libraryPoster.php?type=' . $type . '&id=' . $id
            : '',
    ];
}

switch ($_POST['event'] ?? '') {
    case 'letters':
        $request = libraryBrowserRequest();
        echo json_encode([
            'error'   => false,
            'letters' => $database->getLibraryBrowserLetters($request['type'], $request['userIds'], $request['watched'], $request['roots']),
        ]);
        exit;

    case 'items':
        $request   = libraryBrowserRequest();
        $direction = ($_POST['direction'] ?? '') == 'up' ? 'up' : 'down';
        $cursor    = [];
        if (trim(strval($_POST['title'] ?? '')) != '' || intval($_POST['id'] ?? 0)) {
            $cursor = [
                'title' => strval($_POST['title'] ?? ''),
                'type'  => ($_POST['itemType'] ?? '') == 'series' ? 'series' : 'movie',
                'id'    => intval($_POST['id'] ?? 0),
            ];
        }
        $rows  = $database->getLibraryBrowserItems($request['type'], $request['userIds'], $cursor, $direction, $request['letter'], $request['limit'], $request['watched'], $request['roots']);
        $items = [];
        foreach ($rows as $row) {
            $items[] = libraryBrowserItemPayload($row);
        }
        echo json_encode([
            'error' => false,
            'items' => $items,
            'done'  => count($items) < $request['limit'],
        ]);
        exit;

    case 'itemWatch':
        $type = ($_POST['type'] ?? '') == 'series' ? 'series' : 'movie';
        $id   = intval($_POST['id'] ?? 0);
        $item = $database->getLibraryItem($type, $id);
        if (!$item) {
            echo '<div class="alert alert-danger mb-0" role="alert">' . htmlEscape(translate('libraryItemNotFound')) . '</div>';
            exit;
        }
        $watch = $database->getLibraryItemWatchMatrix($type, $id);
        require RELATIVE_PATH . 'pages/library/itemWatch.php';
        exit;

    case 'seriesWatch':
        $seriesId = intval($_POST['seriesId'] ?? 0);
        $userId   = intval($_POST['userId'] ?? 0);
        $appId    = intval($_POST['appId'] ?? 0);
        $series   = $database->getSeries($seriesId);
        $detail   = $database->getLibrarySeriesUserEpisodes($seriesId, $userId, $appId);
        if (!$series || !$detail) {
            echo '<div class="alert alert-danger mb-0" role="alert">' . htmlEscape(translate('libraryItemNotFound')) . '</div>';
            exit;
        }
        require RELATIVE_PATH . 'pages/library/seriesWatch.php';
        exit;

    default:
        echo json_encode(['error' => true, 'message' => translate('unknownSettingsEvent')]);
        exit;
}
