<?php

use Illuminate\Support\Facades\Schedule;

// Notify hotel admins about expiring subscriptions — runs daily at 8:00 AM
Schedule::command('subscriptions:notify-expiring')->dailyAt('08:00');

// Sync watchlist with OpenSanctions (Interpol Red Notices + UN Sanctions) — runs daily at 02:00 AM
// OpenSanctions refreshes their data daily; running at 02:00 ensures we pick up overnight updates.
Schedule::command('watchlist:sync-opensanctions')->dailyAt('02:00');
