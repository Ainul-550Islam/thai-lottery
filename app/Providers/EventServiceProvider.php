<?php

namespace App\Providers;

use App\Events\ComplianceCaseEscalated;
use App\Events\DrawResultCertified;
use App\Events\FinancialLedgerReconciled;
use App\Events\MfaChallengeVerified;
use App\Events\NotificationDelivered;
use App\Events\NotificationDeliveryFailed;
use App\Events\AdminOperationCompleted;
use App\Events\ProviderOperationalStateChanged;
use App\Events\PaymentTransactionReconciled;
use App\Events\PayoutBatchCompleted;
use App\Events\PlayerProtectionActionApplied;
use App\Events\PrizeClaimApproved;
use App\Events\PrizeMatched;
use App\Events\RetailTicketAllocated;
use App\Events\SelfExclusionActivated;
use App\Events\SuspiciousAuthenticationDetected;
use App\Events\WithdrawalKycApproved;
use App\Listeners\RecordComplianceActionAudit;
use App\Listeners\RecordDrawCertificationAudit;
use App\Listeners\RecordFinancialReconciliationAudit;
use App\Listeners\RecordMfaVerificationAudit;
use App\Listeners\RecordNotificationAudit;
use App\Listeners\RecordAdminOperationAudit;
use App\Listeners\RecordProviderOperationAudit;
use App\Listeners\RecordPaymentReconciliationAudit;
use App\Listeners\RecordPayoutBatchAudit;
use App\Listeners\RecordPlayerProtectionActionAudit;
use App\Listeners\RecordPrizeClaimAudit;
use App\Listeners\RecordPrizeDisbursementAudit;
use App\Listeners\RecordRetailTicketAllocationAudit;
use App\Listeners\RecordSecurityEventAudit;
use App\Listeners\RecordSelfExclusionAudit;
use App\Listeners\RecordWithdrawalKycAudit;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

class EventServiceProvider extends ServiceProvider
{
    /**
     * Event to listener mappings.
     *
     * NOTE: Finance, lottery and payment events/listeners are registered here
     * as they are implemented in their respective phases.
     *
     * @var array<class-string, array<int, class-string>>
     */
    protected $listen = [
        // Prize claim lane: every approval (auto or human) lands one audit
        // row. Discovery is disabled below — anything unlisted here never
        // fires a listener.
        PrizeClaimApproved::class => [
            RecordPrizeClaimAudit::class,
        ],
        // Payout batch lane: the one-time completion of a batch lands one
        // scrubbed, anchor-deduplicated audit row.
        PayoutBatchCompleted::class => [
            RecordPayoutBatchAudit::class,
        ],
        // Money-out KYC gate: every pass (direct or detention-lifted)
        // lands one scrubbed, anchor-deduplicated audit row.
        WithdrawalKycApproved::class => [
            RecordWithdrawalKycAudit::class,
        ],
        // Retail allocation lane: every vendor allocation completion lands
        // exactly one scrubbed, anchor-deduplicated audit row.
        RetailTicketAllocated::class => [
            RecordRetailTicketAllocationAudit::class,
        ],
        // Draw certification lane: every certification act lands exactly
        // one immutable, anchor-deduplicated audit row.
        DrawResultCertified::class => [
            RecordDrawCertificationAudit::class,
        ],
        // Prize-money lane: every match fires one immutable audit row;
        // settlement acts (reservation/disbursement/reversal/failure)
        // ALSO land here, driven synchronously from the settlement court
        // itself via the same scribe's `from()` — money evidence never
        // rides a fire-and-forget queue.
        PrizeMatched::class => [
            RecordPrizeDisbursementAudit::class,
        ],
        // Wallet-liability reconciliation lane (batch-11): every
        // pronouncement/refresh of a reconciliation row (matched or
        // drift) lands one fingerprint-anchored, deduplicated audit
        // row. The service ALSO fires it synchronously inside the same
        // transaction, so evidence never rides a fire-and-forget queue.
        FinancialLedgerReconciled::class => [
            RecordFinancialReconciliationAudit::class,
        ],
        // Payment provider reconciliation lane (batch-12): every
        // pronouncement (matched or drift) lands one fingerprint-
        // anchored, deduplicated audit row. The service also fires the
        // event inside its own transaction.
        PaymentTransactionReconciled::class => [
            RecordPaymentReconciliationAudit::class,
        ],
        // Compliance escalation lane (batch-13): every escalation
        // pronouncement lands one fingerprint-anchored audit row;
        // individual compliance ACTIONS are recorded synchronously by
        // the action service's own static scribe (same transaction).
        ComplianceCaseEscalated::class => [
            RecordComplianceActionAudit::class,
        ],
        // Responsible-gaming lane (batch-14): activation + applied-act
        // envelopes land one anchor-deduplicated audit row each; the
        // services' own static scribes stamp in-transaction alongside.
        SelfExclusionActivated::class => [
            RecordSelfExclusionAudit::class,
        ],
        PlayerProtectionActionApplied::class => [
            RecordPlayerProtectionActionAudit::class,
        ],
        // Account-security lane (batch-15): verification + detection
        // envelopes each land one anchor-deduplicated audit row.
        MfaChallengeVerified::class => [
            RecordMfaVerificationAudit::class,
        ],
        SuspiciousAuthenticationDetected::class => [
            RecordSecurityEventAudit::class,
        ],
        // Notification lane (batch-16): the service's own scribe already
        // stamps the notification row; these envelopes seal the moment.
        NotificationDelivered::class => [
            RecordNotificationAudit::class,
        ],
        NotificationDeliveryFailed::class => [
            RecordNotificationAudit::class,
        ],
        // Admin / provider operations lane (batch-17).
        AdminOperationCompleted::class => [
            RecordAdminOperationAudit::class,
        ],
        ProviderOperationalStateChanged::class => [
            RecordProviderOperationAudit::class,
        ],
    ];

    public function shouldDiscoverEvents(): bool
    {
        return false;
    }
}
