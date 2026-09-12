<?php

/*
----------------------------------
------  Created: 091326   ------
------  Austin Best       ------
----------------------------------
*/

trait MediaAppLibrary
{
    public function getMediaAppLibraries($mediaAppId)
    {
        $id = intval($mediaAppId);
        if (isset($this->appLibrariesCache[$id])) {
            return $this->appLibrariesCache[$id];
        }

        $libraries = [];
        $sql       = "SELECT media_app_id, library_key, guid, title, type, paths, updated_at, scanned_at
                FROM " . MEDIA_APP_LIBRARY_TABLE . "
                WHERE media_app_id = " . $id;
        $res       = $this->query($sql);
        while ($row = $this->fetchAssoc($res)) {
            $paths       = json_decode($row['paths'] ?? '', true);
            $libraries[] = [
                'media_app_id' => intval($row['media_app_id']),
                'key'          => strval($row['library_key'] ?? ''),
                'guid'         => strval($row['guid'] ?? ''),
                'title'        => strval($row['title'] ?? ''),
                'type'         => strval($row['type'] ?? ''),
                'paths'        => is_array($paths) ? $paths : [],
                'updated_at'   => intval($row['updated_at'] ?? 0),
                'scanned_at'   => intval($row['scanned_at'] ?? 0),
            ];
        }

        $this->appLibrariesCache[$id] = $libraries;

        return $libraries;
    }

    public function replaceMediaAppLibraries($mediaAppId, $libraries)
    {
        $id  = intval($mediaAppId);
        $sql = "DELETE FROM " . MEDIA_APP_LIBRARY_TABLE . "
                WHERE media_app_id = " . $id;
        $this->query($sql);

        $stored = [];
        if (is_array($libraries)) {
            foreach ($libraries as $library) {
                $key = trim(strval($library['key'] ?? ''));
                if ($key == '') {
                    continue;
                }

                $paths = [];
                foreach ($library['paths'] ?? [] as $path) {
                    if (trim(strval($path)) != '') {
                        $paths[] = strval($path);
                    }
                }

                $row = [
                    'media_app_id' => $id,
                    'key'          => $key,
                    'guid'         => strval($library['guid'] ?? ''),
                    'title'        => strval($library['title'] ?? ''),
                    'type'         => strval($library['type'] ?? ''),
                    'paths'        => $paths,
                    'updated_at'   => intval($library['updated_at'] ?? 0),
                    'scanned_at'   => intval($library['scanned_at'] ?? 0),
                ];

                $sql = "INSERT INTO " . MEDIA_APP_LIBRARY_TABLE . "
                        (`media_app_id`, `library_key`, `guid`, `title`, `type`, `paths`, `updated_at`, `scanned_at`)
                        VALUES
                        (" . $id . ", '" . $this->prepare($row['key']) . "', '" . $this->prepare($row['guid']) . "', '" . $this->prepare($row['title']) . "', '" . $this->prepare($row['type']) . "', '" . $this->prepare(json_encode($row['paths'])) . "', " . $row['updated_at'] . ", " . $row['scanned_at'] . ")";
                $this->query($sql);
                $stored[] = $row;
            }
        }

        $this->appLibrariesCache[$id] = $stored;

        return $stored;
    }

    public function deleteMediaAppLibraries($mediaAppId)
    {
        $id  = intval($mediaAppId);
        $sql = "DELETE FROM " . MEDIA_APP_LIBRARY_TABLE . "
                WHERE media_app_id = " . $id;
        $res = $this->query($sql);
        unset($this->appLibrariesCache[$id]);

        return $res;
    }
}
