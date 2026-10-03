<?php

/**
 * DR Drill Report — improvement-action list controller (cross-drill view).
 */

include('../../../inc/includes.php');

use GlpiPlugin\Drillreport\Action;
use GlpiPlugin\Pgnsuite\Menu as SuiteMenu;

Session::checkRight(Action::$rightname, READ);

Html::header(
    Action::getTypeName(Session::getPluralNumber()),
    '',
    SuiteMenu::SECTOR,
    'drillreport',
    'action'
);
SuiteMenu::renderSubNav('drillreport', 'action');

Search::show(Action::class);

Html::footer();
