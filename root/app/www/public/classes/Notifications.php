<?php

/*
----------------------------------
------  Created: 091026   ------
------  Austin Best       ------
----------------------------------
*/

loadClassTraits(RELATIVE_PATH . 'classes/traits/Notifications/');

class Notifications
{
    use NotificationTemplates;
    use NotificationTests;
    use Mattermost;
    use Notifiarr;
    use Telegram;

    protected $database;

    public function __construct()
    {
        global $database;

        $this->database = $database;
    }

    public function sendTestNotification($linkId, $name)
    {
        $tests   = $this->getTestPayloads();
        $payload = $tests[$name] ?? $tests['test'];

        return $this->notify($linkId, $name, $payload, true);
    }

    public function notify($linkId, $trigger, $payload, $test = false)
    {
        try {
            $notificationPlatformTable = $this->database->getNotificationPlatforms();
            $notificationTriggersTable = $this->database->getNotificationTriggers();
            $notificationLinkTable     = $this->database->getNotificationLinks();
            $triggerFields             = $this->getTemplate($trigger);

            foreach ($payload as $payloadField => $payloadVal) {
                if (!array_key_exists($payloadField, $triggerFields) || $payloadVal == '' || $payloadVal == null) {
                    unset($payload[$payloadField]);
                }
            }

            $linkIds = [];
            if ($linkId) {
                foreach ($notificationLinkTable as $notificationLink) {
                    if ($notificationLink['id'] == $linkId) {
                        $linkIds[] = $notificationLink;
                    }
                }
            } else {
                foreach ($notificationTriggersTable as $notificationTrigger) {
                    if ($notificationTrigger['name'] == $trigger) {
                        foreach ($notificationLinkTable as $notificationLink) {
                            $triggers = $notificationLink['trigger_ids'] ? json_decode($notificationLink['trigger_ids'], true) : [];
                            if (!is_array($triggers)) {
                                continue;
                            }
                            foreach ($triggers as $linkedTrigger) {
                                if ($linkedTrigger == $notificationTrigger['id']) {
                                    $linkIds[] = $notificationLink;
                                }
                            }
                        }
                        break;
                    }
                }
            }

            $lastResult = ['code' => 200];
            foreach ($linkIds as $linkRow) {
                $platformId         = $linkRow['platform'];
                $platformParameters = json_decode($linkRow['platform_parameters'], true);

                switch ($platformId) {
                    case NotificationPlatforms::NOTIFIARR:
                        $lastResult = $this->notifiarr($platformParameters['apikey'] ?? '', $payload);
                        break;
                    case NotificationPlatforms::TELEGRAM:
                        $lastResult = $this->telegram($platformParameters['botToken'] ?? '', $platformParameters['chatId'] ?? '', $payload, $test);
                        break;
                    case NotificationPlatforms::MATTERMOST:
                        $lastResult = $this->mattermost($platformParameters['url'] ?? '', $payload, $test, $platformParameters['username'] ?? '');
                        break;
                }
            }

            return $lastResult;
        } catch (Throwable $error) {
            return ['code' => 500, 'error' => $error->getMessage()];
        }
    }

    public function getNotificationPlatformNameFromId($id, $platforms)
    {
        foreach ($platforms as $platform) {
            if ($id == $platform['id']) {
                return $platform['platform'];
            }
        }
    }

    public function getNotificationTriggerNameFromId($id, $triggers)
    {
        foreach ($triggers as $trigger) {
            if ($id == $trigger['id']) {
                return $trigger['label'];
            }
        }
    }
}
