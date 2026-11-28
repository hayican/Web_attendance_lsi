<script lang="ts">
    import { onDestroy, onMount } from 'svelte';
    import { SvelteURLSearchParams } from 'svelte/reactivity';
    import { Link, router } from '@inertiajs/svelte';
    import AlertTriangle from '@lucide/svelte/icons/alert-triangle';
    import CircleCheck from '@lucide/svelte/icons/circle-check';
    import MapPin from '@lucide/svelte/icons/map-pin';
    import RefreshCw from '@lucide/svelte/icons/refresh-cw';
    import AppHead from '@/components/AppHead.svelte';
    import LiveCameraCapture from '@/components/LiveCameraCapture.svelte';
    import { Button } from '@/components/ui/button';
    import { Input } from '@/components/ui/input';
    import { Sheet, SheetContent } from '@/components/ui/sheet';
    import {
        isInAppBrowser,
        recommendedExternalBrowserInstruction,
    } from '@/lib/deviceCheck';
    import {
        type FreshGpsResult,
        readXsrfToken,
        requestFreshGps,
    } from '@/lib/gpsClient';

    type VerificationStatus =
        | 'AUTO_APPROVED'
        | 'PENDING_REVIEW'
        | 'APPROVED'
        | 'REJECTED';

    type VisitStatus = 'IN_PROGRESS' | 'COMPLETED' | 'CANCELLED' | 'AUTO_CLOSED';

    type OutletItem = {
        id: number;
        code: string | null;
        name: string;
        address: string;
        radius_m: number;
        status: string;
        distance_m: number | null;
    };

    type ActiveVisit = {
        id: number;
        outlet_name: string;
        outlet_code: string | null;
        outlet_address: string;
        outlet_radius_m: number;
        outlet_timezone: string;
        visit_in_at: string | null;
        visit_in_time: string | null;
        verification_status: VerificationStatus;
        reject_reason: string | null;
        can_cancel: boolean;
        cancel_remaining_today: number;
    };

    type TodayVisit = {
        id: number;
        outlet_name: string;
        outlet_code: string | null;
        outlet_address: string;
        outlet_timezone: string;
        visit_in_time: string | null;
        visit_out_time: string | null;
        duration_minutes: number | null;
        activity_category_name: string | null;
        visit_notes: string | null;
        visit_in_notes: string | null;
        cancel_reason: string | null;
        visit_status: VisitStatus;
        verification_status: VerificationStatus;
        source: 'APP' | 'MANUAL_HR';
        reject_reason: string | null;
    };

    type RejectedOutlet = {
        id: number;
        name: string;
        address: string;
        reject_reason: string | null;
    };

    type SalesProps = {
        homeTimezone: string;
        todayDate: string;
        serverTimeIso: string;
        isDayOff?: boolean;
        hasApprovedTimeOff: boolean;
        rejectedOutlets: RejectedOutlet[];
        activeVisit: ActiveVisit | null;
        recentOutlets: OutletItem[];
        todayVisits: TodayVisit[];
        categories: {
            id: number;
            name: string;
            exempt_short_duration: boolean;
        }[];
    };

    let {
        homeTimezone,
        todayDate,
        serverTimeIso,
        isDayOff = false,
        hasApprovedTimeOff,
        rejectedOutlets,
        activeVisit,
        recentOutlets,
        todayVisits,
        categories,
    }: SalesProps = $props();

    const badgeBase =
        'inline-flex items-center gap-1 rounded border px-1.5 py-0.5 text-[11px] font-medium whitespace-nowrap';
    const toneSuccess = 'border-green-300 bg-green-50 text-green-700';
    const toneWarning = 'border-yellow-300 bg-yellow-50 text-yellow-800';
    const toneError = 'border-red-300 bg-red-50 text-red-700';
    const toneInfo = 'border-blue-300 bg-blue-50 text-blue-700';
    const toneNeutral = 'border-stone-300 bg-stone-100 text-stone-700';

    let inAppWarning = $state(false);
    let gps = $state<FreshGpsResult | null>(null);
    let refreshingGps = $state(false);
    let outletsLoading = $state(true);
    let searchQuery = $state('');
    let searchResults = $state<OutletItem[]>([]);
    let selectedOutlet = $state<OutletItem | null>(null);
    let capturedPhoto = $state<{ blob: Blob; cameraToken: string } | null>(
        null,
    );
    let cameraKey = $state(0);
    let notes = $state('');
    let selectedCategoryId = $state<number | ''>('');
    let cancelReason = $state('');
    let visitInSheetOpen = $state(false);
    let visitOutSheetOpen = $state(false);
    let cancelSheetOpen = $state(false);
    let submitting = $state(false);
    let pendingVisitInKey = $state<string | null>(null);
    let pendingVisitOutKey = $state<string | null>(null);
    let feedback = $state<{ type: 'error' | 'success'; message: string } | null>(
        null,
    );
    let clockTickMs = $state(0);
    let clockOffsetMs = 0;
    let timer: ReturnType<typeof setInterval> | null = null;
    let nooOpen = $state(false);
    let nooName = $state('');
    let nooAddress = $state('');
    let nooTimezone = $state<string>('');
    let nooNearby = $state<OutletItem[] | null>(null);

    const nowMs = $derived(
        clockTickMs !== 0
            ? clockTickMs
            : Number.isNaN(Date.parse(serverTimeIso))
              ? Date.now()
              : Date.parse(serverTimeIso),
    );

    const visitInStartMs = $derived(
        activeVisit?.visit_in_at ? Date.parse(activeVisit.visit_in_at) : null,
    );

    const elapsedLabel = $derived(
        visitInStartMs === null || Number.isNaN(visitInStartMs)
            ? '00:00:00'
            : formatElapsed(Math.max(0, nowMs - visitInStartMs)),
    );

    const canCancelNow = $derived(
        activeVisit !== null &&
            activeVisit.can_cancel &&
            activeVisit.cancel_remaining_today > 0 &&
            visitInStartMs !== null &&
            !Number.isNaN(visitInStartMs) &&
            nowMs - visitInStartMs <= 5 * 60 * 1000,
    );

    const gpsUnavailable = $derived(
        gps?.status === 'PERMISSION_DENIED' ||
            gps?.status === 'GPS_UNAVAILABLE',
    );

    const visitActionBlocked = $derived(inAppWarning || gpsUnavailable);

    const isOutletHardBlocked = $derived(
        selectedOutlet !== null &&
            selectedOutlet.distance_m !== null &&
            selectedOutlet.distance_m > Math.max(200, selectedOutlet.radius_m),
    );

    const rejectedTodayVisits = $derived(
        todayVisits.filter((visit) => visit.verification_status === 'REJECTED'),
    );

    const visitInNotesRequired = $derived.by(() => {
        if (selectedOutlet === null) {
            return false;
        }

        if (isDayOff || selectedOutlet.status !== 'ACTIVE') {
            return true;
        }

        if (
            selectedOutlet.distance_m !== null &&
            selectedOutlet.distance_m > selectedOutlet.radius_m
        ) {
            return true;
        }

        const accuracy = gps?.accuracyM ?? null;

        return accuracy !== null && (accuracy > selectedOutlet.radius_m || accuracy > 100);
    });

    const visitOutPoorAccuracy = $derived(
        activeVisit !== null &&
            gps?.accuracyM != null &&
            (gps.accuracyM > activeVisit.outlet_radius_m || gps.accuracyM > 100),
    );
    const activeVerification = $derived(
        activeVisit !== null ? verificationMeta(activeVisit.verification_status) : null,
    );

    const dateLabel = $derived.by(() =>
        new Intl.DateTimeFormat('id-ID', {
            timeZone: homeTimezone,
            weekday: 'long',
            day: 'numeric',
            month: 'long',
            year: 'numeric',
        }).format(new Date(`${todayDate}T12:00:00Z`)),
    );

    const canVisitIn = $derived(
        !hasApprovedTimeOff && activeVisit === null && !inAppWarning,
    );
    const canVisitOut = $derived(activeVisit !== null && !inAppWarning);

    const completedVisitsCount = $derived(
        todayVisits.filter((visit) => visit.visit_status !== 'CANCELLED').length,
    );

    const summaryMeta = $derived.by<{ label: string; className: string }>(() => {
        if (activeVisit !== null) {
            return {
                label: 'Sedang berkunjung',
                className: `${badgeBase} ${toneInfo}`,
            };
        }

        if (hasApprovedTimeOff) {
            return {
                label: 'Cuti / Izin',
                className: `${badgeBase} ${toneNeutral}`,
            };
        }

        if (completedVisitsCount > 0) {
            return {
                label: `Sudah ${completedVisitsCount} kunjungan`,
                className: `${badgeBase} ${toneSuccess}`,
            };
        }

        return {
            label: 'Belum ada kunjungan',
            className: `${badgeBase} ${toneWarning}`,
        };
    });

    const outletHardBlockLimit = $derived(
        selectedOutlet !== null ? Math.max(200, selectedOutlet.radius_m) : 200,
    );

    const outletLocationMeta = $derived.by<{ label: string; className: string }>(
        () => {
            if (gps === null) {
                return {
                    label: 'Menunggu lokasi GPS…',
                    className: 'text-stone-500',
                };
            }

            if (gps.status === 'PERMISSION_DENIED') {
                return {
                    label: 'Izin lokasi ditolak. Aktifkan izin GPS di browser.',
                    className: 'font-medium text-red-700',
                };
            }

            if (gps.status === 'GPS_UNAVAILABLE') {
                return {
                    label: 'GPS tidak tersedia. Pastikan GPS aktif lalu muat ulang.',
                    className: 'font-medium text-red-700',
                };
            }

            if (gps.lat === null || gps.lng === null) {
                return {
                    label: 'Menunggu lokasi GPS…',
                    className: 'text-stone-500',
                };
            }

            if (selectedOutlet === null) {
                return {
                    label: 'Pilih toko dulu untuk cek jarak lokasi.',
                    className: 'text-stone-600',
                };
            }

            if (isOutletHardBlocked) {
                return {
                    label: `Di luar jangkauan (maks ${outletHardBlockLimit} m). Mendekat ke toko dulu.`,
                    className: 'font-medium text-red-700',
                };
            }

            if (selectedOutlet.status !== 'ACTIVE') {
                return {
                    label: 'Toko baru menunggu cek HR. Wajib isi alasan minimal 10 karakter.',
                    className: 'font-medium text-yellow-800',
                };
            }

            if (
                selectedOutlet.distance_m !== null &&
                selectedOutlet.distance_m > selectedOutlet.radius_m
            ) {
                return {
                    label: 'Di luar radius toko. Wajib isi alasan minimal 10 karakter.',
                    className: 'font-medium text-yellow-800',
                };
            }

            const accuracy = gps.accuracyM ?? null;
            if (
                accuracy !== null &&
                (accuracy > selectedOutlet.radius_m || accuracy > 100)
            ) {
                return {
                    label: 'Sinyal GPS lemah. Wajib isi alasan minimal 10 karakter.',
                    className: 'font-medium text-yellow-800',
                };
            }

            return {
                label: 'Di dalam radius toko.',
                className: 'font-medium text-green-700',
            };
        },
    );

    function formatElapsed(ms: number): string {
        const totalSeconds = Math.floor(ms / 1000);
        const hours = Math.floor(totalSeconds / 3600);
        const minutes = Math.floor((totalSeconds % 3600) / 60);
        const seconds = totalSeconds % 60;

        return [hours, minutes, seconds]
            .map((part) => String(part).padStart(2, '0'))
            .join(':');
    }

    function timezoneLabel(tz: string): string {
        return tz === 'Asia/Makassar' ? 'WITA' : 'WIB';
    }

    function outletStatusLabel(status: string): string {
        switch (status) {
            case 'ACTIVE':
                return 'Aktif';
            case 'PENDING_VERIFICATION':
                return 'Menunggu verifikasi';
            case 'INACTIVE':
                return 'Nonaktif';
            default:
                return status;
        }
    }

    function durationLabel(visit: TodayVisit): string {
        if (visit.duration_minutes !== null) {
            return `${visit.duration_minutes} mnt`;
        }

        return visit.visit_status === 'IN_PROGRESS' ? 'Sedang berkunjung' : '-';
    }

    function verificationMeta(status: VerificationStatus | null): {
        label: string;
        className: string;
    } {
        switch (status) {
            case 'AUTO_APPROVED':
                return {
                    label: 'Otomatis disetujui',
                    className: `${badgeBase} ${toneSuccess}`,
                };
            case 'APPROVED':
                return {
                    label: 'Disetujui',
                    className: `${badgeBase} ${toneSuccess}`,
                };
            case 'PENDING_REVIEW':
                return {
                    label: 'Menunggu cek HR',
                    className: `${badgeBase} ${toneWarning}`,
                };
            case 'REJECTED':
                return {
                    label: 'Ditolak',
                    className: `${badgeBase} ${toneError}`,
                };
            default:
                return { label: '-', className: `${badgeBase} ${toneNeutral}` };
        }
    }

    function visitStatusMeta(status: VisitStatus): {
        label: string;
        className: string;
    } {
        switch (status) {
            case 'IN_PROGRESS':
                return { label: 'Sedang berkunjung', className: `${badgeBase} ${toneInfo}` };
            case 'COMPLETED':
                return { label: 'Selesai', className: `${badgeBase} ${toneSuccess}` };
            case 'CANCELLED':
                return { label: 'Dibatalkan', className: `${badgeBase} ${toneNeutral}` };
            case 'AUTO_CLOSED':
                return {
                    label: 'Ditutup otomatis',
                    className: `${badgeBase} ${toneWarning}`,
                };
            default:
                return { label: status, className: `${badgeBase} ${toneNeutral}` };
        }
    }

    async function readJson(res: Response): Promise<{
        message?: string;
        errors?: Record<string, string[]>;
        duplicate_warning?: boolean;
        nearby_outlets?: OutletItem[];
    }> {
        try {
            return (await res.json()) as {
                message?: string;
                errors?: Record<string, string[]>;
                duplicate_warning?: boolean;
                nearby_outlets?: OutletItem[];
            };
        } catch {
            return {};
        }
    }

    function requestErrorMessage(
        res: Response,
        data: { message?: string; errors?: Record<string, string[]> },
        fallback: string,
    ): string {
        if (res.status === 419) {
            return 'Sesi berakhir. Muat ulang halaman lalu coba lagi.';
        }

        const first = data.errors ? Object.values(data.errors).flat()[0] : undefined;

        return first ?? data.message ?? fallback;
    }

    async function refreshGps() {
        refreshingGps = true;

        try {
            gps = await requestFreshGps(
                selectedOutlet?.radius_m ?? activeVisit?.outlet_radius_m ?? 100,
            );
        } finally {
            refreshingGps = false;
        }
    }

    async function loadOutlets() {
        outletsLoading = true;

        try {
            const params = new SvelteURLSearchParams();
            const query = searchQuery.trim();
            if (query !== '') {
                params.set('q', query);
            }
            if (gps !== null && gps.lat !== null && gps.lng !== null) {
                params.set('lat', String(gps.lat));
                params.set('lng', String(gps.lng));
            }

            const res = await fetch(
                `/attendance/sales/outlets?${params.toString()}`,
                {
                    credentials: 'same-origin',
                    headers: { Accept: 'application/json' },
                },
            );

            if (!res.ok) {
                feedback = {
                    type: 'error',
                    message: 'Gagal memuat daftar toko. Periksa koneksi.',
                };
                return;
            }

            const data = (await res.json()) as { outlets: OutletItem[] };
            searchResults = data.outlets;
            if (selectedOutlet !== null) {
                const updated = data.outlets.find(
                    (o) => o.id === selectedOutlet?.id,
                );
                if (updated) {
                    selectedOutlet = updated;
                }
            }
        } catch {
            feedback = {
                type: 'error',
                message: 'Gagal memuat daftar toko. Periksa koneksi.',
            };
        } finally {
            outletsLoading = false;
        }
    }

    async function refreshGpsAndOutlets() {
        await refreshGps();
        await loadOutlets();
    }

    function openVisitInSheet() {
        feedback = null;
        visitInSheetOpen = true;
        if (searchResults.length === 0 && !outletsLoading) {
            void loadOutlets();
        }
    }

    function openVisitOutSheet() {
        feedback = null;
        visitOutSheetOpen = true;
    }

    async function submitNoo(confirmDuplicate: boolean) {
        if (submitting) {
            return;
        }

        if (nooName.trim() === '' || nooAddress.trim() === '') {
            feedback = {
                type: 'error',
                message: 'Nama dan alamat toko wajib diisi.',
            };
            return;
        }

        submitting = true;
        feedback = null;
        nooNearby = null;

        try {
            const fresh = await requestFreshGps(100);
            gps = fresh;

            if (
                fresh.lat === null ||
                fresh.lng === null ||
                fresh.accuracyM === null
            ) {
                feedback = {
                    type: 'error',
                    message:
                        fresh.message ?? 'Lokasi GPS belum siap. Muat ulang GPS.',
                };
                return;
            }

            if (fresh.accuracyM > 50) {
                feedback = {
                    type: 'error',
                    message:
                        'Akurasi GPS harus 50 m atau lebih baik untuk mengajukan toko baru. Pindah ke area terbuka lalu muat ulang GPS.',
                };
                return;
            }

            const res = await fetch('/attendance/sales/noo', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-XSRF-TOKEN': readXsrfToken(),
                },
                body: JSON.stringify({
                    name: nooName.trim(),
                    address: nooAddress.trim(),
                    lat: fresh.lat,
                    lng: fresh.lng,
                    accuracy_m: fresh.accuracyM,
                    timezone: nooTimezone,
                    confirm_duplicate: confirmDuplicate,
                }),
            });

            const data = await readJson(res);

            if (res.status === 409 && data.duplicate_warning === true) {
                nooNearby = data.nearby_outlets ?? [];
                feedback = {
                    type: 'error',
                    message:
                        'Ada toko lain dalam radius 100 m. Cek daftar di bawah dulu sebelum tetap mengajukan.',
                };
                return;
            }

            if (!res.ok) {
                feedback = {
                    type: 'error',
                    message: requestErrorMessage(res, data, 'Gagal mengajukan toko baru.'),
                };
                return;
            }

            nooOpen = false;
            nooName = '';
            nooAddress = '';
            nooNearby = null;
            searchQuery = '';
            await loadOutlets();
            feedback = {
                type: 'success',
                message:
                    'Pengajuan toko baru terkirim. Menunggu cek HR.',
            };
        } catch {
            feedback = {
                type: 'error',
                message: 'Koneksi bermasalah. Silakan coba lagi.',
            };
        } finally {
            submitting = false;
        }
    }

    onMount(() => {
        inAppWarning = isInAppBrowser();
        nooTimezone =
            homeTimezone === 'Asia/Makassar' ? 'Asia/Makassar' : 'Asia/Jakarta';
        clockOffsetMs = Date.parse(serverTimeIso) - Date.now();
        clockTickMs = Date.now() + clockOffsetMs;
        timer = setInterval(() => {
            clockTickMs = Date.now() + clockOffsetMs;
        }, 1000);

        void refreshGpsAndOutlets();
    });

    onDestroy(() => {
        if (timer !== null) {
            clearInterval(timer);
            timer = null;
        }
    });

    async function submitVisitIn() {
        if (submitting) {
            return;
        }

        if (selectedOutlet === null) {
            feedback = { type: 'error', message: 'Pilih toko tujuan dulu.' };
            return;
        }

        if (capturedPhoto === null) {
            feedback = {
                type: 'error',
                message: 'Ambil foto selfie dulu pakai kamera sebelum Visit In.',
            };
            return;
        }

        if (visitInNotesRequired && notes.trim().length < 10) {
            feedback = {
                type: 'error',
                message:
                    'Tulis alasan Visit In minimal 10 karakter.',
            };
            return;
        }

        submitting = true;
        feedback = null;

        try {
            // PRD v8: token comes from the initial fix or Muat Ulang GPS, never silently at submit.
            const fresh = gps;

            if (
                fresh === null ||
                fresh.gpsSessionToken === null ||
                fresh.lat === null ||
                fresh.lng === null
            ) {
                feedback = {
                    type: 'error',
                    message: 'Lokasi GPS belum siap. Muat ulang GPS.',
                };
                return;
            }

            if (
                fresh.accuracyM !== null &&
                (fresh.accuracyM > selectedOutlet.radius_m ||
                    fresh.accuracyM > 100) &&
                notes.trim().length < 10
            ) {
                feedback = {
                    type: 'error',
                    message:
                        'Sinyal GPS lemah. Tulis alasan Visit In minimal 10 karakter.',
                };
                return;
            }

            if (pendingVisitInKey === null) {
                pendingVisitInKey = crypto.randomUUID();
            }

            const form = new FormData();
            form.append('idempotency_key', pendingVisitInKey);
            form.append('outlet_location_id', String(selectedOutlet.id));
            form.append('lat', String(fresh.lat));
            form.append('lng', String(fresh.lng));
            form.append('accuracy_m', String(fresh.accuracyM ?? 0));
            form.append('gps_session_token', fresh.gpsSessionToken);
            form.append('camera_token', capturedPhoto.cameraToken);
            form.append('photo', capturedPhoto.blob, 'visit-in.jpg');
            if (notes.trim() !== '') {
                form.append('notes', notes.trim());
            }

            const res = await fetch('/attendance/sales/visit-in', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'X-XSRF-TOKEN': readXsrfToken(),
                },
                body: form,
            });

            const data = await readJson(res);

            if (!res.ok) {
                feedback = {
                    type: 'error',
                    message: requestErrorMessage(res, data, 'Visit In gagal.'),
                };
                return;
            }

            pendingVisitInKey = null;
            capturedPhoto = null;
            cameraKey += 1;
            notes = '';
            selectedOutlet = null;
            visitInSheetOpen = false;
            feedback = {
                type: 'success',
                message: 'Visit In berhasil dicatat.',
            };
            router.reload();
        } catch {
            feedback = {
                type: 'error',
                message: 'Koneksi bermasalah. Silakan coba lagi.',
            };
        } finally {
            submitting = false;
        }
    }

    async function submitVisitOut() {
        if (submitting || activeVisit === null) {
            return;
        }

        if (selectedCategoryId === '') {
            feedback = {
                type: 'error',
                message: 'Pilih kategori aktivitas terlebih dahulu.',
            };
            return;
        }

        const minVisitOutNotes = visitOutPoorAccuracy ? 10 : 3;
        if (notes.trim().length < minVisitOutNotes) {
            feedback = {
                type: 'error',
                message: visitOutPoorAccuracy
                    ? 'Sinyal GPS lemah. Tulis catatan kunjungan minimal 10 karakter.'
                    : 'Catatan kunjungan wajib diisi minimal 3 karakter.',
            };
            return;
        }

        submitting = true;
        feedback = null;

        try {
            const fresh = gps;

            if (
                fresh === null ||
                fresh.gpsSessionToken === null ||
                fresh.lat === null ||
                fresh.lng === null
            ) {
                feedback = {
                    type: 'error',
                    message: 'Lokasi GPS belum siap. Muat ulang GPS.',
                };
                return;
            }
            if (pendingVisitOutKey === null) {
                pendingVisitOutKey = crypto.randomUUID();
            }

            const res = await fetch(
                `/attendance/sales/${activeVisit.id}/visit-out`,
                {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                        'X-XSRF-TOKEN': readXsrfToken(),
                    },
                    body: JSON.stringify({
                        idempotency_key: pendingVisitOutKey,
                        activity_category_id: selectedCategoryId,
                        visit_notes: notes.trim(),
                        lat: fresh.lat,
                        lng: fresh.lng,
                        accuracy_m: fresh.accuracyM ?? 0,
                        gps_session_token: fresh.gpsSessionToken,
                    }),
                },
            );

            const data = await readJson(res);

            if (!res.ok) {
                feedback = {
                    type: 'error',
                    message: requestErrorMessage(res, data, 'Visit Out gagal.'),
                };
                return;
            }

            pendingVisitOutKey = null;
            notes = '';
            selectedCategoryId = '';
            visitOutSheetOpen = false;
            feedback = {
                type: 'success',
                message: 'Visit Out berhasil dicatat.',
            };
            router.reload();
        } catch {
            feedback = {
                type: 'error',
                message: 'Koneksi bermasalah. Silakan coba lagi.',
            };
        } finally {
            submitting = false;
        }
    }

    function openCancelSheet() {
        visitOutSheetOpen = false;
        cancelReason = '';
        cancelSheetOpen = true;
        feedback = null;
    }

    async function cancelActiveVisit() {
        if (activeVisit === null || submitting) {
            return;
        }

        if (cancelReason.trim().length < 10) {
            feedback = {
                type: 'error',
                message: 'Alasan pembatalan wajib diisi minimal 10 karakter.',
            };
            return;
        }

        submitting = true;
        feedback = null;

        try {
            const res = await fetch(
                `/attendance/sales/${activeVisit.id}/cancel`,
                {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                        'X-XSRF-TOKEN': readXsrfToken(),
                    },
                    body: JSON.stringify({ cancel_reason: cancelReason.trim() }),
                },
            );

            const data = await readJson(res);

            if (!res.ok) {
                feedback = {
                    type: 'error',
                    message: requestErrorMessage(
                        res,
                        data,
                        'Gagal membatalkan Visit In.',
                    ),
                };
                return;
            }

            cancelSheetOpen = false;
            feedback = {
                type: 'success',
                message: 'Visit In dibatalkan.',
            };
            router.reload();
        } catch {
            feedback = {
                type: 'error',
                message: 'Koneksi bermasalah. Silakan coba lagi.',
            };
        } finally {
            submitting = false;
        }
    }
