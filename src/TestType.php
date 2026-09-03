<?php

namespace GlpiPlugin\Drillreport;

use CommonDropdown;

/**
 * DR exercise type. Seeded on install with the five standard values from
 * NIST SP 800-84 (tabletop / walkthrough / simulation / parallel / full-failover).
 */
class TestType extends CommonDropdown
{
    public static $rightname = 'plugin_drillreport_config';

    /** Seeded on install — see {@see Seed::run()}. */
    public const SEED = ['Tabletop', 'Walkthrough', 'Simulation', 'Parallel test', 'Full failover'];

    public static function getTypeName($nb = 0)
    {
        return _n('Test type', 'Test types', $nb, 'drillreport');
    }
}
