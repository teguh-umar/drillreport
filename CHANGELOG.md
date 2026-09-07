# Changelog

All notable changes to `drillreport` are documented here.
Format: [Keep a Changelog](https://keepachangelog.com/); this project uses
[Semantic Versioning](https://semver.org/). License: GPL-3.0-or-later.

## [Unreleased]

## [0.2.2] - 2026-09-07

Static self-review against GLPI 11 source (`11.0/bugfixes`) — no VM available.

### Fixed
- `Objective` / `Action` (`CommonDBChild`) — `prepareInputForAdd()` /
  `prepareInputForUpdate()` now call `parent::` first. The overrides were
  skipping the parent-item validation and entity forwarding that
  `CommonDBChild` performs.
- Front controllers pass the sub-menu key as `Html::header()`'s 5th arg
  (`'drill'` / `'action'` / `'setup'`) and `displayFullPageForItem()` gets
  `['management', Menu::class, 'drill']` — otherwise GLPI 11 shows no "+ Add"
  button on the list pages and the wrong breadcrumb (flagged by
  `risk-register-dashboard`, their v1.1.3).
- `Objective` / `Action` set `$checkParentRights = HAVE_VIEW_RIGHT_ON_ITEM`,
  `Drill_Item` sets `$checkItem_1_Rights = HAVE_VIEW_RIGHT_ON_ITEM` — managing a
  drill's children needs only READ on the drill; write is gated by the plugin
  right. (Default `HAVE_SAME_RIGHT_ON_ITEM` would have forced the drill's UPDATE
  right on `_action`-only users.)
- Removed an unsupported `'icon'` option from a `Html::submit()` call.
- `Drill::getSpecificValueToDisplay()` hardened against a missing value key.

## [0.2.1] - 2026-09-07

### Fixed
- `getTabNameForItem()` must be a **non-static** instance method on tab-provider
  classes (`Objective`, `Action`, `Profile`) — `CommonGLPI::getTabNameForItem()`
  is non-static, and for `Profile` the core `\Profile::getTabNameForItem()` is
  too. A `static` override is a fatal "cannot make non static method static" on
  autoload, firing when the relevant form (Setup → Profiles, a drill's tabs)
  opens. `displayTabContentForItem()` stays `static`. (`Drill_Item` was already
  correct.) Second instance of the GLPI-11 signature-tightening class of bug —
  flagged by `risk-register-dashboard` (their v1.1.2).

## [0.2.0] - 2026-09-07

First build packaged for a VM-HQ-GLPI test deploy.

### Added
- Child-record management on the drill form: add / edit / delete `Objective`,
  `Action` and `Drill_Item` rows inline from their tabs
  (`front/objective.form.php`, `action.form.php`, `drill_item.form.php`).
- `Action` — "Send findings to Risk Register" button on a **failed** drill (shown
  only when `riskregister` is active). Upserts one `Finding` per action row on the
  `(entities_id, code)` key and writes `findings_id` + `code` back
  (`Action::sendDrillToRiskRegister()`).
- `Action::ensureRiskCategoryId()` — drillreport owns the "Disaster Recovery"
  `RiskCategory` (riskregister's `categoryIdByName()` returns 0 on a miss and does
  not seed it).
- `Drill_Item` — RTO/RPO **targets** pre-filled from the asset's custom fields via
  the `fields` plugin on link creation (`Drill_Item::fieldsSoftReadTargets()`);
  soft, degrades to no-op when `fields` is inactive or nothing matches. Keys are
  `plugin:drillreport` / `fields_rto_key` + `fields_rpo_key`.
- Asset picker (`Dropdown::showSelectItemFromItemtypes`) to add systems to a
  drill's scope.
- `.gitattributes` — normalize line endings to LF for the Linux target.

### Fixed
- `Menu::canView()` now declares `: bool` — required by GLPI 11.0.x
  (`CommonGLPI::canView()` / `CommonDBRelation::can*` gained a `: bool` return
  type; an untyped override is a fatal compile error that takes the whole
  instance down).

## [0.1.0] - 2026-09-03

Initial installable scaffold.

### Added
- Plugin bootstrap: `setup.php`, `hook.php` (install / uninstall), `composer.json`
  (PSR-4 `GlpiPlugin\Drillreport\`), GPL-3.0 `LICENSE`.
- Schema (8 tables under `glpi_plugin_drillreport_*`): `drills`, `objectives`,
  `actions`, `drills_items`, `scenarios`, `testtypes`, `actioncategories`.
- Itemtypes: `Drill` (`CommonDBTM`), `Objective` / `Action` (`CommonDBChild`),
  `Drill_Item` (`CommonDBRelation`, polymorphic to `Appliance` / `Computer`,
  configurable).
- Dropdowns `Scenario` / `TestType` / `ActionCategory`; `TestType` seeded with the
  five NIST SP 800-84 exercise types.
- Four profile rights: `plugin_drillreport_drill` / `_action` / `_approve` /
  `_config`, granted per profile on install.
- `Management → DR Drill Report` menu node; `Setup` config screen
  (`plugin:drillreport` context); rights tab on core Profiles.
- Cron tasks `DrillReminder` (daily, acts in the configured month) and
  `ActionFollowup` (weekly) — skeleton (logs, no notifications yet).
- List/tab views: drill Search list, cross-drill action list, "DR drill history"
  tab on assets, child tabs on the drill form.
- `Action::toFindingInput()` — maps an action row to a `riskregister` `Finding`
  input (`code = DRILL-<drill_id>-<action_id>`).

### Pending (MVP)
- Twig entry forms (§7.3), printable report (§6), multi-year heatmap (§9.3),
  the "Send to Risk Register" button UI (§5), GLPI notifications for the cron
  tasks, RTO/RPO soft-read from the `fields` plugin (§6.4).
