<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The CHANNEL a prize reaches its winner through.
 *
 * GLO-grounded payout channels (Government Lottery Office parity):
 * small wins are credited instantly to the player's digital wallet here,
 * while large wins follow the traditional remittance lanes — bank transfer to
 * the player's account (the digital analogue of Krung Thai Bank's prize
 * counters) and paper cheque for the largest traditional payouts.
 *
 * RULES LIVE WITH THE METHOD
 * --------------------------
 * Each method owns its own semantics: which currency it settles, whether it
 * reaches the player instantly (wallet) or after operations processing
 * (transfer/cheque), whether it needs verified KYC before release, and its
 * handling fee statement for players. A dispatcher chooses the method; this
 * enum only DESCRIBES lawful behaviour — it never moves money.
 */
enum PrizePayoutMethod: string
{
    case Wallet = 'wallet';
    case BankTransfer = 'bank_transfer';
    case Cheque = 'cheque';

    public function label(): string
    {
        return match ($this) {
            self::Wallet => 'Digital Wallet Credit',
            self::BankTransfer => 'Bank Transfer',
            self::Cheque => 'Cheque',
        };
    }

    /**
     * Whether funds land in the player's balance immediately upon settlement
     * (wallet) or await operations processing (bank/cheque lanes with manual
     * disbursement).
     */
    public function isInstant(): bool
    {
        return $this === self::Wallet;
    }

    /**
     * Whether the channel runs on physical/processing paperwork: bank
     * transfers and cheques enter the operations queue; wallet never does.
     */
    public function requiresOperationsProcessing(): bool
    {
        return match ($this) {
            self::Wallet => false,
            self::BankTransfer, self::Cheque => true,
        };
    }

    /**
     * Whether verified KYC is a hard precondition for payout on this
     * channel. Cross-border banking rails and paper instruments travel only
     * after verified identity; wallet can defer it for small amounts.
     */
    public function requiresVerifiedKyc(): bool
    {
        return match ($this) {
            self::Wallet => false,
            self::BankTransfer, self::Cheque => true,
        };
    }

    /**
     * Whether this channel is available for a currency. Bank/cheque settle in
     * THB here (the local bank rails this platform serves); wallet carries
     * whatever the wallet is denominated in.
     */
    public function supportsCurrency(Currency $currency): bool
    {
        return match ($this) {
            self::Wallet => true,
            self::BankTransfer, self::Cheque => $currency === Currency::THB,
        };
    }

    /**
     * The processing SLA a player is told to expect.
     */
    public function slaDescription(): string
    {
        return match ($this) {
            self::Wallet => 'Instant',
            self::BankTransfer => 'Within 1-3 business days',
            self::Cheque => 'Within 5-10 business days after printing',
        };
    }

    /**
     * Badge color for admin surfaces, in Filament vocabulary.
     */
    public function color(): string
    {
        return match ($this) {
            self::Wallet => 'green',
            self::BankTransfer => 'blue',
            self::Cheque => 'gray',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