</script>

<AppHead title="Kunjungan Sales" />

{#snippet gpsBox(radius: number | null, distance: number | null, meta: { label: string; className: string })}
    {@const accuracy = gps?.accuracyM ?? null}
    <div class="rounded-md border border-stone-200 bg-stone-50 p-2.5">
        <div class="flex items-center justify-between gap-2">
            <p class="flex items-center gap-1 text-xs font-semibold text-stone-800">
                <MapPin class="size-3.5" />
                GPS
            </p>
            <Button
                type="button"
                size="sm"
                variant="outline"
                class="h-11"
                disabled={refreshingGps || outletsLoading || submitting}
                onclick={refreshGpsAndOutlets}
            >
                <RefreshCw class="size-3.5 {refreshingGps ? 'animate-spin' : ''}" />
                Muat Ulang
            </Button>
        </div>
        <p class="mt-1.5 font-mono text-[11px] tabular-nums text-stone-600">
            Jarak {distance !== null ? `${Math.round(distance)} m` : '-'} · Akurasi {accuracy !== null ? `${Math.round(accuracy)} m` : '-'} · Batas {radius !== null ? `${radius} m` : '-'}
        </p>
        <div class="mt-2 grid grid-cols-3 gap-1.5 text-center">
            {#each [['Jarak', distance], ['Akurasi', accuracy], ['Radius', radius]] as [label, value] (label)}
                <div class="rounded border border-stone-200 bg-white p-1.5">
                    <p class="text-[10px] text-stone-500">{label}</p>
                    <p class="font-mono text-xs font-semibold tabular-nums text-stone-900">
                        {value !== null ? `${Math.round(Number(value))} m` : '-'}
                    </p>
                </div>
            {/each}
        </div>
        <p class="mt-2 text-[11px] leading-snug {meta.className}">{meta.label}</p>
        {#if gps?.message && gps.status === 'POOR_ACCURACY'}
            <p class="mt-1 text-[11px] leading-snug text-yellow-800">{gps.message}</p>
        {/if}
    </div>
{/snippet}

<div class="flex flex-col gap-3">
    {#if inAppWarning}
        <div
            role="alert"
            class="rounded-lg border border-red-300 bg-red-50 p-3 text-[13px] text-red-800"
        >
            <p class="flex items-center gap-1.5 font-semibold">
                <AlertTriangle class="size-4 shrink-0" />
                Buka di browser utama
            </p>
            <p class="mt-1 leading-snug">{recommendedExternalBrowserInstruction()}</p>
        </div>
    {/if}

    <div class="grid grid-cols-2 gap-2">
        <Button
            type="button"
            class="min-h-12"
            variant={canVisitIn ? 'default' : 'outline'}
            disabled={!canVisitIn}
            onclick={openVisitInSheet}
        >
            Visit In
        </Button>
        <Button
            type="button"
            class="min-h-12"
            variant={canVisitOut ? 'default' : 'outline'}
            disabled={!canVisitOut}
            onclick={openVisitOutSheet}
        >
            Visit Out
        </Button>
    </div>

    {#if feedback && !visitInSheetOpen && !visitOutSheetOpen && !cancelSheetOpen}
        <div
            role={feedback.type === 'error' ? 'alert' : 'status'}
            class="rounded-lg border p-3 text-[13px] {feedback.type === 'error'
                ? 'border-red-300 bg-red-50 text-red-800'
                : 'border-green-300 bg-green-50 text-green-800'}"
        >
            <p class="flex items-start gap-1.5">
                {#if feedback.type === 'error'}
                    <AlertTriangle class="mt-0.5 size-4 shrink-0" />
                {:else}
                    <CircleCheck class="mt-0.5 size-4 shrink-0" />
                {/if}
                <span>{feedback.message}</span>
            </p>
        </div>
    {/if}

    <section class="rounded-xl border border-stone-200 bg-white p-3 shadow-xs">
        <div class="flex items-start justify-between gap-2">
            <div class="min-w-0">
                <p class="text-[11px] font-medium uppercase tracking-wide text-stone-500">
                    Status Kunjungan
                </p>
                <p class="mt-0.5 text-sm font-semibold text-stone-900">{dateLabel}</p>
            </div>
            <span class={summaryMeta.className}>{summaryMeta.label}</span>
        </div>

        {#if isDayOff}
            <div class="mt-2 rounded-md border border-stone-300 bg-stone-100 p-2 text-[11px] leading-snug text-stone-700">
                Hari ini jadwal libur Anda. Anda tetap bisa Visit In dan akan dicek HR.
            </div>
        {/if}

        {#if activeVisit}
            <div class="mt-3 space-y-2 border-t border-stone-200 pt-2.5">
                <div class="flex items-start justify-between gap-2">
                    <div class="min-w-0">
                        <p class="text-sm font-semibold text-stone-900">{activeVisit.outlet_name}</p>
                        <p class="text-[11px] text-stone-500">
                            {activeVisit.outlet_code ?? 'Toko baru'} · {activeVisit.outlet_address}
                        </p>
                    </div>
                    {#if activeVerification}
                        <span class={activeVerification.className}>{activeVerification.label}</span>
                    {/if}
                </div>
                <div class="grid grid-cols-2 gap-2">
                    <div class="rounded-md border border-stone-200 bg-stone-50 p-2">
                        <p class="text-[11px] text-stone-500">Visit In</p>
                        <p class="font-mono text-sm font-semibold tabular-nums text-stone-900">
                            {activeVisit.visit_in_time ?? '-'} {timezoneLabel(activeVisit.outlet_timezone)}
                        </p>
                    </div>
                    <div class="rounded-md border border-stone-200 bg-stone-50 p-2">
                        <p class="text-[11px] text-stone-500">Durasi</p>
                        <p class="font-mono text-sm font-semibold tabular-nums text-stone-900">{elapsedLabel}</p>
                    </div>
                </div>
                {#if activeVisit.reject_reason}
                    <p class="rounded-md border border-red-300 bg-red-50 p-2 text-xs text-red-800">
                        Ditolak HR: {activeVisit.reject_reason}
                    </p>
                {/if}
                {#if canCancelNow}
                    <Button
                        type="button"
                        variant="secondary"
                        class="min-h-11 w-full"
                        disabled={submitting}
                        onclick={openCancelSheet}
                    >
                        Batalkan Visit In
                    </Button>
                    <p class="text-[11px] text-stone-500">
                        Sisa kuota batal hari ini:
                        <span class="font-mono tabular-nums">{activeVisit.cancel_remaining_today}/3</span>
                    </p>
                {:else if activeVisit.cancel_remaining_today === 0}
                    <p class="text-[11px] text-stone-500">Kuota pembatalan Visit In hari ini sudah habis.</p>
                {:else}
                    <p class="text-[11px] text-stone-500">Batas pembatalan Visit In (5 menit) sudah terlewati.</p>
                {/if}
            </div>
        {/if}
    </section>

    {#if hasApprovedTimeOff}
        <section class="rounded-lg border border-stone-300 bg-stone-100 p-4 text-sm text-stone-700">
            <p class="font-semibold text-stone-900">Kunjungan dikunci</p>
            <p class="mt-1 leading-snug">
                Anda sedang Cuti, Izin, atau Sakit hari ini, jadi kunjungan tidak diperlukan.
                {#if activeVisit}Anda tetap bisa Visit Out.{/if}
            </p>
        </section>
    {/if}

    {#if rejectedTodayVisits.length > 0}
        <section class="rounded-xl border border-red-300 bg-red-50 p-3 text-red-800">
            <p class="flex items-center gap-1.5 text-sm font-semibold">
                <AlertTriangle class="size-4 shrink-0" />
                Kunjungan Hari Ini Ditolak HR
            </p>
            {#each rejectedTodayVisits as rejected (rejected.id)}
                <div class="mt-1.5 border-t border-red-200 pt-1.5 first:border-t-0 first:pt-0">
                    <p class="text-sm font-medium">{rejected.outlet_name}</p>
                    {#if rejected.reject_reason}
                        <p class="text-xs">Alasan: {rejected.reject_reason}</p>
                    {/if}
                </div>
            {/each}
            <p class="mt-1.5 text-[11px] text-red-700">
                Anda tetap bisa lanjut ke toko lain, atau hubungi HR untuk koreksi.
            </p>
        </section>
    {/if}

    {#if rejectedOutlets.length > 0}
        <section class="rounded-xl border border-red-300 bg-red-50 p-3 text-red-800">
            <p class="flex items-center gap-1.5 text-sm font-semibold">
                <AlertTriangle class="size-4 shrink-0" />
                Pengajuan Toko Ditolak HR
            </p>
            {#each rejectedOutlets as rejected (rejected.id)}
                <div class="mt-1.5 border-t border-red-200 pt-1.5 first:border-t-0 first:pt-0">
                    <p class="text-sm font-medium">{rejected.name}</p>
                    <p class="text-xs">{rejected.address}</p>
                    {#if rejected.reject_reason}
                        <p class="text-xs">Alasan: {rejected.reject_reason}</p>
                    {/if}
                </div>
            {/each}
        </section>
    {/if}

    <section class="rounded-xl border border-stone-200 bg-white p-3 shadow-xs">
        <div class="flex items-center justify-between gap-2">
            <h2 class="text-sm font-semibold text-stone-900">Aktivitas Hari Ini</h2>
            <span class="text-[11px] text-stone-500">{todayVisits.length} catatan</span>
        </div>
        {#if todayVisits.length === 0}
            <p class="mt-2 text-[11px] text-stone-500">Belum ada kunjungan hari ini.</p>
        {:else}
            <div class="mt-2 divide-y divide-stone-100">
                {#each todayVisits as visit (visit.id)}
                    {@const statusMeta = visitStatusMeta(visit.visit_status)}
                    {@const verifMeta = verificationMeta(visit.verification_status)}
                    <div class="py-2 first:pt-0 last:pb-0">
                        <div class="flex flex-wrap items-center gap-1">
                            <span class={statusMeta.className}>{statusMeta.label}</span>
                            <span class={verifMeta.className}>{verifMeta.label}</span>
                            {#if visit.source === 'MANUAL_HR'}
                                <span class="{badgeBase} {toneNeutral}">Input Manual HR</span>
                            {/if}
                        </div>
                        <p class="mt-1 text-xs font-medium text-stone-800">{visit.outlet_name}</p>
                        <p class="text-[11px] text-stone-500">
                            {visit.outlet_code ?? 'Toko baru'} · {visit.outlet_address}
                        </p>
                        <p class="mt-0.5 font-mono text-[11px] tabular-nums text-stone-600">
                            In {visit.visit_in_time ?? '-'} {timezoneLabel(visit.outlet_timezone)} · Out {visit.visit_out_time ?? '-'} · {durationLabel(visit)}
                        </p>
                        {#if visit.activity_category_name}
                            <p class="text-[11px] text-stone-500">Kategori: {visit.activity_category_name}</p>
                        {/if}
                        {#if visit.visit_notes ?? visit.visit_in_notes}
                            <p class="text-[11px] text-stone-500">{visit.visit_notes ?? visit.visit_in_notes}</p>
                        {/if}
                        {#if visit.cancel_reason}
                            <p class="text-[11px] text-stone-500">Alasan batal: {visit.cancel_reason}</p>
                        {/if}
                        {#if visit.reject_reason}
                            <p class="text-[11px] text-red-700">Ditolak HR: {visit.reject_reason}</p>
                        {/if}
                    </div>
                {/each}
            </div>
        {/if}
        <Link
            href="/attendance/history"
            class="mt-2 flex min-h-11 items-center justify-center rounded-md border border-stone-300 text-[12px] font-medium text-stone-700 hover:bg-stone-50"
        >
            Lihat Riwayat Lainnya
        </Link>
    </section>

    <Sheet bind:open={visitInSheetOpen}>
        <SheetContent
            side="bottom"
            class="max-h-[92dvh] overflow-y-auto rounded-t-xl p-4 pb-[calc(1rem_+_env(safe-area-inset-bottom))]"
        >
            <h2 class="pr-8 text-sm font-semibold text-stone-900">Visit In</h2>

            <div class="mt-3 flex flex-col gap-3">
                <div class="flex gap-2">
                    <Input
                        class="h-11"
                        bind:value={searchQuery}
                        maxlength={100}
                        placeholder="Cari nama, kode, atau alamat toko..."
                        onkeydown={(event) => {
                            if (event.key === 'Enter') {
                                event.preventDefault();
                                void loadOutlets();
                            }
                        }}
                    />
                    <Button
                        type="button"
                        variant="secondary"
                        class="h-11"
                        disabled={outletsLoading}
                        onclick={loadOutlets}
                    >
                        {outletsLoading ? 'Memuat…' : 'Cari'}
                    </Button>
                </div>

                {#if recentOutlets.length > 0}
                    <div>
                        <p class="mb-1 text-xs font-medium text-stone-500">Toko terakhir dikunjungi</p>
                        <div class="flex gap-1.5 overflow-x-auto">
                            {#each recentOutlets.slice(0, 5) as outlet (outlet.id)}
                                <Button
                                    type="button"
                                    size="sm"
                                    variant={selectedOutlet?.id === outlet.id ? 'default' : 'outline'}
                                    class="shrink-0"
                                    onclick={() => {
                                        selectedOutlet =
                                            searchResults.find((o) => o.id === outlet.id) ?? outlet;
                                        pendingVisitInKey = null;
                                    }}
                                >
                                    {outlet.name}
                                </Button>
                            {/each}
                        </div>
                    </div>
                {/if}

                {#if outletsLoading && searchResults.length === 0}
                    <p class="text-sm text-stone-500">Memuat daftar toko…</p>
                {:else if searchResults.length === 0}
                    <p class="text-sm text-stone-500">
                        Toko tidak ditemukan. Cek kata kunci atau ajukan toko baru di bawah.
                    </p>
                {:else}
                    <div class="max-h-56 space-y-1.5 overflow-y-auto">
                        {#each searchResults as outlet (outlet.id)}
                            <button
                                type="button"
                                class="w-full rounded-md border p-2 text-left text-sm {selectedOutlet?.id === outlet.id
                                    ? 'border-primary bg-primary/5'
                                    : 'border-stone-200'}"
                                onclick={() => {
                                    selectedOutlet = outlet;
                                    pendingVisitInKey = null;
                                }}
                            >
                                <div class="flex justify-between gap-2 font-medium">
                                    <span>{outlet.name}</span>
                                    {#if outlet.distance_m !== null}
                                        <span class="shrink-0 font-mono text-xs tabular-nums text-stone-500">
                                            ±{Math.round(outlet.distance_m)} m
                                        </span>
                                    {/if}
                                </div>
                                <p class="text-xs text-stone-500">
                                    {outlet.code ?? 'Toko baru'} · Radius
                                    <span class="font-mono tabular-nums">{outlet.radius_m} m</span>
                                    · {outletStatusLabel(outlet.status)}
                                </p>
                                <p class="text-xs text-stone-500">{outlet.address}</p>
                            </button>
                        {/each}
                    </div>
                {/if}

                <Button
                    type="button"
                    variant="outline"
                    class="min-h-11 w-full"
                    onclick={() => {
                        nooOpen = !nooOpen;
                        nooNearby = null;
                        feedback = null;
                    }}
                >
                    {nooOpen ? 'Tutup Form Toko Baru' : 'Ajukan Toko Baru'}
                </Button>

                {#if nooOpen}
                    <div class="flex flex-col gap-2 rounded-md border border-stone-200 p-3">
                        <Input bind:value={nooName} maxlength={150} placeholder="Nama toko" />
                        <textarea
                            bind:value={nooAddress}
                            rows="2"
                            maxlength="500"
                            placeholder="Alamat toko"
                            class="w-full rounded-md border border-stone-300 bg-white p-2 text-sm"
                        ></textarea>
                        <select
                            bind:value={nooTimezone}
                            class="min-h-11 rounded-md border border-stone-300 bg-white px-3 text-sm"
                        >
                            <option value="Asia/Jakarta">WIB</option>
                            <option value="Asia/Makassar">WITA</option>
                        </select>
                        <p class="text-xs text-stone-500">
                            Lokasi toko dikunci dari GPS saat kirim (akurasi wajib 50 m atau lebih baik).
                        </p>
                        {#if gps !== null && gps.accuracyM !== null && gps.accuracyM <= 50}
                            <p class="rounded-md border border-green-300 bg-green-50 p-2 text-xs text-green-700">
                                Akurasi GPS
                                <span class="font-mono font-semibold tabular-nums">±{Math.round(gps.accuracyM)} m</span>,
                                siap kunci lokasi.
                            </p>
                        {:else}
                            <div class="flex flex-col gap-2 rounded-md border border-yellow-300 bg-yellow-50 p-2 text-xs text-yellow-800">
                                <p>
                                    {#if gps?.accuracyM != null}
                                        Akurasi GPS saat ini
                                        <span class="font-mono font-semibold tabular-nums">±{Math.round(gps.accuracyM)} m</span>
                                        (lebih dari 50 m). Pindah ke area terbuka lalu muat ulang GPS.
                                    {:else}
                                        Lokasi GPS belum siap. Aktifkan GPS lalu muat ulang.
                                    {/if}
                                </p>
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="outline"
                                    class="min-h-11 w-full bg-white"
                                    disabled={refreshingGps || submitting}
                                    onclick={refreshGps}
                                >
                                    {refreshingGps ? 'Memuat…' : 'Muat Ulang GPS'}
                                </Button>
                            </div>
                        {/if}

                        {#if nooNearby !== null && nooNearby.length > 0}
                            <div class="rounded-md border border-yellow-300 bg-yellow-50 p-2.5 text-xs text-yellow-900">
                                <p class="font-semibold">Ada toko lain dalam radius 100 m:</p>
                                <ul class="mt-1 list-disc space-y-0.5 pl-4">
                                    {#each nooNearby as near (near.id)}
                                        <li><span class="font-medium">{near.name}</span>: {near.address}</li>
                                    {/each}
                                </ul>
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="outline"
                                    class="mt-2 min-h-11 w-full bg-white"
                                    disabled={submitting || inAppWarning}
                                    onclick={() => submitNoo(true)}
                                >
                                    Tetap Ajukan Toko Baru
                                </Button>
                            </div>
                        {/if}

                        <Button
                            type="button"
                            class="min-h-12 w-full"
                            disabled={submitting || inAppWarning}
                            onclick={() => submitNoo(false)}
                        >
                            Kirim Pengajuan Toko Baru
                        </Button>
                    </div>
                {/if}

                {@render gpsBox(
                    selectedOutlet?.radius_m ?? null,
                    selectedOutlet?.distance_m ?? null,
                    outletLocationMeta,
                )}

                <div>
                    <div class="mb-1.5 flex items-center justify-between gap-2">
                        <p class="text-xs font-semibold text-stone-800">
                            Foto Selfie
                            <span class="font-normal text-stone-500">(kamera langsung)</span>
                        </p>
                        {#if capturedPhoto !== null}
                            <span class="text-[11px] font-medium text-green-700">
                                Siap · {Math.round(capturedPhoto.blob.size / 1024)} KB
                            </span>
                        {/if}
                    </div>
                    {#key cameraKey}
                        <LiveCameraCapture
                            facingMode="user"
                            autoStart
                            disabled={submitting}
                            onCapture={(payload) => {
                                capturedPhoto = payload;
                                pendingVisitInKey = null;
                                feedback = null;
                            }}
                        />
                    {/key}
                </div>

                <div>
                    <div class="flex items-center justify-between gap-2">
                        <label for="visit-in-notes" class="text-xs font-semibold text-stone-800">
                            Catatan / Alasan
                        </label>
                        {#if visitInNotesRequired}
                            <span
                                class="font-mono text-[11px] font-medium tabular-nums {notes.trim().length >= 10
                                    ? 'text-green-700'
                                    : 'text-red-600'}"
                            >
                                Wajib ({notes.trim().length}/10 karakter)
                            </span>
                        {:else}
                            <span class="text-[11px] text-stone-500">Opsional</span>
                        {/if}
                    </div>
                    <textarea
                        id="visit-in-notes"
                        bind:value={notes}
                        rows="2"
                        maxlength="500"
                        placeholder={visitInNotesRequired
                            ? 'Tulis alasan Visit In (min. 10 karakter)...'
                            : 'Tulis catatan tambahan (opsional)...'}
                        class="mt-1 w-full rounded-md border border-stone-300 bg-white p-2 text-sm leading-snug text-stone-900 placeholder:text-stone-400 focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20"
                    ></textarea>
                </div>

                {#if feedback?.type === 'error'}
                    <p role="alert" class="rounded-md bg-destructive/10 p-2 text-sm text-destructive">
                        {feedback.message}
                    </p>
                {/if}

                <Button
                    type="button"
                    class="min-h-12 w-full"
                    disabled={selectedOutlet === null || submitting || visitActionBlocked || isOutletHardBlocked}
                    onclick={submitVisitIn}
                >
                    {#if submitting}
                        Memproses…
                    {:else if selectedOutlet !== null}
                        Visit In ke {selectedOutlet.name}
                    {:else}
                        Pilih toko dulu
                    {/if}
                </Button>
            </div>
        </SheetContent>
    </Sheet>

    {#if activeVisit}
        <Sheet bind:open={visitOutSheetOpen}>
            <SheetContent
                side="bottom"
                class="max-h-[92dvh] overflow-y-auto rounded-t-xl p-4 pb-[calc(1rem_+_env(safe-area-inset-bottom))]"
            >
                <h2 class="pr-8 text-sm font-semibold text-stone-900">Visit Out</h2>
                <div class="mt-3 flex flex-col gap-3">
                    <div>
                        <p class="text-sm font-semibold text-stone-900">{activeVisit.outlet_name}</p>
                        <p class="text-[11px] text-stone-500">
                            {activeVisit.outlet_code ?? 'Toko baru'} · {activeVisit.outlet_address}
                        </p>
                    </div>
                    <div class="grid grid-cols-2 gap-2">
                        <div class="rounded-md border border-stone-200 bg-stone-50 p-2">
                            <p class="text-[11px] text-stone-500">Visit In</p>
                            <p class="font-mono text-sm font-semibold tabular-nums text-stone-900">
                                {activeVisit.visit_in_time ?? '-'} {timezoneLabel(activeVisit.outlet_timezone)}
                            </p>
                        </div>
                        <div class="rounded-md border border-stone-200 bg-stone-50 p-2">
                            <p class="text-[11px] text-stone-500">Durasi</p>
                            <p class="font-mono text-sm font-semibold tabular-nums text-stone-900">{elapsedLabel}</p>
                        </div>
                    </div>

                    {@render gpsBox(
                        activeVisit.outlet_radius_m,
                        null,
                        visitOutPoorAccuracy
                            ? { label: 'Sinyal GPS lemah. Wajib isi catatan minimal 10 karakter.', className: 'font-medium text-yellow-800' }
                            : gpsUnavailable
                              ? { label: 'GPS tidak tersedia. Aktifkan GPS lalu muat ulang.', className: 'font-medium text-red-700' }
                              : { label: 'Lokasi siap dikirim.', className: 'font-medium text-green-700' },
                    )}

                    <div>
                        <label for="visit-out-category" class="text-xs font-semibold text-stone-800">
                            Kategori Aktivitas
                        </label>
                        <select
                            id="visit-out-category"
                            bind:value={selectedCategoryId}
                            class="mt-1 min-h-11 w-full rounded-md border border-stone-300 bg-white px-3 text-sm"
                        >
                            <option value="">Pilih Kategori Aktivitas...</option>
                            {#each categories as cat (cat.id)}
                                <option value={cat.id}>{cat.name}</option>
                            {/each}
                        </select>
                    </div>

                    <div>
                        <label for="visit-out-notes" class="text-xs font-semibold text-stone-800">
                            Ringkasan hasil kunjungan
                        </label>
                        <textarea
                            id="visit-out-notes"
                            bind:value={notes}
                            rows="3"
                            maxlength="1000"
                            placeholder={visitOutPoorAccuracy
                                ? 'Ringkasan hasil kunjungan dan alasan sinyal GPS lemah (min. 10 karakter)'
                                : 'Ringkasan hasil kunjungan (min. 3 karakter)'}
                            class="mt-1 w-full rounded-md border border-stone-300 bg-white p-2 text-sm leading-snug text-stone-900 placeholder:text-stone-400 focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20"
                        ></textarea>
                        {#if visitOutPoorAccuracy}
                            <p class="mt-1 font-mono text-[11px] tabular-nums text-yellow-800">
                                ({notes.trim().length}/10 karakter)
                            </p>
                        {/if}
                    </div>

                    {#if feedback?.type === 'error'}
                        <p role="alert" class="rounded-md bg-destructive/10 p-2 text-sm text-destructive">
                            {feedback.message}
                        </p>
                    {/if}

                    <Button
                        type="button"
                        class="min-h-12 w-full"
                        disabled={submitting || visitActionBlocked}
                        onclick={submitVisitOut}
                    >
                        {submitting ? 'Memproses…' : 'Simpan Visit Out'}
                    </Button>

                    {#if canCancelNow}
                        <Button
                            type="button"
                            variant="outline"
                            class="min-h-12 w-full"
                            disabled={submitting}
                            onclick={openCancelSheet}
                        >
                            Batalkan Visit In
                        </Button>
                    {/if}
                </div>
            </SheetContent>
        </Sheet>

        <Sheet bind:open={cancelSheetOpen}>
            <SheetContent
                side="bottom"
                class="max-h-[92dvh] overflow-y-auto rounded-t-xl p-4 pb-[calc(1rem_+_env(safe-area-inset-bottom))]"
            >
                <h2 class="text-base font-semibold">Batalkan Visit In</h2>
                <p class="text-sm text-muted-foreground">{activeVisit.outlet_name}</p>
                <p class="text-xs text-muted-foreground">
                    Sisa kuota batal hari ini:
                    <span class="font-mono tabular-nums">{activeVisit.cancel_remaining_today ?? 0}/3</span>
                </p>
                <textarea
                    bind:value={cancelReason}
                    rows="3"
                    maxlength="255"
                    placeholder="Alasan pembatalan (min. 10 karakter)"
                    class="mt-2 w-full rounded-md border bg-background p-2 text-sm"
                ></textarea>
                {#if feedback?.type === 'error'}
                    <p role="alert" class="mt-2 rounded-md bg-destructive/10 p-2 text-sm text-destructive">
                        {feedback.message}
                    </p>
                {/if}
                <Button
                    type="button"
                    variant="destructive"
                    class="mt-2 min-h-12 w-full"
                    disabled={submitting}
                    onclick={cancelActiveVisit}
                >
                    Konfirmasi Pembatalan
                </Button>
            </SheetContent>
        </Sheet>
    {/if}
</div>
