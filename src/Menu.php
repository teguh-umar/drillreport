<?php

namespace GlpiPlugin\Drillreport;

use CommonGLPI;
use Session;

/**
 * "DR Drill Report" — its own top-level sidebar sector (GLPI 11).
 *
 * Wired in {@see plugin_init_drillreport()} via
 *   $PLUGIN_HOOKS['menu_toadd']['drillreport'] = ['drillreport' => [Menu::class]];
 * The ARRAY-of-classes value creates a NEW sector `drillreport`; a bare class
 * string would only append to an existing sector. `menu.html.twig` renders only
 * `content` entries that carry a `page`, never an `options` array — so a
 * multi-page plugin must return `is_multi_entries` (see getMenuContent()).
 *
 * Not a persisted itemtype — no table.
 */
class Menu extends CommonGLPI
{
    public static function getTypeName($nb = 0)
    {
        return __('DR Drill Report', 'drillreport');
    }

    public static function getMenuName()
    {
        return self::getTypeName();
    }

    public static function getIcon()
    {
        return 'ti ti-refresh-alert';
    }

    public static function canView(): bool
    {
        return Session::haveRight(Drill::$rightname, READ)
            || Session::haveRight(Action::$rightname, READ);
    }

    /**
     * @return array<string,mixed>|false
     */
    public static function getMenuContent()
    {
        if (!self::canView()) {
            return false;
        }

        $entries = [];

        if (Session::haveRight(Drill::$rightname, READ)) {
            $entries['drill'] = [
                'title' => Drill::getTypeName(Session::getPluralNumber()),
                'page'  => Drill::getSearchURL(false),
                'icon'  => Drill::getIcon(),
                'links' => ['search' => Drill::getSearchURL(false)],
            ];
            if (Session::haveRight(Drill::$rightname, CREATE)) {
                $entries['drill']['links']['add'] = Drill::getFormURL(false);
            }
        }

        if (Session::haveRight(Action::$rightname, READ)) {
            $entries['action'] = [
                'title' => Action::getTypeName(Session::getPluralNumber()),
                'page'  => Action::getSearchURL(false),
                'icon'  => Action::getIcon(),
                'links' => ['search' => Action::getSearchURL(false)],
            ];
        }

        // No "Setup" sidebar entry — config now lives in the shared "PGN Suite"
        // tab on Setup > General ({@see SuiteConfig}). front/config.php is
        // still a real, reachable page (direct URL, and the Setup > Plugins
        // cog) for users who hold only plugin_drillreport_config, not core
        // config — see that file's redirect logic.

        if ($entries === []) {
            return false;
        }

        return ['is_multi_entries' => true, 'title' => self::getTypeName()] + $entries;
    }
}
