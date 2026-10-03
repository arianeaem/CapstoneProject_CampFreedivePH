<?php

namespace Tests\Feature;

use App\Support\DemandRules;
use Tests\TestCase;

class DemandSeasonLabelsTest extends TestCase
{
    public function test_every_month_belongs_to_exactly_one_season(): void
    {
        $all = array_merge(
            DemandRules::monthsForSeason('Peak'),
            DemandRules::monthsForSeason('Shoulder'),
            DemandRules::monthsForSeason('Off-Peak')
        );
        sort($all);

        $this->assertSame(range(1, 12), $all);
    }

    public function test_labels_follow_the_demand_rules_not_a_fixed_calendar(): void
    {
        foreach (['Peak', 'Shoulder', 'Off-Peak'] as $season) {
            foreach (DemandRules::monthsForSeason($season) as $m) {
                $this->assertSame($season, DemandRules::seasonForMonth($m));
            }
        }

        $this->assertNotSame('none', DemandRules::monthsLabel('Peak'));
    }
}
