<?php

/*
----------------------------------
------  Created: 091026   ------
------  Austin Best       ------
----------------------------------
*/

loadClassTraits(RELATIVE_PATH . 'classes/traits/Database/');

class Database
{
    use Users;
    use NotificationPlatform;
    use NotificationTrigger;
    use NotificationLink;
    use MediaApp;
    use MediaAppUser;
    use MediaAppUserLink;
    use MediaAppLibraryLink;
    use MediaLibrary;
    use Movie;
    use Series;
    use Episode;
    use UserMovieLink;
    use UserEpisodeLink;

    public $db;
    public $notificationPlatformTable;
    public $notificationTriggersTable;
    public $notificationLinkTable;
    public $migrationStart = '001';

    public function __construct()
    {
        $this->connect();
        $this->migrations();
    }

    public function connect()
    {
        $mysqlSetup = false;

        logger(SYSTEM_LOG, 'MYSQL: Connecting to \'' . DB_NAME . ' -> ' . DB_USER . '@' . DB_HOST . '\'...');

        try {
            $this->db = mysqli_connect(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
        } catch (Exception $e) {
            $message = $e->getMessage();
            logger(SYSTEM_LOG, 'MYSQL: Failed to connect to \'' . DB_NAME . ' -> ' . DB_USER . '@' . DB_HOST . '\': ' . $message);

            if (str_contains($message, 'Unknown database')) {
                logger(SYSTEM_LOG, 'MYSQL: Connecting to \'' . DB_USER . '@' . DB_HOST . '\'...');
                try {
                    $this->db   = mysqli_connect(DB_HOST, DB_USER, DB_PASSWORD);
                    $mysqlSetup = true;
                } catch (Exception $retry) {
                    logger(SYSTEM_LOG, 'MYSQL: Failed to connect to \'' . DB_USER . '@' . DB_HOST . '\': ' . $retry->getMessage());
                }
            } else if (str_contains($message, 'No such file or directory') || str_contains($message, 'Connection refused')) {
                logger(SYSTEM_LOG, 'MYSQL: Connecting to \'' . DB_USER . '@127.0.0.1\'...');
                try {
                    $this->db   = mysqli_connect('127.0.0.1', DB_USER, DB_PASSWORD);
                    $mysqlSetup = true;
                } catch (Exception $retry) {
                    logger(SYSTEM_LOG, 'MYSQL: Failed to connect to \'' . DB_USER . '@127.0.0.1\': ' . $retry->getMessage());
                }
            } else {
                logger(SYSTEM_LOG, 'All connection attempts failed');
            }
        }

        if (!$mysqlSetup && $this->db && !$this->mysqliBaseTablesExist()) {
            logger(SYSTEM_LOG, 'MYSQL: Database exists but base tables are missing, running setup');
            $mysqlSetup = true;
        }

        define('MYSQL_SETUP', $mysqlSetup);

        if ($this->db) {
            mysqli_set_charset($this->db, 'utf8mb4');
        }
    }

    public function mysqliTableExists($table)
    {
        if (!$this->db || $table == '') {
            return false;
        }

        try {
            $sql = "SHOW TABLES LIKE '" . $this->prepare($table) . "'";
            $res = $this->query($sql);
            return $res && $this->fetchAssoc($res) ? true : false;
        } catch (Exception $e) {
            logger(SYSTEM_LOG, 'MYSQL: Table check failed for \'' . $table . '\': ' . $e->getMessage());
            return false;
        }
    }

    public function mysqliBaseTablesExist()
    {
        $requiredTables = [
            SETTINGS_TABLE,
            USERS_TABLE,
        ];

        foreach ($requiredTables as $table) {
            if (!$this->mysqliTableExists($table)) {
                logger(SYSTEM_LOG, 'MYSQL: Required table missing: ' . $table);
                return false;
            }
        }

        return true;
    }

    public function query($sql)
    {
        return mysqli_query($this->db, $sql);
    }

    public function fetchAssoc($res)
    {
        if (!$res) {
            return;
        }

        return mysqli_fetch_assoc($res);
    }

    public function insertId()
    {
        return mysqli_insert_id($this->db);
    }

    public function matchedRows()
    {
        $info = mysqli_info($this->db);
        if ($info && preg_match('/Rows matched:\s+(\d+)/', $info, $matches)) {
            return intval($matches[1]);
        }

        return intval(mysqli_affected_rows($this->db));
    }

    public function error()
    {
        return $this->db ? mysqli_error($this->db) : '';
    }

    public function prepare($val)
    {
        return dbPrepare($val);
    }

    public function getNewestMigration()
    {
        $newestMigration = $this->migrationStart;
        $dir             = opendir(MIGRATIONS_PATH);
        while ($migration = readdir($dir)) {
            if (intval(substr($migration, 0, 3)) > intval($newestMigration) && str_contains($migration, '.php')) {
                $newestMigration = substr($migration, 0, 3);
            }
        }
        closedir($dir);

        return $newestMigration;
    }

    public function migrations()
    {
        if (!IS_STARTUP) {
            return;
        }

        $database = $this; //-- USED IN THE MIGRATIONS FILES
        $q        = []; //-- RESET THE QUERY ARRAY FOR THE INITIAL SETUP

        if (MYSQL_SETUP) {
            setFile(MIGRATION_FILE, ['started' => date('c')]);
            logger(SYSTEM_LOG, 'Creating database and applying migration ' . $this->migrationStart . '_initial_migration');
            logger(SYSTEM_LOG, 'Review the migration log for specific migration query details: ' . MIGRATION_LOG);
            logger(MIGRATION_LOG, 'migration ' . $this->migrationStart . ' ->');
            $error = false;
            require MIGRATIONS_PATH . $this->migrationStart . '_initial_migration.php';
            logger(MIGRATION_LOG, 'migration ' . $this->migrationStart . ' <-');
            if ($error) {
                deleteFile(MIGRATION_FILE);
                return;
            }

            $currentMigration = $this->migrationStart;
        } else {
            $sql = "SELECT value
                    FROM " . SETTINGS_TABLE . "
                    WHERE name = 'migration'";
            $res = $this->query($sql);
            $row = $this->fetchAssoc($res);

            $currentMigration = $row['value'] ?? $this->migrationStart;
        }

        $currentMigration = intval($currentMigration);
        $neededMigrations = [];
        $dir              = opendir(MIGRATIONS_PATH);
        while ($migration = readdir($dir)) {
            $migrationFileNumber = intval(substr($migration, 0, 3));

            if ($migrationFileNumber > $currentMigration && str_contains($migration, '.php')) {
                $neededMigrations[$migrationFileNumber] = $migration;
            }
        }
        closedir($dir);

        if (!empty($neededMigrations)) {
            setFile(MIGRATION_FILE, ['started' => date('c')]);
            ksort($neededMigrations);
            $neededMigrationsNumbers = implode(', ', array_keys($neededMigrations));

            logger(MIGRATION_LOG, 'Current migration: ' . $currentMigration);
            logger(MIGRATION_LOG, 'Needed migrations: ' . $neededMigrationsNumbers);
            logger(SYSTEM_LOG, 'Applying migrations: ' . $neededMigrationsNumbers);
            logger(SYSTEM_LOG, 'Review the migration log for specific migration query details: ' . MIGRATION_LOG);

            foreach ($neededMigrations as $migrationNumber => $neededMigration) {
                $q     = []; //-- RESET THE QUERY ARRAY FOR EACH MIGRATION
                $error = false;
                logger(MIGRATION_LOG, 'migration ' . $migrationNumber . ' ->');
                require MIGRATIONS_PATH . $neededMigration;
                logger(MIGRATION_LOG, 'migration ' . $migrationNumber . ' <-');
                if ($error) {
                    break;
                }
            }
        } else {
            logger(SYSTEM_LOG, 'No migrations needed');
        }

        deleteFile(MIGRATION_FILE);
    }
}
