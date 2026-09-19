<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\AgentStatus;
use App\Filament\Resources\AgentResource\Pages;
use App\Models\Agent;
use App\Support\Admin\AdminAccess;
use App\Support\Admin\AdminFormat;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The agent register — a read-only window, on purpose.
 *
 * WHY NOTHING HERE IS WRITABLE
 * An agent row is mostly accounting: commission_rate prices every referral,
 * total_commission_earned and total_commission_paid are running money totals, and
 * total_referrals is a count derived from other tables. app/Services contains Betting,
 * Draw, Finance and Risk — there is NO agent or commission service anywhere in it, and
 * AgentCommission rows are the only record of how those totals were reached.
 *
 * That leaves two options. Either the panel invents commission logic (recomputing a rate,
 * editing an earned total, approving an agent and back-filling approved_at) or it shows
 * what the domain recorded and offers no button it cannot honour. The second is the only
 * defensible one: a hand-edited total_commission_earned is indistinguishable from a
 * correct one afterwards, and changing commission_rate on a live agent silently reprices
 * work already done without touching the AgentCommission rows that priced it. So this
 * resource displays and never computes, never edits and never approves. The absence of an
 * agent lifecycle service is reported as a gap rather than papered over here.
 *
 * MONEY
 * Every amount is a decimal string straight from the column, rendered with
 * AdminFormat::money. No float, no rounding, no arithmetic — the panel does not even add
 * earned and paid together to show an outstanding balance, because "outstanding
 * commission" is a domain definition (does a reversed commission count? a cancelled one?)
 * and inventing it in a table column would be inventing an accounting rule.
 *
 * WHAT IS DELIBERATELY NOT DONE
 * - No approve / suspend / terminate actions. AgentStatus has four cases and no
 *   transition graph, and unlike users there is no operational emergency that justifies
 *   the panel writing the column itself: nobody is being harmed by an agent whose status
 *   can only be changed by the flow that set it.
 * - No commission payout. Paying an agent moves money and needs WalletService and a
 *   ledger posting; there is no service that does it and the panel will not be the first.
 * - No delete. Commission history is financial history.
 */
class AgentResource extends Resource
{
    protected static ?string $model = Agent::class;

    protected static ?string $navigationIcon = 'heroicon-o-user-group';

    protected static ?string $navigationGroup = 'People';

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'agent_code';

    public static function canViewAny(): bool
    {
        return AdminAccess::current(AdminAccess::MANAGE_AGENTS);
    }

    public static function canView(Model $record): bool
    {
        return static::canViewAny();
    }

