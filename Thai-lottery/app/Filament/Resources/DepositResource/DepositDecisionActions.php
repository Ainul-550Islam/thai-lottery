<?php

declare(strict_types=1);

namespace App\Filament\Resources\DepositResource;

use App\Enums\DepositStatus;
use App\Models\Deposit;
use App\Services\Finance\DepositApprovalService;
use App\Services\Finance\DepositCompletionService;
use App\Support\Admin\AdminAccess;
use App\Support\Admin\AdminFormat;
use App\Support\Admin\OperatorActionFactory;
use Filament\Forms;
use Filament\Notifications\Notification;

/**
 * The four decisions a deposit can need from a human.
 *
 * WHY APPROVAL AND COMPLETION ARE SEPARATE BUTTONS
 * The domain splits them deliberately and this screen must not paper over it. Approving a
 * deposit moves no money: it records that an operator believes the payment arrived.
 * Completing it is the moment the wallet is credited and a balanced double entry is posted.
 * Merging them into one "confirm" button would let a mis-click credit a wallet against a
 * payment nobody had actually verified, and would leave no state in which a wrong approval
 * could still be rejected. So the operator makes two decisions, and the second one says in
 * its own confirmation text that it credits real balance.
 *
 * Visibility is asked of the services themselves - canApprove/canReject/canComplete - so
 * the buttons on screen agree with what the domain would accept. The permission gate is
 * separate and stricter: reading a deposit needs 'view transaction history', but deciding
 * one needs 'manage payouts', which is why an auditor sees this screen and no buttons.
 *
 * WHAT IS DELIBERATELY NOT DONE
 * - No status is written here. Every branch calls DepositApprovalService or
 *   DepositCompletionService and reports what it said.
 * - No un-approve and no un-complete. Taking back a credited deposit is a reversing
 *   transaction (FinancialReversalService), not a status edit, and that flow is not
 *   exposed in this phase - reported as a gap instead of half-built.
 * - markProcessing has no canX() guard on the service, so its visibility replicates the
 *   service's own precondition (pending or approved, nothing credited). The service still
 *   re-checks under a row lock; this is only about which button to offer.
 */
final class DepositDecisionActions
{
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
                'name' => 'approve',
                'label' => 'Approve',
                'icon' => 'heroicon-o-check-circle',
                'color' => 'success',
                'heading' => 'Approve this deposit',
                'description' => 'Records that the payment was verified. NO money moves and no wallet is credited by this step — completion does that, as a separate decision.',
                'submitLabel' => 'Approve',
                'form' => [
                    Forms\Components\Textarea::make('note')
                        ->label('Note (optional, kept on the deposit)')
                        ->maxLength(500)
                        ->helperText('What did you check? A provider reference or statement line makes the next reconciliation cheap.'),
                ],
                'visible' => static fn (Deposit $record): bool => AdminAccess::current(AdminAccess::MANAGE_PAYOUTS)
                    && app(DepositApprovalService::class)->canApprove($record),
                'action' => static function (Deposit $record, array $data): void {
                    OperatorActionFactory::run(
                        static fn () => app(DepositApprovalService::class)->approve(
                            $record,
                            AdminAccess::operator()?->getKey() === null ? null : (int) AdminAccess::operator()->getKey(),
                            self::optionalString($data['note'] ?? null),
                        ),
                        $record->reference_number.' is approved. It is not credited yet.',
                    );
                },
            ],
            [
                'name' => 'reject',
                'label' => 'Reject',
                'icon' => 'heroicon-o-x-circle',
                'color' => 'danger',
                'heading' => 'Reject this deposit',
                'description' => 'The request is refused and no money ever moves. A deposit that has already been credited cannot be rejected — that would need a reversing transaction.',
                'submitLabel' => 'Reject this deposit',
                'form' => [
                    Forms\Components\Textarea::make('reason')
                        ->label('Reason (required, shown in the decision trail)')
                        ->required()
                        ->minLength(5)
                        ->maxLength(500),
                ],
                'visible' => static fn (Deposit $record): bool => AdminAccess::current(AdminAccess::MANAGE_PAYOUTS)
                    && app(DepositApprovalService::class)->canReject($record),
                'action' => static function (Deposit $record, array $data): void {
                    OperatorActionFactory::run(
                        static fn () => app(DepositApprovalService::class)->reject(
                            $record,
                            (string) $data['reason'],
                            AdminAccess::operator()?->getKey() === null ? null : (int) AdminAccess::operator()->getKey(),
                        ),
                        $record->reference_number.' is rejected.',
                    );
                },
            ],
            [
                'name' => 'markProcessing',
                'label' => 'Mark processing',
                'icon' => 'heroicon-o-arrow-path',
                'color' => 'warning',
                'confirm' => true,
                'heading' => 'Mark this deposit as being settled',
                'description' => 'Bookkeeping only: it makes a long-running settlement visible from the outside. No balance changes and completion still has to be run.',
                'visible' => static fn (Deposit $record): bool => AdminAccess::current(AdminAccess::MANAGE_PAYOUTS)
                    && $record->financial_transaction_id === null
                    && in_array($record->status, [DepositStatus::Pending, DepositStatus::Approved], true),
                'action' => static function (Deposit $record): void {
                    OperatorActionFactory::run(
                        static fn () => app(DepositApprovalService::class)->markProcessing($record),
                        $record->reference_number.' is marked processing.',
                    );
                },
            ],
            [
                'name' => 'complete',
                'label' => 'Complete — credit the wallet',
                'icon' => 'heroicon-o-banknotes',
                'color' => 'primary',
                'confirm' => true,
                'heading' => 'Credit this deposit to the wallet',
                'description' => 'This is the step that moves money: the wallet balance rises and a balanced double entry is posted to the ledger. It is idempotent, so a retry after a timeout cannot credit twice — but it cannot be undone from this panel either.',
                'submitLabel' => 'Credit the wallet',
                'visible' => static fn (Deposit $record): bool => AdminAccess::current(AdminAccess::MANAGE_PAYOUTS)
                    && app(DepositCompletionService::class)->canComplete($record),
                'action' => static function (Deposit $record): void {
                    OperatorActionFactory::run(static function () use ($record): void {
                        $outcome = app(DepositCompletionService::class)->complete($record, null, [
                            'description' => 'Deposit credited from the admin panel',
                            'metadata' => ['completed_by' => AdminAccess::operator()?->getKey()],
                        ]);

                        $replayed = ($outcome['replayed'] ?? false) === true;

                        Notification::make()
                            ->success()
                            ->title($replayed ? 'Already credited — nothing was written' : 'Deposit credited')
                            ->body(sprintf(
                                '%s: %s. Transaction %s.',
                                $record->reference_number,
                                AdminFormat::money($outcome['amount'] ?? null, $record->currency->value),
                                (string) ($outcome['transaction']->reference_number ?? '—'),
                            ))
                            ->send();
                    });
                },
            ],
        ];
    }

    private static function optionalString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        return trim($value) === '' ? null : $value;
    }
}
