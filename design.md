# Design System: Hybrid Live Attendance PT. LSI

Referensi implementasi UI untuk Svelte 5 + Inertia + Tailwind CSS v4 ([app.css](file:///c:/Projects/Web_attendance_lsi/resources/css/app.css)).
Arah desain: **Clean Corporate & Trustworthy** (Light Mode), **Brand Brown**, **Hybrid Role-Based** (Mobile-First karyawan/sales, Desktop-First HR), **Compact / Padat Data** (tetap menjaga touch target `min-h-12` pada CTA lapangan).

---

## 1. Color & Semantic Tokens (Light Mode Only)

Selaraskan variabel CSS di `:root` (`app.css`) dengan palet **Corporate Brown + Warm Stone**:

| Token | Hex | Tailwind Class / CSS Var | Penggunaan |
|---|---|---|---|
| **Primary (Brand)** | `#6F3E1D` | `bg-[#6F3E1D]` / `bg-primary text-primary-foreground` | CTA utama, active nav, focus ring (`--primary`) |
| **Primary Hover** | `#583015` | `hover:bg-[#583015]` | Hover tombol & link primer |
| **Primary Soft** | `#F5EDE6` | `bg-[#F5EDE6] text-[#6F3E1D]` | Active pill sidebar/bottom-nav, highlight ringan |
| **Secondary (Warm Accent)** | `#8B5E3C` | `bg-[#8B5E3C] text-white` | Header banner, sidebar brand header, border-t KPI, kanvas gradient |
| **Background** | `#F8F7F4` | `bg-gradient-to-b from-[#8B5E3C]/10 via-[#F8F7F4] to-[#F8F7F4]` | Canvas aplikasi dengan gradien hangat agar tidak monoton |
| **Surface / Card** | `#FFFFFF` | `bg-white` (`--card`) | Kartu, tabel, modal, sticky bar |
| **Muted Surface** | `#F5F5F4` | `bg-stone-100` (`--muted`) | Header tabel, stripped row, chip non-aktif |
| **Text Primary** | `#1C1917` | `text-stone-900` (`--foreground`) | Heading, nilai data utama, jam server |
| **Text Muted** | `#57534E` | `text-stone-600` (`--muted-foreground`) | Label sekunder, timestamp, helper text |
| **Border / Input** | `#E7E5E4` / `#D6D3D1` | `border-stone-200` / `border-stone-300` | Garis pemisah tabel padat, border kartu & input |

### Semantic Status & Anomaly Badges (PRD Mapping)
Format badge wajib kompak: `inline-flex items-center gap-1 rounded px-1.5 py-0.5 text-[11px] font-medium border whitespace-nowrap`.

| Status / Flag PRD | Text Hex & Class | Bg & Border Class | Contoh Konteks |
|---|---|---|---|
| **Success** | `#15803D` `text-green-700` | `bg-green-50 border-green-300` | `AUTO_APPROVED`, `APPROVED`, `ACTIVE`, `COMPLETED`, `Hadir` |
| **Warning / Review** | `#A16207` `text-yellow-800` | `bg-yellow-50 border-yellow-300` | `PENDING_REVIEW`, `PENDING`, `PENDING_VERIFICATION`, `IN_PROGRESS`, `Terlambat`, `Belum Absen`, `OUT_OF_RADIUS`, `LOW_ACCURACY`, `EARLY_LEAVE`, `SHORT_DURATION`, `>2 hari cuti` |
| **Error / Reject** | `#B91C1C` `text-red-700` | `bg-red-50 border-red-300` | `REJECTED`, `INACTIVE`, `Alpa`, `Alpa (Ditolak HR)`, GPS mati, In-app browser block |
| **Fraud / Critical** | `#9F1239` `text-rose-800` | `bg-rose-50 border-rose-300` | `SHARED_DEVICE`, `IMPOSSIBLE_TRAVEL`, `REPEATED_COORDS`, `CONSTANT_ACCURACY`, `STALE_TIMESTAMP` |
| **Info / Khusus** | `#1D4ED8` `text-blue-700` | `bg-blue-50 border-blue-300` | `DINAS_LUAR`, `OFFSITE_DUTY`, `OFF_DAY_ATTENDANCE`, `MANUAL_HR`, `PARTIALLY_CANCELLED` |
| **Neutral / Off** | `#44403C` `text-stone-700` | `bg-stone-100 border-stone-300` | `CANCELLED`, `AUTO_CLOSED`, `Libur` / `is_day_off`, `Berkas retensi 12 bln` |

---

## 2. Typography, Density & Spacing

- **Font Family:** Sans-serif `Instrument Sans, ui-sans-serif, system-ui, sans-serif`; Monospace `ui-monospace, SFMono-Regular, monospace` (`font-mono tabular-nums`) wajib untuk jam server, NIK, jarak meter (`d`/`a`/`R`), koordinat, durasi menit, dan hash audit.
- **Type Scale (Compact Corporate):**
  - `Page Title (H1)`: `text-lg md:text-xl font-semibold tracking-tight text-stone-900`
  - `Section / Card Title (H2)`: `text-sm font-semibold text-stone-900`
  - `Body / Table Cell`: `text-[13px] leading-snug text-stone-800` (mobile form body `text-sm`)
  - `Field Label`: `text-xs font-medium text-stone-700`
  - `Caption / Meta / Badge`: `text-[11px] leading-tight text-stone-500`
- **Compact Spacing Scale:**
  - Container padding: Mobile `px-3 py-3 pb-20`; Desktop HR `p-4 lg:p-5`
  - Card & Modal padding: `p-3` (mobile/padat) atau `p-4` (modal verifikasi), gap antar blok `gap-3`
  - Table density: header `h-8 px-2.5 text-[11px] uppercase tracking-wide bg-stone-100`; cell `py-1.5 px-2.5 text-[13px]`
  - Touch targets (Mobile `/attendance/*`, `/leave`): CTA utama `min-h-12` (48px), icon/secondary button `min-h-11 min-w-11` (44px). Desktop HR action buttons: `h-8 px-2.5 text-xs`.
  - Radius & Shadow: `rounded-md` (input/button/badge), `rounded-lg` (card/modal); shadow minimal `shadow-xs` + `border border-stone-200`.

---

## 3. Shell & Navigasi per Role

1. **Auth, Consent UU PDP & Force Change Password (`/login`, consent, password):**
   - Centered 1-col card `max-w-sm mx-auto mt-12 p-5 bg-white border border-stone-200 rounded-lg`.
   - Consent UU PDP: checklist ringkas cakupan data (GPS, kamera, SKD) + tombol primer `Setuju` (`min-h-12`) & sekunder `Tolak` (trigger logout).
2. **Mobile-First Shell (`EMPLOYEE_OFFICE`, `EMPLOYEE_SALES`, plus `HR_ADMIN` di `/attendance/office` & `/leave`):**
   - **Sticky Top Header (`h-12 bg-white border-b border-stone-200 px-3`):** Nama cabang & badge zona waktu (`WIB`/`WITA`), jam server real-time `font-mono text-sm font-semibold`, serta tombol kembali ke `/admin/dashboard` khusus `HR_ADMIN`.
   - **Content:** Single column `max-w-lg mx-auto space-y-3`.
   - **Fixed Bottom Nav (`h-14 bg-white border-t border-stone-200 grid-cols-3/4`):**
     - `EMPLOYEE_OFFICE`: `Absen` (`/attendance/office`), `Cuti & Dinas` (`/leave`), `Riwayat` (`/attendance/history`), `Profil`.
     - `EMPLOYEE_SALES`: `Kunjungan` (`/attendance/sales`), `Cuti & Izin` (`/leave`), `Riwayat` (`/attendance/history`), `Profil`. (Tanpa rute `/attendance/office` & opsi `DINAS_LUAR`).
3. **Desktop-First Shell (`HR_ADMIN` — `/admin/*`):**
   - **Sidebar (`w-60 shrink-0 bg-white border-r border-stone-200`):** Navigasi terkelompok: *Monitoring* (Dashboard Rekap, Antrean Review, Persetujuan Cuti/Izin `/admin/leaves`), *Master Data* (Karyawan, Shift & Pola, Kantor & Toko/NOO, Kategori, Libur, Mapping Talenta `/admin/talenta-mapping`), *Pribadi & Audit* (Absen Saya `/attendance/office`, Cuti Saya `/leave`, Riwayat Audit `/admin/auditlogs`).
   - **Top Alert Slot:** Banner permanen `bg-yellow-50 border-b border-yellow-300 px-4 py-2 text-xs` jika akun `HR_ADMIN` aktif `< 2` atau bentrok cuti antar-HR `< 2`.

---

## 4. Spesifikasi Komponen Inti (PRD Feature Map)

### A. Absensi Kantor (`/attendance/office` — `US-003`, `US-004`, `US-010`)
- **In-App Browser Blocker:** Full-width alert merah (`bg-red-50 border-red-300`) bila terdeteksi UA WhatsApp/Line, menginstruksikan buka di Chrome/Safari.
- **Status & Shift Summary Card:** Shift aktif (`JWD 08:00–16:00`), status `AUTO_APPROVED`/`PENDING_REVIEW`/`REJECTED`, atau locked banner biru/merah saat Cuti/Izin/Sakit `APPROVED` (disable tombol Clock-In). Jika `clock_in_status = REJECTED` dan sebelum `effective_end_time`, tampilkan banner merah berisi `clock_in_reject_reason` + CTA `Absen Ulang Sekarang` (`min-h-12`).
- **GPS & Accuracy Bar:** Status bar kompak (`d: 18m • a: 12m • R: 50m`, `font-mono`). Jika `a > 100m` atau `a > R`, tampilkan warning kuning `Sinyal GPS kurang akurat` + tombol `Muat Ulang GPS` (`min-h-11`) + textarea wajib `Alasan`. Jika GPS mati, kunci CTA submit.
- **WebRTC Selfie Camera Widget:** `<video>` live stream `aspect-[3/4] max-h-72 w-full rounded-md bg-stone-900 object-cover` (dilarang `<input type="file">`). Indikator kompresi `<300 KB`, hasil preview side-check, CTA `Clock-In` / `Clock-Out` (`min-h-12 w-full bg-[#6F3E1D]`).

### B. Kunjungan Sales (`/attendance/sales` — `US-005`, `US-006`)
- **Active Visit Card (Max 1 `IN_PROGRESS`):** Tampilkan nama toko, jam Visit In, timer durasi berjalan (`font-mono`), tombol `Batalkan Visit In` (aktif `≤5 menit`, buka modal wajib `cancel_reason`, info sisa kuota `3/hari`), dan form inline **Visit Out** (select `activity_categories` + textarea `visit_notes` + token GPS, **tanpa kamera**).
- **Unified Outlet Picker:** Search bar terpadu (`h-11`), horizontal scroll chips `Recently Visited Outlets` (maks 5), daftar maksimal 30 toko terdekat menampilkan **Nama, Kode, Alamat, Radius, Status, Jarak (`distance_m`)** — *tanpa menampilkan koordinat lat/lng*.
- **Ajukan Toko Baru (NOO) Modal:** Input nama & alamat toko, kunci GPS dengan guard `accuracy_m ≤ 50m` (warning + tombol refresh bila `>50m`), daftar peringatan toko eksisting dalam radius `100m` sebelum simpan.

### C. ESS Cuti, Izin, Sakit & Dinas Luar (`/leave` — `US-009`)
- **Leave Balance 4-Grid (`grid-cols-4 gap-2`):** Kartu kompak berisi `Jatah`, `Terpakai + Cutber`, `Pending`, dan `Sisa` (`font-mono font-semibold`; izinkan badge merah jika minus). Tampilkan state `0 hari (< 1 tahun)` dengan kunci opsi `CUTI`.
- **Pengajuan Form Modal/Drawer:** Radio/Select jenis (`CUTI`, `IZIN`, `SAKIT`, plus `DINAS_LUAR` khusus non-Sales). Input tanggal terikat horizon `90 hari` (`user_shift_schedules` / `work_days`), preview kalkulasi `total_days` efektif otomatis, upload SKD (wajib `SAKIT`, JPG/PNG/PDF `≤2MB`), field `destination_location` + `scheduled_start_time` (wajib `DINAS_LUAR`), dan **Soft Warning Banner** kuning bila `CUTI > 2 hari/bulan`.
- **History List:** Kartu per pengajuan menampilkan badge status (`PARTIALLY_CANCELLED` dsb.), rincian tanggal aktif/batal, `reject_reason`, serta tombol `Batalkan Pengajuan` (khusus `PENDING`).

### D. Riwayat Pribadi (`/attendance/history` — `US-010`)
- **Filter Bar:** Bulan / `30 Hari Terakhir` + toggle tipe.
- **Compact Log Cards:** Per fase masuk/pulang (atau Visit In/Out), tampilkan `attempt_no`, jam lokal, flag anomali, `reject_reason`, dan thumbnail foto via **Signed URL** (atau teks muted `Berkas melewati retensi 12 bulan` bila `NULL`).

### E. Panel HR Admin (`/admin/*` — `US-007`, `US-008`, Lampiran)
- **KPI & Filter Strip:** 5 kartu angka ringkas (`Total Aktif`, `Hadir`, `Terlambat`, `Belum Absen / Alpa`, `Antrean Review`) + filter tanggal, cabang, role, status, dan flag anomali (`SHARED_DEVICE`, `OFFSITE_DUTY`, dll.).
- **Compact Data Table & Bulk Bar:**
  - Kolom sticky kiri (Nama/NIK), badge `Input Manual HR` untuk `MANUAL_HR`, status kehadiran, badge fase masuk/pulang terpisah, tombol aksi `Tinjau` / `Koreksi`.
  - Checkbox seleksi massal + sticky bottom/top **Bulk Approve Bar** (otomatis disable/kecualikan item `self`, `MISSED_CLOCK_OUT`, `MISSED_VISIT_OUT`, `OFFSITE_DUTY`, `OFF_DAY_ATTENDANCE`, `UNVERIFIED_OUTLET`, serta seluruh flag fraud).
- **Verification Review Modal (`max-w-5xl`, 2-column desktop):**
  - *Panel Kiri:* Foto Profil Referensi vs Foto Absen/Visit In **berdampingan (`grid-cols-2 gap-2`)** + metadata teknis (`device_id`, `IP`, akurasi, catatan alasan, detail `SHARED_DEVICE` pasangan akun atau info `DINAS_LUAR` + tanda pengajuan `Sakit Susulan` pada tanggal `REJECTED`).
  - *Panel Kanan:* Peta **Leaflet.js** (`h-72 rounded-md border`) merender pin lokasi kantor/toko + lingkaran `radius_m` (`#6F3E1D`) + pin GPS user + lingkaran `accuracy_m` (`#1D4ED8`).
  - *Footer Aksi:* Input editable `clock_out_at`/`visit_out_at` (khusus `MISSED_*`), textarea wajib saat `Reject`, tombol `Approve` (`bg-green-700`) & `Reject` (`bg-red-700`), atau `Koreksi Status (REJECTED → APPROVED)`. Disabled otomatis jika `reviewed_by === user_id`.
- **Master Data, Impor & Ekspor Modals:**
  - **CSV Import Preview Modal:** Tabel validasi baris-per-baris sebelum commit atomik (saldo awal cuti & daftar toko).
  - **Export 3 Format Modal:** Pilih rentang (`≤93 hari`), tipe (`File 1 Office 26-kolom`, `File 2 Sales 18-kolom` + checkbox *Sertakan Pending/Rejected*, `File 3 Audit`), dan **Warning Confirmation Modal** otomatis jika masih ada `PENDING` / `PENDING_REVIEW` atau rentang mencakup `hari_ini`.
  - **Override & Audit UI:** Dialog konfirmasi `Lanjutkan / Batal` untuk cuti `>2 hari/bulan`; tabel `/admin/auditlogs` read-only dengan diff `old_values`/`new_values` (`font-mono text-xs`) dan status verifikasi `row_hash`.
