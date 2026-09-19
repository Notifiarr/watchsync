<?php

/*
----------------------------------
------  Created: 091326   ------
------  Austin Best       ------
----------------------------------
*/

$masterUsers = [];
foreach ($database->getMediaApps() as $mediaApp) {
    if (intval($mediaApp['role']) == MediaAppRoles::MASTER) {
        $masterUsers = $database->getMediaAppUsers($mediaApp['id']);
        break;
    }
}

$resetApps = [];
foreach ($database->getMediaApps() as $mediaApp) {
    $users = intval($mediaApp['role']) == MediaAppRoles::MASTER || !$masterUsers ? $database->getMediaAppUsers($mediaApp['id']) : $database->getMediaAppUsers($mediaApp['id'], $masterUsers);
    foreach ($users as &$user) {
        $user['movies']   = $database->countUserMovieLinks($user['id'], $mediaApp['platform']);
        $user['episodes'] = $database->countUserEpisodeLinks($user['id'], $mediaApp['platform']);
    }
    unset($user);
    $resetApps[] = $mediaApp + ['users' => $users];
}

$resetUserTotal = 0;
foreach ($resetApps as $resetApp) {
    $resetUserTotal += count($resetApp['users']);
}

?>
<?php if (!$resetUserTotal) { ?>
                            <p class="text-body-secondary mb-0"><?= htmlEscape(translate('noMediaAppUsers')) ?></p>
<?php } else { ?>
                            <div class="row">
                                <?php foreach ($resetApps as $resetApp) {
                                    $platformName = $mediaApps->getPlatformName($resetApp['platform']);
                                    $platformLogo = $mediaApps->getPlatformLogo($resetApp['platform']);
                                    $appId        = intval($resetApp['id']);
                                    ?>
                                                            <div class="col">
                                                                <h3 class="h6 d-flex align-items-center mb-2">
                                                                    <?php if ($platformLogo) { ?>
                                                                                                <img src="<?= $platformLogo ?>" alt="<?= htmlEscape($platformName) ?>" class="me-2" style="height: 1.25em; width: auto;">
                                                                    <?php } ?>
                                                                    <?php if (!empty($resetApp['users'])) { ?>
                                                                                                <label class="form-check-label mb-0" for="resetHistoryUserAll-<?= $appId ?>"><?= htmlEscape($resetApp['name']) ?></label>
                                                                                                <input class="form-check-input ms-2" type="checkbox" id="resetHistoryUserAll-<?= $appId ?>" onchange="$('.reset-history-user-<?= $appId ?>').prop('checked', $(this).prop('checked'))">
                                                                    <?php } else { ?>
                                                                                                <?= htmlEscape($resetApp['name']) ?>
                                                                    <?php } ?>
                                                                </h3>
                                                                <?php if (empty($resetApp['users'])) { ?>
                                                                                            <p class="text-body-secondary small"><?= htmlEscape(translate('noMediaAppUsers')) ?></p>
                                                                <?php } ?>
                                                                <?php foreach ($resetApp['users'] as $resetUser) {
                                                                    if (empty($resetUser['id'])) {
                                                                        continue;
                                                                    }
                                                                    ?>
                                                                                            <div class="form-check mb-2">
                                                                                                <input class="form-check-input reset-history-user reset-history-user-<?= $appId ?>" type="checkbox" id="resetHistoryUser-<?= intval($resetUser['id']) ?>" value="<?= intval($resetUser['id']) ?>">
                                                                                                <label class="form-check-label" for="resetHistoryUser-<?= intval($resetUser['id']) ?>">
                                                                                                    <?= htmlEscape((!empty($resetUser['is_admin']) ? '* ' : '') . $resetUser['username']) ?>
                                                                                                    <span class="text-body-secondary small"><?= htmlEscape(translate('userWatchCount', [intval($resetUser['movies']), intval($resetUser['episodes'])])) ?></span>
                                                                                                </label>
                                                                                            </div>
                                                                <?php } ?>
                                                            </div>
                                <?php } ?>
                            </div>
                            <p class="small text-body-secondary mt-2"><?= htmlEscape(translate('adminAccountNote')) ?></p>
<?php } ?>
