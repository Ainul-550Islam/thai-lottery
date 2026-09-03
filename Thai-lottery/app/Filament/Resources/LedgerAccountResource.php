<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\Currency;
use App\Enums\LedgerAccountType;
use App\Filament\Resources\LedgerAccountResource\Pages;
use App\Models\LedgerAccount;
use App\Models\LedgerEntry;
use App\Support\Admin\AdminAccess;
use App\Support\Admin\AdminFormat;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * The chart of accounts, with each account's balance computed from its entries.
 *
 * WHY THE BALANCE IS COMPUTED AND NOT READ FROM ledger_accounts.current_balance
 * The column exists, but a cached balance is only as trustworthy as the last process that
 * remembered to update it — and a stale figure on this screen is worse than no figure,
 * because reconciliation starts here. So the balance shown is derived from the entries
 * themselves: opening balance, plus debits and minus credits for a debit-normal account,
 * the other way round for a credit-normal one. That is the definition the accounting model
 * uses, and LedgerAccount::isDebitNormal() already owns which side an account type sits on.
 *
 * HOW IT STAYS CHEAP
 * One grouped aggregate query per page render, not one per row: entrySums() sums
 * ledger_entries by account and side in a single statement and memoises the result for the
 * request. A per-row query would turn an eight-account chart into seventeen queries, and a
 * per-row PHP fold over every entry would load the entire ledger into memory to display a
 * table of eight numbers. The arithmetic on the aggregates is bcmath on strings; the raw
 * SUM() values are read through a raw select so no driver hands back a float.
 *
 * WHY THERE IS NO WRITE PATH
 * Accounts are seeded infrastructure. Renaming 1000 to something else, deactivating an
 * account that has entries, or deleting one outright would break every posting that
 * references it — LedgerPostingService::resolveAccount() deliberately refuses to invent an
 * account for exactly this reason, and this screen deliberately refuses to create one.
 *
 * WHAT IS DELIBERATELY NOT DONE
 * - No view page. There is nothing on a single account that the row does not already show,
 *   and the entries behind it are FinancialTransactionResource's subject.
 * - No period selector. Balances are lifetime-to-date; period reporting is the reporting
 *   phase's job and a half-built date filter here would be quietly wrong at month end.
 */
class LedgerAccountResource extends Resource
{
    protected static ?string $model = LedgerAccount::class;

    protected static ?string $navigationIcon = 'heroicon-o-book-open';

    protected static ?string $navigationGroup = 'Finance';

    protected static ?int $navigationSort = 14;

    protected static ?string $navigationLabel = 'Chart of accounts';

    protected static ?string $recordTitleAttribute = 'code';

    /**
     * Memoised per-account, per-side sums for the current request.
     *
     * @var array<string, string>|null
     */
    private static ?array $entrySums = null;