    public static function canCreate(): bool
    {
        // An agent is created by the (unbuilt) agent onboarding flow, which allocates the
        // agent_code and sets the commission rate from an agreement.
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        // Read-only: see the class comment. Every writable column here is either money or
        // the rate that prices it.
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

    /**
     * Whether an operator may see the money columns at all.
     *
     * MANAGE_AGENTS gets you the register; VIEW_COMMISSIONS gets you the earnings. They
     * are separate permissions in the seeded catalogue and this resource keeps them
     * separate rather than treating one as implying the other.
     */
    public static function canViewCommissions(): bool
    {
        return AdminAccess::current(AdminAccess::VIEW_COMMISSIONS);
    }

    /**
     * @return Builder<Agent>
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['user', 'parent']);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('agent_code')
            ->columns([
                Tables\Columns\TextColumn::make('agent_code')
                    ->label('Code')
                    ->searchable()
                    ->sortable()
                    ->copyable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('user.username')
                    ->label('Account')
                    ->searchable()
                    ->url(fn (Agent $record): ?string => $record->user_id !== null && UserResource::canViewAny()
                        ? UserResource::getUrl('view', ['record' => $record->user_id])
                        : null),

                Tables\Columns\TextColumn::make('user.name')
                    ->label('Name')
                    ->searchable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (AgentStatus $state): string => $state->label())
                    ->color(fn (AgentStatus $state): string => match ($state) {
                        AgentStatus::Active => 'success',
                        AgentStatus::Suspended => 'warning',
                        AgentStatus::Terminated => 'danger',
                        AgentStatus::Inactive => 'gray',
                    })
                    ->sortable(),

                Tables\Columns\TextColumn::make('parent.agent_code')
                    ->label('Upline')
                    ->placeholder('—')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('total_referrals')
                    ->label('Referrals')
                    ->formatStateUsing(fn ($state): string => AdminFormat::count($state))
                    ->alignEnd()
                    ->sortable(),

                // Commission columns. Each one is separately gated: an operator with
                // MANAGE_AGENTS but not VIEW_COMMISSIONS administers the register without
                // seeing what anyone earns.
                Tables\Columns\TextColumn::make('commission_rate')
                    ->label('Rate')
                    ->formatStateUsing(fn ($state): string => AdminFormat::number($state))
                    ->alignEnd()
                    ->sortable()
                    ->visible(fn (): bool => static::canViewCommissions())
                    ->tooltip('Stored as a decimal(8,4) fraction and shown exactly as stored. The panel does not convert it to a percentage, because whether it multiplies stake or net revenue is a domain question.'),

                Tables\Columns\TextColumn::make('total_commission_earned')
                    ->label('Earned')
                    ->formatStateUsing(fn ($state, Agent $record): string => AdminFormat::money($state, static::currencyOf($record)))
                    ->alignEnd()
                    ->sortable()
                    ->visible(fn (): bool => static::canViewCommissions()),

                Tables\Columns\TextColumn::make('total_commission_paid')
                    ->label('Paid')
                    ->formatStateUsing(fn ($state, Agent $record): string => AdminFormat::money($state, static::currencyOf($record)))
                    ->alignEnd()
                    ->sortable()
                    ->visible(fn (): bool => static::canViewCommissions()),

                Tables\Columns\TextColumn::make('approved_at')
                    ->label('Approved')
                    ->formatStateUsing(fn ($state): string => AdminFormat::marketTime($state))
                    ->placeholder('not approved')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Registered')
                    ->formatStateUsing(fn ($state): string => AdminFormat::marketTime($state))
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options(fn (): array => collect(AgentStatus::cases())
                        ->mapWithKeys(fn (AgentStatus $status): array => [$status->value => $status->label()])
                        ->all())
                    ->multiple(),

                Tables\Filters\Filter::make('suspended_or_terminated')
                    ->label('Not operating')
                    ->query(fn (Builder $query): Builder => $query->whereIn('status', [
                        AgentStatus::Suspended->value,
                        AgentStatus::Terminated->value,
                    ]))
                    ->toggle(),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
            ])
            ->bulkActions([])
            ->emptyStateHeading('No agents')
            ->emptyStateDescription('Agents are onboarded by the agent flow; this register only reports them.');
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make('Agent')
                ->schema([
                    Infolists\Components\TextEntry::make('agent_code')->label('Code')->copyable(),
                    Infolists\Components\TextEntry::make('user.username')->label('Account')->placeholder('—'),
                    Infolists\Components\TextEntry::make('user.email')->label('Email')->placeholder('—'),
                    Infolists\Components\TextEntry::make('status')
                        ->badge()
                        ->formatStateUsing(fn (AgentStatus $state): string => $state->label())
                        ->color(fn (AgentStatus $state): string => match ($state) {
                            AgentStatus::Active => 'success',
                            AgentStatus::Suspended => 'warning',
                            AgentStatus::Terminated => 'danger',
                            AgentStatus::Inactive => 'gray',
                        }),
                    Infolists\Components\TextEntry::make('parent.agent_code')->label('Upline agent')->placeholder('none — top of tree'),
                    Infolists\Components\TextEntry::make('total_referrals')
                        ->label('Referrals')
                        ->formatStateUsing(fn ($state): string => AdminFormat::count($state)),
                ])
                ->columns(3),

            Infolists\Components\Section::make('Commission')
                ->description('Recorded by the domain, displayed here. Nothing on this screen recomputes a rate or a total.')
                ->visible(fn (): bool => static::canViewCommissions())
                ->schema([
                    Infolists\Components\TextEntry::make('commission_rate')
                        ->label('Rate (as stored)')
                        ->formatStateUsing(fn ($state): string => AdminFormat::number($state)),
                    Infolists\Components\TextEntry::make('currency')
                        ->label('Currency')
                        ->formatStateUsing(fn ($state): string => is_object($state) ? (string) $state->value : (string) $state),
                    Infolists\Components\TextEntry::make('total_commission_earned')
                        ->label('Total earned')
                        ->formatStateUsing(fn ($state, Agent $record): string => AdminFormat::money($state, static::currencyOf($record))),
                    Infolists\Components\TextEntry::make('total_commission_paid')
                        ->label('Total paid')
                        ->formatStateUsing(fn ($state, Agent $record): string => AdminFormat::money($state, static::currencyOf($record))),
                ])
                ->columns(4),

            Infolists\Components\Section::make('Lifecycle ('.AdminFormat::timezoneLabel().' time)')
                ->schema([
                    Infolists\Components\TextEntry::make('approved_at')
                        ->label('Approved')
                        ->formatStateUsing(fn ($state): string => AdminFormat::marketTime($state))
                        ->placeholder('not approved'),
                    Infolists\Components\TextEntry::make('suspended_at')
                        ->label('Suspended')
                        ->formatStateUsing(fn ($state): string => AdminFormat::marketTime($state))
                        ->placeholder('—'),
                    Infolists\Components\TextEntry::make('suspension_reason')
                        ->label('Suspension reason')
                        ->placeholder('—')
                        ->columnSpan(2),
                    Infolists\Components\TextEntry::make('created_at')
                        ->label('Registered')
                        ->formatStateUsing(fn ($state): string => AdminFormat::marketTime($state)),
                    Infolists\Components\TextEntry::make('updated_at')
                        ->label('Last changed')
                        ->formatStateUsing(fn ($state): string => AdminFormat::marketTime($state)),
                ])
                ->columns(4),
        ]);
    }

    /**
     * The agent's own currency, read from the row rather than assumed to be the platform
     * default — an agent register can legitimately hold agents in THB, USD and BDT.
     */
    private static function currencyOf(Agent $record): string
    {
        $currency = $record->currency;

        return is_object($currency) ? (string) $currency->value : (string) $currency;
    }

    /**
     * @return array<string, \Filament\Resources\Pages\PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAgents::route('/'),
            'view' => Pages\ViewAgent::route('/{record}'),
        ];
    }
}
