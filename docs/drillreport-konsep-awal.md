# Drill Report — Rancangan Konsep Awal

> Plugin GLPI baru untuk mencatat hasil drill Disaster Recovery (DR) tahunan.
> Dirancang berdiri sendiri (standalone), dengan hook opsional ke `riskregister`
> saat plugin itu terdeteksi aktif.
>
> **Target: GLPI 11** (dikonfirmasi — instance yang dipakai versi 11). Deploy ke
> VM-HQ-GLPI, satu instance bersama `integrasipaman` / `riskregister` / SAC
> (lihat hub `_pgn-glpi-suite.md`).

---

## 1. Latar Belakang

- Frekuensi drill: **1x per tahun**
- Pencatatan: **manual** (bukan tarikan otomatis dari tool DR)
- Cakupan data: **aset, application/appliance, group in charge**
- Keputusan desain: **plugin standalone**, bukan modul di dalam `riskregister` —
  supaya bisa dipakai tim DR/BCP tanpa harus install `riskregister`, dan karena
  sifat data (low-frequency, manual entry) tidak butuh pipeline otomatis seperti
  modul SAC (EOL/License/Patch/Config).

## 2. Konteks — Plugin GLPI Lain yang Sudah Ada

| Plugin | Status | Relevansi |
|---|---|---|
| `riskregister` | Dibangun (51 file, ~9.700 baris), **belum diverifikasi jalan di GLPI nyata** | Finding/Risk itemtype, skoring 5×5, ISO 27001/CIS v8 — target korelasi opsional untuk drill yang gagal |
| `integrasipaman` | **Sudah live** di produksi (v1.11.0), JumpServer PAM audit | Referensi pola "soft dependency" (`safeCorrelationMap()` yang degrade ke `[]` kalau tabel korelasi tidak ada) |

## 3. Kenapa Standalone (Bukan Modul di `riskregister`)

- Drill Report punya nilai berdiri sendiri (laporan ke manajemen, evidence audit
  ITGC terpisah) — tidak bergantung pada Risk Register untuk berguna
- Frekuensi rendah + manual entry → tidak butuh pipeline otomatis yang jadi alasan
  utama modul SAC digabung ke dalam `riskregister`
- Risiko over-engineering lebih besar kalau dipaksa jadi modul dependent

**Prinsip: standalone secara struktural, tapi punya hook opsional — bukan hard
dependency, bukan juga sepenuhnya terisolasi.**

Pola referensi (dari `integrasipaman`) — **guard kanonik** (dikonfirmasi tim `riskregister`):
```php
if (\Plugin::isPluginActive('riskregister')) {
    // riskregister ada & aktif → tawarkan tombol "Kirim ke Risk Register"
} else {
    // riskregister tidak ada → DrillReport tetap berfungsi penuh sendirian
}
```

> ⚠️ **Jangan** pakai `class_exists('PluginRiskregisterFinding')` — class itu **tidak ada**.
> `riskregister` 100% PSR-4, tanpa alias legacy `Plugin<Name><Class>`. Alternatif yang
> juga jalan tapi kurang eksplisit: `class_exists(\GlpiPlugin\Riskregister\Finding::class)`.

## 4. Rancangan Ringkas

| Aspek | Detail |
|---|---|
| **Nama plugin** | `drillreport` (ikut konvensi lowercase seperti `riskregister`/`integrasipaman`) |
| **Itemtype utama** | `GlpiPlugin\Drillreport\Drill` (`CommonDBTM`) — PSR-4, ikut `riskregister` |
| **Field utama (usulan)** | tanggal drill, skenario/site DR, RTO target vs aktual, RPO target vs aktual, status pass/fail, catatan (RTO/RPO **bukan** field native GLPI — lihat §6.3) |
| **Link ke aset** | `Drill_Item` polymorphic — target utama **`Appliance`** (web application — disepakati) & **`Computer`** (server/host); opsional `Cluster`, `DatabaseInstance`. **`Application` bukan itemtype GLPI** — lihat §6.3 |
| **Group in charge** | Native field `groups_id` — **tidak perlu tabel mapping baru** (beda dengan `assetmap`/`usermap` di `integrasipaman`), karena GLPI sudah track relasi Group per aset secara native |
| **Menu node** | **Management → DR Drill Report** (`$PLUGIN_HOOKS['menu_toadd']['drillreport'] = ['management' => \GlpiPlugin\Drillreport\Menu::class]`) — lihat §11.1 |
| **Cron (opsional, ringan)** | `GlpiPlugin\Drillreport\Cron::DrillReminder` (reminder tahunan) + `…::ActionFollowup` (action overdue) — notifikasi saja. Lihat §11.2 |
| **Korelasi ke `riskregister`** | Tombol manual "Kirim ke Risk Register" saat drill berstatus fail — muncul hanya kalau `riskregister` terdeteksi aktif; sengaja manual (bukan auto-create Finding) karena proses drill sendiri manual dan butuh review manusia sebelum jadi Finding formal. Detail kontrak API di §5. |

## 5. Kontrak Integrasi ke `riskregister` (Mapping Drill → Finding)

> Dikonfirmasi oleh tim `riskregister` (sesi `risk-register-dashboard-df`).
> Pola "tombol manual → Finding, lalu risk officer promote ke Risk" **persis cocok** —
> `riskregister` sendiri tidak auto-create Risk dari Finding.

### 5.1 Cara membuat Finding secara programatik

- Class: **`\GlpiPlugin\Riskregister\Finding`** (PSR-4, plain `CommonDBTM`)
- Kategori: **`ImportSupport::categoryIdByName()` return 0 kalau nama tidak ada**
  (bukan find-or-create — dikonfirmasi riskregister 2026-09-03) dan nama
  `'Disaster Recovery'` **tidak** ada di seed riskregister. Jadi drillreport yang
  bikin sendiri via itemtype API: `Action::ensureRiskCategoryId($eid)` →
  `find()` dulu, `add()` kalau belum ada, return 0 kalau `riskregister` non-aktif
  atau gagal (Finding jatuh ke kategori 0, dibetulkan di Reconcile).
- Link ke aset GLPI (opsional): `\GlpiPlugin\Riskregister\Finding_Item`

Tombol "Kirim ke Risk Register" muncul di **drill berstatus fail** dan meng-upsert
**1 Finding per baris `_actions`** (bukan 1 Finding per drill) — tiap finding drill
= 1 Finding formal.

