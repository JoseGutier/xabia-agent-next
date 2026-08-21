#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Reloj Maestro del Hub — dispara el pipeline auto-sync en sitios WordPress remotos.
 *
 * Uso:
 *   php scripts/cloud-cron-trigger.php
 *   php scripts/cloud-cron-trigger.php --dry-run
 *   php scripts/cloud-cron-trigger.php --limit 50 --sleep-ms 500
 *
 * Cron Hostinger (cada 5 min, ajustar ruta al búnker):
 *   0,5,10,15,20,25,30,35,40,45,50,55 * * * * /usr/bin/php /home/u610697097/central-api/scripts/cloud-cron-trigger.php >> /home/u610697097/logs/xabia-cloud-cron.log 2>&1
 */

require dirname(__DIR__) . '/bootstrap.php';

exit(\XabiaCentral\CloudCronTrigger::runFromCli($argv));
