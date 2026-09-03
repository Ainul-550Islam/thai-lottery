<?php

declare(strict_types=1);

namespace App\Filament\Resources\DrawResource;

use App\Enums\DrawLifecycleState;
use App\Enums\DrawStatus;
use App\Models\Draw;
use App\Services\Draw\DrawLifecycleService;
use App\Services\Draw\DrawResultPublicationService;
use App\Services\Draw\DrawSettlementSimulationService;
use App\Support\Admin\AdminAccess;
use App\Support\Admin\AdminFormat;
use Filament\Actions as PageActions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Tables\Actions as TableActions;
use Throwable;

/**
 * The five operator decisions a draw can need, defined exactly once.
 *
 * WHY A FACTORY INSTEAD OF TWO COPIES
 * The same decisions have to appear in two places Filament types differently: a row
 * action on the list screen (Filament\Tables\Actions\Action) and a header action on the
 * view screen (Filament\Actions\Action). Writing them twice would mean two answers to
 * "when may an operator publish a result?", and the copies would drift. So the rule -
 * label, permission, visibility condition, confirmation text, form and the service call -
 * is declared once in specs() and rendered into whichever type the screen needs.
 *
 * WHAT IS DELIBERATELY NOT DONE
 * - No transition rule is reimplemented here. Visibility is a cheap pre-filter so an
 *   operator is not shown a button that would fail; the authority remains
 *   DrawLifecycleService, which is called and whose refusal is reported verbatim.
 * - Nothing here writes to a draw directly. Every action body calls a domain service.
 */
final class DrawLifecycleActions
{
    /**
     * Row actions for the list/table screen.
     *
     * @return list<TableActions\Action>
     */
    public static function forTable(): array
    {
        return array_map(
            static function (array $spec): TableActions\Action {
                $action = TableActions\Action::make($spec['name']);

                return self::configure($action, $spec);
            },
            self::specs(),
        );
    }

