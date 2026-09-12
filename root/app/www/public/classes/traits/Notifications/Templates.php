<?php

/*
----------------------------------
------  Created: 091026   ------
------  Austin Best       ------
----------------------------------
*/

trait NotificationTemplates
{
    public function getTemplate($trigger)
    {
        switch ($trigger) {
            case 'test':
                return [
                    'event'   => '',
                    'message' => '',
                ];
            case 'userLogin':
                return [
                    'event'    => '',
                    'username' => '',
                ];
            case 'userUpdate':
                return [
                    'event'    => '',
                    'username' => '',
                ];
            case 'connectionError':
                return [
                    'event'    => '',
                    'mediaApp' => '',
                    'message'  => '',
                ];
            case 'syncStart':
                return [
                    'event'     => '',
                    'id'        => '',
                    'type'      => '',
                    'trigger'   => '',
                    'mode'      => '',
                    'libraries' => '',
                    'users'     => '',
                    'mediaApps' => '',
                    'scan'      => '',
                ];
            case 'syncEnd':
                return [
                    'event'     => '',
                    'id'        => '',
                    'type'      => '',
                    'trigger'   => '',
                    'mode'      => '',
                    'status'    => '',
                    'runtime'   => '',
                    'libraries' => '',
                    'users'     => '',
                    'mediaApps' => '',
                ];
            case 'backup':
                return [
                    'event'   => '',
                    'type'    => '',
                    'status'  => '',
                    'runtime' => '',
                    'size'    => '',
                ];
            default:
                return [];
        }
    }

    public function buildSyncNotificationLines($payload)
    {
        $labels = [
            'type'       => 'Type',
            'trigger'    => 'Trigger',
            'mode'       => 'Mode',
            'status'     => 'Status',
            'runtime'    => 'Runtime',
            'added'      => 'Added',
            'updated'    => 'Updated',
            'pulled'     => 'Pulled',
            'pushed'     => 'Pushed',
            'created'    => 'Created',
            'linked'     => 'Linked',
            'removed'    => 'Removed',
            'libraries'  => 'Libraries',
            'users'      => 'Users',
            'mediaApps'  => 'Media apps',
            'media'      => 'Media',
            'scan'       => 'Scan',
            'size'       => 'Size',
            'movies'     => 'Movies',
            'series'     => 'Series',
            'episodes'   => 'Episodes',
            'finished'   => 'Finished',
            'inProgress' => 'In progress',
            'started'    => 'Started',
        ];
        $lines  = [];
        foreach ($labels as $field => $label) {
            if (!array_key_exists($field, $payload) || $payload[$field] == '' || $payload[$field] == null) {
                continue;
            }
            if ($field == 'mediaApps' && is_array($payload[$field])) {
                $lines[] = $label . ':';
                $lines   = array_merge($lines, $this->buildNestedNotificationLines($payload[$field], $labels, 0));
                continue;
            }
            if (is_array($payload[$field]) || is_object($payload[$field])) {
                $lines[] = $label . ': ' . json_encode($payload[$field], JSON_UNESCAPED_UNICODE);
                continue;
            }
            $lines[] = $label . ': ' . $payload[$field];
        }

        return implode("\n", $lines);
    }

    public function buildNestedNotificationLines($data, $labels, $depth = 0)
    {
        $lines  = [];
        $indent = str_repeat('  ', max(0, intval($depth)));
        foreach ($data as $key => $value) {
            $label = $labels[$key] ?? $key;
            if (is_array($value)) {
                $isList = array_keys($value) == range(0, count($value) - 1);
                if ($isList) {
                    $lines[] = $indent . $label . ': ' . implode(', ', $value);
                    continue;
                }
                $lines[] = $indent . $label . ':';
                $lines   = array_merge($lines, $this->buildNestedNotificationLines($value, $labels, $depth + 1));
                continue;
            }
            $lines[] = $indent . $label . ': ' . $value;
        }

        return $lines;
    }
}
