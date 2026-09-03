<?php

declare(strict_types=1);

namespace App\Filament\Resources\UserResource\RelationManagers;

use App\Enums\WalletStatus;
use App\Enums\WalletType;
use App\Models\Wallet;
use App\Support\Admin\AdminAccess;
use App\Support\Admin\AdminFormat;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * The wallets belonging to one account — read only.
 *
 * A wallet balance is not a number on a screen, it is the closing position of a
 * double-entry ledger. WalletService owns every movement (credit, debit, lockFunds,
 * unlockFunds, lockWallet) and each one posts entries; typing a new balance into a table
 * cell would leave the ledger and the wallet disagreeing, which is the one failure an
 * accounting system must never be able to produce. So there is no create, no edit, no
 * delete and no inline balance adjustment here.
 *
 * Wallet locking and crediting belong to the Finance resources, next to the deposits and
 * withdrawals they arise from, not to an identity screen.
 */
class WalletsRelationManager extends RelationManager
{
    protected static string $relationship = 'wallets';

    protected static ?string $title = 'Wallets';

    protected static ?string $icon = 'heroicon-o-wallet';

    public function isReadOnly(): bool
    {
        return true;
    }

    public static function canViewForRecord($ownerRecord, string $pageClass): bool
    {
        // Seeing someone's balance is a financial disclosure, so it needs a financial
        // permission on top of the identity one that opened the page.
        return AdminAccess::currentAny([
            AdminAccess::MANAGE_USERS,
            AdminAccess::VIEW_TRANSACTION_HISTORY,
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('type')
            ->columns([
                Tables\Columns\TextColumn::make('type')
                    ->label('Wallet')
                    ->badge()
                    ->formatStateUsing(fn (WalletType $state): string => ucfirst($state->value)),

                Tables\Columns\TextColumn::make('currency')
                    ->label('Currency')
                    ->formatStateUsing(fn ($state): string => is_object($state) ? (string) $state->value : (string) $state),

                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (WalletStatus $state): string => ucfirst($state->value))
                    ->color(fn (WalletStatus $state): string => match ($state) {
                        WalletStatus::Active => 'success',
                        WalletStatus::Locked, WalletStatus::Frozen, WalletStatus::Suspended => 'danger',
                        WalletStatus::Closed => 'gray',
                        WalletStatus::Pending => 'info',
                    }),

                Tables\Columns\TextColumn::make('balance')
                    ->label('Balance')
                    ->formatStateUsing(fn ($state, Wallet $record): string => AdminFormat::money($state, is_object($record->currency) ? $record->currency->value : (string) $record->currency))
                    ->alignEnd(),

                Tables\Columns\TextColumn::make('locked_balance')
                    ->label('Locked')
                    ->formatStateUsing(fn ($state): string => AdminFormat::money($state))
                    ->alignEnd(),

                Tables\Columns\TextColumn::make('total_deposited')
                    ->label('Deposited')
                    ->formatStateUsing(fn ($state): string => AdminFormat::money($state))
                    ->alignEnd()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('total_withdrawn')
                    ->label('Withdrawn')
                    ->formatStateUsing(fn ($state): string => AdminFormat::money($state))
                    ->alignEnd()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('total_wagered')
                    ->label('Wagered')
                    ->formatStateUsing(fn ($state): string => AdminFormat::money($state))
                    ->alignEnd()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('locked_reason')
                    ->label('Lock reason')
                    ->placeholder('—')
                    ->wrap()
                    ->toggleable(),
            ])
            ->headerActions([])
            ->actions([])
            ->bulkActions([])
            ->emptyStateHeading('No wallets')
            ->emptyStateDescription('A wallet is provisioned by the finance layer, not from this screen.');
    }
}
