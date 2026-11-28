# Roadmap & Milestones — Web Attendance LSI (Hybrid Live Attendance v8)

Dokumen ini disusun berdasarkan audit langsung terhadap `docs/PRD.md` (v8), `docs/design.md`, dan struktur codebase aktual per 2026-11-28 (Laravel 13 + Inertia v3 + Svelte 5 + Tailwind v4 + MySQL/SQLite + Redis + Docker).

## Legenda Status Codebase Saat Ini

- `[DONE]` — Sudah terimplementasi di codebase dan memiliki cakupan dasar.
- `[PARTIAL]` — Fondasi sudah ada, namun masih terdapat celah/bug terhadap PRD v8 atau `docs/design.md`.
- `[TODO]` — Belum ada di codebase; perlu dibuat baru.

## Peta Dependensi & Strategi Kerja Paralel

```text
[Kontrak API per Milestone: docs/api-contract.md + docs/tasks.md]
          │
          ▼
Milestone 1: Fondasi, Skema DB v8, Auth & Keamanan ──┐
          │                                          │
          ├──▶ Milestone 2: Absensi Kantor, GPS,     │  (M2 & M3 bergantung pada M1;
          │                 Kamera & Anti-Fraud      │   BE & FE paralel per milestone
          │                                          │   setelah kontrak API dikunci)
          ├──▶ Milestone 3: Field Sales Visit & NOO  │
          │                                          │
          ▼                                          │
Milestone 4: ESS (Cuti/Izin/Sakit/Dinas) &           │  (bergantung pada kehadiran M2/M3
             Master Data HR + Ganti Hari             │   untuk anti-bentrok & bukti libur)
          │                                          │
          ▼                                          │
Milestone 5: Dashboard HR, Verifikasi Side-by-Side,  │  (agregasi M2, M3, M4 + ekspor
             Input Manual, Ekspor 3 Format & Audit   │   Talenta 26/18 kolom & Audit)
          │                                          │
          ▼                                          │
Milestone 6: QA Menyeluruh (SQLite + MySQL),         │
             Polish UI Mobile/Desktop & Ready Deploy ┘
```

**Prinsip Paralelisasi `[BACKEND]` vs `[FRONTEND]`:**
1. Sebelum subagent dijalankan pada tiap milestone, PM mengunci kontrak request/response, payload Inertia props, dan kode status error di `docs/api-contract.md` serta memecah task di `docs/tasks.md`.
2. Dengan kontrak terkunci, `backend-engineer` dan `frontend-engineer` dapat mengerjakan endpoint/validasi dan halaman/komponen Svelte secara paralel, lalu disinkronkan di akhir milestone sebelum lanjut ke tahap berikutnya.

---

## Ringkasan Status per Milestone

| Milestone | Fokus Utama | Status Codebase | Sisa Pekerjaan Kritis |
| :--- | :--- | :---: | :--- |
| **M1** | Fondasi, Skema DB v8, Auth, UU PDP & Infrastruktur | `[PARTIAL]` (~85%) | Trigger MySQL append-only `audit_logs`, `enforceMorphMap`, audit `SESSION_LIFETIME` 12 jam, nonaktifkan rute auth starter-kit tak terpakai, audit shell/header `design.md`. |
| **M2** | Absensi Kantor (WFO/Dinas), GPS, Kamera WebRTC & Anti-Fraud | `[PARTIAL]` (~80%) | Fix bug `CloseMissedAttendanceCommand` (re-close baris `REJECTED`/`PENDING_REVIEW`, deadline `DINAS_LUAR` & `!isEffectiveWorkDay`), audit urutan cek `idempotency_key` sebelum konsumsi token, audit pengecualian FR-3 kantor. |
| **M3** | Field Sales Visit Tracking & Pengajuan Toko Baru (NOO) | `[PARTIAL]` (~85%) | Audit guard NOO (`accuracy <= 50m`, soft warning 100m, kuota 5/hari), konsistensi snapshot outlet & Visit Out saat NOO ditolak, audit UI mobile `sales/Kunjungan.svelte`. |
| **M4** | ESS (Cuti/Izin/Sakit/Dinas) & Master Data HR (Ganti Hari & Saldo) | `[PARTIAL]` (~75%) | **Gap besar:** aturan batas tanggal 25 Ganti Hari, tolak `COLLECTIVE_LEAVE` untuk Ganti Hari, syarat bukti `APPROVED` 2 fase, endpoint Batal/Reschedule Ganti Hari, header CSV `nik,year,adjustment_days,notes` & blokir saldo positif `< 1 tahun`. |
| **M5** | Dashboard HR, Verifikasi Side-by-Side, Input Manual, Ekspor 3 Format & Audit | `[PARTIAL]` (~75%) | Panel `Input Manual HR (7 Hari Terakhir)` antar-HR, input manual `sales_visits`, koreksi status + edit jam masuk/pulang & koreksi `sales_visits`, pemisahan antrean fase masuk/pulang, metadata `SHARED_DEVICE`/`OFFSITE_DUTY`/`COLLECTIVE_LEAVE`, modal konfirmasi export. |
| **M6** | QA Menyeluruh (SQLite & MySQL), Audit UI/UX & Kesiapan Deploy | `[PARTIAL]` (~60%) | Lengkapi Pest test untuk seluruh gap M1–M5, verifikasi full suite di MySQL (`phpunit.mysql.xml`), `phpstan`, `pint`, `svelte-check`, `eslint`, uji browser mock GPS/kamera, uji 5 command catch-up & Docker Compose. |

---

## Milestone 1: Fondasi, Skema DB v8, Auth, UU PDP & Keamanan Infrastruktur

### Tujuan & Scope Utama
Memastikan seluruh fondasi 14 tabel ERD PRD v8, keamanan autentikasi (single session, throttle berversi, force password change, persetujuan UU PDP, cookie `device_id`), sistem token sekali pakai, penyimpanan bukti privat (Signed URL), tata kelola `audit_logs` append-only, dan shell navigasi berbasis role sesuai `docs/design.md` 100% solid sebelum pengerjaan fitur transaksi.

