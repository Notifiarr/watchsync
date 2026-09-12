<?php

/*
----------------------------------
------  Created: 091126   ------
------  Austin Best       ------
----------------------------------
*/

$rootFolders = $rootFolders ?? [];

?>
<div class="container-fluid">
    <?php if (!$rootFolders) { ?>
                <p class="text-body-secondary mb-0"><?= htmlEscape(translate('noRootFolders')) ?></p>
    <?php } else { ?>
                <table class="table table-bordered table-hover table-no-squish">
                    <tbody>
                        <?php foreach ($rootFolders as $folder) { ?>
                                    <tr>
                                        <td><?= htmlEscape($folder) ?></td>
                                    </tr>
                        <?php } ?>
                    </tbody>
                </table>
    <?php } ?>
</div>