```php
if (!\Plugin::isPluginActive('riskregister')) {
    return; // tombol tidak muncul
}

$eid  = $drill->fields['entities_id'];

foreach ($actions as $action) {                       // baris glpi_plugin_drillreport_actions
    $code = "DRILL-{$drill->getID()}-{$action['id']}"; // deterministik & stabil per action

    $input = [
        'entities_id'    => $eid,
        'code'           => $code,
        'scope_label'    => $siteDR,          // WAJIB non-kosong — nama sistem/site DR
        'name'           => $action['name'] ?: "Temuan drill DR {$year}",
        'severity'       => ucfirst($action['severity']),  // enum _actions = enum Finding
        'status'         => 'Open',
        'content'        => $action['content'],
        'cause'          => $action['cause'],
        'recommendation' => $action['recommendation'],
        'date_due'       => $action['date_due'] ?: null,
        'assessment_ref' => "DR Drill {$year}",
        'raw_asset_text' => $assetLabel,      // dari _drills_items kalau action ditautkan ke aset
        // $catId dari Action::ensureRiskCategoryId($eid) — hanya di-set kalau > 0
    ];

    $f   = new \GlpiPlugin\Riskregister\Finding();
    $hit = $f->find(['entities_id' => $eid, 'code' => $code]); // upsert idempoten
    if ($hit) {
        $fid = array_key_first($hit);
        $f->update(['id' => $fid] + $input);
    } else {
        $fid = $f->add($input);              // int id, atau false kalau validasi gagal
    }

    // simpan balik ke _actions supaya tidak dobel & bisa ditelusuri
    (new \GlpiPlugin\Drillreport\Action())->update([
        'id' => $action['id'],
        'riskregister_findings_id' => (int) $fid,
        'riskregister_code'        => $code,
    ]);
}
```

### 5.2 Field Finding — wajib vs opsional

| Field | Status | Catatan |
|---|---|---|
| `entities_id` | **wajib** | set eksplisit |
| `code` | **wajib** | string ≤50, deterministik & stabil — **per action**: `DRILL-<drills_id>-<actions_id>`. Unique key `(entities_id, code)` = kunci idempoten kalau tombol ditekan 2× |
| `scope_label` | **wajib** | nama sistem/site DR; validasi menolak `''` hanya kalau key dikirim kosong — kalau tidak dikirim malah tersimpan `''`. **Selalu isi.** |
| `name` | **wajib** | string ≤255, judul singkat |
| `severity` | opsional | `Critical\|High\|Medium\|Low\|Info` (default `Medium`; nilai invalid didrop diam-diam). Literal di `Finding::SEVERITIES` |
| `status` | opsional | `Open\|In Treatment\|Treated\|Closed` (default `Open`). Literal di `Finding::STATUSES` |
| `content` / `cause` / `recommendation` | opsional | text — apa yang gagal / akar masalah / rekomendasi |
| `date_due` | opsional | format `Y-m-d` |
| `assessment_ref` | opsional | mis. `DR Drill 2026` |
| `raw_asset_text` / `raw_control_text` | opsional | string ≤255, teks bebas aset/kontrol belum ter-resolve → muncul di **Reconcile** untuk review manusia |
| `plugin_riskregister_riskcategories_id` | opsional | int, default 0 |
| `id`, `is_deleted`, `date_creation`, `date_mod` | **jangan set** | milik `riskregister` |

### 5.3 Deteksi plugin aktif

- **Kanonik:** `\Plugin::isPluginActive('riskregister')`
- Alternatif: `class_exists(\GlpiPlugin\Riskregister\Finding::class)` (jalan, kurang eksplisit)
- ❌ `class_exists('PluginRiskregisterFinding')` — class ini **tidak ada**

### 5.4 Klaim namespace (sebelum kunci string konkret)

`glpi_plugin_drillreport_*` · `plugin:drillreport` · `GlpiPlugin\Drillreport\`
— tidak bentrok dengan `riskregister`.

## 6. Struktur Laporan — Selaras Standar (ISO 22398 / NIST SP 800-84 AAR/IP)

> Standar acuan laporan drill DR: **ISO 22398** (*Guidelines for exercises*),
> **ISO 22301** klausul 8.5 & 9.1 (BCMS — exercise programme + evaluasi
> terdokumentasi), **ISO/IEC 27031** (ICT readiness), **NIST SP 800-84** (definisi
> **After-Action Report / Improvement Plan — AAR/IP**), **NIST SP 800-34 Rev.1**,
> **FEMA HSEEP** (template AAR/IP). Konteks Pertamina: **SNI ISO 22301** acuan
> formal, ISO 22398 panduan teknisnya.
>
> Irisan semua standar → 14 komponen laporan. Peta ke struktur data plugin:

### 6.1 Komponen laporan wajib (irisan standar)

| # | Komponen standar | Field / tabel plugin |
|---|---|---|
| 1 | Executive summary (hasil keseluruhan) | `executive_summary` (text), `overall_result` (enum) |
| 2 | Overview: objectives, scope, skenario, tanggal | `objectives` (text), `scope_summary` (text), `plugin_drillreport_scenarios_id`, `date_drill` |
| 3 | Jenis test | `test_type` — tabletop / walkthrough / simulation / parallel / full-failover |
| 4 | Participants & roles | `groups_id` (group in charge), `users_id_tech` (koordinator), tab **Actors** native GLPI |
| 5 | Sistem/aplikasi dalam scope | `Drill_Item` polymorphic → **`Appliance`** (web app) / **`Computer`** (server); opsional `Cluster` / `DatabaseInstance` (§6.3) |
| 6 | Timeline aktual | `declaration_time`, `recovery_time`, `validation_time` (datetime) → RTO aktual dihitung |
| 7 | RTO: target vs aktual | `rto_target_minutes`, `rto_actual_minutes` (per drill + opsional per aset di `Drill_Item`) |
| 8 | RPO: target vs aktual | `rpo_target_minutes`, `rpo_actual_minutes`, `data_loss_summary` (text) |
| 9 | Objectives assessment (met/partial/not-met) | tabel anak `glpi_plugin_drillreport_objectives` (objective, result, komentar) |
| 10 | Findings & gaps + severity | tabel anak `glpi_plugin_drillreport_actions` (finding, `severity`) |
| 11 | Root cause | `glpi_plugin_drillreport_actions.cause` |
| 12 | **Improvement Plan** (corrective action + owner + due date) | `glpi_plugin_drillreport_actions` (recommendation, `groups_id`/`users_id` owner, `date_due`, `status`) — **inilah yang dipetakan ke `Finding` riskregister (§5)** |
| 13 | Evidence / appendix | `Document_Item` native GLPI (lampiran) |
| 14 | Approval / sign-off | `approved_by` (users_id), `approval_date`, `status` draft→submitted→approved |

### 6.2 Skema tabel (final draft)

```
glpi_plugin_drillreport_drills            -- 1 baris = 1 laporan drill (induk)
  ├─ glpi_plugin_drillreport_objectives   -- 1:N  per-objective assessment (komp. 9)
  ├─ glpi_plugin_drillreport_actions      -- 1:N  finding + improvement plan (komp. 10-12) → Finding
  └─ glpi_plugin_drillreport_drills_items -- 1:N  Drill_Item polymorphic + RTO/recovered per aset (komp. 5,7)
