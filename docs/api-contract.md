# API & Inertia Contract — Web Attendance LSI (Core Modules + Reporting Summary)

Dokumen kontrak sinkronisasi resmi antara `[BACKEND]` (Laravel 13 + Inertia v3) dan `[FRONTEND]` (Svelte 5) untuk PT Ladang Sehat Indonesia, selaras dengan `docs/PRD.md` (v8) dan `docs/design.md`.

**Konvensi Global:**
- **Auth & CSRF:** Session cookie Laravel (`credentials: 'same-origin'`) + header `X-XSRF-TOKEN` + `Accept: application/json` untuk endpoint JSON.
- **Device Cookie:** `lsi_device_id` (`httpOnly`, `SameSite=Lax`, TTL 365 hari, persisten lintas logout/ganti akun).
- **Timezone:** Semua timestamp database disimpan UTC; UI menampilkan jam lokal cabang (`Asia/Jakarta` = `WIB`, `Asia/Makassar` = `WITA`). Khusus `sales_visits`: `visit_date` memakai timezone homebase sales (`users.office_location_id`), sedangkan jam tampil & watermark memakai timezone outlet (`outlet_location_id`).
- **Standard Error JSON:**
  - `401 Unauthenticated`: sesi habis / belum login.
  - `403 Forbidden`: role tidak berwenang / aksi self-manipulation / bukan pemilik resource.
  - `409 Conflict`: `idempotency_key` sudah dipakai user lain, atau soft-warning duplikat NOO (`duplicate_warning: true`), atau warning ekspor pending.
  - `419 Page Expired`: CSRF token kedaluwarsa.
  - `422 Unprocessable Entity`: `{ "message": "...", "errors": { "<field>": ["..."] } }`.
  - `429 Too Many Requests`: rate limit login tercapai (`5x/(user+IP)` atau `30x/IP` per 15 menit).

---

## 1. Autentikasi Role, Keamanan Sesi, UU PDP & Token

### 1.1 Auth & Role Redirect
| Method & Path | Middleware | Request Payload | Response / Redirect | Error Status |
| :--- | :--- | :--- | :--- | :--- |
| `POST /login` | `guest` | `{ email: string (NIK atau Email), password: string, remember?: boolean }` | `302` → `/settings/security` (jika `must_change_password = true`), lalu `/consent` (jika `consent_accepted_at = NULL`), lalu `/dashboard` → redirect role (`HR_ADMIN` → `/admin/dashboard`, `EMPLOYEE_SALES` → `/attendance/sales`, `EMPLOYEE_OFFICE` → `/attendance/office`) | `422` kredensial salah / akun nonaktif; `429` throttle terkunci |
| `POST /logout` | `auth` | — | `302` → `/login` (cookie `lsi_device_id` TIDAK dihapus) | `401` |
| `GET /consent` | `auth, active, password.changed` | — | Inertia `auth/Consent` | `302` ke `/dashboard` jika sudah setuju |
| `POST /consent/accept` | `auth, active, password.changed` | — | Catat `consent_accepted_at`, `302` → `/dashboard` | `401` |
| `POST /consent/decline` | `auth, active, password.changed` | — | Logout paksa + flash pesan `'Harap setujui akses kamera dan lokasi GPS jika ingin absen'`, `302` → `/login` | `401` |
| `PUT /settings/password` | `auth, verified, active` | `{ current_password, password, password_confirmation }` | Update password, set `must_change_password = false` | `422`, `429` |
| `POST /notifications/read-all` | `auth, verified, active, password.changed, consent` | — | `302` back (mark all `unreadNotifications` as read) | `401` |
| `GET /settings/security` | `auth, verified, active` | — | Inertia `settings/Security` dengan props `{ passwordRules: string, mustChangePassword: boolean }` (tanpa middleware `RequirePassword` agar alur `must_change_password = true` langsung menuju form ganti password tanpa loop konfirmasi) | `401` |
| `GET /settings/appearance` | `auth, verified, active` | — | Inertia `settings/Appearance` (informasi Light Mode Only sesuai `docs/design.md`) | `401` |
### 1.2 Single-Use Security Tokens (GPS & Kamera)
| Method & Path | Middleware | Request | Response `200 OK` | Error |
| :--- | :--- | :--- | :--- | :--- |
| `POST /api/tokens/gps` | `auth, verified, active, password.changed, consent` | `{}` | `{ token: string (UUID), issued_at: int (unix), expires_at: int (unix) }` (TTL 300s; `STALE_TIMESTAMP` jika umur `> 120s` dan `<= 300s`) | `401`, `419` |
| `POST /api/tokens/camera` | `auth, verified, active, password.changed, consent` | `{}` | `{ token: string (UUID), issued_at: int (unix), expires_at: int (unix) }` (TTL 300s; tidak memicu `STALE_TIMESTAMP`) | `401`, `419` |

