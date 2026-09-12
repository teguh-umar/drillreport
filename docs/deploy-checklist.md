# drillreport — deploy & first-run checklist (VM-HQ-GLPI)

**Target:** VM-HQ-GLPI (`10.129.2.174`, via JumpServer bastion `paman.pgn.co.id:2222`), GLPI root `/data/www/glpi`
**Artifact:** `drillreportv<version>.zip` — archive root `drillreport/`, git-tracked files only, no `.git/`
**GLPI:** 11.0.x · **PHP:** 8.2+ (VM is 8.4)

This is the **first** deploy — drillreport has never run on a real GLPI. Run top
to bottom; fix any failure as its own commit, rebuild the zip, redeploy, re-run
the section.

VM-HQ-GLPI is a **shared production instance** (integrasipaman v1.11.0 is live on
it). A class-load fatal in a plugin takes the whole instance down — do §0.5
first.

---

## 0. Build the zip (on the workstation)

```bash
cd "…/Documents/Drill Report GLPI/drillreport"
git archive --format=zip --prefix=drillreport/ -o "../drillreportv$(grep -oP "VERSION', '\K[^']+" setup.php).zip" HEAD
```

The zip lands next to the repo dir (same convention as `riskregisterv*.zip`).
Confirm: `unzip -l ../drillreportv*.zip` → root is `drillreport/`, no `.git/`.

## 0.5 Production pre-flight (do this BEFORE §1)

- [ ] DB backup: `sudo -u nginx mysqldump --single-transaction glpi | gzip > ~/glpi-predrill-$(date +%F).sql.gz` (or GLPI's own Maintenance → Backup)
- [ ] Note the current active-plugin list: `sudo -u nginx php bin/console glpi:plugin:list`
- [ ] Confirm you can reach the box on a second SSH session (for rollback)
- [ ] **Rollback plan** if the login page dies after activate:
      `cd /data/www/glpi/plugins && sudo rm -rf drillreport && sudo systemctl reload php-fpm`
      — GLPI ignores a plugin dir that isn't there; the instance comes straight back.
      (If it was activated first: `sudo -u nginx php bin/console glpi:plugin:deactivate drillreport` also works, but removing the dir is the sledgehammer.)
- [ ] Pick a low-traffic window; keep `tail -f files/_log/php-errors.log` open throughout

## 1. Upload & extract

- [ ] Upload `drillreportv<version>.zip` (JumpServer file transfer / scp).
- [ ] `cd /data/www/glpi/plugins && sudo unzip -o /path/drillreportv<version>.zip` → creates `drillreport/`
- [ ] `sudo chown -R nginx:nginx /data/www/glpi/plugins/drillreport`
- [ ] `grep VERSION /data/www/glpi/plugins/drillreport/setup.php` → matches
- [ ] `tail -f /data/www/glpi/files/_log/php-errors.log` in another shell for the rest of this pass

## 2. Install

- [ ] `cd /data/www/glpi && sudo -u nginx php bin/console glpi:plugin:install drillreport --username=<admin>`
- [ ] `sudo -u nginx php bin/console glpi:plugin:activate drillreport`

> **Upgrading an already-installed drillreport** (not a first install): the web
> **Setup → Plugins → Upgrade** button is unreliable on VM-HQ-GLPI's GLPI 11
> build (`BadRequestHttpException` at `front/plugin.form.php`). Use the CLI:
> `sudo -u nginx php bin/console glpi:plugin:install drillreport --force`
> then `glpi:plugin:activate drillreport` if it deactivated. `--force` is
> required — `isAlreadyInstalled()` counts an ACTIVATED plugin as installed.
> Safe: `plugin_drillreport_install()` is idempotent (missing tables only,
> `addField` for new columns, config top-up, `array_diff` rights) — no DROP.
> A schema-only change (no seed/column change) needs just a file overwrite +
> `glpi:cache:clear` + `systemctl reload php-fpm`, no re-install.
- [ ] **Open the GLPI login page** — confirm it still loads (guards against a
      class-load fatal).
- [ ] Open **Setup → Profiles → (any profile) → DR Drill Report tab** — confirm it
      renders (guards against the `getTabNameForItem` static/instance fatal).
- [ ] `mysql> SHOW TABLES LIKE 'glpi\_plugin\_drillreport\_%';` → **8 rows**
      (`drills`, `objectives`, `actions`, `drills_items`, `scenarios`,
      `testtypes`, `actioncategories`)
- [ ] `mysql> SELECT name FROM glpi_plugin_drillreport_testtypes ORDER BY id;`
      → 5 seeded (Tabletop / Walkthrough / Simulation / Parallel test / Full failover)
- [ ] `mysql> SELECT name FROM glpi_profilerights WHERE name LIKE 'plugin_drillreport_%' GROUP BY name;`
      → 4 (`_drill` / `_action` / `_approve` / `_config`)
- [ ] Setup → Automatic actions → 2 tasks `DrillReminder`, `ActionFollowup` present, state = "Scheduled"
      (since v0.7.0 these raise real notifications once configured — see
      `docs/notifications.md` for the one-time Setup → Notifications wiring)

## 3. UI smoke test (as Super-Admin)

- [ ] **"DR Drill Report" appears as its own top-level sidebar sector** (with the
      `ti-refresh-alert` icon), expanding to **Drills / Improvement actions /
      Setup** — NOT collapsed to a single "DR Drill Report → DR Drill Report" line
- [ ] On the **Drills** list page — the breadcrumb reads "DR Drill Report ›
      DR drills" and a **"+ Add"** button is present (guards against the menu
      `content`/`is_multi_entries` regression)
