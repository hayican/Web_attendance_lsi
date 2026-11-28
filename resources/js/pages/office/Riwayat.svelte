<script lang="ts">
    import { page, router } from '@inertiajs/svelte';
    import CalendarCheck from '@lucide/svelte/icons/calendar-check';
    import ChevronLeft from '@lucide/svelte/icons/chevron-left';
    import ChevronRight from '@lucide/svelte/icons/chevron-right';
    import AppHead from '@/components/AppHead.svelte';
    import { Button } from '@/components/ui/button';

    type OfficeHistoryItem = {
        id: number;
        date: string;
        attempt_no: number;
        shift: string;
        source: string;
        clock_in_at: string | null;
        clock_out_at: string | null;
        clock_in_status: string;
        clock_out_status: string | null;
        clock_in_flags: string[];
        clock_out_flags: string[];
        clock_in_reject_reason: string | null;
        clock_out_reject_reason: string | null;
        late_minutes: number;
        early_leave_minutes: number;
        clock_in_notes: string | null;
        clock_out_notes: string | null;
        clock_in_photo_url: string | null;
        clock_out_photo_url: string | null;
        clock_in_photo_notice: string | null;
        clock_out_photo_notice: string | null;
    };

    type SalesHistoryItem = {
        id: number;
        date: string;
        outlet_name: string;
        outlet_code: string | null;
        outlet_address: string;
        visit_in_at: string | null;
        visit_out_at: string | null;
        duration_minutes: number | null;
        category: string | null;
        visit_notes: string | null;
        visit_in_notes: string | null;
        visit_status: string;
        verification_status: string;
        anomaly_flags: string[];
        reject_reason: string | null;
        source: string;
        photo_url: string | null;
        photo_expired_notice: string | null;
    };

    type HistoryProps = {
        office: {
            timezone: string;
        };
        mode: 'office' | 'sales';
        range: 'month' | '30d';
        month: string;
        todayDate: string;
        items: (OfficeHistoryItem | SalesHistoryItem)[];
    };

    let {
        office,
        mode,
        range,
        month,
        todayDate,
        items,
    }: HistoryProps = $props();

    const badgeBase =
        'inline-flex items-center gap-1 rounded border px-1.5 py-0.5 text-[11px] font-medium whitespace-nowrap';
    const toneSuccess = 'border-green-300 bg-green-50 text-green-700';
    const toneWarning = 'border-yellow-300 bg-yellow-50 text-yellow-800';
    const toneError = 'border-red-300 bg-red-50 text-red-700';
    const toneFraud = 'border-rose-300 bg-rose-50 text-rose-800';
    const toneInfo = 'border-blue-300 bg-blue-50 text-blue-700';
    const toneNeutral = 'border-stone-300 bg-stone-100 text-stone-700';

    const flagLabels: Record<string, string> = {
        OUT_OF_RADIUS: 'Di luar radius',
        OUT_OF_RADIUS_VISIT_OUT: 'Di luar radius',
        UNCERTAIN_ACCURACY: 'Akurasi GPS meragukan',
        LOW_ACCURACY: 'Sinyal GPS lemah',
        SHARED_DEVICE: 'Perangkat dipakai bersama',
        EARLY_LEAVE: 'Pulang lebih awal',
        LATE_PERMISSION: 'Izin masuk siang',
        MISSED_CLOCK_OUT: 'Belum Clock-Out',
        MISSED_VISIT_OUT: 'Belum Visit Out',
        OFFSITE_DUTY: 'Dinas luar',
        OFF_DAY_ATTENDANCE: 'Hadir di hari libur',
        IMPOSSIBLE_TRAVEL: 'Perpindahan lokasi terlalu cepat',
        REPEATED_COORDS: 'Titik GPS berulang',
        CONSTANT_ACCURACY: 'Akurasi GPS selalu sama',
        STALE_TIMESTAMP: 'Data GPS kedaluwarsa',
        SHORT_DURATION: 'Kunjungan terlalu singkat',
        UNVERIFIED_OUTLET: 'Toko baru belum diverifikasi',
    };

    function verificationMeta(status: string | null): {
        label: string;
        className: string;
    } {
        switch (status) {
            case 'AUTO_APPROVED':
                return { label: 'Otomatis disetujui', className: `${badgeBase} ${toneSuccess}` };
            case 'APPROVED':
                return { label: 'Disetujui', className: `${badgeBase} ${toneSuccess}` };
            case 'PENDING_REVIEW':
                return { label: 'Menunggu cek HR', className: `${badgeBase} ${toneWarning}` };
            case 'REJECTED':
                return { label: 'Ditolak', className: `${badgeBase} ${toneError}` };
            default:
                return { label: '-', className: `${badgeBase} ${toneNeutral}` };
        }
    }

    function visitStatusMeta(status: string): {
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
                return { label: 'Ditutup otomatis', className: `${badgeBase} ${toneWarning}` };
            default:
                return { label: status, className: `${badgeBase} ${toneNeutral}` };
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

        if (['OFFSITE_DUTY', 'OFF_DAY_ATTENDANCE'].includes(flag)) {
            return `${badgeBase} ${toneInfo}`;
        }

        if (
            ['OUT_OF_RADIUS', 'OUT_OF_RADIUS_VISIT_OUT', 'UNCERTAIN_ACCURACY', 'LOW_ACCURACY', 'EARLY_LEAVE', 'LATE_PERMISSION', 'MISSED_CLOCK_OUT', 'MISSED_VISIT_OUT', 'SHORT_DURATION', 'UNVERIFIED_OUTLET'].includes(
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

    function formatDate(value: string): string {
        const [year, month, day] = value.slice(0, 10).split('-');

        return year && month && day ? `${day}-${month}-${year}` : value;
    }

    function monthLabel(value: string): string {
        return new Intl.DateTimeFormat('id-ID', {
            month: 'long',
            year: 'numeric',
            timeZone: office.timezone,
        }).format(new Date(`${value}-01T12:00:00Z`));
    }

    const isHrAdmin = $derived(
        (page.props.auth.user as { role?: string }).role === 'HR_ADMIN',
    );
    const currentMonth = $derived(todayDate.slice(0, 7));
    const canGoNextMonth = $derived(month < currentMonth);

    const officeItems = $derived(
        mode === 'office' ? (items as OfficeHistoryItem[]) : [],
    );
    const salesItems = $derived(
        mode === 'sales' ? (items as SalesHistoryItem[]) : [],
    );

    function applyRange(nextRange: 'month' | '30d') {
        router.get(
            '/attendance/history',
            { range: nextRange, month },
            {
                preserveState: true,
                preserveScroll: true,
                replace: true,
            },
        );
    }

    function shiftMonth(delta: number) {
        const [year, monthNumber] = month.split('-').map(Number);
        const cursor = new Date(Date.UTC(year, (monthNumber ?? 1) - 1 + delta, 1));
        const nextMonth = `${cursor.getUTCFullYear()}-${String(
            cursor.getUTCMonth() + 1,
        ).padStart(2, '0')}`;

        if (delta > 0 && nextMonth > currentMonth) {
            return;
        }

        router.get(
            '/attendance/history',
            { range: 'month', month: nextMonth },
            {
                preserveState: true,
                preserveScroll: true,
                replace: true,
            },
        );
    }
</script>

{#snippet photo(
    url: string | null,
    notice: string | null,
    label: string,
)}
    {#if url}
        <a
            href={url}
            target="_blank"
            rel="noreferrer"
            class="inline-flex h-16 w-16 overflow-hidden rounded-md border border-stone-200 bg-stone-100"
            aria-label={label}
        >
            <img
                src={url}
                alt={label}
                loading="lazy"
                class="h-full w-full object-cover"
            />
        </a>
    {:else}
        <p class="text-[10px] leading-snug text-stone-500">
            {notice ?? 'Foto sudah dihapus (lewat 12 bulan)'}
        </p>
    {/if}
{/snippet}

<AppHead title="Riwayat Absensi" />

<div
    class="mx-auto flex w-full flex-col gap-3 {isHrAdmin
        ? 'max-w-3xl p-4'
        : ''}"
>
    <div>
        <h1 class="text-lg font-semibold tracking-tight text-stone-900">
            Riwayat {mode === 'sales' ? 'Kunjungan Sales' : 'Absensi'}
        </h1>
        <p class="mt-0.5 text-[11px] text-stone-500">
            Menampilkan riwayat milik akun Anda.
        </p>
    </div>

    <section class="rounded-xl border border-stone-200 bg-white p-3 shadow-xs">
        <div class="grid grid-cols-2 gap-2">
            <Button
                type="button"
                variant={range === '30d' ? 'default' : 'outline'}
                class="min-h-11"
                onclick={() => applyRange('30d')}
            >
                30 Hari Terakhir
            </Button>
            <Button
                type="button"
                variant={range === 'month' ? 'default' : 'outline'}
                class="min-h-11"
                onclick={() => applyRange('month')}
            >
                Per Bulan
            </Button>
        </div>

        {#if range === 'month'}
            <div
                class="mt-2 flex items-center justify-between gap-2 rounded-md border border-stone-200 bg-stone-50 p-1"
            >
                <Button
                    type="button"
                    size="icon"
                    variant="ghost"
                    class="min-h-11 min-w-11"
                    aria-label="Bulan sebelumnya"
                    onclick={() => shiftMonth(-1)}
                >
                    <ChevronLeft class="size-4" />
                </Button>
                <span class="text-xs font-semibold text-stone-800">
                    {monthLabel(month)}
                </span>
                <Button
                    type="button"
                    size="icon"
                    variant="ghost"
                    class="min-h-11 min-w-11"
                    disabled={!canGoNextMonth}
                    aria-label="Bulan berikutnya"
                    onclick={() => shiftMonth(1)}
                >
                    <ChevronRight class="size-4" />
                </Button>
            </div>
        {:else}
            <p class="mt-2 text-center text-[11px] text-stone-500">
                30 hari terakhir sampai {formatDate(todayDate)}
            </p>
        {/if}

        <p class="mt-2 text-[11px] text-stone-500">
            {items.length} catatan pada periode ini.
        </p>
    </section>

    {#if items.length === 0}
        <div
            class="rounded-xl border border-dashed border-stone-300 bg-white p-6 text-center"
        >
            <CalendarCheck class="mx-auto size-6 text-stone-400" />
            <p class="mt-2 text-sm font-medium text-stone-700">
                Belum ada riwayat
            </p>
            <p class="mt-0.5 text-[11px] text-stone-500">
                Belum ada absensi atau kunjungan pada periode ini.
            </p>
        </div>
    {:else if mode === 'office'}
        {#each officeItems as item (item.id)}
            {@const clockInMeta = verificationMeta(item.clock_in_status)}
            {@const clockOutMeta = verificationMeta(item.clock_out_status)}
            <article
                class="rounded-xl border border-stone-200 bg-white p-3 shadow-xs"
            >
                <div class="flex items-start justify-between gap-2">
                    <div>
                        <p
                            class="text-[11px] font-medium uppercase tracking-wide text-stone-500"
                        >
                            {formatDate(item.date)}
                        </p>
                        <p class="mt-0.5 text-sm font-semibold text-stone-900">
                            Shift {item.shift} · Percobaan #{item.attempt_no}
                        </p>
                    </div>
                    {#if item.source === 'MANUAL_HR'}
                        <span class={`${badgeBase} ${toneInfo}`}>
                            Input Manual HR
                        </span>
                    {/if}
                </div>

                <div class="mt-2 grid grid-cols-2 gap-2">
                    <div
                        class="rounded-md border border-stone-200 bg-stone-50 p-2"
                    >
                        <p class="text-[10px] text-stone-500">Masuk</p>
                        <div class="mt-0.5 flex flex-wrap items-center gap-1">
                            <span
                                class="font-mono text-sm font-semibold tabular-nums text-stone-900"
                            >
                                {item.clock_in_at ?? '-'}
                            </span>
                            <span class={clockInMeta.className}>
                                {clockInMeta.label}
                            </span>
                        </div>
                    </div>
                    <div
                        class="rounded-md border border-stone-200 bg-stone-50 p-2"
                    >
                        <p class="text-[10px] text-stone-500">Pulang</p>
                        <div class="mt-0.5 flex flex-wrap items-center gap-1">
                            <span
                                class="font-mono text-sm font-semibold tabular-nums text-stone-900"
                            >
                                {item.clock_out_at ?? '-'}
                            </span>
                            {#if item.clock_out_status}
                                <span class={clockOutMeta.className}>
                                    {clockOutMeta.label}
                                </span>
                            {/if}
                        </div>
                    </div>
                </div>

                {#if item.late_minutes > 0 || item.early_leave_minutes > 0}
                    <div class="mt-2 flex flex-wrap gap-1">
                        {#if item.late_minutes > 0}
                            <span class={`${badgeBase} ${toneWarning}`}>
                                Terlambat {item.late_minutes} menit
                            </span>
                        {/if}
                        {#if item.early_leave_minutes > 0}
                            <span class={`${badgeBase} ${toneWarning}`}>
                                Pulang awal {item.early_leave_minutes} menit
                            </span>
                        {/if}
                    </div>
                {/if}

                {#if item.clock_in_flags.length > 0 || item.clock_out_flags.length > 0}
                    <div class="mt-2 flex flex-wrap gap-1">
                        {#each item.clock_in_flags as flag (flag)}
                            <span class={flagBadge(flag)}>{flagLabel(flag)}</span>
                        {/each}
                        {#each item.clock_out_flags as flag (flag)}
                            <span class={flagBadge(flag)}>{flagLabel(flag)}</span>
                        {/each}
                    </div>
                {/if}

                {#if item.clock_in_reject_reason || item.clock_out_reject_reason}
                    <div
                        class="mt-2 space-y-1 rounded-md border border-red-200 bg-red-50 p-2 text-[11px] leading-snug text-red-800"
                    >
                        {#if item.clock_in_reject_reason}
                            <p>Masuk ditolak: {item.clock_in_reject_reason}</p>
                        {/if}
                        {#if item.clock_out_reject_reason}
                            <p>Pulang ditolak: {item.clock_out_reject_reason}</p>
                        {/if}
                    </div>
                {/if}

                {#if item.clock_in_notes || item.clock_out_notes}
                    <div class="mt-2 space-y-0.5 text-[11px] text-stone-600">
                        {#if item.clock_in_notes}
                            <p>Catatan masuk: {item.clock_in_notes}</p>
                        {/if}
                        {#if item.clock_out_notes}
                            <p>Catatan pulang: {item.clock_out_notes}</p>
                        {/if}
                    </div>
                {/if}

                {#if item.clock_in_at || item.clock_out_at || item.clock_in_photo_url || item.clock_out_photo_url || item.clock_in_photo_notice || item.clock_out_photo_notice}
                    <div class="mt-2 flex items-end gap-2">
                        {#if item.clock_in_at || item.clock_in_photo_url || item.clock_in_photo_notice}
                            <div class="space-y-1">
                                <p class="text-[10px] text-stone-500">
                                    Foto masuk
                                </p>
                                {@render photo(
                                    item.clock_in_photo_url,
                                    item.clock_in_photo_notice,
                                    'Foto Clock-In',
                                )}
                            </div>
                        {/if}
                        {#if item.clock_out_at || item.clock_out_photo_url || item.clock_out_photo_notice}
                            <div class="space-y-1">
                                <p class="text-[10px] text-stone-500">
                                    Foto pulang
                                </p>
                                {@render photo(
                                    item.clock_out_photo_url,
                                    item.clock_out_photo_notice,
                                    'Foto Clock-Out',
                                )}
                            </div>
                        {/if}
                    </div>
                {/if}
            </article>
        {/each}
    {:else}
        {#each salesItems as item (item.id)}
            {@const visitMeta = visitStatusMeta(item.visit_status)}
            {@const verification = verificationMeta(item.verification_status)}
            <article
                class="rounded-xl border border-stone-200 bg-white p-3 shadow-xs"
            >
                <div class="flex items-start justify-between gap-2">
                    <div class="min-w-0">
                        <p
                            class="text-[11px] font-medium uppercase tracking-wide text-stone-500"
                        >
                            {formatDate(item.date)}
                        </p>
                        <p class="mt-0.5 truncate text-sm font-semibold text-stone-900">
                            {item.outlet_name}
                        </p>
                        <p class="text-[11px] text-stone-500">
                            {item.outlet_code ?? 'Toko baru'} · {item.outlet_address}
                        </p>
                    </div>
                    <div class="flex shrink-0 flex-col items-end gap-1">
                        <span class={visitMeta.className}>
                            {visitMeta.label}
                        </span>
                        <span class={verification.className}>
                            {verification.label}
                        </span>
                    </div>
                </div>

                <div class="mt-2 grid grid-cols-3 gap-2 text-[11px]">
                    <div class="rounded-md border border-stone-200 bg-stone-50 p-2">
                        <p class="text-[10px] text-stone-500">Visit In</p>
                        <p class="mt-0.5 font-mono text-sm font-semibold tabular-nums">
                            {item.visit_in_at ?? '-'}
                        </p>
                    </div>
                    <div class="rounded-md border border-stone-200 bg-stone-50 p-2">
                        <p class="text-[10px] text-stone-500">Visit Out</p>
                        <p class="mt-0.5 font-mono text-sm font-semibold tabular-nums">
                            {item.visit_out_at ?? '-'}
                        </p>
                    </div>
                    <div class="rounded-md border border-stone-200 bg-stone-50 p-2">
                        <p class="text-[10px] text-stone-500">Durasi</p>
                        <p class="mt-0.5 font-mono text-sm font-semibold tabular-nums">
                            {item.duration_minutes !== null
                                ? `${item.duration_minutes} menit`
                                : '-'}
                        </p>
                    </div>
                </div>

                {#if item.category}
                    <p class="mt-2 text-[11px] text-stone-600">
                        Aktivitas: {item.category}
                    </p>
                {/if}

                {#if item.visit_notes || item.visit_in_notes}
                    <p class="mt-1.5 text-[12px] leading-snug text-stone-600">
                        {item.visit_notes ?? item.visit_in_notes}
                    </p>
                {/if}

                {#if item.anomaly_flags.length > 0}
                    <div class="mt-2 flex flex-wrap gap-1">
                        {#each item.anomaly_flags as flag (flag)}
                            <span class={flagBadge(flag)}>{flagLabel(flag)}</span>
                        {/each}
                    </div>
                {/if}

                {#if item.reject_reason}
                    <p
                        class="mt-2 rounded-md border border-red-200 bg-red-50 p-2 text-[11px] leading-snug text-red-800"
                    >
                        Alasan ditolak: {item.reject_reason}
                    </p>
                {/if}

                <div class="mt-2 flex items-end gap-2">
                    <div class="space-y-1">
                        <p class="text-[10px] text-stone-500">Foto Visit In</p>
                        {@render photo(
                            item.photo_url,
                            item.photo_expired_notice,
                            'Foto Visit In',
                        )}
                    </div>
                </div>
            </article>
        {/each}
    {/if}
</div>
