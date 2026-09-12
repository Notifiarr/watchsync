<?php

/*
----------------------------------
------  Created: 091526   ------
------  Austin Best       ------
----------------------------------
*/

function mediaAppUserTokenIconHtml($userId, $hasToken, $manual = false, $pinRequired = false, $hasPin = false)
{
    $userId = intval($userId);
    if (!$hasToken) {
        $icon = 'fa-xmark text-danger';
    } else if ($manual) {
        $icon = 'fa-file-pen text-info';
    } else {
        $icon = 'fa-check text-success';
    }

    $html  = '<span class="media-app-user-token-wrap" data-user-id="' . $userId . '" data-pin-required="' . ($pinRequired ? '1' : '0') . '">';
    $html .= '<i class="fas ' . $icon . ' media-app-user-token" data-user-id="' . $userId . '" style="cursor: pointer;" title="' . htmlEscape(translate('editToken')) . '" onclick="editMediaAppUserToken(' . $userId . ');"></i>';
    if ($hasToken && $pinRequired && !$hasPin) {
        $html .= ' <i class="fas fa-exclamation text-warning" style="cursor: pointer;" title="' . htmlEscape(translate('pinRequired')) . '" onclick="editMediaAppUserToken(' . $userId . ');"></i>';
    }
    $html .= '</span>';

    return $html;
}

function mediaAppUserTokenIconFromRow($user)
{
    return mediaAppUserTokenIconHtml(
        intval($user['id'] ?? 0),
        !empty($user['has_token']),
        intval($user['token_age'] ?? 0) == -1,
        !empty($user['pin_required']),
        trim(strval($user['pin'] ?? '')) != ''
    );
}