### Status Eksisting di Codebase
- `[DONE]` 14 tabel utama + `notifications` (termasuk migrasi `2026_11_27_000001_align_schema_with_prd_v8.php`), seluruh Eloquent model & Enum, `DatabaseSeeder` (5 cabang LSI, shift, pola, kategori, mapping Talenta).
- `[DONE]` Fortify login NIK/Email, `LoginThrottleService` (Redis/Cache `user_lock_version` + batas IP 30x/15m), middleware `EnsureSingleSession`, `EnsureUserIsActive`, `EnsurePasswordChanged`, `EnsureConsentAccepted`, `EnsureRole`, `EnsureDeviceCookie`.
- `[DONE]` `ConsentController`, `TokenController` (`/api/tokens/gps`, `/api/tokens/camera`), `EvidenceController` (Signed URL), `AuditLogger` (hash chain SHA-256 + blacklist), `AuditLog` model guard, Docker Compose + `docker/mysql/init.sql` (`REVOKE UPDATE, DELETE` pada `audit_logs`).
- `[DONE]` Token warna Corporate Brown (`resources/css/app.css`), `auth/Login.svelte`, `auth/Consent.svelte`, `EmployeeLayout.svelte`, `AppSidebar.svelte`.
- `[PARTIAL]` Trigger MySQL `BEFORE UPDATE/DELETE` `audit_logs`, `enforceMorphMap`, default `SESSION_LIFETIME`, pembersihan rute auth starter-kit (registrasi publik), dan kepatuhan detail header/nav `design.md`.

### Tugas `[BACKEND]`
- `[TODO]` Tambah migrasi khusus driver MySQL untuk trigger `BEFORE UPDATE` dan `BEFORE DELETE` pada `audit_logs` yang melempar `SIGNAL SQLSTATE '45000'` (PRD Lampiran 1 lapis ke-3; dilewati aman pada SQLite karena sudah dijaga model `AuditLog`).
- `[TODO]` Daftarkan `Relation::enforceMorphMap` di `AppServiceProvider` untuk `'ATTENDANCE_LOG' => AttendanceLog::class` dan `'SALES_VISIT' => SalesVisit::class` sesuai US-001.
- `[PARTIAL]` Verifikasi `config/session.php` (`SESSION_LIFETIME` default `720` menit / 12 jam), verifikasi `config/fortify.php` agar fitur public registration / self-reset-password yang melanggar US-002 dinonaktifkan, dan pastikan urutan middleware paksa: `must_change_password` → `consent_accepted_at` → redirect role.
- `[PARTIAL]` Audit `EvidenceController` & `ImageEvidenceService`: pastikan Signed URL memakai TTL 5–15 menit dan menolak akses selain `HR_ADMIN` atau pemilik berkas (`user_id = auth.id`).
- `[PARTIAL]` Audit `AuditLogger` pada MySQL advisory lock (`GET_LOCK('audit_logs_chain', 5)`) dan blacklist key sensitif (`password`, `password_hash`, `remember_token`, token sesi/CSRF).

### Tugas `[FRONTEND]`
- `[PARTIAL]` Audit `auth/Login.svelte`, `auth/Consent.svelte`, dan `settings/Security.svelte`: pastikan alur Force Change Password → Consent UU PDP (tombol `Setuju` `min-h-12` & `Tolak` → pesan + logout) berjalan mulus tanpa redirect loop.
- `[PARTIAL]` Audit `EmployeeLayout.svelte`: pastikan sticky header `h-12` menampilkan nama cabang, badge zona waktu (`WIB`/`WITA`), jam server real-time `font-mono tabular-nums`, indikator notifikasi in-app (lonceng + `notifications/read-all`), tombol kembali ke `/admin/dashboard` khusus `HR_ADMIN`, dan bottom nav 4 tab sesuai role (`EMPLOYEE_OFFICE` vs `EMPLOYEE_SALES` tanpa akses saling silang).
- `[PARTIAL]` Audit `AppSidebar.svelte` & layout admin: pastikan grup menu (*Monitoring*, *Master Data*, *Pribadi & Audit*) dan slot alert banner atas sinkron dengan `docs/design.md`.
- `[TODO]` Pastikan tampilan Light Mode terkunci konsisten (hilangkan opsi toggle dark mode yang tidak relevan di `settings/Appearance.svelte` / profil bila masih aktif).

### Definition of Done (DoD)
- Login memakai NIK maupun Email berhasil, sesi lama otomatis gugur saat login di browser lain, akun `is_active = false` langsung ter-logout di request berikutnya.
- Gagal login 5x memblokir `(user + IP)` selama 15 menit tanpa memblokir IP lain; gagal 30x/IP memblokir IP tersebut.
- Akun baru dengan `must_change_password = true` dan `consent_accepted_at = NULL` dipaksa ganti password dulu lalu layar Consent UU PDP sebelum masuk dashboard role.
- Akses `/attendance/sales` oleh `EMPLOYEE_OFFICE`/`HR_ADMIN` dan `/attendance/office` oleh `EMPLOYEE_SALES` mengembalikan HTTP 403.
- Upaya `UPDATE`/`DELETE` pada `audit_logs` ditolak (Eloquent exception di SQLite & trigger + privilege revoke di MySQL).
- `LsiAuthTest`, `DatabaseSchemaTest`, `SessionAndAuditTest`, `TokenAndEvidenceTest`, dan `AuditGovernanceTest` lulus 100%.

---

## Milestone 2: Absensi Kantor (WFO & Dinas Luar), GPS, WebRTC Camera, Anti-Fraud & Scheduler

### Tujuan & Scope Utama
Menuntaskan seluruh alur presensi staf kantor & HR (`US-003`, `US-004`, sebagian `US-010`, `FR-1` s.d. `FR-6`): validasi geofencing Haversine + akurasi, token sesi GPS & kamera sekali pakai, watermark server, 4 lapis anti-fake GPS + `SHARED_DEVICE` retroaktif, izin setengah hari (`EARLY_LEAVE` & `LATE_PERMISSION`), absen di bawah `DINAS_LUAR`, absen ulang pasca-`REJECTED`, serta keandalan scheduler `attendance:close-missed` dan `attendance:prune-retention`.

