<?php

/*
----------------------------------
------  Created: 091026   ------
------  Austin Best       ------
----------------------------------
*/

interface NotificationPlatforms
{
    public const NOTIFIARR  = 1;
    public const TELEGRAM   = 2;
    public const MATTERMOST = 3;
}

interface NotificationEndpoints
{
    public const ENDPOINT_NOTIFIARR = 'https://notifiarr.com/api/v1/notification/watchsync';
    public const ENDPOINT_TELEGRAM  = 'https://api.telegram.org/bot%s/sendMessage';
}
