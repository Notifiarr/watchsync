<?php

/*
----------------------------------
------  Created: 092826   ------
------  Austin Best       ------
----------------------------------
*/

$detailRows = $detailRows ?? [];

?>
<div class="table-responsive">
    <table class="table table-bordered table-hover mb-0">
        <tbody>
            <?php foreach ($detailRows as $detailRow) { ?>
                <tr>
                    <th class="text-nowrap"><?= htmlEscape($detailRow[0] ?? '') ?></th>
                    <td class="text-break"><?= htmlEscape(strval($detailRow[1] ?? '')) ?></td>
                </tr>
            <?php } ?>
        </tbody>
    </table>
</div>