### Status Eksisting di Codebase
- `[DONE]` `OfficeAttendanceController` (`index`, `adminIndex`, `clockIn`, `clockOut`), `GeoLocationService`, `ScheduleResolverService`, `TokenGuardService`, `ImageEvidenceService`, `AntiFraudService`, `HistoryController`.
- `[DONE]` Command `CloseMissedAttendanceCommand`, `PruneOrphanFilesCommand`, `PruneRetentionCommand` terdaftar di `routes/console.php`.
- `[DONE]` Halaman `office/Absen.svelte`, `office/Riwayat.svelte`, `admin/AbsenSaya.svelte`, `admin/RiwayatSaya.svelte`, komponen `LiveCameraCapture.svelte`, utilitas `gpsClient.ts`, `imageCompressor.ts`, `deviceCheck.ts`.
- `[PARTIAL]` Ditemukan bug nyata pada `CloseMissedAttendanceCommand`, serta perlu audit ketat urutan idempotency/token dan pengecualian anti-fraud kantor.

### Tugas `[BACKEND]`
- `[PARTIAL]` **Fix bug kritis `CloseMissedAttendanceCommand`:**
  - Tambahkan filter `->whereNull('clock_out_status')` pada query `openLogs` agar baris yang sudah `PENDING_REVIEW` (`MISSED_CLOCK_OUT`) atau `REJECTED` (`clock_out_at = NULL`) tidak ditimpa ulang setiap 15 menit.
  - Evaluasi `!isEffectiveWorkDay($user, $workDate)` agar hari non-efektif (termasuk `COLLECTIVE_LEAVE` yang memiliki snapshot `schedule_end_time`) selalu memakai batas tutup `23:59`, bukan `schedule_end_time + auto_close_hours`.
  - Hitung `effectiveEndTime` untuk baris dengan `leave_request_id` (`DINAS_LUAR`) memakai `MAX(schedule_end_time, scheduled_start_time + durasi_shift)` sebelum menambah `auto_close_hours` (tetap dikunci `<= 23:59`).
- `[PARTIAL]` Audit `OfficeAttendanceController::clockIn` & `clockOut`:
  - Pastikan pemeriksaan `idempotency_key` (HTTP 200 untuk replay user sama, HTTP 409 untuk user berbeda) dieksekusi **sebelum** validasi/konsumsi `gps_session_token` & `camera_token` (FR-5), dan konsumsi token dilakukan di tahap akhir transaksi DB (tidak hangus saat HTTP 422).
  - Pastikan blok `catch` menghapus file foto yatim (orphan file) bila transaksi DB rollback.
  - Pastikan Clock-Out menggunakan snapshot `office_lat`, `office_lng`, `office_radius_m` yang dikunci saat Clock-In, serta `late_minutes` dihitung dengan presisi detik (`08:10:00` → 0, `08:10:01` → 1 menit).
  - Pastikan `LATE_PERMISSION` hanya diterima pada hari kerja efektif normal tanpa `DINAS_LUAR` ketika `late_minutes > 0` dan `clock_in_notes >= 10` karakter.
  - Pastikan percobaan ulang (`attempt_no + 1`) pasca-`REJECTED` di bawah `DINAS_LUAR` tetap menautkan `leave_request_id` dan membuka blokir jarak kantor.
- `[PARTIAL]` Audit `AntiFraudService` (FR-3 & FR-4):
  - Pastikan `REPEATED_COORDS` dan `CONSTANT_ACCURACY` tidak memicu `PENDING_REVIEW` pada absensi kantor dalam radius dengan akurasi valid (`d <= R`, `a <= R`, `a <= 100m`, non-`DINAS_LUAR`), dan tetap aktif penuh untuk luar radius, `DINAS_LUAR`, serta `sales_visits`.
  - Pastikan `SHARED_DEVICE` mengecek lintas `attendance_logs` + `sales_visits` di tanggal lokal sama, menandai akun ke-1 secara retroaktif (`AUTO_APPROVED` → `PENDING_REVIEW`, tidak menimpa `APPROVED`/`REJECTED`), dan mengabaikan `source = MANUAL_HR`.

### Tugas `[FRONTEND]`
- `[PARTIAL]` Audit `office/Absen.svelte` & `LiveCameraCapture.svelte`:
  - Pastikan deteksi In-App Browser (WhatsApp/Line) memblokir submit dengan alert merah dan instruksi buka Chrome/Safari.
  - Pastikan `gps_session_token` hanya diminta ketika GPS fresh diperoleh atau tombol `Muat Ulang GPS` ditekan — **bukan** direfresh diam-diam saat klik submit (agar `STALE_TIMESTAMP` 120–300 detik bekerja akurat).
  - Pastikan status bar GPS `d / a / R` memakai `font-mono tabular-nums`, wajib kamera `facingMode: "user"` tanpa `<input type="file">`, indikator kompresi `< 300 KB`, serta tombol CTA `min-h-12` disable saat loading (kirim UUID `idempotency_key` stabil per aksi).
  - Pastikan checkbox `Izin Masuk Siang` hanya tampil saat terlambat di hari kerja efektif non-`DINAS_LUAR`, dan banner merah `REJECTED` + CTA `Absen Ulang Sekarang` tampil hanya sebelum `effective_end_time` (atau `23:59` di hari non-efektif).
- `[PARTIAL]` Audit `office/Riwayat.svelte` & `admin/RiwayatSaya.svelte`:
  - Filter bulan / 30 hari terakhir, tampilkan `attempt_no`, status & flag per fase, `reject_reason`, thumbnail via Signed URL, dan teks muted `"Berkas melewati retensi 12 bulan"` ketika URL foto `NULL`.

### Definition of Done (DoD)
- Clock-In dan Clock-Out memenuhi seluruh matriks FR-2 (`AUTO_APPROVED`, `UNCERTAIN_ACCURACY`, `LOW_ACCURACY`, `OUT_OF_RADIUS`, hard block `d > max(200m, R)` kecuali `DINAS_LUAR`), lintas zona waktu WIB/WITA, dan Sabtu 13:00 (SWE/BWE/PWE bukan `EARLY_LEAVE`).
- Retry dengan `idempotency_key` sama mengembalikan HTTP 200 tanpa error token terpakai; gagal validasi HTTP 422 tidak menghanguskan `gps_session_token`/`camera_token`.
- `attendance:close-missed` dijalankan berulang (idempoten) tidak menimpa baris `REJECTED`/`PENDING_REVIEW` dan menutup `DINAS_LUAR` serta hari non-efektif pada batas waktu yang benar.
- `OfficeAttendanceTest` dan `HistoryTest` (termasuk kasus baru bug-fix) lulus 100%, serta alur kamera + GPS terverifikasi di browser.

