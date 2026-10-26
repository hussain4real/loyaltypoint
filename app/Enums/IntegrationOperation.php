<?php

declare(strict_types=1);

namespace App\Enums;

enum IntegrationOperation: string
{
    case Authorize = 'authorize';
    case Revoke = 'revoke';
    case ReadBalance = 'read_balance';
    case RequestOffer = 'request_offer';
    case ConfirmTerms = 'confirm_terms';
    case Reserve = 'reserve';
    case ConfirmDebit = 'confirm_debit';
    case Release = 'release';
    case Reverse = 'reverse';
    case OperationStatus = 'operation_status';
    case Enroll = 'enroll';
    case Issue = 'issue';
    case Reveal = 'reveal';
    case CardState = 'card_state';
    case Provision = 'provision';
    case Fund = 'fund';
    case IssuerBalance = 'issuer_balance';
    case CardEvent = 'card_event';
    case Dispute = 'dispute';
    case DailyFeed = 'daily_feed';
    case SubmitScreening = 'submit_screening';
    case ScreeningStatus = 'screening_status';
    case Reverify = 'reverify';
    case DeliverSettlement = 'deliver_settlement';
    case SettlementAcknowledgement = 'settlement_acknowledgement';
    case PaymentEvidence = 'payment_evidence';
}
