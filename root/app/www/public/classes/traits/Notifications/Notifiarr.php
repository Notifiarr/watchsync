<?php

/*
----------------------------------
------  Created: 091026   ------
------  Austin Best       ------
----------------------------------
*/

trait Notifiarr
{
    public function notifiarr($apikey, $payload)
    {
        if (!$apikey) {
            return ['code' => 400, 'error' => 'Missing API key'];
        }

        $headers = ['x-api-key:' . $apikey];
        $url     = 'https://notifiarr.com/api/v1/notification/watchsync';
        $curl    = curl($url, $headers, 'POST', json_encode($payload, JSON_UNESCAPED_UNICODE));

        $return = ['code' => 200];
        if ($curl['code'] < 200 || $curl['code'] > 299) {
            $return = [
                'code'  => $curl['code'] ?: 500,
                'error' => $curl['error'] ?: $curl['response'],
            ];
        }

        return $return;
    }
}
