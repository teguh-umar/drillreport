# Entering a real drill — worked example: CRM Failover, August 2026

A step-by-step for entering an actual drill into the plugin, using the
**CRM DRC drill of 23 August 2026** (the company / ICT) as the example. Source
documents: `DRILL_CRM_2026_Activities_Plan_v.1.0_Fix.xlsx` (plan + execution
log) and `DRC-DRILL Agustus 2026 CRM.pdf` (result report).

Mapping rules used here:
- **RTO target** = the sum of the activities' *planned* durations.
- **RTO actual** = the sum of the activities' *actual* durations (end − start).
  → In the plugin you don't type these on the drill; you enter each activity on
  the **Objectives / activities** tab with its target + start/end, and the report
  sums them. Leave the drill's own RTO target/actual blank.
- **Systems in scope** = the DC→DR asset pairs, with `recovered` set from the
  activity result column (Sukses → yes, Gagal → no).
- **RPO** is not quantified in the sources — leave blank; note "no archive gap"
  in Data loss summary.

---

## 0. Prerequisites (Setup → Dropdowns)

- **DR scenario** — add: `CRM Failover to DRC` (or `Failover CRM ke DRC`).
- **Test type** — `Full failover` already seeded; use it.
- **Assets** — the CRM DC servers should exist as `Computer` / `Appliance` in
  GLPI. If they aren't inventoried yet, create them (Assets → Computers/Appliances):
  `DBSERVER2` (CRM DB), `CRM_EOM`, `CRM_AM`, `CRM_GW`, `CRM_WEB1`, `APEX-RMS` (RMS),
  `Payment Gateway`. For a first pass you can also just link one representative asset.

## 1. Create the drill

**DR Drill Report → DR drills → + Add**

| Field | Value |
|---|---|
| Name | `DR Drill 2026 — CRM Failover to DRC` |
| Drill date | `2026-08-23` |
| DR scenario | `CRM Failover to DRC` |
| Test type | `Full failover` |
| Group in charge | *(the DR/BCP group — e.g. "ICT Infrastructure Management")* |
| Drill coordinator | *(pick the ORG lead — Moh Nur Alim, or yourself)* |
| Overall result | `Pass with exceptions` *(overall PASS, but Payment via Mobile App failed)* |
| Status | `Submitted` |
| RTO target (min) | **leave blank** — comes from the activity totals |
| RTO actual (min) | **leave blank** |
| RPO target (min) | blank |
| RPO actual (min) | blank |
| Data loss summary | `Tidak ada kehilangan data — pengecekan gap primary/standby "Sukses" setelah fallback (no archive gap). Risiko data-loss teridentifikasi di risk register untuk step fallback.` |
| Objectives (free text) | *(optional — the bulleted list is generated from the Objectives tab)* |
| Scope summary | `Failover terkontrol aplikasi CRM (Siebel 8.1, web + database Oracle Data Guard), RMS, Online Payment Gateway, dan Payment Engine via MyApp/MobileApp dari Pusat Data Utama (Jl. K.H Zainul Arifin 20, Jakarta) ke DRC Indosat Jatiluhur, lalu dikembalikan. Skenario: kegagalan total DC HQ / DB primary CRM.` |
| Executive summary | `Failover CRM berjalan baik secara fungsional (aplikasi online, data konsisten, tanpa bantuan vendor). RTO meleset: total downtime ~104 menit vs rencana ~65 menit; penyebab utama tidak ada standby database ter-restore di DRC. 1 sub-test gagal: pembayaran via MyApp & Mobile App (konfigurasi IP API belum diarahkan ke DB DRC). Rencana perbaikan Q4 2026.` |
| Lessons learned | `Runbook failover akurat dan dieksekusi tim internal. Bottleneck di infrastruktur data & konfigurasi integrasi, bukan prosedur. Perlu keterlibatan tim MIS untuk konfigurasi API pembayaran, penambahan resource APEX-RMS, dan monitoring rutin sinkronisasi archivelog.` |

Save.

## 2. Timeline (on the drill form)

| Field | Value |
|---|---|
| Disaster declared | `2026-08-23 21:09:00` *(first shutdown action, xlsx II.1)* |
| Service recovered | `2026-08-23 22:11:00` *(CRM app up at DRC, xlsx end of III)* |
| Validation complete | `2026-08-23 23:10:00` *(final testing done, xlsx VIII)* |

(These are the DRC-side milestones. The full switch-back also completed the same
night ~23:55 per the PDF forms.)

## 3. Systems in scope tab

Add each asset, then edit its row. **DR failover target** = the DR replica
name / IP from the *DR Component Inventory* (PDF).

| Asset | DR failover target | Recovered | Recovery order | Comment |
|---|---|---|---|---|
| DBSERVER2 (CRM DB) | `DR_DBSERVER2 / 192.0.2.87` | Yes | 1 | Switchover Oracle Data Guard DC↔DRC, tidak ada gap. |
| CRM_EOM | `DR CRM_EOM / 192.0.2.46` | Yes | 2 | Test Failover VM Siebel di DRC — Sukses. |
| CRM_AM | `DR CRM_AM / 192.0.2.45` | Yes | 2 | Sukses. |
| CRM_GW | `DR CRM_GW / 192.0.2.44` | Yes | 2 | Sukses. |
| CRM_WEB1 | `DR CRM_WEB1 / 192.0.2.42` | Yes | 2 | Sukses; workaround NIC DRCBAY11 → semua VM Siebel di DRCBAY09. |
| Payment Gateway | *(tidak ada replika VM)* | Yes | 2 | Re-point connection string; transaksi pembayaran Sukses di DRC. |
| APEX-RMS (RMS) | `DR_APEX (RMS) / 192.0.2.35` | Yes | 3 | Sukses; perubahan IP terhambat (Guest OS unsupported, GUI freeze). |
| *Payment Engine (MyApp/MobileApp)* | — | **No** | 4 | **Gagal** — "Unexpected system error" / "Token is not valid". |

