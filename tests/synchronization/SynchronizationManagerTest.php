<?php

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/includes/class-wsz-subscription-manager.php';
require_once dirname(__DIR__, 2) . '/includes/class-wsz-synchronization-manager.php';

final class SynchronizationManagerTest extends TestCase
{
    public function test_calculate_synced_timestamp_aligns_to_next_sync_day_for_monthly(): void
    {
        $reference = gmmktime(12, 0, 0, 4, 20, 2026);

        $synced = WSZ_Synchronization_Manager::calculate_synced_timestamp($reference, 25, 'month');

        $this->assertSame(25, (int) gmdate('j', $synced));
        $this->assertSame(4, (int) gmdate('n', $synced));
        $this->assertSame(2026, (int) gmdate('Y', $synced));
    }

    public function test_calculate_synced_timestamp_rolls_to_next_month_when_day_passed(): void
    {
        $reference = gmmktime(12, 0, 0, 4, 28, 2026);

        $synced = WSZ_Synchronization_Manager::calculate_synced_timestamp($reference, 5, 'month');

        $this->assertSame(5, (int) gmdate('j', $synced));
        $this->assertSame(5, (int) gmdate('n', $synced));
        $this->assertSame(2026, (int) gmdate('Y', $synced));
    }

    public function test_calculate_synced_timestamp_for_yearly_rolls_to_next_year_when_needed(): void
    {
        $reference = gmmktime(12, 0, 0, 8, 20, 2026);

        $synced = WSZ_Synchronization_Manager::calculate_synced_timestamp($reference, 10, 'year');

        $this->assertSame(10, (int) gmdate('j', $synced));
        $this->assertSame(8, (int) gmdate('n', $synced));
        $this->assertSame(2027, (int) gmdate('Y', $synced));
    }
}
