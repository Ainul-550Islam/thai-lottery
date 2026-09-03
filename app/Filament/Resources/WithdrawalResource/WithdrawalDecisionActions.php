<?php

declare(strict_types=1);

namespace App\Filament\Resources\WithdrawalResource;

use App\Enums\WithdrawalStatus;
use App\Models\Withdrawal;
use App\Services\Finance\WithdrawalApprovalService;
use App\Services\Finance\WithdrawalCompletionService;
use App\Support\Admin\AdminAccess;
use App\Support\Admin\AdminFormat;
use App\Support\Admin\OperatorActionFactory;
use Filament\Forms;
use Filament\Notifications\Notification;

/**
 * The five decisions a withdrawal can need from a human.
 *
 * WHY THIS IS THE MOST CAREFUL SCREEN IN THE PANEL
 * A withdrawal is the only request in the system where money leaves the platform, and each
 * of its transitions means something different to the player's balance:
 *
 *   approve        reserves the amount (available balance falls, total does not)
 *   markProcessing hands it to the payout channel, reservation must still be intact
 *   complete       debits the wallet and posts the double entry — the money is gone
 *   reject         releases any reservation, nothing ever left
 *   markFailed     the payout attempt failed; the reservation goes back to available
 *
 * None of that arithmetic happens here. Every button calls WithdrawalApprovalService or
 * WithdrawalCompletionService, whose refusal is shown verbatim, so the panel cannot invent
 * a state in which reserved funds are not accounted for.
 *
 * WHAT IS DELIBERATELY NOT DONE
 * - No un-complete. Once the debit is posted, reversing it is FinancialReversalService's
 *   job and is not exposed in this phase.
 * - No "approve and pay" combination. Approving is a review decision; paying is a treasury
 *   action, and collapsing them removes the window in which a reviewer can still change
 *   their mind.
 * - markProcessing's visibility mirrors the service's own precondition (Approved only)
 *   because the service exposes no canMarkProcessing(); the service still re-checks under
 *   the wallet row lock, including that the reservation is intact.
 */
