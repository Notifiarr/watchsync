<?php

/*
----------------------------------
------  Created: 091226   ------
------  Austin Best       ------
----------------------------------
*/

function gitBranch()
{
    if (!defined('WATCHSYNC_BRANCH')) {
        return 'Source';
    }

    return WATCHSYNC_BRANCH;
}

function gitHash()
{
    if (!defined('WATCHSYNC_COMMIT')) {
        return 'Unknown';
    }

    return WATCHSYNC_COMMIT;
}

function gitVersion($full = false)
{
    if (!defined('WATCHSYNC_COMMITS') && !defined('WATCHSYNC_BRANCH')) {
        return ($full ? 'v' : '') . '0.0.0' . ($full ? ' - ' . gitBranch() : '');
    }

    $commits = defined('WATCHSYNC_COMMITS') ? WATCHSYNC_COMMITS : '0';
    if ($full) {
        return 'v' . APP_X . '.' . APP_Y . '.' . $commits . ' - ' . gitBranch();
    }

    return APP_X . '.' . APP_Y . '.' . $commits;
}
