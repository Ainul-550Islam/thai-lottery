<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\Currency;
use App\Enums\LedgerEntryType;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Filament\Resources\FinancialTransactionResource\Pages;
use App\Models\FinancialTransaction;
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
 * The transaction ledger, read-only by construction.
 *
 * WHY THIS RESOURCE HAS NO ACTIONS AT ALL
 * Not one, not even a hidden one. A financial transaction is the record that a balanced
 * double entry was posted; it is the evidence, not the thing the evidence describes. If it
 * could be edited, the ledger would stop being an audit trail and become an opinion. If it
 * could be deleted, the ledger entries that reference it would be orphaned and total debits
 * would no longer equal total credits — the single invariant the whole finance layer is
 * built to preserve.
 *
 * Correcting a transaction therefore means posting another one. That is
 * FinancialReversalService's job, and it is deliberately not surfaced here: a reversal is a
 * finance decision with its own preconditions, not a row action on a history screen. The
 * gap is reported rather than half-built.
 *
 * WHY 'view transaction history' AND NOT 'manage payouts'
 * This is the screen an auditor lives on. It is gated on the read permission an auditor
 * holds, and because the resource offers no write path of any kind, the auditor's view and
 * an admin's view are identical — there is no branch to get wrong.
 *
 * WHAT IS DELIBERATELY NOT DONE
 * - No entry-level ledger relation manager. The paired debit and credit are summarised in
 *   the infolist; the full per-account view belongs to the chart of accounts screen.
 * - No CSV export. Export belongs to the reporting phase, and a half-implemented one
 *   invites people to reconcile against an incomplete file.
 */
class FinancialTransactionResource extends Resource
{
    protected static ?string $model = FinancialTransaction::class;

    protected static ?string $navigationIcon = 'heroicon-o-receipt-percent';

    protected static ?string $navigationGroup = 'Finance';

    protected static ?int $navigationSort = 13;

    protected static ?string $navigationLabel = 'Transactions';

    protected static ?string $recordTitleAttribute = 'reference_number';

    public static function canViewAny(): bool
    {
        return AdminAccess::currentAny([
            AdminAccess::VIEW_TRANSACTION_HISTORY,
            AdminAccess::VIEW_FINANCIAL_REPORTS,
        ]);
    }

    public static function canView(Model $record): bool
    {
        return static::canViewAny();
    }

    public static function canCreate(): bool
    {
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

    public static function form(Form $form): Form
    {
        // Intentionally empty: a posted transaction has no editable field.
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
                    ->placeholder('system')
                    ->sortable(),

                Tables\Columns\TextColumn::make('wallet_id')
                    ->label('Wallet')
                    ->formatStateUsing(fn ($state): string => $state === null ? '—' : '#'.$state)
                    ->searchable(),

                Tables\Columns\TextColumn::make('type')
                    ->badge()
                    ->formatStateUsing(fn (TransactionType $state): string => $state->label())
                    ->sortable(),

                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (TransactionStatus $state): string => $state->label())
                    ->color(fn (TransactionStatus $state): string => $state->color())
                    ->sortable(),

                Tables\Columns\TextColumn::make('amount')
                    ->label('Amount')
                    ->formatStateUsing(fn ($state, FinancialTransaction $record): string => AdminFormat::money($state, $record->currency->value))
                    ->alignEnd()
                    ->sortable(),

                Tables\Columns\TextColumn::make('fee')
                    ->label('Fee')
                    ->formatStateUsing(fn ($state): string => AdminFormat::money($state))
                    ->alignEnd()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('description')
                    ->label('Description')
                    ->searchable()
                    ->wrap()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('reference_type')
                    ->label('Source')
                    ->formatStateUsing(fn ($state): string => $state === null ? '—' : class_basename((string) $state))
                    ->toggleable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Posted')
                    ->since()
                    ->tooltip(fn ($state): string => AdminFormat::marketTime($state))
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('type')
                    ->options(fn (): array => collect(TransactionType::cases())
                        ->mapWithKeys(fn (TransactionType $type): array => [$type->value => $type->label()])
                        ->all())
                    ->multiple(),

                Tables\Filters\SelectFilter::make('status')
                    ->options(fn (): array => collect(TransactionStatus::cases())
                        ->mapWithKeys(fn (TransactionStatus $status): array => [$status->value => $status->label()])
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

                Tables\Filters\Filter::make('posted_between')
                    ->label('Posted between')
                    ->form([
                        \Filament\Forms\Components\DatePicker::make('from')->label('From'),
                        \Filament\Forms\Components\DatePicker::make('until')->label('Until'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['from'] ?? null, fn (Builder $q, $from): Builder => $q->whereDate('created_at', '>=', $from))
                            ->when($data['until'] ?? null, fn (Builder $q, $until): Builder => $q->whereDate('created_at', '<=', $until));
                    }),

                Tables\Filters\Filter::make('reversed')
                    ->label('Reversed only')
                    ->query(fn (Builder $query): Builder => $query->whereNotNull('reversed_at'))
                    ->toggle(),
            ])
            // No row actions, no bulk actions: this screen cannot change anything.
            ->actions([
                Tables\Actions\ViewAction::make(),
            ])
            ->bulkActions([])
            ->emptyStateHeading('No transactions posted')
            ->emptyStateDescription('Every credit and debit in the system appears here once a service posts it.');
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make('Transaction')
                ->schema([
                    Infolists\Components\TextEntry::make('reference_number')->label('Reference')->copyable(),
                    Infolists\Components\TextEntry::make('type')
                        ->badge()
                        ->formatStateUsing(fn (TransactionType $state): string => $state->label()),
                    Infolists\Components\TextEntry::make('status')
                        ->badge()
                        ->formatStateUsing(fn (TransactionStatus $state): string => $state->label())
                        ->color(fn (TransactionStatus $state): string => $state->color()),
                    Infolists\Components\TextEntry::make('user.username')->label('Player')->placeholder('system'),
                    Infolists\Components\TextEntry::make('wallet_id')
                        ->label('Wallet')
                        ->formatStateUsing(fn ($state): string => $state === null ? '—' : '#'.$state),
                    Infolists\Components\TextEntry::make('idempotency_key')
                        ->label('Idempotency key')
                        ->placeholder('—')
                        ->helperText('The unique key that makes a retry of this posting a no-op.'),
                ])
                ->columns(3),

