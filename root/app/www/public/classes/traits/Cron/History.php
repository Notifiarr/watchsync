<?php

/*
----------------------------------
------  Created: 091126   ------
------  Austin Best       ------
----------------------------------
*/

trait History
{
    public function watchApps()
    {
        $mediaAppId = intval($this->sidecar['media_app_id'] ?? 0);
        $apps       = [];
        foreach ($this->database->getMediaApps() as $mediaApp) {
            if ($mediaAppId) {
                if ($mediaApp['id'] == $mediaAppId) {
                    $apps[] = $mediaApp;
                }
                continue;
            }
            if (!$mediaApp['active']) {
                continue;
            }
            $apps[] = $mediaApp;
        }

        return $apps;
    }

    public function pullWatch()
    {
        global $mediaApps;

        foreach ($this->watchApps() as $mediaApp) {
            $this->stopIfCancelled();
            foreach ($this->selectedUsers($mediaApp['id']) as $user) {
                $this->stopIfCancelled();
                logger($this->logfile, 'pull ' . $mediaApp['name'] . ' ' . $user['username']);
                $status = $mediaApps->getWatchStatus($mediaApp, $user['remote_id'], $user['username']);
                $movies = 0;
                $episodes = 0;
                foreach ($status['movies'] as $remoteId => $watch) {
                    $this->stopIfCancelled();
                    $movie = $this->database->getMovieByRemoteId($mediaApp['platform'], $remoteId);
                    if (!$movie) {
                        continue;
                    }
                    $this->database->upsertUserMovieLink($movie['id'], $user['id'], $mediaApp['platform'], $watch['started'], $watch['inprogress'], $watch['finished']);
                    $movies++;
                }
                foreach ($status['episodes'] as $remoteId => $watch) {
                    $this->stopIfCancelled();
                    $episode = $this->database->getEpisodeByRemoteId($mediaApp['platform'], $remoteId);
                    if (!$episode) {
                        continue;
                    }
                    $this->database->upsertUserEpisodeLink($episode['id'], $user['id'], $mediaApp['platform'], $watch['started'], $watch['inprogress'], $watch['finished']);
                    $episodes++;
                }
                logger($this->logfile, 'pull ' . $user['username'] . ' movies=' . $movies . ' episodes=' . $episodes);
            }
        }
    }

    public function pushWatch()
    {
        global $mediaApps;

        foreach ($this->watchApps() as $mediaApp) {
            $this->stopIfCancelled();
            $flag   = $this->database->mediaLibraryFlag($mediaApp['platform']);
            $field  = $this->database->mediaLibraryRemoteField($mediaApp['platform']);
            foreach ($this->selectedUsers($mediaApp['id']) as $user) {
                $this->stopIfCancelled();
                logger($this->logfile, 'push ' . $mediaApp['name'] . ' ' . $user['username']);
                $movies   = 0;
                $episodes = 0;
                foreach ($this->database->getUserMovieLinks($user['id'], $mediaApp['platform']) as $link) {
                    $this->stopIfCancelled();
                    $movie = $this->database->getMovie($link['movie_id']);
                    if (!$movie || empty($movie[$flag]) || $movie[$field] === '') {
                        continue;
                    }
                    $mediaApps->setWatchStatus($mediaApp, $user, $movie[$field], $link['started'], $link['inprogress'], $link['finished']);
                    $movies++;
                }
                foreach ($this->database->getUserEpisodeLinks($user['id'], $mediaApp['platform']) as $link) {
                    $this->stopIfCancelled();
                    $episode = $this->database->getEpisode($link['episode_id']);
                    if (!$episode || empty($episode[$flag]) || $episode[$field] === '') {
                        continue;
                    }
                    $mediaApps->setWatchStatus($mediaApp, $user, $episode[$field], $link['started'], $link['inprogress'], $link['finished']);
                    $episodes++;
                }
                logger($this->logfile, 'push ' . $user['username'] . ' movies=' . $movies . ' episodes=' . $episodes);
            }
        }
    }
}
