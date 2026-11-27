<script lang="ts">
    import { onDestroy, onMount } from 'svelte';
    import { SvelteURLSearchParams } from 'svelte/reactivity';
    import { router } from '@inertiajs/svelte';
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
    let cancelSheetOpen = $state(false);
    let submitting = $state(false);
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

    const visitInNotesRequired = $derived.by(() => {
        if (selectedOutlet === null) {
            return false;
        }

        if (selectedOutlet.status !== 'ACTIVE') {
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

    const activeVerification = $derived(
        activeVisit !== null ? verificationMeta(activeVisit.verification_status) : null,
    );

    function formatDate(value: string): string {
        const parts = value.split('-');
        if (parts.length !== 3) {
            return value;
        }

        const [year, month, day] = parts;
        if (!year || !month || !day) {
            return value;
        }

        return `${day}-${month}-${year}`;
    }

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

        return visit.visit_status === 'IN_PROGRESS' ? 'Berjalan' : '—';
    }

    function verificationMeta(status: VerificationStatus | null): {
        label: string;
        className: string;
    } {
        switch (status) {
            case 'AUTO_APPROVED':
                return {
                    label: 'Sah Otomatis',
                    className: `${badgeBase} ${toneSuccess}`,
                };
            case 'APPROVED':
                return {
                    label: 'Disetujui',
                    className: `${badgeBase} ${toneSuccess}`,
                };
            case 'PENDING_REVIEW':
                return {
                    label: 'Menunggu Review',
                    className: `${badgeBase} ${toneWarning}`,
                };
            case 'REJECTED':
                return {
                    label: 'Ditolak',
                    className: `${badgeBase} ${toneError}`,
                };
            default:
                return { label: '—', className: `${badgeBase} ${toneNeutral}` };
        }
    }

    function visitStatusMeta(status: VisitStatus): {
        label: string;
        className: string;
    } {
        switch (status) {
            case 'IN_PROGRESS':
                return { label: 'Berjalan', className: `${badgeBase} ${toneInfo}` };
            case 'COMPLETED':
                return { label: 'Selesai', className: `${badgeBase} ${toneSuccess}` };
            case 'CANCELLED':
                return { label: 'Dibatalkan', className: `${badgeBase} ${toneNeutral}` };
            case 'AUTO_CLOSED':
                return {
                    label: 'Ditutup Otomatis',
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
                        'Akurasi GPS wajib ≤ 50 m untuk mengajukan toko baru. Cari area terbuka lalu tekan Muat Ulang GPS.',
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
                        'Sudah ada toko dalam radius 100 m. Periksa daftar berikut sebelum tetap mengajukan.',
                };
                return;
            }

            if (!res.ok) {
                feedback = {
                    type: 'error',
                    message: requestErrorMessage(res, data, 'Pengajuan NOO gagal.'),
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
                    'Pengajuan toko baru terkirim. Menunggu verifikasi HR.',
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
                message: 'Visit In wajib ambil 1x foto selfie kamera langsung.',
            };
            return;
        }

        if (visitInNotesRequired && notes.trim() === '') {
            feedback = {
                type: 'error',
                message: 'Alasan Visit In wajib diisi untuk toko atau kondisi ini.',
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
                notes.trim() === ''
            ) {
                feedback = {
                    type: 'error',
                    message:
                        'Alasan Visit In wajib diisi karena akurasi GPS rendah.',
                };
                return;
            }

            const form = new FormData();
            form.append('idempotency_key', crypto.randomUUID());
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

            capturedPhoto = null;
            cameraKey += 1;
            notes = '';
            selectedOutlet = null;
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

        if (notes.trim().length < 3) {
            feedback = {
                type: 'error',
                message: 'Catatan kunjungan wajib diisi minimal 3 karakter.',
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
                        idempotency_key: crypto.randomUUID(),
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

            notes = '';
            selectedCategoryId = '';
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

<div class="flex flex-col gap-3">
    {#if inAppWarning}
        <div
            class="rounded-lg border border-amber-500/50 bg-amber-50 p-3 text-sm text-amber-900"
        >
            {recommendedExternalBrowserInstruction()}
        </div>
    {/if}

    <div class="rounded-xl border bg-card p-4">
        <p class="text-xs text-muted-foreground">
            Kunjungan Sales · {formatDate(todayDate)} · {timezoneLabel(
                homeTimezone,
            )}
        </p>
        <div class="mt-2 flex items-center justify-between gap-2">
            <span class="text-sm font-medium">
                GPS:
                {#if gps?.status === 'OK'}
                    Akurat (±{Math.round(gps.accuracyM ?? 0)} m)
                {:else if gps?.status === 'POOR_ACCURACY'}
                    Kurang akurat (±{Math.round(gps?.accuracyM ?? 0)} m)
                {:else if gps?.status === 'PERMISSION_DENIED' || gps?.status === 'GPS_UNAVAILABLE'}
                    Tidak tersedia
                {:else}
                    Belum siap
                {/if}
            </span>
            <Button
                type="button"
                size="sm"
                variant="outline"
                disabled={refreshingGps || outletsLoading || submitting}
                onclick={refreshGpsAndOutlets}
            >
                {refreshingGps || outletsLoading ? 'Memuat…' : 'Muat Ulang GPS'}
            </Button>
        </div>
        {#if gps?.message}
            <p class="mt-1 text-xs text-muted-foreground">{gps.message}</p>
        {/if}
        {#if selectedOutlet !== null && selectedOutlet.distance_m !== null}
            <p class="mt-1 text-xs text-muted-foreground">
                Jarak ke toko: ±{Math.round(selectedOutlet.distance_m)} m
            </p>
        {/if}
    </div>

    {#if rejectedOutlets.length > 0}
        <div class="flex flex-col gap-1 rounded-xl border bg-card p-4">
            <p class="text-xs font-medium text-muted-foreground">
                Pengajuan Toko Ditolak HR
            </p>
            {#each rejectedOutlets as rejected (rejected.id)}
                <div class="border-t pt-1 first:border-t-0 first:pt-0">
                    <p class="text-sm font-medium">{rejected.name}</p>
                    <p class="text-xs text-muted-foreground">
                        {rejected.address}
                    </p>
                    {#if rejected.reject_reason}
                        <p class="text-xs text-destructive">
                            Alasan: {rejected.reject_reason}
                        </p>
                    {/if}
                </div>
            {/each}
        </div>
    {/if}

    {#if activeVisit}
        <div class="flex flex-col gap-3 rounded-xl border bg-card p-4">
            <div class="flex items-center justify-between gap-2">
                <p class="text-xs font-medium text-blue-600">
                    KUNJUNGAN BERJALAN
                </p>
                {#if activeVerification}
                    <span class={activeVerification.className}>
                        {activeVerification.label}
                    </span>
                {/if}
            </div>
            <div>
                <h2 class="text-base font-semibold">{activeVisit.outlet_name}</h2>
                <p class="text-xs text-muted-foreground">
                    {activeVisit.outlet_code ?? 'NOO'} · {activeVisit.outlet_address}
                </p>
            </div>

            <div class="grid grid-cols-2 gap-2">
                <div class="rounded-md border bg-muted/40 p-2">
                    <p class="text-[11px] text-muted-foreground">Visit In</p>
                    <p class="text-sm font-medium">
                        {activeVisit.visit_in_time ?? '—'}
                        {timezoneLabel(activeVisit.outlet_timezone)}
                    </p>
                </div>
                <div class="rounded-md border bg-muted/40 p-2">
                    <p class="text-[11px] text-muted-foreground">Durasi</p>
                    <p class="font-mono text-sm font-medium tabular-nums">
                        {elapsedLabel}
                    </p>
                </div>
            </div>

            {#if activeVisit.reject_reason}
                <p class="rounded-md bg-destructive/10 p-2 text-xs text-destructive">
                    Ditolak HR: {activeVisit.reject_reason}
                </p>
            {/if}

            <select
                bind:value={selectedCategoryId}
                class="min-h-11 rounded-md border bg-background px-3 text-sm"
            >
                <option value="">Pilih Kategori Aktivitas...</option>
                {#each categories as cat (cat.id)}
                    <option value={cat.id}>{cat.name}</option>
                {/each}
            </select>

            <textarea
                bind:value={notes}
                rows="3"
                maxlength="1000"
                placeholder="Ringkasan hasil kunjungan (wajib, min. 3 karakter)"
                class="w-full rounded-md border bg-background p-2 text-sm"
            ></textarea>

            <Button
                type="button"
                class="min-h-12 w-full"
                disabled={submitting}
                onclick={submitVisitOut}
            >
                Visit Out (Selesai Kunjungan)
            </Button>

            {#if canCancelNow}
                <div class="border-t pt-3">
                    <Button
                        type="button"
                        variant="outline"
                        class="min-h-12 w-full"
                        disabled={submitting}
                        onclick={openCancelSheet}
                    >
                        Batalkan Visit In
                    </Button>
                    <p class="mt-1 text-xs text-muted-foreground">
                        Sisa kuota batal hari ini: {activeVisit.cancel_remaining_today}/3
                    </p>
                </div>
            {:else if activeVisit.cancel_remaining_today === 0}
                <p class="border-t pt-3 text-xs text-muted-foreground">
                    Kuota pembatalan Visit In hari ini sudah habis.
                </p>
            {:else}
                <p class="border-t pt-3 text-xs text-muted-foreground">
                    Batas pembatalan Visit In (5 menit) sudah terlewati.
                </p>
            {/if}

            {#if hasApprovedTimeOff}
                <p class="text-xs text-muted-foreground">
                    Visit Out tetap dapat dilakukan meskipun ada pengajuan
                    cuti/izin yang disetujui.
                </p>
            {/if}
        </div>

        <Sheet bind:open={cancelSheetOpen}>
            <SheetContent
                side="bottom"
                class="max-h-[92dvh] overflow-y-auto rounded-t-xl p-4 pb-[calc(1rem_+_env(safe-area-inset-bottom))]"
            >
                <h2 class="text-base font-semibold">Batalkan Visit In</h2>
                <p class="text-sm text-muted-foreground">
                    {activeVisit.outlet_name}
                </p>
                <p class="text-xs text-muted-foreground">
                    Sisa kuota batal hari ini: {activeVisit.cancel_remaining_today ?? 0}/3
                </p>

                <textarea
                    bind:value={cancelReason}
                    rows="3"
                    maxlength="255"
                    placeholder="Alasan pembatalan (min. 10 karakter)"
                    class="w-full rounded-md border bg-background p-2 text-sm"
                ></textarea>

                {#if feedback?.type === 'error'}
                    <p
                        role="alert"
                        class="rounded-md bg-destructive/10 p-2 text-sm text-destructive"
                    >
                        {feedback.message}
                    </p>
                {/if}

                <Button
                    type="button"
                    variant="destructive"
                    class="min-h-12 w-full"
                    disabled={submitting}
                    onclick={cancelActiveVisit}
                >
                    Konfirmasi Pembatalan
                </Button>
            </SheetContent>
        </Sheet>
    {:else if hasApprovedTimeOff}
        <div class="rounded-lg border bg-muted p-4 text-sm">
            Anda tidak dapat berkunjung karena sedang dalam status
            Cuti/Izin/Sakit yang telah disetujui hari ini.
        </div>
    {:else}
        <div class="flex flex-col gap-3 rounded-xl border bg-card p-4">
            <div class="flex gap-2">
                <Input
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
                    disabled={outletsLoading}
                    onclick={loadOutlets}
                >
                    {outletsLoading ? 'Memuat…' : 'Cari'}
                </Button>
            </div>

            {#if recentOutlets.length > 0}
                <div>
                    <p class="mb-1 text-xs font-medium text-muted-foreground">
                        Recently Visited Outlets
                    </p>
                    <div class="flex gap-1.5 overflow-x-auto">
                        {#each recentOutlets as outlet (outlet.id)}
                            <Button
                                type="button"
                                size="sm"
                                variant={selectedOutlet?.id === outlet.id
                                    ? 'default'
                                    : 'outline'}
                                class="shrink-0"
                                onclick={() => {
                                    selectedOutlet = outlet;
                                }}
                            >
                                {outlet.name}
                            </Button>
                        {/each}
                    </div>
                </div>
            {/if}

            {#if outletsLoading && searchResults.length === 0}
                <p class="text-sm text-muted-foreground">Memuat daftar toko…</p>
            {:else if searchResults.length === 0}
                <p class="text-sm text-muted-foreground">
                    Toko tidak ditemukan. Periksa kata kunci atau hubungi HR
                    untuk menambahkan outlet.
                </p>
            {:else}
                <div class="max-h-64 space-y-1.5 overflow-y-auto">
                    {#each searchResults as outlet (outlet.id)}
                        <button
                            type="button"
                            class="w-full rounded-md border p-2 text-left text-sm {selectedOutlet?.id ===
                            outlet.id
                                ? 'border-primary bg-primary/5'
                                : ''}"
                            onclick={() => {
                                selectedOutlet = outlet;
                            }}
                        >
                            <div class="flex justify-between gap-2 font-medium">
                                <span>{outlet.name}</span>
                                {#if outlet.distance_m !== null}
                                    <span class="shrink-0 text-xs text-muted-foreground">
                                        ±{Math.round(outlet.distance_m)} m
                                    </span>
                                {/if}
                            </div>
                            <p class="text-xs text-muted-foreground">
                                {outlet.code ?? 'NOO'} · R {outlet.radius_m} m · {outletStatusLabel(
                                    outlet.status,
                                )}
                            </p>
                            <p class="text-xs text-muted-foreground">
                                {outlet.address}
                            </p>
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
                {nooOpen ? 'Tutup Form Toko Baru' : 'Ajukan Toko Baru (NOO)'}
            </Button>

            {#if nooOpen}
                <div class="flex flex-col gap-2 rounded-md border p-3">
                    <Input
                        bind:value={nooName}
                        maxlength={150}
                        placeholder="Nama toko"
                    />
                    <textarea
                        bind:value={nooAddress}
                        rows="2"
                        maxlength="500"
                        placeholder="Alamat toko"
                        class="w-full rounded-md border bg-background p-2 text-sm"
                    ></textarea>
                    <select
                        bind:value={nooTimezone}
                        class="min-h-11 rounded-md border bg-background px-3 text-sm"
                    >
                        <option value="Asia/Jakarta">WIB</option>
                        <option value="Asia/Makassar">WITA</option>
                    </select>
                    <p class="text-xs text-muted-foreground">
                        Koordinat dikunci dari GPS saat kirim. Akurasi wajib ≤ 50 m.
                    </p>
                    {#if gps !== null && gps.accuracyM !== null && gps.accuracyM > 50}
                        <p class="text-xs text-destructive">
                            Akurasi GPS saat ini ±{Math.round(gps.accuracyM)} m
                            (&gt; 50 m). Cari area terbuka lalu tekan Muat Ulang
                            GPS.
                        </p>
                    {/if}

                    {#if nooNearby !== null && nooNearby.length > 0}
                        <div class="rounded-md bg-destructive/10 p-2 text-xs">
                            <p class="font-medium">Toko dalam radius 100 m:</p>
                            {#each nooNearby as near (near.id)}
                                <p>{near.name} — {near.address}</p>
                            {/each}
                            <Button
                                type="button"
                                size="sm"
                                variant="outline"
                                class="mt-2 w-full"
                                disabled={submitting}
                                onclick={() => submitNoo(true)}
                            >
                                Tetap Ajukan
                            </Button>
                        </div>
                    {/if}

                    <Button
                        type="button"
                        class="min-h-12 w-full"
                        disabled={submitting}
                        onclick={() => submitNoo(false)}
                    >
                        Kirim Pengajuan NOO
                    </Button>
                </div>
            {/if}

            {#if selectedOutlet !== null}
                <div class="rounded-md border bg-muted/40 p-2 text-xs">
                    <p class="font-medium">{selectedOutlet.name}</p>
                    <p class="text-muted-foreground">
                        {selectedOutlet.code ?? 'NOO'} · R {selectedOutlet.radius_m} m · {outletStatusLabel(
                            selectedOutlet.status,
                        )}
                    </p>
                </div>

                {#key cameraKey}
                    <LiveCameraCapture
                        facingMode="user"
                        disabled={submitting}
                        onCapture={(payload) => {
                            capturedPhoto = payload;
                            feedback = null;
                        }}
                    />
                {/key}

                <textarea
                    bind:value={notes}
                    rows="2"
                    maxlength="500"
                    placeholder="Alasan Visit In (wajib jika toko NOO, luar radius, atau akurasi buruk)"
                    class="w-full rounded-md border bg-background p-2 text-sm"
                ></textarea>
                <p class="text-xs text-muted-foreground">
                    {visitInNotesRequired ? 'Wajib diisi' : 'Opsional'}
                </p>
                {#if visitInNotesRequired}
                    <p class="text-xs text-muted-foreground">
                        Wajib diisi karena toko NOO, di luar radius, atau akurasi
                        GPS rendah.
                    </p>
                {/if}

                <Button
                    type="button"
                    class="min-h-12 w-full"
                    disabled={submitting}
                    onclick={submitVisitIn}
                >
                    Visit In ke {selectedOutlet.name}
                </Button>
            {/if}
        </div>
    {/if}

    {#if feedback && !cancelSheetOpen}
        <p
            role={feedback.type === 'error' ? 'alert' : 'status'}
            class="rounded-md p-3 text-sm {feedback.type === 'error'
                ? 'bg-destructive/10 text-destructive'
                : 'bg-green-50 text-green-800'}"
        >
            {feedback.message}
        </p>
    {/if}

    {#if todayVisits.length > 0}
        <div class="flex flex-col gap-2 rounded-xl border bg-card p-4">
            <p class="text-xs font-medium text-muted-foreground">
                Aktivitas Hari Ini
            </p>

            {#each todayVisits as visit (visit.id)}
                <div class="flex flex-col gap-1 border-t pt-2 first:border-t-0 first:pt-0">
                    <div class="flex flex-wrap items-center gap-1">
                        <span class={visitStatusMeta(visit.visit_status).className}>
                            {visitStatusMeta(visit.visit_status).label}
                        </span>
                        <span class={verificationMeta(visit.verification_status).className}>
                            {verificationMeta(visit.verification_status).label}
                        </span>
                        {#if visit.source === 'MANUAL_HR'}
                            <span class="{badgeBase} {toneNeutral}">Input Manual HR</span>
                        {/if}
                    </div>

                    <p class="text-sm font-medium">{visit.outlet_name}</p>
                    <p class="text-xs text-muted-foreground">
                        {visit.outlet_code ?? 'NOO'} · {visit.outlet_address}
                    </p>
                    <p class="text-xs text-muted-foreground">
                        In {visit.visit_in_time ?? '—'}
                        {timezoneLabel(visit.outlet_timezone)} · Out
                        {visit.visit_out_time ?? '—'} · {durationLabel(visit)}
                    </p>
                    {#if visit.activity_category_name}
                        <p class="text-xs text-muted-foreground">
                            Kategori: {visit.activity_category_name}
                        </p>
                    {/if}
                    {#if visit.visit_notes ?? visit.visit_in_notes}
                        <p class="text-xs text-muted-foreground">
                            {visit.visit_notes ?? visit.visit_in_notes}
                        </p>
                    {/if}
                    {#if visit.cancel_reason}
                        <p class="text-xs text-muted-foreground">
                            Alasan batal: {visit.cancel_reason}
                        </p>
                    {/if}
                    {#if visit.reject_reason}
                        <p class="text-xs text-destructive">
                            Ditolak HR: {visit.reject_reason}
                        </p>
                    {/if}
                </div>
            {/each}
        </div>
    {/if}
</div>
