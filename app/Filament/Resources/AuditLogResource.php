<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\AuditAction;
use App\Enums\RiskLevel;
use App\Filament\Resources\AuditLogResource\Pages;
use App\Models\AuditLog;
use App\Support\Admin\AdminAccess;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Filament Admin Resource for Immutable Security & Financial Audit Trails.
 */
class AuditLogResource extends Resource
{
    protected static ?string $model = AuditLog::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?string $navigationGroup = 'Security & Audit';

    protected static ?int $navigationSort = 1;

    public static function shouldRegisterNavigation(): bool
    {
        return AdminAccess::current(AdminAccess::VIEW_USERS);
    }

    public static function canViewAny(): bool
    {
        return AdminAccess::current(AdminAccess::VIEW_USERS);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(mixed $record): bool
    {
        return false;
    }

    public static function canDelete(mixed $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
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
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime('d M Y H:i:s')
                    ->sortable()
                    ->label('Timestamp'),
                Tables\Columns\TextColumn::make('user.username')
                    ->label('Actor')
                    ->placeholder('System')
                    ->searchable(),
                Tables\Columns\TextColumn::make('action')
                    ->badge()
                    ->sortable(),
                Tables\Columns\TextColumn::make('risk_level')
                    ->badge()
                    ->colors([
                        'danger' => RiskLevel::Critical->value,
                        'warning' => RiskLevel::High->value,
                        'primary' => RiskLevel::Medium->value,
                        'secondary' => RiskLevel::Low->value,
                    ])
                    ->sortable(),
                Tables\Columns\TextColumn::make('auditable_type')
                    ->label('Target')
                    ->formatStateUsing(fn ($state) => class_basename((string) $state))
                    ->sortable(),
                Tables\Columns\TextColumn::make('auditable_id')
                    ->label('Target ID')
                    ->sortable(),
                Tables\Columns\TextColumn::make('ip_address')
                    ->label('IP')
                    ->searchable(),
                Tables\Columns\TextColumn::make('description')
                    ->limit(50),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('action')
                    ->options(collect(AuditAction::cases())->mapWithKeys(fn ($a) => [$a->value => $a->value])),
                Tables\Filters\SelectFilter::make('risk_level')
                    ->options(collect(RiskLevel::cases())->mapWithKeys(fn ($r) => [$r->value => $r->label()])),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
            ])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAuditLogs::route('/'),
            'view' => Pages\ViewAuditLog::route('/{record}'),
        ];
    }
}
