<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\DeliverConsumerContactCode;
use App\Models\ConsumerChallenge;
use App\Models\ConsumerOtpDelivery;
use App\Services\CaseService;
use App\Services\FoundationClaims;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RecoverConsumerContactCodes extends Command
{
    protected $signature = 'consumer:recover-contact-codes {--limit=50}';

    protected $description = 'Recover durable contact-code deliveries without exposing their payloads';

    public function handle(FoundationClaims $claims, CaseService $cases): int
    {
        $limit = max(1, min(200, (int) $this->option('limit')));
        ConsumerChallenge::where('purpose', 'contact_verification')->where('method', 'verified_channel_otp')
            ->whereNull('consumed_at')->where('expires_at', '<=', now())
            ->update(['payload' => null, 'proof_hash' => null, 'revoked_at' => now()]);
        ConsumerOtpDelivery::whereIn('state', ['pending', 'processing'])->where('expires_at', '<=', now())
            ->update(['state' => 'expired', 'payload' => null, 'lease_token' => null, 'lease_expires_at' => null]);
        ConsumerOtpDelivery::whereIn('state', ['sent', 'expired', 'failed'])->whereNotNull('payload')->update(['payload' => null]);
        $exhausted = ConsumerOtpDelivery::whereIn('state', ['pending', 'processing'])->where('attempts', '>=', config('pull2spend.event_attempts'))
            ->where(fn ($query) => $query->whereNull('lease_expires_at')->orWhere('lease_expires_at', '<=', now()))->limit($limit)->get();
        foreach ($exhausted as $delivery) {
            DB::transaction(function () use ($delivery, $cases): void {
                $changed = ConsumerOtpDelivery::whereKey($delivery->id)->whereIn('state', ['pending', 'processing'])
                    ->where('attempts', '>=', config('pull2spend.event_attempts'))->where('lease_token', $delivery->lease_token)
                    ->where(fn ($query) => $query->whereNull('lease_expires_at')->orWhere('lease_expires_at', '<=', now()))
                    ->update(['state' => 'failed', 'payload' => null, 'lease_token' => null, 'lease_expires_at' => null]);
                if ($changed === 1) {
                    $cases->open('notification', $delivery->id);
                }
            });
        }
        foreach ($claims->due(new ConsumerOtpDelivery)->orderBy('available_at')->limit($limit)->pluck('id') as $id) {
            DeliverConsumerContactCode::dispatch($id);
        }
        $this->info('Contact delivery recovery dispatched.');

        return self::SUCCESS;
    }
}
