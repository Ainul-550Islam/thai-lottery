<?php

declare(strict_types=1);

namespace Tests\Unit\Settlement;

use App\Enums\DrawLifecycleState;
use App\Services\Draw\DrawResultValidator;
use App\Services\Draw\DrawSettlementSimulationService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * PHASE 5.1 - requirement I points 27, 28 and 29, plus requirement J and H.
 *
 * The behavioural tests prove the settlement produces exact decimal strings. This suite
 * proves the stronger, structural claim: the dangerous constructs are not present in the
 * Phase 5.1 source at all.
 *
 * It reads each file with PHP's own tokeniser rather than with a regular expression, so
 * that a doc block explaining "this class never calls round()" is not itself reported as
 * a call to round(). Comments and doc comments are dropped before any check runs.
 *
 * Point 27 - no floating point arithmetic.
 * Point 28 - no round().
 * Point 29 - no intval() and no floatval().
 *
 * This test touches no database and boots no application, so it also serves as a fast
 * regression guard: if a later phase edits one of these files and reaches for a float,
 * this suite fails immediately.
 */
final class SettlementSafetyAuditTest extends TestCase
{
    /**
     * Every file Phase 5.1 created, plus the one file it modified.
     *
     * @var list<string>
     */
    private const PHASE_51_FILES = [
        'app/Enums/DrawStatus.php',
        'app/Enums/DrawLifecycleState.php',
        'app/Enums/SettlementSimulationStatus.php',
        'app/Exceptions/DrawLifecycleException.php',
        'app/Exceptions/DrawResultValidationException.php',
        'app/Exceptions/SettlementSimulationException.php',
        'app/DTOs/DrawResultData.php',
        'app/DTOs/SettlementSelectionResult.php',
        'app/DTOs/SettlementSimulationResult.php',
        'app/Services/Draw/DrawLifecycleService.php',
        'app/Services/Draw/DrawResultValidator.php',
        'app/Services/Draw/DrawResultPublicationService.php',
        'app/Services/Draw/SelectionSettlementResolver.php',
        'app/Services/Draw/DrawSettlementSimulationService.php',
    ];

    /**
     * Functions that either introduce a float or silently discard precision.
     *
     * @var list<string>
     */
    private const FORBIDDEN_FUNCTIONS = [
        'round',
        'floor',
        'ceil',
        'intval',
        'floatval',
        'doubleval',
        'number_format',
        'fdiv',
        'abs',
        'max',
        'min',
        'array_sum',
        'array_product',
        'money_format',
        'settype',
    ];

    /**
     * Debug output. Any of these in a settlement path is a leak.
     *
     * @var list<string>
     */
    private const FORBIDDEN_DEBUG_FUNCTIONS = [
        'dd',
        'dump',
        'var_dump',
        'print_r',
        'var_export',
        'error_log',
        'ray',
        'logger',
    ];

    /**
     * Raw database access. Phase 5.1 goes through Eloquent and the query builder only.
     *
     * @var list<string>
     */
    private const FORBIDDEN_SQL = [
        'DB::statement',
        'DB::unprepared',
        'DB::raw',
        'DB::select',
        'DB::insert',
        'DB::update',
        'DB::delete',
        'whereRaw',
        'selectRaw',
        'orderByRaw',
        'havingRaw',
        'updateRaw',
        'increment(',
        'decrement(',
    ];

    /**
     * Escape hatches. If any of these appears as an identifier, an outcome can be
     * dictated from outside instead of derived from the verified rules.
     *
     * @var list<string>
     */
    private const FORBIDDEN_FLAGS = [
        'force',
        'override',
        'bypass',
        'skip_validation',
        'skipValidation',
        'ignore_rules',
        'ignoreRules',
        'allow_unsafe',
        'unsafe',
        'no_check',
        'noCheck',
        'admin_override',
        'debug_mode',
    ];

    // -----------------------------------------------------------------------------
    // Point 27 - no floating point arithmetic
    // -----------------------------------------------------------------------------

    #[Test]
    public function point_27_no_phase_51_file_contains_a_float_or_double_cast(): void
    {
        foreach (self::PHASE_51_FILES as $file) {
            $tokens = $this->codeTokens($file);

            foreach ($tokens as $token) {
                if (! is_array($token)) {
                    continue;
                }

                $this->assertNotSame(
                    T_DOUBLE_CAST,
                    $token[0],
                    $file.' line '.$token[2].' casts to float or double. Money is a decimal string here.',
                );
            }
        }
    }

