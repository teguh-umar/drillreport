<?php

/**
 * DR Drill Report — improvement-action list controller (cross-drill view).
 */

include('../../../inc/includes.php');

use GlpiPlugin\Drillreport\Action;

Session::checkRight(Action::$rightname, READ);

Html::header(
    Action::getTypeName(Session::getPluralNumber()),
    '',
    'drillreport',
    'action'
);

Search::show(Action::class);

Html::footer();
