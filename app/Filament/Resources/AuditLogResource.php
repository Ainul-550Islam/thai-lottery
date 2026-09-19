<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\AuditAction;
use App\Enums\RiskLevel;
use App\Filament\Resources\AuditLogResource\Pages;
use App\Models\AuditLog;
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
 * The audit trail. Readable by anyone with the permission, writable by no one.
 *
 * WHY EVERY WRITE PATH IS CLOSED
 * An audit log an operator can edit is not an audit log — it is a note-taking app that
 * happens to record logins. The value of the trail is entirely in the guarantee that what
 * it says happened is what happened, so the guarantee has to survive the most privileged
 * user in the system. canCreate, canEdit, canDelete and canDeleteAny all return an
 * unconditional false here, with no permission check in front of them: there is no
 * permission that unlocks them, including super-admin's blanket bypass in AdminAccess.
 * That is asserted in tests, because a guarantee nobody checks is a comment.
 *
 * The storage layer agrees: the migration gives audit_logs a created_at and nothing else
 * — no updated_at, no deleted_at — and App\Models\AuditLog sets UPDATED_AT = null and
 * omits SoftDeletes. This resource is the third lock on the same door.
 *
 * WHY THE COLUMN NAMES LOOK LIKE THIS
 * Checked against the model and the migration rather than assumed: the actor is `user_id`
 * (nullable — a system action has none, which is why isSystemAction() exists), the
 * subject is the nullable morph pair `auditable_type` / `auditable_id`, the payload is
 * the two JSON columns `old_values` and `new_values`, and correlation is `request_id`, a
 * uuid shared by every row written during one request. There is no `changes` column and
 * no `subject_id`; guessing either would have produced an empty screen.
 *
 * WHAT IS DELIBERATELY NOT DONE
 * - No export. A CSV of the audit trail is a data-exfiltration surface and DataExport is
 *   itself an audited action; building it needs a decision about who may take the file
 *   away, which is not this phase's to make.
 * - No redaction or masking here. The audit service is responsible for stripping the keys
 *   listed in config('security.audit.sensitive_fields') before the row is written; a
 *   viewer that redacted at display time would imply the secret is in the database, which
 *   is exactly the thing the write-side rule prevents.
 * - No retention or purge tooling. Deleting audit rows on a schedule is a compliance
 *   decision with a legal answer, not a button.
 */
class AuditLogResource extends Resource
{
    protected static ?string $model = AuditLog::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?string $navigationGroup = 'Compliance';

    protected static ?int $navigationSort = 1;

    protected static ?string $modelLabel = 'audit log';

    protected static ?string $pluralModelLabel = 'audit logs';

    protected static ?string $recordTitleAttribute = 'description';

    public static function canViewAny(): bool
    {
        return AdminAccess::current(AdminAccess::VIEW_AUDIT_LOGS);
    }

    public static function canView(Model $record): bool
    {
        return static::canViewAny();
    }

    /**
     * Rows are written by the application, never by a person. No form exists to make one.
     */
    public static function canCreate(): bool
    {
        return false;
    }

    /**
     * Unconditionally false, for everyone, including super-admin. See the class comment.
     */
    public static function canEdit(Model $record): bool
    {
        return false;
    }

    /**
     * Unconditionally false, for everyone, including super-admin. See the class comment.
     */
    public static function canDelete(Model $record): bool
    {
        return false;
    }

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

    public static function canRestore(Model $record): bool
    {
        return false;
    }

    public static function canRestoreAny(): bool
    {
        return false;
    }

