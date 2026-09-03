<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\Currency;
use App\Enums\WalletStatus;
use App\Enums\WalletType;
use App\Filament\Resources\WalletResource\Pages;
use App\Filament\Resources\WalletResource\WalletControlActions;
use App\Models\Wallet;
use App\Services\Finance\WalletService;
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
 * Player wallets: what is in them, what is reserved, and the three operator controls.
 *
 * WHY available BALANCE IS ASKED OF THE SERVICE RATHER THAN SUBTRACTED HERE
 * Available balance is balance minus locked_balance, which looks trivial enough to inline —
 * and that is exactly how two definitions of "available" end up in one codebase. The panel
 * calls WalletService::availableBalance() so the number an operator reads is the number the
 * withdrawal and bet paths enforce, computed by the same bcmath code, to the same scale.
 *
 * WHY THERE IS NO CREATE, EDIT OR DELETE
 * A wallet is provisioned alongside its user and its balance is a derived fact: every
 * change to it is the consequence of a posted financial transaction. An editable balance
 * field would let money exist that no ledger entry explains. The only writes offered are
 * lock, unlock and an explicit adjustment that posts a double entry — and deleting a wallet
 * would orphan the ledger entries that reference it, so it is never allowed.
 *
 * WHY READING AND ADJUSTING ARE DIFFERENT PERMISSIONS
 * Reading is gated on 'view financial reports' or 'manage wallet', so an auditor can see
 * every balance. Adjusting, locking and unlocking need 'manage wallet', which an auditor
 * does not hold — so the auditor's version of this screen has no buttons at all rather than
 * buttons that fail.
 *
 * WHAT IS DELIBERATELY NOT DONE
 * - No hold breakdown per reservation. Wallet holds are owned by the requests that create
 *   them (withdrawals, bet purchases); this screen shows the aggregate the wallet carries.
 * - No transaction list embedded here. FinancialTransactionResource is the ledger history
 *   screen and duplicating it as a relation manager would mean two filter implementations.
 */
class WalletResource extends Resource
{
    protected static ?string $model = Wallet::class;

    protected static ?string $navigationIcon = 'heroicon-o-wallet';

    protected static ?string $navigationGroup = 'Finance';

    protected static ?int $navigationSort = 12;

    protected static ?string $recordTitleAttribute = 'id';

    public static function canViewAny(): bool
    {
        return AdminAccess::currentAny([
            AdminAccess::MANAGE_WALLET,
            AdminAccess::VIEW_FINANCIAL_REPORTS,
        ]);
    }

    public static function canView(Model $record): bool
    {
        return static::canViewAny();
    }

    public static function canCreate(): bool
    {
        // Wallets are provisioned with their user, never by hand.
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        // A balance is a consequence of postings, not a field. See the class comment.
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
        $locked = Wallet::query()->where('status', WalletStatus::Locked)->count();

        return $locked > 0 ? (string) $locked : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Locked wallets';
    }

