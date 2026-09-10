# DR drill — evidence checklist (ISO 22398 / ISO 22301 §9.1)

The standards require **documented evidence** that the exercise was run and its
results — they do **not** prescribe specific screenshots. This is the artifact
set an ITGC / ISO 27001 (A.5.30) / SOC auditor typically expects for a **live
failover drill**.

**Rules for every capture:**
- One file per step. Name it `NN_<step>_<HHMM>.png` (e.g. `03_switchover_db_2130.png`).
- The **system clock / timestamp must be visible** in the image.
- Attach all files on the drill's **Documents** tab in the plugin — they then
  appear in the report's *Findings & Improvement Plan* header ("Evidence: …").
- On each **Objective / activity** row, put the matching file name(s) in the
  **Evidence reference** field so the report ties each activity to its proof.

---

## Before — preparation & baseline

- [ ] Change ticket / drill announcement / management approval (email or ticket)
- [ ] Runbook document + version used
- [ ] Primary DB status healthy (e.g. `v$database` role = PRIMARY, open)
- [ ] Replication / Data Guard status = **no gap** — baseline for RPO
- [ ] Application health check green at the primary site
- [ ] Backup completed (log / disk-space screenshot)

## During failover — one per activity

- [ ] Application **shutdown** confirmed — no active sessions on the DB
- [ ] Replication / Data Guard **switchover** command output + "switchover complete"
- [ ] DR database is now **PRIMARY** (role check on the DR node)
- [ ] VM **failover** status (e.g. Hyper-V Test Failover, VMs up at DR)
- [ ] **DNS / connection-string / IP** change applied (before/after)
- [ ] Application **login page loading from the DR site** (URL / IP visible)
- [ ] **Successful transaction test at DR**, per business function
      (e.g. a payment receipt, an order created) — the strongest single artifact

## Recovery validation

- [ ] Functional test **pass/fail per module** (CRM, RMS, payment, …)
- [ ] **Data integrity** check — record counts / latest transaction match
- [ ] Monitoring dashboard showing the service green at DR

## Failback

- [ ] Switchover **back to the primary site** + role check
- [ ] **Archive gap = 0** after failback — evidence the RPO held
- [ ] Final functional test at the primary site

## Sign-off

- [ ] Signed attendance / participant list
- [ ] Reviewed & signed drill report (the plugin's printed report + signatures)

---

## Mapping to the plugin

| Evidence | Where it goes |
|---|---|
| All files | Drill form → **Documents** tab → *Add a document* |
| "which file proves which step" | **Objective / activity** row → *Evidence reference* field |
| DR failover target (name / IP) | **Systems in scope** row → *DR failover target* field → report §3 column |
| Baseline "no gap" / final "no gap" | Drill → *Data loss summary* + an Objective for the RPO check |
| Approval | Drill → *Status = Approved* (stamps approver + date) |

The report's **Evidence** line lists every attached document; a reviewer then
cross-checks it against this list and the per-activity references.
