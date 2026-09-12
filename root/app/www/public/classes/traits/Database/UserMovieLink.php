<?php

/*
----------------------------------
------  Created: 091126   ------
------  Austin Best       ------
----------------------------------
*/

trait UserMovieLink
{
    public function getUserMovieLinks($mediaAppUserId, $platform)
    {
        $links = [];

        $sql = "SELECT id, movie_id, media_app_user_id, platform, started, inprogress, finished
                FROM " . USER_MOVIE_LINK_TABLE . "
                WHERE media_app_user_id = " . intval($mediaAppUserId) . "
                AND platform = " . intval($platform);
        $res = $this->query($sql);
        while ($row = $this->fetchAssoc($res)) {
            $links[] = $row;
        }

        return $links;
    }

    public function upsertUserMovieLink($movieId, $mediaAppUserId, $platform, $started, $inprogress, $finished)
    {
        $sql = "SELECT id
                FROM " . USER_MOVIE_LINK_TABLE . "
                WHERE media_app_user_id = " . intval($mediaAppUserId) . "
                AND movie_id = " . intval($movieId) . "
                AND platform = " . intval($platform);
        $res = $this->query($sql);
        $row = $this->fetchAssoc($res);

        if ($row) {
            $sql = "UPDATE " . USER_MOVIE_LINK_TABLE . "
                    SET started = " . intval($started) . ",
                        inprogress = " . intval($inprogress) . ",
                        finished = " . intval($finished) . "
                    WHERE id = " . intval($row['id']);
            $res = $this->query($sql);

            return intval($row['id']);
        }

        $sql = "INSERT INTO " . USER_MOVIE_LINK_TABLE . "
                (`movie_id`, `media_app_user_id`, `platform`, `started`, `inprogress`, `finished`)
                VALUES
                (" . intval($movieId) . ", " . intval($mediaAppUserId) . ", " . intval($platform) . ", " . intval($started) . ", " . intval($inprogress) . ", " . intval($finished) . ")";
        $res = $this->query($sql);

        return $this->insertId();
    }

    public function deleteUserMovieLinksByUserIds($userIds)
    {
        if (!$userIds) {
            return;
        }

        $ids = [];
        foreach ($userIds as $userId) {
            $ids[] = intval($userId);
        }

        $sql = "DELETE FROM " . USER_MOVIE_LINK_TABLE . "
                WHERE media_app_user_id IN (" . implode(',', $ids) . ")";
        $res = $this->query($sql);

        return $res;
    }
}
