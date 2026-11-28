<script lang="ts">
    import { router } from '@inertiajs/svelte';
    import AlertTriangle from '@lucide/svelte/icons/alert-triangle';
    import CalendarDays from '@lucide/svelte/icons/calendar-days';
    import CircleCheck from '@lucide/svelte/icons/circle-check';
    import FileText from '@lucide/svelte/icons/file-text';
    import Paperclip from '@lucide/svelte/icons/paperclip';
    import Plus from '@lucide/svelte/icons/plus';
    import AppHead from '@/components/AppHead.svelte';
    import { Button } from '@/components/ui/button';
    import { Input } from '@/components/ui/input';
    import { Sheet, SheetContent } from '@/components/ui/sheet';
    import { readXsrfToken } from '@/lib/gpsClient';

    type LeaveType = 'CUTI' | 'IZIN' | 'SAKIT' | 'DINAS_LUAR';

    type LeaveDay = {
        id: number;
        leave_date: string;
        day_value: string | number;
        status: string;
        cancel_reason: string | null;
    };

    type LeaveRequestItem = {
        id: number;
        type: LeaveType;
        start_date: string;
        end_date: string;
        total_days: string | number;
        destination_location: string | null;
        scheduled_start_time: string | null;
        reason: string;
        status: string;
        reject_reason: string | null;
        reviewed_at: string | null;
        reviewer: { name: string } | null;
        days: LeaveDay[];
        attachment_url: string | null;
    };

    type LeaveCalendar = {
        max_date: string;
        work_dates: string[];
        published_dates: string[];
        requires_published_schedule: boolean;
        min_start_dates: {
            CUTI: string | null;
            IZIN: string;
            SAKIT: string;
            DINAS_LUAR: string;
        };
    };

    type LeaveProps = {
        office: {
            timezone: string;
        };
        balance: {
            year: number;
            base_quota: number;
            prorated_deduction: number;
            collective_leave_deduction: number;
            used_days: number;
            pending_days: number;
            adjustment_days: number;
            available_balance: number;
            eligible_for_annual_leave: boolean;
        };
        requests: LeaveRequestItem[];
        todayDate: string;
        canSubmitDinasLuar: boolean;
        calendar: LeaveCalendar;
        cutiDaysByMonth: Record<string, number>;
    };

    type LeavePageProps = LeaveProps & {
        desktop?: boolean;
    };

    let {
        office,
        balance,
        requests,
        todayDate,
        canSubmitDinasLuar,
        calendar,
        cutiDaysByMonth,
        desktop = false,
    }: LeavePageProps = $props();

    const badgeBase =
        'inline-flex items-center gap-1 rounded border px-1.5 py-0.5 text-[11px] font-medium whitespace-nowrap';
    const toneSuccess = 'border-green-300 bg-green-50 text-green-700';
    const toneWarning = 'border-yellow-300 bg-yellow-50 text-yellow-800';
    const toneError = 'border-red-300 bg-red-50 text-red-700';
    const toneInfo = 'border-blue-300 bg-blue-50 text-blue-700';
    const toneBrand = 'border-primary/30 bg-accent text-accent-foreground';
    const toneNeutral = 'border-stone-300 bg-stone-100 text-stone-700';

    function typeMeta(type: LeaveType): { label: string; className: string } {
        switch (type) {
            case 'CUTI':
                return { label: 'Cuti Tahunan', className: `${badgeBase} ${toneBrand}` };
            case 'IZIN':
                return { label: 'Izin', className: `${badgeBase} ${toneInfo}` };
            case 'SAKIT':
                return { label: 'Sakit', className: `${badgeBase} ${toneError}` };
            case 'DINAS_LUAR':
                return { label: 'Dinas Luar', className: `${badgeBase} ${toneInfo}` };
            default:
                return { label: type, className: `${badgeBase} ${toneNeutral}` };
        }
    }

    function statusMeta(status: string): { label: string; className: string } {
        switch (status) {
            case 'PENDING':
                return { label: 'Menunggu', className: `${badgeBase} ${toneWarning}` };
            case 'APPROVED':
                return { label: 'Disetujui', className: `${badgeBase} ${toneSuccess}` };
            case 'REJECTED':
                return { label: 'Ditolak', className: `${badgeBase} ${toneError}` };
            case 'PARTIALLY_CANCELLED':
                return { label: 'Sebagian dibatalkan', className: `${badgeBase} ${toneInfo}` };
            case 'CANCELLED':
                return { label: 'Dibatalkan', className: `${badgeBase} ${toneNeutral}` };
            default:
                return { label: status, className: `${badgeBase} ${toneNeutral}` };
        }
    }

    function formatDate(value: string | null | undefined): string {
        if (!value) {
            return '-';
        }

        const datePart = value.slice(0, 10);
        const [year, month, day] = datePart.split('-');

        return year && month && day ? `${day}-${month}-${year}` : value;
    }

    function formatDays(value: string | number): string {
        const days = Number(value);

        return Number.isInteger(days) ? String(days) : days.toFixed(1);
    }

    function formatMonth(value: string): string {
        return new Intl.DateTimeFormat('id-ID', {
            month: 'long',
            year: 'numeric',
            timeZone: office.timezone,
        }).format(new Date(`${value}-01T12:00:00Z`));
    }

    function eachDate(start: string, end: string): string[] {
        if (!start || !end || end < start) {
            return [];
        }

        const dates: string[] = [];
        const endMs = Date.parse(`${end}T12:00:00Z`);

        for (let ms = Date.parse(`${start}T12:00:00Z`); ms <= endMs; ms += 86_400_000) {
            dates.push(new Date(ms).toISOString().slice(0, 10));
        }
        return dates;
    }

    let formOpen = $state(false);
    let type = $state<LeaveType>('IZIN');
    // svelte-ignore state_referenced_locally
    let startDate = $state(desktop ? todayDate : '');
    // svelte-ignore state_referenced_locally
    let endDate = $state(desktop ? todayDate : '');
    let reason = $state('');
    let destinationLocation = $state('');
    let scheduledStartTime = $state('09:00');
    let attachment = $state<File | null>(null);
    let attachmentError = $state<string | null>(null);
    let fileInput = $state<HTMLInputElement | null>(null);
    let submitting = $state(false);
    let cancellingId = $state<number | null>(null);
    let formError = $state<string | null>(null);
    let pageFeedback = $state<{
        type: 'success' | 'error';
        message: string;
    } | null>(null);
    let serverWarnings = $state<string[]>([]);

    const typeOptions = $derived.by<
        { value: LeaveType; label: string; hint: string }[]
    >(() => {
        const options: { value: LeaveType; label: string; hint: string }[] = [];

        if (
            balance.eligible_for_annual_leave &&
            calendar.min_start_dates.CUTI !== null &&
            calendar.min_start_dates.CUTI <= calendar.max_date
        ) {
            options.push({
                value: 'CUTI',
                label: 'Cuti Tahunan',
                hint: 'Ajukan minimal H-3 hari kerja, batas normal 2 hari/bulan, tidak bisa mundur tanggal.',
            });
        }

        options.push({
            value: 'IZIN',
            label: 'Izin',
            hint: 'Maksimal 2 hari kerja, boleh susulan maksimal 2 hari kerja.',
        });

        options.push({
            value: 'SAKIT',
            label: 'Sakit',
            hint: 'Boleh susulan maksimal 2 hari kerja dan wajib lampirkan surat dokter (SKD).',
        });

        if (canSubmitDinasLuar) {
            options.push({
                value: 'DINAS_LUAR',
                label: 'Dinas Luar',
                hint: 'Ajukan minimal H-1, wajib isi lokasi tujuan dan jam mulai.',
            });
        }

        return options;
    });

    const selectedTypeHint = $derived(
        typeOptions.find((option) => option.value === type)?.hint ?? '',
    );

    const minStartDate = $derived(
        calendar.min_start_dates[type] ?? todayDate,
    );

    const selectedDates = $derived(eachDate(startDate, endDate));
    const previewCalendarDays = $derived(selectedDates.length);
    const workDateSet = $derived(new Set(calendar.work_dates));
    const publishedDateSet = $derived(new Set(calendar.published_dates));

    const previewWorkDates = $derived(
        selectedDates.filter((date) => workDateSet.has(date)),
    );
    const previewTotalDays = $derived(previewWorkDates.length);

    const unpublishedDates = $derived(
        calendar.requires_published_schedule
            ? selectedDates.filter((date) => !publishedDateSet.has(date))
            : [],
    );

    const monthlyUsage = $derived.by<Record<string, number>>(() => {
        if (type !== 'CUTI') {
            return {};
        }

        const usage: Record<string, number> = { ...cutiDaysByMonth };

        for (const date of previewWorkDates) {
            const month = date.slice(0, 7);
            usage[month] = (usage[month] ?? 0) + 1;
        }

        return usage;
    });

    const monthlyWarningMonths = $derived(
        Object.entries(monthlyUsage)
            .filter(([, value]) => value > 2)
            .map(([month]) => month),
    );

    const invalidRange = $derived(
        startDate === '' || endDate === '' || endDate < startDate,
    );
    const outsideAllowedWindow = $derived(
        startDate < minStartDate || endDate > calendar.max_date,
    );
    const noEffectiveDays = $derived(
        type !== 'DINAS_LUAR' && previewTotalDays <= 0,
    );
    const exceedsBalance = $derived(
        type === 'CUTI' && previewTotalDays > balance.available_balance,
    );
    const izinTooLong = $derived(type === 'IZIN' && previewTotalDays > 2);
    const hasUnpublishedDates = $derived(unpublishedDates.length > 0);
    const dinasIncomplete = $derived(
        type === 'DINAS_LUAR' &&
            (destinationLocation.trim() === '' || scheduledStartTime === ''),
    );
    const reasonInvalid = $derived(reason.trim().length < 3);
    const attachmentMissing = $derived(
        type === 'SAKIT' && attachment === null,
    );
    const hasAttachment = $derived(attachment !== null);

    const canSubmit = $derived(
        !submitting &&
            !invalidRange &&
            !outsideAllowedWindow &&
            !noEffectiveDays &&
            !exceedsBalance &&
            !izinTooLong &&
            !hasUnpublishedDates &&
            !dinasIncomplete &&
            !reasonInvalid &&
            !attachmentMissing &&
            attachmentError === null,
    );

    function chooseType(nextType: LeaveType) {
        type = nextType;
        formError = null;
        const nextMinDate =
            calendar.min_start_dates[nextType] ?? todayDate;
        startDate = nextMinDate;
        endDate = nextMinDate;

        if (nextType !== 'SAKIT' && nextType !== 'IZIN') {
            clearAttachment();
        }
    }

    function openForm() {
        formOpen = true;
        pageFeedback = null;
        serverWarnings = [];
        formError = null;
        reason = '';
        destinationLocation = '';
        scheduledStartTime = '09:00';
        clearAttachment();
        type = 'IZIN';
        startDate = todayDate;
        endDate = todayDate;
    }

    function clearAttachment() {
        attachment = null;
        attachmentError = null;

        if (fileInput) {
            fileInput.value = '';
        }
    }

    function handleFileChange(event: Event) {
        const input = event.currentTarget as HTMLInputElement;
        const file = input.files?.[0] ?? null;
        attachmentError = null;

        if (file === null) {
            attachment = null;
            return;
        }

        if (file.size > 2 * 1024 * 1024) {
            attachment = null;
            attachmentError = 'Ukuran lampiran maksimal 2 MB.';
            input.value = '';
            return;
        }

        if (
            !['image/jpeg', 'image/png', 'application/pdf'].includes(
                file.type,
            )
        ) {
            attachment = null;
            attachmentError = 'Lampiran wajib berupa JPG, PNG, atau PDF.';
            input.value = '';
            return;
        }

        attachment = file;
    }

    async function submitLeave() {
        if (!canSubmit) {
            return;
        }

        submitting = true;
        formError = null;

        try {
            const form = new FormData();
            form.append('type', type);
            form.append('start_date', startDate);
            form.append('end_date', endDate);
            form.append('reason', reason.trim());

            if (type === 'DINAS_LUAR') {
                form.append('destination_location', destinationLocation.trim());
                form.append('scheduled_start_time', scheduledStartTime);
            }

            if (
                (type === 'SAKIT' || type === 'IZIN') &&
                attachment !== null
            ) {
                form.append('attachment', attachment);
            }

            const res = await fetch('/leave', {
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
                monthly_limit_warning?: boolean;
                hr_conflict_warning?: boolean;
            };

            if (!res.ok) {
                const firstError = data.errors
                    ? Object.values(data.errors).flat()[0]
                    : null;
                formError =
                    firstError ??
                    data.message ??
                    'Pengajuan gagal. Periksa kembali data Anda.';
                return;
            }

            serverWarnings = [];

            if (data.monthly_limit_warning) {
                serverWarnings.push(
                    'Cuti melebihi 2 hari/bulan. HR akan meminta konfirmasi sebelum menyetujui.',
                );
            }

            if (data.hr_conflict_warning) {
                serverWarnings.push(
                    'Pada tanggal itu, HR yang masuk kurang dari 2 orang sehingga persetujuan mungkin agak lama.',
                );
            }

            formOpen = false;
            pageFeedback = {
                type: 'success',
                message: 'Pengajuan terkirim. Menunggu cek HR.',
            };
            reason = '';
            destinationLocation = '';
            clearAttachment();
            router.reload();
        } catch {
            formError = 'Koneksi bermasalah. Silakan coba lagi.';
        } finally {
            submitting = false;
        }
    }

    async function cancelRequest(id: number) {
        if (!window.confirm('Batalkan pengajuan ini?')) {
            return;
        }

        cancellingId = id;
        pageFeedback = null;

        try {
            const res = await fetch(`/leave/${id}/cancel`, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'X-XSRF-TOKEN': readXsrfToken(),
                },
            });

            if (!res.ok) {
                const data = (await res.json()) as {
                    message?: string;
                    errors?: Record<string, string[]>;
                };
                pageFeedback = {
                    type: 'error',
                    message:
                        (data.errors
                            ? Object.values(data.errors).flat()[0]
                            : null) ??
                        data.message ??
                        'Gagal membatalkan pengajuan.',
                };
                return;
            }

            pageFeedback = {
                type: 'success',
                message: 'Pengajuan berhasil dibatalkan.',
            };
            router.reload();
        } catch {
            pageFeedback = {
                type: 'error',
                message: 'Koneksi bermasalah. Silakan coba lagi.',
            };
        } finally {
            cancellingId = null;
        }
    }