**Aturan Konsumsi Token (WAJIB Backend & Frontend):**
1. Frontend meminta `gps_session_token` **HANYA** saat fix GPS awal diperoleh atau tombol `Muat Ulang GPS` ditekan — **tidak boleh** direfresh diam-diam saat klik submit.
2. Backend memeriksa `idempotency_key` terlebih dahulu (FR-5), memvalidasi seluruh aturan bisnis + MIME gambar + token (tanpa menghapus cache), lalu **baru mengonsumsi (menghapus) kedua token di tahap akhir di dalam transaksi simpan final** agar HTTP 422 tidak menghanguskan token.

### 1.3 Signed Evidence File
| Method & Path | Middleware | Query | Response | Error |
| :--- | :--- | :--- | :--- | :--- |
| `GET /evidence/file` | `auth, active, signed` | `?path=...&expires=...&signature=...` (TTL 5–15 menit) | Binary stream gambar/PDF privat | `403` jika signature invalid/kedaluwarsa atau bukan `HR_ADMIN` dan bukan pemilik berkas |

---

## 2. Absensi Hybrid Kantor & Validasi Geofencing GPS (`EMPLOYEE_OFFICE` & `HR_ADMIN`)

Akses ditolak (`403 Forbidden`) untuk `EMPLOYEE_SALES`.

### 2.1 Halaman Absensi Kantor (`GET /attendance/office` & `GET /admin/absen-saya`)
**Inertia Component:** `office/Absen` (atau `admin/AbsenSaya` / `admin/AbsenSayaMobile`).

**Props Response:**
```ts
type OfficeAttendancePageProps = {
  office: {
    id: number;
    name: string;
    radius_m: number;
    timezone: 'Asia/Jakarta' | 'Asia/Makassar';
    latitude: number;
    longitude: number;
  };
  serverTimeIso: string; // ISO-8601 UTC
  workDate: string; // YYYY-MM-DD (local branch)
  shift: {
    code: string;
    name: string;
    start_time: string; // HH:mm:ss
    end_time: string; // HH:mm:ss
    grace_period_minutes: number;
  } | null;
  isDayOff: boolean; // !isEffectiveWorkDay || shift === null
  hasApprovedTimeOff: boolean; // CUTI/IZIN/SAKIT APPROVED pada workDate
  dinasLuar: {
    id: number;
    destination_location: string;
    scheduled_start_time: string; // HH:mm:ss
    status: 'PENDING' | 'APPROVED';
  } | null;
  effectiveStartIso: string | null;
  effectiveEndIso: string | null;
  closeDeadlineIso: string; // Batas auto-close Clock-Out (min(effectiveEnd + auto_close_hours, 23:59); 23:59 jika hari non-efektif)
  retryDeadlineIso: string; // Batas absen ulang setelah REJECTED (effectiveEnd, atau 23:59 jika !isEffectiveWorkDay && tanpa DINAS_LUAR)
  activeLog: AttendanceLogSummary | null;
  rejectedLog: AttendanceLogSummary | null;
  canRetryRejected: boolean; // rejectedLog != null && clock_out_at == null && nowLocal < retryDeadline
  recentLogs: RecentAttendanceRow[];
};
```

### 2.2 Clock-In Kantor (`POST /attendance/office/clock-in`)
**Content-Type:** `multipart/form-data`