---

## Milestone 3: Field Sales Visit Tracking & Pengajuan Toko Baru (NOO)

### Tujuan & Scope Utama
Menuntaskan alur kerja tim sales lapangan (`US-005`, `US-006`, bagian sales `US-010`): pencarian toko terdekat tanpa kebocoran koordinat, pintasan 5 toko terakhir, pengajuan toko baru (NOO) dengan kunci GPS presisi & anti-duplikat 100m, Visit In (wajib selfie + lock 1 kunjungan berjalan), pembatalan Visit In (`≤ 5 menit`, kuota 3/hari), serta Visit Out tanpa foto dengan aturan durasi `< 3 menit` dan pengecualian kategori `exempt_short_duration`.

### Status Eksisting di Codebase
- `[DONE]` `SalesVisitController` (`index`, `searchOutlets`, `storeNoo`, `visitIn`, `cancelVisitIn`, `visitOut`), `serializeOutlet` (tanpa lat/lng), transaksi `lockForUpdate()`, `autoCloseStaleVisits`, pembatalan `MAX_CANCELS_PER_DAY = 3`.
- `[DONE]` Halaman `sales/Kunjungan.svelte`, `sales/Riwayat.svelte`, `sales/Profil.svelte`.
- `[PARTIAL]` Perlu audit kepatuhan detail PRD v8 (NOO 50m/100m/kuota 5/hari, urutan idempotency/token, snapshot outlet, skenario NOO ditolak saat `IN_PROGRESS`, dan pengalaman UI mobile 1 tangan).

### Tugas `[BACKEND]`
- `[PARTIAL]` Audit `SalesVisitController::searchOutlets` & `storeNoo`:
  - Pastikan hasil pencarian dibatasi maksimal 30 toko terdekat, `Recently Visited Outlets` maksimal 5 toko berbeda terakhir, dan tidak pernah mengirim `latitude`/`longitude` outlet ke frontend.
  - Pastikan `storeNoo` memvalidasi `accuracy_m <= 50m`, memeriksa toko eksisting dalam radius 100m lintas `ACTIVE` + seluruh `PENDING_VERIFICATION` sebagai soft warning (mengembalikan daftar nama & alamat sekitar tanpa koordinat, membutuhkan flag konfirmasi eksplisit untuk lanjut simpan), membatasi maksimal 5 pengajuan NOO per hari per sales, dan hanya menampilkan toko `PENDING_VERIFICATION` kepada `created_by = user_id`.
- `[PARTIAL]` Audit `SalesVisitController::visitIn`, `cancelVisitIn`, `visitOut`:
  - Pastikan urutan cek `idempotency_key` mendahului validasi/konsumsi token (FR-5), `visitIn` memblokir jika ada cuti/izin/sakit `APPROVED` hari itu, menyimpan snapshot `outlet_lat`, `outlet_lng`, `outlet_radius_m`, menerapkan hard block `d > max(200m, R)`, serta mewajibkan `visit_in_notes >= 10` karakter untuk kondisi `PENDING_REVIEW` (`OUT_OF_RADIUS`, `UNCERTAIN_ACCURACY`, `LOW_ACCURACY`, `UNVERIFIED_OUTLET`, `OFF_DAY_ATTENDANCE`).
  - Pastikan `visitOut` berjalan tanpa kamera/tanpa `camera_token`, memakai snapshot outlet, mengizinkan penyelesaian `Visit Out` walau toko NOO telah diubah HR menjadi `INACTIVE` di tengah kunjungan (menjadi `PENDING_REVIEW` + `UNVERIFIED_OUTLET`), dan menandai `SHORT_DURATION` hanya jika `duration_minutes < 3` AND `exempt_short_duration = false`.
  - Pastikan `cancelVisitIn` memvalidasi `<= 5 menit`, `cancel_reason >= 10` karakter, batas 3x/hari, dan status `CANCELLED` dikecualikan dari `IMPOSSIBLE_TRAVEL` namun tetap dipantau `REPEATED_COORDS` & `SHARED_DEVICE`.
  - Pastikan `visit_date` memakai timezone cabang induk sales (`users.office_location_id`) sedangkan tampilan jam & watermark memakai timezone toko (`outlet_location_id`) sesuai FR-1.

### Tugas `[FRONTEND]`
- `[PARTIAL]` Audit `sales/Kunjungan.svelte`:
  - **Active Visit Card:** timer durasi `font-mono tabular-nums`, tombol `Batalkan Visit In` (aktif `<= 5 menit`, buka modal wajib `cancel_reason`, tampilkan sisa kuota `N/3 hari ini`), form inline Visit Out (`activity_categories` + `visit_notes` + GPS segar, tanpa komponen kamera).
  - **Unified Outlet Picker:** search bar `h-11`, horizontal scroll chips `Recently Visited Outlets` (maks 5), daftar maks 30 toko menampilkan Nama, Kode, Alamat, Radius, Status, Jarak (`distance_m`) tanpa lat/lng.
  - **Modal Ajukan Toko Baru (NOO):** guard visual `accuracy_m <= 50m` + tombol `Muat Ulang GPS`, tampilan soft warning toko sekitar dalam 100m + tombol konfirmasi eksplisit `"Tetap Ajukan Toko Baru"`.
  - **Banner Status:** tampilkan peringatan kunjungan hari ini yang berstatus `REJECTED` (nama toko + `reject_reason`) serta info toko NOO yang ditolak HR (`locations.reject_reason`).
- `[PARTIAL]` Audit `sales/Riwayat.svelte`: filter bulan / 30 hari, durasi, catatan, foto Signed URL / penanda retensi 12 bulan, flag anomali, dan `reject_reason`.

### Definition of Done (DoD)
- Request paralel Visit In dari 2 tab/device tidak pernah menghasilkan 2 baris `IN_PROGRESS` (diverifikasi dengan lock transaksi).
- Kunjungan `IN_PROGRESS` dari hari kemarin otomatis menjadi `AUTO_CLOSED` (`MISSED_VISIT_OUT`) via on-the-fly fallback saat halaman dibuka maupun via scheduler `attendance:close-missed`.
- API `/attendance/sales/outlets` tidak pernah mengekspos `latitude`/`longitude` di JSON payload.
- Alur NOO (akurasi `> 50m` ditolak, soft warning `<= 100m`, kuota 5/hari, NOO ditolak saat kunjungan berjalan) dan Visit Out (`< 3 menit` vs `Toko Tutup` `exempt_short_duration = true`) berjalan end-to-end.
- `SalesVisitTest` dan `HistoryTest` (skenario sales) lulus 100%.