    #[Test]
    public function point_27b_no_phase_51_file_contains_a_float_literal(): void
    {
        foreach (self::PHASE_51_FILES as $file) {
            foreach ($this->codeTokens($file) as $token) {
                if (! is_array($token)) {
                    continue;
                }

                $this->assertNotSame(
                    T_DNUMBER,
                    $token[0],
                    $file.' line '.$token[2].' contains the float literal '.trim((string) $token[1])
                    .'. Decimal values must be written as strings.',
                );
            }
        }
    }

    #[Test]
    public function point_27b2_the_tokeniser_actually_detects_a_float(): void
    {
        // A negative control. Without this, a broken detector would let every file pass.
        $tokens = token_get_all("<?php \$x = 1.5; \$y = (float) '2'; \$z = round(1.4);");

        $found = ['dnumber' => false, 'cast' => false, 'round' => false];

        foreach ($tokens as $token) {
            if (! is_array($token)) {
                continue;
            }

            if ($token[0] === T_DNUMBER) {
                $found['dnumber'] = true;
            }

            if ($token[0] === T_DOUBLE_CAST) {
                $found['cast'] = true;
            }

            if ($token[0] === T_STRING && $token[1] === 'round') {
                $found['round'] = true;
            }
        }

        $this->assertTrue($found['dnumber'], 'The float literal detector is broken.');
        $this->assertTrue($found['cast'], 'The float cast detector is broken.');
        $this->assertTrue($found['round'], 'The function call detector is broken.');
    }

    #[Test]
    public function point_27c_every_arithmetic_operation_on_a_money_value_uses_bcmath(): void
    {
        // The two files that do the money arithmetic.
        $calculators = [
            'app/Services/Draw/SelectionSettlementResolver.php',
            'app/Services/Draw/DrawSettlementSimulationService.php',
        ];

        foreach ($calculators as $file) {
            $code = $this->codeOf($file);

            // BCMath is present.
            $this->assertMatchesRegularExpression(
                '/\bbc(add|mul|sub|comp|div)\s*\(/',
                $code,
                $file.' must do its arithmetic with BCMath.',
            );

            // And the native arithmetic operators are not applied anywhere in it. The
            // tokeniser is used so that a "*" inside a doc block or a string cannot
            // trigger this.
            foreach ($this->codeTokens($file) as $token) {
                if (is_array($token)) {
                    continue;
                }

                $this->assertNotSame('*', $token, $file.' uses the multiplication operator.');
                $this->assertNotSame('/', $token, $file.' uses the division operator.');
                $this->assertNotSame('%', $token, $file.' uses the modulo operator.');
            }
        }
    }

    #[Test]
    public function point_27d_the_bcmath_extension_is_loaded(): void
    {
        // Every exactness guarantee in this phase rests on it.
        $this->assertTrue(extension_loaded('bcmath'), 'BCMath is required for exact decimal settlement.');
        $this->assertSame('450.00', bcmul('10.00', '45', 2));
        $this->assertSame('90000.00', bcmul('100.00', '900', 2));

        // The float route to the same figure is not reliable; this is why the code avoids it.
        $this->assertSame('0.30', bcadd('0.10', '0.20', 2));
    }

    // -----------------------------------------------------------------------------
    // Points 28 and 29 - no round(), intval() or floatval()
    // -----------------------------------------------------------------------------

    #[Test]
    public function points_28_and_29_no_phase_51_file_calls_a_precision_losing_function(): void
    {
        foreach (self::PHASE_51_FILES as $file) {
            $tokens = $this->codeTokens($file);
            $count = count($tokens);

            for ($index = 0; $index < $count; $index++) {
                $token = $tokens[$index];

                if (! is_array($token) || $token[0] !== T_STRING) {
                    continue;
                }

                $name = strtolower($token[1]);

                if (! in_array($name, self::FORBIDDEN_FUNCTIONS, true)) {
                    continue;
                }

                // Only a call matters. A method or property of the same name would be
                // preceded by "->" or "::" and is not a global function call.
                if ($this->isMemberAccess($tokens, $index)) {
                    continue;
                }

                $this->assertFalse(
                    $this->isCall($tokens, $index),
                    $file.' line '.$token[2].' calls '.$name.'(), which is forbidden in Phase 5.1.',
                );
            }
        }
    }

