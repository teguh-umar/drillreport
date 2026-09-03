<?php

include('../../../inc/includes.php');

use GlpiPlugin\Drillreport\ActionCategory;

Session::checkRight('plugin_drillreport_config', READ);

Html::header(ActionCategory::getTypeName(Session::getPluralNumber()), '', 'config', 'commondropdown');
Search::show(ActionCategory::class);
Html::footer();
