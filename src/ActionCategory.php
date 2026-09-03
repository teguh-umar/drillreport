<?php

namespace GlpiPlugin\Drillreport;

use CommonDropdown;

/**
 * Optional category for improvement-plan actions (e.g. "Runbook gap",
 * "Backup integrity", "Network / DNS", "Staffing").
 */
class ActionCategory extends CommonDropdown
{
    public static $rightname = 'plugin_drillreport_config';

    public static function getTypeName($nb = 0)
    {
        return _n('Action category', 'Action categories', $nb, 'drillreport');
    }
}
