<script lang="ts">
    import { onDestroy, onMount } from 'svelte';
    import { router } from '@inertiajs/svelte';
    import AppHead from '@/components/AppHead.svelte';
    import LiveCameraCapture from '@/components/LiveCameraCapture.svelte';
    import { Button } from '@/components/ui/button';
    import {
        isInAppBrowser,
        recommendedExternalBrowserInstruction,
    } from '@/lib/deviceCheck';
    import {
        type FreshGpsResult,
        readXsrfToken,
        requestFreshGps,
    } from '@/lib/gpsClient';

    type OfficeProps = {
        office: {
            name: string;
            radius_m: number;
            timezone: string;
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
        activeLog: {
            id: number;
            clock_in_at: string;
            clock_out_at: string | null;
            clock_in_status: string;
            clock_out_status: string | null;
            late_minutes: number;
        } | null;
        rejectedLog: {
            id: number;
            clock_in_reject_reason: string | null;
        } | null;
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
        activeLog,
        rejectedLog,
        canRetryRejected,
    }: OfficeProps = $props();

    let inAppWarning = $state(false);
    let gps = $state<FreshGpsResult | null>(null);
    let capturedPhoto = $state<{ blob: Blob; cameraToken: string } | null>(
        null,
    );
    let notes = $state('');
    let latePermission = $state(false);
    let submitting = $state(false);
    let feedback = $state<{ type: 'error' | 'success'; message: string } | null>(
        null,
    );
    let clockOffsetMs = $state(0);
    let nowText = $state('');
    let nowComparable = $state('');
    let timer: ReturnType<typeof setInterval> | null = null;

    function updateClock() {
        const current = new Date(Date.now() + clockOffsetMs);
        nowText = new Intl.DateTimeFormat('id-ID', {
            timeZone: office.timezone,
            hour: '2-digit',
            minute: '2-digit',
            second: '2-digit',
            hour12: false,
        }).format(current);
        nowComparable = new Intl.DateTimeFormat('en-GB', {
            timeZone: office.timezone,
            hour: '2-digit',
            minute: '2-digit',
            hour12: false,
        }).format(current);
    }

    async function refreshGps() {
        gps = await requestFreshGps(office.radius_m);
    }

    onMount(() => {
        clockOffsetMs = new Date(serverTimeIso).getTime() - Date.now();
        updateClock();
        timer = setInterval(updateClock, 1000);
        inAppWarning = isInAppBrowser();
        void refreshGps();
    });

    onDestroy(() => {
        if (timer) {
            clearInterval(timer);
        }
    });

    const isClockOutMode = $derived(
        activeLog !== null && activeLog.clock_out_at === null,
    );
    const isFinishedToday = $derived(
        activeLog !== null && activeLog.clock_out_at !== null,
    );

    const lateLimit = $derived.by(() => {
        if (!shift) return '';

        const [hour = 0, minute = 0] = shift.start_time.split(':').map(Number);
        const total = hour * 60 + minute + shift.grace_period_minutes;

        return `${String(Math.floor(total / 60) % 24).padStart(2, '0')}:${String(total % 60).padStart(2, '0')}`;
    });

    const isLateNow = $derived(
        !isClockOutMode &&
            !isDayOff &&
            dinasLuar === null &&
            shift !== null &&
            nowComparable !== '' &&
            nowComparable > lateLimit,
    );

    async function submitAttendance() {
        if (!gps?.gpsSessionToken || gps.lat === null || gps.lng === null) {
            feedback = {
                type: 'error',
                message: 'Lokasi GPS belum siap. Tekan Muat Ulang GPS.',
            };
            return;
        }

        if (!capturedPhoto) {
            feedback = {
                type: 'error',
                message: 'Ambil foto selfie kamera langsung terlebih dahulu.',
            };
            return;
        }

        submitting = true;
        feedback = null;

        try {
            const form = new FormData();
            form.append('idempotency_key', crypto.randomUUID());
            form.append('lat', String(gps.lat));
            form.append('lng', String(gps.lng));
            form.append('accuracy_m', String(gps.accuracyM ?? 0));
            form.append('gps_session_token', gps.gpsSessionToken);
            form.append('camera_token', capturedPhoto.cameraToken);
            form.append('photo', capturedPhoto.blob, 'selfie.jpg');
            if (notes.trim()) {
                form.append('notes', notes.trim());
            }
            if (isLateNow && latePermission) {
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

<div class="mx-auto flex w-full max-w-md flex-col gap-4 p-4">
    {#if inAppWarning}
        <div
            class="rounded-lg border border-amber-500/50 bg-amber-50 p-3 text-sm text-amber-900"
        >
            {recommendedExternalBrowserInstruction()}
        </div>
    {/if}

    <div class="rounded-xl border bg-card p-4 shadow-xs">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-xs text-muted-foreground">{office.name}</p>
                <p class="text-2xl font-bold tabular-nums">{nowText}</p>
            </div>
            <div class="text-right text-xs text-muted-foreground">
                <p>{workDate}</p>
                <p>
                    {#if shift}
                        {shift.code} ({shift.start_time.slice(0, 5)} - {shift.end_time.slice(0, 5)})
                    {:else}
                        Fleksibel / Libur
                    {/if}
                </p>
            </div>
        </div>

        {#if dinasLuar}
            <p class="mt-2 rounded-md bg-blue-50 p-2 text-xs text-blue-800">
                {dinasLuar.status === 'PENDING'
                    ? 'Mode Dinas Luar (menunggu persetujuan HR)'
                    : 'Mode Dinas Luar Disetujui'}:
                {dinasLuar.destination_location} (Acuan
                {dinasLuar.scheduled_start_time.slice(0, 5)})
            </p>
        {/if}

        {#if isDayOff}
            <p class="mt-2 rounded-md bg-muted p-2 text-xs text-muted-foreground">
                Hari ini terjadwal OFF/Libur. Absen tetap dibuka sebagai lembur
                (menunggu review HR).
            </p>
        {/if}
    </div>

    {#if rejectedLog}
        <div
            class="rounded-lg border border-destructive/50 bg-destructive/10 p-3 text-sm text-destructive"
        >
            <p class="font-semibold">Clock-In Ditolak HR</p>
            <p class="mt-1">
                Alasan: {rejectedLog.clock_in_reject_reason ?? '-'}
            </p>
            {#if !canRetryRejected}
                <p class="mt-1 text-xs">
                    Batas waktu absen ulang sudah berakhir. Hubungi HR Admin.
                </p>
            {/if}
        </div>
    {/if}

    {#if hasApprovedTimeOff}
        <div class="rounded-lg border bg-muted p-4 text-sm">
            Anda tidak dapat absen karena sedang dalam status Cuti/Izin/Sakit
            yang telah disetujui hari ini.
        </div>
    {:else if isFinishedToday}
        <div
            class="rounded-lg border border-green-500/40 bg-green-50 p-4 text-sm text-green-900"
        >
            Absensi hari ini sudah lengkap (Clock-In &amp; Clock-Out tercatat).
        </div>
    {:else}
        <div class="flex flex-col gap-3 rounded-xl border bg-card p-4">
            <div class="flex items-center justify-between gap-2">
                <span class="text-sm font-medium">
                    GPS:
                    {#if gps?.status === 'OK'}
                        Akurat (+/-{Math.round(gps.accuracyM ?? 0)}m)
                    {:else if gps?.status === 'POOR_ACCURACY'}
                        Kurang akurat (+/-{Math.round(gps.accuracyM ?? 0)}m)
                    {:else}
                        Belum siap
                    {/if}
                </span>
                <Button
                    type="button"
                    size="sm"
                    variant="outline"
                    onclick={refreshGps}
                >
                    Muat Ulang GPS
                </Button>
            </div>

            {#if gps?.message}
                <p class="text-xs text-amber-700">{gps.message}</p>
            {/if}

            <LiveCameraCapture
                facingMode="user"
                disabled={submitting || gps?.status === 'PERMISSION_DENIED'}
                onCapture={(payload) => {
                    capturedPhoto = payload;
                }}
            />

            {#if isLateNow}
                <label class="flex items-center gap-2 text-xs">
                    <input type="checkbox" bind:checked={latePermission} />
                    <span>Izin Masuk Siang (wajib catatan minimal 10 karakter)</span>
                </label>
            {/if}

            <textarea
                bind:value={notes}
                rows="2"
                placeholder="Alasan / catatan (wajib jika di luar radius, akurasi rendah, atau pulang awal)"
                class="w-full rounded-md border bg-background p-2 text-sm"
            ></textarea>

            {#if feedback}
                <p
                    class="rounded-md p-2 text-sm {feedback.type === 'error'
                        ? 'bg-destructive/10 text-destructive'
                        : 'bg-green-50 text-green-800'}"
                >
                    {feedback.message}
                </p>
            {/if}

            <Button
                type="button"
                class="min-h-12 w-full"
                disabled={submitting ||
                    gps?.status === 'PERMISSION_DENIED' ||
                    (rejectedLog !== null && !canRetryRejected)}
                onclick={submitAttendance}
            >
                {isClockOutMode ? 'Clock-Out Sekarang' : 'Clock-In Sekarang'}
            </Button>
        </div>
    {/if}
</div>
