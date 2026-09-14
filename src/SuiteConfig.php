<?php

namespace GlpiPlugin\Drillreport;

use Session;

/**
 * drillreport's section provider for the shared "PGN Suite" tab on
 * Setup > General ({@see \GlpiPlugin\Pgnsuite\ConfigTab}, lib/pgnsuite/).
 *
 * Wired in {@see plugin_init_drillreport()} via
 *   $PLUGIN_HOOKS[ConfigTab::HOOK]['drillreport'] = SuiteConfig::class . '::section';
 *
 * The section renders the same body as the standalone `front/config.php`
 * page ({@see Config::showForm()}) — both post to that same controller.
 */
final class SuiteConfig
{
    /**
     * @return array{title: string, icon: string, order: int, render: string}|null
     *         null when the current user may not see this section at all.
     */
    public static function section(): ?array
    {
        if (!Session::haveRight('plugin_drillreport_config', READ)) {
            return null;
        }

        return [
            'title'  => Drill::getTypeName(2),
            'icon'   => Drill::getIcon(),
            'order'  => 500,
            'render' => self::class . '::render',
        ];
    }

    public static function render(): void
    {
        Config::showForm();
    }
}
