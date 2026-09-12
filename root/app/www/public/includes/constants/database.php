<?php

/*
----------------------------------
------  Created: 091026   ------
------  Austin Best       ------
----------------------------------
*/

define('DB_NAME', 'watchsync');
define('DB_USER', 'abc');
define('DB_PASSWORD', 'abc');
define('DB_HOST', 'localhost');

define('USERS_TABLE', 'users');
define('SETTINGS_TABLE', 'settings');
define('MIGRATIONS_TABLE', 'migrations');
define('MIGRATIONS_PATH', RELATIVE_PATH . 'migrations/');
define('NOTIFICATION_PLATFORM_TABLE', 'notification_platform');
define('NOTIFICATION_TRIGGER_TABLE', 'notification_trigger');
define('NOTIFICATION_LINK_TABLE', 'notification_link');
define('MEDIA_APP_TABLE', 'media_app');
define('MEDIA_APP_USER_TABLE', 'media_app_user');
define('MEDIA_APP_USER_LINK_TABLE', 'media_app_user_link');
define('MEDIA_APP_LIBRARY_LINK_TABLE', 'media_app_library_link');
define('MEDIA_APP_LIBRARY_TABLE', 'media_app_library');
define('MOVIE_TABLE', 'movie');
define('SERIES_TABLE', 'series');
define('EPISODE_TABLE', 'episode');
define('USER_MOVIE_LINK_TABLE', 'user_movie_link');
define('USER_EPISODE_LINK_TABLE', 'user_episode_link');
define('SYNC_HISTORY_TABLE', 'sync_history');
