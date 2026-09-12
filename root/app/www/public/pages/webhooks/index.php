<?php

/*
----------------------------------
------  Created: 091626   ------
------  Austin Best       ------
----------------------------------
*/

$apiKey       = strval($database->getSetting('apiKey'));
$apps         = $database->getMediaApps();
$logs         = webhookLogRows();
$webhookUsers = [];
$sql          = "SELECT u.remote_id, u.username, a.platform
                 FROM " . MEDIA_APP_USER_TABLE . " u
                 INNER JOIN " . MEDIA_APP_TABLE . " a ON a.id = u.media_app_id";
$res          = $database->query($sql);
while ($row = $database->fetchAssoc($res)) {
    $remote = preg_replace('/[^A-Za-z0-9-]/', '', strval($row['remote_id'] ?? ''));
    if ($remote == '') {
        continue;
    }
    switch (intval($row['platform'] ?? 0)) {
        case MediaPlatforms::EMBY:
            $slug = 'emby';
            break;
        case MediaPlatforms::JELLYFIN:
            $slug = 'jellyfin';
            break;
        default:
            $slug = 'plex';
            break;
    }
    $webhookUsers[$slug][$remote] = strval($row['username'] ?? '');
    $named                        = strtolower(trim(strval($row['username'] ?? '')));
    if ($named != '') {
        $webhookUsers[$slug]['name:' . $named] = strval($row['username']);
    }
}

?>
<div class="row">
    <div class="col-12 mb-3">
        <h1 class="h3 mb-0"><?= htmlEscape(translate('webhooks')) ?></h1>
    </div>
    <div class="col-12 mb-3">
        <div class="card border shadow-sm">
            <div class="card-body">
                <div class="mb-3">
                    <label for="webhookApiKey" class="form-label"><?= htmlEscape(translate('webhookApiKey')) ?></label>
                    <div class="input-group">
                        <input type="text" class="form-control" id="webhookApiKey" value="<?= htmlEscape($apiKey) ?>" autocomplete="off" oninput="updateWebhookUrl();">
                        <button type="button" class="btn btn-outline-secondary" onclick="generateWebhookApiKey();"><?= htmlEscape(translate('generate')) ?></button>
                    </div>
                    <div class="form-text"><?= htmlEscape(translate('webhookApiKeyDescription')) ?></div>
                </div>
                <div class="mb-3">
                    <label for="webhookUrl" class="form-label"><?= htmlEscape(translate('webhookUrl')) ?></label>
                    <div class="input-group">
                        <input type="text" class="form-control" id="webhookUrl" readonly>
                        <button type="button" class="btn btn-outline-secondary" onclick="copyWebhookUrl();"><?= htmlEscape(translate('copy')) ?></button>
                    </div>
                    <div class="form-text"><?= htmlEscape(translate('webhookUrlDescription')) ?></div>
                </div>
                <div class="mb-3">
                    <div class="form-label"><?= htmlEscape(translate('webhookApps')) ?></div>
                    <?php if (!$apps) { ?>
                                            <p class="text-body-secondary mb-0"><?= htmlEscape(translate('noMediaApps')) ?></p>
                    <?php } else { ?>
                                            <?php foreach ($apps as $mediaApp) { ?>
                                                                    <div class="form-check form-switch mb-2">
                                                                        <input class="form-check-input webhook-app" type="checkbox" role="switch" id="webhookApp-<?= intval($mediaApp['id']) ?>" value="<?= intval($mediaApp['id']) ?>"<?= !empty($mediaApp['webhooks']) ? ' checked' : '' ?>>
                                                                        <label class="form-check-label" for="webhookApp-<?= intval($mediaApp['id']) ?>"><?= htmlEscape($mediaApp['name']) ?> (<?= htmlEscape($mediaApps->getPlatformName($mediaApp['platform'])) ?>)</label>
                                                                    </div>
                                            <?php } ?>
                    <?php } ?>
                </div>
                <button type="button" class="btn btn-primary" onclick="saveWebhooks();"><?= htmlEscape(translate('save')) ?></button>
            </div>
        </div>
    </div>
    <div class="col-12">
        <div class="table-responsive">
            <table class="table table-bordered table-hover table-no-squish">
                <colgroup>
                    <col style="width: 20%;">
                    <col style="width: 12%;">
                    <col>
                    <col style="width: 20%;">
                    <col style="width: 8%;">
                    <col style="width: 5%;">
                </colgroup>
                <thead>
                    <tr>
                        <th style="width: 20%;"><?= htmlEscape(translate('time')) ?></th>
                        <th style="width: 12%;"><?= htmlEscape(translate('app')) ?></th>
                        <th><?= htmlEscape(translate('type')) ?></th>
                        <th style="width: 20%;"><?= htmlEscape(translate('user')) ?></th>
                        <th style="width: 8%;"><?= htmlEscape(translate('code')) ?></th>
                        <th class="text-center" style="width: 5%;">
                            <i class="fas fa-trash text-danger" style="cursor: pointer;" title="<?= htmlEscape(translate('clearWebhookLogs')) ?>" onclick="deleteAllWebhookLogs()"></i>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$logs) { ?>
                                            <tr>
                                                <td colspan="6"><?= htmlEscape(translate('noWebhookLogs')) ?></td>
                                            </tr>
                    <?php } ?>
                    <?php foreach ($logs as $log) {
                        $path     = 'webhooks/' . $log['file'];
                        $username = webhookLogUsername($log, $webhookUsers);
                        $type     = webhookEventLabel($log['event']);
                        ?>
                                            <tr style="cursor: pointer;" onclick="viewWebhookLog('<?= htmlEscape($path) ?>');">
                                                <td style="width: 20%;"><?= htmlEscape($log['time'] ? date('Y-m-d g:i:s A', $log['time']) : '') ?></td>
                                                <td style="width: 12%;"><?= $log['app'] != '' ? htmlEscape(translate($log['app'])) : '' ?></td>
                                                <td><?= htmlEscape($type) ?></td>
                                                <td style="width: 20%;"><?= htmlEscape($username) ?></td>
                                                <td class="<?= intval($log['code'] ?? 0) >= 400 ? 'text-danger' : '' ?>" style="width: 8%;"><?= htmlEscape($log['code'] ?? '') ?></td>
                                                <td class="text-center" style="width: 5%;" onclick="event.stopPropagation();">
                                                    <i class="fas fa-trash text-danger" style="cursor: pointer;" title="<?= htmlEscape(translate('remove')) ?>" onclick="deleteWebhookLog('<?= htmlEscape($log['file']) ?>');"></i>
                                                </td>
                                            </tr>
                    <?php } ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
