<?php

namespace App\Providers;

use App\Services\ConsumerVerificationGateway;
use App\Services\LocalConsumerVerificationGateway;
use App\Services\NotificationGateway;
use App\Services\SimulatedNotificationGateway;
use App\Services\UnavailableConsumerVerificationGateway;
use App\Services\UnavailableNotificationGateway;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(ConsumerVerificationGateway::class, function (): ConsumerVerificationGateway {
            return $this->app->environment('local', 'testing') && config('pull2spend.consumer.verification_mode') === 'fixture'
                ? new LocalConsumerVerificationGateway : new UnavailableConsumerVerificationGateway;
        });
        $this->app->bind(NotificationGateway::class, function (): NotificationGateway {
            return $this->app->environment('local', 'testing') && config('pull2spend.simulators_enabled')
                ? new SimulatedNotificationGateway : new UnavailableNotificationGateway;
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
