<?php

/*
----------------------------------
------  Created: 091026   ------
------  Austin Best       ------
----------------------------------
*/

trait Mattermost
{
    public function mattermost($url, $payload, $test = false, $webhookUsername = null)
    {
        if (!$url) {
            return ['code' => 400, 'error' => 'Missing webhook url'];
        }

        $message = $this->buildMattermostMessage($payload, $test);
        $body    = ['text' => $message, 'username' => trim($webhookUsername ?? '')];
        $curl    = curl($url, [], 'POST', json_encode($body, JSON_UNESCAPED_UNICODE));

        $return = ['code' => 200];
        if ($curl['code'] < 200 || $curl['code'] > 299) {
            $return = [
                'code'  => $curl['code'] ?: 500,
                'error' => $curl['error'] ?: $curl['response'],
            ];
        }

        return $return;
    }

    public function buildMattermostMessage($payload, $test = false)
    {
        $message = '';

        switch ($payload['event']) {
            case 'test':
                $message .= '##### ' . APP_NAME . ': Test' . "\n\n";
                $message .= $payload['message'];
                $message .= "\n\n";
                break;
            case 'user_login':
                $message .= '##### ' . APP_NAME . ': User login' . "\n\n";
                $message .= 'Username: ' . $payload['username'];
                $message .= "\n\n";
                break;
            case 'user_update':
                $message .= '##### ' . APP_NAME . ': User update' . "\n\n";
                $message .= 'Username: ' . $payload['username'];
                $message .= "\n\n";
                break;
            case 'connection_error':
                $message .= '##### ' . APP_NAME . ': Connection error' . "\n\n";
                $message .= 'Service: ' . $payload['service'] . "\n";
                $message .= 'Message: ' . $payload['message'];
                $message .= "\n\n";
                break;
            case 'sync_update':
                $message .= '##### ' . APP_NAME . ': Sync update' . "\n\n";
                $message .= 'Service: ' . $payload['service'] . "\n";
                $message .= 'Message: ' . $payload['message'];
                $message .= "\n\n";
                break;
        }

        return $test ? $message .= '`[TEST NOTIFICATION]`' : $message;
    }
}
