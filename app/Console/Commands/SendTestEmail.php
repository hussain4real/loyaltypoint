<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

class SendTestEmail extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:send-test-email {--to=aminuhussain22@gmail.com : The email address to send to}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send a test email to verify email configuration';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $to = $this->option('to');

        $this->info("Sending test email to: {$to}");

        try {
            Mail::raw('This is a test email from your Laravel application. If you received this, your email configuration is working correctly!', function ($message) use ($to) {
                $message->to($to)
                    ->subject('Test Email from Laravel');
            });

            $this->info('✅ Test email sent successfully!');

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error('❌ Failed to send test email: '.$e->getMessage());

            return self::FAILURE;
        }
    }
}
