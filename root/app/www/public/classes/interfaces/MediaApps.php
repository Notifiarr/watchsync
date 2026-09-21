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
    public const MANUAL    = 1;
    public const AUTOMATIC = 2;
    public const WEBHOOK   = 3;
}

interface MediaSyncIntervals
{
    public const PARITY  = 86400;
    public const LIBRARY = 3600;
    public const HISTORY = 43200;
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

interface MediaAppEndpoints
{
    public const ENDPOINT_PLEX_ONLINE                 = '%s/identity';
    public const ENDPOINT_PLEX_SERVER                 = '%s/';
    public const ENDPOINT_PLEX_ACCOUNTS               = '%s/accounts';
    public const ENDPOINT_PLEX_LIBRARIES              = '%s/library/sections';
    public const ENDPOINT_PLEX_LIBRARIES_CREATE       = '%s/library/sections?%s';
    public const ENDPOINT_PLEX_LIBRARY                = '%s/library/sections/%s';
    public const ENDPOINT_PLEX_LIBRARY_ITEMS          = '%s/library/sections/%s/all?type=%s&X-Plex-Container-Start=%s&X-Plex-Container-Size=%s';
    public const ENDPOINT_PLEX_SERIES_EPISODES        = '%s/library/metadata/%s/allLeaves?X-Plex-Container-Start=%s&X-Plex-Container-Size=%s';
    public const ENDPOINT_PLEX_METADATA               = '%s/library/metadata/%s?includeGuids=1&checkFiles=1';
    public const ENDPOINT_PLEX_HISTORY                = '%s/status/sessions/history/all?accountID=%s&sort=viewedAt:desc&X-Plex-Container-Start=%s&X-Plex-Container-Size=%s';
    public const ENDPOINT_PLEX_HISTORY_RECENT         = '%s/status/sessions/history/all?sort=viewedAt:desc&X-Plex-Container-Start=%s&X-Plex-Container-Size=%s';
    public const ENDPOINT_PLEX_SCROBBLE               = '%s/:/scrobble?identifier=com.plexapp.plugins.library&key=%s';
    public const ENDPOINT_PLEX_UNSCROBBLE             = '%s/:/unscrobble?identifier=com.plexapp.plugins.library&key=%s';
    public const ENDPOINT_PLEX_PROGRESS               = '%s/:/progress?identifier=com.plexapp.plugins.library&key=%s&time=%s&state=stopped';
    public const ENDPOINT_PLEX_TV_HOME_USERS          = 'https://plex.tv/api/v2/home/users/';
    public const ENDPOINT_PLEX_TV_HOME_USER_CREATE    = 'https://plex.tv/api/v2/home/users/restricted';
    public const ENDPOINT_PLEX_TV_HOME_USER_SWITCH    = 'https://clients.plex.tv/api/v2/home/users/%s/switch';
    public const ENDPOINT_PLEX_TV_HOME_USER_SWITCH_ID = 'https://plex.tv/api/home/users/%s/switch';
    public const ENDPOINT_PLEX_TV_HOME_USERS_XML      = 'https://plex.tv/api/home/users';
    public const ENDPOINT_PLEX_TV_USERS               = 'https://plex.tv/api/users/';
    public const ENDPOINT_PLEX_TV_RESOURCES           = 'https://plex.tv/api/v2/resources?includeIPv6=1&includeHttps=1&includeRelay=1';
    public const ENDPOINT_PLEX_TV_SERVER              = 'https://plex.tv/api/servers/%s';
    public const ENDPOINT_PLEX_TV_SHARED_SERVERS      = 'https://plex.tv/api/servers/%s/shared_servers';
    public const ENDPOINT_PLEX_TV_SHARED_SERVER       = 'https://plex.tv/api/servers/%s/shared_servers/%s';
    public const ENDPOINT_PLEX_TV_SHARED_SERVERS_V2   = 'https://plex.tv/api/v2/shared_servers';
    public const ENDPOINT_PLEX_TV_SHARED_SERVER_V2    = 'https://plex.tv/api/v2/shared_servers/%s';

