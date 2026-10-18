<?php

namespace Splicewire\Beam\Tests\Dashboard;

use Splicewire\Beam\Dashboard\RealmDashboard;
use Splicewire\Beam\Tests\TestCase;

class RealmDashboardTest extends TestCase
{
    public function test_an_ungated_dashboard_ability_names_its_realm(): void
    {
        $this->assertSame('realm-dashboard.view.tenant', RealmDashboard::openAbilityFor('tenant'));
        $this->assertSame('realm-dashboard.view.user', RealmDashboard::openAbilityFor('user'));
        $this->assertNotSame(RealmDashboard::openAbilityFor('tenant'), RealmDashboard::openAbilityFor('user'));
    }
}
