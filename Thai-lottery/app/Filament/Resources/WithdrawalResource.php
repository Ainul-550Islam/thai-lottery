<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\Currency;
use App\Enums\PaymentMethod;
use App\Enums\WithdrawalApprovalStatus;
use App\Enums\WithdrawalStatus;
use App\Filament\Resources\WithdrawalResource\Pages;
use App\Filament\Resources\WithdrawalResource\WithdrawalDecisionActions;
use App\Models\Withdrawal;
use App\Services\Finance\WithdrawalApprovalService;
use App\Services\Finance\WithdrawalCompletionService;
use App\Support\Admin\AdminAccess;
use App\Support\Admin\AdminFormat;
use Filament\Forms\Form;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Money leaving: the payout review queue.
 *
 * WHY THE CLASS NAME AND ROUTES ARE FIXED
 * PendingApprovalsWidget on the dashboard links every waiting withdrawal straight into
 * this resource's 'view' route by class name. Renaming the class or dropping either page
 * registration breaks the dashboard, not just this screen, so both are load-bearing.
 *
 * WHY THERE IS NO FORM AND NO DELETE
 * A withdrawal request is the player's instruction plus an encrypted beneficiary payload.
 * The panel has no business rewriting either: an operator who could edit the amount could
 * pay a different sum than the one the player authorised, and an operator who could edit
 * payout_details could redirect a payment. Both are exactly the abuse an approval queue
 * exists to prevent. So the screen offers the five domain transitions and nothing else,
 * and payout_details is never rendered on it at all.
 *
 * The permission split is the same as deposits: reading needs 'view transaction history'
 * (so an auditor can reconcile), deciding needs 'manage payouts'.
 *
 * WHAT IS DELIBERATELY NOT DONE
 * - payout_details is not displayed. It decrypts to a bank account number, and nothing on
 *   this screen needs it to make a decision; the payout channel reads it, humans do not.
 * - No reversal of a completed payout — FinancialReversalService owns that and it is not
 *   exposed in this phase.
 * - No bulk approval, for the same reason as deposits.
 */
class WithdrawalResource extends Resource
{
    protected static ?string $model = Withdrawal::class;

    protected static ?string $navigationIcon = 'heroicon-o-arrow-up-on-square';

    protected static ?string $navigationGroup = 'Finance';

    protected static ?int $navigationSort = 11;

    protected static ?string $recordTitleAttribute = 'reference_number';

    public static function canViewAny(): bool
    {
        return AdminAccess::currentAny([
            AdminAccess::VIEW_TRANSACTION_HISTORY,
            AdminAccess::MANAGE_PAYOUTS,
        ]);
    }

    public static function canView(Model $record): bool
    {
        return static::canViewAny();
    }

