<?php

/*
----------------------------------
------  Created: 091126   ------
------  Austin Best       ------
----------------------------------
*/

trait Accounts
{
    public function logDeletedSyncUser($user)
    {
        $key = strtolower(trim(strval($user['username'] ?? '')));
        if ($key == '') {
            $key = strval(intval($user['id'] ?? 0));
        }
        if ($key == '' || $key == '0' || !empty($this->currentJob['stats']['_deletedSkip'][$key])) {
            return;
        }

        $this->currentJob['stats']['_deletedSkip'][$key] = true;
        logger($this->logfile, translate('syncSkipDeletedUser', [strval($user['username'] ?? $key)]));
    }

    public function selectedUsers($mediaAppId)
    {
        $selected = $this->currentJob['user_ids'] ?? [];
        $mediaApp = $this->database->getMediaApp($mediaAppId);
        if (!$mediaApp) {
            return [];
        }
        if (!$selected) {
            $users = [];
            foreach ($this->database->getMediaAppUsers($mediaAppId) as $user) {
                $users[] = $user;
            }

            return $users;
        }

        $users = [];
        if ($mediaApp['role'] == MediaAppRoles::MASTER) {
            foreach ($this->database->getMediaAppUsers($mediaAppId) as $user) {
                if (!in_array(intval($user['id']), $selected)) {
                    continue;
                }
                $users[] = $user;
            }

            return $users;
        }

        foreach ($selected as $userId) {
            $direct = $this->database->getMediaAppUser(intval($userId));
            if ($direct && intval($direct['media_app_id'] ?? 0) == intval($mediaAppId)) {
                $users[] = $direct;
                continue;
            }
            $masterUser = $direct;
            $link       = $this->database->getMediaAppUserLinkForApp(intval($userId), $mediaAppId);
            if (!$link) {
                logger($this->logfile, ($mediaApp['name'] ?? 'media app') . ' skipped, ' . ($masterUser['username'] ?? $userId) . ' is not linked');
                continue;
            }
            $user = $this->database->getMediaAppUser(intval($link['linked_media_app_user_id']));
            if ($user) {
                $users[] = $user;
            }
        }

        return $users;
    }