final class WithdrawalDecisionActions
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
                'heading' => 'Approve this withdrawal',
                'description' => 'Reserves the amount inside the wallet: the available balance falls immediately so the player cannot spend money that is on its way out. The total balance does not change and no ledger entry is written — that happens at completion.',
                'submitLabel' => 'Approve and reserve the funds',
                'form' => [
                    Forms\Components\Textarea::make('note')
                        ->label('Note (optional, kept on the withdrawal)')
                        ->maxLength(500),
                ],
                'visible' => static fn (Withdrawal $record): bool => AdminAccess::current(AdminAccess::MANAGE_PAYOUTS)
                    && app(WithdrawalApprovalService::class)->canApprove($record),
                'action' => static function (Withdrawal $record, array $data): void {
                    OperatorActionFactory::run(static function () use ($record, $data): void {
                        $outcome = app(WithdrawalApprovalService::class)->approve(
                            $record,
                            self::operatorId(),
                            self::optionalString($data['note'] ?? null),
                        );

                        Notification::make()
                            ->success()
                            ->title('Withdrawal approved')
                            ->body(sprintf(
                                '%s: %s reserved. Nothing has left the platform yet.',
                                $record->reference_number,
                                AdminFormat::money($outcome['reserved'] ?? null, $record->currency->value),
                            ))
                            ->send();
                    });
                },
            ],
            [
                'name' => 'reject',
                'label' => 'Reject',
                'icon' => 'heroicon-o-x-circle',
                'color' => 'danger',
                'heading' => 'Reject this withdrawal',
                'description' => 'Refuses the request and gives any reservation back to the available balance. The release is idempotent, so a repeated rejection cannot release the amount twice.',
                'submitLabel' => 'Reject this withdrawal',
                'form' => [
                    Forms\Components\Textarea::make('reason')
                        ->label('Reason (required, stored on the withdrawal and shown to the player)')
                        ->required()
                        ->minLength(5)
                        ->maxLength(500),
                ],
                'visible' => static fn (Withdrawal $record): bool => AdminAccess::current(AdminAccess::MANAGE_PAYOUTS)
                    && app(WithdrawalApprovalService::class)->canReject($record),
                'action' => static function (Withdrawal $record, array $data): void {
                    OperatorActionFactory::run(
                        static fn () => app(WithdrawalApprovalService::class)->reject(
                            $record,
                            (string) $data['reason'],
                            self::operatorId(),
                        ),
                        $record->reference_number.' is rejected and any reservation is released.',
                    );
                },
            ],
            [
                'name' => 'markProcessing',
                'label' => 'Mark processing',
                'icon' => 'heroicon-o-arrow-path',
                'color' => 'warning',
                'confirm' => true,
                'heading' => 'Hand this withdrawal to the payout channel',
                'description' => 'Records that a payout attempt is under way. The service refuses unless the reservation taken at approval is still intact, so this can never dispatch a payout against money that is no longer set aside.',
                'visible' => static fn (Withdrawal $record): bool => AdminAccess::current(AdminAccess::MANAGE_PAYOUTS)
                    && $record->status === WithdrawalStatus::Approved,
                'action' => static function (Withdrawal $record): void {
                    OperatorActionFactory::run(
                        static fn () => app(WithdrawalApprovalService::class)->markProcessing($record),
                        $record->reference_number.' is marked processing.',
                    );
                },
            ],
            [
                'name' => 'complete',
                'label' => 'Complete — debit the wallet',
                'icon' => 'heroicon-o-banknotes',
                'color' => 'primary',
                'confirm' => true,
                'heading' => 'Settle this payout',
                'description' => 'This is the step where money leaves: the reservation is consumed, the wallet is debited and a balanced double entry is posted. It is idempotent against a retry, but it cannot be undone from this panel.',
                'submitLabel' => 'Debit the wallet',
                'visible' => static fn (Withdrawal $record): bool => AdminAccess::current(AdminAccess::MANAGE_PAYOUTS)
                    && app(WithdrawalCompletionService::class)->canComplete($record),
                'action' => static function (Withdrawal $record): void {
                    OperatorActionFactory::run(static function () use ($record): void {
                        $outcome = app(WithdrawalCompletionService::class)->complete($record, null, [
                            'description' => 'Withdrawal settled from the admin panel',
                            'metadata' => ['completed_by' => AdminAccess::operator()?->getKey()],
                        ]);

                        $replayed = ($outcome['replayed'] ?? false) === true;

                        Notification::make()
                            ->success()
                            ->title($replayed ? 'Already settled — nothing was written' : 'Payout settled')
                            ->body(sprintf(
                                '%s: %s debited. Transaction %s.',
                                $record->reference_number,
                                AdminFormat::money($outcome['amount'] ?? null, $record->currency->value),
                                (string) ($outcome['transaction']->reference_number ?? '—'),
                            ))
                            ->send();
                    });
                },
            ],
            [
                'name' => 'markFailed',
                'label' => 'Mark failed',
                'icon' => 'heroicon-o-exclamation-triangle',
                'color' => 'danger',
                'heading' => 'Record a failed payout attempt',
                'description' => 'The payout channel could not pay. The money never left, so the reservation goes back to the available balance and no ledger entry is written or removed.',
                'submitLabel' => 'Record the failure',
                'form' => [
                    Forms\Components\Textarea::make('reason')
                        ->label('What did the payout channel say? (required)')
                        ->required()
                        ->minLength(5)
                        ->maxLength(500),
                ],
                'visible' => static fn (Withdrawal $record): bool => AdminAccess::current(AdminAccess::MANAGE_PAYOUTS)
                    && $record->status->canTransitionTo(WithdrawalStatus::Failed),
                'action' => static function (Withdrawal $record, array $data): void {
                    OperatorActionFactory::run(
                        static fn () => app(WithdrawalApprovalService::class)->markFailed($record, (string) $data['reason']),
                        $record->reference_number.' is marked failed and any reservation is released.',
                    );
                },
            ],
        ];
    }

    private static function operatorId(): ?int
    {
        $key = AdminAccess::operator()?->getKey();

        return $key === null ? null : (int) $key;
    }

    private static function optionalString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        return trim($value) === '' ? null : $value;
    }
}