    public static function canCreate(): bool
    {
        // A withdrawal is a player instruction. An operator who could create one could pay
        // themselves.
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function getNavigationBadge(): ?string
    {
        $waiting = Withdrawal::query()
            ->whereIn('status', [WithdrawalStatus::Pending, WithdrawalStatus::UnderReview])
            ->count();

        return $waiting > 0 ? (string) $waiting : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Withdrawals waiting for a decision';
    }

    public static function form(Form $form): Form
    {
        // Intentionally empty: see the class comment. Filament requires the method.
        return $form->schema([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['user', 'wallet']))
            ->columns([
                Tables\Columns\TextColumn::make('reference_number')
                    ->label('Reference')
                    ->searchable()
                    ->sortable()
                    ->copyable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('user.username')
                    ->label('Player')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('method')
                    ->badge()
                    ->formatStateUsing(fn (PaymentMethod $state): string => $state->label()),

                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (WithdrawalStatus $state): string => $state->label())
                    ->color(fn (WithdrawalStatus $state): string => $state->color())
                    ->sortable(),

                Tables\Columns\TextColumn::make('review_outcome')
                    ->label('Review')
                    ->badge()
                    ->state(fn (Withdrawal $record): string => app(WithdrawalApprovalService::class)->approvalStatus($record)->label())
                    ->color(fn (Withdrawal $record): string => app(WithdrawalApprovalService::class)->approvalStatus($record)->color())
                    ->toggleable(),

                Tables\Columns\TextColumn::make('amount')
                    ->label('Amount')
                    ->formatStateUsing(fn ($state, Withdrawal $record): string => AdminFormat::money($state, $record->currency->value))
                    ->alignEnd()
                    ->sortable(),

                Tables\Columns\TextColumn::make('fee')
                    ->label('Fee')
                    ->formatStateUsing(fn ($state): string => AdminFormat::money($state))
                    ->alignEnd()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('net_amount')
                    ->label('Net to player')
                    ->formatStateUsing(fn ($state, Withdrawal $record): string => AdminFormat::money($state, $record->currency->value))
                    ->alignEnd()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Requested')
                    ->since()
                    ->tooltip(fn ($state): string => AdminFormat::marketTime($state))
                    ->sortable(),

                Tables\Columns\TextColumn::make('completed_at')
                    ->label('Paid')
                    ->formatStateUsing(fn ($state): string => AdminFormat::marketTime($state))
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options(fn (): array => collect(WithdrawalStatus::cases())
                        ->mapWithKeys(fn (WithdrawalStatus $status): array => [$status->value => $status->label()])
                        ->all())
                    ->multiple(),

                Tables\Filters\SelectFilter::make('method')
                    ->options(fn (): array => collect(PaymentMethod::cases())
                        ->mapWithKeys(fn (PaymentMethod $method): array => [$method->value => $method->label()])
                        ->all())
                    ->multiple(),

                Tables\Filters\SelectFilter::make('currency')
                    ->options(fn (): array => collect(Currency::cases())
                        ->mapWithKeys(fn (Currency $currency): array => [$currency->value => $currency->value])
                        ->all()),

                Tables\Filters\SelectFilter::make('user_id')
                    ->label('Player')
                    ->relationship('user', 'username')
                    ->searchable()
                    ->preload(false),

                Tables\Filters\Filter::make('requested_between')
                    ->label('Requested between')
                    ->form([
                        \Filament\Forms\Components\DatePicker::make('from')->label('From'),
                        \Filament\Forms\Components\DatePicker::make('until')->label('Until'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['from'] ?? null, fn (Builder $q, $from): Builder => $q->whereDate('created_at', '>=', $from))
                            ->when($data['until'] ?? null, fn (Builder $q, $until): Builder => $q->whereDate('created_at', '<=', $until));
                    }),

                Tables\Filters\Filter::make('holding_reserved_funds')
                    ->label('Holding reserved funds')
                    ->query(fn (Builder $query): Builder => $query->whereIn('status', [
                        WithdrawalStatus::Approved,
                        WithdrawalStatus::Processing,
                    ]))
                    ->toggle(),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\ActionGroup::make(WithdrawalDecisionActions::forTable())
                    ->label('Decide')
                    ->icon('heroicon-m-check-badge')
                    ->button()
                    ->outlined()
                    ->visible(fn (): bool => AdminAccess::current(AdminAccess::MANAGE_PAYOUTS)),
            ])
            ->bulkActions([])
            ->emptyStateHeading('No withdrawals')
            ->emptyStateDescription('Payout requests appear here as players create them.');
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make('Request')
                ->schema([
                    Infolists\Components\TextEntry::make('reference_number')->label('Reference')->copyable(),
                    Infolists\Components\TextEntry::make('user.username')->label('Player'),
                    Infolists\Components\TextEntry::make('status')
                        ->badge()
                        ->formatStateUsing(fn (WithdrawalStatus $state): string => $state->label())
                        ->color(fn (WithdrawalStatus $state): string => $state->color()),
                    Infolists\Components\TextEntry::make('method')
                        ->badge()
                        ->formatStateUsing(fn (PaymentMethod $state): string => $state->label()),
                    Infolists\Components\TextEntry::make('provider')->placeholder('—'),
                    Infolists\Components\TextEntry::make('provider_reference')->label('Provider reference')->placeholder('—'),
                ])
                ->columns(3),

            Infolists\Components\Section::make('Amounts')
                ->description('Exact decimal strings as stored. The beneficiary payload is deliberately not shown on this screen.')
                ->schema([
                    Infolists\Components\TextEntry::make('amount')
                        ->label('Requested')
                        ->formatStateUsing(fn ($state, Withdrawal $record): string => AdminFormat::money($state, $record->currency->value)),
                    Infolists\Components\TextEntry::make('fee')
                        ->label('Fee')
                        ->formatStateUsing(fn ($state): string => AdminFormat::money($state)),
                    Infolists\Components\TextEntry::make('net_amount')
                        ->label('Net to player')
                        ->formatStateUsing(fn ($state, Withdrawal $record): string => AdminFormat::money($state, $record->currency->value)),
                    Infolists\Components\TextEntry::make('debitable_amount')
                        ->label('Would debit now')
                        ->state(fn (Withdrawal $record): string => AdminFormat::money(
                            app(WithdrawalApprovalService::class)->amountOf($record)->amount(),
                            $record->currency->value,
                        )),
                ])
                ->columns(4),

            Infolists\Components\Section::make('Decision trail')
                ->description('Withdrawals do have indexed review columns, so this trail is queryable rather than JSON — the derived outcome comes from WithdrawalApprovalService::approvalStatus().')
                ->schema([
                    Infolists\Components\TextEntry::make('review_outcome')
                        ->label('Derived review outcome')
                        ->badge()
                        ->state(fn (Withdrawal $record): string => app(WithdrawalApprovalService::class)->approvalStatus($record)->label())
                        ->color(fn (Withdrawal $record): string => app(WithdrawalApprovalService::class)->approvalStatus($record)->color()),
                    Infolists\Components\TextEntry::make('reviewed_by')->label('Reviewed by (user id)')->placeholder('—'),
                    Infolists\Components\TextEntry::make('reviewed_at')->label('Reviewed at')->formatStateUsing(fn ($state): string => AdminFormat::marketTime($state)),
                    Infolists\Components\TextEntry::make('approved_at')->label('Approved at')->formatStateUsing(fn ($state): string => AdminFormat::marketTime($state)),
                    Infolists\Components\TextEntry::make('rejected_at')->label('Rejected at')->formatStateUsing(fn ($state): string => AdminFormat::marketTime($state)),
                    Infolists\Components\TextEntry::make('rejection_reason')
                        ->label('Reason given (rejection or failure)')
                        ->placeholder('—')
                        ->columnSpanFull(),
                ])
                ->columns(3),

            Infolists\Components\Section::make('Settlement')
                ->schema([
                    Infolists\Components\TextEntry::make('settled')
                        ->label('Debited from the wallet?')
                        ->badge()
                        ->state(fn (Withdrawal $record): string => app(WithdrawalCompletionService::class)->isSettled($record) ? 'yes' : 'no')
                        ->color(fn (Withdrawal $record): string => app(WithdrawalCompletionService::class)->isSettled($record) ? 'success' : 'gray'),
                    Infolists\Components\TextEntry::make('holds_reserved_funds')
                        ->label('Holding a reservation?')
                        ->state(fn (Withdrawal $record): string => $record->status->holdsReservedFunds() ? 'yes' : 'no'),
                    Infolists\Components\TextEntry::make('financialTransaction.reference_number')
                        ->label('Financial transaction')
                        ->placeholder('none posted'),
                    Infolists\Components\TextEntry::make('completed_at')
                        ->label('Paid at')
                        ->formatStateUsing(fn ($state): string => AdminFormat::marketTime($state)),
                ])
                ->columns(4),
        ]);
    }

    /**
     * The approval-status vocabulary, kept here so a reader of this resource can see it
     * without opening the enum.
     *
     * @return array<string, string>
     */
    public static function approvalStatusOptions(): array
    {
        return collect(WithdrawalApprovalStatus::cases())
            ->mapWithKeys(fn (WithdrawalApprovalStatus $status): array => [$status->value => $status->label()])
            ->all();
    }

    /**
     * @return array<string, \Filament\Resources\Pages\PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListWithdrawals::route('/'),
            'view' => Pages\ViewWithdrawal::route('/{record}'),
        ];
    }
}