**Request Fields:**
- `idempotency_key` (`required|uuid`): UUID stabil per percobaan submit (dipertahankan saat retry jaringan).
- `lat` (`required|numeric|-90..90`)
- `lng` (`required|numeric|-180..180`)
- `accuracy_m` (`required|numeric|min:0`)
- `gps_session_token` (`required|string`)
- `camera_token` (`required|string`)
- `photo` (`required|file|max:2048`, gambar valid hasil kompresi `< 300 KB`)
- `notes` (`nullable|string|max:500`; **opsional** saat Clock-In berada di dalam radius kantor `d <= R` — termasuk saat akurasi rendah/tidak pasti atau hari libur, kecuali bila `is_late_permission = true` tetap wajib min. 10 karakter; **wajib min. 10 karakter** saat Clock-In di luar radius `R < d <= max(200, R)` atau `DINAS_LUAR` di luar radius dengan `a > 100m`)
- `is_late_permission` (`nullable|boolean`; hanya valid jika `late_minutes > 0`, hari kerja efektif, dan tanpa `DINAS_LUAR`)

**Matriks Validasi Lokasi & Status:**
| Kondisi | Hasil Status | Anomaly Flag | Catatan |
| :--- | :--- | :--- | :--- |
| `d > max(200, R)` tanpa `DINAS_LUAR` | `422` (Hard Block) | — | Ditolak sebelum konsumsi token |
| `DINAS_LUAR` (`PENDING`/`APPROVED`) | `PENDING_REVIEW` | `OFFSITE_DUTY` (+ `LOW_ACCURACY` bila `a > 100m`, + `OFF_DAY_ATTENDANCE` bila hari non-efektif) | Radius kantor dibebaskan; notes opsional jika `d <= R`, wajib `>= 10` char bila `d > R && a > 100m` |
| `R < d <= max(200, R)` | `PENDING_REVIEW` | `OUT_OF_RADIUS` | `notes >= 10` karakter |
| `d <= R && a > R` | `PENDING_REVIEW` | `UNCERTAIN_ACCURACY` | `notes` opsional (flag & review tetap berlaku) |
| `d <= R && a <= R && a > 100` | `PENDING_REVIEW` | `LOW_ACCURACY` | `notes` opsional (flag & review tetap berlaku) |
| `!isEffectiveWorkDay` tanpa `DINAS_LUAR` | `PENDING_REVIEW` | `OFF_DAY_ATTENDANCE` | Jendela `00:00–23:59`, `late_minutes = 0`, `notes` opsional jika `d <= R` |
| `d <= R && a <= R && a <= 100` tanpa anomali | `AUTO_APPROVED` | `null` | `notes` opsional; `REPEATED_COORDS` & `CONSTANT_ACCURACY` dikecualikan khusus kondisi compliant ini |
**Aturan Anti-Fraud (FR-3 & FR-4) pada Clock-In / Clock-Out:**
- `IMPOSSIBLE_TRAVEL`: mengabaikan titik sebelumnya **maupun titik saat ini** apabila `accuracy_m > 500m` (menghindari false positive BTS/basement; tetap ditangani oleh `LOW_ACCURACY`).
- `SHARED_DEVICE`: evaluasi & penandaan retroaktif akun ke-1 (`AUTO_APPROVED → PENDING_REVIEW`) wajib berjalan **di dalam `DB::transaction()` final** setelah validasi & penyimpanan foto lolos, agar request yang gagal (HTTP 422 / rollback) tidak pernah mengubah status kehadiran akun lain.

**Response:**
- `201 Created`: `{ attendance: AttendanceLog }`
- `200 OK` (idempotent replay): `{ attendance: AttendanceLog, idempotent_replay: true }`
- `409 Conflict`: `{ message: "Idempotency key sudah digunakan oleh pengguna lain." }`
- `422 Unprocessable Entity`: validasi gagal (cuti APPROVED, sudah Clock-In aktif, di luar jendela shift, hard-block lokasi, notes `< 10` karakter, token invalid/kedaluwarsa, file foto bukan gambar valid).

### 2.3 Clock-Out Kantor (`POST /attendance/office/clock-out`)
**Content-Type:** `multipart/form-data`

**Request Fields:** Sama seperti Clock-In tanpa `is_late_permission`.

