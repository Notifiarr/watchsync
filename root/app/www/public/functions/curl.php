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
    $response     = !empty($jsonResponse) ? $jsonResponse : $response;
    $error        = json_decode($curlError, true);
    $code         = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    global $cron;
    if (!empty($cron)) {
        $safeUrl = preg_replace('/([?&](X-Plex-Token|api_key|apikey|apiKey|AccessToken)=)[^&]*/i', '$1***', $url);
        $line    = $method . ' [' . intval($code) . '] ' . $safeUrl;
        $code    = intval($code);
        if ($code >= 400 || $code == 0 || $curlError != '') {
            $detail = '';
            if ($curlError != '') {
                $detail = $curlError;
            } else if (is_array($response) || is_object($response)) {
                $detail = json_encode($response);
            } else if (is_string($response) && $response != '') {
                $detail = $response;
            } else if ($response == false) {
                $detail = 'request failed';
            }
            $detail = trim(preg_replace('/\s+/', ' ', strval($detail)));
            if ($detail != '') {
                if (strlen($detail) > 500) {
                    $detail = substr($detail, 0, 500) . '...';
                }
                $line .= ' error=' . $detail;
            }
        }
        $cron->log($line);
    }

    return [
        'url'      => $url,
        'method'   => $method,
        'payload'  => $payload,
        'response' => $response,
        'error'    => $error,
        'code'     => $code,
    ];
}