---

## Milestone 4: ESS (Cuti, Izin, Sakit, Dinas Luar) & Master Data HR (Termasuk Ganti Hari & Saldo Cuti)

### Tujuan & Scope Utama
Menyelesaikan seluruh fitur Employee Self-Service (`US-009`) dan pusat kendali Master Data HR (`US-007`), terutama menutup celah kritis pada aturan **Ganti Hari (batas tanggal 25, penolakan `COLLECTIVE_LEAVE`, syarat bukti 2 fase, pembatalan/reschedule)**, **Impor CSV Saldo Awal Cuti (`< 1 tahun` & header resmi)**, serta sinkronisasi saldo cuti lintas tahun & rekalkulasi otomatis saat jadwal/libur berubah.

### Status Eksisting di Codebase
- `[DONE]` `LeaveController` (`/leave`), `LeaveApprovalController` (`/admin/leaves`), `LeaveBalanceService`, `ShiftScheduleGeneratorService`, `GenerateShiftSchedulesCommand`, `AnnualLeaveMaintenanceCommand`.
- `[DONE]` CRUD Master Data di `MasterDataController` (karyawan, reset password, unlock, saldo cuti, shift, pola shift, lokasi kantor & toko, verifikasi NOO, kategori aktivitas, hari libur, mapping Talenta).
- `[DONE]` Halaman `office/Pengajuan.svelte`, `sales/Pengajuan.svelte`, `admin/PengajuanSaya.svelte`, `admin/Leaves.svelte`, `admin/MasterData.svelte`, `admin/TalentaMapping.svelte`.
- `[PARTIAL]` **Gap teridentifikasi pada audit kode:**
  - `MasterDataController::overrideSchedule` belum menerapkan batas tanggal 25, belum menolak `COLLECTIVE_LEAVE`, belum mewajibkan 2 fase `APPROVED` + `clock_out_at IS NOT NULL` untuk `ATTENDANCE_LOG` & `COMPLETED/AUTO_CLOSED` + `APPROVED` untuk `SALES_VISIT`, belum mengecek kunjungan sales aktif hari ini, dan belum memiliki endpoint batal/reschedule Ganti Hari.
  - `MasterDataController::importLeaveBalancesCsv` belum memakai header wajib `nik,year,adjustment_days,notes` dan belum menolak baris saldo positif untuk karyawan bermasa kerja `< 1 tahun`.

### Tugas `[BACKEND]`
- `[TODO]` **Lengkapi aturan Ganti Hari di `MasterDataController::overrideSchedule` (US-007):**
  - Validasi batas mutlak tanggal 25 dari `source_date`: `DAY(source_date)` 1–24 → `source_date < work_date <= YYYY-MM-25` bulan sama; `DAY(source_date)` 25–akhir bulan → `source_date < work_date <= YYYY-(MM+1)-25` bulan berikutnya (lintas tahun ke 25 Jan untuk 25–31 Des); tolak HTTP 422 bila `work_date > batas_25` atau `todayLocal > batas_25` (hangus).
  - Tolak eksplisit (HTTP 422) jika `source_date` jatuh pada hari libur bertipe `COLLECTIVE_LEAVE`.
  - Perketat syarat bukti sah: `ATTENDANCE_LOG` wajib `clock_in_status = APPROVED` AND `clock_out_status = APPROVED` AND `clock_out_at IS NOT NULL`; `SALES_VISIT` wajib `verification_status = APPROVED` AND `visit_status IN ('COMPLETED', 'AUTO_CLOSED')`.
  - Tolak override hari berjalan bila terdapat `SalesVisit` aktif (`visit_status != CANCELLED AND verification_status != REJECTED`), bukan hanya `AttendanceLog`.
- `[TODO]` **Tambah endpoint Batal / Reschedule Ganti Hari** (`POST /admin/users/{user}/schedule-override/cancel`): hanya HR Admin lain (`actor_id != user_id`), syarat `work_date >= todayLocal` dan belum ada kehadiran aktif di tanggal tersebut, mengembalikan baris `user_shift_schedules` ke jadwal `PATTERN` (atau menghapus override untuk sales) sehingga `compensation_source_*` terlepas dan tanggal asal bisa dijadwalkan ulang dalam batas tanggal 25, memanggil `recalculateLeaveRequestDays()`, dan mencatat `audit_logs`.
- `[PARTIAL]` **Perbaiki `MasterDataController::importLeaveBalancesCsv`:**
  - Dukung header resmi `nik,year,adjustment_days,notes`, simpan `notes` ke `audit_logs.reason`, dan tolak (HTTP 422 / rollback atomik) baris yang mencoba memberi penyesuaian/saldo positif bagi karyawan dengan masa kerja `< 1 tahun` pada tahun target.
- `[PARTIAL]` Audit `MasterDataController` (karyawan, shift, lokasi, libur, mapping Talenta):
  - Pastikan aturan minimal 2 `HR_ADMIN` aktif, anti self-manipulation (`target_user_id != actor_id`), blokir pindah role ke `EMPLOYEE_SALES` jika ada `DINAS_LUAR` `PENDING`/`APPROVED` `>= hari_ini` atau sesi absen/kunjungan berjalan, blokir nonaktifkan kantor `OFFICE` yang masih punya user aktif, dan `recalculateLeaveRequestDays()` mengecualikan `DINAS_LUAR` dari auto-cancel saat `total_days = 0.0`.
- `[PARTIAL]` Audit `LeaveController` & `LeaveApprovalController` (US-009):
  - Verifikasi batas H-3 hari kerja efektif (`CUTI`), maks 2 hari kerja/bulan (`IZIN`), backdate H-2 hari kerja dari `end_date` (`SAKIT`/`IZIN`), validasi `DINAS_LUAR` (`start_date > today`, `scheduled_start_time <= shifts.end_time`, `+ durasi <= 23:59`, larangan `EMPLOYEE_SALES` HTTP 403, larangan batal tanggal yang sudah punya `attendance_logs` tertaut), validasi magic-bytes SKD `JPG/PNG/PDF <= 2 MB`, cuti lintas tahun (lazy init `Y+1`), email penolakan saat `DINAS_LUAR` `PENDING` hari-H ditolak, dan `AuditAction::OVERRIDE_LIMIT` pada override `> 2 hari/bulan`.