    /**
     * @return Builder<AuditLog>
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('user');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label('When ('.AdminFormat::timezoneLabel().')')
                    ->formatStateUsing(fn ($state): string => AdminFormat::marketTime($state))
                    ->description(fn (AuditLog $record): string => $record->created_at?->diffForHumans() ?? '')
                    ->sortable(),

                Tables\Columns\TextColumn::make('action')
                    ->badge()
                    ->formatStateUsing(fn (AuditAction $state): string => $state->label())
                    ->color(fn (AuditAction $state): string => match ($state->severity()) {
                        'high' => 'danger',
                        'medium' => 'warning',
                        default => 'gray',
                    })
                    ->description(fn (AuditLog $record): string => $record->action->category())
                    ->sortable(),

                Tables\Columns\TextColumn::make('risk_level')
                    ->label('Risk')
                    ->badge()
                    ->placeholder('—')
                    ->formatStateUsing(fn (?RiskLevel $state): string => $state?->value === null ? '—' : ucfirst($state->value))
                    ->color(fn (?RiskLevel $state): string => match ($state) {
                        RiskLevel::Critical, RiskLevel::High => 'danger',
                        RiskLevel::Medium => 'warning',
                        RiskLevel::Low => 'success',
                        default => 'gray',
                    })
                    ->sortable(),

                Tables\Columns\TextColumn::make('user.username')
                    ->label('Actor')
                    ->placeholder('system')
                    ->description(fn (AuditLog $record): ?string => $record->isSystemAction() ? 'no signed-in user' : $record->user?->name)
                    ->searchable(),

                Tables\Columns\TextColumn::make('auditable_type')
                    ->label('Subject')
                    ->formatStateUsing(fn (?string $state): string => static::shortType($state))
                    ->description(fn (AuditLog $record): ?string => $record->auditable_id === null ? null : '#'.$record->auditable_id)
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('description')
                    ->label('Description')
                    ->wrap()
                    ->limit(80)
                    ->searchable()
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('changed_fields')
                    ->label('Fields changed')
                    ->state(fn (AuditLog $record): string => static::changedFieldSummary($record))
                    ->placeholder('—')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('ip_address')
                    ->label('IP')
                    ->placeholder('—')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('request_id')
                    ->label('Request')
                    ->copyable()
                    ->limit(12)
                    ->placeholder('—')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('user_id')
                    ->label('Actor')
                    ->relationship('user', 'username')
                    ->searchable()
                    ->preload()
                    ->multiple(),

                Tables\Filters\SelectFilter::make('action')
                    ->label('Action')
                    ->options(fn (): array => collect(AuditAction::cases())
                        ->mapWithKeys(fn (AuditAction $action): array => [$action->value => $action->label()])
                        ->all())
                    ->multiple(),

                Tables\Filters\SelectFilter::make('risk_level')
                    ->label('Risk level')
                    ->options(fn (): array => collect(RiskLevel::cases())
                        ->mapWithKeys(fn (RiskLevel $level): array => [$level->value => ucfirst($level->value)])
                        ->all())
                    ->multiple(),

                Tables\Filters\SelectFilter::make('auditable_type')
                    ->label('Subject type')
                    // Built from the values actually present rather than from a hard-coded
                    // model list, so a new audited model appears in the filter the first
                    // time it is audited.
                    ->options(fn (): array => AuditLog::query()
                        ->whereNotNull('auditable_type')
                        ->distinct()
                        ->orderBy('auditable_type')
                        ->pluck('auditable_type', 'auditable_type')
                        ->map(fn (string $type): string => static::shortType($type))
                        ->all())
                    ->multiple(),

                Tables\Filters\Filter::make('system_only')
                    ->label('System actions only')
                    ->query(fn (Builder $query): Builder => $query->whereNull('user_id'))
                    ->toggle(),

                Tables\Filters\Filter::make('high_risk')
                    ->label('High risk and above')
                    ->query(fn (Builder $query): Builder => $query->whereIn('risk_level', [
                        RiskLevel::High->value,
                        RiskLevel::Critical->value,
                    ]))
                    ->toggle(),

                Tables\Filters\Filter::make('created_at')
                    ->label('Date range')
                    ->form([
                        \Filament\Forms\Components\DatePicker::make('from')
                            ->label('From')
                            ->native(false),
                        \Filament\Forms\Components\DatePicker::make('until')
                            ->label('Until')
                            ->native(false),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $q, $date): Builder => $q->whereDate('created_at', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $q, $date): Builder => $q->whereDate('created_at', '<=', $date)))
                    ->indicateUsing(function (array $data): array {
                        $indicators = [];

                        if (filled($data['from'] ?? null)) {
                            $indicators[] = 'From '.$data['from'];
                        }

                        if (filled($data['until'] ?? null)) {
                            $indicators[] = 'Until '.$data['until'];
                        }

                        return $indicators;
                    }),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
            ])
            // No bulk actions at all: the only bulk action Filament offers by default is
            // delete, and there is nothing to bulk-do to an append-only table.
            ->bulkActions([])
            ->emptyStateHeading('No audit entries match')
            ->emptyStateDescription('Rows are written by the application as events happen; nothing here creates them.');
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make('Event')
                ->schema([
                    Infolists\Components\TextEntry::make('created_at')
                        ->label('When ('.AdminFormat::timezoneLabel().')')
                        ->formatStateUsing(fn ($state): string => AdminFormat::marketTime($state)),
                    Infolists\Components\TextEntry::make('action')
                        ->badge()
                        ->formatStateUsing(fn (AuditAction $state): string => $state->label()),
                    Infolists\Components\TextEntry::make('action_category')
                        ->label('Category')
                        ->state(fn (AuditLog $record): string => $record->action->category()),
                    Infolists\Components\TextEntry::make('risk_level')
                        ->label('Risk level')
                        ->badge()
                        ->placeholder('not classified')
                        ->formatStateUsing(fn (?RiskLevel $state): string => $state === null ? '—' : ucfirst($state->value)),
                    Infolists\Components\TextEntry::make('description')
                        ->label('Description')
                        ->placeholder('—')
                        ->columnSpanFull(),
                ])
                ->columns(4),

            Infolists\Components\Section::make('Actor and subject')
                ->schema([
                    Infolists\Components\TextEntry::make('user.username')
                        ->label('Actor')
                        ->placeholder('system (no signed-in user)'),
                    Infolists\Components\TextEntry::make('user.email')
                        ->label('Actor email')
                        ->placeholder('—'),
                    Infolists\Components\TextEntry::make('auditable_type')
                        ->label('Subject type')
                        ->formatStateUsing(fn (?string $state): string => static::shortType($state))
                        ->placeholder('—'),
                    Infolists\Components\TextEntry::make('auditable_id')
                        ->label('Subject id')
                        ->placeholder('—'),
                ])
                ->columns(4),

            Infolists\Components\Section::make('What changed')
                ->description('Only the keys whose value actually differs between old_values and new_values. A key added by the change shows "(absent)" on the left; a key removed shows it on the right.')
                ->schema([
                    Infolists\Components\TextEntry::make('diff')
                        ->hiddenLabel()
                        ->state(fn (AuditLog $record): array => static::diffLines($record))
                        ->listWithLineBreaks()
                        ->bulleted()
                        ->placeholder('No field-level difference recorded for this entry.')
                        ->columnSpanFull(),
                ]),

            Infolists\Components\Section::make('Recorded payload')
                ->description('The two JSON columns exactly as stored. Nested structures are shown as JSON rather than flattened, so nothing is lost in the rendering.')
                ->collapsed()
                ->schema([
                    Infolists\Components\KeyValueEntry::make('old_values')
                        ->label('Before')
                        ->keyLabel('field')
                        ->valueLabel('value')
                        ->state(fn (AuditLog $record): array => static::flatten($record->old_values))
                        ->placeholder('—'),

                    Infolists\Components\KeyValueEntry::make('new_values')
                        ->label('After')
                        ->keyLabel('field')
                        ->valueLabel('value')
                        ->state(fn (AuditLog $record): array => static::flatten($record->new_values))
                        ->placeholder('—'),
                ])
                ->columns(2),

            Infolists\Components\Section::make('Request context')
                ->description('Where the action came from. request_id correlates every row written during the same request or job.')
                ->collapsed()
                ->schema([
                    Infolists\Components\TextEntry::make('ip_address')->label('IP address')->placeholder('—'),
                    Infolists\Components\TextEntry::make('method')->label('HTTP method')->placeholder('—'),
                    Infolists\Components\TextEntry::make('url')->label('URL')->placeholder('—')->columnSpan(2),
                    Infolists\Components\TextEntry::make('user_agent')->label('User agent')->placeholder('—')->columnSpanFull(),
                    Infolists\Components\TextEntry::make('request_id')->label('Request id')->copyable()->placeholder('—'),
                    Infolists\Components\KeyValueEntry::make('metadata')
                        ->label('Metadata')
                        ->keyLabel('key')
                        ->valueLabel('value')
                        ->state(fn (AuditLog $record): array => static::flatten($record->metadata))
                        ->columnSpanFull(),
                ])
                ->columns(4),
        ]);
    }

    /**
     * "App\Models\User" -> "User". The FQCN is stored (and filtered on); the operator
     * reading the screen does not need the namespace.
     */
    private static function shortType(?string $type): string
    {
        if ($type === null || $type === '') {
            return '—';
        }

        return class_basename($type);
    }

