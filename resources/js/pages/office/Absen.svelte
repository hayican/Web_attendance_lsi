<script lang="ts">
    import { onDestroy, onMount } from 'svelte';
    import { router } from '@inertiajs/svelte';
    import AlertTriangle from '@lucide/svelte/icons/alert-triangle';
    import CircleCheck from '@lucide/svelte/icons/circle-check';
    import MapPin from '@lucide/svelte/icons/map-pin';
    import RefreshCw from '@lucide/svelte/icons/refresh-cw';
    import AppHead from '@/components/AppHead.svelte';
    import LiveCameraCapture from '@/components/LiveCameraCapture.svelte';
    import { Button } from '@/components/ui/button';
    import {
        isInAppBrowser,
        recommendedExternalBrowserInstruction,
    } from '@/lib/deviceCheck';
    import {
        type FreshGpsResult,
        haversineDistanceMeters,
        readXsrfToken,
        requestFreshGps,
    } from '@/lib/gpsClient';

    type VerificationStatus =
        | 'AUTO_APPROVED'
        | 'PENDING_REVIEW'
        | 'APPROVED'
        | 'REJECTED';

    type AttendanceSummary = {
        id: number;
        attempt_no: number;
        clock_in_at: string | null;
        clock_out_at: string | null;
        clock_in_status: VerificationStatus;
        clock_out_status: VerificationStatus | null;
        clock_in_anomaly_flags: string[];
        clock_out_anomaly_flags: string[];
        clock_in_reject_reason: string | null;
        clock_out_reject_reason: string | null;
        clock_in_notes: string | null;
        clock_out_notes: string | null;
        late_minutes: number;
        early_leave_minutes: number;
        schedule_start_time: string | null;
        schedule_end_time: string | null;
        auto_close_hours: number | null;
    };

    type OfficeProps = {
        office: {
            id: number;
            name: string;
            radius_m: number;
            timezone: string;
            latitude: number;
            longitude: number;
        };
        serverTimeIso: string;
        workDate: string;
        shift: {
            code: string;
            name: string;
            start_time: string;
            end_time: string;
            grace_period_minutes: number;
        } | null;
        isDayOff: boolean;
        hasApprovedTimeOff: boolean;
        dinasLuar: {
            id: number;
            destination_location: string;
            scheduled_start_time: string;
            status: string;
        } | null;
        effectiveStartIso: string | null;
        effectiveEndIso: string | null;
        closeDeadlineIso: string | null;
        activeLog: AttendanceSummary | null;
        rejectedLog: AttendanceSummary | null;
        canRetryRejected: boolean;
    };

    let {
        office,
        serverTimeIso,
        workDate,
        shift,
        isDayOff,
        hasApprovedTimeOff,
        dinasLuar,
        effectiveStartIso,
        effectiveEndIso,
        closeDeadlineIso,
        activeLog,
        rejectedLog,
        canRetryRejected,
    }: OfficeProps = $props();

    const badgeBase =
        'inline-flex items-center gap-1 rounded border px-1.5 py-0.5 text-[11px] font-medium whitespace-nowrap';
    const toneSuccess = 'border-green-300 bg-green-50 text-green-700';
    const toneWarning = 'border-yellow-300 bg-yellow-50 text-yellow-800';
    const toneError = 'border-red-300 bg-red-50 text-red-700';
    const toneFraud = 'border-rose-300 bg-rose-50 text-rose-800';
    const toneInfo = 'border-blue-300 bg-blue-50 text-blue-700';
    const toneNeutral = 'border-stone-300 bg-stone-100 text-stone-700';

    const flagLabels: Record<string, string> = {
        OUT_OF_RADIUS: 'Luar Radius',
        UNCERTAIN_ACCURACY: 'Akurasi Tidak Pasti',
        LOW_ACCURACY: 'Akurasi Rendah',
        SHARED_DEVICE: 'Perangkat Bersama',
        EARLY_LEAVE: 'Pulang Awal',
        LATE_PERMISSION: 'Izin Masuk Siang',
        MISSED_CLOCK_OUT: 'Lupa Clock-Out',
        OFFSITE_DUTY: 'Dinas Luar',
        OFF_DAY_ATTENDANCE: 'Absen Hari Libur',
        IMPOSSIBLE_TRAVEL: 'Perjalanan Tidak Wajar',
        REPEATED_COORDS: 'Koordinat Berulang',
        CONSTANT_ACCURACY: 'Akurasi Konstan',
        STALE_TIMESTAMP: 'Token Kedaluwarsa',
    };

    function verificationMeta(status: VerificationStatus | null): {
        label: string;
        className: string;
    } {
        switch (status) {
            case 'AUTO_APPROVED':
                return { label: 'Sah Otomatis', className: `${badgeBase} ${toneSuccess}` };
            case 'APPROVED':
                return { label: 'Disetujui', className: `${badgeBase} ${toneSuccess}` };
            case 'PENDING_REVIEW':
                return { label: 'Menunggu Review', className: `${badgeBase} ${toneWarning}` };
            case 'REJECTED':
                return { label: 'Ditolak', className: `${badgeBase} ${toneError}` };
            default:
                return { label: 'Belum Absen', className: `${badgeBase} ${toneWarning}` };
        }
    }

    function flagBadge(flag: string): string {
        if (
            ['SHARED_DEVICE', 'IMPOSSIBLE_TRAVEL', 'REPEATED_COORDS', 'CONSTANT_ACCURACY', 'STALE_TIMESTAMP'].includes(
                flag,
            )
        ) {
            return `${badgeBase} ${toneFraud}`;
        }

        if (
            ['OFFSITE_DUTY', 'OFF_DAY_ATTENDANCE'].includes(flag)
        ) {
            return `${badgeBase} ${toneInfo}`;
        }

        if (
            ['OUT_OF_RADIUS', 'UNCERTAIN_ACCURACY', 'LOW_ACCURACY', 'EARLY_LEAVE', 'LATE_PERMISSION', 'MISSED_CLOCK_OUT'].includes(
                flag,
            )
        ) {
            return `${badgeBase} ${toneWarning}`;
        }

        return `${badgeBase} ${toneNeutral}`;
    }

    function flagLabel(flag: string): string {
        return flagLabels[flag] ?? flag.replaceAll('_', ' ');
    }

    function timezoneLabel(timezone: string): string {
        return timezone === 'Asia/Makassar' ? 'WITA' : 'WIB';
    }

    function toMs(iso: string | null): number | null {
        if (iso === null) {
            return null;
        }

        const parsed = Date.parse(iso);

        return Number.isNaN(parsed) ? null : parsed;
    }

    function formatTime(iso: string | null): string {
        const ms = toMs(iso);

        if (ms === null) {
            return '—';
        }

        return new Intl.DateTimeFormat('id-ID', {
            timeZone: office.timezone,
            hour: '2-digit',
            minute: '2-digit',
            hourCycle: 'h23',
        }).format(new Date(ms));
    }

    const dateLabel = $derived.by(
        () =>
            new Intl.DateTimeFormat('id-ID', {
                timeZone: office.timezone,
                weekday: 'long',
                day: 'numeric',
                month: 'long',
                year: 'numeric',
            }).format(new Date(`${workDate}T12:00:00Z`)),
    );

    let inAppWarning = $state(false);
    let gps = $state<FreshGpsResult | null>(null);
    let refreshingGps = $state(false);
    let capturedPhoto = $state<{ blob: Blob; cameraToken: string } | null>(
        null,
    );
    let notes = $state('');
    let latePermission = $state(false);
    let submitting = $state(false);
    let cameraKey = $state(0);
    let feedback = $state<{
        type: 'error' | 'success';
        message: string;
    } | null>(null);
    let clockOffsetMs = 0;
    let clockTickMs = $state(0);
    let timer: ReturnType<typeof setInterval> | null = null;

    const nowMs = $derived(
        clockTickMs !== 0
            ? clockTickMs
            : serverTimeIso !== null
              ? Date.parse(serverTimeIso)
              : Date.now(),
    );

    onMount(() => {
        clockOffsetMs =
            (serverTimeIso !== null ? Date.parse(serverTimeIso) : Date.now()) -
            Date.now();
        clockTickMs = Date.now() + clockOffsetMs;
        timer = setInterval(() => {
            clockTickMs = Date.now() + clockOffsetMs;
        }, 1000);
        inAppWarning = isInAppBrowser();
        void refreshGps();
    });

    onDestroy(() => {
        if (timer) {
            clearInterval(timer);
        }
    });

    async function refreshGps() {
        refreshingGps = true;

        try {
            gps = await requestFreshGps(office.radius_m);
        } finally {
            refreshingGps = false;
        }
    }

    const isClockOutMode = $derived(
        activeLog !== null && activeLog.clock_out_at === null,
    );
    const isFinishedToday = $derived(
        activeLog !== null && activeLog.clock_out_at !== null,
    );
    const isMissedClockOut = $derived(
        activeLog !== null &&
            activeLog.clock_out_at === null &&
            activeLog.clock_out_anomaly_flags.includes('MISSED_CLOCK_OUT'),
    );
    const isClockInRejectedActive = $derived(
        activeLog !== null && activeLog.clock_in_status === 'REJECTED',
    );
    const isClockOutRejected = $derived(
        activeLog?.clock_out_status === 'REJECTED',
    );

    const effectiveStartMs = $derived(toMs(effectiveStartIso));
    const effectiveEndMs = $derived(toMs(effectiveEndIso));
    const closeDeadlineMs = $derived(toMs(closeDeadlineIso));

    const lateCutoffMs = $derived(
        effectiveStartMs !== null && shift !== null
            ? effectiveStartMs + shift.grace_period_minutes * 60000
            : null,
    );

    const isLateNow = $derived(
        !isClockOutMode &&
            !isDayOff &&
            dinasLuar === null &&
            lateCutoffMs !== null &&
            nowMs > lateCutoffMs,
    );

    const lateMinutesNow = $derived(
        isLateNow && effectiveStartMs !== null
            ? Math.max(1, Math.floor((nowMs - effectiveStartMs) / 60000))
            : 0,
    );

    const earlyLeaveMinutes = $derived.by(() => {
        if (!isClockOutMode || isDayOff || effectiveEndMs === null) {
            return 0;
        }

        const remaining = effectiveEndMs - nowMs;

        return remaining > 0 ? Math.max(1, Math.floor(remaining / 60000)) : 0;
    });

    const isEarlyLeave = $derived(earlyLeaveMinutes > 0);

    const isRetryAfterEnd = $derived(
        !isClockOutMode &&
            rejectedLog !== null &&
            effectiveEndMs !== null &&
            nowMs >= effectiveEndMs,
    );

    const clockOutRetryAfterEnd = $derived.by(() => {
        if (
            !isClockOutMode ||
            activeLog === null ||
            activeLog.attempt_no <= 1 ||
            effectiveEndMs === null
        ) {
            return false;
        }

        const clockInMs = toMs(activeLog.clock_in_at);

        return clockInMs !== null && clockInMs >= effectiveEndMs;
    });

    const distanceM = $derived.by(() => {
        if (gps === null || gps.lat === null || gps.lng === null) {
            return null;
        }

        return haversineDistanceMeters(
            gps.lat,
            gps.lng,
            office.latitude,
            office.longitude,
        );
    });

    const accuracyM = $derived(gps?.accuracyM ?? null);
    const hardBlockLimit = $derived(Math.max(200, office.radius_m));
    const isHardBlocked = $derived(
        !isClockOutMode &&
            dinasLuar === null &&
            distanceM !== null &&
            distanceM > hardBlockLimit,
    );
    const isOutsideRadius = $derived(
        distanceM !== null && distanceM > office.radius_m,
    );
    const isAccuracyPoor = $derived(
        accuracyM !== null &&
            (accuracyM > 100 || accuracyM > office.radius_m),
    );

    const geoRequiresNotes = $derived.by(() => {
        if (dinasLuar !== null) {
            return accuracyM !== null && accuracyM > 100;
        }

        if (distanceM !== null && distanceM > office.radius_m) {
            return true;
        }

        if (accuracyM === null) {
            return false;
        }

        return accuracyM > office.radius_m || accuracyM > 100;
    });

    const gpsMeta = $derived.by<{ label: string; className: string }>(() => {
        if (gps === null) {
            return { label: 'Menunggu lokasi GPS…', className: 'text-stone-500' };
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
            return { label: 'Menunggu lokasi GPS…', className: 'text-stone-500' };
        }

        if (isHardBlocked) {
            return {
                label: `Di luar jangkauan (maks ${hardBlockLimit} m). Mendekat ke kantor dulu.`,
                className: 'font-medium text-red-700',
            };
        }

        if (dinasLuar !== null) {
            return {
                label: 'Mode Dinas Luar — blokir radius dibuka, absen masuk review HR.',
                className: 'font-medium text-blue-700',
            };
        }

        if (isOutsideRadius) {
            return {
                label: 'Di luar radius kantor — wajib isi alasan.',
                className: 'font-medium text-yellow-800',
            };
        }

        if (isAccuracyPoor) {
            return {
                label: 'Sinyal GPS kurang akurat — wajib isi alasan.',
                className: 'font-medium text-yellow-800',
            };
        }

        return {
            label: 'Di dalam radius kantor.',
            className: 'font-medium text-green-700',
        };
    });

    const notesReasons = $derived.by<string[]>(() => {
        const reasons: string[] = [];

        if (geoRequiresNotes) {
            reasons.push('Lokasi atau akurasi GPS di luar toleransi');
        }

        if (isDayOff) {
            reasons.push('Absen pada hari OFF/libur (lembur)');
        }

        if (isEarlyLeave) {
            reasons.push(`Pulang ${earlyLeaveMinutes} menit lebih awal`);
        }

        if (isRetryAfterEnd) {
            reasons.push('Absen ulang setelah jam shift berakhir');
        }

        if (clockOutRetryAfterEnd) {
            reasons.push('Clock-Out pada percobaan absen ulang');
        }

        if (latePermission) {
            reasons.push('Izin masuk siang: alasan minimal 10 karakter');
        }

        return reasons;
    });

    const requiresNotes = $derived(notesReasons.length > 0);

    const notesPlaceholder = $derived.by(() => {
        if (isEarlyLeave) {
            return 'Alasan pulang lebih awal (wajib diisi)…';
        }

        if (geoRequiresNotes) {
            return 'Alasan lokasi/akurasi GPS (wajib diisi)…';
        }

        if (isDayOff) {
            return 'Alasan absen pada hari libur (wajib diisi)…';
        }

        if (latePermission) {
            return 'Alasan izin masuk siang (minimal 10 karakter)…';
        }

        return 'Catatan tambahan (opsional)…';
    });

    const showForm = $derived(
        !hasApprovedTimeOff &&
            !isFinishedToday &&
            !isMissedClockOut &&
            !isClockInRejectedActive &&
            !(rejectedLog !== null && !canRetryRejected),
    );

    const nextAttemptNo = $derived(
        isClockOutMode
            ? (activeLog?.attempt_no ?? 1)
            : rejectedLog !== null
              ? rejectedLog.attempt_no + 1
              : 1,
    );

    const submitDisabled = $derived(
        submitting ||
            gps?.status === 'PERMISSION_DENIED' ||
            gps?.status === 'GPS_UNAVAILABLE' ||
            isHardBlocked ||
            (rejectedLog !== null && !canRetryRejected),
    );

    const summaryMeta = $derived.by<{ label: string; className: string }>(() => {
        if (hasApprovedTimeOff) {
            return {
                label: 'Cuti / Izin',
                className: `${badgeBase} ${toneNeutral}`,
            };
        }

        if (rejectedLog !== null) {
            return verificationMeta('REJECTED');
        }

        if (activeLog === null) {
            return {
                label: 'Belum Absen',
                className: `${badgeBase} ${toneWarning}`,
            };
        }

        if (isMissedClockOut) {
            return {
                label: 'Lupa Clock-Out',
                className: `${badgeBase} ${toneWarning}`,
            };
        }

        return verificationMeta(
            activeLog.clock_out_status ?? activeLog.clock_in_status,
        );
    });

    const formTitle = $derived(
        isClockOutMode
            ? 'Clock-Out'
            : rejectedLog !== null
              ? 'Absen Ulang (Clock-In)'
              : 'Clock-In',
    );

    const buttonLabel = $derived(
        isClockOutMode
            ? 'Clock-Out Sekarang'
            : rejectedLog !== null
              ? 'Absen Ulang Sekarang'
              : 'Clock-In Sekarang',
    );

    async function submitAttendance() {
        if (submitting) {
            return;
        }

        feedback = null;

        if (rejectedLog !== null && !canRetryRejected) {
            feedback = {
                type: 'error',
                message:
                    'Batas waktu absen ulang sudah berakhir. Hubungi HR Admin.',
            };
            return;
        }

        submitting = true;

        try {
            const fresh = await requestFreshGps(office.radius_m);
            gps = fresh;

            if (
                fresh.gpsSessionToken === null ||
                fresh.lat === null ||
                fresh.lng === null
            ) {
                feedback = {
                    type: 'error',
                    message:
                        fresh.message ??
                        'Lokasi GPS belum siap. Tekan Muat Ulang GPS.',
                };
                return;
            }

            if (capturedPhoto === null) {
                feedback = {
                    type: 'error',
                    message: 'Ambil foto selfie kamera langsung terlebih dahulu.',
                };
                return;
            }

            const freshDistance = haversineDistanceMeters(
                fresh.lat,
                fresh.lng,
                office.latitude,
                office.longitude,
            );
            const freshAccuracy = fresh.accuracyM;
            const freshHardBlockLimit = Math.max(200, office.radius_m);

            if (
                !isClockOutMode &&
                dinasLuar === null &&
                freshDistance > freshHardBlockLimit
            ) {
                feedback = {
                    type: 'error',
                    message: `Jarak Anda ${Math.round(freshDistance)} m dari kantor (maksimal ${freshHardBlockLimit} m). Mendekat ke lokasi dulu.`,
                };
                return;
            }

            const freshGeoNotes =
                dinasLuar !== null
                    ? freshAccuracy !== null && freshAccuracy > 100
                    : freshDistance > office.radius_m ||
                      (freshAccuracy !== null &&
                          (freshAccuracy > office.radius_m ||
                              freshAccuracy > 100));

            const now = Date.now() + clockOffsetMs;
            const freshEarlyLeave =
                isClockOutMode &&
                !isDayOff &&
                effectiveEndMs !== null &&
                now < effectiveEndMs;
            const freshRetryAfterEnd =
                !isClockOutMode &&
                rejectedLog !== null &&
                effectiveEndMs !== null &&
                now >= effectiveEndMs;
            const clockInMs = activeLog !== null ? toMs(activeLog.clock_in_at) : null;
            const freshClockOutRetry =
                isClockOutMode &&
                activeLog !== null &&
                activeLog.attempt_no > 1 &&
                effectiveEndMs !== null &&
                clockInMs !== null &&
                clockInMs >= effectiveEndMs;

            const trimmedNotes = notes.trim();
            const freshRequiresNotes =
                freshGeoNotes ||
                isDayOff ||
                freshEarlyLeave ||
                freshRetryAfterEnd ||
                freshClockOutRetry ||
                latePermission;

            if (freshRequiresNotes && trimmedNotes === '') {
                feedback = {
                    type: 'error',
                    message:
                        'Alasan wajib diisi untuk kondisi lokasi, lembur/hari libur, pulang awal, atau absen ulang.',
                };
                return;
            }

            if (latePermission && trimmedNotes.length < 10) {
                feedback = {
                    type: 'error',
                    message:
                        'Alasan izin masuk siang wajib diisi minimal 10 karakter.',
                };
                return;
            }

            const form = new FormData();
            form.append('idempotency_key', crypto.randomUUID());
            form.append('lat', String(fresh.lat));
            form.append('lng', String(fresh.lng));
            form.append('accuracy_m', String(fresh.accuracyM ?? 0));
            form.append('gps_session_token', fresh.gpsSessionToken);
            form.append('camera_token', capturedPhoto.cameraToken);
            form.append('photo', capturedPhoto.blob, 'selfie.jpg');

            if (trimmedNotes !== '') {
                form.append('notes', trimmedNotes);
            }

            if (latePermission) {
                form.append('is_late_permission', '1');
            }

            const endpoint = isClockOutMode
                ? '/attendance/office/clock-out'
                : '/attendance/office/clock-in';

            const res = await fetch(endpoint, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'X-XSRF-TOKEN': readXsrfToken(),
                },
                body: form,
            });

            const data = (await res.json()) as {
                message?: string;
                errors?: Record<string, string[]>;
            };

            if (!res.ok) {
                const firstErr = data.errors
                    ? Object.values(data.errors).flat()[0]
                    : null;
                feedback = {
                    type: 'error',
                    message:
                        firstErr ??
                        data.message ??
                        'Gagal menyimpan absensi.',
                };
                return;
            }

            capturedPhoto = null;
            notes = '';
            latePermission = false;
            cameraKey += 1;
            feedback = {
                type: 'success',
                message: isClockOutMode
                    ? 'Clock-Out berhasil dicatat.'
                    : 'Clock-In berhasil dicatat.',
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

<AppHead title="Absensi Kantor" />

{#snippet phaseRow(
    label: string,
    at: string | null,
    status: VerificationStatus | null,
    flags: string[],
)}
    <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
        <span class="w-11 text-[11px] text-stone-500">{label}</span>
        <span class="font-mono text-xs font-semibold tabular-nums text-stone-900">
            {formatTime(at)}
        </span>
        {#if status}
            {@const meta = verificationMeta(status)}
            <span class={meta.className}>{meta.label}</span>
        {/if}
        {#if flags.length > 0}
            <div class="flex w-full flex-wrap gap-1 pl-13">
                {#each flags as flag (flag)}
                    <span class={flagBadge(flag)}>{flagLabel(flag)}</span>
                {/each}
            </div>
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
            <p class="mt-1 leading-snug">
                {recommendedExternalBrowserInstruction()}
            </p>
        </div>
    {/if}

    {#if feedback}
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

    <section
        class="rounded-xl border border-stone-200 bg-white p-3 shadow-xs"
    >
        <div class="flex items-start justify-between gap-2">
            <div class="min-w-0">
                <p
                    class="text-[11px] font-medium uppercase tracking-wide text-stone-500"
                >
                    Status Absensi
                </p>
                <p class="mt-0.5 text-sm font-semibold text-stone-900">
                    {dateLabel}
                </p>
            </div>
            <span class={summaryMeta.className}>{summaryMeta.label}</span>
        </div>

        <dl class="mt-3 grid grid-cols-2 gap-x-3 gap-y-2 text-[11px]">
            <div>
                <dt class="text-stone-500">Shift</dt>
                <dd class="mt-0.5 font-medium text-stone-900">
                    {#if shift}
                        {shift.code} · {shift.start_time.slice(0, 5)}–{shift.end_time.slice(0, 5)}
                    {:else}
                        Fleksibel / Libur
                    {/if}
                </dd>
            </div>
            <div>
                <dt class="text-stone-500">
                    {dinasLuar !== null ? 'Acuan Masuk (Dinas)' : 'Acuan Masuk'}
                </dt>
                <dd class="mt-0.5 font-mono font-semibold tabular-nums text-stone-900">
                    {formatTime(effectiveStartIso)}
                </dd>
            </div>
            <div>
                <dt class="text-stone-500">Toleransi</dt>
                <dd class="mt-0.5 font-medium text-stone-900">
                    {shift !== null ? `${shift.grace_period_minutes} menit` : '—'}
                </dd>
            </div>
            <div>
                <dt class="text-stone-500">Acuan Pulang</dt>
                <dd class="mt-0.5 font-mono font-semibold tabular-nums text-stone-900">
                    {formatTime(effectiveEndIso)}
                </dd>
            </div>
        </dl>

        {#if dinasLuar !== null}
            <div
                class="mt-2 rounded-md border border-blue-300 bg-blue-50 p-2 text-[11px] leading-snug text-blue-800"
            >
                <p class="font-semibold">
                    {dinasLuar.status === 'PENDING'
                        ? 'Dinas Luar — menunggu persetujuan HR'
                        : 'Dinas Luar disetujui'}
                </p>
                <p>
                    {dinasLuar.destination_location} · acuan masuk
                    {dinasLuar.scheduled_start_time.slice(0, 5)}
                    {timezoneLabel(office.timezone)}
                </p>
                <p>
                    Blokir radius dibuka; absen otomatis masuk antrean review HR.
                </p>
            </div>
        {/if}

        {#if isDayOff}
            <div
                class="mt-2 rounded-md border border-stone-300 bg-stone-100 p-2 text-[11px] leading-snug text-stone-700"
            >
                Hari ini terjadwal OFF/libur. Absen tetap dibuka sebagai lembur
                dan akan berstatus menunggu review HR.
            </div>
        {/if}

        {#if activeLog !== null}
            <div class="mt-3 space-y-2 border-t border-stone-200 pt-2.5">
                <p class="text-[11px] text-stone-500">
                    Percobaan #{activeLog.attempt_no}
                </p>
                {@render phaseRow(
                    'Masuk',
                    activeLog.clock_in_at,
                    activeLog.clock_in_status,
                    activeLog.clock_in_anomaly_flags,
                )}
                {#if activeLog.clock_out_at !== null || activeLog.clock_out_status !== null}
                    {@render phaseRow(
                        'Pulang',
                        activeLog.clock_out_at,
                        activeLog.clock_out_status,
                        activeLog.clock_out_anomaly_flags,
                    )}
                {/if}
                <div class="flex flex-wrap gap-1">
                    {#if activeLog.late_minutes > 0}
                        <span class={`${badgeBase} ${toneWarning}`}>
                            Terlambat {activeLog.late_minutes} menit
                        </span>
                    {/if}
                    {#if activeLog.early_leave_minutes > 0}
                        <span class={`${badgeBase} ${toneWarning}`}>
                            Pulang awal {activeLog.early_leave_minutes} menit
                        </span>
                    {/if}
                </div>
            </div>
        {:else if rejectedLog !== null}
            <div class="mt-3 space-y-2 border-t border-stone-200 pt-2.5">
                <p class="text-[11px] text-stone-500">
                    Percobaan #{rejectedLog.attempt_no}
                </p>
                {@render phaseRow(
                    'Masuk',
                    rejectedLog.clock_in_at,
                    rejectedLog.clock_in_status,
                    rejectedLog.clock_in_anomaly_flags,
                )}
                {#if rejectedLog.clock_out_at !== null || rejectedLog.clock_out_status !== null}
                    {@render phaseRow(
                        'Pulang',
                        rejectedLog.clock_out_at,
                        rejectedLog.clock_out_status,
                        rejectedLog.clock_out_anomaly_flags,
                    )}
                {/if}
            </div>
        {/if}
    </section>

    {#if rejectedLog !== null && !isFinishedToday}
        <section class="rounded-xl border border-red-300 bg-red-50 p-3">
            <p
                class="flex items-center gap-1.5 text-sm font-semibold text-red-800"
            >
                <AlertTriangle class="size-4 shrink-0" />
                Clock-In Ditolak HR
            </p>
            <p class="mt-1 text-[13px] leading-snug text-red-800">
                Alasan: {rejectedLog.clock_in_reject_reason ?? '—'}
            </p>
            {#if rejectedLog.clock_out_reject_reason !== null}
                <p class="mt-1 text-[12px] leading-snug text-red-800">
                    Clock-Out: {rejectedLog.clock_out_reject_reason}
                </p>
            {/if}
            <p class="mt-1.5 text-[11px] leading-snug text-red-700">
                Percobaan #{rejectedLog.attempt_no}.
                {#if canRetryRejected}
                    Absen ulang dapat dilakukan sampai pukul
                    {formatTime(closeDeadlineIso)}.
                {:else}
                    Batas waktu absen ulang sudah berakhir. Hubungi HR Admin
                    untuk koreksi.
                {/if}
            </p>
        </section>
    {/if}

    {#if hasApprovedTimeOff}
        <section
            class="rounded-lg border border-stone-300 bg-stone-100 p-4 text-sm text-stone-700"
        >
            <p class="font-semibold text-stone-900">Absen dikunci</p>
            <p class="mt-1 leading-snug">
                Anda tidak dapat absen karena sedang dalam status Cuti/Izin/Sakit
                yang telah disetujui hari ini.
            </p>
        </section>
    {:else if isMissedClockOut}
        <section
            class="rounded-xl border p-3 {isClockOutRejected
                ? 'border-red-300 bg-red-50'
                : 'border-yellow-300 bg-yellow-50'}"
        >
            <p
                class="flex items-center gap-1.5 text-sm font-semibold {isClockOutRejected
                    ? 'text-red-800'
                    : 'text-yellow-900'}"
            >
                <AlertTriangle class="size-4 shrink-0" />
                Sesi ditutup otomatis
            </p>
            <p
                class="mt-1 text-[13px] leading-snug {isClockOutRejected
                    ? 'text-red-800'
                    : 'text-yellow-800'}"
            >
                Anda belum Clock-Out sampai batas
                {formatTime(closeDeadlineIso)}. Jam pulang akan ditetapkan HR
                melalui verifikasi absensi.
            </p>
            {#if isClockOutRejected}
                <p class="mt-1 text-[12px] font-medium leading-snug text-red-800">
                    Clock-Out ditolak: {activeLog?.clock_out_reject_reason ?? '—'}
                </p>
            {/if}
        </section>
    {:else if isFinishedToday}
        <section
            class="rounded-lg border p-3 text-[13px] leading-snug {isClockOutRejected
                ? 'border-red-300 bg-red-50 text-red-800'
                : 'border-green-300 bg-green-50 text-green-800'}"
        >
            {#if isClockOutRejected}
                <p class="flex items-center gap-1.5 font-semibold">
                    <AlertTriangle class="size-4 shrink-0" />
                    Clock-Out ditolak HR
                </p>
                <p class="mt-1">
                    Alasan: {activeLog?.clock_out_reject_reason ?? '—'}.
                    Ajukan koreksi status ke HR Admin.
                </p>
            {:else}
                <p class="flex items-center gap-1.5 font-semibold">
                    <CircleCheck class="size-4 shrink-0" />
                    Absensi hari ini sudah tercatat
                </p>
                <p class="mt-1">
                    Clock-In {formatTime(activeLog?.clock_in_at ?? null)} ·
                    Clock-Out {formatTime(activeLog?.clock_out_at ?? null)}.
                </p>
            {/if}
        </section>
    {:else if isClockInRejectedActive}
        <section
            class="rounded-lg border border-red-300 bg-red-50 p-3 text-[13px] leading-snug text-red-800"
        >
            <p class="flex items-center gap-1.5 font-semibold">
                <AlertTriangle class="size-4 shrink-0" />
                Clock-In ditolak HR
            </p>
            <p class="mt-1">
                Alasan: {activeLog?.clock_in_reject_reason ?? '—'}. Hubungi HR
                Admin untuk koreksi status.
            </p>
        </section>
    {:else if rejectedLog !== null && !canRetryRejected}
        <p
            class="rounded-lg border border-stone-300 bg-stone-100 p-3 text-[13px] leading-snug text-stone-700"
        >
            Batas waktu absen ulang sudah berakhir. Hubungi HR Admin untuk
            koreksi status.
        </p>
    {/if}

    {#if showForm}
        <section
            class="rounded-xl border border-stone-200 bg-white p-3 shadow-xs"
        >
            <div class="flex items-center justify-between gap-2">
                <h2 class="text-sm font-semibold text-stone-900">
                    {formTitle}
                </h2>
                <span class="text-[11px] text-stone-500">
                    Percobaan #{nextAttemptNo}
                </span>
            </div>

            <div class="mt-3 rounded-md border border-stone-200 bg-stone-50 p-2.5">
                <div class="flex items-center justify-between gap-2">
                    <p
                        class="flex items-center gap-1 text-xs font-semibold text-stone-800"
                    >
                        <MapPin class="size-3.5" />
                        GPS
                    </p>
                    <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        class="h-11"
                        disabled={refreshingGps || submitting}
                        onclick={refreshGps}
                    >
                        <RefreshCw
                            class="size-3.5 {refreshingGps
                                ? 'animate-spin'
                                : ''}"
                        />
                        Muat Ulang
                    </Button>
                </div>

                <div class="mt-2 grid grid-cols-3 gap-1.5 text-center">
                    <div class="rounded border border-stone-200 bg-white p-1.5">
                        <p class="text-[10px] text-stone-500">Jarak</p>
                        <p
                            class="font-mono text-xs font-semibold tabular-nums text-stone-900"
                        >
                            {distanceM !== null
                                ? `${Math.round(distanceM)} m`
                                : '—'}
                        </p>
                    </div>
                    <div class="rounded border border-stone-200 bg-white p-1.5">
                        <p class="text-[10px] text-stone-500">Akurasi</p>
                        <p
                            class="font-mono text-xs font-semibold tabular-nums text-stone-900"
                        >
                            {accuracyM !== null
                                ? `${Math.round(accuracyM)} m`
                                : '—'}
                        </p>
                    </div>
                    <div class="rounded border border-stone-200 bg-white p-1.5">
                        <p class="text-[10px] text-stone-500">Radius</p>
                        <p
                            class="font-mono text-xs font-semibold tabular-nums text-stone-900"
                        >
                            {office.radius_m} m
                        </p>
                    </div>
                </div>

                <p class="mt-2 text-[11px] leading-snug {gpsMeta.className}">
                    {gpsMeta.label}
                </p>

                {#if gps?.message && gps.status === 'POOR_ACCURACY'}
                    <p class="mt-1 text-[11px] leading-snug text-yellow-800">
                        {gps.message}
                    </p>
                {/if}
            </div>

            <div class="mt-3">
                <div class="mb-1.5 flex items-center justify-between gap-2">
                    <p class="text-xs font-semibold text-stone-800">
                        Foto Selfie
                        <span class="font-normal text-stone-500">
                            (kamera langsung)
                        </span>
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
                        disabled={submitting}
                        onCapture={(payload) => {
                            capturedPhoto = payload;
                            feedback = null;
                        }}
                    />
                {/key}
            </div>

            {#if isLateNow}
                <label
                    class="mt-3 flex items-start gap-2 rounded-md border border-yellow-300 bg-yellow-50 p-2.5 text-[12px] leading-snug text-yellow-900"
                >
                    <input
                        type="checkbox"
                        bind:checked={latePermission}
                        class="mt-0.5 size-4"
                    />
                    <span>
                        <span class="font-semibold">Izin Masuk Siang</span> —
                        terlambat {lateMinutesNow} menit. Wajib isi alasan
                        minimal 10 karakter agar dapat ditinjau HR.
                    </span>
                </label>
            {/if}

            <div class="mt-3">
                <div class="flex items-center justify-between gap-2">
                    <label
                        for="attendance-notes"
                        class="text-xs font-semibold text-stone-800"
                    >
                        Catatan
                    </label>
                    {#if requiresNotes}
                        <span class="text-[11px] font-medium text-red-600">
                            Wajib diisi
                        </span>
                    {:else}
                        <span class="text-[11px] text-stone-500">Opsional</span>
                    {/if}
                </div>
                <textarea
                    id="attendance-notes"
                    bind:value={notes}
                    rows="2"
                    maxlength="500"
                    placeholder={notesPlaceholder}
                    class="mt-1 w-full rounded-md border border-stone-300 bg-white p-2 text-sm leading-snug text-stone-900 placeholder:text-stone-400 focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20"
                ></textarea>
                {#if notesReasons.length > 0}
                    <ul
                        class="mt-1 list-disc space-y-0.5 pl-4 text-[11px] leading-snug text-yellow-800"
                    >
                        {#each notesReasons as reason (reason)}
                            <li>{reason}</li>
                        {/each}
                    </ul>
                {/if}
            </div>

            <Button
                type="button"
                class="mt-3 min-h-12 w-full"
                disabled={submitDisabled}
                onclick={submitAttendance}
            >
                {submitting ? 'Memproses…' : buttonLabel}
            </Button>
            <p class="mt-1.5 text-center text-[10px] text-stone-500">
                Waktu mengikuti jam server {timezoneLabel(office.timezone)} · {office.name}
            </p>
        </section>
    {/if}
</div>
