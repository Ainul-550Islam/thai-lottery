<?php

declare(strict_types=1);

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

/**
 * Profile corrections only.
 *
 * WHY mutateFormDataBeforeFill EXISTS HERE
 * Filament fills a form from the model's attributes, and `password` is an attribute — the
 * bcrypt hash would land in the password input, be re-submitted verbatim and be hashed a
 * second time by the `hashed` cast, silently locking the account out. Stripping it before
 * fill is what makes "leave blank to keep the current password" true rather than an
 * aspiration. The matching half is UserResource's `->dehydrated(filled($state))`, which
 * removes the key from the payload when it is blank, so saving a phone number cannot
 * reset a password.
 *
 * WHAT IS DELIBERATELY NOT DONE
 * - No status, no roles, no verification timestamps, no wallet fields. Everything with a
 *   consequence beyond "the name was spelled wrong" is an action on the view screen.
 * - No handleRecordUpdate override delegating to a service, because no user profile
 *   service exists; the fields written here are exactly User::$fillable identity fields,
 *   which is the one part of the aggregate that is genuinely plain data.
 */
class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    /**
     * @return array<int, Actions\Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Actions\ViewAction::make(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        unset($data['password']);

        return $data;
    }

    /**
     * Belt and braces: a blank password never reaches the model even if the dehydration
     * rule is ever relaxed. An empty string would otherwise be hashed into a valid
     * credential nobody knows.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        if (! filled($data['password'] ?? null)) {
            unset($data['password']);
        }

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->record]);
    }
}
