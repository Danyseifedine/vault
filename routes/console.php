<?php

use Illuminate\Support\Facades\Schedule;

/*
 * The audit chain's out-of-band heartbeat. Anchoring copies the newest hash
 * OUTSIDE the database, so a rewrite of history has to beat the file too;
 * verification walks the whole chain and fails loudly on any break. Daily,
 * because an anchor nobody refreshes protects exactly one day of history.
 */
Schedule::command('vault:anchor-audit-chain')->dailyAt('03:00');
Schedule::command('vault:verify-audit-chain')->dailyAt('03:10');
