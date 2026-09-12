<?php

/*
----------------------------------
------  Created: 091026   ------
------  Austin Best       ------
----------------------------------
*/

trait NotificationTests
{
    public function getTestPayloads()
    {
        $payloads = [
            'test'             => [
                'event'   => 'test',
                'message' => 'This is a test message sent from ' . APP_NAME,
            ],
            'userLogin'       => [
                'event'    => 'userLogin',
                'username' => 'admin',
            ],
            'userUpdate'      => [
                'event'    => 'userUpdate',
                'username' => 'admin',
            ],
            'connectionError' => [
                'event'    => 'connectionError',
                'mediaApp' => 'plex',
                'message'  => 'Unable to reach the media app',
            ],
            'syncStart'       => [
                'event'     => 'syncStart',
                'id'        => 'pull-20260912-120000',
                'type'      => 'History',
                'trigger'   => 'Manual',
                'mode'      => 'Push and pull',
                'libraries' => 'Movies, TV',
                'users'     => 'admin, alice',
                'mediaApps' => 'Plex, Emby',
            ],
            'syncStartLibrary' => [
                'event'     => 'syncStart',
                'id'        => 'pull-20260912-120100',
                'type'      => 'Library',
                'trigger'   => 'Manual',
                'mode'      => 'Pull only',
                'libraries' => 'Movies, TV',
                'users'     => '',
                'mediaApps' => 'Plex, Emby',
                'scan'      => 'Since last scan',
            ],
            'backup'           => [
                'event'   => 'backup',
                'type'    => 'manual',
                'status'  => 'success',
                'runtime' => '4s',
                'size'    => '12.4 MB',
            ],
        ];

        foreach ($this->getSyncEndTestPayloads() as $name => $payload) {
            $payloads[$name] = $payload;
        }
        $payloads['syncEnd'] = $payloads['syncEndHistory'];

        return $payloads;
    }

    public function getSyncEndTestPayloads()
    {
        return [
            'syncEndHistory' => [
                'event'     => 'syncEnd',
                'id'        => 'history-20260912-120000',
                'type'      => 'History',
                'trigger'   => 'Manual',
                'mode'      => 'Push and pull',
                'status'    => 'finished',
                'runtime'   => '1m 12s',
                'libraries' => 'Movies, TV',
                'users'     => 'admin, alice',
                'mediaApps' => [
                    'Plex' => [
                        'media' => [
                            'pulled' => 48,
                            'pushed' => 18,
                        ],
                        'users' => [
                            'admin' => [
                                'movies' => [
                                    'finished'   => 3,
                                    'inProgress' => 1,
                                ],
                                'episodes' => [
                                    'finished'   => 12,
                                    'inProgress' => 2,
                                ],
                            ],
                            'alice' => [
                                'movies' => [
                                    'finished'   => 1,
                                    'inProgress' => 0,
                                ],
                                'episodes' => [
                                    'finished'   => 4,
                                    'inProgress' => 3,
                                ],
                            ],
                        ],
                    ],
                    'Emby' => [
                        'media' => [
                            'pulled' => 44,
                            'pushed' => 18,
                        ],
                        'users' => [
                            'admin' => [
                                'movies' => [
                                    'finished'   => 2,
                                    'inProgress' => 1,
                                ],
                                'episodes' => [
                                    'finished'   => 8,
                                    'inProgress' => 1,
                                ],
                            ],
                            'alice' => [
                                'movies' => [
                                    'finished'   => 1,
                                    'inProgress' => 0,
                                ],
                                'episodes' => [
                                    'finished'   => 3,
                                    'inProgress' => 2,
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            'syncEndLibrary' => [
                'event'     => 'syncEnd',
                'id'        => 'library-20260912-130000',
                'type'      => 'Library',
                'trigger'   => 'Automatic',
                'mode'      => 'Pull',
                'status'    => 'finished',
                'runtime'   => '42s',
                'libraries' => 'Movies, TV',
                'mediaApps' => [
                    'Plex' => [
                        'media' => [
                            'movies' => [
                                'added'   => 5,
                                'updated' => 2,
                            ],
                            'series' => [
                                'added'   => 1,
                                'updated' => 1,
                            ],
                            'episodes' => [
                                'added'   => 12,
                                'updated' => 4,
                            ],
                        ],
                        'users' => [],
                    ],
                    'Emby' => [
                        'media' => [
                            'movies' => [
                                'added'   => 3,
                                'updated' => 1,
                            ],
                            'series' => [
                                'added'   => 0,
                                'updated' => 1,
                            ],
                            'episodes' => [
                                'added'   => 8,
                                'updated' => 2,
                            ],
                        ],
                        'users' => [],
                    ],
                ],
            ],
            'syncEndParity' => [
                'event'     => 'syncEnd',
                'id'        => 'parity-20260912-140000',
                'type'      => 'Parity users',
                'trigger'   => 'Manual',
                'mode'      => 'Push',
                'status'    => 'finished',
                'runtime'   => '9s',
                'users'     => 'admin, alice, charlie',
                'mediaApps' => [
                    'Emby' => [
                        'media' => [],
                        'users' => [
                            'created' => ['charlie'],
                            'linked'  => ['alice', 'admin'],
                        ],
                    ],
                    'Jellyfin' => [
                        'media' => [],
                        'users' => [
                            'linked' => ['alice'],
                        ],
                    ],
                ],
            ],
            'syncEndParityLibraries' => [
                'event'     => 'syncEnd',
                'id'        => 'parity-libraries-20260912-150000',
                'type'      => 'Parity libraries',
                'trigger'   => 'Manual',
                'mode'      => 'Push',
                'status'    => 'finished',
                'runtime'   => '15s',
                'libraries' => 'Movies, TV, Anime',
                'mediaApps' => [
                    'Emby' => [
                        'media' => [
                            'created' => ['Anime'],
                            'linked'  => ['Movies', 'TV'],
                            'removed' => ['Home Videos'],
                        ],
                        'users' => [],
                    ],
                ],
            ],
        ];
    }
}
