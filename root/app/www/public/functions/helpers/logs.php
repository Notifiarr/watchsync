<?php

/*
----------------------------------
------  Created: 091326   ------
------  Austin Best       ------
----------------------------------
*/

function isLogFile($name)
{
    $name = basename((string) $name);

    return $name != '' && $name[0] != '.' && str_ends_with($name, '.log');
}

function isContainerLogGroup($group)
{
    $group = strtolower(basename((string) $group));

    return str_equals_any($group, ['nginx', 'php']);
}

function isContainerLogFile($name)
{
    $name = strtolower(basename((string) $name));

    return $name == 'init-database.log' || str_starts_with($name, 'init-database-');
}

function isProtectedLog($relative)
{
    $relative = str_replace('\\', '/', (string) $relative);
    $relative = ltrim($relative, '/');
    $group    = explode('/', $relative)[0] ?? '';

    return isContainerLogGroup($group) || isContainerLogFile($relative);
}

function getLogGroups()
{
    $groups = [];
    if (!is_dir(LOGS_PATH)) {
        return $groups;
    }

    $dir = opendir(LOGS_PATH);
    while ($group = readdir($dir)) {
        if ($group[0] == '.' || !is_dir(LOGS_PATH . $group) || $group == 'webhooks') {
            continue;
        }

        $files    = [];
        $groupDir = opendir(LOGS_PATH . $group);
        while ($log = readdir($groupDir)) {
            $path = LOGS_PATH . $group . '/' . $log;
            if ($log[0] == '.' || is_dir($path) || !isLogFile($log)) {
                continue;
            }

            $container = isContainerLogGroup($group) || isContainerLogFile($log);
            $files[]   = [
                'name'      => $log,
                'path'      => $group . '/' . $log,
                'size'      => filesize($path),
                'rotated'   => str_contains($log, '-') && !isContainerLogFile($log),
                'container' => $container,
            ];
        }
        closedir($groupDir);

        if (!$files) {
            continue;
        }

        usort($files, function ($a, $b) {
            return strnatcasecmp($a['name'], $b['name']);
        });
        $groups[$group] = $files;
    }
    closedir($dir);
    ksort($groups, SORT_NATURAL | SORT_FLAG_CASE);

    return $groups;
}

function getLogSections()
{
    $sections = [
        'appLogs'       => [],
        'containerLogs' => [],
    ];

    foreach (getLogGroups() as $group => $files) {
        $app       = [];
        $container = [];
        foreach ($files as $file) {
            if (!empty($file['container'])) {
                $container[] = $file;
            } else {
                $app[] = $file;
            }
        }

        if ($app) {
            $sections['appLogs'][$group] = $app;
        }
        if ($container) {
            $sections['containerLogs'][$group] = $container;
        }
    }

    return $sections;
}

function resolveLogRoot()
{
    $root = realpath(LOGS_PATH);
    if (!$root) {
        return '';
    }

    return rtrim(str_replace('\\', '/', $root), '/');
}

function resolveLogFile($relative)
{
    $relative = str_replace('\\', '/', (string) $relative);
    $relative = ltrim($relative, '/');
    if ($relative == '' || str_contains($relative, '..') || !preg_match('/^[A-Za-z0-9._\/-]+$/', $relative)) {
        return '';
    }

    $root = resolveLogRoot();
    $full = realpath(LOGS_PATH . $relative);
    if (!$root || !$full || !is_file($full)) {
        return '';
    }

    $full = str_replace('\\', '/', $full);
    if ($full != $root && !str_starts_with($full, $root . '/')) {
        return '';
    }
    if (!isLogFile($full)) {
        return '';
    }

    return $full;
}

function resolveLogGroup($group)
{
    $group = basename((string) $group);
    if ($group == '' || $group[0] == '.') {
        return '';
    }

    $root = resolveLogRoot();
    $full = realpath(LOGS_PATH . $group);
    if (!$root || !$full || !is_dir($full)) {
        return '';
    }

    $full = str_replace('\\', '/', $full);
    if ($full != $root && !str_starts_with($full, $root . '/')) {
        return '';
    }

    return $full;
}

function readLogFile($path, $max = 2097152)
{
    $size = filesize($path);
    if ($size == false) {
        return ['data' => '', 'trimmed' => false, 'size' => 0];
    }
    if ($size <= $max) {
        return ['data' => (string) file_get_contents($path), 'trimmed' => false, 'size' => $size];
    }

    $fp = fopen($path, 'rb');
    if (!$fp) {
        return ['data' => '', 'trimmed' => false, 'size' => $size];
    }

    fseek($fp, -$max, SEEK_END);
    $data = fread($fp, $max);
    fclose($fp);

    return ['data' => (string) $data, 'trimmed' => true, 'size' => $size];
}

function formatLogViewerHtml($data)
{
    $lines = preg_split("/\r\n|\n|\r/", strval($data));
    $html  = '';
    foreach ($lines as $index => $line) {
        $html .= '<div class="log-viewer-line"><span class="log-viewer-num">' . ($index + 1) . '</span><span class="log-viewer-text">' . htmlEscape($line) . '</span></div>';
    }

    return $html;
}

function deleteLogGroupFiles($groupPath)
{
    $dir = opendir($groupPath);
    if (!$dir) {
        return false;
    }

    while ($log = readdir($dir)) {
        $path = $groupPath . '/' . $log;
        if ($log[0] == '.' || is_dir($path) || !isLogFile($log) || isContainerLogFile($log)) {
            continue;
        }
        unlink($path);
    }
    closedir($dir);

    return true;
}