    #[Test]
    public function point_29b_no_phase_51_file_casts_a_money_string_through_a_numeric_cast(): void
    {
        // (int) is legitimate for identifiers, so the check is scoped: an (int) cast may
        // never be applied to something whose name looks like money.
        $moneyish = ['payout', 'prize', 'amount', 'stake', 'balance', 'total', 'multiplier'];

        foreach (self::PHASE_51_FILES as $file) {
            $tokens = $this->codeTokens($file);
            $count = count($tokens);

            for ($index = 0; $index < $count; $index++) {
                $token = $tokens[$index];

                if (! is_array($token) || $token[0] !== T_INT_CAST) {
                    continue;
                }

                $following = '';

                for ($ahead = $index + 1; $ahead < min($count, $index + 8); $ahead++) {
                    $next = $tokens[$ahead];
                    $following .= is_array($next) ? $next[1] : $next;
                }

                $lowered = strtolower($following);

                foreach ($moneyish as $needle) {
                    // payout_multiplier is an unsignedInteger column, so casting it to int
                    // for storage is correct and is asserted separately.
                    if ($needle === 'multiplier' && str_contains($lowered, 'integerpart')) {
                        continue;
                    }

                    $this->assertStringNotContainsString(
                        $needle,
                        $lowered,
                        $file.' line '.$token[2].' applies an (int) cast to what looks like a money value: '
                        .trim($following),
                    );
                }
            }
        }
    }

    // -----------------------------------------------------------------------------
    // Requirement J - the rest of the static audit
    // -----------------------------------------------------------------------------

    #[Test]
    public function requirement_j_no_phase_51_file_uses_raw_sql(): void
    {
        foreach (self::PHASE_51_FILES as $file) {
            // Executable code only. Several of these files carry a guarantees() method
            // whose STRING LITERALS say "there is no DB::statement, DB::raw ...". Those
            // are prose in a returned array, not calls, so string contents are dropped
            // before the check as well as comments.
            $code = $this->executableCodeOf($file);

            foreach (self::FORBIDDEN_SQL as $needle) {
                $this->assertStringNotContainsString(
                    $needle,
                    $code,
                    $file.' uses '.$needle.'. Phase 5.1 goes through Eloquent only.',
                );
            }
        }
    }

    #[Test]
    public function requirement_j_no_phase_51_file_declares_an_escape_hatch(): void
    {
        foreach (self::PHASE_51_FILES as $file) {
            $tokens = $this->codeTokens($file);

            foreach ($tokens as $index => $token) {
                if (! is_array($token)) {
                    continue;
                }

                if (! in_array($token[0], [T_VARIABLE, T_STRING], true)) {
                    continue;
                }

                $name = strtolower(ltrim($token[1], '$'));

                foreach (self::FORBIDDEN_FLAGS as $flag) {
                    // The validator legitimately names these as REFUSED input keys, and
                    // those appear as string literals, not as identifiers. An identifier
                    // is what would make one usable.
                    $this->assertNotSame(
                        $flag,
                        $name,
                        $file.' line '.$token[2].' declares the identifier "'.$token[1].'", which is an '
                        .'override path.',
                    );
                }
            }
        }
    }

    #[Test]
    public function requirement_j_the_refused_input_keys_are_string_literals_and_are_actually_refused(): void
    {
        $refused = DrawResultValidator::REFUSED_FIELDS;

        foreach (['winning_numbers', 'payout_multiplier', 'actual_payout', 'is_winner', 'force', 'override', 'bypass', 'skip_validation'] as $key) {
            $this->assertContains(
                $key,
                $refused,
                'The result validator must refuse the input key "'.$key.'".',
            );
        }
    }

    #[Test]
    public function requirement_h_no_phase_51_file_reads_an_identity_from_the_request(): void
    {
        foreach (self::PHASE_51_FILES as $file) {
            $code = $this->executableCodeOf($file);

            foreach ([
                "request('user_id')",
                "request()->input('user_id')",
                "request->input('user_id')",
                "['user_id']",
                'Request $request',
                'request()',
                'Auth::',
                'auth()',
            ] as $needle) {
                $this->assertStringNotContainsString(
                    $needle,
                    $code,
                    $file.' reads '.$needle.'. The Phase 5.1 domain is given ids by its caller and '
                    .'resolves identity from stored rows only.',
                );
            }
        }
    }

