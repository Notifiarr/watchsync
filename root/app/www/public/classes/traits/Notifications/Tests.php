<?php

/*
----------------------------------
------  Created: 091026   ------
------  Austin Best       ------
----------------------------------
*/

trait NotificationTests
{
    public function getTestPayloads()
    {
        return [
            'test' => [
                'event'   => 'test',
                'message' => 'This is a test message sent from ' . APP_NAME,
            ],
            'user_login' => [
                'event'    => 'user_login',
                'username' => 'admin',
            ],
            'user_update' => [
                'event'    => 'user_update',
                'username' => 'admin',
            ],
            'connection_error' => [
                'event'   => 'connection_error',
                'service' => 'plex',
                'message' => 'Unable to reach the media app',
            ],
            'sync_update' => [
                'event'   => 'sync_update',
                'service' => 'plex',
                'message' => 'Sync completed',
            ],
        ];
    }
}