### Tugas `[FRONTEND]`
- `[TODO]` Perbarui modal Override & Ganti Hari di `admin/MasterData.svelte`:
  - Tampilkan kolom/filter `organization` pada tabel karyawan, tampilkan daftar kehadiran hari libur (`OFF_DAY_ATTENDANCE`) eligible yang belum ditukar beserta tanggal batas hangus (tanggal 25), serta tombol **Batalkan / Reschedule Ganti Hari** pada jadwal override aktif `>= hari_ini`.
- `[PARTIAL]` Perbarui modal **Impor CSV Saldo Awal Cuti** di `admin/MasterData.svelte`:
  - Selaraskan parser preview, tabel validasi baris-per-baris, dan template CSV unduhan ke format `nik,year,adjustment_days,notes`, termasuk indikator error merah untuk karyawan `< 1 tahun` dengan saldo positif.
- `[PARTIAL]` Audit modal **Impor CSV Toko (Outlet)** di `admin/MasterData.svelte`: pastikan preview validasi header `code,name,address,latitude,longitude,radius_m,timezone`, sanitasi formula injection, dan verifikasi NOO dengan input wajib `reject_reason` saat tolak.
- `[PARTIAL]` Audit `office/Pengajuan.svelte`, `sales/Pengajuan.svelte`, `admin/PengajuanSaya.svelte`, dan `admin/Leaves.svelte`:
  - 4-grid kartu saldo cuti (`Jatah`, `Terpakai + Cutber`, `Pending`, `Sisa` termasuk state `0 hari (< 1 tahun)` dan nilai minus merah).
  - Preview kalkulasi `total_days` otomatis dalam horizon 90 hari, soft warning kuning `CUTI > 2 hari/bulan`, banner bentrok cuti antar-HR `< 2`, badge peringatan keras `"Ada absen/kunjungan REJECTED pada tanggal ini"` untuk sakit susulan, dialog override `"Lanjutkan / Batal"`, serta modal pembatalan parsial per tanggal (`leave_date >= hari_ini`) + akses SKD via Signed URL.

### Definition of Done (DoD)
- Penetapan Ganti Hari melewati batas tanggal 25 atau berasal dari tanggal `COLLECTIVE_LEAVE` ditolak HTTP 422; bukti `ATTENDANCE_LOG` yang belum `APPROVED` pada kedua fase atau tanpa `clock_out_at` ditolak; pembatalan Ganti Hari melepas `compensation_source_date` sehingga bisa di-reschedule.
- Impor CSV saldo cuti memakai format `nik,year,adjustment_days,notes`, menolak saldo positif untuk masa kerja `< 1 tahun` secara atomik, dan menampilkan preview validasi sebelum commit.
- Pengajuan `CUTI` `< 1 tahun` / `< H-3`, `IZIN` `> 2 hari/bulan`, `DINAS_LUAR` oleh `EMPLOYEE_SALES`, dan tumpang tindih tanggal/kehadiran ditolak server sesuai US-009; cuti lintas tahun (30 Des – 2 Jan) memecah penguncian saldo ke `Y` dan `Y+1` tanpa ter-reset pada 1 Januari.
- `GantiHariTest`, `MasterDataTest`, dan `EssLeaveTest` lulus 100%.

---

## Milestone 5: Dashboard Monitoring HR, Verifikasi Side-by-Side, Input Manual, Ekspor 3 Format & Audit Logs

### Tujuan & Scope Utama
Menuntaskan pusat kendali operasional HR (`US-008` & `Lampiran 1`): tabel rekap harian akurat (termasuk transisi `Belum Absen` → `Alpa` saat `effective_end_time` berlalu), antrean verifikasi per fase lengkap dengan foto side-by-side + peta Leaflet + konteks anomali (`SHARED_DEVICE`, `OFFSITE_DUTY`, `COLLECTIVE_LEAVE`), Bulk Approve aman, Koreksi Status + penyesuaian jam untuk kantor & sales, Input Manual HR (kantor & sales) dengan panel pengawasan 7 hari antar-HR, Ekspor 3 Format resmi (File 1 Office 26 kolom, File 2 Sales 18 kolom, File 3 Audit Internal), serta halaman `/admin/audit-logs`.

### Status Eksisting di Codebase
- `[DONE]` `HrDashboardController` (`index`, `reviewAttendance`, `correctAttendanceStatus`, `reviewVisit`, `bulkApprove`, `storeManualAttendance`), `ExportController` (File 1, 2, 3), `TabularReportExport`, `CsvSanitizer`, `MasterDataController::auditLogsIndex`.
- `[DONE]` Halaman `admin/Dashboard.svelte`, `admin/AuditLogs.svelte`, komponen `VerificationMap.svelte`.
- `[PARTIAL]` **Gap teridentifikasi pada audit kode:**
  - `HrDashboardController::index` belum memisahkan item antrean `attendance_logs` yang fase `clock_in` dan `clock_out`-nya sama-sama `PENDING_REVIEW`, belum mengirim panel `"Input Manual HR (7 Hari Terakhir)"` khusus antar-HR, belum mengirim pasangan akun `SHARED_DEVICE` / info `destination_location` & `scheduled_start_time` `OFFSITE_DUTY` / penanda `COLLECTIVE_LEAVE` / penanda `Sakit Susulan`, dan memakai koordinat `locations` terkini alih-alih snapshot `office_lat`/`office_lng`/`office_radius_m`.
  - `correctAttendanceStatus` belum mendukung penyesuaian `clock_in_at`/`clock_out_at` + rekalkulasi `late_minutes`/`early_leave_minutes` & `syncCollectiveLeaveFor`; belum ada endpoint koreksi `REJECTED → APPROVED` khusus `sales_visits`.
  - `storeManualAttendance` baru mendukung `attendance_logs` kantor, belum mendukung input kunjungan manual `sales_visits`.
  - `Dashboard.svelte` masih memakai `window.confirm()` untuk warning export (belum modal sesuai `design.md`).

