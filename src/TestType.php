<?php

namespace GlpiPlugin\Drillreport;

use CommonDropdown;
use Session;

/**
 * DR exercise type. Seeded on install with the five standard values from
 * NIST SP 800-84 (tabletop / walkthrough / simulation / parallel / full-failover).
 *
 * Global: one list for every entity. Only group-level staff may change it,
 * i.e. a user who holds the config right and works in the root entity.
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

    public static function canCreate(): bool
    {
        return parent::canCreate() && self::isGroupLevel();
    }

    public static function canUpdate(): bool
    {
        return parent::canUpdate() && self::isGroupLevel();
    }

    public static function canDelete(): bool
    {
        return parent::canDelete() && self::isGroupLevel();
    }

    public static function canPurge(): bool
    {
        return parent::canPurge() && self::isGroupLevel();
    }

    /** Install and cron have no session entity; they are not restricted. */
    private static function isGroupLevel(): bool
    {
        return isCommandLine() || Session::isCron() || Session::haveAccessToEntity(0);
    }
}