## 4. Objectives / activities tab

Enter each drill activity as an objective, with **Target duration** = the planned
minutes and **Start/End time** = the actual times. The tab shows a **Total** row
you can read the drill's RTO target/actual from. Set **Result** by hand, or tick
**"auto from timing"** to let the plugin derive met/partial/not-met from
actual vs target.

| Objective / activity | Target (min) | Start | End | Result | Evidence ref | Comment |
|---|---|---|---|---|---|---|
| Backup database & config check | 210 | `2026-08-23 18:00` | `2026-08-23 20:17` | Met | `01_backup_disk.png` | Disk space cukup; selesai lebih cepat. (bukan downtime) |
| Shutdown aplikasi CRM, RMS & Payment Gateway | 15 | `2026-08-23 21:09` | `2026-08-23 21:18` | Met | `02_shutdown_nosessions.png` | Tidak ada sesi aplikasi di database. |
| Switchover database DC → DRC | 20 | `2026-08-23 21:18` | `2026-08-23 21:30` | Met | `03_switchover_db_2130.png` | Primary ↔ Standby switch, tidak ada gap. |
| Failover VM CRM/RMS + re-IP + hosts + Payment Gateway conn.string | 50 | `2026-08-23 21:30` | `2026-08-23 22:11` | Partially met | `04_vm_failover.png` | Sukses tapi kendala NIC DRCBAY11 & Guest OS RMS unsupported. |
| Test CRM, RMS, Payment Gateway & Payment Engine di DRC | 24 | `2026-08-23 22:12` | `2026-08-23 22:45` | Partially met | `05a_crm_test.png; 05b_payment_ok.png; 05c_mobileapp_fail.png` | CRM/RMS/Payment Gateway Sukses; **Payment via Mobile App GAGAL**. |
| Switchover database DRC → DC (fallback) | 14 | `2026-08-23 22:46` | `2026-08-23 22:55` | Met | `06_fallback_gap0.png` | Delete test-failover VM, gap check (0), switchover — Sukses. |
| Startup CRM/RMS di DC + test akhir | 30 | `2026-08-23 22:56` | `2026-08-23 23:10` | Met | `07_final_test.png` | CRM, RMS, Online Payment Gateway normal. |

> Attach every referenced file on the drill's **Documents** tab. See
> `docs/evidence-checklist.md` for the full ISO-oriented evidence set.

> Totals ≈ **target 363 min / actual ~166 min** (with the long backup window) —
> or, counting only the service-impacting steps (rows 2–7): **target ~153 /
> actual ~104**. Decide which framing you want and either keep only the
> downtime rows, or note it in the comment. The report will show whichever set
> of rows you enter.

## 5. Improvement actions tab

| Finding | Severity | Category | Owner | Due | Observation | Root cause | Recommendation |
|---|---|---|---|---|---|---|---|
| Pembayaran via MyApp & Mobile App gagal saat test DRC | High | *(pick — e.g. Network & connectivity or a new "Integration")* | Tim MIS | *(set a date — none in the source)* | Test Payment Engine via Mobile App — Gagal. Error "Unexpected system error", "Token is not valid". | Konfigurasi IP pada sisi API (Web Method) belum diarahkan ke IP DB DRC. | Libatkan tim MIS di drill berikutnya untuk menyesuaikan konfigurasi API di Web Method ke IP DB DRC. |
| APEX-RMS (RMS) kekurangan RAM & disk | Medium | Infrastructure / capacity | *(Infra team)* | *(set)* | RAM VM DR 1 GB; disk `/` 89% penuh. | Alokasi RAM & disk tidak memadai. | Naikkan RAM APEX-RMS menjadi 8 GB dan available disk `/` menjadi 100 GB. |
| Sinkronisasi archivelog primary → standby perlu diawasi | Low | Monitoring & alerting | *(DBA)* | *(set)* | Risiko gap antara primary dan standby. | — | Lakukan monitoring rutin proses sinkronisasi archivelog dari primary ke standby. |
| Node DRCBAY11 — NIC VM tidak bisa dikonfigurasi | Medium | Infrastructure / capacity | *(DRC infra)* | *(set)* | Kendala pada NIC untuk jaringan VM; issue pada modul Onboard Administrator. Workaround: semua VM SIEBEL di DRCBAY09. | Fault pada modul Onboard Administrator. | Perbaiki modul Onboard Administrator DRCBAY11. |
| VM-SERVER-33 — perubahan IP terhambat, Guest OS unsupported | Low | Infrastructure / capacity | *(DRC infra)* | *(set)* | Kursor GUI freeze/unresponsive saat input konfigurasi. | Versi Guest OS sudah tidak didukung oleh Hyper-V. | Upgrade / migrasi Guest OS VM-SERVER-33 ke versi yang didukung. |

After entering these, if `riskregister` is active and the drill result is `fail`,
the **"Send findings to Risk Register"** button will push each as a Finding. For
`Pass with exceptions` the button is not shown — send the payment-failure finding
to Risk Register manually if you want it tracked there.

## 6. Approval

Once reviewed: set **Status = Approved** (needs the `plugin_drillreport_approve`
right); Approved by + Approval date fill automatically or by hand.

## 7. Report

Drill form → **View / print report** → **Print / Save as PDF**. In the print
dialog: Layout = **Portrait**, and **Background graphics = ON**.
