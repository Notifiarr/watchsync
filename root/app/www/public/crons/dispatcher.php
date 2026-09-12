<?php

/*
----------------------------------
------  Created: 091326   ------
------  Austin Best       ------
----------------------------------
*/

chdir(dirname(__DIR__));
require 'loader.php';

$cron->dispatch();
loggerFlush();