**Aturan Bisnis:**
- Validasi jarak memakai snapshot `attendance_logs.office_lat`, `office_lng`, `office_radius_m` saat Clock-In.
- Tidak pernah hard-block jarak jauh (bila `d > R` tanpa `DINAS_LUAR` → `PENDING_REVIEW` + `OUT_OF_RADIUS` + wajib `notes >= 10` karakter).
- Pulang sebelum `effective_end_time` pada hari kerja efektif → `EARLY_LEAVE` + `early_leave_minutes` + wajib `notes >= 10` karakter.
- Batas tutup Clock-Out: `MIN(effective_end_time + auto_close_hours, 23:59)` pada hari kerja efektif, atau `23:59:59` pada hari non-efektif (`!isEffectiveWorkDay`).
- Menolak (`422`) jika tidak ada sesi aktif, sudah ditutup `MISSED_CLOCK_OUT`, atau melewati batas tutup.

**Response:**
- `200 OK`: `{ attendance: AttendanceLog }` (atau `{ attendance, idempotent_replay: true }`)
- `409 Conflict` / `422 Unprocessable Entity`.

---

## 3. Tracking Kunjungan Sales Lapangan (`EMPLOYEE_SALES` Saja)

Akses ditolak (`403 Forbidden`) untuk `EMPLOYEE_OFFICE` dan `HR_ADMIN`.

### 3.1 Halaman Kunjungan Sales (`GET /attendance/sales`)
**Inertia Component:** `sales/Kunjungan`

**Props Response:**
```ts
type SalesVisitPageProps = {
  homeTimezone: 'Asia/Jakarta' | 'Asia/Makassar';
  todayDate: string; // YYYY-MM-DD (homebase timezone)
  serverTimeIso: string; // ISO-8601 UTC
  isDayOff: boolean; // !isEffectiveWorkDay(user, todayDate)
  hasApprovedTimeOff: boolean;
  rejectedOutlets: { id: number; name: string; address: string; reject_reason: string | null }[];
  activeVisit: {
    id: number;
    outlet_name: string;
    outlet_code: string | null;
    outlet_address: string;
    outlet_radius_m: number; // snapshot sales_visits.outlet_radius_m saat Visit In (fallback outletLocation.radius_m)
    outlet_timezone: 'Asia/Jakarta' | 'Asia/Makassar';
    visit_in_at: string | null;
    visit_in_time: string | null; // HH:mm (outlet timezone)
    verification_status: 'AUTO_APPROVED' | 'PENDING_REVIEW' | 'APPROVED' | 'REJECTED';
    reject_reason: string | null;
    can_cancel: boolean;
    cancel_remaining_today: number; // 0..3
  } | null;
  recentOutlets: OutletItem[]; // max 5 toko terakhir dikunjungi, urut paling baru (hanya ACTIVE atau PENDING_VERIFICATION milik user)
  todayVisits: TodayVisitSummary[]; // tanpa koordinat/IP/device/photo_url mentah
  categories: { id: number; name: string; exempt_short_duration: boolean }[];
};

type OutletItem = {
  id: number;
  code: string | null;
  name: string;
  address: string;
  radius_m: number;
  status: 'ACTIVE' | 'PENDING_VERIFICATION' | 'INACTIVE';
  distance_m: number | null;
  // DILARANG menyertakan latitude / longitude
};
```

### 3.2 Pencarian Toko Terdekat (`GET /attendance/sales/outlets`)
- **Query:** `?q=<string>&lat=<float>&lng=<float>`
- **Response `200 OK`:** `{ outlets: OutletItem[] }` (maksimal 30 hasil terdekat; hanya `ACTIVE` + `PENDING_VERIFICATION` milik `created_by = auth.id`; **tanpa** `latitude`/`longitude`).

### 3.3 Pengajuan Toko Baru / NOO (`POST /attendance/sales/noo`)
- **Request JSON:** `{ name: string, address: string, lat: number, lng: number, accuracy_m: number, timezone?: 'Asia/Jakarta'|'Asia/Makassar', confirm_duplicate?: boolean }`
- **Validasi:**
  - `accuracy_m <= 50` (HTTP `422` bila `> 50`).
  - Kuota maksimal 5 NOO/hari/sales menurut hari lokal homebase (HTTP `422` bila `>= 5`).
  - Cek toko eksisting dalam radius `100m` lintas `ACTIVE` + semua `PENDING_VERIFICATION`. Jika ada dan `confirm_duplicate != true` → HTTP `409 Conflict`: `{ duplicate_warning: true, nearby_outlets: OutletItem[] }`.
- **Response `201 Created`:** `{ outlet: OutletItem }`.

