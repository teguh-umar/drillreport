# drillreport — deploy & first-run checklist (VM-HQ-GLPI)

**Target:** VM-HQ-GLPI (`10.129.2.174`, via JumpServer bastion `paman.pgn.co.id:2222`), GLPI root `/data/www/glpi`
**Artifact:** `drillreportv<version>.zip` — archive root `drillreport/`, git-tracked files only, no `.git/`
**GLPI:** 11.0.x · **PHP:** 8.2+ (VM is 8.4)

This is the **first** deploy — drillreport has never run on a real GLPI. Run top
to bottom; fix any failure as its own commit, rebuild the zip, redeploy, re-run
the section.

---

## 0. Build the zip (on the workstation)

```bash
cd "…/Documents/Drill Report GLPI/drillreport"
git archive --format=zip --prefix=drillreport/ -o "../drillreportv$(grep -oP "VERSION', '\K[^']+" setup.php).zip" HEAD
```

The zip lands next to the repo dir (same convention as `riskregisterv*.zip`).
Confirm: `unzip -l ../drillreportv*.zip` → root is `drillreport/`, no `.git/`.

## 1. Upload & extract

- [ ] Upload `drillreportv<version>.zip` (JumpServer file transfer / scp).
- [ ] `cd /data/www/glpi/plugins && sudo unzip -o /path/drillreportv<version>.zip` → creates `drillreport/`
- [ ] `sudo chown -R nginx:nginx /data/www/glpi/plugins/drillreport`
- [ ] `grep VERSION /data/www/glpi/plugins/drillreport/setup.php` → matches
- [ ] `tail -f /data/www/glpi/files/_log/php-errors.log` in another shell for the rest of this pass

## 2. Install

- [ ] `cd /data/www/glpi && sudo -u nginx php bin/console glpi:plugin:install drillreport --username=<admin>`
- [ ] `sudo -u nginx php bin/console glpi:plugin:activate drillreport`
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

## 3. UI smoke test (as Super-Admin)

- [ ] **Management → DR Drill Report** node visible; sub-entries Drills /
      Improvement actions / Setup
- [ ] On the **Drills** list page — the breadcrumb reads "… › DR Drill Report ›
      DR drills" and a **"+ Add"** button is present (guards against the missing
      `Html::header()` 5th-arg regression)
- [ ] Create a drill (name, date, scenario, test type, group in charge, result = `fail`)
- [ ] Drill form tabs: **Objectives**, **Improvement actions**, **Systems in scope** —
      each shows its add form
- [ ] Add an objective (result dropdown) → saved, shows in the list, editable
- [ ] Add an improvement action (severity, owner group, due date) → saved
- [ ] Systems in scope → asset picker lists `Appliance` + `Computer`; add one →
      row appears with RTO/RPO columns
- [ ] Open the linked `Appliance` / `Computer` → **"DR drill history"** tab shows the drill
- [ ] Setup screen (`front/config.php`) loads; toggle a value, Save, reload → persisted

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

- **GLPI 11.0.x signature tightening** (both fixed here — listed so a future
  method addition gets the same check): `CommonGLPI::canView()` +
  `CommonDBRelation::can*` now return `: bool`; `getTabNameForItem()` is a
  non-static instance method (only `displayTabContentForItem()` is static). An
  untyped `canView()` or a `static getTabNameForItem()` is a fatal on class load
  → whole instance down. Rule of thumb when adding an override: grep the immediate
  parent class for the method and match its signature exactly.
- **`date_creation` / `date_mod`** are declared as raw `timestamp NULL` in
  `hook.php` CREATE TABLEs (same as riskregister ships). If GLPI's `Plugin.php`
  logs a timestamp-column warning on first upload, switch those columns to
  `$migration->addField($table, 'date_creation', 'timestamp')`.
- **OPcache:** after re-uploading changed files, `sudo systemctl reload php-fpm`
  before re-testing — legacy plugin files go through normal `require()`.
- `Drill::showForm()` is still the legacy `showFormHeader()` layout (works in
  GLPI 11); Twig-macro migration is a later item.
