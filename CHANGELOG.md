# Changelog

All notable changes to `drillreport` are documented here.
Format: [Keep a Changelog](https://keepachangelog.com/); this project uses
[Semantic Versioning](https://semver.org/). License: GPL-3.0-or-later.

> **Branch note:** this is the `v0.7-legacy-form` branch, forked from tag
> `v0.7.1` — the last release before `Drill::showForm()` moved to Twig
> (`main`/`master`'s v0.8.0+). It keeps the legacy `showFormHeader()` table
> form. Every feature/fix landed on `main` from v0.8.1 onward that isn't
> specific to the Twig form gets backported here too, versioned
> independently as `0.7.x` (next: `0.7.2`) — so this branch is a live
> fallback, not a frozen snapshot. Rollback: redeploy whichever
> `drillreportv0.7.x.zip` is newest on this branch. See hub §7 / memory for
> the backport checklist.

## [Unreleased]

## [0.7.14] - 2026-10-04

Backported from `main` v0.12.0.

**Upgrade needs the install step** (new column):
`bin/console glpi:plugin:install drillreport --force`, then
`glpi:plugin:activate drillreport` if it was deactivated.

### Added
- **Move an improvement action to another drill.** The action's edit form has
  a "DR drill" dropdown; picking another drill and saving moves the action
  there. Its asset link is cleared (it pointed at a system of the old drill).
  Offered to users who hold Update, Create and Delete permanently on
  improvement actions, which is what core requires for a parent change.
- **Carry over an action from another drill.** The drill's "Improvement
  actions" tab has a "Carry over an action from another drill" picker listing
  actions of the other drills the user may see. It **copies** the action into
  this drill (finding, category, severity, status, owner, due date,
  observation, root cause, recommendation); the original stays on its drill.
  The copy shows "Carried over from <drill> (<date>)" in the tab, on its edit
  form and in the printable report. An action can be carried into a drill once.
- Column `glpi_plugin_drillreport_actions.source_actions_id` (INT UNSIGNED,
  0 = created here) records the action a copy came from.

### Changed
- "Send findings to Risk Register" reuses an action's stored Risk Register
  code, so an action that was sent and then moved keeps updating the same
  Finding instead of creating a second one. A carried-over copy is a new
  action with its own code.
## [0.7.13] - 2026-10-04

### Added
- Backported from `main` v0.11.1.
- **Setup: "Sections shown in the printable report"** — a multi-select for the
  `report_sections` config value, which until now had no field and could only
  be changed in `glpi_configs`. Lists the 9 report sections; the report keeps
  their fixed order and renumbers the headings. Saving with nothing selected
  stores every section. Unknown keys are dropped on save.

### Changed
- `Report::sectionTitles()` is public (the Setup field uses it for labels);
  the enabled-section logic moved to `Config::reportSections()`.

## [0.7.12] - 2026-10-04

### Changed
- Backported from `main` v0.11.0.
- **Sidebar: "DR Drill Report" is now one entry in the suite's shared menu
  sector** (default label "ICT Tools", order 30) instead of a top-level sector
  of its own. `lib/pgnsuite/Menu.php` **r1** (SHA-256
  `FC0B96219DD489639DEECD720197BA6B9810F6303A28CE7DB891139E7913CBFE`) is
  vendored byte-identical and marked `-text`. `Menu::entry()` supplies the
  entry; its options are **DR drills** and **Improvement actions**, shown as a
  sub-nav at the top of the plugin's pages. The entry opens the first page the
  user may read, and is hidden for a user with neither right. **Users must log
  in again** after the upgrade to see the new menu.
- All 8 front controllers pass the shared sector to `Html::header()` /
  `displayFullPageForItem()`. The printable report skips the sub-nav with
  `?print=1` and hides it in the browser's print output.
- `lib/pgnsuite/ConfigTab.php` updated to canonical **r4** (SHA-256
  `F94A80C1C813A951919A20D2521DE35B431F9C836BBCCE89BAC70E544D0B80FA`): the
  Setup > General tab is titled with the suite label and has a "General"
  section where a core-config admin renames it.
- `lib/pgnsuite/AssetTab.php` updated to canonical **r2** (SHA-256
  `F8629CCA6D141B1E9271686C8D7F9D65E4FC84E39CF07E85B10FBC849A0B2466`): the
  asset tab is titled with the same suite label.
- Plugin author is now `ICT` (suite convention).

### Removed
- The plugin's own `drillreport` sidebar sector.

## [0.7.11] - 2026-09-24

### Changed
- Backported from `main` v0.10.2. `lib/pgnsuite/ConfigTab.php` updated to canonical
  **r3** (SHA-256 `008C4C817DB2E42040915136108CD41917C34760A8F4CDDC60E46BDB208F39D0`).
  Visual only: Setup > General "PGN Suite" sub-nav sized to its longest title
  (max 16rem, title as tooltip) instead of `col-md-3`. `CONTRACT_VERSION` unchanged.

## [0.7.10] - 2026-09-24

### Fixed
- Backported from `main` v0.10.1.
- **GLPI 11 custom assets (e.g. Security, Storage) could never be used as
  drill assets** - not selectable in Setup, no "PGN Suite" section on their
  form, no purge cascade. `plugin_init` runs before core boots custom asset
  definitions (`InitializePlugins` priority 110 > `CustomObjectsBoot` 100,
  `11.0/bugfixes` `ListenersPriority`), so at that moment they are neither in
  `$CFG_GLPI['asset_types']` nor autoloadable; `Config::assetItemtypeChoices()`
  (statically cached for the request) silently dropped them. It now also
  reads active definitions straight from `glpi_assets_assetdefinitions`
  (`Glpi\CustomAsset\<system_name>Asset`, label from the `label` column).
  Found while answering VM check 2 of the PGN Suite asset-tab contract.
  After deploying, add them in drillreport Setup > Asset itemtypes.
## [0.7.9] - 2026-09-24

### Changed
- Backported from `main` v0.10.0 (not Twig-form-specific).
- **"DR drill history" moved into the shared "PGN Suite" asset tab** (suite
  contract v1 r1, user decision 2026-09-24, hub §5/§7). On every configured
  asset itemtype (`asset_itemtypes`, default `Appliance`, `Computer`) the
  PGN plugins' tabs now collapse into one "PGN Suite" tab with horizontal
  sub-tabs; drillreport's is "DR drill history" (order 40). `Drill_Item` no
  longer `addtabon`s asset itemtypes — only `Drill` ("Systems in scope").
  The rights tab on `Profile` is unchanged.
- The section renders the same `Drill_Item::showForAsset()` body the old tab
  did (now public). It is read-only (no POST, no pager, no DOM ids), so
  `rememberSection()`/`getItemUrl()` have nothing to hook into; there were no
  `forcetab=…Drill_Item$1` links to rewrite. The old tab's row-count number
  is gone (sections carry no count — suite UI rule).

### Added
- `lib/pgnsuite/AssetTab.php` — vendored byte-identical from the canonical
  `shared/pgnsuite/AssetTab.php` r1 (SHA-256
  `C7F6C88B58BE9A7AAF172F3A2F2DBAAFBB07B0CE1898BA5A74A0EDB61EA143A9`),
  marked `-text` in `.gitattributes`. Never edit this copy.
- `src/AssetSections.php` — section provider: `[]` unless the itemtype is in
  `asset_itemtypes` and the user has `plugin_drillreport_drill` READ.

## [0.7.8] - 2026-09-16

### Fixed
- **PRODUCTION-DOWN HOTFIX — `Config::showForm()` static fatal.** Backported
  from `main` v0.9.5. `Config extends CommonDBTM`, whose `showForm($ID,
  $options)` is an instance method — declaring the override `static`
  fatals on class load, aborting `Plugin::load()` for drillreport and
  breaking every core page that calls it (confirmed live on VM-HQ-GLPI:
  search pages down, not just drillreport's own screens, right after
  deploying `main`'s v0.9.4 — this branch was never actually deployed with
  the bug, but shipped the same broken code and would have hit identically
  the moment it was). Fixed: `showForm()` is now a proper instance method;
  both call sites (`front/config.php`, `src/SuiteConfig.php`) updated to
  `(new Config())->showForm()` in this same commit.

## [0.7.7] - 2026-09-16

### Fixed
- **Super-Admin identified by profile name, not capability** — backported
  from `main` v0.9.4. `hook.php`'s full-rights seeding matched the literal
  string `'Super-Admin'`, which is renameable and localised; on a renamed
  profile the check silently matched nothing and that profile lost the
  "always full rights on upgrade" guarantee with no error. Now identifies
  the full-rights profile by testing the core `config` UPDATE right
  instead. If no profile passes that test, nothing extra is seeded — never
  falls back to granting everyone full rights. Flagged by
  `pgn-glpi-suite-70` (hub §4, 2026-09-16); riskregister's equivalent fix
  went the other direction (filters Super-Admin through the same
  new-rights-only path as every other profile) after the user decided
  drillreport's "always full" convention is the one the suite follows.

## [0.7.6] - 2026-09-15

### Fixed
- **`lib/pgnsuite/ConfigTab.php` was still r1 on this branch** — v0.9.2's
  canonical-r2 update (readable active sub-tab, `--glpi-tabs-*` CSS vars)
  was only applied to `main`. Re-vendored here too: SHA-256
  `347d56c91508e53326c8d4de74105628d0fd535bde619fe8a94eb147a448cbd6`,
  verified inside the built zip. `CONTRACT_VERSION` unchanged.
- `docs/deploy-checklist.md` stale content, found while writing the user
  manual: table count said 8 (actually 7), the sidebar "Setup" sub-entry it
  checked for was removed in v0.7.3, the Setup-page save-flow check didn't
  account for the v0.7.3+ PGN Suite tab redirect, `Administration →
  Profiles` (not `Setup → Profiles`) is GLPI 11's actual location, and the
  `report_sections` check told testers to set it from a Setup field that
  doesn't exist (flagged, not built here — no UI for it yet).

## [0.7.5] - 2026-09-15

### Fixed
- **Profile rights overwritten on every `plugin:install --force` upgrade** —
  backported from `main` v0.9.3. Same `hook.php` code, same fix: the
  per-profile default-grant loop is now gated on `$toAdd` (rights newly
  created this run) instead of running unconditionally on every install, so
  a hand-tuned rights matrix survives routine upgrades. Super-Admin stays
  unconditional.

## [0.7.4] - 2026-09-14

### Fixed
- **v0.7.3's backport was incomplete**: `Config::showForm()` was added to
  `src/Config.php` but `front/config.php`'s body was never actually replaced
  with a call to it — the page kept rendering its own duplicate inline copy
  (worked fine on its own, but not what the v0.7.3 changelog claimed, and
  not shared with the PGN Suite tab section as documented). Fixed now: the
  body is a single `Config::showForm();` call, same as `main`.
- **Missing GET redirect to the PGN Suite tab** (same gap as `main` v0.9.1,
  caught by peer review): opening `front/config.php` directly (the "Config"
  cog in Setup → Plugins) still showed the standalone page for a
  core-`config`-READ user; only the POST/save paths redirected. Added the
  same `Session::haveRight('config', READ)` redirect before `Html::header()`.

## [0.7.3] - 2026-09-14

### Added
- **"PGN Suite" shared config tab** — backported from `main` v0.9.0 (not
  Twig-form-specific). `lib/pgnsuite/ConfigTab.php` (byte-identical vendored
  copy, SHA-256 verified:
  `89e864d926385ebb09f463a5968fe0e5971503b8e9567540feba56371637ac40`,
  `.gitattributes` marks it `-text` so line-ending normalization never
  touches it — a commit+checkout round trip was seen to flip LF→CRLF on an
  already-LF file for another plugin, SAC, despite `eol=lf`) +
  `src/SuiteConfig.php` (section provider, gated on
  `plugin_drillreport_config` READ). `Config::showForm()` extracted so the
  standalone `front/config.php` page and the tab section share one
  implementation. Sidebar "Setup" menu entry removed (`src/Menu.php`); the
  page stays reachable directly, with core-config-READ users redirected back
  to the PGN Suite tab after saving.

## [0.7.2] - 2026-09-13

### Fixed
- **Signed foreign key** — backported from `main` v0.8.5 (suite-wide unsigned-
  keys audit). `glpi_plugin_drillreport_objectives.plugin_drillreport_drills_items_id`
  was created as signed `INT` — the v0.5.0 `addField(..., 'integer', ...)`
  call used GLPI's plain-integer type instead of `'fkey'` (resolves to
  `INT UNSIGNED`). Every other `*_id`/`id` column across all 8 tables is
  already `int unsigned`; no negative sentinel values found anywhere.
  `addField()` now uses `'fkey'` and a `changeField()` retrofit converts
  already-existing installs (safe no-op if already unsigned).

## [0.7.1] - 2026-09-12

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
