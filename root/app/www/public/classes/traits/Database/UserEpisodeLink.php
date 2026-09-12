<?php

/*
----------------------------------
------  Created: 091126   ------
------  Austin Best       ------
----------------------------------
*/

trait UserEpisodeLink
{
    public function countUserEpisodeLinks($mediaAppUserId, $platform)
    {
        $sql = "SELECT COUNT(*) AS total
                FROM " . USER_EPISODE_LINK_TABLE . "
                WHERE media_app_user_id = " . intval($mediaAppUserId) . "
                AND platform = " . intval($platform);
        $res = $this->query($sql);
        $row = $this->fetchAssoc($res);

        return intval($row['total'] ?? 0);
    }

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

    public function getUserEpisodeLink($episodeId, $mediaAppUserId, $platform)
    {
        $sql = "SELECT id, episode_id, media_app_user_id, platform, started, inprogress, finished
                FROM " . USER_EPISODE_LINK_TABLE . "
                WHERE media_app_user_id = " . intval($mediaAppUserId) . "
                AND episode_id = " . intval($episodeId) . "
                AND platform = " . intval($platform);
        $res = $this->query($sql);
        $row = $this->fetchAssoc($res);

        return $row ?: [];
    }

    public function upsertUserEpisodeLink($episodeId, $mediaAppUserId, $platform, $started, $inprogress, $finished)
    {
        $row = $this->getUserEpisodeLink($episodeId, $mediaAppUserId, $platform);

        return $this->saveUserEpisodeLink($row, $episodeId, $mediaAppUserId, $platform, $started, $inprogress, $finished);
    }

    public function saveUserEpisodeLink($existing, $episodeId, $mediaAppUserId, $platform, $started, $inprogress, $finished)
    {
        $started    = intval($started) ? 1 : 0;
        $inprogress = intval($inprogress);
        $finished   = intval($finished);
        $row        = is_array($existing) ? $existing : [];

        if ($row) {
            $changed = !watchStatesEqual($row, [
                'started'    => $started,
                'inprogress' => $inprogress,
                'finished'   => $finished,
            ]);
            if ($changed) {
                $sql = "UPDATE " . USER_EPISODE_LINK_TABLE . "
                        SET started = " . $started . ",
                            inprogress = " . $inprogress . ",
                            finished = " . $finished . "
                        WHERE id = " . intval($row['id']);
                $this->query($sql);
            }

            return [
                'id'      => intval($row['id']),
                'changed' => $changed,
            ];
        }

        $sql = "INSERT INTO " . USER_EPISODE_LINK_TABLE . "
                (`episode_id`, `media_app_user_id`, `platform`, `started`, `inprogress`, `finished`)
                VALUES
                (" . intval($episodeId) . ", " . intval($mediaAppUserId) . ", " . intval($platform) . ", " . $started . ", " . $inprogress . ", " . $finished . ")";
        $this->query($sql);

        return [
            'id'      => $this->insertId(),
            'changed' => true,
        ];
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
