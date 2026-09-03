<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\BetType;
use App\Enums\LimitStatus;
use App\Filament\Resources\NumberLimitResource\Pages;
use App\Models\NumberLimit;
use App\Services\Risk\NumberNormalizationService;
use App\Support\Admin\AdminAccess;
use App\Support\Admin\AdminFormat;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * Per-number exposure ceilings — the one genuinely editable thing in this area.
 *
 * WHY THIS RESOURCE HAS CREATE AND EDIT WHEN THE OTHERS DO NOT
 * A bet is history; a number limit is *configuration*. NumberLimitEngine::reserve() locks
 * exactly one row per (draw_id, bet_type, number) and refuses a bet whose projected
 * exposure would exceed the ceiling on that row. If no row exists, assess() reports
 * "no capacity can be reserved" and the number is effectively unsellable through the risk
 * path. Someone must therefore be able to create the row, and until now that someone was
 * a developer in a tinker session. That is what this screen replaces.
 *
 * WHAT THE FORM WRITES, AND WHY EXACTLY THOSE FIELDS
 * Read from NumberLimitEngine and its collaborators, the columns split cleanly in two:
 *
 *   definition (this form writes them, they are the model's $fillable)
 *     draw_id, bet_type, number, max_amount, maximum_payout_exposure, metadata
 *
 *   consumption (the engine owns them, written with forceFill inside a row lock)
 *     current_amount, current_payout_exposure, status, exceeded_at
 *
 * The form contains only the first group. current_amount is displayed — an operator
 * raising a ceiling needs to know how much is already sold against it — but as a
 * disabled, non-dehydrated entry that cannot be submitted. Suspending a number is a
 * status change and therefore *not* offered here: ExposureType/LimitStatus are moved by
 * the engine, and a service to suspend a number by hand does not exist yet.
 *
 * VALIDATION
 * `number` is validated against the digit count the bet type implies, taken from
 * NumberNormalizationService::digitsFor() rather than from BetType::digits(). Those two
 * disagree for Tod and Run (the enum says 2 for both; configuration says 3 and 1), the
 * service documents the disagreement, and the service is the value the engine actually
 * normalises against — so the form validates against the same source the engine will.
 *
 * Both money fields are validated with a regex against a decimal string. Filament's
 * ->numeric() is never used: it routes through the intl-dependent Number helper and
 * would hand the model a float for a DECIMAL(20,2) column.
 *
 * DELETION
 * Permitted, but only for a limit nothing has been reserved against. Once
 * current_amount or current_payout_exposure is non-zero, the row is the record of a
 * liability the house is carrying; deleting it would erase the ceiling that a placed bet
 * was accepted under, and NumberLimitEngine::release() would later fail to find the row
 * it must decrement. An untouched, mistakenly created row has no such history and is safe
 * to remove.
 *
 * WHAT IS DELIBERATELY NOT DONE
 * - No "reset counters" button. Zeroing current_amount would free capacity the house has
 *   genuinely sold. Only release() may lower it, inside the caller's transaction.
 * - No bulk create of all 100 two-digit numbers. It is tempting and it is a data-loading
 *   concern; a console command can do it transactionally without a browser timeout.
 * - No deletion of a draw's limits when the draw is cancelled. The migration already
 *   cascades on draw deletion, and cancellation is not deletion.
 */
class NumberLimitResource extends Resource
{
    protected static ?string $model = NumberLimit::class;

    protected static ?string $navigationIcon = 'heroicon-o-shield-exclamation';

    protected static ?string $navigationGroup = 'Risk';

    protected static ?int $navigationSort = 1;

    protected static ?string $modelLabel = 'number limit';

    protected static ?string $recordTitleAttribute = 'number';

    /**
     * Reading a limit is part of watching risk; changing one is a risk decision.
     * Both phrases exist in the seeded catalogue, so neither is invented here.
     *
     * @var list<string>
     */
    public const READ_PERMISSIONS = [
        AdminAccess::VIEW_RISK_ALERTS,
        AdminAccess::MANAGE_RISK_LIMITS,
    ];

    public static function canViewAny(): bool
    {
        return AdminAccess::currentAny(self::READ_PERMISSIONS);
    }

    public static function canView(Model $record): bool
    {
        return static::canViewAny();
    }

    public static function canCreate(): bool
    {
        return AdminAccess::current(AdminAccess::MANAGE_RISK_LIMITS);
    }

    public static function canEdit(Model $record): bool
    {
        return AdminAccess::current(AdminAccess::MANAGE_RISK_LIMITS);
    }

    /**
     * A limit may be deleted only while it is still untouched.
     *
     * Both enforced exposure counters must read exactly zero. bccomp is used rather than
     * == because the columns are DECIMAL(20,2) and arrive as '0.00' from MySQL and '0'
     * from SQLite; a string comparison would disagree between the two drivers and a float
     * comparison is forbidden outright.
     */
    public static function canDelete(Model $record): bool
    {
        if (! AdminAccess::current(AdminAccess::MANAGE_RISK_LIMITS)) {
            return false;
        }

        return $record instanceof NumberLimit && ! static::hasReservations($record);
    }

    /**
     * Whether anything has been reserved against this limit.
     *
     * Reservation is not only stake: NumberLimitEngine::reserve() moves current_amount
     * AND current_payout_exposure together, so a row whose stake counter is zero but
     * whose payout exposure is not has still been used and must survive.
     */
    public static function hasReservations(NumberLimit $limit): bool
    {
        $stake = (string) ($limit->getAttribute('current_amount') ?? '0');
        $payout = (string) ($limit->getAttribute('current_payout_exposure') ?? '0');

        return bccomp($stake, '0', 2) !== 0 || bccomp($payout, '0', 2) !== 0;
    }

    /**
     * Bulk deletion is off even though single deletion is on: the guard above is a
     * per-record judgement, and a bulk action that silently skipped the guarded rows
     * would report "12 deleted" when it deleted three.
     */
    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function canForceDelete(Model $record): bool
    {
        return false;
    }

    public static function canForceDeleteAny(): bool
    {
        return false;
    }

    /**
     * The digit count this bet type's numbers must have.
     *
     * Configuration is authoritative (NumberNormalizationService says so, and the engine
     * normalises against it). If configuration is missing or malformed the service
     * throws; rather than break the form, the enum's own answer is used as a last resort
     * and the operator simply gets the stricter of two plausible shapes.
     */
    public static function digitsFor(BetType $type): int
    {
        try {
            return app(NumberNormalizationService::class)->digitsFor($type);
        } catch (Throwable) {
            return $type->digits();
        }
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('What this limit covers')
                ->description('The unique key (draw, bet type, number) is what makes the row lockable — NumberLimitEngine::reserve() locks exactly this row. Two limits for the same triple cannot exist.')
                ->schema([
                    Forms\Components\Select::make('draw_id')
                        ->label('Draw')
                        ->relationship('draw', 'draw_number')
                        ->searchable()
                        ->preload()
                        ->required()
                        ->native(false)
                        ->live()
                        ->helperText('number_limits.draw_id is NOT NULL, so a limit is always per draw. There is no global limit row in this schema.'),

                    Forms\Components\Select::make('bet_type')
                        ->label('Bet type')
                        ->options(fn (): array => collect(BetType::cases())
                            ->mapWithKeys(fn (BetType $type): array => [
                                $type->value => sprintf('%s (%d digit%s)', strtoupper($type->value), static::digitsFor($type), static::digitsFor($type) === 1 ? '' : 's'),
                            ])
                            ->all())
                        ->required()
                        ->native(false)
                        ->live()
                        ->afterStateUpdated(fn (Forms\Set $set) => $set('number', null))
                        ->helperText('Changing the type clears the number, because the two must agree on digit count.'),

                    Forms\Components\TextInput::make('number')
                        ->label('Number')
                        ->required()
                        ->maxLength(16)
                        ->live(onBlur: true)
                        ->rule(static function (Get $get): \Closure {
                            return static function (string $attribute, mixed $value, \Closure $fail) use ($get): void {
                                $raw = $get('bet_type');
                                $type = $raw instanceof BetType ? $raw : BetType::tryFrom((string) $raw);

                                if (! $type instanceof BetType) {
                                    $fail('Choose a bet type before entering a number.');

                                    return;
                                }

                                $digits = static::digitsFor($type);

                                if (preg_match('/^[0-9]{'.$digits.'}$/', (string) $value) !== 1) {
                                    $fail(sprintf(
                                        'A %s number must be exactly %d digit%s, zero padded — "%s" is not.',
                                        strtoupper($type->value),
                                        $digits,
                                        $digits === 1 ? '' : 's',
                                        (string) $value,
                                    ));
                                }
                            };
                        })
                        ->helperText(function (Get $get): string {
                            $raw = $get('bet_type');
                            $type = $raw instanceof BetType ? $raw : BetType::tryFrom((string) $raw);

                            if (! $type instanceof BetType) {
                                return 'Pick a bet type first; the required digit count comes from it.';
                            }

                            $digits = static::digitsFor($type);

                            return sprintf(
                                'Exactly %d digit%s, leading zeros kept (e.g. %s). Stored as a string so "%s" never becomes %d.',
                                $digits,
                                $digits === 1 ? '' : 's',
                                str_pad('7', $digits, '0', STR_PAD_LEFT),
                                str_pad('7', $digits, '0', STR_PAD_LEFT),
                                7,
                            );
                        }),
                ])
                ->columns(3),

            Forms\Components\Section::make('Ceilings')
                ->description('Exact decimal strings. Neither field is ->numeric(): these are DECIMAL(20,2) columns and a float would be a rounding bug waiting for an auditor.')
                ->schema([
                    Forms\Components\TextInput::make('max_amount')
                        ->label('Maximum accepted stake')
                        ->required()
                        ->rule('regex:/^\d{1,18}(\.\d{1,2})?$/')
                        ->rule(static function (): \Closure {
                            return static function (string $attribute, mixed $value, \Closure $fail): void {
                                $raw = trim((string) $value);

                                if (preg_match('/^\d{1,18}(\.\d{1,2})?$/', $raw) !== 1) {
                                    return; // the regex rule above already reported it
                                }

                                if (bccomp($raw, '0', 2) <= 0) {
                                    $fail('The stake ceiling must be greater than zero; the schema carries CHECK (max_amount > 0).');
                                }
                            };
                        })
                        ->validationMessages([
                            'regex' => 'Enter an exact amount such as 50000 or 50000.00 — digits and at most two decimal places.',
                        ])
                        ->helperText('The total stake the house will accept on this number in this draw.'),

                    Forms\Components\TextInput::make('maximum_payout_exposure')
                        ->label('Maximum payout exposure')
                        ->rule('regex:/^\d{1,18}(\.\d{1,2})?$/')
                        ->validationMessages([
                            'regex' => 'Enter an exact amount such as 4500000 or leave it empty to fall back to the configured ceiling.',
                        ])
                        ->helperText('Liability if this number wins. Nullable: left empty, NumberLimitResolver falls back to the configured ceiling. This is the number that actually protects the bankroll.'),

                    Forms\Components\Placeholder::make('reserved_so_far')
                        ->label('Already reserved')
                        ->content(fn (?NumberLimit $record): string => $record === null
                            ? 'Nothing yet — this limit does not exist.'
                            : sprintf(
                                'stake %s of %s · payout exposure %s',
                                AdminFormat::money($record->current_amount),
                                AdminFormat::money($record->max_amount),
                                AdminFormat::money($record->current_payout_exposure),
                            ))
                        ->helperText('Read-only, and not part of the submitted form. Only NumberLimitEngine may move these counters, inside a row lock.'),
                ])
                ->columns(3),

            Forms\Components\Section::make('Notes')
                ->collapsed()
                ->schema([
                    Forms\Components\KeyValue::make('metadata')
                        ->label('Metadata')
                        ->keyLabel('key')
                        ->valueLabel('value')
                        ->helperText('Free-form. Why this ceiling was chosen is worth writing down; nothing in the engine reads it.')
                        ->columnSpanFull(),
                ]),
        ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('draw');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('current_amount', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('draw.draw_number')
                    ->label('Draw')
                    ->searchable()
                    ->sortable()
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('bet_type')
                    ->label('Type')
                    ->badge()
                    ->formatStateUsing(fn (BetType $state): string => strtoupper($state->value))
                    ->sortable(),

                Tables\Columns\TextColumn::make('number')
                    ->label('Number')
                    ->formatStateUsing(fn ($state): string => AdminFormat::number($state))
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->copyable(),

                Tables\Columns\TextColumn::make('max_amount')
                    ->label('Stake ceiling')
                    ->formatStateUsing(fn ($state): string => AdminFormat::money($state))
                    ->alignEnd()
                    ->sortable(),

                Tables\Columns\TextColumn::make('current_amount')
                    ->label('Reserved')
                    ->formatStateUsing(fn ($state): string => AdminFormat::money($state))
                    ->alignEnd()
                    ->sortable(),

                Tables\Columns\TextColumn::make('remaining_amount')
                    ->label('Remaining')
                    ->state(fn (NumberLimit $record): string => AdminFormat::money($record->remaining_amount))
                    ->alignEnd()
                    ->color(fn (NumberLimit $record): string => bccomp((string) $record->remaining_amount, '0', 2) === 0 ? 'danger' : 'success')
                    ->tooltip('Stake ceiling minus reserved, floored at zero — bcsub at scale 2, never a float subtraction.'),

                Tables\Columns\TextColumn::make('utilisation')
                    ->label('Utilisation')
                    ->state(fn (NumberLimit $record): string => $record->utilisation_percent.'%')
                    ->alignEnd()
                    ->color(fn (NumberLimit $record): string => match (true) {
                        bccomp((string) $record->utilisation_percent, '100', 2) >= 0 => 'danger',
                        bccomp((string) $record->utilisation_percent, '80', 2) >= 0 => 'warning',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('maximum_payout_exposure')
                    ->label('Payout ceiling')
                    ->formatStateUsing(fn ($state): string => AdminFormat::money($state))
                    ->placeholder('config fallback')
                    ->alignEnd()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('current_payout_exposure')
                    ->label('Payout reserved')
                    ->formatStateUsing(fn ($state): string => AdminFormat::money($state))
                    ->alignEnd()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (LimitStatus $state): string => $state->label())
                    ->color(fn (LimitStatus $state): string => $state->color())
                    ->sortable(),

                Tables\Columns\TextColumn::make('exceeded_at')
                    ->label('Exceeded at')
                    ->formatStateUsing(fn ($state): string => AdminFormat::marketTime($state))
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('draw_id')
                    ->label('Draw')
                    ->relationship('draw', 'draw_number')
                    ->searchable()
                    ->preload(),

                Tables\Filters\SelectFilter::make('bet_type')
                    ->label('Bet type')
                    ->options(fn (): array => collect(BetType::cases())
                        ->mapWithKeys(fn (BetType $type): array => [$type->value => strtoupper($type->value)])
                        ->all())
                    ->multiple(),

                Tables\Filters\SelectFilter::make('status')
                    ->options(fn (): array => collect(LimitStatus::cases())
                        ->mapWithKeys(fn (LimitStatus $status): array => [$status->value => $status->label()])
                        ->all())
                    ->multiple(),

                Tables\Filters\Filter::make('untouched')
                    ->label('Nothing reserved yet')
                    ->query(fn (Builder $query): Builder => $query
                        ->where('current_amount', '<=', 0)
                        ->where('current_payout_exposure', '<=', 0))
                    ->toggle()
                    ->indicator('Untouched limits only'),

                Tables\Filters\Filter::make('full')
                    ->label('At or over the stake ceiling')
                    ->query(fn (Builder $query): Builder => $query->whereColumn('current_amount', '>=', 'max_amount'))
                    ->toggle(),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make()
                    // The same guard as canDelete(), stated again on the button so an
                    // operator never sees an action that would refuse them.
                    ->visible(fn (NumberLimit $record): bool => static::canDelete($record))
                    ->modalDescription('This limit has never been reserved against, so removing it erases no liability. A limit with reservations cannot be deleted at all.'),
            ])
            ->bulkActions([])
            ->emptyStateHeading('No number limits configured')
            ->emptyStateDescription('Without a row here, NumberLimitEngine::assess() reports that no capacity can be reserved for that number.');
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make('Limit')
                ->schema([
                    Infolists\Components\TextEntry::make('draw.draw_number')->label('Draw')->placeholder('—'),
                    Infolists\Components\TextEntry::make('bet_type')
                        ->label('Bet type')
                        ->badge()
                        ->formatStateUsing(fn (BetType $state): string => strtoupper($state->value)),
                    Infolists\Components\TextEntry::make('number')
                        ->label('Number')
                        ->formatStateUsing(fn ($state): string => AdminFormat::number($state))
                        ->copyable(),
                    Infolists\Components\TextEntry::make('status')
                        ->badge()
                        ->formatStateUsing(fn (LimitStatus $state): string => $state->label())
                        ->color(fn (LimitStatus $state): string => $state->color()),
                ])
                ->columns(4),

            Infolists\Components\Section::make('Utilisation')
                ->description('Reserved versus remaining, computed with bcmath at scale 2 by the model accessors — no float arithmetic touches these figures.')
                ->schema([
                    Infolists\Components\TextEntry::make('max_amount')
                        ->label('Stake ceiling')
                        ->formatStateUsing(fn ($state): string => AdminFormat::money($state, AdminFormat::currency())),
                    Infolists\Components\TextEntry::make('current_amount')
                        ->label('Reserved stake')
                        ->formatStateUsing(fn ($state): string => AdminFormat::money($state, AdminFormat::currency())),
                    Infolists\Components\TextEntry::make('remaining')
                        ->label('Remaining stake')
                        ->state(fn (NumberLimit $record): string => AdminFormat::money($record->remaining_amount, AdminFormat::currency())),
                    Infolists\Components\TextEntry::make('utilisation')
                        ->label('Utilisation')
                        ->state(fn (NumberLimit $record): string => $record->utilisation_percent.'%'),

                    Infolists\Components\TextEntry::make('maximum_payout_exposure')
                        ->label('Payout ceiling')
                        ->formatStateUsing(fn ($state): string => AdminFormat::money($state, AdminFormat::currency()))
                        ->placeholder('falls back to configuration'),
                    Infolists\Components\TextEntry::make('current_payout_exposure')
                        ->label('Reserved payout exposure')
                        ->formatStateUsing(fn ($state): string => AdminFormat::money($state, AdminFormat::currency())),
                    Infolists\Components\TextEntry::make('exceeded_at')
                        ->label('Ceiling consumed at')
                        ->formatStateUsing(fn ($state): string => AdminFormat::marketTime($state)),
                    Infolists\Components\TextEntry::make('deletable')
                        ->label('Deletable')
                        ->state(fn (NumberLimit $record): string => static::hasReservations($record)
                            ? 'No — capacity has been reserved against it'
                            : 'Yes — nothing reserved yet')
                        ->color(fn (NumberLimit $record): string => static::hasReservations($record) ? 'danger' : 'success'),
                ])
                ->columns(4),

            Infolists\Components\Section::make('Metadata')
                ->collapsed()
                ->schema([
                    Infolists\Components\KeyValueEntry::make('metadata')->label(''),
                ]),
        ]);
    }

    /**
     * @return array<string, \Filament\Resources\Pages\PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListNumberLimits::route('/'),
            'create' => Pages\CreateNumberLimit::route('/create'),
            'edit' => Pages\EditNumberLimit::route('/{record}/edit'),
        ];
    }
}
