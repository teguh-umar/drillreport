<?php

/**
 * DR Drill Report — printable report for one drill.
 *
 * ?id=<drills_id>. Read-only. GLPI 11 has no generic page→PDF service, so the
 * layout is print-optimised and the browser's "Save as PDF" is the path
 * (same approach as riskregister's Executive View).
 *
 * CSRF is validated by core before this legacy file runs.
 */

include('../../../inc/includes.php');

use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Drillreport\Drill;
use GlpiPlugin\Drillreport\Report;
use GlpiPlugin\Pgnsuite\Menu as SuiteMenu;

Session::checkRight(Drill::$rightname, READ);

$id    = (int) ($_GET['id'] ?? 0);
$drill = new Drill();
if ($id <= 0 || !$drill->getFromDB($id) || !$drill->can($id, READ)) {
    Html::displayNotFoundError();
}

Html::header(
    sprintf(__('%s — report', 'drillreport'), $drill->fields['name']),
    '',
    SuiteMenu::SECTOR,
    'drillreport',
    'drill'
);

$print_mode = (($_GET['print'] ?? '') === '1');
if (!$print_mode) {
    SuiteMenu::renderSubNav('drillreport', 'drill');
}

TemplateRenderer::getInstance()->display('@drillreport/report.html.twig', [
    'report'     => Report::build($drill),
    'print_mode' => $print_mode,
]);

Html::footer();
