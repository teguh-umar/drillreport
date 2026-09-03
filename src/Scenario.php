<?php

namespace GlpiPlugin\Drillreport;

use CommonDropdown;

/**
 * DR scenario / site (e.g. "Failover to DRC Surabaya", "Full site loss - HQ").
 */
class Scenario extends CommonDropdown
{
    public static $rightname = 'plugin_drillreport_config';

    public static function getTypeName($nb = 0)
    {
        return _n('DR scenario', 'DR scenarios', $nb, 'drillreport');
    }
}