    #[Test]
    public function requirement_h_no_phase_51_file_leaks_a_stack_trace_or_a_driver_message(): void
    {
        foreach (self::PHASE_51_FILES as $file) {
            $code = $this->executableCodeOf($file);

            foreach ([
                'getTraceAsString',
                'getTrace(',
                '__toString()',
                'PDOException',
                'getPrevious()',
                'getBindings',
                'getSql',
                'errorInfo',
            ] as $needle) {
                $this->assertStringNotContainsString(
                    $needle,
                    $code,
                    $file.' exposes '.$needle.', which can leak internals to a caller.',
                );
            }
        }
    }

    #[Test]
    public function requirement_h_no_phase_51_file_calls_a_debug_output_function(): void
    {
        // Detected as calls rather than as substrings, because "bcadd(" contains "dd(".
        foreach (self::PHASE_51_FILES as $file) {
            $tokens = $this->codeTokens($file);
            $count = count($tokens);

            for ($index = 0; $index < $count; $index++) {
                $token = $tokens[$index];

                if (! is_array($token) || $token[0] !== T_STRING) {
                    continue;
                }

                if (! in_array(strtolower($token[1]), self::FORBIDDEN_DEBUG_FUNCTIONS, true)) {
                    continue;
                }

                if ($this->isMemberAccess($tokens, $index)) {
                    continue;
                }

                $this->assertFalse(
                    $this->isCall($tokens, $index),
                    $file.' line '.$token[2].' calls '.$token[1].'(), which writes internals to output.',
                );
            }
        }
    }

    #[Test]
    public function requirement_h_the_only_exception_messages_reused_are_from_the_projects_own_domain(): void
    {
        // SelectionSettlementResolver quotes the message of a refusal so the audit record
        // says WHY a selection was unusable. That is only safe because the exceptions it
        // catches are the project's own market and bet exceptions, never a driver
        // exception. This asserts that scoping.
        $code = $this->codeOf('app/Services/Draw/SelectionSettlementResolver.php');

        preg_match_all('/catch \(([^)]+)\)/', $code, $matches);

        $this->assertNotEmpty($matches[1], 'The resolver is expected to catch the verified rule exceptions.');

        foreach ($matches[1] as $caught) {
            foreach (explode('|', $caught) as $class) {
                $class = trim(preg_replace('/\$\w+/', '', $class) ?? '');

                $this->assertContains(
                    $class,
                    ['MarketRuleException', 'BetDomainException'],
                    'The resolver may only catch the project\'s own domain exceptions, not '.$class.'.',
                );
            }
        }

        // And the publication service inspects only the SQLSTATE code of a driver
        // exception, never its message.
        $publication = $this->codeOf('app/Services/Draw/DrawResultPublicationService.php');
        $this->assertStringContainsString('QueryException $exception', $publication);
        $this->assertStringContainsString('$exception->getCode()', $publication);
        $this->assertStringNotContainsString('$exception->getMessage()', $publication);
    }

    #[Test]
    public function requirement_g_no_phase_51_file_names_a_financial_table_outside_the_forbidden_list(): void
    {
        $financial = ['wallets', 'financial_transactions', 'ledger_entries', 'ledger_accounts', 'payouts', 'deposits', 'withdrawals', 'payments', 'agent_commissions'];

        foreach (self::PHASE_51_FILES as $file) {
            $code = $this->codeOf($file);

            foreach ($financial as $table) {
                if (! str_contains($code, "'".$table."'")) {
                    continue;
                }

                // The one legitimate mention is the forbidden list itself.
                $this->assertSame(
                    'app/Services/Draw/DrawSettlementSimulationService.php',
                    $file,
                    $file.' names the financial table "'.$table.'" in executable code.',
                );
                $this->assertContains($table, DrawSettlementSimulationService::FORBIDDEN_TABLES);
            }
        }
    }

    // -----------------------------------------------------------------------------
    // The invariants the audit rests on
    // -----------------------------------------------------------------------------

    #[Test]
    public function the_lifecycle_transition_table_is_the_single_source_of_truth(): void
    {
        $table = DrawLifecycleState::transitions();

        // Every state appears as a key exactly once.
        $this->assertCount(count(DrawLifecycleState::cases()), $table);

        foreach (DrawLifecycleState::cases() as $state) {
            $this->assertArrayHasKey($state->value, $table);
        }

        // The table is keyed by the string value and holds enum instances.
        $this->assertSame([], $table[DrawLifecycleState::Settled->value]);
        $this->assertSame([], $table[DrawLifecycleState::Cancelled->value]);
        $this->assertContainsOnlyInstancesOf(
            DrawLifecycleState::class,
            $table[DrawLifecycleState::Draft->value],
        );

        // Settled is only reachable from ResultPublished, so no draw can be settled
        // without a published result.
        $sources = [];

        foreach ($table as $from => $targets) {
            if (in_array(DrawLifecycleState::Settled, $targets, true)) {
                $sources[] = $from;
            }
        }

        $this->assertSame([DrawLifecycleState::ResultPublished->value], $sources);

        // And a published result can never be cancelled away.
        $this->assertNotContains(
            DrawLifecycleState::Cancelled,
            $table[DrawLifecycleState::ResultPublished->value],
        );
    }

