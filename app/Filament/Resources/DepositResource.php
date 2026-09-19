<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\Currency;
use App\Enums\DepositStatus;
use App\Enums\PaymentMethod;
use App\Filament\Resources\DepositResource\DepositDecisionActions;
use App\Filament\Resources\DepositResource\Pages;
use App\Models\Deposit;
use App\Services\Finance\DepositApprovalService;
use App\Services\Finance\DepositCompletionService;
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
 * Money arriving: the deposit queue and the two decisions it needs.
 *
 * WHY THIS RESOURCE HAS NO FORM
 * A deposit is a record of something a payment provider did. Nothing about it is the
 * panel's to invent: the amount, the fee, the method and the provider reference all came
 * from the request that created it, and editing any of them after the fact would make the
 * row disagree with the provider's own statement while looking authoritative. So there is
 * no create page, no edit page and no delete: the only writes this screen can cause are
 * the state transitions DepositApprovalService and DepositCompletionService own, and they
 * are offered as explicit decisions with their own confirmation copy.
 *
 * WHY TWO DIFFERENT PERMISSIONS
 * Reading the queue is gated on 'view transaction history' so an auditor can reconcile
 * without being able to move anything. Every decision is gated on 'manage payouts', which
 * an auditor does not hold — so the same screen is a read-only ledger for them and a work
 * queue for an admin, with no second implementation.
 *
 * WHAT IS DELIBERATELY NOT DONE
 * - No reversal. Un-crediting a confirmed deposit is FinancialReversalService's job and is
 *   not exposed here; a confirmed deposit is therefore terminal from this panel's point of
 *   view, which is the honest state of the phase.
 * - No provider re-query. This screen does not talk to payment gateways; it shows what the
 *   application already recorded.
 * - The decision trail is read out of metadata by the service, because the schema has no
 *   indexed reviewer columns for deposits (withdrawals do). That limitation is surfaced in
 *   the infolist rather than hidden behind a prettier column.
 */
class DepositResource extends Resource
{
    protected static ?string $model = Deposit::class;

    protected static ?string $navigationIcon = 'heroicon-o-arrow-down-on-square';

    protected static ?string $navigationGroup = 'Finance';

    protected static ?int $navigationSort = 10;

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
        // Deposits are created by the deposit pipeline from a real payment intent, never
        // typed in by an operator.
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
        $pending = Deposit::query()->where('status', DepositStatus::Pending)->count();

        return $pending > 0 ? (string) $pending : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Deposits awaiting a decision';
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
                    ->formatStateUsing(fn (DepositStatus $state): string => $state->label())
                    ->color(fn (DepositStatus $state): string => $state->color())
                    ->sortable(),

                Tables\Columns\TextColumn::make('amount')
                    ->label('Amount')
                    ->formatStateUsing(fn ($state, Deposit $record): string => AdminFormat::money($state, $record->currency->value))
                    ->alignEnd()
                    ->sortable(),

                Tables\Columns\TextColumn::make('fee')
                    ->label('Fee')
                    ->formatStateUsing(fn ($state): string => AdminFormat::money($state))
                    ->alignEnd()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('net_amount')
                    ->label('Credited net')
                    ->formatStateUsing(fn ($state, Deposit $record): string => AdminFormat::money($state, $record->currency->value))
                    ->alignEnd()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('provider_reference')
                    ->label('Provider ref')
                    ->searchable()
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Requested')
                    ->since()
                    ->tooltip(fn ($state): string => AdminFormat::marketTime($state))
                    ->sortable(),

