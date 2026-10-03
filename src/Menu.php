<?php

namespace GlpiPlugin\Drillreport;

use Session;

/**
 * "DR Drill Report" — this plugin's single entry in the suite's shared sidebar
 * sector (GlpiPlugin\Pgnsuite\Menu, shipped byte-identical at
 * lib/pgnsuite/Menu.php; hub file §5). Until v0.10.2 the plugin had a
 * top-level sector of its own.
 *
 * Wired in {@see plugin_init_drillreport()} via
 *   SuiteMenu::register('drillreport');
 *   $PLUGIN_HOOKS[SuiteMenu::HOOK]['drillreport'] = Menu::class . '::entry';
 * The provider must be a "Class::method" *string* — the shared Menu drops
 * anything else.
 *
 * The plugin's own pages (DR drills / Improvement actions) are the entry's
 * `options`: they feed the breadcrumb, the "+ Add" / search links and
 * SuiteMenu::renderSubNav(). The sidebar itself shows only the entry.
 *
 * Front controllers select the active page with
 *   Html::header($title, '', SuiteMenu::SECTOR, 'drillreport', '<option>')
 * where `<option>` is `drill` or `action`.
 *
 * Not a persisted itemtype — no table.
 */
class Menu
{
    /** Suite-wide sidebar position (hub file §7, 2026-10-03). */
    private const ORDER = 30;

    public static function getIcon(): string
    {
        return 'ti ti-refresh-alert';
    }

    /**
     * Provider for \GlpiPlugin\Pgnsuite\Menu::HOOK. Null hides the entry when
     * the current user may open none of the plugin's pages.
     *
     * @return array<string,mixed>|null
     */
    public static function entry(): ?array
    {
        $options = [];

        if (Session::haveRight(Drill::$rightname, READ)) {
            $options['drill'] = [
                'title' => Drill::getTypeName(Session::getPluralNumber()),
                'page'  => Drill::getSearchURL(false),
                'icon'  => Drill::getIcon(),
                'links' => ['search' => Drill::getSearchURL(false)],
            ];
            if (Session::haveRight(Drill::$rightname, CREATE)) {
                $options['drill']['links']['add'] = Drill::getFormURL(false);
            }
        }

        if (Session::haveRight(Action::$rightname, READ)) {
            $options['action'] = [
                'title' => Action::getTypeName(Session::getPluralNumber()),
                'page'  => Action::getSearchURL(false),
                'icon'  => Action::getIcon(),
                'links' => ['search' => Action::getSearchURL(false)],
            ];
        }

        // No "Setup" option — config lives in the shared suite tab on
        // Setup > General ({@see SuiteConfig}). front/config.php is still a
        // real, reachable page (direct URL, and the Setup > Plugins cog) for
        // users who hold only plugin_drillreport_config, not core config —
        // see that file's redirect logic.

        if ($options === []) {
            return null;
        }

        // The entry lands on the first page this user may open, so a user who
        // holds only the action right is not sent to a page that denies them.
        return [
            'title'   => __('DR Drill Report', 'drillreport'),
            'icon'    => self::getIcon(),
            'page'    => reset($options)['page'],
            'order'   => self::ORDER,
            'options' => $options,
        ];
    }
}
