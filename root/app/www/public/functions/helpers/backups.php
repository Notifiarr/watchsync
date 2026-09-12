<?php

/*
----------------------------------
------  Created: 091226   ------
------  Austin Best       ------
----------------------------------
*/

function getBackupList()
{
    $backups = [];
    if (!is_dir(BACKUP_PATH)) {
        return $backups;
    }

    $dates = opendir(BACKUP_PATH);
    while ($date = readdir($dates)) {
        if ($date[0] == '.' || !is_dir(BACKUP_PATH . $date)) {
            continue;
        }

        $runs = opendir(BACKUP_PATH . $date);
        while ($run = readdir($runs)) {
            if ($run[0] == '.' || !is_dir(BACKUP_PATH . $date . '/' . $run)) {
                continue;
            }
            if (!preg_match('/^(\d{6})_(manual|automatic)$/', $run, $match)) {
                continue;
            }

            $size  = 0;
            $inner = opendir(BACKUP_PATH . $date . '/' . $run);
            while ($file = readdir($inner)) {
                if ($file[0] == '.' || !is_file(BACKUP_PATH . $date . '/' . $run . '/' . $file)) {
                    continue;
                }
                $size += filesize(BACKUP_PATH . $date . '/' . $run . '/' . $file);
            }
            closedir($inner);

            $backups[] = [
                'date'   => $date,
                'time'   => $match[1],
                'method' => $match[2],
                'run'    => $run,
                'size'   => $size,
            ];
        }
        closedir($runs);
    }
    closedir($dates);

    usort($backups, function ($a, $b) {
        return strcmp($b['date'] . $b['time'], $a['date'] . $a['time']);
    });

    return $backups;
}

function zipBackupFolder($dir, $zipFile)
{
    $files = [];
    if (!is_dir($dir)) {
        return false;
    }

    $handle = opendir($dir);
    while ($file = readdir($handle)) {
        if ($file[0] == '.' || !str_ends_with($file, '.sql') || !is_file($dir . '/' . $file)) {
            continue;
        }
        $files[$file] = $dir . '/' . $file;
    }
    closedir($handle);

    if (!$files) {
        return false;
    }

    if (class_exists('ZipArchive')) {
        $zip = new ZipArchive();
        if ($zip->open($zipFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) != true) {
            return false;
        }
        foreach ($files as $name => $path) {
            $zip->addFile($path, $name);
        }
        $zip->close();

        return is_file($zipFile) && filesize($zipFile);
    }

    $out = fopen($zipFile, 'wb');
    if (!$out) {
        return false;
    }

    $central = '';
    $offset  = 0;
    $count   = 0;
    foreach ($files as $name => $path) {
        $data    = file_get_contents($path);
        $len     = strlen($data);
        $crc     = crc32($data);
        $nameLen = strlen($name);
        fwrite($out, pack('VvvvvvVVVvv', 0x04034b50, 20, 0, 0, 0, 0, $crc, $len, $len, $nameLen, 0));
        fwrite($out, $name);
        fwrite($out, $data);
        $central .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0, 0, 0, 0, $crc, $len, $len, $nameLen, 0, 0, 0, 0, 0, $offset);
        $central .= $name;
        $offset  += 30 + $nameLen + $len;
        $count++;
    }
    fwrite($out, $central);
    fwrite($out, pack('VvvvvVVv', 0x06054b50, 0, 0, $count, $count, strlen($central), $offset, 0));
    fclose($out);

    return is_file($zipFile) && filesize($zipFile);
}
