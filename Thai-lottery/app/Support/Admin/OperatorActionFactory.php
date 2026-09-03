<?php

declare(strict_types=1);

namespace App\Support\Admin;

use Filament\Actions as PageActions;
use Filament\Notifications\Notification;
use Filament\Tables\Actions as TableActions;
use Throwable;

/**
 * Renders one operator-decision declaration into both of Filament's action types.
 *
 * WHY THIS EXISTS
 * DrawResource proved the shape: a decision an operator can take has exactly one
 * definition - label, colour, permission, visibility condition, confirmation copy, form
 * and service call - and that definition has to appear both as a row action on a list
 * screen (Filament\Tables\Actions\Action) and as a header action on a view screen
 * (Filament\Actions\Action). DrawLifecycleActions solved that for draws by writing the
 * render/try-catch plumbing next to the draw specs.
 *
 * The finance area has four such action sets (deposits, withdrawals, wallets, and the
 * read-only ledger screens which have none). Copying the plumbing four more times would
 * mean five places where "does a domain refusal become a red notification or a 500?" is
 * answered, and the copies would drift the first time someone fixed one of them. So the
 * plumbing lives here once and each resource contributes only its specs.
 *
 * WHAT IS DELIBERATELY NOT DONE HERE
 * - No authorization decision. A spec's `visible` closure asks AdminAccess itself; this
 *   class never guesses a permission, and never grants one by omission.
 * - No domain rule. Visibility is a cheap pre-filter so an operator is not shown a button
 *   the domain would refuse; the domain service remains the authority and its refusal is
 *   reported verbatim.
 * - No success invention. run() reports either the caller's own message or nothing; it
 *   never claims money moved, because only the service knows whether it did.
 */
final class OperatorActionFactory
{
    /**
     * @param  list<array<string, mixed>>  $specs
     * @return list<TableActions\Action>
     */
    public static function forTable(array $specs): array
    {
        return array_map(
            static fn (array $spec): TableActions\Action => self::configure(TableActions\Action::make($spec['name']), $spec),
            $specs,
        );
    }

    /**
     * @param  list<array<string, mixed>>  $specs
     * @return list<PageActions\Action>
     */
    public static function forPage(array $specs): array
    {
        return array_map(
            static fn (array $spec): PageActions\Action => self::configure(PageActions\Action::make($spec['name']), $spec),
            $specs,
        );
    }

    /**
     * @param  TableActions\Action|PageActions\Action  $action
     * @param  array<string, mixed>  $spec
     * @return TableActions\Action|PageActions\Action
     */
    private static function configure(object $action, array $spec): object
    {
        $action
            ->label($spec['label'])
            ->icon($spec['icon'])
            ->color($spec['color'])
            ->visible($spec['visible'])
            ->action($spec['action']);

        if (($spec['confirm'] ?? false) === true) {
            $action->requiresConfirmation();
        }

        if (isset($spec['heading'])) {
            $action->modalHeading($spec['heading']);
        }

        if (isset($spec['description'])) {
            $action->modalDescription($spec['description']);
        }

        if (isset($spec['submitLabel'])) {
            $action->modalSubmitActionLabel($spec['submitLabel']);
        }

        if (isset($spec['form'])) {
            $action->form($spec['form']);
        }

        return $action;
    }

    /**
     * Call a domain service and turn its refusal into a notification.
     *
     * A domain exception here is normal operation, not a bug: two operators looking at the
     * same pending withdrawal will race, and the loser's screen is simply stale. They get
     * the service's own message and nothing was written.
     */
    public static function run(callable $operation, ?string $successMessage = null): void
    {
        try {
            $operation();

            if ($successMessage !== null) {
                Notification::make()->success()->title($successMessage)->send();
            }
        } catch (Throwable $exception) {
            Notification::make()
                ->danger()
                ->title('The domain refused this operation')
                ->body($exception->getMessage())
                ->persistent()
                ->send();
        }
    }
}