</script>

<AppHead title={canSubmitDinasLuar ? 'Pengajuan' : 'Cuti & Izin'} />

<div
    class={desktop
        ? 'mx-auto flex w-full max-w-6xl flex-col gap-4 p-4 lg:p-5'
        : 'flex flex-col gap-3'}
>
    <div>
        <h1 class="text-lg font-semibold tracking-tight text-stone-900">
            {canSubmitDinasLuar ? 'Pengajuan' : 'Cuti & Izin'}
        </h1>
        <p class="mt-0.5 text-[11px] text-stone-500">
            {canSubmitDinasLuar
                ? 'Cuti tahunan, izin, sakit, dan dinas luar.'
                : 'Cuti tahunan, izin, dan sakit.'}
        </p>
    </div>

    {#if pageFeedback}
        <div
            role={pageFeedback.type === 'error' ? 'alert' : 'status'}
            class="rounded-lg border p-3 text-[13px] {pageFeedback.type === 'error'
                ? 'border-red-300 bg-red-50 text-red-800'
                : 'border-green-300 bg-green-50 text-green-800'}"
        >
            <p class="flex items-start gap-1.5">
                {#if pageFeedback.type === 'error'}
                    <AlertTriangle class="mt-0.5 size-4 shrink-0" />
                {:else}
                    <CircleCheck class="mt-0.5 size-4 shrink-0" />
                {/if}
                <span>{pageFeedback.message}</span>
            </p>
        </div>
    {/if}

    {#if serverWarnings.length > 0}
        <div
            class="rounded-lg border border-yellow-300 bg-yellow-50 p-3 text-[12px] leading-snug text-yellow-900"
        >
            <p class="flex items-center gap-1.5 font-semibold">
                <AlertTriangle class="size-4 shrink-0" />
                Perhatian
            </p>
            <ul class="mt-1 list-disc space-y-0.5 pl-4">
                {#each serverWarnings as warning (warning)}
                    <li>{warning}</li>
                {/each}
            </ul>
        </div>
    {/if}

    <section class="rounded-xl border border-stone-200 bg-white p-3 shadow-xs">
        <div class="flex items-start justify-between gap-2">
            <div>
                <p
                    class="text-[11px] font-medium uppercase tracking-wide text-stone-500"
                >
                    Saldo Cuti Tahunan {balance.year}
                </p>
                <p
                    class="mt-0.5 text-2xl font-bold tabular-nums {balance.available_balance <
                    0
                        ? 'text-red-600'
                        : 'text-stone-900'}"
                >
                    {formatDays(balance.available_balance)}
                    <span class="text-sm font-medium text-stone-500">hari</span>
                </p>
            </div>
            <span
                class={`${badgeBase} ${balance.eligible_for_annual_leave ? toneSuccess : toneNeutral}`}
            >
                {balance.eligible_for_annual_leave
                    ? 'Cuti aktif'
                    : 'Masa kerja < 1 tahun'}
            </span>
        </div>

        <div class="mt-3 grid grid-cols-4 gap-1.5 text-center">
            <div class="rounded-md border border-stone-200 bg-stone-50 px-1 py-1.5">
                <p class="text-[10px] text-stone-500">Jatah</p>
                <p class="font-mono text-sm font-semibold text-stone-900">
                    {formatDays(balance.base_quota)}
                </p>
            </div>
            <div class="rounded-md border border-stone-200 bg-stone-50 px-1 py-1.5">
                <p class="text-[10px] text-stone-500">Terpakai</p>
                <p class="font-mono text-sm font-semibold text-stone-900">
                    {formatDays(
                        balance.used_days +
                            balance.collective_leave_deduction,
                    )}
                </p>
            </div>
            <div class="rounded-md border border-stone-200 bg-stone-50 px-1 py-1.5">
                <p class="text-[10px] text-stone-500">Menunggu</p>
                <p class="font-mono text-sm font-semibold text-stone-900">
                    {formatDays(balance.pending_days)}
                </p>
            </div>
            <div class="rounded-md border border-stone-200 bg-stone-50 px-1 py-1.5">
                <p class="text-[10px] text-stone-500">Sisa</p>
                <p
                    class="font-mono text-sm font-semibold {balance.available_balance <
                    0
                        ? 'text-red-600'
                        : 'text-stone-900'}"
                >
                    {formatDays(balance.available_balance)}
                </p>
            </div>
        </div>

        <p class="mt-2 text-[11px] leading-snug text-stone-500">
            Prorata -{formatDays(balance.prorated_deduction)} · Cuti bersama
            -{formatDays(balance.collective_leave_deduction)}
            {#if balance.adjustment_days !== 0}
                · Penyesuaian {balance.adjustment_days > 0 ? '+' : ''}{formatDays(
                    balance.adjustment_days,
                )}
            {/if}
        </p>

        {#if !balance.eligible_for_annual_leave}
            <p class="mt-1 text-[11px] leading-snug text-stone-600">
                {canSubmitDinasLuar
                    ? 'Cuti Tahunan aktif setelah 1 tahun kerja. Anda tetap bisa ajukan Izin, Sakit, dan Dinas Luar.'
                    : 'Cuti Tahunan aktif setelah 1 tahun kerja. Anda tetap bisa ajukan Izin dan Sakit.'}
            </p>
        {:else if calendar.min_start_dates.CUTI === null}
            <p class="mt-1 text-[11px] leading-snug text-yellow-800">
                Jadwal 3 bulan ke depan belum terbit, jadi Cuti Tahunan belum
                bisa diajukan.
            </p>
        {/if}
    </section>

    {#if !desktop}
        <Button type="button" class="min-h-12 w-full" onclick={openForm}>
            <Plus class="size-4" />
            {canSubmitDinasLuar
                ? 'Ajukan Cuti / Izin / Sakit / Dinas'
                : 'Ajukan Cuti / Izin / Sakit'}
        </Button>
    {/if}

    <div
        class={desktop
            ? 'grid gap-4 lg:grid-cols-[minmax(0,1fr)_minmax(0,0.95fr)] lg:items-start'
            : ''}
    >
        <section class="space-y-2">
        <div class="flex items-center justify-between gap-2">
            <h2 class="text-sm font-semibold text-stone-900">
                Riwayat Pengajuan
            </h2>
            <span class="text-[11px] text-stone-500">
                {requests.length} pengajuan
            </span>
        </div>

        {#if requests.length === 0}
            <div
                class="rounded-xl border border-dashed border-stone-300 bg-white p-6 text-center"
            >
                <CalendarDays class="mx-auto size-6 text-stone-400" />
                <p class="mt-2 text-sm font-medium text-stone-700">
                    Belum ada pengajuan
                </p>
                <p class="mt-0.5 text-[11px] text-stone-500">
                    Pengajuan
                    {canSubmitDinasLuar
                        ? 'cuti, izin, sakit, dan dinas luar'
                        : 'cuti, izin, dan sakit'}
                    akan tampil di sini.
                </p>
            </div>
        {:else}
            {#each requests as item (item.id)}
                {@const typeInfo = typeMeta(item.type)}
                {@const statusInfo = statusMeta(item.status)}
                {@const cancelledDays = item.days.filter(
                    (day) => day.status === 'CANCELLED',
                )}
                <article
                    class="rounded-xl border border-stone-200 bg-white p-3 shadow-xs"
                >
                    <div class="flex items-start justify-between gap-2">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-1.5">
                                <span class={typeInfo.className}>
                                    {typeInfo.label}
                                </span>
                                <span class="text-[11px] text-stone-500">
                                    {formatDate(item.start_date)}
                                    {#if item.start_date.slice(0, 10) !== item.end_date.slice(0, 10)}
                                        – {formatDate(item.end_date)}
                                    {/if}
                                </span>
                            </div>
                            <p class="mt-1 text-sm font-semibold text-stone-900">
                                {formatDays(item.total_days)} hari kerja efektif
                            </p>
                        </div>
                        <span class={statusInfo.className}>
                            {statusInfo.label}
                        </span>
                    </div>

                    <p class="mt-1.5 text-[12px] leading-snug text-stone-600">
                        {item.reason}
                    </p>

                    {#if item.type === 'DINAS_LUAR'}
                        <p class="mt-1 text-[11px] leading-snug text-blue-700">
                            {item.destination_location ?? '-'} · mulai
                            {item.scheduled_start_time?.slice(0, 5) ?? '-'}
                        </p>
                    {/if}

                    {#if item.reject_reason}
                        <p
                            class="mt-1.5 rounded-md bg-red-50 p-1.5 text-[11px] leading-snug text-red-700"
                        >
                            Alasan ditolak: {item.reject_reason}
                        </p>
                    {/if}

                    {#if cancelledDays.length > 0}
                        <details class="mt-1.5 text-[11px] text-stone-600">
                            <summary class="cursor-pointer font-medium">
                                {cancelledDays.length} tanggal dibatalkan
                            </summary>
                            <ul class="mt-1 space-y-0.5 pl-3">
                                {#each cancelledDays as day (day.id)}
                                    <li>
                                        {formatDate(day.leave_date)}
                                        {#if day.cancel_reason}
                                            : {day.cancel_reason}
                                        {/if}
                                    </li>
                                {/each}
                            </ul>
                        </details>
                    {/if}

                    {#if item.attachment_url}
                        <a
                            href={item.attachment_url}
                            target="_blank"
                            rel="noreferrer"
                            class="mt-1.5 inline-flex items-center gap-1 text-[11px] font-medium text-primary underline-offset-2 hover:underline"
                        >
                            <Paperclip class="size-3.5" />
                            {item.type === 'SAKIT'
                                ? 'Lihat lampiran SKD'
                                : 'Lihat lampiran'}
                        </a>
                    {/if}

                    <div
                        class="mt-2 flex items-center justify-between gap-2 border-t border-stone-100 pt-2"
                    >
                        <span class="text-[10px] text-stone-500">
                            {#if item.reviewer}
                                Ditinjau oleh {item.reviewer.name}
                            {:else}
                                Menunggu cek HR
                            {/if}
                        </span>

                        {#if item.status === 'PENDING'}
                            <Button
                                type="button"
                                size="sm"
                                variant="outline"
                                class="min-h-9"
                                disabled={cancellingId === item.id}
                                onclick={() => cancelRequest(item.id)}
                            >
                                {cancellingId === item.id
                                    ? 'Membatalkan…'
                                    : 'Batalkan'}
                            </Button>
                        {/if}
                    </div>
                </article>
            {/each}
        {/if}
        </section>

        {#if desktop}
            <section
                class="rounded-xl border border-stone-200 bg-white p-3 shadow-xs"
            >
                {@render leaveForm()}
            </section>
        {/if}
    </div>
</div>

{#snippet leaveForm()}
        <div class="pr-8">
            <h2 class="text-base font-semibold text-stone-900">
                {canSubmitDinasLuar
                    ? 'Ajukan Cuti / Izin / Sakit / Dinas'
                    : 'Ajukan Cuti / Izin / Sakit'}
            </h2>
            <p class="mt-0.5 text-[11px] leading-snug text-stone-500">
                Satu pengajuan untuk minimal 1 hari kerja penuh. Izin setengah
                hari cukup lewat catatan Clock-In/Clock-Out.
            </p>
        </div>

        <div class="mt-3 grid grid-cols-2 gap-2">
            {#each typeOptions as option (option.value)}
                <button
                    type="button"
                    class="min-h-11 rounded-md border px-2 text-xs font-medium transition-colors {type ===
                    option.value
                        ? 'border-primary bg-accent text-accent-foreground'
                        : 'border-stone-300 bg-white text-stone-700'}"
                    onclick={() => chooseType(option.value)}
                >
                    {option.label}
                </button>
            {/each}
        </div>

        {#if selectedTypeHint}
            <p class="mt-1.5 text-[11px] leading-snug text-stone-500">
                {selectedTypeHint}
            </p>
        {/if}

        <div class="mt-3 grid grid-cols-2 gap-2">
            <label class="block text-[11px] font-medium text-stone-700">
                Tanggal mulai
                <Input
                    type="date"
                    bind:value={startDate}
                    min={minStartDate}
                    max={calendar.max_date}
                    class="mt-1 h-11"
                    onchange={() => {
                        if (endDate < startDate) {
                            endDate = startDate;
                        }
                    }}
                />
            </label>
            <label class="block text-[11px] font-medium text-stone-700">
                Tanggal selesai
                <Input
                    type="date"
                    bind:value={endDate}
                    min={startDate || minStartDate}
                    max={calendar.max_date}
                    class="mt-1 h-11"
                />
            </label>
        </div>

        <div class="mt-3 rounded-md border border-stone-200 bg-stone-50 p-2.5">
            <div class="flex items-baseline justify-between gap-2">
                <p class="text-[11px] text-stone-600">Hari kerja efektif</p>
                <p
                    class="font-mono text-base font-semibold text-stone-900"
                >
                    {previewTotalDays}
                    <span class="text-[10px] font-normal text-stone-500">
                        dari {previewCalendarDays} hari kalender
                    </span>
                </p>
            </div>

            {#if invalidRange}
                <p class="mt-1 text-[11px] font-medium text-red-700">
                    Tanggal selesai tidak boleh sebelum tanggal mulai.
                </p>
            {/if}

            {#if outsideAllowedWindow}
                <p class="mt-1 text-[11px] font-medium text-red-700">
                    Tanggal harus berada antara {formatDate(minStartDate)} dan
                    {formatDate(calendar.max_date)}.
                </p>
            {/if}

            {#if noEffectiveDays}
                <p class="mt-1 text-[11px] font-medium text-red-700">
                    Rentang tanggal tidak memuat hari kerja efektif. Pilih
                    tanggal lain.
                </p>
            {/if}

            {#if dinasIncomplete}
                <p class="mt-1 text-[11px] font-medium text-red-700">
                    Lengkapi lokasi tujuan dan jam mulai agenda dinas.
                </p>
            {/if}

            {#if type === 'DINAS_LUAR' && previewTotalDays === 0}
                <p class="mt-1 text-[11px] text-blue-700">
                    Dinas pada hari libur/akhir pekan tetap dapat diajukan dengan
                    0 hari kerja efektif.
                </p>
            {/if}

            {#if hasUnpublishedDates}
                <p class="mt-1 text-[11px] font-medium text-red-700">
                    Jadwal kerja belum terbit untuk {unpublishedDates
                        .slice(0, 3)
                        .map(formatDate)
                        .join(', ')}{unpublishedDates.length > 3
                        ? ` dan ${unpublishedDates.length - 3} tanggal lain`
                        : ''}.
                </p>
            {/if}

            {#if exceedsBalance}
                <p class="mt-1 text-[11px] font-medium text-red-700">
                    Saldo cuti tidak mencukupi (tersedia
                    {formatDays(balance.available_balance)} hari).
                </p>
            {/if}

            {#if izinTooLong}
                <p class="mt-1 text-[11px] font-medium text-red-700">
                    Izin maksimal 2 hari kerja efektif.
                </p>
            {/if}

            {#if monthlyWarningMonths.length > 0}
                <p class="mt-1 text-[11px] leading-snug text-yellow-800">
                    Cuti bulan
                    {monthlyWarningMonths.map(formatMonth).join(', ')} melewati
                    batas normal 2 hari/bulan. HR akan meminta konfirmasi.
                </p>
            {/if}
        </div>

        {#if type === 'DINAS_LUAR'}
            <div class="mt-3 space-y-2">
                <label class="block text-[11px] font-medium text-stone-700">
                    Lokasi / kota tujuan
                    <Input
                        bind:value={destinationLocation}
                        placeholder="Contoh: Bandung"
                        class="mt-1 h-11"
                    />
                </label>
                <label class="block text-[11px] font-medium text-stone-700">
                    Jam mulai agenda
                    <Input
                        type="time"
                        bind:value={scheduledStartTime}
                        class="mt-1 h-11"
                    />
                </label>
            </div>
        {/if}

        <label class="mt-3 block text-[11px] font-medium text-stone-700">
            Alasan pengajuan
            <textarea
                bind:value={reason}
                rows="3"
                maxlength="1000"
                placeholder="Jelaskan keperluan pengajuan…"
                class="mt-1 w-full rounded-md border border-stone-300 bg-white p-2 text-sm leading-snug text-stone-900 placeholder:text-stone-400 focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20"
            ></textarea>
        </label>
        {#if reasonInvalid}
            <p class="mt-1 text-[11px] text-stone-500">
                Alasan minimal 3 karakter.
            </p>
        {/if}

        {#if type === 'SAKIT' || type === 'IZIN'}
            <div
                class="mt-3 rounded-md border p-2.5 {type === 'SAKIT'
                    ? hasAttachment
                        ? 'border-green-300 bg-green-50/60'
                        : 'border-red-200 bg-red-50/60'
                    : 'border-stone-200 bg-stone-50'}"
            >
                <p
                    class="flex items-center gap-1.5 text-[11px] font-semibold {type ===
                    'SAKIT'
                        ? hasAttachment
                            ? 'text-green-800'
                            : 'text-red-800'
                        : 'text-stone-700'}"
                >
                    {#if type === 'SAKIT'}
                        {#if hasAttachment}
                            <CircleCheck class="size-3.5" />
                            Surat Keterangan Dokter (terlampir)
                        {:else}
                            <FileText class="size-3.5" />
                            Surat Keterangan Dokter (wajib)
                        {/if}
                    {:else}
                        <Paperclip class="size-3.5" />
                        Lampiran (opsional)
                    {/if}
                </p>
                <input
                    bind:this={fileInput}
                    type="file"
                    accept=".jpg,.jpeg,.png,.pdf"
                    onchange={handleFileChange}
                    class="mt-1.5 block w-full text-[11px] text-stone-700 file:mr-2 file:rounded-md file:border-0 file:bg-white file:px-2 file:py-1 file:text-[11px] file:font-medium file:text-stone-700"
                />
                <p
                    class="mt-1 text-[10px] {type === 'SAKIT'
                        ? hasAttachment
                            ? 'text-green-700'
                            : 'text-red-700'
                        : 'text-stone-500'}"
                >
                    Format JPG/PNG/PDF, maksimal 2 MB.
                </p>
                {#if attachment}
                    <div
                        class="mt-1 flex items-center justify-between gap-2"
                    >
                        <p class="text-[11px] font-medium text-stone-700">
                            {attachment.name} ·
                            {Math.round(attachment.size / 1024)} KB
                        </p>
                        <button
                            type="button"
                            class="text-[11px] font-medium text-red-700 hover:underline"
                            onclick={clearAttachment}
                        >
                            Hapus
                        </button>
                    </div>
                {/if}
                {#if attachmentError}
                    <p class="mt-1 text-[11px] font-medium text-red-700">
                        {attachmentError}
                    </p>
                {/if}
            </div>
        {/if}

        {#if formError}
            <p
                role="alert"
                class="mt-3 rounded-md border border-red-300 bg-red-50 p-2.5 text-[12px] leading-snug text-red-800"
            >
                {formError}
            </p>
        {/if}

        <p class="mt-3 rounded-md bg-stone-100 p-2.5 text-[10px] leading-snug text-stone-600">
            {#if calendar.requires_published_schedule}
                Pilih tanggal sesuai jadwal 3 bulan yang sudah terbit. Hari
                libur tidak dihitung.
            {:else}
                Maksimal 90 hari ke depan. Hari libur tidak dihitung.
            {/if}
        </p>

        <Button
            type="button"
            class="mt-3 min-h-12 w-full"
            disabled={!canSubmit}
            onclick={submitLeave}
        >
            {submitting ? 'Mengirim…' : 'Kirim Pengajuan'}
        </Button>
{/snippet}

{#if !desktop}
    <Sheet bind:open={formOpen}>
        <SheetContent
            side="bottom"
            class="max-h-[92dvh] overflow-y-auto rounded-t-xl p-4 pb-[calc(1rem_+_env(safe-area-inset-bottom))]"
        >
            {@render leaveForm()}
        </SheetContent>
    </Sheet>
{/if}
