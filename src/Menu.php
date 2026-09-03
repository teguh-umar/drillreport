<?php

namespace GlpiPlugin\Drillreport;

use CommonGLPI;
use Session;

/**
 * "DR Drill Report" node under the GLPI 11 Management sector.
 *
 * Wired in {@see plugin_init_drillreport()} via
 *   $PLUGIN_HOOKS['menu_toadd']['drillreport'] = ['management' => Menu::class];
 *
 * Not a persisted itemtype — no table. Only supplies the static hooks GLPI's
 * menu builder needs.
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

    public static function canView()
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

        $drill_read  = Session::haveRight(Drill::$rightname, READ);
        $action_read = Session::haveRight(Action::$rightname, READ);

        $landing = $drill_read
            ? Drill::getSearchURL(false)
            : Action::getSearchURL(false);

        $menu = [
            'title'   => self::getMenuName(),
            'page'    => $landing,
            'icon'    => self::getIcon(),
            'links'   => ['search' => $landing],
            'options' => [],
        ];

        if ($drill_read) {
            $menu['options']['drill'] = [
                'title' => Drill::getTypeName(Session::getPluralNumber()),
                'page'  => Drill::getSearchURL(false),
                'icon'  => Drill::getIcon(),
                'links' => ['search' => Drill::getSearchURL(false)],
            ];
            if (Session::haveRight(Drill::$rightname, CREATE)) {
                $menu['options']['drill']['links']['add'] = Drill::getFormURL(false);
            }
        }

        if ($action_read) {
            $menu['options']['action'] = [
                'title' => Action::getTypeName(Session::getPluralNumber()),
                'page'  => Action::getSearchURL(false),
                'icon'  => Action::getIcon(),
                'links' => ['search' => Action::getSearchURL(false)],
            ];
        }

        if (Session::haveRight('plugin_drillreport_config', READ)) {
            $setup = '/plugins/drillreport/front/config.php';
            $menu['options']['setup'] = [
                'title' => __('Setup', 'drillreport'),
                'page'  => $setup,
                'icon'  => 'ti ti-settings',
                'links' => ['search' => $setup],
            ];
        }

        return $menu;
    }
}
