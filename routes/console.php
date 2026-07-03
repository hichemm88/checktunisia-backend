<?php

use Illuminate\Support\Facades\Schedule;

// Notify hotel admins about expiring subscriptions — runs daily at 8:00 AM
Schedule::command('subscriptions:notify-expiring')->dailyAt('08:00');
