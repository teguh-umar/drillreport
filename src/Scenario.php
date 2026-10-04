<?php

namespace GlpiPlugin\Drillreport;

use CommonDropdown;

/**
 * DR scenario / site (e.g. "Failover to DRC Surabaya", "Full site loss - HQ").
 *
 * Per entity: a scenario names one entity's own systems, so it is not shared
 * unless its "Child entities" switch is on.
 */
class Scenario extends CommonDropdown
{
    use EntityDropdown;

    public static $rightname = 'plugin_drillreport_config';

    public static function getTypeName($nb = 0)
    {
        return _n('DR scenario', 'DR scenarios', $nb, 'drillreport');
    }
}