    #[Test]
    public function the_lifecycle_state_maps_onto_the_persisted_status_in_both_directions(): void
    {
        foreach (DrawLifecycleState::cases() as $state) {
            $status = $state->toDrawStatus();

            $this->assertSame(
                $state,
                DrawLifecycleState::fromDrawStatus($status),
                'The mapping of '.$state->value.' onto '.$status->value.' must be reversible.',
            );
        }
    }

    #[Test]
    public function the_settlement_service_declares_the_guarantees_this_phase_promises(): void
    {
        $reflection = new ReflectionClass(DrawSettlementSimulationService::class);

        $this->assertTrue($reflection->isInstantiable());
        $this->assertSame('simulation', DrawSettlementSimulationService::MODE);
        $this->assertTrue(
            $reflection->getConstructor()?->isPublic() ?? false,
            'The service is resolved from the container, so its constructor is public and its '
            .'collaborators are injected rather than built inside it.',
        );

        foreach ([
            'wallets',
            'financial_transactions',
            'ledger_entries',
            'payouts',
            'deposits',
            'withdrawals',
            'payments',
        ] as $table) {
            $this->assertContains($table, DrawSettlementSimulationService::FORBIDDEN_TABLES);
        }
    }

    // -----------------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------------

    /**
     * The source of a Phase 5.1 file with every comment and doc block removed, so that
     * prose about a forbidden construct is never mistaken for the construct.
     */
    private function codeOf(string $projectRelativePath): string
    {
        $kept = '';

        foreach ($this->codeTokens($projectRelativePath) as $token) {
            $kept .= is_array($token) ? $token[1] : $token;
        }

        return $kept;
    }

    /**
     * The source with comments AND the contents of string literals removed. Used for the
     * checks whose needles could legitimately appear inside a documentation string that
     * the class returns from audit() or guarantees().
     */
    private function executableCodeOf(string $projectRelativePath): string
    {
        $kept = '';

        foreach ($this->codeTokens($projectRelativePath) as $token) {
            if (is_array($token) && in_array($token[0], [
                T_CONSTANT_ENCAPSED_STRING,
                T_ENCAPSED_AND_WHITESPACE,
                T_INLINE_HTML,
            ], true)) {
                $kept .= "''";

                continue;
            }

            $kept .= is_array($token) ? $token[1] : $token;
        }

        return $kept;
    }

    /**
     * @return list<array{0:int,1:string,2:int}|string>
     */
    private function codeTokens(string $projectRelativePath): array
    {
        $path = dirname(__DIR__, 3).'/'.$projectRelativePath;
        $this->assertFileExists($path, 'Phase 5.1 file is missing: '.$projectRelativePath);

        $source = file_get_contents($path);
        $this->assertIsString($source);

        $kept = [];

        foreach (token_get_all($source) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $kept[] = $token;
        }

        return array_values($kept);
    }

    /**
     * Is the token at $index preceded by "->", "?->" or "::"?
     *
     * @param  list<array{0:int,1:string,2:int}|string>  $tokens
     */
    private function isMemberAccess(array $tokens, int $index): bool
    {
        for ($back = $index - 1; $back >= 0; $back--) {
            $token = $tokens[$back];

            if (is_array($token) && $token[0] === T_WHITESPACE) {
                continue;
            }

            if (is_array($token)) {
                return in_array($token[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_CONST], true);
            }

            return false;
        }

        return false;
    }

    /**
     * Is the token at $index immediately followed by an opening parenthesis?
     *
     * @param  list<array{0:int,1:string,2:int}|string>  $tokens
     */
    private function isCall(array $tokens, int $index): bool
    {
        $count = count($tokens);

        for ($ahead = $index + 1; $ahead < $count; $ahead++) {
            $token = $tokens[$ahead];

            if (is_array($token) && $token[0] === T_WHITESPACE) {
                continue;
            }

            return $token === '(';
        }

        return false;
    }
}
