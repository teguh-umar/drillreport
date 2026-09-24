<?php

namespace GlpiPlugin\Drillreport;

use CommonDBTM;

/**
 * drillreport's section provider for the shared "PGN Suite" tab on asset forms
 * ({@see \GlpiPlugin\Pgnsuite\AssetTab}, lib/pgnsuite/).
 *
 * Wired in {@see plugin_init_drillreport()} via
 *   $PLUGIN_HOOKS[AssetTab::HOOK]['drillreport'] = AssetSections::class . '::sections';
 *
 * Replaces the old standalone "DR drill history" tab ({@see Drill_Item} used to
 * addtabon every configured asset itemtype). The section renders the same body
 * as that tab did ({@see Drill_Item::showForAsset()}) — read-only, no pager, no
 * POST, so no AssetTab::rememberSection() / getItemUrl() plumbing is needed.
 */
final class AssetSections
{
    /** Full section id as AssetTab sees it: "<plugin>-<key>". */
    public const DRILLS = 'drillreport-drills';

    /**
     * @return list<array{key: string, title: string, icon: string, order: int, render: string}>
     */
    public static function sections(CommonDBTM $item): array
    {
        if (!in_array($item::class, Drill_Item::getLinkedAssetItemtypes(), true) || !Drill::canView()) {
            return [];
        }

        return [[
            'key'    => 'drills',
            'title'  => __('DR drill history', 'drillreport'),
            'icon'   => Drill::getIcon(),
            'order'  => 40,
            'render' => self::class . '::renderDrills',
        ]];
    }

    public static function renderDrills(CommonDBTM $item): void
    {
        Drill_Item::showForAsset($item);
    }
}
