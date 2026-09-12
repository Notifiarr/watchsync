<?php

/*
----------------------------------
------  Created: 091026   ------
------  Austin Best       ------
----------------------------------
*/

trait Telegram
{
    public function telegram($botToken, $chatId, $payload, $test = false)
    {
        if (!$botToken) {
            return ['code' => 400, 'error' => 'Missing bot token'];
        }
        if (!$chatId) {
            return ['code' => 400, 'error' => 'Missing chat id'];
        }

        $message = $this->buildTelegramMessage($payload, $test);
        $url     = sprintf(NotificationEndpoints::ENDPOINT_TELEGRAM, $botToken);
        $body    = [
            'chat_id'                  => $chatId,
            'text'                     => $message,
            'parse_mode'               => 'MarkdownV2',
            'disable_web_page_preview' => true,
        ];
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

    public function buildTelegramMessage($payload, $test = false)
    {
        $message = '';

        switch ($payload['event']) {
            case 'test':
                $message .= APP_NAME . ': Test' . "\n\n";
                $message .= $payload['message'];
                $message .= "\n\n";
                break;
            case 'userLogin':
                $message .= APP_NAME . ': User login' . "\n\n";
                $message .= 'Username: ' . $payload['username'];
                $message .= "\n\n";
                break;
            case 'userUpdate':
                $message .= APP_NAME . ': User update' . "\n\n";
                $message .= 'Username: ' . $payload['username'];
                $message .= "\n\n";
                break;
            case 'connectionError':
                $message .= APP_NAME . ': Connection error' . "\n\n";
                $message .= 'Media app: ' . $payload['mediaApp'] . "\n";
                $message .= 'Message: ' . $payload['message'];
                $message .= "\n\n";
                break;
            case 'syncWebhook':
                $message .= APP_NAME . ': Sync webhook' . "\n\n";
                $message .= $this->buildSyncNotificationLines($payload);
                $message .= "\n\n";
                break;
            case 'syncOverview':
                $message .= APP_NAME . ': Sync overview' . "\n\n";
                $message .= $this->buildSyncNotificationLines($payload);
                $message .= "\n\n";
                break;
            case 'backup':
                $message .= APP_NAME . ': Backup' . "\n\n";
                $message .= $this->buildSyncNotificationLines($payload);
                $message .= "\n\n";
                break;
        }

        $message = $test ? $message .= '`[TEST NOTIFICATION]`' : $message;

        return $this->escapeTelegramNotification($message);
    }

    public function escapeTelegramNotification($message)
    {
        $chars = ['-', '.', '(', ')', '<', '>', '=', '[', ']', '_'];
        foreach ($chars as $char) {
            $message = str_replace($char, '\\' . $char, $message);
        }

        return $message;
    }
}