### 3.4 Visit In (`POST /attendance/sales/visit-in`)
**Content-Type:** `multipart/form-data`
- **Request Fields:** `idempotency_key (uuid)`, `outlet_location_id`, `lat`, `lng`, `accuracy_m`, `gps_session_token`, `camera_token`, `photo (file max 2048KB)`, `notes (nullable|max:500)`
- **Aturan Bisnis:**
  - Blokir `422` bila cuti/izin/sakit `APPROVED`, bila outlet `INACTIVE` atau `PENDING_VERIFICATION` milik sales lain, atau bila `d > max(200, R)`.
  - Wajib `notes >= 10` karakter bila `OUT_OF_RADIUS`, `UNCERTAIN_ACCURACY`, `LOW_ACCURACY`, `UNVERIFIED_OUTLET` (NOO), atau `!isEffectiveWorkDay` (`OFF_DAY_ATTENDANCE`).
  - Di dalam `DB::transaction` dengan `lockForUpdate()` pada baris `users`: jalankan `autoCloseStaleVisits` (menutup kunjungan kemarin menjadi `AUTO_CLOSED` + `MISSED_VISIT_OUT`), cek belum ada `IN_PROGRESS` lain (`422` jika sudah ada), simpan snapshot `outlet_lat`/`outlet_lng`/`outlet_radius_m`, lalu konsumsi token GPS & kamera.
- **Response:** `201 Created` `{ visit: SalesVisit }` | `200 OK` `{ visit, idempotent_replay: true }` | `409` | `422`.

### 3.5 Batal Visit In (`POST /attendance/sales/{visit}/cancel`)
- **Request JSON:** `{ cancel_reason: string (min:10, max:255) }`
- **Validasi:** Milik `auth.id` (`403`), masih `IN_PROGRESS` & `visit_out_at IS NULL`, umur `<= 300 detik`, total batal hari ini `< 3`.
- **Response `200 OK`:** `{ visit: SalesVisit }`.

### 3.6 Visit Out (`POST /attendance/sales/{visit}/visit-out`) — Tanpa Foto & Tanpa `camera_token`
- **Request JSON:** `{ idempotency_key: uuid, activity_category_id: int (harus aktif), visit_notes: string (min:3; min:10 bila luar radius atau akurasi buruk), lat: number, lng: number, accuracy_m: number, gps_session_token: string }`
- **Aturan Bisnis:**
  - Cek `idempotency_key` sebelum validasi token.
  - Jika `visit_date < todayBranch`, auto-close `MISSED_VISIT_OUT` dan tolak `422`.
  - Validasi jarak memakai snapshot `outlet_lat`/`outlet_lng`/`outlet_radius_m`; tidak pernah hard-block jarak jauh (di luar radius → `OUT_OF_RADIUS_VISIT_OUT`).
  - Durasi `< 3 menit` dan `category.exempt_short_duration === false` → `SHORT_DURATION`.
  - Tetap diizinkan meski toko NOO baru ditolak HR menjadi `INACTIVE` (menandai `UNVERIFIED_OUTLET` + `PENDING_REVIEW`).
  - Konsumsi `gps_session_token` di tahap akhir transaksi.
- **Response:** `200 OK` `{ visit: SalesVisit }` | `409` | `422` | `403`.

---

## 4. Riwayat Pribadi & Ringkasan Rekap Laporan Admin

### 4.1 Riwayat Pribadi (`GET /attendance/history`)
- **Query:** `?range=30d|month&month=YYYY-MM` (default `range=month` untuk bulan berjalan)
- **Inertia Component:** `office/Riwayat`, `sales/Riwayat`, atau `admin/RiwayatSaya` (khusus data `user_id = auth.id`; foto melalui Signed URL, `null` ditampilkan sebagai `"Berkas melewati retensi 12 bulan"`).

### 4.2 Dashboard Monitoring HR, Verifikasi, Input Manual, Ekspor 3 Format & Audit Logs (`HR_ADMIN`)

