<?php

/*
----------------------------------
------  Created: 091326   ------
------  Austin Best       ------
----------------------------------
*/

$masterApp      = $mediaApps->masterMediaApp();
$libraryUsers   = $masterApp ? $database->getMediaAppUsers($masterApp['id']) : [];
$libraryLetters = array_merge(['#'], range('A', 'Z'));
$libraryStats   = $database->getLibraryUserWatchStats($libraryUsers);

?>
<div class="row">
    <div class="col-12 mb-3">
        <h1 class="h3 mb-0"><?= htmlEscape(translate('library')) ?></h1>
    </div>
    <div class="col-12 mb-3">
        <div class="card border shadow-sm">
            <button class="library-stats-toggle card-header d-flex align-items-center justify-content-between collapsed"
                    type="button"
                    data-bs-toggle="collapse"
                    data-bs-target="#libraryStatsBody"
                    aria-expanded="false"
                    aria-controls="libraryStatsBody">
                <span><?= htmlEscape(translate('stats')) ?></span>
                <i class="fa-solid fa-chevron-down library-stats-chevron" aria-hidden="true"></i>
            </button>
            <div id="libraryStatsBody" class="collapse">
                <div class="card-body">
                    <?php if (!$libraryStats) { ?>
                                                <p class="text-body-secondary mb-0"><?= htmlEscape(translate('noMediaAppUsers')) ?></p>
                    <?php } else { ?>
                                                <div class="table-responsive">
                                                    <table class="table table-sm table-hover align-middle mb-0" id="libraryStatsTable">
                                                        <thead>
                                                            <tr>
                                                                <th class="library-stats-sort" data-sort="text" scope="col">
                                                                    <?= htmlEscape(translate('users')) ?>
                                                                    <i class="fa-solid fa-sort library-stats-sort-icon" aria-hidden="true"></i>
                                                                </th>
                                                                <th class="library-stats-sort text-end" data-sort="number" scope="col">
                                                                    <?= htmlEscape(translate('moviesWatched')) ?>
                                                                    <i class="fa-solid fa-sort library-stats-sort-icon" aria-hidden="true"></i>
                                                                </th>
                                                                <th class="library-stats-sort text-end" data-sort="number" scope="col">
                                                                    <?= htmlEscape(translate('moviesInProgress')) ?>
                                                                    <i class="fa-solid fa-sort library-stats-sort-icon" aria-hidden="true"></i>
                                                                </th>
                                                                <th class="library-stats-sort text-end" data-sort="number" scope="col">
                                                                    <?= htmlEscape(translate('episodesWatched')) ?>
                                                                    <i class="fa-solid fa-sort library-stats-sort-icon" aria-hidden="true"></i>
                                                                </th>
                                                                <th class="library-stats-sort text-end" data-sort="number" scope="col">
                                                                    <?= htmlEscape(translate('episodesInProgress')) ?>
                                                                    <i class="fa-solid fa-sort library-stats-sort-icon" aria-hidden="true"></i>
                                                                </th>
                                                                <th class="library-stats-sort text-end" data-sort="number" scope="col">
                                                                    <?= htmlEscape(translate('timeWatched')) ?>
                                                                    <i class="fa-solid fa-sort library-stats-sort-icon" aria-hidden="true"></i>
                                                                </th>
                                                            </tr>
                                                        </thead>
                                                        <tbody>
                                                            <?php foreach ($libraryStats as $stat) { ?>
                                                                                        <tr>
                                                                                            <td data-value="<?= htmlEscape($stat['username'] ?? '') ?>"><?= htmlEscape((!empty($stat['is_admin']) ? '* ' : '') . ($stat['username'] ?? '')) ?></td>
                                                                                            <td class="text-end" data-value="<?= intval($stat['movies_watched']) ?>"><?= number_format(intval($stat['movies_watched'])) ?></td>
                                                                                            <td class="text-end" data-value="<?= intval($stat['movies_started']) ?>"><?= number_format(intval($stat['movies_started'])) ?></td>
                                                                                            <td class="text-end" data-value="<?= intval($stat['episodes_watched']) ?>"><?= number_format(intval($stat['episodes_watched'])) ?></td>
                                                                                            <td class="text-end" data-value="<?= intval($stat['episodes_started']) ?>"><?= number_format(intval($stat['episodes_started'])) ?></td>
                                                                                            <td class="text-end" data-value="<?= intval($stat['watch_seconds'] ?? 0) ?>"><?= htmlEscape(formatWatchDuration(intval($stat['watch_seconds'] ?? 0))) ?></td>
                                                                                        </tr>
                                                            <?php } ?>
                                                        </tbody>
                                                    </table>
                                                </div>
                    <?php } ?>
                </div>
            </div>
        </div>
    </div>
    <div class="col-12">
        <div class="card border shadow-sm">
            <div class="card-body">
                <div class="row g-3 mb-3">
                    <div class="col-sm-6 col-lg-3">
                        <label for="libraryType" class="form-label"><?= htmlEscape(translate('type')) ?></label>
                        <select class="form-select" id="libraryType">
                            <option value="all"><?= htmlEscape(translate('allTypes')) ?></option>
                            <option value="movie"><?= htmlEscape(translate('movies')) ?></option>
                            <option value="series"><?= htmlEscape(translate('series')) ?></option>
                        </select>
                    </div>
                    <div class="col-sm-6 col-lg-3">
                        <label for="libraryUser" class="form-label"><?= htmlEscape(translate('users')) ?></label>
                        <select class="form-select" id="libraryUser">
                            <option value="0"><?= htmlEscape(translate('allUsers')) ?></option>
                            <?php foreach ($libraryUsers as $libraryUser) { ?>
                                                        <option value="<?= intval($libraryUser['id']) ?>"><?= htmlEscape((!empty($libraryUser['is_admin']) ? '* ' : '') . $libraryUser['username']) ?></option>
                            <?php } ?>
                        </select>
                    </div>
                </div>
                <div class="library-browser">
                    <div class="library-az" id="libraryAz">
                        <?php foreach ($libraryLetters as $libraryLetter) { ?>
                                                    <button type="button" class="library-az-letter disabled" data-letter="<?= htmlEscape($libraryLetter) ?>"><?= htmlEscape($libraryLetter) ?></button>
                        <?php } ?>
                    </div>
                    <div class="library-list-wrap" id="libraryListWrap">
                        <div class="library-sentinel" id="librarySentinelTop"></div>
                        <div class="library-list" id="libraryList"></div>
                        <div class="library-empty text-body-secondary d-none" id="libraryEmpty"><?= htmlEscape(translate('noLibraryItems')) ?></div>
                        <div class="library-sentinel" id="librarySentinelBottom"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
