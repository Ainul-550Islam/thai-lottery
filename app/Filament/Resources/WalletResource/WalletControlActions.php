<?php

declare(strict_types=1);

namespace App\Filament\Resources\WalletResource;

use App\Enums\FinancialTransactionType;
use App\Enums\WalletStatus;
use App\Models\Wallet;
use App\Services\Finance\Money;
use App\Services\Finance\WalletService;
use App\Support\Admin\AdminAccess;
use App\Support\Admin\AdminFormat;
use App\Support\Admin\OperatorActionFactory;
use Filament\Forms;
use Filament\Notifications\Notification;

/**
 * Locking, unlocking and the one manual balance correction the panel allows.
 *
 * WHY THE ADJUSTMENT AMOUNT IS VALIDATED WITH A REGEX AND NOT `numeric`
 * `numeric` accepts "1e3", " 12 ", "0x10" and "1.005", and Filament would hand the value
 * on as a float. Money in this application is a bcmath decimal string end to end: the
 * moment an amount becomes a float, 0.1 + 0.2 stops being 0.3 and the ledger acquires a
 * rounding artefact that no reconciliation can explain. So the field accepts exactly
 * digits with at most two decimal places, as a string, and hands that string to
 * Money::of() untouched. There is no cast, no round() and no number_format() on this path.
 *
 * WHY AN ADJUSTMENT IS A CREDIT OR DEBIT AND NOT A BALANCE EDIT
 * WalletService::credit/debit post a balanced double entry against the adjustment-equity
 * account, so a manual correction is visible in the ledger as a correction, attributable to
 * the operator who made it, and reversible by a further posting. Writing wallets.balance
 * directly would move money that no ledger entry explains — the exact defect an auditor
 * looks for first. The reason field is required for the same purpose: an adjustment with no
 * stated cause is indistinguishable from theft after the fact.
 *
 * WHAT IS DELIBERATELY NOT DONE
 * - No lockFunds/unlockFunds (partial reservations). Those belong to the withdrawal and bet
 *   flows that create the holds; a human moving a reservation by hand would desynchronise
 *   it from the request that owns it.
 * - No wallet creation, closure or currency change. Wallets are provisioned with the user.
 * - No reversal of an adjustment. FinancialReversalService owns reversals and is not
 *   exposed in this phase; a mistaken adjustment is corrected by the opposite adjustment,
 *   which is what the reason field is there to record.
 */
final class WalletControlActions
{
    /**
     * Digits, optionally with one or two decimal places. Nothing else.
     */
    public const AMOUNT_PATTERN = '/^\d{1,15}(\.\d{1,2})?$/';

    /**
     * @return list<\Filament\Tables\Actions\Action>
     */
    public static function forTable(): array
    {
        return OperatorActionFactory::forTable(self::specs());
    }

