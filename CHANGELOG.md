# Changelog

All notable changes to `drillreport` are documented here.
Format: [Keep a Changelog](https://keepachangelog.com/); this project uses
[Semantic Versioning](https://semver.org/). License: GPL-3.0-or-later.

> **Branch note:** `main` carries `Drill::showForm()` as Twig (since v0.8.0).
> A parallel branch **`v0.7-legacy-form`** (forked from tag `v0.7.1`) keeps
> the legacy `showFormHeader()` table form as a maintained fallback — every
> feature/fix here that isn't specific to the Twig form gets backported
> there too, versioned independently as `0.7.x`. See that branch's own
> CHANGELOG.md and hub §7 for the backport checklist.

## [Unreleased]

## [0.8.4] - 2026-09-13

### Fixed
- **"Timeline" heading too small** — `<h5>` → `<h4>`, "(optional)" kept
  smaller/muted inline.
- **The 6 long-text fields (Objectives, Scope summary, Data loss summary,
  Executive summary, Lessons learned, Comments) were half-width and hard to
  read/edit** — `fields.textareaField()` defaults to the same half-width
  column as `htmlField`/other macros (not full-width as assumed). Rather than
  guess at the macro's column-width option a third time, hand-rolled these 6
  as plain full-width `<div class="col-12">` + `<label>` + `<textarea
  class="form-control">` rows — no macro involved, width fully guaranteed.
  Order unchanged (matches the pre-Twig v0.7.1 form). Twig-template-only;
  not backported to `v0.7-legacy-form`.

## [0.8.3] - 2026-09-13

### Fixed
- **Drill form field alignment** — Overall result / Status (rendered via
  `fields.htmlField()`) sit in a differently-sized grid column than
  `numberField`/`dropdownField`; mixed into one continuous flex-wrap row they
  staggered every field after them (RTO/RPO boxes misaligned, uneven widths).
  Forced a line break (`<div class="w-100">`) before and after that pair —
  standard Bootstrap flex-wrap technique, no macro internals to guess at this
  time. Twig-template-only change — not backported to `v0.7-legacy-form`
  (that branch never uses this template).

## [0.8.2] - 2026-09-13

### Changed
- **Drill form layout tightened** — v0.8.1 worked but felt sparser than the
  legacy v0.7.1 table (user feedback: fields squeezed with large empty gaps).
  Root cause: every field macro call was wrapped in its own
  `<div class="col-lg-...">`, nesting Bootstrap columns — `fields_macros`
  already emit their own column sizing internally (confirmed by re-reading
  `generic_show_form.html.twig`: it never wraps a macro call in a `col` div,
  every field sits directly in one `<div class="row flex-row">`). Removed all
  manual per-field column wrappers to match. Not yet re-verified live — the
  macros' own column width classes now drive the layout, unconfirmed how many
  fields land per row.

## [0.8.1] - 2026-09-13

### Fixed
- **Drill add/edit form crashed** (`Dropdown::showFromArray(): Argument #2
  ($elements) must be of type array, string given` at `drill.html.twig:90`) —
  `fields.dropdownArrayField()`'s real macro signature does not match the
  `overall_result`/`status` value=>label map I fed it (its one confirmed core
  call-site passes a plain list of itemtype class names, not an assoc map —
  a different use case). Replaced both calls with the legacy-proven
  `Dropdown::showFromArray()` PHP call itself, captured via `{% do call() %}`
  and shown through `fields.htmlField()` — same call the pre-v0.8.0 form used
  successfully, no macro-signature guessing left for these two fields.
  `fields.textField`/`dateField`/`dropdownField` (Scenario/TestType/Group/User)
  were confirmed correct on the live VM before the crash.
- **Duplicate title/entity/actions banner** on an existing drill's form — a
  missing `no_header` computation before including `components/form/header.html.twig`
  (present in GLPI core's own `generic_show_form.html.twig`, omitted here).
  Now suppresses the redundant header for existing items, matching core forms
  (only shown for a brand-new item or inside a modal).

### Still unverified
- `fields.numberField` (RTO/RPO minutes), `fields.datetimeField` (Timeline),
  `fields.textareaField` (Objectives/Scope/Data loss/Executive
  summary/Lessons learned/Comments) were never reached by the v0.8.0 crash —
  confirm these render correctly on the next live check.

## [0.8.0] - 2026-09-12

### Changed
- **Drill form migrated to Twig.** `Drill::showForm()` now renders
  `templates/drill.html.twig` via `TemplateRenderer`, built from GLPI 11's own
  generic form scaffolding (`components/form/header.html.twig` +
  `fields_macros.html.twig` + `buttons.html.twig` + `dates.html.twig`) — the
  same building blocks core forms (Software, Contract, SoftwareLicense, ...)
  use — instead of the legacy `showFormHeader()`/`Html::input()` table. Same
  fields, same order, same behavior; only the rendering layer changed.
  **Higher-risk change than usual**: the exact `fields_macros` macro
  signatures were inferred from real call-sites in GLPI 11 core
  (`templates/generic_show_form.html.twig`), not from the macro definitions
  themselves (not present in the sparse local GLPI checkout) — **verify the
  Drill add/edit form immediately after deploying this version.** Rollback is
  one command: redeploy `drillreportv0.7.1.zip`.

### Added
- `Seed::seedNotifications()` — install now wires the two v0.7.0 events to a
  default Notification + NotificationTemplate each (Indonesian subject/body,
  `is_active=1`, recipient = entity administrator), so `DrillReminder`/
  `ActionFollowup` send out of the box instead of needing the manual Setup
  walkthrough first. Idempotent: only creates one if none exists yet for that
  itemtype+event, and never edits one that already exists (admin-made or
  previously seeded) — safe to re-run on every upgrade. Wrapped in try/catch
  in `hook.php`; a failure here logs to `drillreport_install.log` and does
  not abort the rest of install. **Needs a plugin re-install to take effect
  on an already-installed site:**
  `bin/console glpi:plugin:install drillreport --force`.
  `docs/notifications.md` updated — add a Group recipient if entity admin
  email isn't the right channel.

## [0.7.0] - 2026-09-12

### Added
- **Real cron notifications.** `DrillReminder` and `ActionFollowup` now raise
  GLPI `NotificationEvent`s (`NotificationTargetDrill` / `NotificationTargetAction`,
  events `reminder` / `overdue`) instead of only logging. `Drill` and `Action`
  are registered into `$CFG_GLPI['notificationtemplates_types']` so they're
  selectable itemtypes in Setup → Notifications. Recipients are left to the
  admin (Group / Profile / Administrator, standard GLPI options) since the DR
  group differs per entity. `raiseEvent()` no-ops safely until a Notification
  is configured — see `docs/notifications.md` for the one-time setup + tag
  reference. No schema or config change.

## [0.6.3] - 2026-09-10

### Fixed
- Editing or deleting a child row (system in scope / objective / improvement
  action) from its own page left you on that now-stale page — after a delete,
  `Html::back()` returned to a dead URL. All three child controllers now
  redirect to the **parent drill** after add / update / delete.

### Added
- A **"← Back to drill"** button at the top of every child edit form
  (system in scope, objective, improvement action).

### Changed
- Sample drill (`src/Sample.php`) now carries the **real CRM DRC drill of
  23 Aug 2026**, transcribed from the two source documents — 7 timed runbook
  activities (planned vs actual), 5 improvement findings, full timeline and
  narrative. Still `[SAMPLE]`-marked and removable from Setup. The 8-server
  "Systems in scope" table still can't be seeded (assets may not exist) — one
  representative row is linked, add the rest per `docs/entering-a-real-drill.md`.

## [0.6.2] - 2026-09-10

### Fixed
- Objectives tab — an objective / activity saved with a blank name produced a
  row that could not be opened or deleted. `Objective::prepareCommon()` now
  rejects a blank name on add/update, and the drill's Objectives table renders
  a clickable *(untitled #N)* placeholder for any legacy nameless row so it can
  still be opened and purged.

## [0.6.1] - 2026-09-10

### Fixed
- `plugin_drillreport_install()` — `$DB` was used without `global $DB`, so the
  v0.6.0 in-place `addField` guard threw *Call to a member function tableExists()
  on null* during `plugin:install`. Added `global $DB;`. Install is idempotent —
  just re-run it.

## [0.6.0] - 2026-09-10

### Added
- **`Drill_Item.dr_target`** — the DR failover target (replica name / IP) for a
  system in scope. Shown on the row form, the "Systems in scope" tab, and as a
  "DR failover target" column in report §3 when any row has it. Eases audit —
  the primary→DR pairing is explicit and comparable, not buried in a comment.
- **`Objective.evidence_ref`** — the screenshot / file name(s) proving that
  activity. Shown on the objective form, the tab, and report §6 "Evidence"
  column. Attach the actual files on the drill's Documents tab.
- `docs/evidence-checklist.md` — the ISO 22398 / ISO 22301 §9.1 evidence set for
  a live failover drill (before / during / validation / failback / sign-off) and
  how each artifact maps to the plugin.
- In-place `addField` migration for both columns (runs on `plugin:install`).

## [0.5.0] - 2026-09-10

### Added
- **Objectives are now timed activities.** `Objective` gains `target_minutes`
  (planned / budget duration), `start_time`, `end_time` and an optional
  `plugin_drillreport_drills_items_id` (link to a system in scope). Actual
  duration = end − start. `result` (met / partial / not-met) can be entered by
  hand or ticked **"auto from timing"** — actual ≤ target → met, ≤ target×1.25 →
  partial, else not-met. The Objectives tab shows a **Total** (Σ target / Σ actual).
  In-place `addField` migration; runs on `plugin:install`.
- **Report §5 RTO** falls back to the objective totals when the drill's own RTO
  target / actual are left blank (docs §6.6 rule: RTO = sum of activity
  plan / actual durations), with a note saying so. §6 gains Target / Actual
  columns and a Total row when any objective is timed.
- `docs/entering-a-real-drill.md` — a worked walkthrough for entering the real
  CRM Failover drill (Aug 2026) field by field.

### Changed
- Setting a drill to **Approved** now stamps `approved_by` + `approval_date`
  automatically (first time only).

## [0.4.2] - 2026-09-10

### Fixed
- Printed report kept its navy table headers / colour fills — browsers drop
  background colours in print by default, which turned the white header text
  invisible. Added `print-color-adjust: exact` (report-scoped) and `!important`
  header colours in the `@media print` block, plus `@page { size: A4 portrait }`.

## [0.4.1] - 2026-09-09

### Fixed
- `Config::setDefaults()` (run on `plugin:install`) now resets a stored
  `report_sections` that still holds keys from an older version
  (`participants` / `evidence`) so the new "Remediation Summary" section shows
  and dropped sections disappear on an in-place upgrade.

## [0.4.0] - 2026-09-09

### Changed
- **Report re-styled to the reference AAR/IP document format** — numbered
  sections (`1. Executive Summary` … `9. Approval`), a header info table
  (Entity / Date / Result / Status), navy table headers, serif body, and:
  - Scenario Overview merges the old Overview + Participants and lists the
    objectives as bullets.
  - Systems in Scope shows `order (recovered)` in one column.
  - Timeline is a Milestone / Time table with a computed "Actual recovery
    duration" row (recovery − declaration), and a note when unfilled.
  - RTO/RPO has explicit "Overall Summary" / "Per System" sub-tables.
  - Findings: each recommendation is auto-split into bullets when it is
    enumerated ("1) … 2) …" or line-per-item); the Evidence line moves into
    this section's header.
  - **New "8. Remediation Summary"** — Severity / Finding / Due Date / Status
    table of all findings, severity-coloured.
  - Footer note (ISO 22398 / NIST SP 800-84; SAMPLE marker when demo data).
- `report_sections` default updated to the new 9 keys
  (`summary,overview,scope,timeline,rto_rpo,objectives,findings,remediation,approval`).

## [0.3.9] - 2026-09-09

### Fixed
- Printing the report (`front/report.php` → Print / Save as PDF) now hides the
  GLPI chrome — sidebar, secondary bar, debug toolbar, footer, banners — and
  lets the report fill the sheet. GLPI's top header already carries
  `d-print-none`; the `@media print` block in `report.html.twig` handles the rest.

## [0.3.8] - 2026-09-09

### Changed
- `fields_rto_key` / `fields_rpo_key` now default to empty ("— not used —").
  The earlier `rto_target_minutes` / `rpo_target_minutes` placeholders showed as
  "(not found)" on the Setup screen because they rarely match a real `fields`
  column. `Config::setDefaults()` (run on `plugin:install`) self-heals an existing
  install: a stored key that is not an actual `fields` column is blanked.

## [0.3.7] - 2026-09-09

### Changed
- Setup → "Assets in scope" — no more free text:
  - **Asset types** is now a multi-select of real GLPI asset / management types
    (`Config::assetItemtypeChoices()` — the curated DR list plus everything GLPI
    declares in `asset_types` / `management_types`, including GLPI 11 custom asset
    definitions). A mistyped or unknown value can't be saved.
  - **RTO / RPO target column** — when the `fields` plugin is active, a dropdown
    of the columns it actually exposes on any `glpi_plugin_fields_*` table
    (`Drill_Item::availableFieldsColumns()`), with a "— not used —" option. Falls
    back to a plain text field only when `fields` is inactive, with an inline
    note explaining the state.

## [0.3.6] - 2026-09-09

### Added
- **Timeline fields on the drill form** — "Disaster declared", "Service
  recovered", "Validation complete" (datetime, optional). They were storable in
  the schema and shown on the report but had no form input. The `plugin:drillreport`
  / `show_timeline_fields` toggle now controls only whether the report renders the
  Timeline section; the form always captures them.
- Sample drill fills the timeline (08:12 → 09:47 → 10:35).

## [0.3.5] - 2026-09-09

### Added
- **Sample drill** — Setup screen → "Sample data" → "Create the sample drill".
  Loads one fully worked demo drill (3 objectives, 3 improvement actions, and a
  system in scope if any asset exists) matching docs §6.6, so a fresh install
  has a concrete reference. Every row is prefixed `[SAMPLE]`; a "Remove sample
  data" button purges them. Gated on `_config` UPDATE + `_drill` CREATE/PURGE.
- `Drill::cleanDBonPurge()` — permanently deleting a drill now also purges its
  `Objective` / `Action` rows (soft-delete still keeps them for a lossless
  restore; `Drill_Item` was already handled by the `item_purge` hook).

## [0.3.4] - 2026-09-09

### Added
- `ActionCategory` is seeded on install with a 9-item BCP/DR starter taxonomy
  (Runbook / procedure, Backup & recovery, Infrastructure / capacity,
  Network & connectivity, Data integrity, Staffing & training,
  Monitoring & alerting, Third-party / vendor, Documentation). Idempotent —
  the admin can rename, deactivate, delete or add. `Scenario` stays empty
  (entirely org-specific). Re-run `bin/console glpi:plugin:install drillreport`
  to seed on an already-installed instance.
- Design doc §6.6 — a fully worked Improvement Action example (all fields +
  the resulting Risk Register Finding).

## [0.3.3] - 2026-09-08

### Changed
- Drill form: RTO/RPO **target and actual** now sit side by side on one row each
  (was one field per row).

## [0.3.2] - 2026-09-08

First fix from the VM-HQ-GLPI production deploy — install + activate were clean
(no class-load fatal), adding a drill failed on save.

### Fixed
- Saving a `Drill` / `Drill_Item` / `Action` with an empty numeric or date field
  threw `Incorrect integer value: '' for column …` — MySQL strict mode rejects
  `''` for `INT` / `DATE` / `DATETIME`. `prepareInputFor{Add,Update}` now maps an
  empty value to `'NULL'` (GLPI's sentinel, converted to SQL `NULL` in
  `CommonDBTM::addToDB()` / the query builder) for: `Drill` — `date_drill`,
  `declaration_time`, `recovery_time`, `validation_time`, `approval_date`,
  `rto_target_minutes`, `rto_actual_minutes`, `rpo_target_minutes`,
  `rpo_actual_minutes`; `Drill_Item` — the four RTO/RPO columns + `recovery_order`
  (and a `prepareInputForUpdate` override, which was missing); `Action` —
  `date_due`.
- Confirmed on the VM: raw `timestamp NULL` columns for `date_creation` /
  `date_mod` install and insert fine — no `$migration->addField()` change needed.

## [0.3.1] - 2026-09-07

### Fixed
- `front/config.php` — the Setup form posted to `$_SERVER['PHP_SELF']`, which in
  GLPI 11 points at `public/index.php` (not the running script), so the POST hit
  the native inventory endpoint instead of saving the config. Now builds the URL
  with `Html::getPrefixedUrl('/plugins/drillreport/front/config.php')`.
  `Html::header()`'s ignored 2nd `$url` arg is passed `''` in `config.php` /
  `report.php`. (Flagged by `risk-register-dashboard`, their v1.2.3.) The
  `src/*` forms already used `Itemtype::getFormURL()` and were unaffected.

## [0.3.0] - 2026-09-07

### Changed
- **Menu is now its own top-level sidebar sector** (`drillreport`), not a node
  under Management. GLPI 11's `menu.html.twig` renders only `content` entries
  that carry a `page` — never an `options` array — so the old "one node +
  options" layout showed just "DR Drill Report → DR Drill Report" in the sidebar.
  `Menu::getMenuContent()` now returns `is_multi_entries` with Drills /
  Improvement actions / Setup entries; `$PLUGIN_HOOKS['menu_toadd']` uses the
  array-of-classes form. Front controllers pass sector `'drillreport'` + the
  entry key. (Pattern from riskregister v1.2.0.)

### Added
- **Printable drill report** — `front/report.php?id=<drill>` renders the full
  ISO 22398 / NIST SP 800-84 AAR/IP structure (executive summary, overview,
  participants, systems in scope, timeline, RTO/RPO target-vs-actual with a
  met/not-met marker, objectives assessment, findings & improvement plan,
  evidence, approval) via `@drillreport/report.html.twig`. Print-optimised CSS +
  a "Print / Save as PDF" button. Sections are toggled by
  `plugin:drillreport` / `report_sections`; the Business Criticality column
  follows `show_business_criticity`; the timeline block follows
  `show_timeline_fields`. `src/Report.php` assembles the data (plain scalars —
  no pre-rendered HTML). A "View / print report" button is on the drill form.

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
