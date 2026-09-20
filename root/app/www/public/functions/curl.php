<?php

/*
----------------------------------
------  Created: 091126   ------
------  Austin Best       ------
----------------------------------
*/

function curl($url, $headers = [], $method = 'GET', $payload = '', $userPass = [], $timeout = 60)
{
    $payload = !is_string($payload) ? '' : $payload;
    if ($method == 'GET') {
        $payload = '';
    }

    $curlHeaders = [
        'User-Agent: ' . APP_NAME,
        'Expect:',
    ];
    if ($payload != '') {
        $curlHeaders[] = 'Content-Length: ' . strlen($payload);
        $curlHeaders[] = 'Content-Type: application/json';
    }

    if ($headers) {
        foreach ($headers as $header) {
            $curlHeaders[] = $header;
        }
    }

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, min(5, $timeout));
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);

    if (!str_contains($url, '/api/')) {
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    }

    switch ($method) {
        case 'DELETE':
        case 'PATCH':
        case 'PUT':
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
            break;
        case 'POST':
            curl_setopt($ch, CURLOPT_POST, true);
            break;
        default:
            unset($payload);
            break;
    }

    curl_setopt($ch, CURLOPT_HTTPHEADER, $curlHeaders);

    if ($payload && $method != 'GET') {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    }

    if ($userPass) {
        $user = $userPass[0];
        $pass = $userPass[1];
        curl_setopt($ch, CURLOPT_USERPWD, "$user:$pass");
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
    }

    $response     = curl_exec($ch);
    $curlError    = curl_error($ch);
    $jsonResponse = json_decode($response, true);
    $parsed       = !empty($jsonResponse) ? $jsonResponse : $response;
    $error        = json_decode($curlError, true);
    $code         = intval(curl_getinfo($ch, CURLINFO_HTTP_CODE));

    global $cron;
    if (!empty($cron)) {
        $safeUrl = preg_replace('/([?&](X-Plex-Token|api_key|apikey|apiKey|AccessToken)=)[^&]*/i', '$1***', $url);
        $failed  = $code >= 400 || $code == 0 || $curlError != '';
        if ($failed) {
            $body = '';
            if ($curlError != '') {
                $body = $curlError;
            } else if (is_array($parsed) || is_object($parsed)) {
                $body = json_encode($parsed);
            } else if (is_string($parsed) && $parsed != '') {
                $body = $parsed;
            } else if ($response === false) {
                $body = 'request failed';
            }
            $body = trim(preg_replace('/\s+/', ' ', strval($body)));
            if (strlen($body) > 1000) {
                $body = substr($body, 0, 1000) . '...';
            }
            $cron->log('request: ' . $method . ' ' . $safeUrl);
            $cron->log('code: ' . $code);
            $cron->log('response: ' . ($body != '' ? $body : '(empty)'));
        } else {
            $cron->log($method . ' [' . $code . '] ' . $safeUrl);
        }
    }

    return [
        'url'      => $url,
        'method'   => $method,
        'payload'  => $payload ?? '',
        'response' => $parsed,
        'error'    => $error,
        'code'     => $code,
    ];
}
