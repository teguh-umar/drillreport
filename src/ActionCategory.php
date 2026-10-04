<?php

namespace GlpiPlugin\Drillreport;

use CommonDropdown;

/**
 * Optional category for improvement-plan actions (e.g. "Runbook gap",
 * "Backup integrity", "Network / DNS", "Staffing").
 *
 * The seeded defaults live in the root entity and are shared with every
 * entity ("Child entities" on); an entity may add its own.
 */
class ActionCategory extends CommonDropdown
{
    use EntityDropdown;

    public static $rightname = 'plugin_drillreport_config';

    public static function getTypeName($nb = 0)
    {
        return _n('Action category', 'Action categories', $nb, 'drillreport');
    }
}
