<?php

/*
----------------------------------
------  Created: 091226   ------
------  Austin Best       ------
----------------------------------
*/

$backups = getBackupList();

?>
<div class="table-responsive">
    <table class="table table-bordered table-hover">
        <thead>
            <tr>
                <th><?= htmlEscape(translate('date')) ?></th>
                <th><?= htmlEscape(translate('time')) ?></th>
                <th><?= htmlEscape(translate('method')) ?></th>
                <th><?= htmlEscape(translate('size')) ?></th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            <?php if (!$backups) { ?>
                <tr class="backup-empty">
                    <td colspan="5"><?= htmlEscape(translate('noBackups')) ?></td>
                </tr>
            <?php } ?>
            <?php foreach ($backups as $backup) {
                $date = date_create_from_format('Ymd', $backup['date']);
                $time = date_create_from_format('His', $backup['time']);
                ?>
                <tr>
                    <td><?= htmlEscape($date ? $date->format('m/d/Y') : $backup['date']) ?></td>
                    <td><?= htmlEscape($time ? $time->format('g:i A') : $backup['time']) ?></td>
                    <td><?= htmlEscape(translate($backup['method'])) ?></td>
                    <td><?= htmlEscape(byteConversion($backup['size'])) ?></td>
                    <td class="backup-actions">
                        <i class="fa-solid fa-download me-2" style="cursor: pointer;" title="<?= htmlEscape(translate('download')) ?>" onclick="downloadBackup('<?= htmlEscape($backup['date']) ?>', '<?= htmlEscape($backup['run']) ?>');"></i>
                        <i class="fa-solid fa-rotate-left me-2 text-warning" style="cursor: pointer;" title="<?= htmlEscape(translate('restore')) ?>" onclick="restoreBackup('<?= htmlEscape($backup['date']) ?>', '<?= htmlEscape($backup['run']) ?>');"></i>
                        <i class="fas fa-trash text-danger" style="cursor: pointer;" title="<?= htmlEscape(translate('remove')) ?>" onclick="deleteBackup('<?= htmlEscape($backup['date']) ?>', '<?= htmlEscape($backup['run']) ?>');"></i>
                    </td>
                </tr>
            <?php } ?>
        </tbody>
    </table>
</div>