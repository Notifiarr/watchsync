<?php

/*
----------------------------------
------  Created: 091026   ------
------  Austin Best       ------
----------------------------------
*/

trait NotificationTemplates
{
    public function getTemplate($trigger)
    {
        switch ($trigger) {
            case 'test':
                return [
                    'event'   => '',
                    'message' => '',
                ];
            case 'user_login':
                return [
                    'event'    => '',
                    'username' => '',
                ];
            case 'user_update':
                return [
                    'event'    => '',
                    'username' => '',
                ];
            case 'connection_error':
                return [
                    'event'   => '',
                    'service' => '',
                    'message' => '',
                ];
            case 'sync_update':
                return [
                    'event'   => '',
                    'service' => '',
                    'message' => '',
                ];
            default:
                return [];
        }
    }
}
