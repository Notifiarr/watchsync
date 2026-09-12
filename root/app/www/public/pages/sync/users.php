<?php

/*
----------------------------------
------  Created: 091126   ------
------  Austin Best       ------
----------------------------------
*/

$syncUsers = $syncUsers ?? [];

?>
    <?php if (!$syncUsers) { ?>
    <p class="text-body-secondary mb-0"><?= htmlEscape(translate('noMediaAppUsers')) ?></p>
    <?php } else { ?>
    <div class="mb-2">
        <div class="form-check">
            <input class="form-check-input" type="checkbox" id="syncUserAll" checked onchange="$('.sync-user').prop('checked', $(this).prop('checked'))">
            <label class="form-check-label" for="syncUserAll"><?= htmlEscape(translate('users')) ?></label>
        </div>
    </div>
    <div class="row">
        <?php foreach ($syncUsers as $syncApp) {
            $platformName = $mediaApps->getPlatformName($syncApp['platform']);
            $platformLogo = $mediaApps->getPlatformLogo($syncApp['platform']);
            $isMaster     = intval($syncApp['role']) == MediaAppRoles::MASTER;
            $linkedUsers   = [];
            $unlinkedUsers = [];
            foreach ($syncApp['users'] as $index => $syncUser) {
                $syncUser['_sort'] = $index;
                if (!empty($syncUser['links'])) {
                    $linkedUsers[] = $syncUser;
                } else {
                    $unlinkedUsers[] = $syncUser;
                }
            }
            $userGroups = [
                '1' => ['label' => translate('linked'), 'users' => $linkedUsers],
                '0' => ['label' => translate('unlinked'), 'users' => $unlinkedUsers],
            ];
            ?>
        <div class="col">
            <h3 class="h6 d-flex align-items-center">
                <?php if ($platformLogo) { ?>
                <img src="<?= $platformLogo ?>" alt="<?= htmlEscape($platformName) ?>" class="me-2" style="height: 1.25em; width: auto;">
                <?php } ?>
                <?= htmlEscape($syncApp['name']) ?>
            </h3>
            <?php if (empty($syncApp['users'])) { ?>
            <p class="text-body-secondary small"><?= htmlEscape(translate('noMediaAppUsers')) ?></p>
            <?php } ?>
            <?php foreach ($userGroups as $linkedFlag => $userGroup) { ?>
            <div class="parity-group<?= $linkedFlag === '0' ? '' : ' mb-3' ?>" data-linked="<?= $linkedFlag ?>">
                <div class="small text-body-secondary mb-1"><?= htmlEscape($userGroup['label']) ?></div>
                <?php foreach ($userGroup['users'] as $syncUser) {
                    $linkId = intval($syncUser['link_id'] ?? 0);
                    ?>
                <div class="parity-item mb-1" style="cursor: pointer;" data-kind="user" data-app="<?= intval($syncApp['id']) ?>" data-id="<?= intval($syncUser['id']) ?>" data-master="<?= $isMaster ? '1' : '0' ?>" data-link="<?= $linkId ?>" data-sort="<?= intval($syncUser['_sort']) ?>">
                    <?php if ($isMaster) { ?>
                    <div class="form-check d-inline-block mb-0 me-1">
                        <input class="form-check-input<?= empty($syncUser['linkedAll']) ? ' sync-user' : '' ?>" type="checkbox" id="syncUser-<?= intval($syncUser['id']) ?>" value="<?= intval($syncUser['id']) ?>"<?= empty($syncUser['linkedAll']) ? ' checked' : ' disabled' ?>>
                    </div>
                    <?php } ?>
                    <span class="parity-name"><?= htmlEscape((!empty($syncUser['is_admin']) ? '* ' : '') . $syncUser['username']) ?></span><?php foreach ($syncUser['links'] ?? [] as $linkIcon) { ?>
                    <i class="fas fa-link small parity-unlink ms-1" style="color: <?= htmlEscape($linkIcon['color']) ?>;" data-app="<?= intval($linkIcon['app']) ?>" data-id="<?= htmlEscape($linkIcon['id']) ?>" title="<?= htmlEscape(translate('unlink')) ?>"></i><?php } ?>
                </div>
                <?php } ?>
            </div>
            <?php } ?>
        </div>
        <?php } ?>
    </div>
    <p class="small text-body-secondary mt-2"><?= htmlEscape(translate('adminAccountNote')) ?></p>
    <div class="text-center w-100 mt-3">
        <button type="button" class="btn btn-outline-success" onclick="startUsersSync()"><?= htmlEscape(translate('startSync')) ?></button>
    </div>
    <?php } ?>
