<?php

/**
 * DR Drill Report — drill list controller.
 *
 * GLPI 11 validates CSRF in core before this legacy file runs — never call
 * Session::checkCSRF() here.
 */

include('../../../inc/includes.php');

use GlpiPlugin\Drillreport\Drill;
use GlpiPlugin\Pgnsuite\Menu as SuiteMenu;

Session::checkRight(Drill::$rightname, READ);

Html::header(
    Drill::getTypeName(Session::getPluralNumber()),
    '',
    SuiteMenu::SECTOR,
    'drillreport',
    'drill'
);
SuiteMenu::renderSubNav('drillreport', 'drill');

Search::show(Drill::class);

Html::footer();
