<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('foundation:recover')->everyMinute()->withoutOverlapping(5);

Schedule::command('consumer:recover-contact-codes')->everyMinute()->withoutOverlapping();
