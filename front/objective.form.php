<?php

/**
 * DR Drill Report — drill objective controller (child of Drill).
 *
 * CSRF is validated by core before this legacy file runs — never call
 * Session::checkCSRF() here; Html::closeForm() emits the token.
 */

include('../../../inc/includes.php');

use GlpiPlugin\Drillreport\Objective;

Session::checkRight(Objective::$rightname, UPDATE);

$obj = new Objective();

if (isset($_POST['add'])) {
    $obj->add($_POST);
    Html::back();
} elseif (isset($_POST['update'])) {
    $obj->update($_POST);
    Html::back();
} elseif (isset($_POST['purge']) || isset($_POST['delete'])) {
    $obj->delete($_POST, true);
    Html::back();
} elseif (isset($_GET['id'])) {
    $drills_id = (int) ($_GET['parent_id'] ?? 0);
    $menus = ['central' => ['management', ''], 'helpdesk' => []];
    Html::header(Objective::getTypeName(1), '', 'management', \GlpiPlugin\Drillreport\Menu::class);
    (new Objective())->showForm((int) $_GET['id'], ['parent_id' => $drills_id]);
    Html::footer();
} else {
    Html::back();
}