    /**
     * A one-line "which fields moved" summary for the table.
     */
    private static function changedFieldSummary(AuditLog $record): string
    {
        $keys = array_keys(static::changedKeys($record));

        if ($keys === []) {
            return '—';
        }

        return implode(', ', array_slice($keys, 0, 4)).(count($keys) > 4 ? ' +'.(count($keys) - 4).' more' : '');
    }

    /**
     * The keys that genuinely differ, each mapped to its before/after pair.
     *
     * Compared with a strict !== on the *rendered* scalar so that a nested array that did
     * not change is not reported as a change just because PHP re-ordered it.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    private static function changedKeys(AuditLog $record): array
    {
        $old = static::flatten($record->old_values);
        $new = static::flatten($record->new_values);

        $changed = [];

        foreach (array_unique([...array_keys($old), ...array_keys($new)]) as $key) {
            $before = $old[$key] ?? '(absent)';
            $after = $new[$key] ?? '(absent)';

            if ($before !== $after) {
                $changed[$key] = [$before, $after];
            }
        }

        ksort($changed);

        return $changed;
    }

    /**
     * The diff as readable lines: `field: before → after`.
     *
     * @return list<string>
     */
    private static function diffLines(AuditLog $record): array
    {
        return array_map(
            static fn (string $key, array $pair): string => sprintf('%s: %s → %s', $key, $pair[0], $pair[1]),
            array_keys(static::changedKeys($record)),
            array_values(static::changedKeys($record)),
        );
    }

    /**
     * A JSON column as a flat map of string keys to string values.
     *
     * KeyValueEntry can only render scalars, and the payload columns can legitimately
     * contain nested arrays (a metadata block, an authorization snapshot). Rather than
     * drop them, nested values are JSON-encoded, which keeps the entry readable and keeps
     * the information on screen. Nothing is truncated: an audit viewer that hides part of
     * the record is worse than one that is occasionally ugly.
     *
     * @return array<string, string>
     */
    private static function flatten(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        $flat = [];

        foreach ($values as $key => $value) {
            $flat[(string) $key] = match (true) {
                $value === null => 'null',
                is_bool($value) => $value ? 'true' : 'false',
                is_scalar($value) => (string) $value,
                default => (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            };
        }

        return $flat;
    }

    /**
     * @return array<string, \Filament\Resources\Pages\PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAuditLogs::route('/'),
            'view' => Pages\ViewAuditLog::route('/{record}'),
        ];
    }
}
