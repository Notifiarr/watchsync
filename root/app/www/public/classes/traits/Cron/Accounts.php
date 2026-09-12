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
        if ($key == '' || $key == '0' || !empty($this->sidecar['stats']['_deletedSkip'][$key])) {
            return;
        }

        $this->sidecar['stats']['_deletedSkip'][$key] = true;
        logger($this->logfile, translate('syncSkipDeletedUser', [strval($user['username'] ?? $key)]));
    }

    public function selectedUsers($mediaAppId)
    {
        $selected = $this->sidecar['user_ids'] ?? [];
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
        if ($this->database->settingEnabled('syncParityAutoUsers')) {
            $state   = $mediaApps->paritySyncState('user');
            $changed = false;
            foreach ($this->database->getMediaAppUsers($master['id']) as $user) {
                $id = intval($user['id'] ?? 0);
                if (!$id || $mediaApps->userIsDeleted($user) || $mediaApps->parityItemSelected('user', $id)) {
                    continue;
                }
                if ($this->database->getMediaAppUserLinks($id)) {
                    continue;
                }
                $state[strval($id)] = 1;
                $changed            = true;
            }
            if ($changed) {
                $mediaApps->setParitySync('user', $state);
            }
        }
        $selected = [];
        foreach ($mediaApps->selectedParityUserIds(false) as $userId) {
            $user = $this->database->getMediaAppUser($userId);
            if ($user) {
                $selected[] = $user;
            }
        }
        $keep = [];
        foreach ($selected as $user) {
            $keep[strtolower(trim($user['username']))] = $user;
        }

        logger($this->logfile, 'user sync master ' . $master['name'] . ' users=' . count($keep));

        $automatic = intval($this->sidecar['trigger'] ?? 0) == MediaSyncTriggers::AUTOMATIC;

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

            $created      = 0;
            $linked       = 0;
            $unchanged    = 0;
            $removed      = 0;
            $skipped      = 0;
            $accessUsers  = [];
            $createdNames = [];
            $linkedNames  = [];

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
                $result = $mediaApps->createUser($listener, $masterUser['username']);
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
            $access = $mediaApps->syncListenerUserAccess($master, $listener, $accessUsers);
            if ($created || $linked) {
                $this->database->setMediaAppNeedsSync($listener['id']);
            }
            $this->sidecar['stats']['created']   = intval($this->sidecar['stats']['created'] ?? 0) + $created;
            $this->sidecar['stats']['linked']    = intval($this->sidecar['stats']['linked'] ?? 0) + $linked;
            $this->sidecar['stats']['unchanged'] = intval($this->sidecar['stats']['unchanged'] ?? 0) + $unchanged;
            $this->sidecar['stats']['removed']   = intval($this->sidecar['stats']['removed'] ?? 0) + $removed;
            $this->sidecar['stats']['access']    = intval($this->sidecar['stats']['access'] ?? 0) + intval($access['updated'] ?? 0);
            $this->recordUserParitySummary($listener['name'] ?? '', $created, $removed, $linked, $unchanged);
            foreach ($createdNames as $name) {
                $this->addParityResult($listener, 'users', 'created', $name);
            }
            foreach ($linkedNames as $name) {
                $this->addParityResult($listener, 'users', 'linked', $name);
            }
            logger($this->logfile, 'user sync ' . $listener['name'] . ' added=' . $created . ' removed=' . $removed . ' linked=' . $linked . ' unchanged=' . $unchanged . ' skipped=' . $skipped . ' access=' . intval($access['updated'] ?? 0));
        }
    }
}
