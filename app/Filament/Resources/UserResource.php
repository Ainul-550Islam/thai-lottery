<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\UserStatus;
use App\Filament\Resources\UserResource\Pages;
use App\Filament\Resources\UserResource\UserAccountActions;
use App\Models\User;
use App\Support\Admin\AdminAccess;
use App\Support\Admin\AdminFormat;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;

/**
 * People, and the two things an operator does to them: fix a profile, change access.
 *
 * WHY THE FORM AND THE ACTIONS ARE SPLIT
 * The edit form contains identity fields and nothing else — name, email, username, phone
 * and an optional new password. It cannot change a status and it cannot change a role.
 * Those are decisions, not corrections: they need a reason, a confirmation and an audit
 * line, and a <select> next to a phone number gives none of the three. They live in
 * UserAccountActions, which is also where the (documented) absence of a user-moderation
 * service is dealt with.
 *
 * PASSWORDS
 * User::casts() declares `'password' => 'hashed'`, so Eloquent hashes on assignment and
 * this resource must NOT call Hash::make itself — doing so would double-hash and lock the
 * account out. The field is optional, is never populated from the stored hash (EditUser
 * strips it before filling) and is dropped from the payload entirely when left blank, so
 * saving a name change cannot silently reset someone's password to an empty string.
 *
 * WHAT IS DELIBERATELY NOT DONE
 * - No delete, hard or soft. A user is the owner of wallets, bets, deposits, withdrawals
 *   and ledger entries; the financial tables RESTRICT on delete precisely so that history
 *   cannot be erased, and offering the button would only produce a foreign key error.
 * - No creating users from the panel. Registration is an application flow with email
 *   verification and wallet provisioning attached; a hand-made row would skip both and
 *   arrive with no wallet.
 * - No impersonation, no session revocation, no per-user permission grants. Each needs a
 *   service that does not exist yet, and faking them here would be worse than their
 *   absence.
 * - No wallet or bet editing. Both relation managers are read-only views onto records the
 *   finance and betting services own.
 */