Dropdowns (CommonDropdown, seeded saat install):
  glpi_plugin_drillreport_scenarios       -- skenario / site DR
  glpi_plugin_drillreport_testtypes       -- jenis test (5 nilai standar)
  glpi_plugin_drillreport_actioncategories-- kategori finding/action (opsional)
Native: Document_Item (evidence komp. 13), Log (audit trail)
```

PSR-4 (ikut `riskregister`): `GlpiPlugin\Drillreport\Drill`,
`…\Objective`, `…\Action`, `…\Drill_Item`, `…\Scenario`, `…\TestType`,
`…\ActionCategory` → `src/`.

#### `glpi_plugin_drillreport_drills`

| Kolom | Tipe | Catatan |
|---|---|---|
| `id` | INT UNSIGNED AI PK | |
| `entities_id` | INT UNSIGNED NOT NULL DEFAULT 0 | idx |
| `is_recursive` | TINYINT NOT NULL DEFAULT 0 | |
| `name` | VARCHAR(255) NOT NULL DEFAULT '' | judul laporan |
| `date_drill` | DATE NULL | tanggal pelaksanaan · idx |
| `plugin_drillreport_scenarios_id` | INT UNSIGNED NOT NULL DEFAULT 0 | idx · dropdown |
| `plugin_drillreport_testtypes_id` | INT UNSIGNED NOT NULL DEFAULT 0 | idx · dropdown |
| `groups_id` | INT UNSIGNED NOT NULL DEFAULT 0 | idx · group in charge |
| `users_id_tech` | INT UNSIGNED NOT NULL DEFAULT 0 | idx · koordinator drill |
| `objectives` | TEXT | naratif tujuan (komp. 2) |
| `scope_summary` | TEXT | ruang lingkup (komp. 2) |
| `declaration_time` | TIMESTAMP NULL | timeline (komp. 6) — tampil kalau `show_timeline_fields` |
| `recovery_time` | TIMESTAMP NULL | timeline |
| `validation_time` | TIMESTAMP NULL | timeline |
| `rto_target_minutes` | INT UNSIGNED NULL | keseluruhan · pre-fill dari max aset |
| `rto_actual_minutes` | INT UNSIGNED NULL | **manual input** |
| `rpo_target_minutes` | INT UNSIGNED NULL | |
| `rpo_actual_minutes` | INT UNSIGNED NULL | **manual input** |
| `data_loss_summary` | TEXT | komp. 8 |
| `overall_result` | VARCHAR(30) NOT NULL DEFAULT 'pending' | **enum**: pending / pass / pass_with_exceptions / fail · idx |
| `executive_summary` | TEXT | komp. 1 |
| `lessons_learned` | TEXT | |
| `status` | VARCHAR(20) NOT NULL DEFAULT 'draft' | **enum**: draft / submitted / approved · idx |
| `approved_by` | INT UNSIGNED NOT NULL DEFAULT 0 | users_id |
| `approval_date` | TIMESTAMP NULL | |
| `comment` | TEXT | |
| `date_creation` / `date_mod` | TIMESTAMP NULL | |
| `is_deleted` | TINYINT NOT NULL DEFAULT 0 | idx |

#### `glpi_plugin_drillreport_objectives` (komp. 9)

| Kolom | Tipe | Catatan |
|---|---|---|
| `id` | INT UNSIGNED AI PK | |
| `plugin_drillreport_drills_id` | INT UNSIGNED NOT NULL DEFAULT 0 | idx |
| `name` | VARCHAR(255) NOT NULL DEFAULT '' | objective singkat |
| `content` | TEXT | deskripsi |
| `result` | VARCHAR(20) NOT NULL DEFAULT 'not_met' | **enum**: met / partial / not_met |
| `comment` | TEXT | |
| `date_creation` / `date_mod` | TIMESTAMP NULL | |

#### `glpi_plugin_drillreport_actions` (komp. 10-12 · Improvement Plan → Finding)

| Kolom | Tipe | Catatan |
|---|---|---|
| `id` | INT UNSIGNED AI PK | |
| `plugin_drillreport_drills_id` | INT UNSIGNED NOT NULL DEFAULT 0 | idx |
| `plugin_drillreport_drills_items_id` | INT UNSIGNED NOT NULL DEFAULT 0 | idx · aset yang kena (opsional) |
| `plugin_drillreport_actioncategories_id` | INT UNSIGNED NOT NULL DEFAULT 0 | idx · dropdown (opsional) |
| `name` | VARCHAR(255) NOT NULL DEFAULT '' | judul finding → `Finding.name` |
| `content` | TEXT | apa yang gagal/observasi → `Finding.content` |
| `cause` | TEXT | root cause → `Finding.cause` |
| `recommendation` | TEXT | corrective action → `Finding.recommendation` |
| `severity` | VARCHAR(20) NOT NULL DEFAULT 'medium' | **enum** = `Finding::SEVERITIES` (critical/high/medium/low/info) · idx |
| `groups_id` | INT UNSIGNED NOT NULL DEFAULT 0 | idx · owner group (Improvement Plan) |
| `users_id` | INT UNSIGNED NOT NULL DEFAULT 0 | idx · owner person |
| `date_due` | DATE NULL | → `Finding.date_due` |
| `status` | VARCHAR(20) NOT NULL DEFAULT 'open' | **enum**: open / in_progress / done / cancelled · idx |
| `riskregister_findings_id` | INT UNSIGNED NOT NULL DEFAULT 0 | id Finding hasil "Kirim ke Risk Register"; 0 = belum |
| `riskregister_code` | VARCHAR(50) NOT NULL DEFAULT '' | `code` yang dipakai — `DRILL-<drills_id>-<action_id>` |
| `date_creation` / `date_mod` | TIMESTAMP NULL | |

> Kolom `content`/`cause`/`recommendation`/`severity`/`date_due` sengaja 1:1 dengan
> `Finding` supaya tombol "Kirim ke Risk Register" (§5) tinggal copy tanpa transформasi.
> `code` per-**action** (bukan per-drill) supaya tiap finding = 1 Finding, idempoten.

#### `glpi_plugin_drillreport_drills_items` — `Drill_Item` polymorphic (komp. 5, 7)

| Kolom | Tipe | Catatan |
|---|---|---|
| `id` | INT UNSIGNED AI PK | |
| `plugin_drillreport_drills_id` | INT UNSIGNED NOT NULL DEFAULT 0 | idx |
| `itemtype` | VARCHAR(100) NOT NULL DEFAULT '' | `Appliance` / `Computer` / … (whitelist config `asset_itemtypes`) |
| `items_id` | INT UNSIGNED NOT NULL DEFAULT 0 | idx gabungan `(itemtype, items_id)` |
| `rto_target_minutes` | INT UNSIGNED NULL | pre-fill soft-read dari `fields` aset — bisa override |
| `rto_actual_minutes` | INT UNSIGNED NULL | manual |
| `rpo_target_minutes` | INT UNSIGNED NULL | pre-fill soft-read |
| `rpo_actual_minutes` | INT UNSIGNED NULL | manual |
| `is_recovered` | TINYINT NOT NULL DEFAULT 0 | aset berhasil dipulihkan? |
| `recovery_order` | SMALLINT NULL | urutan recovery |
| `comment` | TEXT | |
| `date_creation` / `date_mod` | TIMESTAMP NULL | |

Unique: `(plugin_drillreport_drills_id, itemtype, items_id)`.
Index: `item (itemtype, items_id)`, `plugin_drillreport_drills_id`.

#### Dropdown tables (seeded saat install)

| Tabel | Class | Seed |
|---|---|---|
| `glpi_plugin_drillreport_scenarios` | `Scenario` (`CommonDropdown`) | kosong (admin isi) |
| `glpi_plugin_drillreport_testtypes` | `TestType` (`CommonDropdown`) | tabletop, walkthrough, simulation, parallel, full-failover |
| `glpi_plugin_drillreport_actioncategories` | `ActionCategory` (`CommonDropdown`) | kosong (opsional) |

Semua tabel dibuat/di-drop oleh `Migration` helper (discovery by prefix
`glpi\_plugin\_drillreport\_%`, pola dari `riskregister` §4 hub).

### 6.3 Riset: fitur GLPI native terkait RTO/RPO & aset aplikasi

> Riset 2026-09-01 terhadap dokumentasi GLPI 11 + forum. **Kesimpulan: GLPI tidak
> punya field RTO/RPO native, dan tidak ada itemtype bernama `Application`.**

**a. RTO / RPO — TIDAK ada field native di mana pun**

Dicek: form aset, tab **Financial & administrative information** (Infocom), tab
**Impact analysis**, `Appliance`. Tidak ada field recovery time / recovery point /
DR / SLA aset. Field paling dekat:

| Fitur native | Detail | Pakai untuk drillreport |
|---|---|---|
| **Business criticity** (`Businesscriticity`, `CommonTreeDropdown`, tabel `glpi_businesscriticities`, field `businesscriticities_id`) | Sejak GLPI 9.2. Dropdown hierarkis di blok Infocom, ada di **semua** aset (Computer/Appliance/Software/…). Daftar nilai bisa diatur admin via **Setup → Dropdowns**. | **Tampilkan di laporan** sebagai tier kritikалitas aset (mis. Tier-0/1/2). Ini satu-satunya "tingkat kritikалitas" native — target RTO/RPO per tier didefinisikan organisasi, bukan disimpan GLPI. |
| **Impact analysis** (tab "Impact", GLPI 9.5+) | Graf dependency/impact antar aset. Support: Appliance, Cluster, Computer, Datacenter, Server room, Domain, Enclosure, Monitor, Network device, PDU, Peripheral, Phone, Printer, Rack, Software, **DatabaseInstance** + custom asset. Panah merah = impact, biru = dependency. Tabel `glpi_impacts*`. **Tidak ada** data RTO/RPO atau urutan recovery — murni topologi. | **Bantu scoping drill**: link dari form drill ke tab Impact aset untuk lihat "kalau site X jatuh, apa saja kena". Bukan sumber angka RTO. |

**b. Menambah field RTO/RPO ke aset GLPI**

| Cara | Status GLPI 11 | Catatan |
|---|---|---|
| **Genericity / custom fields native GLPI 11** | ❌ **Hanya** untuk *custom asset definition* baru — **tidak bisa** menambah field ke `Computer`/`Appliance`/`Software` bawaan | — |
| **Plugin `fields`** (`pluginsGLPI/fields`, "Additional Fields") | ✅ **Kompatibel GLPI 11** (v1.24.x) · **SUDAH TERPASANG di VM-HQ-GLPI** (dikonfirmasi user 2026-09-01) | Cara standar menambah blok RTO/RPO (number/dropdown) ke `Appliance`/`Computer` via UI. Sisa verifikasi VM: versi persisnya + container/blok sudah di-assign ke itemtype `Appliance` & `Computer` |
| Plugin `customfields` (`pluginsGLPI/customfields`) | Legacy | Jangan pakai — digantikan `fields` |

**c. Itemtype "aplikasi" di GLPI — yang benar**

- **`Application` TIDAK ada** di core GLPI. Konsep doc sebelumnya keliru.
- **`Appliance`** (Management → Appliances, GLPI 9.5+) = itemtype GLPI untuk
  "software & aplikasi yang dikelola di GLPI" — paling dekat dengan "aplikasi".
  Field: name, status, location, tech/group in charge, manufacturer, serial,
  inventory no, user, group, comments + **Appliance type** (dropdown: VOIP/EDM/
  custom) + **Appliance environment** (production/pre-production/acceptance) +
  pictures. Link via **`Appliance_Item`** ke Computer/Cluster/dll, ke appliance
  lain, contract, document, certificate, domain, KB. Punya tab Impact.
  → **DISEPAKATI (user 2026-09-01): web application dimodelkan sebagai `Appliance`.**
  Jadi RTO/RPO custom field dari plugin `fields` di-assign ke itemtype `Appliance`
  (untuk web app) + `Computer` (untuk server/host).
- **`Software`** (Assets → Software) = inventori software terpasang (OS, aplikasi
  office, utilitas) + versi + lisensi. **Tidak** dipakai untuk web app di sini.
- **`Cluster`** (GLPI 9.5+) = grup aset jadi cluster HA/fungsional — relevan untuk
  pasangan primary/DR node.
- **`DatabaseInstance`** / **`Database`** (GLPI 10.0+) = aset instans database.

**d. Tidak ada plugin GLPI khusus BCP/DR** — riset tidak menemukan plugin
business-continuity/disaster-recovery di ekosistem GLPI. `drillreport` mengisi gap
nyata.

### 6.4 Keputusan desain RTO/RPO (dampak dari riset §6.3 + fakta terkonfirmasi)

**Fakta terkonfirmasi (user 2026-09-01):**
- Plugin **`fields` sudah terpasang** di VM-HQ-GLPI.
- **Web application = itemtype `Appliance`.**

**Sumber kebenaran target RTO/RPO = custom field plugin `fields` pada aset:**

- Buat blok field `fields` (via UI, oleh admin) berisi 2 field number untuk target
  RTO & RPO — di-assign ke itemtype **`Appliance`** (web app) dan **`Computer`**
  (server/host). **Nama field belum final** (belum bisa akses server) → drillreport
  membacanya lewat config key `fields_rto_key` / `fields_rpo_key` (§7.2), default
  `rto_target_minutes` / `rpo_target_minutes`, bisa diubah tanpa rilis.
- Opsional: `dr_tier` (dropdown) kalau tidak mau pakai `businesscriticities_id`
  native.

**drillreport tetap menyimpan salinan target + nilai aktual per drill** di
`_drills` / `_items` — bukan hanya baca dari aset. Alasan:

1. Target bisa berubah antar tahun; laporan drill 2026 harus membekukan target
   yang berlaku **saat itu** (evidence audit).
2. Nilai **aktual** hanya ada di konteks drill — tidak pernah jadi field aset.
3. Kalau blok `fields` belum di-set untuk aset tertentu → drill tetap bisa diisi
   manual (plugin tidak boleh mati kalau field aset kosong).

**Alur:** saat aset ditambah ke drill (`_items`), form **pre-fill** `rto_target` /
`rpo_target` dari custom field `fields` aset itu (pembacaan lunak — kalau plugin
`fields` non-aktif / field tidak ada / nilai kosong → biarkan kosong, tidak
error). Operator bisa override. Nilai final + aktual disimpan di `_items`.

**Native yang tetap dipakai:**
- **Business criticity** (`businesscriticities_id`) aset → ditarik ke laporan
  sebagai kolom tier. Selalu ada, tanpa dependency.
- Tombol di form drill → buka tab **Impact analysis** aset untuk bantu scoping.

### 6.5 Opsi GLPI 11 — Custom Asset Definition (TIDAK dipakai — catatan alternatif)

> **Keputusan:** web app = `Appliance` + RTO/RPO via plugin `fields` (§6.4).
> Custom Asset Definition **tidak** dipakai. Disimpan hanya sebagai catatan kalau
> modeling aset kelak berubah.

GLPI 11 "Genericity" bisa bikin itemtype aset baru dari UI dengan field number
native (RTO/RPO) tanpa plugin `fields`. Tidak dipilih karena:

1. Web app sudah disepakati masuk `Appliance` — bikin custom asset paralel = data
   ganda + kebingungan.
2. Plugin `fields` sudah terpasang → menambah blok RTO/RPO ke `Appliance` cukup
   lewat UI, tanpa migrasi aset.
3. Custom asset punya batasan (tipe field immutable setelah disimpan, reserved
   word, field default tidak bisa dihapus).

Secara kode drillreport tidak ada bedanya — soft-read tetap `class_exists` /
`isPluginActive`-guarded; target bacaannya sekarang custom field `fields` pada
`Appliance` / `Computer`, bukan custom asset.

---

## 7. Apakah Format Bisa Diubah dengan Mudah? (Configurable)

**Ya — dengan pendekatan berlapis, tanpa harus rilis versi plugin baru untuk
sebagian besar perubahan.**

| Lapis | Apa yang bisa diubah | Cara | Butuh coding / upgrade? |
|---|---|---|---|
| **1. Dropdown itemtypes** | Daftar skenario/site DR, kategori action, label hasil | UI native GLPI: **Setup → Dropdowns** — admin tambah/edit/hapus sendiri | ❌ Tidak — bawaan GLPI |
| **2. Field org-spesifik tambahan** | Field custom apa pun di form drill (mis. "Nomor BCP", "Sponsor Manajemen", "Lokasi DRC") | Plugin **`fields`** v1.23.2 — terpasang & GLPI-11-compatible. Admin bikin field via UI, nempel ke itemtype `GlpiPlugin\Drillreport\Drill`. (Genericity native GLPI 11 **tidak** bisa untuk itemtype plugin — hanya custom asset baru, §6.3b) | ❌ Tidak — konfigurasi UI |
| **3. Default nilai** | RTO/RPO target default per skenario, reminder cron on/off & bulan | Plugin Config (`plugin:drillreport` context) + field di dropdown Scenario | ❌ Tidak — halaman Setup plugin |
| **4. Layout laporan cetak/PDF** | Section mana yang tampil, header/logo/footer, urutan | Config toggle per-section + override Twig template (`templates/report.html.twig`) | ⚠️ Toggle = tidak; ubah layout = edit Twig (ringan) |
| **5. Field inti standar** | RTO/RPO, objectives, struktur AAR/IP, timeline | Kolom tabel di migration | ✅ Ya — upgrade plugin + migration (jarang; sengaja "kaku" karena ini standar ISO/NIST yang memang tidak boleh sering berubah) |

**Prinsip desain:** field inti (lapis 5) dikunci ke standar ISO 22398 / NIST 800-84
supaya laporan tetap audit-ready dan konsisten antar tahun. Semua kebutuhan
"tweak" organisasi diarahkan ke lapis 1–2 (dropdown + plugin `fields`) yang
100% UI-driven. **Tidak** membangun form-builder penuh — over-engineering untuk
itemtype yang diisi 1x/tahun.

> Catatan: plugin `fields` terpasang **v1.23.2** (dikonfirmasi user) —
> GLPI-11-compatible. Yang belum: akses server untuk cek blok field per-itemtype
> & bikin field RTO/RPO. Sampai akses ada, nama field disimpan sebagai konstanta
> config (bisa diubah tanpa rilis).

### 7.1 Enum hardcoded vs Dropdown itemtype — bedanya

| | **Enum hardcoded** | **Dropdown itemtype** (`CommonDropdown`) |
|---|---|---|
| Nilai disimpan di | Konstanta PHP (`const TEST_TYPES = [...]`) | Tabel DB (`glpi_plugin_drillreport_testtypes`) |
| Tambah / rename nilai | Edit kode → rilis plugin baru | Admin via **Setup → Dropdowns**, langsung, tanpa kode |
| Terjemahan (i18n) | File `.po` plugin | Kolom per-bahasa di UI dropdown |
| Nonaktifkan nilai lama | Hapus dari kode (data lama jadi yatim) | Flag `is_active=0`, data lama tetap valid |
| Kode & tabel | Ringkas — 0 tabel, 0 class | +1 tabel, +1 class, +entri menu config |
| Cocok untuk | Nilai standar industri yang stabil (mis. `overall_result`: pass / pass-with-exceptions / fail) | Nilai yang organisasi mungkin mau ubah/tambah (mis. `test_type`, skenario, kategori action) |

**Keputusan drillreport:**
- `overall_result`, `objective_result` (met/partial/not-met), `action_status` →
  **enum** (baku, dari standar, jarang berubah)
- `test_type`, skenario/site DR (`scenarios_id`), kategori action → **dropdown
  itemtype** (configurable)

### 7.2 Config toggle konkret (`plugin:drillreport` context)

| Key config | Fungsi | Default |
|---|---|---|
| `show_business_criticity` | Tampilkan kolom Business Criticity aset di laporan | `on` |
| `show_timeline_fields` | Tampilkan field declaration/recovery/validation time + durasi saran | `off` |
| `asset_itemtypes` | Daftar itemtype yang boleh di-`Drill_Item` (CSV) | `Appliance,Computer` |
| `fields_rto_key` / `fields_rpo_key` | Nama custom field `fields` untuk soft-read target RTO/RPO | `rto_target_minutes` / `rpo_target_minutes` |
| `reminder_enabled` / `reminder_month` | Cron reminder tahunan "drill belum disubmit" | `on` / `11` |
| `report_sections` | Section laporan cetak yang aktif (CSV dari 14 komponen §6.1) | semua |

### 7.3 Form entry — Twig native vs hand-rolled

Yang dibandingkan: cara **render form input** di GLPI 11.

| | **Twig native GLPI** | **Hand-rolled** |
|---|---|---|
| Cara | Template `.html.twig` pakai macro form GLPI (`{{ fields.textField(...) }}`, `fields.dropdownField`, `fields.dateField`, …) via `TemplateRenderer` | `<form>` HTML/Bootstrap sendiri + `Dropdown::show*()`, `Html::showDateField()`, ditutup `Html::closeForm()` (inject CSRF) |
| Konsistensi tampilan | Otomatis ikut tema GLPI (light/dark), layout 2-kolom standar | Harus jaga sendiri biar mirip GLPI |
| Dropdown / datepicker / AJAX / validasi wajib | "Gratis" dari macro | Wiring manual per field |
| Kontrol layout custom (grid anak, matriks RTO/RPO, tab) | Terbatas — macro dioptimalkan untuk form field datar | Bebas total |
| Jumlah kode (form field datar `_drills` ~30 field) | Sedikit | Banyak (tiap `<tr><td>` + input ditulis) |
| Arah GLPI 11 | **Ini jalur resmi** — core sudah pindah ke Twig | Legacy-friendly tapi makin di luar konvensi |
| Dipakai `riskregister` | — | Jalur legacy `showFormHeader()` (form 200 baris) — **masih jalan di GLPI 11** (hub §4) |

> Ada 3 varian sebenarnya: (a) legacy `showFormHeader()` + `echo '<tr><td>…'` +
> `showFormButtons()` — cara lama, masih jalan; (b) **Twig macro** — cara GLPI 11;
> (c) **hand-rolled** `<form>` Bootstrap. `riskregister` pakai (a).

**Keputusan drillreport (plugin GLPI-11-only, baru):**

1. **Form induk `_drills`** (~30 field datar) → **Twig macro native**. Konsisten,
   sedikit kode, theme-aware.
2. **Anak `_objectives` / `_actions` / `_drills_items`** → **tab native GLPI**
   (`defineTabs()`), tiap anak = `CommonDBTM` dengan `showForm()` mini sendiri —
   **pola idiomatik GLPI**, bukan satu form raksasa. Grid daftar pakai komponen
   `@components/datatable`.
3. **Hand-rolled hanya** kalau satu view butuh layout yang macro tidak bisa
   (mis. matriks RTO/RPO target-vs-aktual antar aset). Laporan cetak/PDF
   (`report.html.twig`) memang Twig sendiri — bukan form, tidak masuk hitungan ini.

---

## 8. Peta Posisi dalam Ekosistem GLPI (saat ini)

```
┌──────────────┐     ┌──────────────────┐     ┌───────────────────┐
│  SAC          │     │  riskregister     │◀───▶│  integrasipaman     │
│  (belum       │────▶│  (Finding/Risk,   │     │  (JumpServer PAM,   │
│  dibangun)    │     │  belum verified   │     │  live di produksi)  │
└──────────────┘     └────────▲──────────┘     └───────────────────┘
                               │ (hook opsional,
                               │  bukan hard dependency)
                      ┌────────┴──────────┐
                      │  drillreport        │
                      │  (rencana baru,     │
                      │  standalone)        │
                      └───────────────────┘
```

## 9. Tampilan & Output — Search, Dashboard, Visualisasi

### 9.1 Search options (`rawSearchOptions()`)

Tiap itemtype (`Drill`, `Objective`, `Action`, `Drill_Item`) punya
`rawSearchOptions()` → menggerakkan Search engine GLPI: list view, filter, saved
search, **export CSV**, kolom yang bisa dipilih. Ini menutup ~90% kebutuhan
"list drill history" tanpa halaman custom.

**`Drill`** — opsi utama:

| Grup | Field |
|---|---|
| Umum | `name`, `id`, `entities_id` (80), `date_creation` (121), `date_mod` (19) |
| Domain | `date_drill`, `scenario` (join dropdown), `testtype` (join dropdown), `overall_result`, `status`, `groups_id` (71), `users_id_tech`, `approved_by`, `approval_date` |
| Metrik | `rto_target_minutes`, `rto_actual_minutes`, `rpo_target_minutes`, `rpo_actual_minutes` |
| Turunan (computed) | jumlah aset (`_drills_items`), jumlah action terbuka, jumlah action `severity=critical/high` — via `joinparams` + `datatype: specific` / `usehaving` |

**`Action`** — searchoption sendiri → "semua improvement action terbuka lintas
drill", filter `severity` / `status` / `date_due` (overdue), `groups_id` owner,
`riskregister_findings_id` (sudah dikirim / belum).

**`Drill_Item`** — searchoption dengan `itemtype` + `items_id` → dari halaman
sebuah `Appliance`/`Computer` muncul tab **"Riwayat DR Drill"** (`CommonDBRelation`)
= semua drill yang menyentuh aset itu + hasil RTO-nya per tahun.

### 9.2 Dashboard / heatmap — perlu halaman custom?

**Tidak untuk MVP.** Cukup:

1. **Saved searches** bawaan GLPI (mis. "Drill fail tahun ini", "Action overdue").
2. **2–3 native dashboard card** (`$PLUGIN_HOOKS['dashboard_cards']['drillreport']`,
   pola dari `riskregister` hub §4):
   - "Hasil drill tahun ini" (donut pass / pass-with-exceptions / fail)
   - "Improvement action terbuka per severity" (bar)
   - "Aset gagal penuhi RTO — drill terakhir" (angka + list)
3. Volume data kecil (1 drill/tahun) → tidak butuh framework dashboard sendiri.

### 9.3 Visualisasi tren multi-tahun — **Fase 2**

Berguna untuk governance DR, tapi bukan MVP. Data sudah tersedia di
`_drills_items` (join `_drills.date_drill` → tahun, `overall_result`, `rto_actual`
vs `rto_target` per aset).

- `Provider::assetHistory()` → per aset: baris `(tahun, drills_id, result,
  rto_target, rto_actual, rto_met?)`.
- Render satu Twig view (`history.html.twig`, dari menu — bukan dashboard):
  - **Heatmap aset × tahun** — sel hijau (pass & RTO tercapai) / kuning
    (pass-with-exceptions) / merah (fail).
  - **Sparkline RTO per aset** — target vs aktual sepanjang tahun.
- Tab "Riwayat DR Drill" di halaman aset (§9.1) = versi ringkas yang MVP.

### 9.4 Section toggle laporan cetak (`report_sections`)

Default: **14 komponen §6.1 semua ON** (audit-ready begitu diinstal). Toggle ada
supaya org bisa sembunyikan yang tidak dipakai. Yang lazim dimatikan:

| Section | Default | Catatan |
|---|---|---|
| 6 Timeline | ikut `show_timeline_fields` (off) | tampil hanya kalau timeline diisi |
| 13 Evidence/appendix | on | matikan kalau lampiran ditaruh di sistem lain |
| 4 Participants (detail per orang) | on | sebagian org cukup group in charge |

Sisanya (executive summary, RTO/RPO, objectives, findings, improvement plan,
approval) **tidak disarankan dimatikan** — inti laporan standar.

---

## 10. Roadmap / Fase

| Fase | Isi |
|---|---|
| **MVP** | 4 tabel data + 3 dropdown · form induk Twig + tab anak · `rawSearchOptions` semua itemtype · tab "Riwayat DR Drill" di aset · laporan cetak Twig (print CSS + `window.print`) · CSV export (native Search) · tombol "Kirim ke Risk Register" (§5) · cron reminder tahunan · soft-read RTO/RPO dari `fields` (§6.4) · Config page (§7.2) |
| **Fase 2** | Native dashboard cards (§9.2) · heatmap aset × tahun + sparkline RTO (§9.3) · matriks RTO/RPO hand-rolled (§7.3) · notifikasi GLPI (approval workflow) |
| **Kalau perlu** | `Cluster`/`DatabaseInstance` di `Drill_Item` (tinggal ubah config) · dropdown `ActionCategory` diaktifkan · integrasi balik status Finding → tampilkan di `_actions` |

---

## 11. Menu Node & Cron Task

### 11.1 Menu node — **Management** (bukan Tools)

**Keputusan: `Management → DR Drill Report`.**

| Kandidat | Untuk | Lawan |
|---|---|---|
| **Management** ✅ | Sejajar `riskregister` (Management → Risk Register) — domain governance yang sama & target integrasi. `Appliance` (itemtype utama yang di-link) juga hidup di Management. Data governance level-organisasi, bukan utilitas per-user. Sub-menu Contracts/Certificates/Appliances/Clusters/Databases semua "manajemen CMDB/bisnis" — drillreport pas di situ. | — |
| Tools | Tempat `integrasipaman`. | Tools = utilitas (Projects, Notes, Reminders, KB, Reports). `integrasipaman` di sana karena sifatnya **sync/integration utility**; drillreport adalah **modul records-management** seperti `riskregister`, bukan utilitas. "Reports" di Tools = plugin `reports`/`mreporting`, hal lain. |

```php
$PLUGIN_HOOKS['menu_toadd']['drillreport'] = [
    'management' => \GlpiPlugin\Drillreport\Menu::class,
];
```

`Menu extends CommonGLPI` (pola `riskregister` hub §4) → `getMenuContent()` return
`['title','page','icon','links','options'=>[...]]`. Sub-entri:

| Sub-entri | Halaman | Fase |
|---|---|---|
| Drills | list `Drill` (Search) | MVP |
| Improvement Actions | list `Action` (Search, filter overdue) | MVP |
| DR Drill History | `history.html.twig` — heatmap aset × tahun | Fase 2 |
| Setup | Config page (`plugin:drillreport`) | MVP |

Icon: `ti ti-refresh-alert` atau `ti ti-shield-check` (Tabler, dipakai GLPI 11).

### 11.2 Cron task — usulan nama

Register di `install()` via `CronTask::register(\GlpiPlugin\Drillreport\Cron::class,
'<Nama>', <freq_detik>, [...])`. GLPI dispatch ke
`\GlpiPlugin\Drillreport\Cron::cron<Nama>($task)`. CamelCase (pola `riskregister`
`RiskReview`). Namespace cron `GlpiPlugin\Drillreport\Cron::*` sudah diklaim di
hub §2 — tanpa bentrok.

| # | Nama task | Frekуensi | Fungsi | Fase |
|---|---|---|---|---|
| 1 | **`DrillReminder`** | `DAY_TIMESTAMP` (harian, tapi hanya bertindak di bulan `reminder_month`) | Kirim notifikasi ke `groups_id` DR: "Drill DR tahun `<Y>` belum disubmit" kalau belum ada `Drill` dengan `YEAR(date_drill)=<Y>` & `status IN (submitted,approved)`. Guard `reminder_enabled`. | **MVP** |
| 2 | **`ActionFollowup`** | `WEEK_TIMESTAMP` (mingguan) | Notifikasi ke owner (`groups_id`/`users_id`) tiap baris `_actions` dengan `date_due < today` & `status NOT IN (done,cancelled)`. | MVP / awal Fase 2 |
| 3 | **`FindingStatusSync`** | `DAY_TIMESTAMP` | *(opsional, "kalau perlu")* Kalau `riskregister` aktif & `_actions.riskregister_findings_id > 0` → baca `Finding.status` terkini, simpan cache untuk ditampilkan di `_actions`. Read-only, guarded. | Kalau perlu |

Full identifier: `GlpiPlugin\Drillreport\Cron::DrillReminder` /
`…::ActionFollowup` / `…::FindingStatusSync`.

Di VM-HQ-GLPI dijalankan lewat `sudo -u nginx php /data/www/glpi/front/cron.php
DrillReminder` (tidak ada `bin/console` cron — hub §3); `/etc/cron.d/glpi` yang
sudah ada meng-cover otomatis.

---

## 12. Hal yang Perlu Diputuskan Selanjutnya (di Claude Code)

- [x] ~~Finalisasi skema tabel (§6.2)~~ **DONE** — 4 tabel data + 3 dropdown,
      kolom/tipe/index/enum di §6.2. `_actions.code` per-action (§5.1).
- [x] ~~Plugin `fields` terpasang + versi?~~ **SUDAH, v1.23.2** (user 2026-09-01) —
      GLPI-11-compatible (fields ≥1.22.0 = GLPI 11; 1.23.2 target `~11.0.2`). ✅
- [ ] **Nama field `fields` untuk RTO/RPO target = TBD** — belum bisa akses server.
      Rencana: `rto_target_minutes` / `rpo_target_minutes` (number) di `Appliance` +
      `Computer`. Buat blok-nya + sepakati nama saat akses server tersedia. Sampai
      itu: soft-read §6.4 dikoding dengan **nama field sebagai konstanta config**
      (bisa diubah tanpa rilis).
- [x] ~~`Drill_Item` itemtype target?~~ Mulai **`Appliance` (web app) + `Computer`**.
      `Cluster` / `DatabaseInstance` **belum masuk, direncanakan** → daftar itemtype
      target dibuat **configurable** (Plugin Config), jadi nambah nanti = ubah
      config, bukan migration.
- [x] ~~Model DR sebagai Custom Asset GLPI 11?~~ **TIDAK** — web app = `Appliance` (§6.5)
- [x] ~~Tarik `businesscriticities_id` ke laporan?~~ **Configurable** — toggle
      Plugin Config "tampilkan kolom Business Criticity di laporan" (default: on).
- [x] ~~`test_type` enum vs dropdown?~~ **Dropdown itemtype** (configurable) —
      `PluginDrillreportTestType` (`CommonDropdown`), di-seed 5 nilai standar saat
      install (tabletop / walkthrough / simulation / parallel / full-failover),
      admin bisa tambah/rename via Setup → Dropdowns. Lihat §7.1 untuk beda
      enum vs dropdown.
- [x] ~~RTO/RPO aktual: auto dari timeline atau manual?~~ **Manual input**
      (`rto_actual_minutes` / `rpo_actual_minutes` diisi operator). **Configurable:**
      toggle "tampilkan field timeline (declaration/recovery/validation time) +
      hitung durasi sebagai saran" — default off. Timeline = pelengkap, bukan
      sumber angka.
- [x] ~~Form entry: Twig vs hand-rolled?~~ **Twig macro native** untuk form induk
      `_drills`; anak (`_objectives`/`_actions`/`_drills_items`) sebagai **tab native**
      (`defineTabs()`, tiap anak `CommonDBTM` + `showForm()` mini). Hand-rolled hanya
      untuk view khusus (mis. matriks RTO/RPO). Detail §7.3.
- [x] ~~Search options?~~ `rawSearchOptions()` di semua itemtype — detail §9.1.
- [x] ~~Dashboard/heatmap sendiri?~~ **Tidak untuk MVP** — saved search + 2-3 native
      card. Heatmap multi-tahun = Fase 2 (§9.2–9.3).
- [x] ~~Section toggle default?~~ semua 14 ON; yang lazim dimatikan di §9.4.
- [x] ~~Histori multi-tahun?~~ **Fase 2** — heatmap aset×tahun + sparkline RTO
      (§9.3). MVP = tab "Riwayat DR Drill" di halaman aset.

- [x] ~~Menu node: Tools vs Management?~~ **Management → DR Drill Report** (§11.1)
- [x] ~~Nama cron task?~~ `DrillReminder` + `ActionFollowup` (impl.) `FindingStatusSync` (opsional) — §11.2
- [x] ~~Right `plugin_drillreport_*`?~~ **4 right** `_drill` / `_action` / `_approve` / `_config`
- [x] ~~Bikin repo + skeleton?~~ **DONE** — `teguh-umar/drillreport` (private), commit
      `edb3ce2` v0.1.0. Konsep doc kanonik pindah ke repo: `drillreport/docs/drillreport-konsep-awal.md`.

## 13. Status Implementasi

**Repo:** `teguh-umar/drillreport` (private) · **v0.2.2** · `drillreportv0.2.2.zip`
dibangun. Child-CRUD + integrasi RR + **3 fix kompat GLPI 11.0.x** + **static
review lengkap vs source `glpi 11.0/bugfixes`** (semua framework call di `src/` +
`front/` diverifikasi). `php -l` bersih. **Belum dijalankan di VM-HQ-GLPI** —
jalankan `docs/deploy-checklist.md`.

> ⚠️ **GLPI 11.0.x signature tightening** (dua-duanya sudah difix di drillreport):
> 1. `CommonGLPI::canView()` + `CommonDBRelation::can{View,Create,Update,Delete,Purge}`
>    sekarang `: bool`. Override tanpa return type = fatal compile error →
>    **seluruh GLPI tumbang** (login included). Fix: `Menu::canView(): bool` (`87448fd`).
> 2. `getTabNameForItem()` **harus instance method** (non-static) — di core
>    `CommonGLPI` non-static, dan untuk kelas yang extends `\Profile` core-nya juga
>    non-static. `static` override = fatal "cannot make non static method static"
>    saat autoload (kebuka waktu Setup → Profiles / tab drill dibuka). Fix:
>    `Objective` / `Action` / `Profile` `getTabNameForItem` jadi non-static
>    (`0.2.1`). `displayTabContentForItem` tetap `static`. `Drill_Item` sudah benar.
>
> Aturan main: kalau nambah override, grep parent class-nya, samakan signature-nya
> persis. Heads-up dari sesi riskregister (v1.1.1 + v1.1.2 kena dua-duanya).

Sudah ada:

| Area | File |
|---|---|
| Bootstrap | `setup.php`, `hook.php`, `composer.json`, `LICENSE` (GPL-3.0), `README.md`, `CHANGELOG.md` |
| Schema (8 tabel) | `hook.php` + `src/Migration.php` |
| Itemtype | `src/Drill.php` (form legacy sementara), `Objective.php`, `Action.php`, `Drill_Item.php` |
| **Child CRUD** | tab tambah/edit/hapus inline untuk Objective / Action / Drill_Item + `front/objective.form.php`, `action.form.php`, `drill_item.form.php` |
| **Send to RR** | tombol "Send findings to Risk Register" di drill fail (guard `riskregisterAvailable()`) → `Action::sendDrillToRiskRegister()` upsert per baris + write-back `findings_id`/`code` |
| **Soft-read `fields`** | `Drill_Item::fieldsSoftReadTargets()` — pre-fill RTO/RPO target dari custom field aset saat link dibuat; no-op kalau `fields` non-aktif |
| Dropdown | `Scenario.php`, `TestType.php`, `ActionCategory.php`, `Seed.php` (seed 5 test type) |
| Rights | `RightDefinition.php` (4 right), grant per profil di `hook.php` |
| Menu / Setup | `Menu.php`, `Profile.php`, `front/config.php` |
| Cron | `Cron.php` (`DrillReminder`, `ActionFollowup` — log-only stub) |
| Asset picker | `Dropdown::showSelectItemFromItemtypes` di tab "Systems in scope" |

Belum ada (MVP berikutnya):

- [ ] Form entry Twig macro (ganti `Drill::showForm()` legacy) — §7.3
- [ ] Notifikasi GLPI untuk kedua cron (sekarang cuma `Toolbox::logInFile`)
- [ ] Laporan cetak (`templates/report.html.twig` + print CSS + `window.print`) — §6
- [ ] Kolom Business Criticity di laporan (toggle `show_business_criticity`) — §6.3a

Belum diverifikasi (butuh akses VM):

- [ ] `php bin/console glpi:plugin:install drillreport` bersih
- [ ] `date_creation`/`date_mod` raw `timestamp` di CREATE TABLE — apakah `Plugin.php` VM ngeflag (hub §7 catatan `-46`); kalau ya → `$migration->addField(..., 'timestamp')`
- [ ] Nama field `fields` RTO/RPO final + blok di-assign ke `Appliance`/`Computer`
- [ ] Menu highlight + sub-entri tampil benar di Management

### Sisa keputusan kecil

- [ ] Konfirmasi label menu ("DR Drill Report" dipakai sekarang)
