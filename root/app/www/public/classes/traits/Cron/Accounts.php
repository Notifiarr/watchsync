<?php

/*
----------------------------------
------  Created: 091126   ------
------  Austin Best       ------
----------------------------------
*/

trait Accounts
{
    public function selectedUsers($mediaAppId)
    {
        $selected = $this->sidecar['user_ids'] ?? [];
        $mediaApp = $this->database->getMediaApp($mediaAppId);
        if (!$mediaApp) {
            return [];
        }
        if (!$selected) {
            return $this->database->getMediaAppUsers($mediaAppId);
        }

        $users = [];
        if ($mediaApp['role'] == MediaAppRoles::MASTER) {
            foreach ($this->database->getMediaAppUsers($mediaAppId) as $user) {
                if (in_array(intval($user['id']), $selected)) {
                    $users[] = $user;
                }
            }

            return $users;
        }

        foreach ($selected as $userId) {
            $link = $this->database->getMediaAppUserLinkForApp(intval($userId), $mediaAppId);
            if (!$link) {
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

        $listeners = [];
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
            $mediaApps->linkUsers();
            $existing = [];
            foreach ($this->database->getMediaAppUsers($listener['id']) as $user) {
                $existing[strtolower(trim($user['username']))] = $user;
            }

            $created      = 0;
            $linked       = 0;
            $skipped      = 0;
            $accessUsers  = [];
            $createdNames = [];
            $linkedNames  = [];

            foreach ($keep as $username => $masterUser) {
                $this->stopIfCancelled();
                if ($this->database->getMediaAppUserLinkForApp($masterUser['id'], $listener['id'])) {
                    $accessUsers[] = $masterUser;
                    $linked++;
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
                    $linked++;
                }
            }

            $mediaApps->refreshUsers($listener['id']);
            $mediaApps->linkUsers();
            $access = $mediaApps->syncListenerUserAccess($master, $listener, $accessUsers);
            if ($created || $linked) {
                $this->database->setMediaAppNeedsSync($listener['id']);
            }
            $this->sidecar['stats']['created'] = intval($this->sidecar['stats']['created'] ?? 0) + $created;
            $this->sidecar['stats']['linked']  = intval($this->sidecar['stats']['linked'] ?? 0) + $linked;
            foreach ($createdNames as $name) {
                $this->addParityResult($listener, 'users', 'created', $name);
            }
            foreach ($linkedNames as $name) {
                $this->addParityResult($listener, 'users', 'linked', $name);
            }
            logger($this->logfile, 'user sync ' . $listener['name'] . ' created=' . $created . ' linked=' . $linked . ' skipped=' . $skipped . ' access=' . intval($access['updated'] ?? 0));
        }
    }
}