    public function syncAccounts()
    {
        global $mediaApps;

        $master    = [];
        $listeners = [];
        foreach ($this->database->getMediaApps() as $mediaApp) {
            if (!$mediaApp['active']) {
                continue;
            }
            if ($mediaApp['role'] == MediaAppRoles::MASTER) {
                $master = $mediaApp;
            } else {
                $listeners[] = $mediaApp;
            }
        }
        if (!$master) {
            logger($this->logfile, 'no master for user sync');
            return;
        }

        $online = $this->onlineMediaApps(array_merge([$master], $listeners), 2);
        if (!$online) {
            return;
        }

        $listeners    = [];
        $masterOnline = false;
        foreach ($online as $mediaApp) {
            if (intval($mediaApp['id']) == intval($master['id'])) {
                $master       = $mediaApp;
                $masterOnline = true;
                continue;
            }
            $listeners[] = $mediaApp;
        }
        if (!$masterOnline) {
            logger($this->logfile, 'no online master for user sync');
            return;
        }

        $mediaApps->refreshUsers($master['id']);
        if ($this->isAutomaticJob() && $this->database->settingEnabled('syncParityAutoUsers')) {
            $state = $mediaApps->paritySyncState('user');
            if ($state) {
                $changed = false;
                foreach ($this->database->getMediaAppUsers($master['id']) as $user) {
                    $id = intval($user['id'] ?? 0);
                    if (!$id || $mediaApps->userIsDeleted($user)) {
                        continue;
                    }
                    $key = strval($id);
                    if (array_key_exists($key, $state) || $this->database->getMediaAppUserLinks($id)) {
                        continue;
                    }
                    $state[$key] = 1;
                    $changed     = true;
                }
                if ($changed) {
                    $mediaApps->setParitySync('user', $state);
                    $this->currentJob['user_ids'] = $mediaApps->selectedParityUserIds(false);
                }
            }
        }

        $selected = [];
        $jobIds   = [];
        foreach ($this->currentJob['user_ids'] ?? [] as $userId) {
            $userId = intval($userId);
            if ($userId) {
                $jobIds[] = $userId;
            }
        }
        if ($jobIds) {
            foreach ($jobIds as $userId) {
                $user = $this->database->getMediaAppUser($userId);
                if ($user) {
                    $selected[] = $user;
                }
            }
        } else {
            foreach ($mediaApps->selectedParityUserIds(false) as $userId) {
                $user = $this->database->getMediaAppUser($userId);
                if ($user) {
                    $selected[] = $user;
                }
            }
        }
        $keep = [];
        foreach ($selected as $user) {
            $keep[strtolower(trim($user['username']))] = $user;
        }

        logger($this->logfile, 'user sync master ' . $master['name'] . ' users=' . count($keep));

        $automatic = $this->isAutomaticJob();

        foreach ($listeners as $listener) {
            $this->stopIfCancelled();
            logger($this->logfile, 'user sync ' . $listener['name']);
            $mediaApps->refreshUsers($listener['id']);
            $linkedBefore = [];
            foreach ($keep as $username => $masterUser) {
                if ($this->database->getMediaAppUserLinkForApp($masterUser['id'], $listener['id'])) {
                    $linkedBefore[$username] = true;
                }
            }
            $mediaApps->linkUsers();
            $existing = [];
            foreach ($this->database->getMediaAppUsers($listener['id']) as $user) {
                $existing[strtolower(trim($user['username']))] = $user;
            }

            $created       = 0;
            $linked        = 0;
            $unchanged     = 0;
            $removed       = 0;
            $skipped       = 0;
            $passwords     = 0;
            $accessUsers   = [];
            $createdNames  = [];
            $linkedNames   = [];
            $passwordNames = [];

            foreach ($keep as $username => $masterUser) {
                $this->stopIfCancelled();
                if (!empty($linkedBefore[$username])) {
                    $accessUsers[] = $masterUser;
                    $unchanged++;
                    continue;
                }
                if ($this->database->getMediaAppUserLinkForApp($masterUser['id'], $listener['id'])) {
                    $accessUsers[] = $masterUser;
                    $linked++;
                    $linkedNames[] = $masterUser['username'];
                    continue;
                }
                if ($automatic && !$this->database->settingEnabled('syncParityAutoUsers')) {
                    $skipped++;
                    logger($this->logfile, $masterUser['username'] . ' no link, skip');
                    continue;
                }
                if (!empty($existing[$username])) {
                    $this->database->addMediaAppUserLink($masterUser['id'], $existing[$username]['id']);
                    $accessUsers[] = $masterUser;
                    $linked++;
                    $linkedNames[] = $masterUser['username'];
                    continue;
                }
                $createPassword = '';
                if ($mediaApps->isRemotePlexUser($master, $masterUser)) {
                    $createPassword = MediaAppEndpoints::PARITY_DEFAULT_USER_PASSWORD;
                }
                $result = $mediaApps->createUser($listener, $masterUser['username'], $createPassword);
                if (!empty($result['error'])) {
                    logger($this->logfile, 'create ' . $masterUser['username'] . ' ' . ($result['message'] ?? ''));
                    continue;
                }
                $created++;
                $createdNames[] = $masterUser['username'];
                $mediaApps->refreshUsers($listener['id']);
                $linkedUser = [];
                $remoteId   = $result['remote_id'] ?? '';
                foreach ($this->database->getMediaAppUsers($listener['id']) as $user) {
                    if ($remoteId != '' && ($user['remote_id'] ?? '') == $remoteId) {
                        $linkedUser = $user;
                        break;
                    }
                    if (strtolower(trim($user['username'])) == $username) {
                        $linkedUser = $user;
                    }
                }
                if ($linkedUser) {
                    $this->database->addMediaAppUserLink($masterUser['id'], $linkedUser['id']);
                    $existing[$username] = $linkedUser;
                    $accessUsers[]       = $masterUser;
                }
            }

            if ($created) {
                $mediaApps->refreshUsers($listener['id']);
            }
            $mediaApps->linkUsers();

            $platform = intval($listener['platform'] ?? 0);
            if ($platform == MediaPlatforms::EMBY || $platform == MediaPlatforms::JELLYFIN) {
                foreach ($keep as $username => $masterUser) {
                    $this->stopIfCancelled();
                    if (!$mediaApps->isRemotePlexUser($master, $masterUser)) {
                        continue;
                    }
                    $link = $this->database->getMediaAppUserLinkForApp($masterUser['id'], $listener['id']);
                    if (!$link) {
                        continue;
                    }
                    $listenerUser = $this->database->getMediaAppUser(intval($link['linked_media_app_user_id'] ?? 0));
                    $remoteId     = trim(strval($listenerUser['remote_id'] ?? ''));
                    if ($remoteId == '') {
                        continue;
                    }
                    $password = $mediaApps->setPasswordIfMissing($listener, $remoteId);
                    if (!empty($password['error'])) {
                        logger($this->logfile, 'password ' . ($masterUser['username'] ?? $username) . ' ' . ($password['message'] ?? ''));
                        continue;
                    }
                    if (!empty($password['changed'])) {
                        $passwords++;
                        $passwordNames[] = $masterUser['username'] ?? $username;
                    }
                }
            }

            $access = $mediaApps->syncListenerUserAccess($master, $listener, $accessUsers);
            if ($created || $linked || $passwords) {
                $this->database->setMediaAppNeedsSync($listener['id']);
            }
            $this->currentJob['stats']['created']   = intval($this->currentJob['stats']['created'] ?? 0) + $created;
            $this->currentJob['stats']['linked']    = intval($this->currentJob['stats']['linked'] ?? 0) + $linked;
            $this->currentJob['stats']['unchanged'] = intval($this->currentJob['stats']['unchanged'] ?? 0) + $unchanged;
            $this->currentJob['stats']['removed']   = intval($this->currentJob['stats']['removed'] ?? 0) + $removed;
            $this->currentJob['stats']['passwords'] = intval($this->currentJob['stats']['passwords'] ?? 0) + $passwords;
            $this->currentJob['stats']['access']    = intval($this->currentJob['stats']['access'] ?? 0) + intval($access['updated'] ?? 0);
            $this->recordUserParitySummary($listener['name'] ?? '', $created, $removed, $linked, $unchanged);
            foreach ($createdNames as $name) {
                $this->addParityResult($listener, 'users', 'created', $name);
            }
            foreach ($linkedNames as $name) {
                $this->addParityResult($listener, 'users', 'linked', $name);
            }
            foreach ($passwordNames as $name) {
                $this->addParityResult($listener, 'users', 'password', $name);
            }
            logger($this->logfile, 'user sync ' . $listener['name'] . ' added=' . $created . ' removed=' . $removed . ' linked=' . $linked . ' unchanged=' . $unchanged . ' skipped=' . $skipped . ' passwords=' . $passwords . ' access=' . intval($access['updated'] ?? 0));
        }
    }
}
