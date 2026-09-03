<?php

namespace Tests\Integration;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MigrationSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_core_tables_are_created(): void
    {
        foreach ([
            'users',
            'wallets',
            'ledger_accounts',
            'ledger_entries',
            'financial_transactions',
            'draws',
            'draw_results',
            'winning_numbers',
            'number_limits',
            'tickets',
            'bets',
            'bet_items',
            'payouts',
            'payments',
            'deposits',
            'withdrawals',
            'agents',
            'agent_commissions',
            'audit_logs',
            'roles',
            'permissions',
        ] as $table) {
            $this->assertTrue(Schema::hasTable($table), "Missing table: {$table}");
        }
    }
}
