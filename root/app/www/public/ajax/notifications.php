<?php

/*
----------------------------------
------  Created: 091026   ------
------  Austin Best       ------
----------------------------------
*/

if (!defined('RELATIVE_PATH')) {
    switch (true) {
        case file_exists('loader.php'):
            define('RELATIVE_PATH', './');
            break;
        case file_exists('../loader.php'):
            define('RELATIVE_PATH', '../');
            break;
        case file_exists('../../loader.php'):
            define('RELATIVE_PATH', '../../');
            break;
    }
}

require RELATIVE_PATH . 'loader.php';

if (IS_GUEST) {
    echo json_encode(['error' => true, 'message' => translate('notSignedIn')]);
    exit;
}

switch ($_POST['event'] ?? '') {
    case 'openTriggers':
        $_POST['linkId']           = $_POST['linkId'] ?: 0;
        $notificationPlatformTable = $database->getNotificationPlatforms();
        $notificationTriggersTable = $database->getNotificationTriggers();
        $notificationLinkTable     = $database->getNotificationLinks();
        $platformId                = intval($_POST['platformId'] ?? 0);
        $platformParameters        = json_decode($notificationPlatformTable[$platformId]['parameters'] ?? '{}', true);
        $platformName              = $notifications->getNotificationPlatformNameFromId($platformId, $notificationPlatformTable);
        $linkRow                   = $notificationLinkTable[intval($_POST['linkId'])] ?? [];
        $existingTriggers          = [];
        $existingParameters        = [];
        $existingName              = '';

        if ($linkRow) {
            $existingTriggers   = $linkRow['trigger_ids'] ? json_decode($linkRow['trigger_ids'], true) : [];
            $existingParameters = $linkRow['platform_parameters'] ? json_decode($linkRow['platform_parameters'], true) : [];
            $existingName       = $linkRow['name'];
        }
        if (!is_array($existingTriggers)) {
            $existingTriggers = [];
        }
        if (!is_array($existingParameters)) {
            $existingParameters = [];
        }
        if (!is_array($platformParameters)) {
            $platformParameters = [];
        }
        $testPayloads = $notifications->getTestPayloads();
        ?>
                                                                <div class="container-fluid">
                                                                    <h3><?= htmlEscape($platformName) ?></h3>
                                                                    <table class="table table-bordered table-hover table-no-squish">
                                                                        <thead>
                                                                            <tr>
                                                                                <th><input type="checkbox" class="form-check-input" onchange="$('.notification-trigger').prop('checked', $(this).prop('checked'))"></th>
                                                                                <th><?= htmlEscape(translate('trigger')) ?></th>
                                                                                <th><?= htmlEscape(translate('description')) ?></th>
                                                                                <th><?= htmlEscape(translate('event')) ?></th>
                                                                            </tr>
                                                                        </thead>
                                                                        <tbody>
                                                                            <?php foreach ($notificationTriggersTable as $notificationTrigger) {
                                                                                $triggerName = $notificationTrigger['name'];
                                                                                $preview     = $testPayloads[$triggerName] ?? ['event' => $triggerName];
                                                                                $fields      = $notifications->getTemplate($triggerName);
                                                                                if ($fields) {
                                                                                    foreach ($preview as $payloadField => $payloadVal) {
                                                                                        if (!array_key_exists($payloadField, $fields) || $payloadVal == '' || $payloadVal == null) {
                                                                                            unset($preview[$payloadField]);
                                                                                        }
                                                                                    }
                                                                                }
                                                                                $previewJson = json_encode($preview, JSON_UNESCAPED_UNICODE);
                                                                                if ($previewJson == false) {
                                                                                    $previewJson = '{}';
                                                                                }
                                                                                ?>
                                                                                                            <tr>
                                                                                                                <td><input <?= in_array($notificationTrigger['id'], $existingTriggers) ? 'checked' : '' ?> type="checkbox" class="form-check-input notification-trigger" id="notificationTrigger-<?= intval($notificationTrigger['id']) ?>"></td>
                                                                                                                <td>
                                                                                                                    <?php if ($linkRow) { ?>
                                                                                                                                                    <i class="far fa-bell" style="cursor: pointer;" title="<?= htmlEscape(translate('testNotification')) ?>" onclick="testNotify(<?= intval($linkRow['id']) ?>, '<?= htmlEscape($triggerName) ?>')"></i>
                                                                                                                    <?php } ?>
                                                                                                                    <?= htmlEscape($notificationTrigger['label']) ?>
                                                                                                                </td>
                                                                                                                <td><?= htmlEscape($notificationTrigger['description']) ?></td>
                                                                                                                <td style="cursor: pointer;" data-name="<?= htmlEscape($triggerName) ?>" data-payload="<?= htmlEscape($previewJson) ?>" onclick="previewNotificationPayload(this)"><?= htmlEscape($notificationTrigger['event']) ?></td>
                                                                                                            </tr>
                                                                            <?php } ?>
                                                                        </tbody>
                                                                    </table>
                                                                    <table class="table table-bordered table-hover table-no-squish">
                                                                        <thead>
                                                                            <tr>
                                                                                <th><?= htmlEscape(translate('setting')) ?></th>
                                                                                <th></th>
                                                                            </tr>
                                                                        </thead>
                                                                        <tbody>
                                                                            <tr>
                                                                                <td>
                                                                                    <?= htmlEscape(translate('senderName')) ?> <span class="small text-danger"><?= htmlEscape(translate('required')) ?></span><br>
                                                                                    <span class="small"><?= htmlEscape(translate('senderNameDescription')) ?></span>
                                                                                </td>
                                                                                <td><input data-required="true" type="text" class="form-control" value="<?= htmlEscape($existingName ?: $platformName) ?>" id="notificationPlatformParameter-name"></td>
                                                                            </tr>
                                                                            <?php foreach ($platformParameters as $platformParameterField => $platformParameterData) { ?>
                                                                                                            <tr>
                                                                                                                <td>
                                                                                                                    <?= htmlEscape($platformParameterData['label']) ?>                                                                                                <?= !empty($platformParameterData['required']) ? ' <span class="small text-danger">' . htmlEscape(translate('required')) . '</span>' : '' ?><br>
                                                                                                                    <span class="small"><?= htmlEscape($platformParameterData['description']) ?></span>
                                                                                                                </td>
                                                                                                                <td>
                                                                                                                    <?php
                                                                                                                    switch ($platformParameterData['type']) {
                                                                                                                        case 'text':
                                                                                                                            ?><input <?= !empty($platformParameterData['required']) ? 'data-required="true"' : '' ?> type="text" id="notificationPlatformParameter-<?= htmlEscape($platformParameterField) ?>" class="form-control" value="<?= htmlEscape($existingParameters[$platformParameterField] ?? '') ?>"><?php
                                                                                                                                         break;
                                                                                                                    }
                                                                                                                    ?>
                                                                                                                </td>
                                                                                                            </tr>
                                                                            <?php } ?>
                                                                        </tbody>
                                                                    </table>
                                                                    <div class="text-center w-100 mt-3">
                                                                        <?php if ($linkRow) { ?>
                                                                                                        <button type="button" class="btn btn-outline-success" onclick="saveNotification(<?= $platformId ?>, <?= intval($linkRow['id']) ?>)"><?= htmlEscape(translate('save')) ?></button>
                                                                                                        <button type="button" class="btn btn-outline-danger" onclick="deleteNotification(<?= intval($linkRow['id']) ?>)"><?= htmlEscape(translate('remove')) ?></button>
                                                                        <?php } else { ?>
                                                                                                        <button type="button" class="btn btn-outline-success" onclick="addNotification(<?= $platformId ?>)"><?= htmlEscape(translate('add')) ?></button>
                                                                        <?php } ?>
                                                                    </div>
                                                                </div>
                                                                <?php
                                                                exit;

    case 'add':
    case 'save':
        $error      = '';
        $platformId = intval($_POST['platformId'] ?? 0);
        $linkId     = intval($_POST['linkId'] ?? 0);

        if (!$platformId) {
            $error = translate('missingRequiredField', [translate('platforms')]);
        }
        if ($_POST['event'] == 'save' && !$linkId) {
            $error = translate('missingRequiredField', [translate('configuredSenders')]);
        }

        if (!$error) {
            $notificationPlatformTable = $database->getNotificationPlatforms();
            $platformParametersDef     = json_decode($notificationPlatformTable[$platformId]['parameters'] ?? '{}', true);
            $platformName              = $notifications->getNotificationPlatformNameFromId($platformId, $notificationPlatformTable);

            if (is_array($platformParametersDef)) {
                foreach ($platformParametersDef as $platformParameterField => $platformParameterData) {
                    if (!empty($platformParameterData['required']) && !($_POST['notificationPlatformParameter-' . $platformParameterField] ?? '')) {
                        $error = translate('missingRequiredField', [$platformParameterData['label']]);
                        break;
                    }
                }
            }

            if (!$error) {
                $triggerIds         = [];
                $platformParameters = [];
                $senderName         = $platformName;

                foreach ($_POST as $key => $val) {
                    if (str_contains($key, 'notificationTrigger-') && $val) {
                        $triggerIds[] = str_replace('notificationTrigger-', '', $key);
                    }

                    if (str_contains($key, 'notificationPlatformParameter-')) {
                        $field = str_replace('notificationPlatformParameter-', '', $key);

                        if ($field != 'name') {
                            $platformParameters[$field] = $val;
                        } else {
                            $senderName = $val;
                        }
                    }
                }

                if ($_POST['event'] == 'save') {
                    $database->updateNotificationLink($linkId, $triggerIds, $platformParameters, $senderName);
                } else {
                    $database->addNotificationLink($platformId, $triggerIds, $platformParameters, $senderName);
                }
            }
        }

        echo json_encode(['error' => $error ? true : false, 'message' => $error]);
        exit;

    case 'delete':
        $database->deleteNotificationLink(intval($_POST['linkId'] ?? 0));
        echo json_encode(['error' => false, 'message' => translate('saved')]);
        exit;

    case 'test':
        $apiResponse = $notifications->sendTestNotification(intval($_POST['linkId'] ?? 0), $_POST['name'] ?? 'test');

        if (($apiResponse['code'] ?? 0) == 200) {
            echo json_encode(['error' => false, 'message' => translate('testNotificationSent')]);
            exit;
        }

        $detail = $apiResponse['error'] ?? '';
        if (is_array($detail) || is_object($detail)) {
            $detail = json_encode($detail);
        }
        $detail = trim(strval($detail));

        echo json_encode([
            'error'   => true,
            'message' => translate('testNotificationFailed') . ($detail != '' ? ' ' . $detail : ''),
        ]);
        exit;

    case 'previewPayload':
        $name    = trim(strval($_POST['name'] ?? ''));
        $payload = json_decode(strval($_POST['payload'] ?? '{}'), true);
        if (!is_array($payload)) {
            $payload = [];
        }
        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json == false) {
            $json = '{}';
        }
        echo json_encode([
            'error' => false,
            'title' => $name != '' ? $name : translate('event'),
            'body'  => '<pre class="mb-0">' . htmlEscape($json) . '</pre>',
        ]);
        exit;

    default:
        echo json_encode(['error' => true, 'message' => translate('unknownSettingsEvent')]);
        exit;
}