- [ ] Create a drill (name, date, scenario, test type, group in charge, result = `fail`)
- [ ] Drill form tabs: **Objectives**, **Improvement actions**, **Systems in scope** —
      each shows its add form
- [ ] Add an objective (result dropdown) → saved, shows in the list, editable
- [ ] Add an improvement action (severity, owner group, due date) → saved
- [ ] Systems in scope → asset picker lists `Appliance` + `Computer`; add one →
      row appears with RTO/RPO columns
- [ ] Open the linked `Appliance` / `Computer` → **"DR drill history"** tab shows the drill
- [ ] Setup screen (`front/config.php`) loads; toggle a value, **Save** → the page
      reloads on `/plugins/drillreport/front/config.php` with "Configuration
      updated." (NOT a redirect to `/` or an inventory-XML error), value persisted
- [ ] Drill form → **"View / print report"** button → `front/report.php` renders
      all sections (summary, overview, participants, scope, timeline if enabled,
      RTO/RPO with met/not-met marks, objectives, findings, evidence, approval)
- [ ] Report **"Print / Save as PDF"** button opens the browser print dialog; the
      toolbar / GLPI chrome are hidden in the print preview
- [ ] Setup → set `report_sections` to a subset (e.g. `summary,findings`) → the
      report shows only those sections
- [ ] Setup → `show_business_criticity = No` → the report's "Systems in scope"
      table drops the Business Criticality column

## 4. `fields` plugin soft-read (optional, if `fields` v1.23.2 is configured)

- [ ] In `fields`, create a container on `Appliance` + `Computer` with two number
      fields; note their **system names**
- [ ] Setup → DR Drill Report → set `fields_rto_key` / `fields_rpo_key` to those names
- [ ] Set the RTO/RPO numbers on one `Appliance`
- [ ] Add that `Appliance` to a drill → the drill_item row's RTO/RPO **target**
      columns are pre-filled (operator can still override)
- [ ] Remove the `fields` container → adding an asset still works (no error)

## 5. riskregister integration (if `riskregister` is active)

- [ ] Drill with `overall_result = fail` and ≥1 improvement action →
      **"Send findings to Risk Register"** button visible on the Improvement actions tab
- [ ] Click it → success message "N findings sent"
- [ ] Management → Risk Register → Findings → one Finding per action, `code`
      `DRILL-<drill_id>-<action_id>`, category **Disaster Recovery**
- [ ] Click the button again → **no duplicates** (idempotent upsert); the action
      rows show the `code` in the "Sent to Risk Register" column
- [ ] Deactivate `riskregister` → the button disappears; the drill form still loads

## 6. Cron

- [ ] `sudo -u nginx php /data/www/glpi/front/cron.php DrillReminder` → runs, no error
      (logs to `files/_log/drillreport_cron*` only in the configured month)
- [ ] `sudo -u nginx php /data/www/glpi/front/cron.php ActionFollowup` → runs, no error

## 7. Uninstall (last, on a throwaway pass)

- [ ] `sudo -u nginx php bin/console glpi:plugin:uninstall drillreport`
- [ ] `SHOW TABLES LIKE 'glpi\_plugin\_drillreport\_%';` → 0 rows
- [ ] `SELECT name FROM glpi_profilerights WHERE name LIKE 'plugin_drillreport_%';` → 0 rows
- [ ] Automatic actions → the 2 tasks are gone

---

## Known items to watch

- **GLPI 11 gotchas** (all handled here — listed so a future addition gets the
  same check):
  - `CommonGLPI::canView()` + `CommonDBRelation::can*` return `: bool`; untyped
    override = fatal on class load → whole instance down.
  - `getTabNameForItem()` is a non-static instance method (only
    `displayTabContentForItem()` is static). `static` override = fatal.
  - `menu.html.twig` renders only `content` entries with a `page` — a multi-page
    plugin menu must be its own sector + `is_multi_entries`.
  - `$_SERVER['PHP_SELF']` / `REQUEST_URI` / `SCRIPT_NAME` point at
    `public/index.php`, not the running `front/*.php`. Use
    `Html::getPrefixedUrl('/plugins/drillreport/front/x.php')` for hand-rolled
    `<form action>` (or `Itemtype::getFormURL()`). Latent until the first POST.
  - `CommonDBChild::prepareInputFor{Add,Update}` overrides must call `parent::`.
  Rule: grep the immediate parent class for the method and match it exactly.
- **`date_creation` / `date_mod`** are declared as raw `timestamp NULL` in
  `hook.php` CREATE TABLEs (same as riskregister ships). If GLPI's `Plugin.php`
  logs a timestamp-column warning on first upload, switch those columns to
  `$migration->addField($table, 'date_creation', 'timestamp')`.
- **OPcache:** after re-uploading changed files, `sudo systemctl reload php-fpm`
  before re-testing — legacy plugin files go through normal `require()`.
- `Drill::showForm()` is still the legacy `showFormHeader()` layout (works in
  GLPI 11); Twig-macro migration is a later item.
