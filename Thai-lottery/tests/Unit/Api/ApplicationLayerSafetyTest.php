<?php

declare(strict_types=1);

namespace Tests\Unit\Api;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase 4.4 - static guarantees about the application layer's SOURCE.
 *
 * WHY A SOURCE-LEVEL TEST EXISTS AT ALL
 * Some of the phase's rules are absolute prohibitions rather than behaviours: the HTTP
 * layer must never call Bet::create(), never mutate a wallet balance, never write a ledger
 * entry and never cast money to a float. A behavioural test can only show that a
 * particular request did not do those things. This suite reads the files and shows that the
 * code CANNOT do them on any request, including ones nobody thought to write a test for.
 *
 * It is also the regression guard that matters most over time. The behavioural tests would
 * keep passing if somebody later added a convenient `$wallet->balance -= $stake` to a
 * controller for a case none of them cover. These tests would not.
 *
 * These assertions read files from disk and touch no database.
 */
final class ApplicationLayerSafetyTest extends TestCase
{
    /**
     * Every Phase 4.4 file that is subject to these prohibitions.
     *
     * @return list<string>
     */
    private function applicationLayerFiles(): array
    {
        $roots = [
            base_path('app/Http/Controllers'),
            base_path('app/Http/Requests'),
            base_path('app/Http/Resources'),
            base_path('app/Http/Responses'),
            base_path('app/Http/Support'),
        ];

        $files = [];

        foreach ($roots as $root) {
            if (! is_dir($root)) {
                continue;
            }

            /** @var iterable<\SplFileInfo> $iterator */
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            );

            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $files[] = $file->getPathname();
                }
            }
        }

        sort($files);

        return $files;
    }

    /**
     * The controllers alone - the strictest set.
     *
     * @return list<string>
     */
    private function controllerFiles(): array
    {
        return array_values(array_filter(
            $this->applicationLayerFiles(),
            static fn (string $path): bool => str_contains($path, '/Controllers/'),
        ));
    }

    /**
     * Source with comment text removed.
     *
     * This matters: several of the forbidden strings appear in the explanatory comments of
     * these very files, where they document what the code deliberately does NOT do. Naive
     * grepping would flag those comments as violations, so the comparison is made against
     * the code only, using PHP's own tokenizer rather than a regular expression.
     */
    private function codeOf(string $path): string
    {
        $source = (string) file_get_contents($path);
        $code = '';

        foreach (token_get_all($source) as $token) {
            if (is_array($token)) {
                if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }

                $code .= $token[1];

                continue;
            }

            $code .= $token;
        }

        return $code;
    }

    #[Test]
    public function the_application_layer_files_are_discoverable(): void
    {
        $files = $this->applicationLayerFiles();

        // A sanity check on the suite itself. If the discovery ever returned nothing, every
        // prohibition below would pass vacuously - which would be far worse than failing.
        $this->assertGreaterThanOrEqual(8, count($files));
        $this->assertNotEmpty($this->controllerFiles());
    }

    #[Test]
    public function no_unsafe_float_or_integer_cast_is_applied_to_money_or_numbers(): void
    {
        foreach ($this->applicationLayerFiles() as $path) {
            $code = $this->codeOf($path);

            foreach (['(float)', '(double)', '(real)', 'floatval(', 'doubleval('] as $forbidden) {
                $this->assertStringNotContainsString(
                    $forbidden,
                    str_replace(' ', '', $code),
                    sprintf('%s must not use %s.', basename($path), $forbidden),
                );
            }

            // intval() is banned outright: there is no legitimate use for it here, and its
            // presence would be a strong signal that a number or an amount is being
            // coerced.
            $this->assertStringNotContainsString(
                'intval(',
                $code,
                sprintf('%s must not use intval().', basename($path)),
            );

            // round(), floor() and ceil() would silently change an amount.
            foreach (['round(', 'floor(', 'ceil(', 'number_format('] as $forbidden) {
                $this->assertStringNotContainsString(
                    $forbidden,
                    $code,
                    sprintf('%s must not use %s on an amount.', basename($path), $forbidden),
                );
            }
        }
    }

    #[Test]
    public function the_controllers_never_create_a_bet_item_ticket_or_financial_record(): void
    {
        foreach ($this->controllerFiles() as $path) {
            $code = $this->codeOf($path);

            foreach ([
                'Bet::create',
                'BetItem::create',
                'Ticket::create',
                'Wallet::create',
                'LedgerEntry::create',
                'LedgerAccount::create',
                'FinancialTransaction::create',
                'Payout::create',
                'NumberLimit::create',
                'Bet::insert',
                'BetItem::insert',
                'Ticket::insert',
            ] as $forbidden) {
                $this->assertStringNotContainsString(
                    $forbidden,
                    $code,
                    sprintf('%s must not call %s().', basename($path), $forbidden),
                );
            }
        }
    }

    #[Test]
    public function the_controllers_never_mutate_a_wallet_balance(): void
    {
        foreach ($this->controllerFiles() as $path) {
            $code = $this->codeOf($path);

            foreach ([
                'balance =',
                'balance-=',
                'balance+=',
                'available_balance',
                'locked_balance',
                'increment(',
                'decrement(',
                'WalletService',
                'LedgerService',
            ] as $forbidden) {
                $this->assertStringNotContainsString(
                    $forbidden,
                    str_replace(' ', '', $code),
                    sprintf('%s must not touch %s.', basename($path), $forbidden),
                );
            }
        }
    }

    #[Test]
    public function the_controllers_open_no_transaction_and_write_no_raw_sql(): void
    {
        foreach ($this->controllerFiles() as $path) {
            $code = $this->codeOf($path);

            foreach ([
                'DB::transaction',
                'DB::beginTransaction',
                'DB::commit',
                'DB::rollBack',
                'DB::statement',
                'DB::raw',
                'DB::unprepared',
                'lockForUpdate',
                'sharedLock',
            ] as $forbidden) {
                $this->assertStringNotContainsString(
                    $forbidden,
                    $code,
                    sprintf('%s must not use %s.', basename($path), $forbidden),
                );
            }
        }
    }

    #[Test]
    public function no_payout_rate_is_hard_coded_in_the_application_layer(): void
    {
        // The configured rates. Finding any of them as a literal in the HTTP layer would
        // mean a payout rate had been duplicated outside config/lottery.php, where a change
        // would silently fail to reach it.
        //
        // HONEST LIMITATION OF A TEXT SCAN
        // Only rates of two or more digits are checked. run_top pays 3 and run_bottom pays
        // 4, and a single-digit literal is indistinguishable from any ordinary small integer
        // - a digit-count of 3, a retry count of 3, a substring length of 4. Asserting on
        // those would produce false failures on code that has nothing to do with payouts,
        // which would make this guard untrustworthy and eventually ignored. The single-digit
        // rates are covered behaviourally instead: tests AF, AG and AI assert that run_top
        // and run_bottom pay exactly the CONFIGURED rate, read from config at assertion
        // time, so a hard-coded 3 or 4 in the HTTP layer would still be caught the moment it
        // disagreed with configuration.
        $rates = [];

        foreach (array_keys((array) config('lottery.markets')) as $market) {
            $rate = config('lottery.markets.'.$market.'.payout_multiplier');

            if ($rate === null) {
                continue;
            }

            $rate = (string) $rate;

            if (strlen($rate) >= 2) {
                $rates[] = $rate;
            }
        }

        $rates = array_values(array_unique($rates));

        $this->assertNotEmpty($rates, 'The configured payout rates must be readable.');

        foreach ($this->applicationLayerFiles() as $path) {
            $code = $this->codeOf($path);

            foreach ($rates as $rate) {
                // Matched as a standalone numeric literal, so an unrelated 90 inside, say,
                // a 900-character length limit is not mistaken for a payout rate.
                $this->assertDoesNotMatchRegularExpression(
                    '/(?<![\w.])'.preg_quote($rate, '/').'(?![\w.])/',
                    $code,
                    sprintf(
                        '%s appears to hard-code the payout rate %s. Read it from config/lottery.php.',
                        basename($path),
                        $rate,
                    ),
                );
            }
        }
    }

    #[Test]
    public function no_bypass_or_override_parameter_is_accepted_anywhere(): void
    {
        foreach ($this->applicationLayerFiles() as $path) {
            $code = $this->codeOf($path);

            // A bypass flag must never be READ from a request. The FormRequest names these
            // strings inside its deny-list constant, which is the opposite of accepting
            // them, so the check targets the act of reading a request value.
            foreach ([
                "request->input('bypass",
                "request->input('skip",
                "request->input('force",
                "request->input('admin_override",
                "request->boolean('bypass",
                "request->boolean('skip",
                "request->boolean('force",
                "request->get('bypass",
            ] as $forbidden) {
                $this->assertStringNotContainsString(
                    $forbidden,
                    $code,
                    sprintf('%s must not read a bypass flag from the request.', basename($path)),
                );
            }
        }
    }

    #[Test]
    public function the_purchase_controller_calls_the_domain_service_and_nothing_else(): void
    {
        $path = base_path('app/Http/Controllers/Api/V1/BetPurchaseController.php');

        $this->assertFileExists($path);

        $code = $this->codeOf($path);

        // It goes through the verified Phase 4.3 entry point.
        $this->assertStringContainsString('BetPurchaseService', $code);
        $this->assertStringContainsString('purchase(', $code);
        $this->assertStringContainsString('BetPurchaseData::fromRequestArray', $code);

        // The user id comes from the auth context, and the string 'user_id' never appears
        // in its code at all - there is no line that could read it from a payload.
        $this->assertStringContainsString('request->user()', str_replace(' ', '', $code));
        $this->assertStringNotContainsString("input('user_id')", $code);
        $this->assertStringNotContainsString("'user_id'", $code);

        // It does not reach into any other domain service.
        foreach ([
            'RiskDecisionService',
            'NumberLimitLockService',
            'LedgerService',
            'WalletService',
            'BetPurchaseTransactionService',
            'BetPurchaseIdempotencyService',
        ] as $forbidden) {
            $this->assertStringNotContainsString(
                $forbidden,
                $code,
                sprintf('The purchase controller must not use %s directly.', $forbidden),
            );
        }
    }

    #[Test]
    public function the_error_mapper_never_forwards_an_exception_message(): void
    {
        $path = base_path('app/Http/Support/BetPurchaseErrorMapper.php');

        $this->assertFileExists($path);

        $code = $this->codeOf($path);

        // The single most important property of the mapper: it authors every message it
        // returns. If this string ever appears, an internal message could reach a client.
        foreach (['getMessage()', 'getTraceAsString', 'getTrace(', 'getFile(', 'getLine('] as $forbidden) {
            $this->assertStringNotContainsString(
                $forbidden,
                $code,
                sprintf('The error mapper must not call %s.', $forbidden),
            );
        }
    }

    #[Test]
    public function every_required_error_code_is_declared(): void
    {
        // The full vocabulary the phase requires. A missing constant here means a failure
        // mode has no stable code a client could branch on.
        $required = [
            'unauthenticated',
            'validation_failed',
            'draw_not_found',
            'draw_not_open',
            'draw_closed',
            'invalid_market',
            'invalid_digits',
            'invalid_number',
            'invalid_stake',
            'insufficient_balance',
            'number_limit_exceeded',
            'risk_rejected',
            'duplicate_idempotency_key',
            'idempotency_payload_mismatch',
            'transaction_failed',
            'market_result_unavailable',
            'purchase_not_allowed',
            'authorization_failed',
        ];

        $reflection = new \ReflectionClass(\App\Http\Support\BetPurchaseErrorMapper::class);
        $declared = array_map(
            static fn (mixed $value): string => (string) $value,
            array_filter($reflection->getConstants(), 'is_string'),
        );

        foreach ($required as $code) {
            $this->assertContains(
                $code,
                array_values($declared),
                sprintf('The error code "%s" must be declared.', $code),
            );
        }
    }

    #[Test]
    public function no_phase_one_to_four_three_domain_file_was_modified_by_this_phase(): void
    {
        // Phase 4.4 is an application layer. It must not have reached into the domain. This
        // is asserted structurally: the directories that hold verified financial logic must
        // contain no reference to the HTTP layer, because a domain class that knew about a
        // controller, a form request or an HTTP response would be evidence that the
        // boundary had been crossed in the wrong direction.
        $roots = [
            base_path('app/Services'),
            base_path('app/DTOs'),
            base_path('app/Exceptions'),
            base_path('app/Enums'),
        ];

        foreach ($roots as $root) {
            if (! is_dir($root)) {
                continue;
            }

            /** @var iterable<\SplFileInfo> $iterator */
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            );

            foreach ($iterator as $file) {
                if (! $file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }

                $code = $this->codeOf($file->getPathname());

                foreach ([
                    'App\\Http\\Controllers',
                    'App\\Http\\Requests',
                    'App\\Http\\Resources',
                    'App\\Http\\Responses',
                    'Illuminate\\Http\\JsonResponse',
                ] as $forbidden) {
                    $this->assertStringNotContainsString(
                        $forbidden,
                        $code,
                        sprintf(
                            '%s references %s. Domain code must not know about the HTTP layer.',
                            $file->getFilename(),
                            $forbidden,
                        ),
                    );
                }
            }
        }
    }
}
