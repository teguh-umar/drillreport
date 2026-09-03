<?php

include('../../../inc/includes.php');

use GlpiPlugin\Drillreport\TestType;

Session::checkRight('plugin_drillreport_config', READ);

Html::header(TestType::getTypeName(Session::getPluralNumber()), '', 'config', 'commondropdown');
Search::show(TestType::class);
Html::footer();
