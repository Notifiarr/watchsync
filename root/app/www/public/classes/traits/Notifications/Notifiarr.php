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
        $url     = NotificationEndpoints::ENDPOINT_NOTIFIARR;
        $curl    = curl($url, $headers, 'POST', json_encode($payload, JSON_UNESCAPED_UNICODE));

        $return = ['code' => 200];
        if ($curl['code'] < 200 || $curl['code'] > 299) {
            $detail = $curl['error'] ?: $curl['response'];
            if (is_array($detail) || is_object($detail)) {
                $detail = json_encode($detail);
            }
            $return = [
                'code'  => $curl['code'] ?: 500,
                'error' => trim(strval($detail)),
            ];
        }

        return $return;
    }
}
