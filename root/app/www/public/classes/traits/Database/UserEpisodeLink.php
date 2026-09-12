<?php

/*
----------------------------------
------  Created: 091126   ------
------  Austin Best       ------
----------------------------------
*/

trait UserEpisodeLink
{
    public function getUserEpisodeLinks($mediaAppUserId, $platform)
    {
        $links = [];

        $sql = "SELECT id, episode_id, media_app_user_id, platform, started, inprogress, finished
                FROM " . USER_EPISODE_LINK_TABLE . "
                WHERE media_app_user_id = " . intval($mediaAppUserId) . "
                AND platform = " . intval($platform);
        $res = $this->query($sql);
        while ($row = $this->fetchAssoc($res)) {
            $links[] = $row;
        }

        return $links;
    }

    public function upsertUserEpisodeLink($episodeId, $mediaAppUserId, $platform, $started, $inprogress, $finished)
    {
        $sql = "SELECT id
                FROM " . USER_EPISODE_LINK_TABLE . "
                WHERE media_app_user_id = " . intval($mediaAppUserId) . "
                AND episode_id = " . intval($episodeId) . "
                AND platform = " . intval($platform);
        $res = $this->query($sql);
        $row = $this->fetchAssoc($res);

        if ($row) {
            $sql = "UPDATE " . USER_EPISODE_LINK_TABLE . "
                    SET started = " . intval($started) . ",
                        inprogress = " . intval($inprogress) . ",
                        finished = " . intval($finished) . "
                    WHERE id = " . intval($row['id']);
            $res = $this->query($sql);

            return intval($row['id']);
        }

        $sql = "INSERT INTO " . USER_EPISODE_LINK_TABLE . "
                (`episode_id`, `media_app_user_id`, `platform`, `started`, `inprogress`, `finished`)
                VALUES
                (" . intval($episodeId) . ", " . intval($mediaAppUserId) . ", " . intval($platform) . ", " . intval($started) . ", " . intval($inprogress) . ", " . intval($finished) . ")";
        $res = $this->query($sql);

        return $this->insertId();
    }

    public function deleteUserEpisodeLinksByUserIds($userIds)
    {
        if (!$userIds) {
            return;
        }

        $ids = [];
        foreach ($userIds as $userId) {
            $ids[] = intval($userId);
        }

        $sql = "DELETE FROM " . USER_EPISODE_LINK_TABLE . "
                WHERE media_app_user_id IN (" . implode(',', $ids) . ")";
        $res = $this->query($sql);

        return $res;
    }
}
