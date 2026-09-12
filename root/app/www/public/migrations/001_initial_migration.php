<?php

/*
----------------------------------
------  Created: 091226   ------
------  Austin Best       ------
----------------------------------
*/

$q   = [];
$q[] = "CREATE DATABASE IF NOT EXISTS `" . $database->prepare(DB_NAME) . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci";

$error = false;

foreach ($q as $query) {
    $database->query($query);

    if ($migrationError = $database->error()) {
        if (!str_contains($migrationError, 'Duplicate entry')) {
            $error = true;
        }
    }
}

mysqli_select_db($database->db, DB_NAME);

$q   = [];
$q[] = "CREATE TABLE IF NOT EXISTS `" . USERS_TABLE . "` (
        `id` int NOT NULL AUTO_INCREMENT,
        `username` varchar(255) NOT NULL,
        `password` varchar(255) NOT NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `username` (`username`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

$q[] = "CREATE TABLE IF NOT EXISTS `" . SETTINGS_TABLE . "` (
        `id` int NOT NULL AUTO_INCREMENT,
        `name` varchar(150) NOT NULL,
        `value` text NOT NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `name` (`name`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

$q[] = "CREATE TABLE IF NOT EXISTS `" . NOTIFICATION_PLATFORM_TABLE . "` (
        `id` int NOT NULL AUTO_INCREMENT,
        `platform` varchar(255) NOT NULL,
        `parameters` text NOT NULL,
        PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

$q[] = "CREATE TABLE IF NOT EXISTS `" . NOTIFICATION_TRIGGER_TABLE . "` (
        `id` int NOT NULL AUTO_INCREMENT,
        `name` varchar(255) NOT NULL,
        `label` varchar(255) NOT NULL,
        `description` varchar(255) NOT NULL,
        `event` varchar(255) NOT NULL,
        PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

$q[] = "CREATE TABLE IF NOT EXISTS `" . NOTIFICATION_LINK_TABLE . "` (
        `id` int NOT NULL AUTO_INCREMENT,
        `name` varchar(255) NOT NULL,
        `platform` int NOT NULL,
        `platform_parameters` text NOT NULL,
        `trigger_ids` text NOT NULL,
        PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

$q[] = "CREATE TABLE IF NOT EXISTS `" . MEDIA_APP_TABLE . "` (
        `id` int NOT NULL AUTO_INCREMENT,
        `name` varchar(255) NOT NULL,
        `platform` int NOT NULL,
        `url` varchar(500) NOT NULL,
        `token` text NOT NULL,
        `apikey` text NOT NULL,
        `role` int NOT NULL,
        `sync_mode` int NOT NULL,
        `active` int NOT NULL,
        `server_id` varchar(255) NOT NULL,
        `server_name` varchar(255) NOT NULL,
        `last_scan` int NOT NULL,
        `needs_sync` int NOT NULL,
        PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

$q[] = "CREATE TABLE IF NOT EXISTS `" . MEDIA_APP_USER_TABLE . "` (
        `id` int NOT NULL AUTO_INCREMENT,
        `media_app_id` int NOT NULL,
        `remote_id` varchar(255) NOT NULL,
        `username` varchar(255) NOT NULL,
        `email` varchar(255) NOT NULL,
        `user_type` varchar(50) NOT NULL,
        `is_admin` int NOT NULL,
        `last_seen` int NOT NULL,
        PRIMARY KEY (`id`),
        KEY `media_app_id` (`media_app_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

$q[] = "CREATE TABLE IF NOT EXISTS `" . MEDIA_APP_USER_LINK_TABLE . "` (
        `id` int NOT NULL AUTO_INCREMENT,
        `media_app_user_id` int NOT NULL,
        `linked_media_app_user_id` int NOT NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `linked_media_app_user_id` (`linked_media_app_user_id`),
        KEY `media_app_user_id` (`media_app_user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

$q[] = "CREATE TABLE IF NOT EXISTS `" . MEDIA_APP_LIBRARY_LINK_TABLE . "` (
        `id` int NOT NULL AUTO_INCREMENT,
        `media_app_id` int NOT NULL,
        `library_key` varchar(255) NOT NULL,
        `linked_media_app_id` int NOT NULL,
        `linked_library_key` varchar(255) NOT NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `linked_library` (`linked_media_app_id`, `linked_library_key`),
        KEY `media_app_library` (`media_app_id`, `library_key`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

$q[] = "CREATE TABLE IF NOT EXISTS `" . MOVIE_TABLE . "` (
        `id` int NOT NULL AUTO_INCREMENT,
        `title` varchar(255) NOT NULL,
        `year` int NOT NULL,
        `path` varchar(1024) NOT NULL,
        `plex` int NOT NULL,
        `emby` int NOT NULL,
        `jellyfin` int NOT NULL,
        `plex_remote_id` varchar(255) NOT NULL,
        `emby_remote_id` varchar(255) NOT NULL,
        `jellyfin_remote_id` varchar(255) NOT NULL,
        PRIMARY KEY (`id`),
        KEY `path` (`path`(255)),
        KEY `plex_remote_id` (`plex_remote_id`),
        KEY `emby_remote_id` (`emby_remote_id`),
        KEY `jellyfin_remote_id` (`jellyfin_remote_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

$q[] = "CREATE TABLE IF NOT EXISTS `" . SERIES_TABLE . "` (
        `id` int NOT NULL AUTO_INCREMENT,
        `title` varchar(255) NOT NULL,
        `year` int NOT NULL,
        `path` varchar(1024) NOT NULL,
        `plex` int NOT NULL,
        `emby` int NOT NULL,
        `jellyfin` int NOT NULL,
        `plex_remote_id` varchar(255) NOT NULL,
        `emby_remote_id` varchar(255) NOT NULL,
        `jellyfin_remote_id` varchar(255) NOT NULL,
        PRIMARY KEY (`id`),
        KEY `path` (`path`(255)),
        KEY `plex_remote_id` (`plex_remote_id`),
        KEY `emby_remote_id` (`emby_remote_id`),
        KEY `jellyfin_remote_id` (`jellyfin_remote_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

$q[] = "CREATE TABLE IF NOT EXISTS `" . EPISODE_TABLE . "` (
        `id` int NOT NULL AUTO_INCREMENT,
        `series_id` int NOT NULL,
        `season` int NOT NULL,
        `episode` int NOT NULL,
        `title` varchar(255) NOT NULL,
        `path` varchar(1024) NOT NULL,
        `plex` int NOT NULL,
        `emby` int NOT NULL,
        `jellyfin` int NOT NULL,
        `plex_remote_id` varchar(255) NOT NULL,
        `emby_remote_id` varchar(255) NOT NULL,
        `jellyfin_remote_id` varchar(255) NOT NULL,
        PRIMARY KEY (`id`),
        KEY `series_id` (`series_id`),
        KEY `path` (`path`(255)),
        KEY `plex_remote_id` (`plex_remote_id`),
        KEY `emby_remote_id` (`emby_remote_id`),
        KEY `jellyfin_remote_id` (`jellyfin_remote_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

$q[] = "CREATE TABLE IF NOT EXISTS `" . USER_MOVIE_LINK_TABLE . "` (
        `id` int NOT NULL AUTO_INCREMENT,
        `movie_id` int NOT NULL,
        `media_app_user_id` int NOT NULL,
        `platform` int NOT NULL,
        `started` int NOT NULL,
        `inprogress` int NOT NULL,
        `finished` int NOT NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `user_movie_platform` (`media_app_user_id`, `movie_id`, `platform`),
        KEY `movie_id` (`movie_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

$q[] = "CREATE TABLE IF NOT EXISTS `" . USER_EPISODE_LINK_TABLE . "` (
        `id` int NOT NULL AUTO_INCREMENT,
        `episode_id` int NOT NULL,
        `media_app_user_id` int NOT NULL,
        `platform` int NOT NULL,
        `started` int NOT NULL,
        `inprogress` int NOT NULL,
        `finished` int NOT NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `user_episode_platform` (`media_app_user_id`, `episode_id`, `platform`),
        KEY `episode_id` (`episode_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

foreach ($q as $query) {
    $database->query($query);

    if ($migrationError = $database->error()) {
        if (!str_contains($migrationError, 'Duplicate entry')) {
            $error = true;
        }
    }
}

$sql = "SELECT id, username, password
        FROM " . USERS_TABLE;
$res = $database->query($sql);
$row = $database->fetchAssoc($res);

if (!$row) {
    $passwordHash = password_hash('password', PASSWORD_BCRYPT, ['cost' => 12]);
    $sql          = "INSERT INTO " . USERS_TABLE . "
                    (`username`, `password`)
                    VALUES
                    ('admin', '" . $database->prepare($passwordHash) . "')";
    $database->query($sql);

    if ($migrationError = $database->error()) {
        if (!str_contains($migrationError, 'Duplicate entry')) {
            $error = true;
        }
    }
}

$notifiarrParameters = json_encode([
    'apikey' => [
        'label'       => 'API Key',
        'description' => 'The Notifiarr API key from your profile (integration specific or global)',
        'required'    => true,
        'type'        => 'text',
    ],
]);
$telegramParameters = json_encode([
    'botToken' => [
        'label'       => 'Bot token',
        'description' => 'The token from Telegram after creating a bot',
        'required'    => true,
        'type'        => 'text',
    ],
    'chatId' => [
        'label'       => 'Chat id',
        'description' => 'The chat id to send the notification to',
        'required'    => true,
        'type'        => 'text',
    ],
]);
$mattermostParameters = json_encode([
    'url' => [
        'label'       => 'Webhook URL',
        'description' => 'The url in Mattermost after adding a webhook',
        'required'    => true,
        'type'        => 'text',
    ],
    'username' => [
        'label'       => 'Username',
        'description' => 'Optional display name for incoming webhook messages in Mattermost',
        'type'        => 'text',
    ],
]);

$q   = [];
$q[] = "INSERT INTO " . NOTIFICATION_PLATFORM_TABLE . "
        (`id`, `platform`, `parameters`)
        VALUES
        (" . NotificationPlatforms::NOTIFIARR . ", 'Notifiarr', '" . $database->prepare($notifiarrParameters) . "')";
$q[] = "INSERT INTO " . NOTIFICATION_PLATFORM_TABLE . "
        (`id`, `platform`, `parameters`)
        VALUES
        (" . NotificationPlatforms::TELEGRAM . ", 'Telegram', '" . $database->prepare($telegramParameters) . "')";
$q[] = "INSERT INTO " . NOTIFICATION_PLATFORM_TABLE . "
        (`id`, `platform`, `parameters`)
        VALUES
        (" . NotificationPlatforms::MATTERMOST . ", 'Mattermost', '" . $database->prepare($mattermostParameters) . "')";
$q[] = "INSERT INTO " . NOTIFICATION_TRIGGER_TABLE . "
        (`name`, `label`, `description`, `event`)
        VALUES
        ('test', 'Test', 'Manual test notification from the Notifications page', 'test')";
$q[] = "INSERT INTO " . NOTIFICATION_TRIGGER_TABLE . "
        (`name`, `label`, `description`, `event`)
        VALUES
        ('user_login', 'User login', 'Sent when a user signs in', 'user')";
$q[] = "INSERT INTO " . NOTIFICATION_TRIGGER_TABLE . "
        (`name`, `label`, `description`, `event`)
        VALUES
        ('user_update', 'User update', 'Sent when the account username or password is changed', 'user')";
$q[] = "INSERT INTO " . NOTIFICATION_TRIGGER_TABLE . "
        (`name`, `label`, `description`, `event`)
        VALUES
        ('connection_error', 'Connection error', 'Sent when a media app API connection fails', 'media')";
$q[] = "INSERT INTO " . NOTIFICATION_TRIGGER_TABLE . "
        (`name`, `label`, `description`, `event`)
        VALUES
        ('sync_update', 'Sync update', 'Sent when a media sync completes or reports a change', 'media')";

foreach ($q as $query) {
    $database->query($query);

    if ($migrationError = $database->error()) {
        if (!str_contains($migrationError, 'Duplicate entry')) {
            $error = true;
        }
    }
}

if (!$error) {
    $sql = "INSERT INTO " . SETTINGS_TABLE . "
            (`name`, `value`)
            VALUES
            ('migration', '001')";
    $database->query($sql);

    if ($migrationError = $database->error()) {
        if (!str_contains($migrationError, 'Duplicate entry')) {
            $error = true;
        }
    }

    $sql = "UPDATE " . SETTINGS_TABLE . "
            SET value = '001'
            WHERE name = 'migration'";
    $database->query($sql);

    if ($migrationError = $database->error()) {
        $error = true;
    }
}
