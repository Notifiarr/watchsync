<?php

/*
----------------------------------
------  Created: 091226   ------
------  Austin Best       ------
----------------------------------
*/

chdir(dirname(__DIR__));
require 'loader.php';

$cron->runHousekeeper();
loggerFlush();
