<?php

use Illuminate\Support\Facades\Schedule;

// Notify hotel admins about expiring subscriptions — runs daily at 8:00 AM
Schedule::command('subscriptions:notify-expiring')->dailyAt('08:00');

// Sync watchlist with OpenSanctions (Interpol Red Notices + UN Sanctions) — runs daily at 02:00 AM
// OpenSanctions refreshes their data daily; running at 02:00 ensures we pick up overnight updates.
Schedule::command('watchlist:sync-opensanctions')->dailyAt('02:00');

// Auto-expire subscriptions past their expiry date, blocking check-ins until renewed
Schedule::command('subscriptions:expire-overdue')->dailyAt('03:00');

// Notify managers of check-ins left unvalidated for >30 min (scan done, not finalised)
Schedule::command('checkins:notify-pending')->everyTenMinutes()->withoutOverlapping();

// Remind staff about active stays due to depart today with no check-out — 14:00 Tunis (§8)
Schedule::command('checkins:notify-departures-due')->dailyAt('14:00')->timezone('Africa/Tunis');