### Tugas `[BACKEND]`
- `[PARTIAL]` **Perkaya payload `HrDashboardController::index`:**
  - Pisahkan `pendingAttendance` menjadi 1 item antrean per fase (`clock_in` dan `clock_out` terpisah) agar review & Bulk Approve bekerja akurat per fase.
  - Gunakan snapshot `office_lat`/`office_lng`/`office_radius_m` (fallback ke `location`) dan `outlet_lat`/`outlet_lng`/`outlet_radius_m` untuk koordinat peta verifikasi.
  - Tambahkan metadata review: detail pasangan akun + jam absen untuk flag `SHARED_DEVICE`, `destination_location` + `scheduled_start_time` + `leave_status` untuk `OFFSITE_DUTY` (kunci tombol jika `PENDING`), penanda `is_collective_leave_date` untuk `OFF_DAY_ATTENDANCE`, sisa waktu absen ulang pada item hari berjalan, dan penanda `has_followup_sick_leave` untuk tanggal `REJECTED`.
  - Tambahkan prop `recentManualEntries` (panel `"Input Manual HR (7 Hari Terakhir)"` khusus baris dengan `actor_id != auth.id`) serta data penginput (`reviewed_by` / `actor_name`) untuk badge `"Input Manual HR"`.
  - Pastikan transisi status harian pada `selectedDate === todayLocal` otomatis beralih dari `'Belum Absen'` (atau `'Belum Absen (Pending ...)'`) menjadi `'Alpa'` (atau `'Alpa (Ada Pengajuan Pending)'`) begitu waktu cabang `>= effective_end_time`.
- `[PARTIAL]` **Lengkapi `correctAttendanceStatus` & tambah `correctVisitStatus` (`POST /admin/visits/{salesVisit}/correct`):**
  - Pada `correctAttendanceStatus`: dukung input opsional `clock_in_time` / `clock_out_time` (validasi `clock_out_at > clock_in_at`), hitung ulang `late_minutes` & `early_leave_minutes`, panggil `syncCollectiveLeaveFor()`, dan catat `old_values`/`new_values` ke `audit_logs` (`AuditAction::CORRECT_STATUS`).
  - Tambah `correctVisitStatus`: non-self (`reviewed_by != user_id`), tolak jika bentrok cuti/izin/sakit `APPROVED`, auto-cancel leave non-dinas `PENDING` di tanggal sama, panggil `syncCollectiveLeaveFor()`, dan catat `AuditAction::CORRECT_STATUS`.
- `[PARTIAL]` **Perluas `storeManualAttendance` (atau tambah `storeManualVisit`):**
  - Dukung input manual darurat untuk `EMPLOYEE_SALES` (`sales_visits` dengan `source = MANUAL_HR`, pilih `outlet_location_id`, `visit_in_time`, opsional `visit_out_time` & `activity_category_id` — bila `visit_out_time` kosong simpan `visit_status = AUTO_CLOSED`, alasan min 10 karakter, non-self, `syncCollectiveLeaveFor`, `AuditAction::MANUAL_ATTENDANCE`).
- `[PARTIAL]` **Audit ketat `ExportController` (File 1, File 2, File 3):**
  - Verifikasi 26 header File 1 Talenta (Sheet `Sheet1`), urutan prioritas 1–6 per `(user_id, work_date)`, `use_offsite_schedule_for_talenta`, `export_offday_overtime_to_talenta` (dikecualikan bila sudah ditukar Ganti Hari via `compensation_source_type`/`compensation_source_id`), dan warning pra-ekspor bila ada `PENDING`/`PENDING_REVIEW` atau rentang mencakup `hari_ini`.
  - Verifikasi 18 header File 2 Sales (Sheet `Worksheet`), baris status harian (`Time Off`/`Absent`/`Day Off`), larangan menyisipkan `Absent` palsu saat masih ada kunjungan `PENDING_REVIEW`/`IN_PROGRESS` ketika checkbox unverified tidak dicentang, serta konsistensi baris `Absent` saat seluruh kunjungan `REJECTED` dan checkbox dicentang.
  - Verifikasi File 3 Audit Internal (grid seluruh karyawan aktif termasuk baris `CANCELLED` & ringkasan rentang lapangan harian sales) + sanitasi formula injection + audit log `EXPORT_ATTENDANCE`.
- `[PARTIAL]` Audit `MasterDataController::auditLogsIndex`: pastikan dukungan filter (entity, action, actor, rentang tanggal) dan akses read-only untuk akun non-HR dengan `users.can_view_audit_logs = true`.

### Tugas `[FRONTEND]`
- `[PARTIAL]` **Lengkapi `admin/Dashboard.svelte` & `VerificationMap.svelte`:**
  - Tambah panel ringkas **"Input Manual HR (7 Hari Terakhir)"** (khusus aksi HR Admin lain) dan filter cepat **"Sumber: Manual HR"** pada tabel rekap harian.
  - Lengkapi Verification Review Modal (`max-w-5xl` 2 kolom): foto profil vs foto absen side-by-side, peta `VerificationMap` (radius kantor `#6F3E1D` + akurasi user `#1D4ED8`), panel konteks khusus untuk `SHARED_DEVICE`, `OFFSITE_DUTY` (lock tombol + pesan bila dinas masih `PENDING`), dan `COLLECTIVE_LEAVE` (`+1 Hari / Bebas Unpaid`), serta validasi `clock_out_at > clock_in_at` saat `MISSED_CLOCK_OUT`.
  - Perluas modal **Input Absen Manual** agar otomatis menyesuaikan form saat memilih karyawan `EMPLOYEE_OFFICE`/`HR_ADMIN` (shift, jam masuk/pulang) vs `EMPLOYEE_SALES` (outlet, kategori, jam visit in/out).
  - Perluas modal **Koreksi Status** agar mendukung penyesuaian jam masuk/pulang (kantor) serta koreksi kunjungan sales `REJECTED → APPROVED`.
  - Ganti `window.confirm()` pada **Export 3 Format** dengan **Warning Confirmation Modal** terstruktur (menampilkan jumlah `PENDING` / `PENDING_REVIEW` dan peringatan jika rentang mencakup `hari_ini`).