    public const ENDPOINT_EMBY_ONLINE                 = '%s/System/Info/Public';
    public const ENDPOINT_EMBY_INFO                   = '%s/System/Info';
    public const ENDPOINT_EMBY_LIBRARIES              = '%s/Library/MediaFolders';
    public const ENDPOINT_EMBY_SELECTABLE_FOLDERS     = '%s/Library/SelectableMediaFolders';
    public const ENDPOINT_EMBY_VIRTUAL_FOLDERS        = '%s/Library/VirtualFolders';
    public const ENDPOINT_EMBY_VIRTUAL_FOLDERS_Q      = '%s/Library/VirtualFolders?%s';
    public const ENDPOINT_EMBY_VIRTUAL_FOLDERS_DELETE = '%s/Library/VirtualFolders/Delete?%s';
    public const ENDPOINT_EMBY_ITEMS                  = '%s/Items?%s';
    public const ENDPOINT_EMBY_USER_ITEMS             = '%s/Users/%s/Items?%s';
    public const ENDPOINT_EMBY_USER_ITEM              = '%s/Users/%s/Items/%s';
    public const ENDPOINT_EMBY_PLAYED_ITEM            = '%s/Users/%s/PlayedItems/%s';
    public const ENDPOINT_EMBY_USER_DATA              = '%s/Users/%s/Items/%s/UserData';
    public const ENDPOINT_EMBY_USERS                  = '%s/Users';
    public const ENDPOINT_EMBY_USER                   = '%s/Users/%s';
    public const ENDPOINT_EMBY_USER_NEW               = '%s/Users/New';
    public const ENDPOINT_EMBY_USER_PASSWORD          = '%s/Users/%s/Password';
    public const ENDPOINT_EMBY_USER_POLICY            = '%s/Users/%s/Policy';
    public const ENDPOINT_EMBY_AUTH                   = '%s/Users/AuthenticateByName';

    public const ENDPOINT_JELLYFIN_ONLINE            = '%s/System/Info/Public';
    public const ENDPOINT_JELLYFIN_INFO              = '%s/System/Info';
    public const ENDPOINT_JELLYFIN_LIBRARIES         = '%s/Library/MediaFolders';
    public const ENDPOINT_JELLYFIN_VIRTUAL_FOLDERS   = '%s/Library/VirtualFolders';
    public const ENDPOINT_JELLYFIN_VIRTUAL_FOLDERS_Q = '%s/Library/VirtualFolders?%s';
    public const ENDPOINT_JELLYFIN_ITEMS             = '%s/Items?%s';
    public const ENDPOINT_JELLYFIN_USER_ITEMS        = '%s/Users/%s/Items?%s';
    public const ENDPOINT_JELLYFIN_USER_ITEM         = '%s/Users/%s/Items/%s';
    public const ENDPOINT_JELLYFIN_PLAYED_ITEM       = '%s/Users/%s/PlayedItems/%s';
    public const ENDPOINT_JELLYFIN_USER_DATA         = '%s/Users/%s/Items/%s/UserData';
    public const ENDPOINT_JELLYFIN_USERS             = '%s/Users';
    public const ENDPOINT_JELLYFIN_USER              = '%s/Users/%s';
    public const ENDPOINT_JELLYFIN_USER_NEW          = '%s/Users/New';
    public const ENDPOINT_JELLYFIN_USER_PASSWORD     = '%s/Users/%s/Password';
    public const ENDPOINT_JELLYFIN_USER_POLICY       = '%s/Users/%s/Policy';
    public const ENDPOINT_JELLYFIN_AUTH              = '%s/Users/AuthenticateByName';

    public const PARITY_DEFAULT_USER_PASSWORD = 'Password123!';
}