    public static function form(Form $form): Form
    {
        // Intentionally empty: there is no editable field on a wallet.
        return $form->schema([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('user'))
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label('Wallet')
                    ->sortable()
                    ->formatStateUsing(fn ($state): string => '#'.$state),

                Tables\Columns\TextColumn::make('user.username')
                    ->label('Player')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('user.email')
                    ->label('Email')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('type')
                    ->badge()
                    ->formatStateUsing(fn (WalletType $state): string => $state->label()),

                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (WalletStatus $state): string => $state->label())
                    ->color(fn (WalletStatus $state): string => $state->color())
                    ->sortable(),

                Tables\Columns\TextColumn::make('balance')
                    ->label('Balance')
                    ->formatStateUsing(fn ($state, Wallet $record): string => AdminFormat::money($state, $record->currency->value))
                    ->alignEnd()
                    ->sortable(),

                Tables\Columns\TextColumn::make('locked_balance')
                    ->label('Reserved')
                    ->formatStateUsing(fn ($state): string => AdminFormat::money($state))
                    ->alignEnd()
                    ->sortable(),

                Tables\Columns\TextColumn::make('available_balance')
                    ->label('Available')
                    ->state(fn (Wallet $record): string => AdminFormat::money(
                        app(WalletService::class)->availableBalance($record)->amount(),
                        $record->currency->value,
                    ))
                    ->alignEnd()
                    ->tooltip('WalletService::availableBalance() — balance minus reserved funds, computed with bcmath.'),

                Tables\Columns\TextColumn::make('locked_reason')
                    ->label('Lock reason')
                    ->placeholder('—')
                    ->wrap()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('updated_at')
                    ->label('Last movement')
                    ->since()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options(fn (): array => collect(WalletStatus::cases())
                        ->mapWithKeys(fn (WalletStatus $status): array => [$status->value => $status->label()])
                        ->all())
                    ->multiple(),

                Tables\Filters\SelectFilter::make('type')
                    ->options(fn (): array => collect(WalletType::cases())
                        ->mapWithKeys(fn (WalletType $type): array => [$type->value => $type->label()])
                        ->all()),

                Tables\Filters\SelectFilter::make('currency')
                    ->options(fn (): array => collect(Currency::cases())
                        ->mapWithKeys(fn (Currency $currency): array => [$currency->value => $currency->value])
                        ->all()),

                Tables\Filters\SelectFilter::make('user_id')
                    ->label('Player')
                    ->relationship('user', 'username')
                    ->searchable()
                    ->preload(false),

                Tables\Filters\Filter::make('has_reservation')
                    ->label('Holding reserved funds')
                    ->query(fn (Builder $query): Builder => $query->where('locked_balance', '>', 0))
                    ->toggle(),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\ActionGroup::make(WalletControlActions::forTable())
                    ->label('Controls')
                    ->icon('heroicon-m-adjustments-horizontal')
                    ->button()
                    ->outlined()
                    ->visible(fn (): bool => AdminAccess::current(AdminAccess::MANAGE_WALLET)),
            ])
            ->bulkActions([])
            ->emptyStateHeading('No wallets')
            ->emptyStateDescription('A wallet is provisioned with each player account.');
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make('Wallet')
                ->schema([
                    Infolists\Components\TextEntry::make('id')->label('Wallet id'),
                    Infolists\Components\TextEntry::make('user.username')->label('Player'),
                    Infolists\Components\TextEntry::make('type')
                        ->badge()
                        ->formatStateUsing(fn (WalletType $state): string => $state->label()),
                    Infolists\Components\TextEntry::make('status')
                        ->badge()
                        ->formatStateUsing(fn (WalletStatus $state): string => $state->label())
                        ->color(fn (WalletStatus $state): string => $state->color()),
                    Infolists\Components\TextEntry::make('currency')
                        ->formatStateUsing(fn (Currency $state): string => $state->value),
                    Infolists\Components\TextEntry::make('version')
                        ->label('Optimistic-lock version')
                        ->helperText('Incremented by every service that touches this wallet.'),
                ])
                ->columns(3),

            Infolists\Components\Section::make('Balances')
                ->description('Exact decimal strings. Available balance is WalletService::availableBalance(), not a subtraction performed by this screen.')
                ->schema([
                    Infolists\Components\TextEntry::make('balance')
                        ->label('Total balance')
                        ->formatStateUsing(fn ($state, Wallet $record): string => AdminFormat::money($state, $record->currency->value)),
                    Infolists\Components\TextEntry::make('locked_balance')
                        ->label('Reserved')
                        ->formatStateUsing(fn ($state, Wallet $record): string => AdminFormat::money($state, $record->currency->value)),
                    Infolists\Components\TextEntry::make('available_balance')
                        ->label('Available to spend')
                        ->state(fn (Wallet $record): string => AdminFormat::money(
                            app(WalletService::class)->availableBalance($record)->amount(),
                            $record->currency->value,
                        ))
                        ->weight('bold'),
                ])
                ->columns(3),

            Infolists\Components\Section::make('Lifetime totals')
                ->description('Maintained by the finance services as transactions post. Never written by this panel.')
                ->schema([
                    Infolists\Components\TextEntry::make('total_deposited')->label('Deposited')->formatStateUsing(fn ($state): string => AdminFormat::money($state)),
                    Infolists\Components\TextEntry::make('total_withdrawn')->label('Withdrawn')->formatStateUsing(fn ($state): string => AdminFormat::money($state)),
                    Infolists\Components\TextEntry::make('total_wagered')->label('Wagered')->formatStateUsing(fn ($state): string => AdminFormat::money($state)),
                    Infolists\Components\TextEntry::make('total_won')->label('Won')->formatStateUsing(fn ($state): string => AdminFormat::money($state)),
                ])
                ->columns(4),

            Infolists\Components\Section::make('Lock')
                ->schema([
                    Infolists\Components\TextEntry::make('locked_at')
                        ->label('Locked at')
                        ->formatStateUsing(fn ($state): string => AdminFormat::marketTime($state)),
                    Infolists\Components\TextEntry::make('locked_reason')
                        ->label('Reason')
                        ->placeholder('not locked')
                        ->columnSpanFull(),
                ])
                ->columns(2),
        ]);
    }

    /**
     * @return array<string, \Filament\Resources\Pages\PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListWallets::route('/'),
            'view' => Pages\ViewWallet::route('/{record}'),
        ];
    }
}
