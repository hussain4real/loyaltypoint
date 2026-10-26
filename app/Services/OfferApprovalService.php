<?php

declare(strict_types=1);

namespace App\Services;

use Brick\Math\BigDecimal;
use DateTimeImmutable;
use DomainException;

/** Local readiness checks only; no wallet posting, partner debit, FX or funding authority. */
final class OfferApprovalService
{
    public function approve(
        PartnerConnectionContext $currentConnection,
        ConfirmedPartnerOffer $confirmedOffer,
        DateTimeImmutable $now,
    ): ConsumerOfferApproval {
        $this->assertCurrentTerms($currentConnection, $confirmedOffer, $now);

        return new ConsumerOfferApproval($confirmedOffer, $now);
    }

    /** Recheck with current authenticated ownership/eligibility and authoritative adapter evidence. */
    public function assertReady(
        PartnerConnectionContext $currentConnection,
        ConsumerOfferApproval $approval,
        ConfirmedPartnerOffer $currentOffer,
        DateTimeImmutable $now,
    ): void {
        $this->assertCurrentTerms($currentConnection, $currentOffer, $now);
        $approved = $approval->confirmedOffer;

        if (! $currentConnection->hasSameOwnerAndConnectionAs($approved->connection)) {
            throw new DomainException('ownership_mismatch');
        }

        if ($approval->approvedAt > $now || $approval->approvedAt < $approved->confirmedAt) {
            throw new DomainException('approval_invalid');
        }

        $this->assertCurrentTerms($currentConnection, $approved, $now);
        $this->assertCurrentTerms($currentConnection, $approved, $approval->approvedAt);

        if (! $approved->offer->hasSameEconomicTermsAs($currentOffer->offer)
            || $approved->offer->quoteReference !== $currentOffer->offer->quoteReference
            || $approved->offer->transactionReference !== $currentOffer->offer->transactionReference
            || $approved->offer->validFrom != $currentOffer->offer->validFrom
            || $approved->offer->validUntil != $currentOffer->offer->validUntil) {
            throw new DomainException('value_changed_reapproval_required');
        }
    }

    private function assertCurrentTerms(
        PartnerConnectionContext $currentConnection,
        ConfirmedPartnerOffer $confirmation,
        DateTimeImmutable $now,
    ): void {
        if (! $currentConnection->hasSameOwnerAndConnectionAs($confirmation->connection)) {
            throw new DomainException('ownership_mismatch');
        }

        if (! $currentConnection->active) {
            throw new DomainException('connection_revoked');
        }

        if (! $currentConnection->eligible) {
            throw new DomainException('eligibility_required');
        }

        if (BigDecimal::of($confirmation->offer->amount)->isZero()) {
            throw new DomainException('value_unavailable');
        }

        if ($currentConnection->walletCurrency === null) {
            throw new DomainException('currency_policy_required');
        }

        if ($currentConnection->walletCurrency !== $confirmation->offer->currency) {
            throw new DomainException('currency_mismatch');
        }

        if ($confirmation->offer->observedAt > $now || $confirmation->confirmedAt > $now) {
            throw new DomainException('offer_unconfirmed');
        }

        if (! $confirmation->offer->isWithinSuppliedValidityAt($now)
            || ($confirmation->confirmationValidUntil !== null && $now >= $confirmation->confirmationValidUntil)) {
            throw new DomainException('offer_expired');
        }
    }
}
