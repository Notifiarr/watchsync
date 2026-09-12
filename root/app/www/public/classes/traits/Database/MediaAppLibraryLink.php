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
        $links = [];

        $sql = "SELECT id, media_app_id, library_key, linked_media_app_id, linked_library_key
                FROM " . MEDIA_APP_LIBRARY_LINK_TABLE;
        $where = [];
        if (intval($mediaAppId)) {
            $where[] = "media_app_id = " . intval($mediaAppId);
        }
        if ($libraryKey !== '') {
            $where[] = "library_key = '" . $this->prepare($libraryKey) . "'";
        }
        if ($where) {
            $sql .= " WHERE " . implode(' AND ', $where);
        }
        $res = $this->query($sql);
        while ($row = $this->fetchAssoc($res)) {
            $links[] = $row;
        }

        return $links;
    }

    public function getMediaAppLibraryLinkForApp($mediaAppId, $libraryKey, $linkedMediaAppId)
    {
        $sql = "SELECT id, media_app_id, library_key, linked_media_app_id, linked_library_key
                FROM " . MEDIA_APP_LIBRARY_LINK_TABLE . "
                WHERE media_app_id = " . intval($mediaAppId) . "
                AND library_key = '" . $this->prepare($libraryKey) . "'
                AND linked_media_app_id = " . intval($linkedMediaAppId);
        $res = $this->query($sql);
        $row = $this->fetchAssoc($res);

        return $row ?: [];
    }

    public function getMediaAppLibraryLink($linkedMediaAppId, $linkedLibraryKey)
    {
        $sql = "SELECT id, media_app_id, library_key, linked_media_app_id, linked_library_key
                FROM " . MEDIA_APP_LIBRARY_LINK_TABLE . "
                WHERE linked_media_app_id = " . intval($linkedMediaAppId) . "
                AND linked_library_key = '" . $this->prepare($linkedLibraryKey) . "'";
        $res = $this->query($sql);
        $row = $this->fetchAssoc($res);

        return $row ?: [];
    }

    public function setMediaAppLibraryLink($mediaAppId, $libraryKey, $linkedMediaAppId, $linkedLibraryKey)
    {
        $existing = $this->getMediaAppLibraryLink($linkedMediaAppId, $linkedLibraryKey);
        if ($existing) {
            $sql = "UPDATE " . MEDIA_APP_LIBRARY_LINK_TABLE . "
                    SET media_app_id = " . intval($mediaAppId) . ",
                        library_key = '" . $this->prepare($libraryKey) . "'
                    WHERE id = " . intval($existing['id']);
            $res = $this->query($sql);

            return intval($existing['id']);
        }

        $sql = "INSERT INTO " . MEDIA_APP_LIBRARY_LINK_TABLE . "
                (`media_app_id`, `library_key`, `linked_media_app_id`, `linked_library_key`)
                VALUES
                (" . intval($mediaAppId) . ", '" . $this->prepare($libraryKey) . "', " . intval($linkedMediaAppId) . ", '" . $this->prepare($linkedLibraryKey) . "')";
        $res = $this->query($sql);

        return $this->insertId();
    }

    public function deleteMediaAppLibraryLink($linkedMediaAppId, $linkedLibraryKey)
    {
        $sql = "DELETE FROM " . MEDIA_APP_LIBRARY_LINK_TABLE . "
                WHERE linked_media_app_id = " . intval($linkedMediaAppId) . "
                AND linked_library_key = '" . $this->prepare($linkedLibraryKey) . "'";
        $res = $this->query($sql);

        return $res;
    }

    public function deleteMediaAppLibraryLinks($mediaAppId, $libraryKey = '')
    {
        $sql = "DELETE FROM " . MEDIA_APP_LIBRARY_LINK_TABLE . "
                WHERE (media_app_id = " . intval($mediaAppId);
        if ($libraryKey !== '') {
            $sql .= " AND library_key = '" . $this->prepare($libraryKey) . "'";
        }
        $sql .= ") OR (linked_media_app_id = " . intval($mediaAppId);
        if ($libraryKey !== '') {
            $sql .= " AND linked_library_key = '" . $this->prepare($libraryKey) . "'";
        }
        $sql .= ")";
        $res = $this->query($sql);

        return $res;
    }
}
