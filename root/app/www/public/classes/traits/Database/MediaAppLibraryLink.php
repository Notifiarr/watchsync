<?php

/*
----------------------------------
------  Created: 091226   ------
------  Austin Best       ------
----------------------------------
*/

trait MediaAppLibraryLink
{
    public function getMediaAppLibraryLinks($mediaAppId = 0, $libraryKey = '')
    {
        if (!is_array($this->libraryLinksCache)) {
            $this->libraryLinksCache = [];
            $sql                     = "SELECT id, media_app_id, library_key, linked_media_app_id, linked_library_key
                    FROM " . MEDIA_APP_LIBRARY_LINK_TABLE;
            $res                     = $this->query($sql);
            while ($row = $this->fetchAssoc($res)) {
                $this->libraryLinksCache[] = $row;
            }
        }

        $links = [];
        foreach ($this->libraryLinksCache as $row) {
            if (intval($mediaAppId) && intval($row['media_app_id']) != intval($mediaAppId)) {
                continue;
            }
            if ($libraryKey != '' && $row['library_key'] != $libraryKey) {
                continue;
            }
            $links[] = $row;
        }

        return $links;
    }

    public function getMediaAppLibraryLinkForApp($mediaAppId, $libraryKey, $linkedMediaAppId)
    {
        foreach ($this->getMediaAppLibraryLinks() as $row) {
            if (intval($row['media_app_id']) == intval($mediaAppId) && $row['library_key'] == $libraryKey && intval($row['linked_media_app_id']) == intval($linkedMediaAppId)) {
                return $row;
            }
        }

        return [];
    }

    public function getMediaAppLibraryLink($linkedMediaAppId, $linkedLibraryKey)
    {
        foreach ($this->getMediaAppLibraryLinks() as $row) {
            if (intval($row['linked_media_app_id']) == intval($linkedMediaAppId) && $row['linked_library_key'] == $linkedLibraryKey) {
                return $row;
            }
        }

        return [];
    }

    public function setMediaAppLibraryLink($mediaAppId, $libraryKey, $linkedMediaAppId, $linkedLibraryKey)
    {
        $existing = $this->getMediaAppLibraryLink($linkedMediaAppId, $linkedLibraryKey);
        if ($existing) {
            $sql                     = "UPDATE " . MEDIA_APP_LIBRARY_LINK_TABLE . "
                    SET media_app_id = " . intval($mediaAppId) . ",
                        library_key = '" . $this->prepare($libraryKey) . "'
                    WHERE id = " . intval($existing['id']);
            $res                     = $this->query($sql);
            $this->libraryLinksCache = null;

            return intval($existing['id']);
        }

        $sql                     = "INSERT INTO " . MEDIA_APP_LIBRARY_LINK_TABLE . "
                (`media_app_id`, `library_key`, `linked_media_app_id`, `linked_library_key`)
                VALUES
                (" . intval($mediaAppId) . ", '" . $this->prepare($libraryKey) . "', " . intval($linkedMediaAppId) . ", '" . $this->prepare($linkedLibraryKey) . "')";
        $res                     = $this->query($sql);
        $this->libraryLinksCache = null;

        return $this->insertId();
    }

    public function deleteMediaAppLibraryLink($linkedMediaAppId, $linkedLibraryKey)
    {
        $sql                     = "DELETE FROM " . MEDIA_APP_LIBRARY_LINK_TABLE . "
                WHERE linked_media_app_id = " . intval($linkedMediaAppId) . "
                AND linked_library_key = '" . $this->prepare($linkedLibraryKey) . "'";
        $res                     = $this->query($sql);
        $this->libraryLinksCache = null;

        return $res;
    }

    public function deleteMediaAppLibraryLinks($mediaAppId, $libraryKey = '')
    {
        $sql = "DELETE FROM " . MEDIA_APP_LIBRARY_LINK_TABLE . "
                WHERE (media_app_id = " . intval($mediaAppId);
        if ($libraryKey != '') {
            $sql .= " AND library_key = '" . $this->prepare($libraryKey) . "'";
        }
        $sql .= ") OR (linked_media_app_id = " . intval($mediaAppId);
        if ($libraryKey != '') {
            $sql .= " AND linked_library_key = '" . $this->prepare($libraryKey) . "'";
        }
        $sql                     .= ")";
        $res                      = $this->query($sql);
        $this->libraryLinksCache  = null;

        return $res;
    }
}
