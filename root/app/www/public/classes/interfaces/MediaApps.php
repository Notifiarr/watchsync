<?php

/*
----------------------------------
------  Created: 091026   ------
------  Austin Best       ------
----------------------------------
*/

interface MediaPlatforms
{
    public const PLEX     = 1;
    public const EMBY     = 2;
    public const JELLYFIN = 3;
}

interface MediaAppRoles
{
    public const MASTER   = 1;
    public const LISTENER = 2;
}

interface MediaSyncModes
{
    public const BOTH = 1;
    public const PUSH = 2;
    public const PULL = 3;
}

interface MediaSyncTypes
{
    public const LIBRARY   = 1;
    public const USERS     = 2;
    public const HISTORY   = 3;
    public const LIBRARIES = 4;
}

interface MediaSyncTriggers
{
    public const MANUAL     = 1;
    public const AUTOMATIC  = 2;
}

interface MediaTypes
{
    public const MOVIE   = 1;
    public const EPISODE = 2;
}

interface MediaLibraryScans
{
    public const LAST_SCAN = 1;
    public const FULL      = 2;
}