                Tables\Columns\TextColumn::make('confirmed_at')
                    ->label('Credited')
                    ->formatStateUsing(fn ($state): string => AdminFormat::marketTime($state))
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options(fn (): array => collect(DepositStatus::cases())
                        ->mapWithKeys(fn (DepositStatus $status): array => [$status->value => $status->label()])
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

                Tables\Filters\Filter::make('awaiting_decision')
                    ->label('Awaiting a decision')
                    ->query(fn (Builder $query): Builder => $query->whereIn('status', [DepositStatus::Pending, DepositStatus::Approved]))
                    ->toggle(),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\ActionGroup::make(DepositDecisionActions::forTable())
                    ->label('Decide')
                    ->icon('heroicon-m-check-badge')
                    ->button()
                    ->outlined()
                    ->visible(fn (): bool => AdminAccess::current(AdminAccess::MANAGE_PAYOUTS)),
            ])
            ->bulkActions([])
            ->emptyStateHeading('No deposits')
            ->emptyStateDescription('Deposit requests appear here as players create them.');
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
                        ->formatStateUsing(fn (DepositStatus $state): string => $state->label())
                        ->color(fn (DepositStatus $state): string => $state->color()),
                    Infolists\Components\TextEntry::make('method')
                        ->badge()
                        ->formatStateUsing(fn (PaymentMethod $state): string => $state->label()),
                    Infolists\Components\TextEntry::make('provider')->placeholder('—'),
                    Infolists\Components\TextEntry::make('provider_reference')->label('Provider reference')->placeholder('—')->copyable(),
                ])
                ->columns(3),

            Infolists\Components\Section::make('Amounts')
                ->description('Exact decimal strings as stored. Nothing on this screen performs arithmetic on them.')
                ->schema([
                    Infolists\Components\TextEntry::make('amount')
                        ->label('Requested')
                        ->formatStateUsing(fn ($state, Deposit $record): string => AdminFormat::money($state, $record->currency->value)),
                    Infolists\Components\TextEntry::make('fee')
                        ->label('Fee')
                        ->formatStateUsing(fn ($state): string => AdminFormat::money($state)),
                    Infolists\Components\TextEntry::make('net_amount')
                        ->label('Net')
                        ->formatStateUsing(fn ($state, Deposit $record): string => AdminFormat::money($state, $record->currency->value)),
                    Infolists\Components\TextEntry::make('creditable_amount')
                        ->label('Creditable now')
                        ->state(fn (Deposit $record): string => AdminFormat::money(
                            app(DepositCompletionService::class)->creditableAmount($record)->amount(),
                            $record->currency->value,
                        ))
                        ->helperText('What DepositCompletionService would credit if completion ran right now.'),
                ])
                ->columns(4),

            Infolists\Components\Section::make('Decision trail')
                ->description('Read back out of the deposit metadata by DepositApprovalService::decisionTrail(). The deposits table has no indexed reviewer columns, so this is JSON rather than a queryable audit table.')
                ->schema([
                    Infolists\Components\TextEntry::make('trail_decision')
                        ->label('Decision')
                        ->state(fn (Deposit $record): string => (string) (app(DepositApprovalService::class)->decisionTrail($record)['decision'] ?? 'no decision recorded')),
                    Infolists\Components\TextEntry::make('trail_at')
                        ->label('Decided at')
                        ->state(fn (Deposit $record): string => AdminFormat::marketTime(app(DepositApprovalService::class)->decisionTrail($record)['at'] ?? null)),
                    Infolists\Components\TextEntry::make('trail_by')
                        ->label('Decided by (user id)')
                        ->state(fn (Deposit $record): string => (string) (app(DepositApprovalService::class)->decisionTrail($record)['by'] ?? '—')),
                    Infolists\Components\TextEntry::make('trail_note')
                        ->label('Note / reason')
                        ->state(fn (Deposit $record): string => (string) (app(DepositApprovalService::class)->decisionTrail($record)['note'] ?? '—'))
                        ->columnSpanFull(),
                ])
                ->columns(3),

            Infolists\Components\Section::make('Settlement')
                ->schema([
                    Infolists\Components\TextEntry::make('settled')
                        ->label('Credited to the wallet?')
                        ->state(fn (Deposit $record): string => app(DepositCompletionService::class)->isSettled($record) ? 'yes' : 'no')
                        ->badge()
                        ->color(fn (Deposit $record): string => app(DepositCompletionService::class)->isSettled($record) ? 'success' : 'gray'),
                    Infolists\Components\TextEntry::make('financialTransaction.reference_number')
                        ->label('Financial transaction')
                        ->placeholder('none posted'),
                    Infolists\Components\TextEntry::make('confirmed_at')
                        ->label('Credited at')
                        ->formatStateUsing(fn ($state): string => AdminFormat::marketTime($state)),
                    Infolists\Components\TextEntry::make('failed_at')
                        ->label('Failed at')
                        ->formatStateUsing(fn ($state): string => AdminFormat::marketTime($state)),
                    Infolists\Components\TextEntry::make('failure_reason')
                        ->label('Failure reason')
                        ->placeholder('—')
                        ->columnSpanFull(),
                ])
                ->columns(4),
        ]);
    }

    /**
     * @return array<string, \Filament\Resources\Pages\PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDeposits::route('/'),
            'view' => Pages\ViewDeposit::route('/{record}'),
        ];
    }
}
