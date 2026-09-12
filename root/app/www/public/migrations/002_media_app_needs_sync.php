<?php

/*
----------------------------------
------  Created: 091226   ------
------  Austin Best       ------
----------------------------------
*/

$q   = [];
$q[] = "ALTER TABLE `" . MEDIA_APP_TABLE . "`
        ADD COLUMN `needs_sync` int NOT NULL DEFAULT 0";

foreach ($q as $query) {
    $database->query($query);

    if ($migrationError = $database->error()) {
        if (!str_contains($migrationError, 'Duplicate column name')) {
            $error = true;
        }
    }
}

if (!$error) {
    $sql = "UPDATE " . SETTINGS_TABLE . "
            SET value = '002'
            WHERE name = 'migration'";
    $database->query($sql);

    if ($migrationError = $database->error()) {
        $error = true;
    }
}
