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
        $users    = [];
        $mediaApp = $this->database->getMediaApp($mediaAppId);
        $names    = [];
        if ($selected && $mediaApp && $mediaApp['role'] != MediaAppRoles::MASTER) {
            foreach ($selected as $userId) {
                $masterUser = $this->database->getMediaAppUser(intval($userId));
                if ($masterUser) {
                    $names[] = strtolower($masterUser['username']);
                }
            }
        }

        foreach ($this->database->getMediaAppUsers($mediaAppId) as $user) {
            if ($selected && !$names && !in_array(intval($user['id']), $selected)) {
                continue;
            }
            if ($names && !in_array(strtolower($user['username']), $names)) {
                continue;
            }
            $users[] = $user;
        }

        return $users;
    }

    public function syncAccounts()
    {
        global $mediaApps;

        $master = [];
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

        $mediaApps->refreshUsers($master['id']);
        $selected = $this->selectedUsers($master['id']);
        $keep     = [];
        foreach ($selected as $user) {
            $keep[strtolower(trim($user['username']))] = $user;
        }

        logger($this->logfile, 'user sync master ' . $master['name'] . ' users=' . count($keep));

        foreach ($listeners as $listener) {
            $this->stopIfCancelled();
            logger($this->logfile, 'user sync ' . $listener['name']);
            $mediaApps->refreshUsers($listener['id']);
            $mediaApps->linkUsers();
            $existing = [];
            foreach ($this->database->getMediaAppUsers($listener['id']) as $user) {
                $existing[strtolower(trim($user['username']))] = $user;
            }

            $created = 0;
            $linked  = 0;
            $skipped = 0;

            foreach ($keep as $username => $masterUser) {
                $this->stopIfCancelled();
                if ($this->database->getMediaAppUserLinkForApp($masterUser['id'], $listener['id'])) {
                    $skipped++;
                    continue;
                }
                if (!empty($existing[$username])) {
                    $this->database->addMediaAppUserLink($masterUser['id'], $existing[$username]['id']);
                    $linked++;
                    continue;
                }

                $result = $mediaApps->createUser($listener, $masterUser['username']);
                if (!empty($result['error'])) {
                    logger($this->logfile, 'create ' . $masterUser['username'] . ' ' . ($result['message'] ?? ''));
                    continue;
                }
                $created++;
                $mediaApps->refreshUsers($listener['id']);
                $linkedUser = [];
                $remoteId   = $result['remote_id'] ?? '';
                foreach ($this->database->getMediaAppUsers($listener['id']) as $user) {
                    if ($remoteId !== '' && ($user['remote_id'] ?? '') === $remoteId) {
                        $linkedUser = $user;
                        break;
                    }
                    if (strtolower(trim($user['username'])) === $username) {
                        $linkedUser = $user;
                    }
                }
                if ($linkedUser) {
                    $this->database->addMediaAppUserLink($masterUser['id'], $linkedUser['id']);
                    $existing[$username] = $linkedUser;
                    $linked++;
                }
            }

            $mediaApps->refreshUsers($listener['id']);
            $mediaApps->linkUsers();
            if ($created || $linked) {
                $this->database->setMediaAppNeedsSync($listener['id']);
            }
            logger($this->logfile, 'user sync ' . $listener['name'] . ' created=' . $created . ' linked=' . $linked . ' skipped=' . $skipped);
        }
    }
}
