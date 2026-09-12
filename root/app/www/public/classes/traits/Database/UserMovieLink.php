<?php

/*
----------------------------------
------  Created: 091126   ------
------  Austin Best       ------
----------------------------------
*/

trait UserMovieLink
{
    public function countUserMovieLinks($mediaAppUserId, $platform)
    {
        $sql = "SELECT COUNT(*) AS total
                FROM " . USER_MOVIE_LINK_TABLE . "
                WHERE media_app_user_id = " . intval($mediaAppUserId) . "
                AND platform = " . intval($platform);
        $res = $this->query($sql);
        $row = $this->fetchAssoc($res);

        return intval($row['total'] ?? 0);
    }

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

    public function getUserMovieLink($movieId, $mediaAppUserId, $platform)
    {
        $sql = "SELECT id, movie_id, media_app_user_id, platform, started, inprogress, finished
                FROM " . USER_MOVIE_LINK_TABLE . "
                WHERE media_app_user_id = " . intval($mediaAppUserId) . "
                AND movie_id = " . intval($movieId) . "
                AND platform = " . intval($platform);
        $res = $this->query($sql);
        $row = $this->fetchAssoc($res);

        return $row ?: [];
    }

    public function getUserWatchState($mediaAppUserId, $platform)
    {
        $userId   = intval($mediaAppUserId);
        $platform = intval($platform);
        $state    = [
            'movie'   => [],
            'episode' => [],
        ];
        $user     = $this->getMediaAppUser($userId);
        $sql      = "SELECT 'movie' AS kind, id, movie_id AS item_id, started, inprogress, finished
                FROM " . USER_MOVIE_LINK_TABLE . "
                WHERE media_app_user_id = " . $userId . "
                AND platform = " . $platform . "
                UNION ALL
                SELECT 'episode' AS kind, id, episode_id AS item_id, started, inprogress, finished
                FROM " . USER_EPISODE_LINK_TABLE . "
                WHERE media_app_user_id = " . $userId . "
                AND platform = " . $platform;
        $res      = $this->query($sql);
        while ($row = $this->fetchAssoc($res)) {
            $kind                                  = ($row['kind'] ?? '') == 'episode' ? 'episode' : 'movie';
            $state[$kind][intval($row['item_id'])] = $row;
        }

        return [
            'user'    => $user ?: [],
            'movie'   => $state['movie'],
            'episode' => $state['episode'],
        ];
    }

    public function upsertUserMovieLink($movieId, $mediaAppUserId, $platform, $started, $inprogress, $finished)
    {
        $row = $this->getUserMovieLink($movieId, $mediaAppUserId, $platform);

        return $this->saveUserMovieLink($row, $movieId, $mediaAppUserId, $platform, $started, $inprogress, $finished);
    }

    public function saveUserMovieLink($existing, $movieId, $mediaAppUserId, $platform, $started, $inprogress, $finished)
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
                $sql = "UPDATE " . USER_MOVIE_LINK_TABLE . "
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

        $sql = "INSERT INTO " . USER_MOVIE_LINK_TABLE . "
                (`movie_id`, `media_app_user_id`, `platform`, `started`, `inprogress`, `finished`)
                VALUES
                (" . intval($movieId) . ", " . intval($mediaAppUserId) . ", " . intval($platform) . ", " . $started . ", " . $inprogress . ", " . $finished . ")";
        $this->query($sql);

        return [
            'id'      => $this->insertId(),
            'changed' => true,
        ];
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
