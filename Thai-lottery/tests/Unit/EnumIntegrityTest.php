<?php

namespace Tests\Unit;

use App\Enums\BetType;
use App\Enums\Currency;
use App\Enums\LedgerEntryType;
use PHPUnit\Framework\TestCase;

class EnumIntegrityTest extends TestCase
{
    public function test_bet_types_match_configured_lottery_types(): void
    {
        $this->assertSame(['2d', '3d', 'tod', 'run'], array_column(BetType::cases(), 'value'));
    }

    public function test_supported_currencies(): void
    {
        $this->assertSame(['THB', 'USD', 'BDT'], array_column(Currency::cases(), 'value'));
    }

    public function test_ledger_is_strictly_double_entry(): void
    {
        $this->assertCount(2, LedgerEntryType::cases());
    }
}
