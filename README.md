# DR Drill Report (`drillreport`)

GLPI 11 plugin for logging **annual Disaster-Recovery drills** and producing an
audit-ready report aligned to **ISO 22398** / **ISO 22301** §8.5–9.1 and
**NIST SP 800-84** (After-Action Report / Improvement Plan).

Part of a GLPI 11 plugin suite (`integrasipaman`, `riskregister`, SAC,
`drillreport`). Standalone — it works with none of the others installed.

## Status

**Design → scaffold.** This is the first installable skeleton: schema, rights,
menu, dropdowns, config, cron tasks and list/tab views. Twig entry forms, the
printable report, the multi-year heatmap and the "Send to Risk Register" button
are the MVP work items — see `docs/drillreport-konsep-awal.md` §10.

## What it does

- **`Drill`** itemtype (`GlpiPlugin\Drillreport\Drill`) — one report per drill:
  date, DR scenario/site, test type, group in charge, RTO/RPO target vs actual,
  overall result, executive summary, approval / sign-off.
- **Child records** as tabs: `Objective` (met / partial / not-met),
  `Action` (finding + improvement plan), `Drill_Item` (assets in scope +
  per-asset RTO/RPO/recovery).
- **`Drill_Item`** links a drill to `Appliance` (web applications) and `Computer`
  by default — configurable via `plugin:drillreport` / `asset_itemtypes`. Every
  supported asset gets a **"DR drill history"** section inside the shared **"ICT Tools"**
  asset tab (one tab for all plugins of the suite, horizontal sub-tabs).
- **Dropdowns** (Setup → Dropdowns): DR scenario (per entity), test type (global,
  seeded with the five NIST 800-84 types, edited from the root entity only),
  action category (shared defaults in the root entity, plus an entity's own).
- **Entities**: a drill can be transferred to another entity with GLPI's
  Transfer; a drill keeps its link to an asset that moved to another entity and
  shows the stored name and the entity it went to, not the asset's live data.
- **Cron**: `DrillReminder` (annual "not submitted" reminder),
  `ActionFollowup` (overdue improvement actions).
- **Optional** manual "Send to Risk Register" — when `riskregister` is active,
  each `Action` on a failed drill can be pushed as a `Finding`
  (`code = DRILL-<drill_id>-<action_id>`). See `docs/…konsep-awal.md` §5.

## Requirements

- GLPI **11.0.0 – 11.9.99**
- PHP ≥ 8.2
- Optional: [`fields`](https://github.com/pluginsGLPI/fields) ≥ 1.22 — to store
  RTO/RPO **targets** on `Appliance` / `Computer` (soft-read to pre-fill the drill
  form; the plugin works without it).
- Optional: `riskregister` — for the "Send to Risk Register" button.

## Install

Deploy the release zip (`drillreportv<version>.zip`, archive root `drillreport/`)
into `<glpi>/plugins/`, then:

```
php bin/console glpi:plugin:install drillreport --username=<admin>
php bin/console glpi:plugin:activate drillreport
```

See [`docs/deploy-checklist.md`](docs/deploy-checklist.md) for the full VM
deploy + first-run verification steps. To build the zip:

```
git archive --format=zip --prefix=drillreport/ -o ../drillreportv<version>.zip HEAD
```

## Rights

| Right                        | Meaning                                        |
|------------------------------|-----------------------------------------------|
| `plugin_drillreport_drill`   | Drill reports + objectives + assets in scope  |
| `plugin_drillreport_action`  | Improvement actions + "Send to Risk Register"  |
| `plugin_drillreport_approve` | Sign off a drill report (status → approved)    |
| `plugin_drillreport_config`  | Setup page, dropdowns                          |

## Automatic actions

| Action | Default frequency |
|---|---|
| `ActionFollowup` | weekly |
| `DrillReminder` | daily |

They can be switched off or rescheduled in **Setup > Automatic actions**.

## Data it stores

`glpi_plugin_drillreport_actioncategories`, `glpi_plugin_drillreport_actions`, `glpi_plugin_drillreport_drills`, `glpi_plugin_drillreport_drills_items`, `glpi_plugin_drillreport_objectives`, `glpi_plugin_drillreport_scenarios`, `glpi_plugin_drillreport_testtypes`.

Uninstall removes these tables, with the plugin's rights, automatic actions and settings.

## Security

- Every form uses GLPI's CSRF token, and every page checks the plugin's rights and the user's entities.
- Secrets such as API keys are stored with GLPI's secured configuration, sent once and never shown again.
- Calls to outside services run only from automatic actions or an explicit button, with a timeout.
- Report a vulnerability privately as described in [SECURITY.md](SECURITY.md).

## Development

- Offline tests: `none yet`
- Release check: `none yet`
- Rules for contributors: [CHARTER.md](CHARTER.md) and [AGENTS.md](AGENTS.md).
- Changes: [CHANGELOG.md](CHANGELOG.md).

## Licence

GPL-3.0-or-later, author Teguh DHAMAR. See [LICENSE](LICENSE) and [NOTICE](NOTICE). To cite this plugin, see
[CITATION.cff](CITATION.cff).
