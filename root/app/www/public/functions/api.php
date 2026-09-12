<?php

/*
----------------------------------
------  Created: 091626   ------
------  Austin Best       ------
----------------------------------
*/

function generateApikey()
{
    return bin2hex(random_bytes(16));
}

function maskAPI($apikey)
{
    if (!$apikey) {
        return '';
    }

    $start = substr($apikey, 0, 4);
    $end   = substr($apikey, -2);

    return $start . '......' . $end;
}

function apiResponse($code, $message)
{
    global $apiKey, $mediaApp, $apiStarted, $webhookParsed;

    $parsed = is_array($webhookParsed ?? null) ? $webhookParsed : [];
    $event  = strval($parsed['event'] ?? '');
    if (in_array($parsed['action'] ?? '', ['pause', 'stop', 'scrobble', 'new'], true)) {
        $event = $parsed['action'];
    }
    webhookWriteLog($mediaApp ?? '', $parsed['userId'] ?? '', $event, $_POST, $code);

    $parameters = $_GET;
    unset($parameters['apikey']);

    $details = [
        'response'   => $message,
        'help'       => APP_DISCORD,
        'apikey'     => maskAPI($apiKey ?? ''),
        'mediaApp'   => $mediaApp ?? '',
        'parameters' => $parameters ?: new stdClass(),
    ];

    if (intval($code) == 200 && !empty($apiStarted)) {
        $finished            = microtime(true);
        $details['started']  = $apiStarted;
        $details['finished'] = $finished;
        $details['elapsed']  = round($finished - $apiStarted, 4);
    }

    httpCode($code, $details);
}

function httpCode($code, $details = false)
{
    if (session_status() == PHP_SESSION_ACTIVE) {
        session_unset();
        session_destroy();
    }

    $code = intval($code);
    http_response_code($code);

    switch ($code) {
        case 200:
            $result = 'success';
            break;
        case 400:
        case 401:
            $result = 'error';
            break;
        default:
            $result = 'error';
            break;
    }

    $response = [
        'code'    => $code,
        'result'  => $result,
        'details' => $details,
    ];

    $return = json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_FORCE_OBJECT);

    header('Content-Length: ' . strlen($return));
    header('Access-Control-Allow-Origin: *');

    if ($details) {
        header('Content-Type: application/json');
        echo $return;
    }

    die();
}