- `[PARTIAL]` Lengkapi `admin/AuditLogs.svelte`: tambahkan filter bar (entity, action, rentang tanggal), tampilan diff `old_values`/`new_values` `font-mono text-xs`, dan indikator status verifikasi rantai `row_hash`.

### Definition of Done (DoD)
- Dashboard HR menampilkan KPI 5 kartu, banner `HR_ADMIN < 2`, panel `Input Manual HR (7 Hari Terakhir)` hanya untuk aksi HR lain, serta transisi `Belum Absen` → `Alpa` tepat setelah `effective_end_time`.
- Verification Modal menampilkan foto side-by-side + peta Leaflet berbasis snapshot, mengunci tombol saat `DINAS_LUAR` masih `PENDING` atau `sales_visits` masih `IN_PROGRESS`, dan Bulk Approve otomatis mengecualikan seluruh flag terlarang & item `self`.
- Koreksi Status (kantor + edit jam & sales visit) dan Input Manual (kantor & sales) bekerja end-to-end, memperbarui `collective_leave_deduction` di tanggal cuti bersama, dan tercatat di `audit_logs`.
- Unduhan File 1 (`.xlsx`/`.csv`, 26 kolom), File 2 (18 kolom + checkbox unverified), dan File 3 lolos verifikasi struktur kolom, sanitasi CSV, batas 93 hari, dan warning modal.
- `HrDashboardAndExportTest` dan `AuditGovernanceTest` (termasuk test baru) lulus 100%.

---

## Milestone 6: QA Menyeluruh (SQLite & MySQL), Audit UI/UX & Kesiapan Deploy

### Tujuan & Scope Utama
Memverifikasi seluruh aplikasi secara menyeluruh lintas mesin database (SQLite lokal & MySQL produksi), memastikan zero error pada static analysis/typecheck/lint, menguji seluruh background scheduler & queue email, memvalidasi kepatuhan visual & responsivitas terhadap `docs/design.md`, serta memastikan kesiapan infrastruktur Docker & UAT perangkat fisik (`PRD Section 8`).

### Status Eksisting di Codebase
- `[DONE]` 20+ file Pest feature test, konfigurasi `phpunit.xml`, `phpunit.mysql.xml`, `phpstan.neon`, `pint.json`, `eslint.config.js`, `docker-compose.yml`, `.github/workflows/`.
- `[PARTIAL]` Cakupan test belum mencakup gap baru yang diperbaiki di M1–M5; belum ada verifikasi akhir terpadu pada MySQL, browser simulasi GPS/kamera, dan checklist UAT perangkat fisik.

### Tugas `[BACKEND]`
- `[TODO]` Tambah/perbarui Pest Feature & Unit Tests untuk seluruh perbaikan M1–M5:
  - Trigger MySQL append-only `audit_logs`, `enforceMorphMap`, `CloseMissedAttendanceCommand` idempoten & `DINAS_LUAR`/`COLLECTIVE_LEAVE`.
  - Ganti Hari batas tanggal 25 (1–24 vs 25–akhir bulan & lintas tahun 25 Jan), penolakan `COLLECTIVE_LEAVE`, bukti 2 fase `APPROVED`, batal/reschedule Ganti Hari.
  - CSV import saldo cuti (`nik,year,adjustment_days,notes` & blokir `< 1 tahun`), koreksi status + edit jam, koreksi `sales_visits`, manual sales visit, dan edge cases ekspor File 1/2/3.
- `[TODO]` Jalankan dan pastikan hijau seluruh gerbang kualitas backend:
  - `php artisan test` (SQLite) dan full suite pada **MySQL** (`phpunit.mysql.xml` — memverifikasi JSON query, timezone WIB/WITA, `UNIQUE(user_id, work_date, is_active_attempt)`, `UNIQUE(user_id, compensation_source_date)`, dan trigger `audit_logs`).
  - PHPStan/Larastan (`./vendor/bin/phpstan analyse`) dan Pint (`./vendor/bin/pint --test`).
- `[TODO]` Uji manual & otomatis 5 command scheduler (`attendance:close-missed`, `attendance:generate-schedules`, `leave:annual-maintenance`, `attendance:prune-retention`, `evidence:prune-orphans`) dalam skenario idempoten & catch-up pasca-downtime, serta uji Queue Worker email penolakan (`$tries = 3`, backoff, Mailpit).

### Tugas `[FRONTEND]`
- `[TODO]` Jalankan dan pastikan nol error/warning pada `npm run types:check` (`svelte-check`), `npm run lint:eslint`, dan `npm run build`.
- `[TODO]` Audit UI/UX menyeluruh terhadap `docs/design.md` di browser (viewport mobile `360px–430px` dan desktop `1280px+`):
  - Konsistensi palet Corporate Brown + Warm Stone, semantik badge warna, `font-mono tabular-nums` pada angka/jam/koordinat/jarak, touch target `min-h-12` CTA mobile, dan kepadatan tabel desktop HR.
- `[TODO]` Uji end-to-end berbasis browser (mock Geolocation + fake WebRTC camera stream) untuk 3 role utama:
  - `EMPLOYEE_OFFICE`: login → consent → Clock-In dalam radius / luar radius + alasan / akurasi rendah → Clock-Out → pengajuan ESS → riwayat.
  - `EMPLOYEE_SALES`: login → search outlet → ajukan NOO (guard 50m + warning 100m) → Visit In selfie → batal / Visit Out tanpa foto → riwayat.
  - `HR_ADMIN`: dashboard → review side-by-side + peta → Bulk Approve → Koreksi Status → Input Manual → Master Data & Ganti Hari → Ekspor 3 Format → Audit Logs.

### Definition of Done (DoD)
- 100% test suite Pest lulus pada SQLite **dan** MySQL tanpa skipped test.
- PHPStan, Laravel Pint, `svelte-check`, ESLint, dan `npm run build` lulus tanpa error.
- Seluruh 5 scheduled command terbukti idempoten; email penolakan terkirim via queue worker dan gagal permanen tercatat di `failed_jobs`.
- Simulasi browser untuk 3 role bebas error console/network, tampilan mobile & desktop sesuai `docs/design.md`, dan dokumen panduan UAT Android (Chrome) / iOS (Safari) + verifikasi `docker compose up` siap diserahkan untuk sign-off produksi.