    /**
     * @return list<\Filament\Actions\Action>
     */
    public static function forPage(): array
    {
        return OperatorActionFactory::forPage(self::specs());
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function specs(): array
    {
        return [
            [
                'name' => 'lockWallet',
                'label' => 'Lock wallet',
                'icon' => 'heroicon-o-lock-closed',
                'color' => 'danger',
                'heading' => 'Lock this wallet',
                'description' => 'A locked wallet accepts no credits and no debits: deposits, withdrawals, bets and payouts against it are all refused until it is unlocked. Existing balances are untouched.',
                'submitLabel' => 'Lock the wallet',
                'form' => [
                    Forms\Components\Textarea::make('reason')
                        ->label('Reason (required — WalletService refuses a lock without one)')
                        ->required()
                        ->minLength(5)
                        ->maxLength(500)
                        ->helperText('Stored on the wallet as locked_reason, so the next operator knows why it is frozen.'),
                ],
                'visible' => static fn (Wallet $record): bool => AdminAccess::current(AdminAccess::MANAGE_WALLET)
                    && ! in_array($record->status, [WalletStatus::Locked, WalletStatus::Closed], true),
                'action' => static function (Wallet $record, array $data): void {
                    OperatorActionFactory::run(
                        static fn () => app(WalletService::class)->lockWallet($record, (string) $data['reason']),
                        'Wallet #'.$record->getKey().' is locked.',
                    );
                },
            ],
            [
                'name' => 'unlockWallet',
                'label' => 'Unlock wallet',
                'icon' => 'heroicon-o-lock-open',
                'color' => 'success',
                'confirm' => true,
                'heading' => 'Return this wallet to active use',
                'description' => 'Money movement is allowed again immediately. Only a wallet in the locked state can be unlocked — a frozen or suspended wallet is a different decision and the service will say so.',
                'visible' => static fn (Wallet $record): bool => AdminAccess::current(AdminAccess::MANAGE_WALLET)
                    && $record->status === WalletStatus::Locked,
                'action' => static function (Wallet $record): void {
                    OperatorActionFactory::run(
                        static fn () => app(WalletService::class)->unlockWallet($record),
                        'Wallet #'.$record->getKey().' is active again.',
                    );
                },
            ],
            [
                'name' => 'adjustBalance',
                'label' => 'Adjust balance',
                'icon' => 'heroicon-o-scale',
                'color' => 'warning',
                'heading' => 'Post a manual adjustment',
                'description' => 'Moves money into or out of this wallet through WalletService, posting a balanced double entry against the adjustment-equity account. This is real money in a real ledger: it is not a display correction.',
                'submitLabel' => 'Post the adjustment',
                'form' => [
                    Forms\Components\Select::make('direction')
                        ->label('Direction')
                        ->options([
                            'credit' => 'Credit — increase the balance',
                            'debit' => 'Debit — decrease the balance',
                        ])
                        ->required()
                        ->native(false),

                    Forms\Components\TextInput::make('amount')
                        ->label('Amount')
                        ->required()
                        ->rule('regex:'.self::AMOUNT_PATTERN)
                        ->validationMessages([
                            'regex' => 'Enter a plain decimal amount: digits, optionally with up to two decimal places (for example 1500 or 1500.25).',
                        ])
                        ->helperText('Kept as a string and handed to Money::of() exactly as typed. No float conversion happens anywhere on this path.'),

                    Forms\Components\Textarea::make('reason')
                        ->label('Reason (required — recorded on the transaction and the ledger entries)')
                        ->required()
                        ->minLength(5)
                        ->maxLength(500),
                ],
                'visible' => static fn (Wallet $record): bool => AdminAccess::current(AdminAccess::MANAGE_WALLET)
                    && $record->status !== WalletStatus::Closed,
                'action' => static function (Wallet $record, array $data): void {
                    OperatorActionFactory::run(static function () use ($record, $data): void {
                        $service = app(WalletService::class);
                        $amount = Money::of((string) $data['amount'], $record->currency);
                        $reason = (string) $data['reason'];

                        $options = [
                            'description' => 'Manual adjustment: '.$reason,
                            'metadata' => [
                                'adjustment_reason' => $reason,
                                'adjusted_by' => AdminAccess::operator()?->getKey(),
                                'source' => 'admin-panel',
                            ],
                            'actor_user_id' => AdminAccess::operator()?->getKey(),
                        ];

                        $transaction = $data['direction'] === 'credit'
                            ? $service->credit($record, $amount, FinancialTransactionType::Adjustment, null, $options)
                            : $service->debit($record, $amount, FinancialTransactionType::Adjustment, null, $options);

                        Notification::make()
                            ->success()
                            ->title('Adjustment posted')
                            ->body(sprintf(
                                'Wallet #%d %sed %s. Transaction %s. Available balance is now %s.',
                                (int) $record->getKey(),
                                (string) $data['direction'],
                                AdminFormat::money($amount->amount(), $record->currency->value),
                                (string) $transaction->reference_number,
                                AdminFormat::money(
                                    $service->availableBalance($record->fresh())->amount(),
                                    $record->currency->value,
                                ),
                            ))
                            ->send();
                    });
                },
            ],
        ];
    }
}
