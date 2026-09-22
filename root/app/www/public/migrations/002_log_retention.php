<?php

/*
----------------------------------
------  Created: 092126   ------
------  Austin Best       ------
----------------------------------
*/

$q   = [];
$q[] = "INSERT INTO " . SETTINGS_TABLE . "
        (`name`, `value`)
        VALUES
        ('cronLogLength', '1'),
        ('systemLogLength', '1'),
        ('webhookLogLength', '1')";

$error = false;

foreach ($q as $query) {
    $database->query($query);

    if ($migrationError = $database->error()) {
        if (!str_contains($migrationError, 'Duplicate entry')) {
            $error = true;
        }
    }
}

if (!$error) {
    $sql = "UPDATE " . SETTINGS_TABLE . "
            SET value = '002'
            WHERE name = 'migration'";
    $database->query($sql);
    if ($database->error()) {
        $error = true;
    }
}