**Inertia Props Tambahan `GET /admin/dashboard?date=YYYY-MM-DD`:**
- `pendingQueue`: item per fase terpisah (`clock_in` & `clock_out` terpisah bila keduanya `PENDING_REVIEW`, serta `visit`), koordinat peta memakai **snapshot** (`office_lat/lng/radius_m` atau `outlet_lat/lng/radius_m`), dilengkapi field opsional:
  - `visit_status?: 'IN_PROGRESS' | 'COMPLETED' | 'AUTO_CLOSED' | 'CANCELLED'` (`review_locked = true` bila `IN_PROGRESS` atau `DINAS_LUAR` masih `PENDING`)
  - `default_out_time?: string | null` (`HH:mm` untuk `MISSED_CLOCK_OUT`)
  - `dinas_info?: { destination_location: string; scheduled_start_time: string | null; status: string } | null`
  - `is_collective_leave_date?: boolean`
  - `shared_device_peers?: { nik: string; name: string; time: string | null }[]`
- `salesVisits`: menyertakan `user_id` agar tombol Koreksi `REJECTED → APPROVED` menerapkan non-self (`user_id != auth.id`).
- `recentManualEntries`: daftar `AuditLog` (`action = MANUAL_ATTENDANCE`, `actor_id != auth.id`, 7 hari terakhir, max 20) untuk panel `"Input Manual HR (7 Hari Terakhir)"`: `{ id, actor_nik, actor_name, entity, record_id, target_name, date, reason, created_at }[]`.
- `outlets`: daftar outlet `ACTIVE` (`id, code, name`) dan `categories`: kategori aktivitas aktif (`id, name`) untuk form Input Manual Kunjungan Sales.
- Transisi harian otomatis: pada `selectedDate === todayLocal`, bila `nowLocal >= effectiveEndTime` dan belum ada kehadiran aktif, status berubah dari `'Belum Absen'` → `'Alpa'` (atau `'Alpa (Ada Pengajuan Pending)'` / `'Alpa (Ditolak HR)'`).

| Method & Path | Role | Request Payload / Query | Response & Aturan Bisnis |
| :--- | :--- | :--- | :--- |
| `GET /admin/dashboard` | `HR_ADMIN` | `?date=YYYY-MM-DD` | Inertia `admin/Dashboard` (KPI, rekap harian, antrean review per fase, rincian sales, `recentManualEntries`). |
| `POST /admin/attendance/{attendanceLog}/review` | `HR_ADMIN` (non-self `403`) | `{ phase: 'clock_in'\|'clock_out', decision: 'APPROVED'\|'REJECTED', reject_reason?, clock_out_at? }` | `200 OK`; `422` jika `DINAS_LUAR` masih `PENDING`, reject tanpa alasan, atau `clock_out_at <= clock_in_at` pada `MISSED_CLOCK_OUT`. |
| `POST /admin/attendance/{attendanceLog}/correct` | `HR_ADMIN` (non-self `403`) | `{ phase: 'clock_in'\|'clock_out', reason (min:3), clock_in_time?: HH:mm, clock_out_time?: HH:mm }` | `200 OK` `{ attendance }`; `409` jika ada attempt baru/baris aktif lain/cuti `APPROVED`; `422` jika `clock_out_at <= clock_in_at`; hitung ulang `late_minutes`/`early_leave_minutes`, sinkronkan `collective_leave_deduction`, catat `CORRECT_STATUS`. |
| `POST /admin/visits/{salesVisit}/review` | `HR_ADMIN` (non-self `403`) | `{ decision: 'APPROVED'\|'REJECTED', reject_reason?, visit_out_at? }` | `200 OK` `{ visit }`; `422` jika `visit_status = IN_PROGRESS` atau reject tanpa alasan. |
| `POST /admin/visits/{salesVisit}/correct` | `HR_ADMIN` (non-self `403`) | `{ reason (min:3) }` | `200 OK` `{ visit }`; ubah `REJECTED → APPROVED`, `409` jika bentrok cuti/izin/sakit `APPROVED`, auto-cancel pending leave hari sama, sinkronkan `collective_leave_deduction`, catat `CORRECT_STATUS`. |
| `POST /admin/reviews/bulk-approve` | `HR_ADMIN` | `{ items: { kind: 'attendance'\|'visit', id: int, phase?: 'clock_in'\|'clock_out' }[] }` | `200 OK` `{ approved_count }`; lewati item `self`, `DINAS_LUAR PENDING`, `IN_PROGRESS`, & seluruh flag terlarang (`BULK_EXCLUDED_FLAGS`). |
| `POST /admin/attendance/manual` | `HR_ADMIN` (non-self `403`) | Office/HR: `{ user_id, work_date, shift_id?, clock_in_time, clock_out_time?, reason (min:10) }`<br>Sales: `{ user_id, work_date, outlet_location_id, visit_in_time (atau clock_in_time), visit_out_time? (atau clock_out_time?), activity_category_id?, reason (min:10) }` | `201 Created` `{ attendance }` atau `{ visit }`; batas 7 hari terakhir, tolak bentrok cuti `APPROVED`, auto-cancel pending leave, sinkronkan `collective_leave_deduction`, catat `MANUAL_ATTENDANCE`. |
| `GET /admin/exports/talenta-office` | `HR_ADMIN` | `?start_date=&end_date=&format=xlsx\|csv&confirm_pending=0\|1` | File 1 Office Talenta 26 kolom (Sheet `Sheet1`, max 93 hari, `409` bila ada pending tanpa `confirm_pending=1`). |
| `GET /admin/exports/sales-visits` | `HR_ADMIN` | `?start_date=&end_date=&format=xlsx\|csv&include_unverified=0\|1&confirm_pending=0\|1` | File 2 Sales 18 kolom (Sheet `Worksheet`, max 93 hari, `409` bila ada pending tanpa `confirm_pending=1`). |
| `GET /admin/exports/audit` | `HR_ADMIN` | `?start_date=&end_date=&format=xlsx\|csv` | File 3 Audit Internal HR (grid karyawan aktif termasuk `CANCELLED`). |
| `GET /admin/audit-logs` | `HR_ADMIN` atau `can_view_audit_logs = true` | `?entity=&action=&date_from=&date_to=` | Inertia `admin/AuditLogs`: `logs`, `filters`, `availableEntities`, `availableActions` (read-only + verifikasi `row_hash` & rantai `prev_hash`). |

