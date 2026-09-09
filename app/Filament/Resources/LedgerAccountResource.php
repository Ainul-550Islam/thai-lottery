<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\LedgerAccountType;
use App\Enums\LedgerEntryType;
use App\Filament\Resources\LedgerAccountResource\Pages;
use App\Models\LedgerAccount;
use App\Models\LedgerEntry;
use App\Support\Admin\AdminAccess;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;

/**
 * Filament Admin Resource for Viewing Double-Entry General Ledger Chart of Accounts.
 */
class LedgerAccountResource extends Resource
{
    protected static ?string $model = LedgerAccount::class;

    protected static ?string $navigationIcon = 'heroicon-o-scale';

    protected static ?string $navigationGroup = 'Financial Operations';

    protected static ?int $navigationSort = 5;

    /**
     * @var array<int, string>|null
     */
    protected static ?array $entrySums = null;

    public static function forgetEntrySums(): void
    {
        static::$entrySums = null;
    }

    public static function entryCount(): int
    {
        return LedgerEntry::query()->count();
    }

    public static function shouldRegisterNavigation(): bool
    {
        return AdminAccess::current(AdminAccess::VIEW_FINANCE);
    }

    public static function canViewAny(): bool
    {
        return AdminAccess::current(AdminAccess::VIEW_FINANCE);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canDelete(mixed $record): bool
    {
        return false;
    }

    public static function form(Form $form): Form
    {
        return $form;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('code')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('type')
                    ->badge()
                    ->sortable(),
                Tables\Columns\TextColumn::make('currency')
                    ->sortable(),
                Tables\Columns\TextColumn::make('computed_balance')
                    ->label('Calculated Balance')
                    ->state(function (LedgerAccount $record): string {
                        if (static::$entrySums === null) {
                            $entries = LedgerEntry::query()
                                ->select('ledger_account_id', 'type', DB::raw('SUM(amount) as total_amount'))
                                ->groupBy('ledger_account_id', 'type')
                                ->get();

                            static::$entrySums = [];
                            foreach ($entries as $row) {
                                $accId = (int) $row->ledger_account_id;
                                $type = $row->type instanceof LedgerEntryType ? $row->type : LedgerEntryType::from((string) $row->type);
                                if (! isset(static::$entrySums[$accId])) {
                                    static::$entrySums[$accId] = '0.00';
                                }

                                if ($type === LedgerEntryType::Debit) {
                                    static::$entrySums[$accId] = bcsub(static::$entrySums[$accId], (string) $row->total_amount, 2);
                                } else {
                                    static::$entrySums[$accId] = bcadd(static::$entrySums[$accId], (string) $row->total_amount, 2);
                                }
                            }
                        }

                        $opening = (string) ($record->opening_balance ?? '0.00');
                        $sum = static::$entrySums[$record->id] ?? '0.00';

                        return bcadd($opening, $sum, 2);
                    })
                    ->money('THB')
                    ->sortable(),
                Tables\Columns\TextColumn::make('current_balance')
                    ->label('Cached Balance')
                    ->money('THB')
                    ->sortable(),
                Tables\Columns\IconColumn::make('is_active')
                    ->boolean(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('type')
                    ->options(collect(LedgerAccountType::cases())->mapWithKeys(fn ($t) => [$t->value => $t->label()])),
            ])
            ->actions([])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListLedgerAccounts::route('/'),
        ];
    }
}
