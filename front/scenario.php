<?php

include('../../../inc/includes.php');

use GlpiPlugin\Drillreport\Scenario;

Session::checkRight('plugin_drillreport_config', READ);

Html::header(Scenario::getTypeName(Session::getPluralNumber()), '', 'config', 'commondropdown');
Search::show(Scenario::class);
Html::footer();