    /**
     * Header actions for the view screen.
     *
     * @return list<PageActions\Action>
     */
    public static function forPage(): array
    {
        return array_map(
            static function (array $spec): PageActions\Action {
                $action = PageActions\Action::make($spec['name']);

                return self::configure($action, $spec);
            },
            self::specs(),
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
     * One entry per operator decision.
     *
     * @return list<array<string, mixed>>
     */
    private static function specs(): array
    {
        return [
            [
                'name' => 'open',
                'label' => 'Open betting',
                'icon' => 'heroicon-o-lock-open',
                'color' => 'success',
                'confirm' => true,
                'heading' => 'Open this draw for betting',
                'description' => 'Players will be able to place bets against it immediately. The scheduler normally does this when the betting window starts.',
                'visible' => static fn (Draw $record): bool => AdminAccess::current(AdminAccess::PROCESS_DRAWS)
                    && $record->status === DrawStatus::Scheduled,
                'action' => static function (Draw $record): void {
                    self::run(
                        static fn () => app(DrawLifecycleService::class)->open($record, ['source' => 'admin-panel']),
                        $record->draw_number.' is open for betting.',
                    );
                },
            ],
            [
                'name' => 'close',
                'label' => 'Close betting',
                'icon' => 'heroicon-o-lock-closed',
                'color' => 'warning',
                'confirm' => true,
                'heading' => 'Close betting on this draw',
                'description' => 'No further bets will be accepted. The scheduler closes a draw at its cut-off automatically; use this only to close early.',
                'visible' => static fn (Draw $record): bool => AdminAccess::current(AdminAccess::PROCESS_DRAWS)
                    && $record->status === DrawStatus::Open,
                'action' => static function (Draw $record): void {
                    self::run(
                        static fn () => app(DrawLifecycleService::class)->close($record, ['source' => 'admin-panel']),
                        $record->draw_number.' is closed.',
                    );
                },
            ],
            [
                'name' => 'publish',
                'label' => 'Publish official numbers',
                'icon' => 'heroicon-o-megaphone',
                'color' => 'primary',
                'heading' => 'Publish the official result',
                'description' => 'Enter the numbers exactly as announced by the Thai Government Lottery Office. This is not reversible: there is no unpublish, and a published draw can no longer be cancelled.',
                'submitLabel' => 'Publish — this cannot be undone',
                'form' => [
                    Forms\Components\TextInput::make('first_prize')
                        ->label('First prize')
                        ->required()
                        ->rule('regex:/^\d{6}$/')
                        ->validationMessages(['regex' => 'The first prize is exactly 6 digits.'])
                        ->helperText('6 digits. Leading zeros are significant and are kept.'),

                    Forms\Components\TextInput::make('bottom_two')
                        ->label('Bottom two')
                        ->required()
                        ->rule('regex:/^\d{2}$/')
                        ->validationMessages(['regex' => 'The bottom two is exactly 2 digits.']),
                ],
                'visible' => static fn (Draw $record): bool => AdminAccess::current(AdminAccess::MANAGE_DRAWS)
                    && $record->status === DrawStatus::Drawing,
                'action' => static function (Draw $record, array $data): void {
                    self::run(static function () use ($record, $data): void {
                        $outcome = app(DrawResultPublicationService::class)->publish((int) $record->getKey(), [
                            'first_prize' => (string) $data['first_prize'],
                            'bottom_two' => (string) $data['bottom_two'],
                        ]);

                        $rows = is_array($outcome['winning_numbers'] ?? null) ? count($outcome['winning_numbers']) : 0;

                        Notification::make()
                            ->success()
                            ->title('Result published')
                            ->body(sprintf(
                                '%s: %s / %s — %d winning number row(s) written. Settlement runs automatically after the configured delay.',
                                $record->draw_number,
                                $data['first_prize'],
                                $data['bottom_two'],
                                $rows,
                            ))
                            ->send();
                    });
                },
            ],
            [
                'name' => 'settle',
                'label' => 'Settle now',
                'icon' => 'heroicon-o-calculator',
                'color' => 'info',
                'confirm' => true,
                'heading' => 'Run the settlement simulation',
                'description' => 'Every selection is evaluated and its simulated prize recorded. This is a SIMULATION: no wallet is credited, no payout row is written and no ledger entry is posted — real payouts are Phase 5.2 and are not built. The scheduler does this automatically after the configured delay.',
                'visible' => static fn (Draw $record): bool => AdminAccess::current(AdminAccess::PROCESS_SETTLEMENTS)
                    && $record->status === DrawStatus::ResultPublished,
                'action' => static function (Draw $record): void {
                    self::run(static function () use ($record): void {
                        $result = app(DrawSettlementSimulationService::class)->settle((int) $record->getKey());

                        Notification::make()
                            ->success()
                            ->title($result->wroteNothing() ? 'Already settled — nothing written' : 'Settlement complete')
                            ->body(sprintf(
                                '%s: %s selection(s) evaluated, %s winner(s), simulated prize %s (mode: %s).',
                                $record->draw_number,
                                AdminFormat::count($result->selectionsEvaluated),
                                AdminFormat::count($result->winningSelections),
                                AdminFormat::money($result->totalSimulatedPrize, $result->currency),
                                $result->mode,
                            ))
                            ->send();
                    });
                },
            ],
            [
                'name' => 'cancelDraw',
                'label' => 'Cancel draw',
                'icon' => 'heroicon-o-x-circle',
                'color' => 'danger',
                'heading' => 'Cancel this draw',
                'description' => 'A cancelled draw accepts no bets and can never be published. Cancellation is only possible up to the moment a result is published.',
                'submitLabel' => 'Cancel this draw',
                'form' => [
                    Forms\Components\Textarea::make('reason')
                        ->label('Reason (recorded with the transition)')
                        ->required()
                        ->minLength(5)
                        ->maxLength(500),
                ],
                'visible' => static fn (Draw $record): bool => AdminAccess::current(AdminAccess::MANAGE_DRAWS)
                    && app(DrawLifecycleService::class)->canTransition($record, DrawLifecycleState::Cancelled),
                'action' => static function (Draw $record, array $data): void {
                    self::run(
                        static fn () => app(DrawLifecycleService::class)->cancel($record, [
                            'source' => 'admin-panel',
                            'reason' => (string) $data['reason'],
                        ]),
                        $record->draw_number.' is cancelled.',
                    );
                },
            ],
        ];
    }

    /**
     * Call a domain service and turn its refusal into a notification.
     *
     * A domain exception here is not a bug: it is the lifecycle guard doing its job on a
     * stale screen (two operators, one draw). The operator gets the service's own message,
     * and nothing was written.
     */
    private static function run(callable $operation, ?string $successMessage = null): void
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