    public static function canViewAny(): bool
    {
        return AdminAccess::currentAny([
            AdminAccess::RECONCILE_LEDGER,
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
        // Intentionally empty: the chart of accounts is seeded infrastructure.
        return $form->schema([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('code')
            ->paginated([25, 50, 100])
            ->columns([
                Tables\Columns\TextColumn::make('code')
                    ->label('Code')
                    ->searchable()
                    ->sortable()
                    ->copyable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('name')
                    ->label('Account')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('type')
                    ->badge()
                    ->formatStateUsing(fn (LedgerAccountType $state): string => $state->label())
                    ->sortable(),

                Tables\Columns\TextColumn::make('normal_side')
                    ->label('Normal side')
                    ->state(fn (LedgerAccount $record): string => $record->isDebitNormal() ? 'debit' : 'credit')
                    ->badge()
                    ->color('gray'),

                Tables\Columns\TextColumn::make('opening_balance')
                    ->label('Opening')
                    ->formatStateUsing(fn ($state): string => AdminFormat::money($state))
                    ->alignEnd()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('total_debits')
                    ->label('Debits')
                    ->state(fn (LedgerAccount $record): string => AdminFormat::money(self::sideTotal($record, 'debit')))
                    ->alignEnd(),

                Tables\Columns\TextColumn::make('total_credits')
                    ->label('Credits')
                    ->state(fn (LedgerAccount $record): string => AdminFormat::money(self::sideTotal($record, 'credit')))
                    ->alignEnd(),

                Tables\Columns\TextColumn::make('computed_balance')
                    ->label('Balance')
                    ->state(fn (LedgerAccount $record): string => AdminFormat::money(
                        self::computedBalance($record),
                        $record->currency->value,
                    ))
                    ->alignEnd()
                    ->weight('bold')
                    ->tooltip('Computed from the account entries with bcmath, not read from the cached current_balance column.'),

                Tables\Columns\TextColumn::make('cached_balance')
                    ->label('Cached column')
                    ->formatStateUsing(fn ($state): string => AdminFormat::money($state))
                    ->state(fn (LedgerAccount $record): mixed => $record->current_balance)
                    ->alignEnd()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->tooltip('ledger_accounts.current_balance as stored. Shown for comparison only.'),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('type')
                    ->options(fn (): array => collect(LedgerAccountType::cases())
                        ->mapWithKeys(fn (LedgerAccountType $type): array => [$type->value => $type->label()])
                        ->all())
                    ->multiple(),

                Tables\Filters\SelectFilter::make('currency')
                    ->options(fn (): array => collect(Currency::cases())
                        ->mapWithKeys(fn (Currency $currency): array => [$currency->value => $currency->value])
                        ->all()),

                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Active'),
            ])
            // No row actions and no bulk actions: the chart of accounts is read-only.
            ->actions([])
            ->bulkActions([])
            ->emptyStateHeading('No ledger accounts')
            ->emptyStateDescription('The chart of accounts must be seeded before any financial transaction can post.');
    }

    /**
     * The exact balance of one account, as a decimal string.
     */
    public static function computedBalance(LedgerAccount $account): string
    {
        $opening = self::normalise((string) $account->opening_balance);
        $debits = self::sideTotal($account, 'debit');
        $credits = self::sideTotal($account, 'credit');

        return $account->isDebitNormal()
            ? bcsub(bcadd($opening, $debits, 2), $credits, 2)
            : bcsub(bcadd($opening, $credits, 2), $debits, 2);
    }

    /**
     * One side's total for one account, from the memoised aggregate.
     */
    public static function sideTotal(LedgerAccount $account, string $side): string
    {
        $sums = self::entrySums();

        return $sums[$account->getKey().':'.$side] ?? '0.00';
    }

    /**
     * Every account's debit and credit totals, in a single query per request.
     *
     * @return array<string, string>
     */
    public static function entrySums(): array
    {
        if (self::$entrySums !== null) {
            return self::$entrySums;
        }

        $rows = LedgerEntry::query()
            ->select('ledger_account_id', 'type')
            ->selectRaw('COALESCE(SUM(amount), 0) as total')
            ->groupBy('ledger_account_id', 'type')
            ->get();

        $sums = [];

        foreach ($rows as $row) {
            $type = is_object($row->type) ? $row->type->value : (string) $row->type;
            $sums[$row->ledger_account_id.':'.$type] = self::normalise((string) $row->total);
        }

        return self::$entrySums = $sums;
    }

    /**
     * Drop the memoised aggregate. Called by the list page on every render so a balance is
     * never a page-lifetime-stale copy of itself after a Livewire round trip.
     */
    public static function forgetEntrySums(): void
    {
        self::$entrySums = null;
    }

    /**
     * How many ledger entries back this page's figures, for the header description.
     */
    public static function entryCount(): int
    {
        return (int) DB::table('ledger_entries')->whereNull('deleted_at')->count();
    }

    private static function normalise(string $amount): string
    {
        $amount = trim($amount);

        return $amount === '' ? '0.00' : bcadd($amount, '0', 2);
    }

    /**
     * @return array<string, \Filament\Resources\Pages\PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListLedgerAccounts::route('/'),
        ];
    }
}
