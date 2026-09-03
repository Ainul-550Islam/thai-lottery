<?php

declare(strict_types=1);

namespace App\Filament\Resources\NumberLimitResource\Pages;

use App\Filament\Resources\NumberLimitResource;
use Filament\Resources\Pages\CreateRecord;

/**
 * Creating a ceiling.
 *
 * WHY THIS PAGE IS ALMOST EMPTY
 * Unlike CreateDraw, there is nothing to derive after the fact. The five definition
 * columns the form collects are exactly the columns NumberLimitEngine reads, and the
 * consumption columns (current_amount, current_payout_exposure, status, exceeded_at) all
 * carry database defaults of zero / 'active' / null. A new limit therefore starts in the
 * only state the engine considers valid for a fresh row, without this page setting a
 * single attribute.
 *
 * WHAT IS DELIBERATELY NOT DONE
 * - No warm-start of current_amount from bets already placed on the number. That would be
 *   the panel deciding how much exposure the house is carrying, which is the engine's
 *   job; a limit created after bets exist honestly reports zero reserved and the operator
 *   is expected to know that.
 * - No "create another 99 like this" convenience. See the resource's note on bulk
 *   provisioning belonging in a console command.
 */
class CreateNumberLimit extends CreateRecord
{
    protected static string $resource = NumberLimitResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
