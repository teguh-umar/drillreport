<?php

/**
 * DR Drill Report — single drill controller.
 *
 * Modeled on GLPI 11 core front/*.form.php. CSRF is validated by core before
 * this legacy file runs — never call Session::checkCSRF() here.
 */

include('../../../inc/includes.php');

use Glpi\Event;
use GlpiPlugin\Drillreport\Drill;
use GlpiPlugin\Pgnsuite\Menu as SuiteMenu;

if (!isset($_GET['id'])) {
    $_GET['id'] = '';
}

$drill = new Drill();

if (isset($_POST['add'])) {
    $drill->check(-1, CREATE, $_POST);
    if ($newID = $drill->add($_POST)) {
        Event::log($newID, 'drills', 4, 'management', sprintf(__('%1$s adds the item %2$s'), $_SESSION['glpiname'], $_POST['name'] ?? ''));
        if ($_SESSION['glpibackcreated']) {
            Html::redirect($drill->getLinkURL());
        }
    }
    Html::back();
} elseif (isset($_POST['update'])) {
    $drill->check($_POST['id'], UPDATE);
    $drill->update($_POST);
    Event::log($_POST['id'], 'drills', 4, 'management', sprintf(__('%s updates an item'), $_SESSION['glpiname']));
    Html::back();
} elseif (isset($_POST['delete'])) {
    $drill->check($_POST['id'], DELETE);
    $drill->delete($_POST);
    Event::log($_POST['id'], 'drills', 4, 'management', sprintf(__('%s deletes an item'), $_SESSION['glpiname']));
    $drill->redirectToList();
} elseif (isset($_POST['restore'])) {
    $drill->check($_POST['id'], DELETE);
    $drill->restore($_POST);
    $drill->redirectToList();
} elseif (isset($_POST['purge'])) {
    $drill->check($_POST['id'], PURGE);
    $drill->delete($_POST, true);
    Event::log($_POST['id'], 'drills', 4, 'management', sprintf(__('%s purges an item'), $_SESSION['glpiname']));
    $drill->redirectToList();
} else {
    $menus = ['central' => [SuiteMenu::SECTOR, 'drillreport', 'drill'], 'helpdesk' => []];
    Drill::displayFullPageForItem($_GET['id'], $menus, [
        'formoptions' => 'data-track-changes=true',
    ]);
}