---

## 5. Modul ESS (Cuti/Izin/Sakit/Dinas Luar) & Master Data HR (Ganti Hari & Saldo Cuti)

### 5.1 ESS Karyawan (`/leave` & `/admin/leaves`)
| Method & Path | Role | Request / Query | Response & Aturan Utama |
| :--- | :--- | :--- | :--- |
| `GET /leave` | Semua role aktif | — | Inertia `office/Pengajuan`, `sales/Pengajuan`, atau `admin/PengajuanSayaMobile`: saldo 4-grid (`base_quota`, `prorated_deduction`, `collective_leave_deduction`, `used_days`, `pending_days`, `adjustment_days`), `calendar` (horizon 90 hari), `cutiDaysByMonth`, riwayat pengajuan pribadi. |
| `POST /leave` | Semua role aktif | `multipart/form-data`: `type` (`CUTI`/`IZIN`/`SAKIT`/`DINAS_LUAR`), `start_date`, `end_date`, `reason`, `attachment` (wajib `SAKIT` JPG/PNG/PDF `<= 2MB`), `destination_location` & `scheduled_start_time` (wajib `DINAS_LUAR`) | `201 Created`; `403` jika `EMPLOYEE_SALES` kirim `DINAS_LUAR`; `422` jika `CUTI` `< 1 tahun` atau `< H-3` hari kerja efektif, `IZIN` `> 2 hari/bulan`, `SAKIT`/`IZIN` backdate `> H-2` hari kerja efektif dari `end_date`, tumpang tindih tanggal/kehadiran, atau `total_days == 0` untuk non-`DINAS_LUAR`. |
| `POST /leave/{leaveRequest}/cancel` | Pemilik (`PENDING`) | — | `200 OK`, ubah ke `CANCELLED` dan lepas `pending_days`. |
| `POST /admin/leaves/{leaveRequest}/approve` | `HR_ADMIN` (non-self) | `{ confirm_override?: boolean }` | `200 OK`; `409` jika `CUTI > 2 hari/bulan` tanpa `confirm_override = true` (dialog `Lanjutkan / Batal`). |
| `POST /admin/leaves/{leaveRequest}/reject` | `HR_ADMIN` (non-self) | `{ reject_reason: string }` | `200 OK`; jika `DINAS_LUAR` `PENDING` punya `attendance_logs` tertaut, otomatis reject log tersebut & kirim email penolakan. |
| `POST /admin/leaves/{leaveRequest}/partial-cancel` | `HR_ADMIN` (non-self) | `{ dates: string[], cancel_reason: string }` | `200 OK`; hanya `leave_date >= todayLocal` dan bukan tanggal `DINAS_LUAR` yang sudah punya `attendance_logs` aktif. |

