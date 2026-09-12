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

    public function buildMattermostMessage($payload, $test = false)
    {
        $message = '';

        switch ($payload['event']) {
            case 'test':
                $message .= '##### ' . APP_NAME . ': Test' . "\n\n";
                $message .= $payload['message'];
                $message .= "\n\n";
                break;
            case 'userLogin':
                $message .= '##### ' . APP_NAME . ': User login' . "\n\n";
                $message .= 'Username: ' . $payload['username'];
                $message .= "\n\n";
                break;
            case 'userUpdate':
                $message .= '##### ' . APP_NAME . ': User update' . "\n\n";
                $message .= 'Username: ' . $payload['username'];
                $message .= "\n\n";
                break;
            case 'connectionError':
                $message .= '##### ' . APP_NAME . ': Connection error' . "\n\n";
                $message .= 'Media app: ' . $payload['mediaApp'] . "\n";
                $message .= 'Message: ' . $payload['message'];
                $message .= "\n\n";
                break;
            case 'syncStart':
                $message .= '##### ' . APP_NAME . ': Sync start' . "\n\n";
                $message .= $this->buildSyncNotificationLines($payload);
                $message .= "\n\n";
                break;
            case 'syncEnd':
                $message .= '##### ' . APP_NAME . ': Sync end' . "\n\n";
                $message .= $this->buildSyncNotificationLines($payload);
                $message .= "\n\n";
                break;
            case 'backup':
                $message .= '##### ' . APP_NAME . ': Backup' . "\n\n";
                $message .= $this->buildSyncNotificationLines($payload);
                $message .= "\n\n";
                break;
        }

        return $test ? $message .= '`[TEST NOTIFICATION]`' : $message;
    }
}
