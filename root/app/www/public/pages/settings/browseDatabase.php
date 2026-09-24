<?php

/*
----------------------------------
------  Created: 092426   ------
------  Austin Best       ------
----------------------------------
*/

$browseView = strval($browseView ?? 'list');

switch ($browseView) {
    case 'table':
        $browse   = $browse ?? [];
        $sql      = strval($browse['sql'] ?? '');
        $columns  = $browse['columns'] ?? [];
        $rows     = $browse['rows'] ?? [];
        $page     = max(1, intval($browse['page'] ?? 1));
        $pages    = max(1, intval($browse['pages'] ?? 1));
        $total    = max(0, intval($browse['total'] ?? 0));
        $perPage  = max(1, intval($browse['perPage'] ?? 25));
        $start    = $total ? (($page - 1) * $perPage) + 1 : 0;
        $end      = $total ? min($page * $perPage, $total) : 0;
        ?>
        <div class="browse-database-viewer">
            <div class="browse-database-toolbar">
                <div class="browse-database-query">
                    <code class="small"><?= htmlEscape($sql) ?></code>
                </div>
                <div class="browse-database-pager">
                    <button type="button" class="btn btn-outline-secondary btn-sm" onclick="browseDatabasePrev();" <?= $page <= 1 ? ' disabled' : '' ?>><?= htmlEscape(translate('previous')) ?></button>
                    <span class="text-body-secondary small"><?= htmlEscape(translate('browseDatabasePageInfo', [$start, $end, $total, $page, $pages])) ?></span>
                    <button type="button" class="btn btn-outline-secondary btn-sm" onclick="browseDatabaseNext();" <?= $page >= $pages ? ' disabled' : '' ?>><?= htmlEscape(translate('next')) ?></button>
                </div>
            </div>
            <div class="browse-database-table table-responsive">
                <?php if ($rows) { ?>
                    <table class="table table-bordered table-hover table-sm mb-0">
                        <thead>
                            <tr>
                                <?php foreach ($columns as $field) { ?>
                                    <th><?= htmlEscape(strval($field)) ?></th>
                                <?php } ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rows as $row) { ?>
                                <tr>
                                    <?php foreach ($columns as $field) { ?>
                                        <td><?= htmlEscape(strval($row[$field] ?? '')) ?></td>
                                    <?php } ?>
                                </tr>
                            <?php } ?>
                        </tbody>
                    </table>
                <?php } else { ?>
                    <div class="text-body-secondary"><?= htmlEscape(translate('browseDatabaseEmpty')) ?></div>
                <?php } ?>
            </div>
        </div>
        <?php
        break;

    case 'schema':
        $browse = $browse ?? [];
        $schema = strval($browse['schema'] ?? '');
        ?>
        <pre class="mb-0 small overflow-auto"><?= htmlEscape($schema) ?></pre>
        <?php
        break;

    case 'query':
        $browse   = $browse ?? [];
        $sql      = strval($browse['sql'] ?? '');
        $columns  = $browse['columns'] ?? [];
        $rows     = $browse['rows'] ?? [];
        $total    = max(0, intval($browse['total'] ?? 0));
        $capped   = !empty($browse['capped']);
        $maxRows  = max(1, intval($browse['maxRows'] ?? 500));
        $affected = array_key_exists('affected', $browse) ? $browse['affected'] : null;
        ?>
        <div class="mb-2">
            <code class="small"><?= htmlEscape($sql) ?></code>
        </div>
        <?php if ($capped) { ?>
            <div class="text-body-secondary small mb-2"><?= htmlEscape(translate('browseDatabaseQueryCapped', [$maxRows])) ?></div>
        <?php } ?>
        <?php if ($rows) { ?>
            <div class="table-responsive">
                <table class="table table-bordered table-hover table-sm mb-0">
                    <thead>
                        <tr>
                            <?php foreach ($columns as $field) { ?>
                                <th><?= htmlEscape(strval($field)) ?></th>
                            <?php } ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $row) { ?>
                            <tr>
                                <?php foreach ($columns as $field) { ?>
                                    <td><?= htmlEscape(strval($row[$field] ?? '')) ?></td>
                                <?php } ?>
                            </tr>
                        <?php } ?>
                    </tbody>
                </table>
            </div>
            <div class="text-body-secondary small mt-2"><?= htmlEscape(translate('browseDatabaseQueryRows', [number_format($total)])) ?></div>
        <?php } else if ($affected != null) { ?>
            <div class="text-body-secondary"><?= htmlEscape(translate('browseDatabaseQueryAffected', [number_format(intval($affected))])) ?></div>
        <?php } else { ?>
            <div class="text-body-secondary"><?= htmlEscape(translate('browseDatabaseQueryNoRows')) ?></div>
        <?php } ?>
        <?php
        break;

    case 'list':
    default:
        $browseSummary = $browseSummary ?? [];
        ?>
        <div class="table-responsive">
            <table class="table table-bordered table-hover">
                <thead>
                    <tr>
                        <th><?= htmlEscape(translate('table')) ?></th>
                        <th><?= htmlEscape(translate('columns')) ?></th>
                        <th><?= htmlEscape(translate('rows')) ?></th>
                        <th><?= htmlEscape(translate('size')) ?></th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$browseSummary) { ?>
                        <tr>
                            <td colspan="5"><?= htmlEscape(translate('browseDatabaseNoTables')) ?></td>
                        </tr>
                    <?php } ?>
                    <?php foreach ($browseSummary as $browseTable) { ?>
                        <tr>
                            <td><?= htmlEscape(strval($browseTable['table'] ?? '')) ?></td>
                            <td><?= htmlEscape(strval($browseTable['columns'] ?? 0)) ?></td>
                            <td><?= htmlEscape(number_format(intval($browseTable['rows'] ?? 0))) ?></td>
                            <td><?= htmlEscape(byteConversion($browseTable['size'] ?? 0)) ?></td>
                            <td>
                                <i class="fa-solid fa-table me-2" style="cursor: pointer;" title="<?= htmlEscape(translate('browse')) ?>" onclick="viewDatabaseBrowse('<?= htmlEscape(strval($browseTable['table'] ?? '')) ?>');"></i>
                                <i class="fa-solid fa-list" style="cursor: pointer;" title="<?= htmlEscape(translate('schema')) ?>" onclick="viewDatabaseSchema('<?= htmlEscape(strval($browseTable['table'] ?? '')) ?>');"></i>
                            </td>
                        </tr>
                    <?php } ?>
                </tbody>
            </table>
        </div>
        <?php
        break;
}