### 5.2 Master Data HR — Ganti Hari & Impor Saldo Cuti (`/admin/master-data`)

**Inertia Props Tambahan `GET /admin/master-data`:**
```ts
type EligibleGantiHariOrigin = {
  user_id: number;
  source_date: string; // YYYY-MM-DD (hari libur NATIONAL/LOCAL/OFF, bukan COLLECTIVE_LEAVE)
  source_type: 'ATTENDANCE_LOG' | 'SALES_VISIT';
  source_id: number;
  deadline_date: string; // YYYY-MM-25 batas hangus mutlak
};

type UpcomingGantiHari = {
  id: number;
  user_id: number;
  work_date: string; // YYYY-MM-DD (>= todayLocal)
  compensation_source_date: string; // YYYY-MM-DD
  notes: string | null;
};
```

| Method & Path | Middleware | Request JSON | Response & Aturan Bisnis |
| :--- | :--- | :--- | :--- |
| `POST /admin/users/{user}/schedule-override` | `role:HR_ADMIN` (non-self `403`) | `{ work_date: YYYY-MM-DD, is_day_off: bool, shift_id?: int\|null, replacement_for_date?: YYYY-MM-DD\|null, notes?: string\|null }` | `200 OK` `{ schedule }`. Untuk Ganti Hari (`replacement_for_date != null`): (1) hanya `EMPLOYEE_OFFICE` & `EMPLOYEE_SALES`, `is_day_off = true`, `notes` wajib; (2) tolak `422` jika `work_date < todayLocal` atau hari ini sudah ada `AttendanceLog`/`SalesVisit` aktif; (3) tolak `422` jika `source_date` adalah `COLLECTIVE_LEAVE`; (4) batas mutlak tanggal 25: `DAY(source_date) <= 24` → `<= YYYY-MM-25` bulan sama, `>= 25` → `<= YYYY-(MM+1)-25` bulan berikutnya (lintas tahun ke 25 Jan), tolak `422` bila `work_date > batas_25` atau `todayLocal > batas_25`; (5) bukti sah: `ATTENDANCE_LOG` wajib `clock_in_status = APPROVED` AND `clock_out_status = APPROVED` AND `clock_out_at IS NOT NULL`; `SALES_VISIT` wajib `verification_status = APPROVED` AND `visit_status IN ('COMPLETED', 'AUTO_CLOSED')`; (6) `UNIQUE(user_id, compensation_source_date)` cegah penukaran ganda; (7) panggil `recalculateLeaveRequestDays()` & catat `audit_logs`. |
| `POST /admin/users/{user}/schedule-override/cancel` | `role:HR_ADMIN` (non-self `403`) | `{ work_date: YYYY-MM-DD, reason?: string }` | `200 OK` `{ cancelled: true }`. Syarat `work_date >= todayLocal`, belum ada `AttendanceLog`/`SalesVisit` aktif di tanggal tsb, dan baris override ada (`422`). Kembalikan ke jadwal `PATTERN` (atau hapus baris override untuk sales) sehingga `compensation_source_*` terlepas untuk reschedule dalam batas tanggal 25, panggil `recalculateLeaveRequestDays()`, catat `audit_logs`. |
| `POST /admin/users/leave-balances/import` | `role:HR_ADMIN` | `{ year?: int, rows: { nik: string, year?: int, adjustment_days: number (kelipatan 0.5, -30..30), notes?: string }[] }` | `200 OK` `{ updated_count: int }`. Header CSV resmi: `nik,year,adjustment_days,notes` (kompatibel juga jika `year` di top-level). Transaksi atomik all-or-nothing, sanitasi formula injection (`=,+,-,@,\t,\r`) pada `nik` & `notes`, lewati akun pengimpor sendiri, tolak `422` bila karyawan `< 1 tahun` diberi `adjustment_days > 0`, jangan timpa `used_days`/`pending_days`/`base_quota`/`prorated_deduction`, simpan `notes` ke `audit_logs.reason`. |