            Infolists\Components\Section::make('Amounts')
                ->schema([
                    Infolists\Components\TextEntry::make('amount')
                        ->label('Amount')
                        ->formatStateUsing(fn ($state, FinancialTransaction $record): string => AdminFormat::money($state, $record->currency->value)),
                    Infolists\Components\TextEntry::make('fee')
                        ->label('Fee')
                        ->formatStateUsing(fn ($state): string => AdminFormat::money($state)),
                    Infolists\Components\TextEntry::make('description')->label('Description')->placeholder('—')->columnSpanFull(),
                ])
                ->columns(3),

            Infolists\Components\Section::make('Double entry')
                ->description('The paired ledger entries this transaction posted. Debits and credits must be equal; if they are not, the ledger balance widget on the dashboard will say so.')
                ->schema([
                    Infolists\Components\TextEntry::make('entry_debits')
                        ->label('Total debits')
                        ->state(fn (FinancialTransaction $record): string => AdminFormat::money(
                            self::entrySum($record, LedgerEntryType::Debit),
                            $record->currency->value,
                        )),
                    Infolists\Components\TextEntry::make('entry_credits')
                        ->label('Total credits')
                        ->state(fn (FinancialTransaction $record): string => AdminFormat::money(
                            self::entrySum($record, LedgerEntryType::Credit),
                            $record->currency->value,
                        )),
                    Infolists\Components\TextEntry::make('entry_accounts')
                        ->label('Accounts touched')
                        ->state(fn (FinancialTransaction $record): string => $record->ledgerEntries()
                            ->with('ledgerAccount')
                            ->get()
                            ->map(fn ($entry): string => sprintf(
                                '%s %s %s',
                                (string) ($entry->ledgerAccount->code ?? '?'),
                                is_object($entry->type) ? $entry->type->value : (string) $entry->type,
                                AdminFormat::money($entry->amount),
                            ))
                            ->implode(' · ') ?: 'no entries posted')
                        ->columnSpanFull(),
                ])
                ->columns(2),

            Infolists\Components\Section::make('Provenance')
                ->schema([
                    Infolists\Components\TextEntry::make('reference_type')
                        ->label('Source model')
                        ->formatStateUsing(fn ($state): string => $state === null ? '—' : (string) $state),
                    Infolists\Components\TextEntry::make('reference_id')->label('Source id')->placeholder('—'),
                    Infolists\Components\TextEntry::make('processed_at')
                        ->label('Processed at')
                        ->formatStateUsing(fn ($state): string => AdminFormat::marketTime($state)),
                    Infolists\Components\TextEntry::make('reversed_at')
                        ->label('Reversed at')
                        ->formatStateUsing(fn ($state): string => AdminFormat::marketTime($state)),
                    Infolists\Components\TextEntry::make('reversedBy.username')
                        ->label('Reversed by')
                        ->placeholder('—'),
                    Infolists\Components\TextEntry::make('created_at')
                        ->label('Posted at')
                        ->formatStateUsing(fn ($state): string => AdminFormat::marketTime($state)),
                ])
                ->columns(3),
        ]);
    }

    /**
     * Sum one side of a transaction's ledger entries as an exact string.
     *
     * The sum is asked of the database through a raw select rather than Eloquent's sum(),
     * which hands back a float on some drivers, and normalised with bcadd rather than cast.
     * A cast to float here would be the one place a rounding artefact could enter an
     * otherwise exact pipeline — the same reason LedgerBalanceWidget does it this way.
     */
    private static function entrySum(FinancialTransaction $transaction, LedgerEntryType $side): string
    {
        $row = $transaction->ledgerEntries()
            ->where('type', $side->value)
            ->selectRaw('COALESCE(SUM(amount), 0) as total')
            ->first();

        $raw = trim((string) ($row->total ?? '0'));

        return $raw === '' ? '0.00' : bcadd($raw, '0', 2);
    }

    /**
     * @return array<string, \Filament\Resources\Pages\PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListFinancialTransactions::route('/'),
            'view' => Pages\ViewFinancialTransaction::route('/{record}'),
        ];
    }
}
