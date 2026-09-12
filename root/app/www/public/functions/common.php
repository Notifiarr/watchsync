<?php

/*
----------------------------------
------  Created: 091026   ------
------  Austin Best       ------
----------------------------------
*/

function loadClassTraits($traits)
{
    if (is_dir($traits)) {
        $traitsDir = opendir($traits);
        while ($traitFile = readdir($traitsDir)) {
            if (str_contains($traitFile, '.php')) {
                require $traits . $traitFile;
            }
        }
        closedir($traitsDir);
    }
}

function dbPrepare($val)
{
    $val = addslashes(stripslashes($val));
    return $val;
}

function htmlEscape($value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

function translate(string $key, array $args = []): string
{
    global $localization;

    return $localization->translate($key, $args);
}

function setFile($path, $data)
{
    $dir = dirname($path);
    if ($dir && !is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    file_put_contents($path, is_array($data) || is_object($data) ? json_encode($data) : $data);
}

function deleteFile($path)
{
    if (is_file($path)) {
        unlink($path);
    }
}
