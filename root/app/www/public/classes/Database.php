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
    use MediaAppLibrary;
    use MediaLibrary;
    use LibraryBrowser;
    use Movie;
    use Series;
    use Episode;
    use UserMovieLink;
    use UserEpisodeLink;
    use SyncHistory;
    use Settings;

    public    $db;
    public    $notificationPlatformTable;
    public    $notificationTriggersTable;
    public    $notificationLinkTable;
    public    $migrationStart            = '001';
    protected $mediaAppsCache            = null;
    protected $settingsCache             = null;
    protected $userLinksCache            = null;
    protected $libraryLinksCache         = null;
    protected $appLibrariesCache         = [];
    protected $usersCache                = [];

    public function __construct()
    {
        $this->connect();
        $this->migrations();
        $this->ensureLoginSettings();
    }

    public function connect()
    {
        $mysqlSetup = false;

        logger(SYSTEM_LOG, 'MYSQL: Connecting to \'' . DB_NAME . ' -> ' . DB_USER . '@' . DB_HOST . '\'...');

        try {
            $this->db = $this->mysqliConnect(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
        } catch (Exception $e) {
            $message = $e->getMessage();
            logger(SYSTEM_LOG, 'MYSQL: Failed to connect to \'' . DB_NAME . ' -> ' . DB_USER . '@' . DB_HOST . '\': ' . $message);

            if (str_contains($message, 'Unknown database')) {
                logger(SYSTEM_LOG, 'MYSQL: Connecting to \'' . DB_USER . '@' . DB_HOST . '\'...');
                try {
                    $this->db   = $this->mysqliConnect(DB_HOST, DB_USER, DB_PASSWORD, '');
                    $mysqlSetup = true;
                } catch (Exception $retry) {
                    logger(SYSTEM_LOG, 'MYSQL: Failed to connect to \'' . DB_USER . '@' . DB_HOST . '\': ' . $retry->getMessage());
                }
            } else if (str_contains_any($message, ['No such file or directory', 'Connection refused'])) {
                logger(SYSTEM_LOG, 'MYSQL: Connecting to \'' . DB_USER . '@127.0.0.1\'...');
                try {
                    $this->db   = $this->mysqliConnect('127.0.0.1', DB_USER, DB_PASSWORD, '');
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

    public function mysqliConnect($host, $user, $password, $database)
    {
        $db = mysqli_init();
        if (!$db) {
            throw new Exception('mysqli_init failed');
        }

        $port       = ini_get('mysqli.default_port') ? intval(ini_get('mysqli.default_port')) : 3306;
        $socket     = ini_get('mysqli.default_socket') ?: null;
        $flags      = defined('MYSQLI_CLIENT_FOUND_ROWS') ? MYSQLI_CLIENT_FOUND_ROWS : 0;
        $connection = mysqli_real_connect($db, $host, $user, $password, $database != '' ? $database : null, $port, $socket, $flags);
        if (!$connection) {
            throw new Exception(mysqli_connect_error());
        }

        return $db;
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
        if (!$this->db) {
            return false;
        }

        try {
            return mysqli_query($this->db, $sql);
        } catch (Exception $e) {
            logger(SYSTEM_LOG, 'MYSQL: Query failed: ' . $e->getMessage());
            return false;
        }
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

        return max(0, intval(mysqli_affected_rows($this->db)));
    }

    public function error()
    {
        return $this->db ? mysqli_error($this->db) : '';
    }

    public function mysqli_backup($type = 'automatic')
    {
        global $shell;

        $type = ($type == 'manual') ? 'manual' : 'automatic';
        $dir  = BACKUP_PATH . date('Ymd') . '/' . date('His') . '_' . $type;
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        logger(CRON_BACKUP_LOG, 'path=' . $dir);

        $res = $this->query('SHOW TABLES');
        if (!$res) {
            logger(CRON_BACKUP_LOG, 'tables=failed error=' . $this->error());
            return false;
        }

        $success = true;
        $count   = 0;
        $failed  = 0;
        $total   = 0;
        while ($row = $this->fetchAssoc($res)) {
            $table = reset($row);
            if ($table == '') {
                continue;
            }
            $count++;
            $file = $dir . '/' . $table . '.sql';
            $exec = 'mariadb-dump --single-transaction=true --skip-lock-tables --quick --user=' . DB_USER . ' --password=' . DB_PASSWORD . ' --host=' . DB_HOST . ' ' . DB_NAME . ' ' . $table . ' 2>/dev/null > ' . $file;
            $shell->exec($exec);
            clearstatcache(true, $file);
            $size   = (is_file($file)) ? intval(filesize($file)) : 0;
            $status = ($size > 0) ? 'success' : 'failed';
            if ($status == 'failed') {
                $success = false;
                $failed++;
            } else {
                $total += $size;
            }
            logger(CRON_BACKUP_LOG, 'table=' . $table . ' status=' . $status . ' size=' . byteConversion($size));
        }

        if (!$count) {
            logger(CRON_BACKUP_LOG, 'tables=0');
            return false;
        }

        logger(CRON_BACKUP_LOG, 'tables=' . $count . ' success=' . ($count - $failed) . ' failed=' . $failed . ' size=' . byteConversion($total));

        return ($success && $count) ? $dir : false;
    }

    public function mysqli_restore($dir)
    {
        global $shell;

        $dir = rtrim(str_replace('\\', '/', $dir), '/');
        if ($dir == '' || !is_dir($dir)) {
            return false;
        }

        $files  = [];
        $handle = opendir($dir);
        while ($file = readdir($handle)) {
            if ($file[0] == '.' || !str_ends_with($file, '.sql') || !is_file($dir . '/' . $file)) {
                continue;
            }
            $files[] = $dir . '/' . $file;
        }
        closedir($handle);

        if (!$files) {
            return false;
        }
        sort($files);

        $this->query('SET FOREIGN_KEY_CHECKS=0');
        $res = $this->query('SHOW TABLES');
        if ($res) {
            while ($row = $this->fetchAssoc($res)) {
                $table = reset($row);
                if ($table == '') {
                    continue;
                }
                $this->query('DROP TABLE IF EXISTS `' . str_replace('`', '``', $table) . '`');
            }
        }

        foreach ($files as $file) {
            $exec = 'mariadb --user=' . DB_USER . ' --password=' . DB_PASSWORD . ' --host=' . DB_HOST . ' ' . DB_NAME . ' < ' . escapeshellarg($file) . ' 2>/dev/null';
            $shell->exec($exec);
        }

        $this->query('SET FOREIGN_KEY_CHECKS=1');

        $res = $this->query('SHOW TABLES');
        if (!$res) {
            return false;
        }
        $count = 0;
        while ($row = $this->fetchAssoc($res)) {
            if (reset($row) != '') {
                $count++;
            }
        }

        $this->mediaAppsCache    = null;
        $this->settingsCache     = null;
        $this->userLinksCache    = null;
        $this->libraryLinksCache = null;
        $this->appLibrariesCache = [];
        $this->usersCache        = [];

        return $count > 0;
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

    public function appliedMigration()
    {
        $number = str_pad(strval($this->migrationRollbackCheck($this->getSetting('migration') ?: $this->migrationStart)), 3, '0', STR_PAD_LEFT);
        $file   = '';
        $name   = '';
        if (is_dir(MIGRATIONS_PATH)) {
            $dir = opendir(MIGRATIONS_PATH);
            while ($migration = readdir($dir)) {
                if (substr($migration, 0, 3) == $number && str_ends_with($migration, '.php')) {
                    $file = $migration;
                    break;
                }
            }
            closedir($dir);
        }
        if ($file != '') {
            $name = str_replace('_', ' ', substr($file, 4, -4));
        }

        return [
            'number' => $number,
            'name'   => $name,
            'file'   => $file,
        ];
    }

    public function migrationRollbackCheck($currentMigration)
    {
        $current = intval($currentMigration);
        $highest = intval($this->getNewestMigration());
        if ($current <= $highest) {
            return $current;
        }

        $value = str_pad(strval($highest), 3, '0', STR_PAD_LEFT);
        logger(MIGRATION_LOG, 'migration rollback: stored ' . $current . ' > available ' . $highest . ', setting to ' . $value);
        logger(SYSTEM_LOG, 'Migration version rolled back from ' . $current . ' to ' . $value);
        $this->setSetting('migration', $value);

        return $highest;
    }

    public function migrations()
    {
        if (!IS_STARTUP) {
            return;
        }

        $database = $this;
        $q        = [];

        if (MYSQL_SETUP) {
            setFile(MIGRATION_FILE, ['started' => date('c')]);
            logger(SYSTEM_LOG, 'Creating database and applying migration ' . $this->migrationStart . '_initial_migration');
            logger(SYSTEM_LOG, 'Review the migration log for specific migration details: ' . MIGRATION_LOG);
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

        $currentMigration = $this->migrationRollbackCheck($currentMigration);
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
                $q     = [];
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
