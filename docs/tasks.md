# Task Breakdown — Redesign Halaman Kunjungan Sales Mengikuti Desain Absensi Kantor

Acuan: `resources/js/pages/office/Absen.svelte`, `docs/api-contract.md` (Bagian 3), `docs/design.md` (Bagian 4B), dan permintaan user (*"untuk design sales ubah menjadi seperti office. tampilan awal : tampilkan button untuk visit in dan visit out (sama seperti office). saat user mengklik visit in barulah muncul pop up untuk memilih toko dan melakukan foto, tampilkan juga status lokasinya berdasarkan lokasi toko yang dipilih"*).

## `[FRONTEND]` — Delegasi ke `frontend-engineer`
**Scope File:** `resources/js/pages/sales/Kunjungan.svelte`.
**Non-Goal:** JANGAN ubah file PHP/backend, endpoint API, payload JSON, atau `Absen.svelte`.

### Design Parity dengan `office/Absen.svelte`
1. **Tampilan Awal (Ringkas & Bersih — Tanpa Form Picker/Kamera Terbuka Langsung):**
   - Alert In-App Browser (merah, sama seperti `Absen.svelte`).
   - Grid 2 tombol utama sejajar (`grid grid-cols-2 gap-2`):
     - **`Visit In`** (`min-h-12`, aktif saat `!hasApprovedTimeOff && activeVisit === null && !inAppWarning`, klik membuka bottom `<Sheet>` Visit In).
     - **`Visit Out`** (`min-h-12`, aktif saat `activeVisit !== null && !inAppWarning`, klik membuka bottom `<Sheet>` Visit Out).
   - Banner feedback error/sukses (sama seperti `Absen.svelte`).
   - **Kartu Status Kunjungan** (`rounded-xl border border-stone-200 bg-white p-3 shadow-xs`, gaya selaras `Absen.svelte`):
     - Judul `"Status Kunjungan"`, tanggal hari ini (`dateLabel`), badge status (`Sedang berkunjung` / `Cuti / Izin` / `Sudah N kunjungan` / `Belum ada kunjungan`).
     - Jika ada `activeVisit`: tampilkan nama toko, kode & alamat, grid 2 kolom (`Visit In` jam lokal toko & `Durasi` berjalan `font-mono tabular-nums`), badge verifikasi, serta tombol sekunder `Batalkan Visit In` bila `canCancelNow` (+ sisa kuota `N/3`).
     - Info strip hari libur (`isDayOff`) & state terkunci (`hasApprovedTimeOff`).
   - Banner kunjungan hari ini yang ditolak HR (`rejectedVisitsToday`) dan pengajuan toko baru yang ditolak HR (`rejectedOutlets`).
   - **Kartu Aktivitas Hari Ini** (`todayVisits`, gaya daftar rapi + tombol link `"Lihat Riwayat Lainnya"` ke `/attendance/history`).

2. **Pop-up Bottom `<Sheet>` Visit In (Muncul Saat Klik `Visit In`):**
   - Bagian 1 — **Pilih Toko:** search bar `h-11` + tombol Cari, chip `Toko terakhir dikunjungi` (maks 5), daftar toko terdekat (jarak `±Xm`, radius, status, alamat), dan tombol/form `Ajukan Toko Baru` (guard akurasi `≤ 50 m`, daftar toko sekitar 100 m pada HTTP 409 + tombol `Tetap Ajukan Toko Baru`).
   - Bagian 2 — **Status Lokasi Berdasarkan Toko yang Dipilih** (meniru kotak GPS di pop-up `Absen.svelte`):
     - Header `GPS` + tombol `Muat Ulang` (`refreshGpsAndOutlets`, sekaligus menyegarkan `selectedOutlet.distance_m` dari hasil `searchResults` terbaru!).
     - 3 kotak metrik `font-mono tabular-nums`: **Jarak** (ke toko terpilih), **Akurasi**, **Radius** (toko terpilih).
     - Pesan status lokasi berwarna jelas: hijau (`Di dalam radius toko.`), kuning (`Di luar radius toko. Wajib isi alasan minimal 10 karakter.` / `Sinyal GPS lemah...` / `Toko baru menunggu cek HR...`), merah (`Jarak di luar batas (maks X m). Mendekat ke toko dulu.` / GPS mati), atau netral saat belum memilih toko.
   - Bagian 3 — **Foto Selfie (Kamera Langsung):** `LiveCameraCapture` (`facingMode="user"`), indikator `Siap · X KB`.
   - Bagian 4 — **Catatan/Alasan** (counter `N/10 karakter` saat wajib) + CTA **`Visit In ke {selectedOutlet.name}`** (`min-h-12 w-full`). Tutup sheet otomatis saat Visit In sukses.

3. **Pop-up Bottom `<Sheet>` Visit Out (Muncul Saat Klik `Visit Out`):**
   - Info toko aktif & durasi berjalan, status GPS ringkas + tombol `Muat Ulang`, dropdown `Kategori Aktivitas`, textarea `Ringkasan hasil kunjungan` (counter `N/10` bila akurasi buruk), CTA **`Simpan Visit Out`** (`min-h-12 w-full`), dan opsi `Batalkan Visit In` bila masih `canCancelNow`. Tutup sheet otomatis saat Visit Out sukses.

4. **Pop-up Bottom `<Sheet>` Batal Visit In** tetap dipertahankan.
5. Gunakan gaya penulisan `stop-slop` (ringkas, aktif, bebas em-dash `—` di kalimat) dan pastikan **0 error** `svelte-check` & `eslint`.