class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $navigationIcon = 'heroicon-o-users';

    protected static ?string $navigationGroup = 'People';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'username';

    public static function canViewAny(): bool
    {
        return AdminAccess::current(AdminAccess::MANAGE_USERS);
    }

    public static function canView(Model $record): bool
    {
        return static::canViewAny();
    }

    public static function canCreate(): bool
    {
        // Accounts are created by registration, which also provisions a wallet and starts
        // email verification. A panel-made row would have neither.
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return AdminAccess::current(AdminAccess::MANAGE_USERS);
    }

    public static function canDelete(Model $record): bool
    {
        // Users own financial history. Deletion is never offered; moderation is the
        // supported path and it is reversible.
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    /**
     * Soft-deleted users stay out of the list by default, but the trashed filter can pull
     * them back so that "where did that account go?" has an answer.
     *
     * @return Builder<User>
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with('roles')
            ->withoutGlobalScopes([SoftDeletingScope::class])
            ->whereNull('deleted_at');
    }

    /**
     * @return array<int, string>
     */
    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'username', 'email', 'phone'];
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Profile')
                ->description('Identity fields only. Status and roles are operator actions with a reason and an audit line, not form fields — you will find them on the view screen.')
                ->schema([
                    Forms\Components\TextInput::make('name')
                        ->label('Full name')
                        ->required()
                        ->maxLength(255),

                    Forms\Components\TextInput::make('username')
                        ->label('Username')
                        ->required()
                        ->maxLength(255)
                        ->unique(ignoreRecord: true)
                        ->helperText('Unique. It appears on every bet and ledger line this account produces.'),

                    Forms\Components\TextInput::make('email')
                        ->label('Email')
                        ->email()
                        ->required()
                        ->maxLength(255)
                        ->unique(ignoreRecord: true)
                        ->helperText('Changing this does NOT re-open email verification; the existing verification timestamp stands.'),

                    Forms\Components\TextInput::make('phone')
                        ->label('Phone')
                        ->tel()
                        ->maxLength(255)
                        ->unique(ignoreRecord: true)
                        ->helperText('Optional, but unique when set.'),
                ])
                ->columns(2),

            Forms\Components\Section::make('Password')
                ->description('Leave blank to keep the current password. This field is never pre-filled with the stored hash, and a blank value is discarded rather than saved.')
                ->schema([
                    Forms\Components\TextInput::make('password')
                        ->label('Set a new password')
                        ->password()
                        ->revealable()
                        ->minLength(8)
                        ->maxLength(255)
                        // Hashing is User::casts()['password' => 'hashed']. Calling
                        // Hash::make here as well would hash the hash.
                        ->dehydrated(fn (?string $state): bool => filled($state))
                        ->autocomplete('new-password')
                        ->helperText('Minimum 8 characters. The account holder is not notified — tell them out of band.'),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('username')
                    ->label('Username')
                    ->searchable()
                    ->sortable()
                    ->copyable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('name')
                    ->label('Name')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('email')
                    ->label('Email')
                    ->searchable()
                    ->copyable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('phone')
                    ->label('Phone')
                    ->searchable()
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (UserStatus $state): string => $state->label())
                    ->color(fn (UserStatus $state): string => match ($state) {
                        UserStatus::Active => 'success',
                        UserStatus::Suspended => 'warning',
                        UserStatus::Banned => 'danger',
                        UserStatus::PendingVerification => 'info',
                        UserStatus::Inactive => 'gray',
                    })
                    ->sortable(),

                Tables\Columns\TextColumn::make('roles.name')
                    ->label('Roles')
                    ->badge()
                    ->placeholder('none')
                    ->color(fn (string $state): string => $state === 'super-admin' ? 'danger' : 'gray'),

                Tables\Columns\TextColumn::make('email_verified_at')
                    ->label('Email verified')
                    ->formatStateUsing(fn ($state): string => AdminFormat::marketTime($state))
                    ->placeholder('not verified')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('last_login_at')
                    ->label('Last login')
                    ->formatStateUsing(fn ($state): string => AdminFormat::marketTime($state))
                    ->placeholder('never')
                    ->sortable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Registered')
                    ->formatStateUsing(fn ($state): string => AdminFormat::marketTime($state))
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options(fn (): array => collect(UserStatus::cases())
                        ->mapWithKeys(fn (UserStatus $status): array => [$status->value => $status->label()])
                        ->all())
                    ->multiple(),

                Tables\Filters\SelectFilter::make('roles')
                    ->relationship('roles', 'name')
                    ->multiple()
                    ->preload()
                    ->label('Role'),

                Tables\Filters\Filter::make('unverified_email')
                    ->label('Email not verified')
                    ->query(fn (Builder $query): Builder => $query->whereNull('email_verified_at'))
                    ->toggle(),

                Tables\Filters\Filter::make('never_logged_in')
                    ->label('Never logged in')
                    ->query(fn (Builder $query): Builder => $query->whereNull('last_login_at'))
                    ->toggle(),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
                Tables\Actions\ActionGroup::make(UserAccountActions::forTable())
                    ->label('Account')
                    ->icon('heroicon-m-shield-check')
                    ->button()
                    ->outlined(),
            ])
            ->bulkActions([])
            ->emptyStateHeading('No accounts')
            ->emptyStateDescription('Accounts arrive through registration; the panel does not create them.');
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make('Identity')
                ->schema([
                    Infolists\Components\TextEntry::make('username')->label('Username')->copyable(),
                    Infolists\Components\TextEntry::make('name')->label('Full name'),
                    Infolists\Components\TextEntry::make('email')->label('Email')->copyable(),
                    Infolists\Components\TextEntry::make('phone')->label('Phone')->placeholder('—'),
                ])
                ->columns(4),

            Infolists\Components\Section::make('Access')
                ->description('Status and roles are changed with the header actions above, never by editing.')
                ->schema([
                    Infolists\Components\TextEntry::make('status')
                        ->badge()
                        ->formatStateUsing(fn (UserStatus $state): string => $state->label())
                        ->color(fn (UserStatus $state): string => match ($state) {
                            UserStatus::Active => 'success',
                            UserStatus::Suspended => 'warning',
                            UserStatus::Banned => 'danger',
                            UserStatus::PendingVerification => 'info',
                            UserStatus::Inactive => 'gray',
                        }),

                    Infolists\Components\TextEntry::make('can_login')
                        ->label('May sign in')
                        ->state(fn (User $record): string => $record->status->canLogin() ? 'yes' : 'no')
                        ->badge()
                        ->color(fn (User $record): string => $record->status->canLogin() ? 'success' : 'danger'),

                    Infolists\Components\TextEntry::make('can_transact')
                        ->label('May transact')
                        ->state(fn (User $record): string => $record->status->canTransact() ? 'yes' : 'no')
                        ->badge()
                        ->color(fn (User $record): string => $record->status->canTransact() ? 'success' : 'danger'),

                    Infolists\Components\TextEntry::make('roles.name')
                        ->label('Roles')
                        ->badge()
                        ->placeholder('none'),

                    Infolists\Components\TextEntry::make('available_transitions')
                        ->label('Account changes available to you')
                        ->state(function (User $record): string {
                            $offered = array_values(array_filter(
                                array_keys(UserAccountActions::TRANSITIONS),
                                fn (string $name): bool => UserAccountActions::canOffer($name, $record),
                            ));

                            return $offered === [] ? 'none' : implode(', ', $offered);
                        })
                        ->columnSpanFull(),
                ])
                ->columns(4),

            Infolists\Components\Section::make('Verification and sign-in ('.AdminFormat::timezoneLabel().' time)')
                ->schema([
                    Infolists\Components\TextEntry::make('email_verified_at')
                        ->label('Email verified')
                        ->formatStateUsing(fn ($state): string => AdminFormat::marketTime($state))
                        ->placeholder('not verified'),
                    Infolists\Components\TextEntry::make('phone_verified_at')
                        ->label('Phone verified')
                        ->formatStateUsing(fn ($state): string => AdminFormat::marketTime($state))
                        ->placeholder('not verified'),
                    Infolists\Components\TextEntry::make('last_login_at')
                        ->label('Last login')
                        ->formatStateUsing(fn ($state): string => AdminFormat::marketTime($state))
                        ->placeholder('never'),
                    Infolists\Components\TextEntry::make('last_login_ip')
                        ->label('Last login IP')
                        ->placeholder('—'),
                    Infolists\Components\TextEntry::make('created_at')
                        ->label('Registered')
                        ->formatStateUsing(fn ($state): string => AdminFormat::marketTime($state)),
                    Infolists\Components\TextEntry::make('updated_at')
                        ->label('Profile last changed')
                        ->formatStateUsing(fn ($state): string => AdminFormat::marketTime($state)),
                ])
                ->columns(3),
        ]);
    }

    /**
     * @return array<int, class-string>
     */
    public static function getRelations(): array
    {
        return [
            UserResource\RelationManagers\WalletsRelationManager::class,
            UserResource\RelationManagers\BetsRelationManager::class,
        ];
    }

    /**
     * @return array<string, \Filament\Resources\Pages\PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListUsers::route('/'),
            'view' => Pages\ViewUser::route('/{record}'),
            'edit' => Pages\EditUser::route('/{record}/edit'),
        ];
    }
}
