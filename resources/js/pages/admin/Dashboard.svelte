<script module lang="ts">
    export const layout = {
        breadcrumbs: [
            {
                title: 'Dashboard Rekap',
                href: '/admin/dashboard',
            },
        ],
    };
</script>

<script lang="ts">
    import { page, router } from '@inertiajs/svelte';
    import AlertTriangle from '@lucide/svelte/icons/alert-triangle';
    import Check from '@lucide/svelte/icons/check';
    import Clock from '@lucide/svelte/icons/clock';
    import Download from '@lucide/svelte/icons/download';
    import ShieldAlert from '@lucide/svelte/icons/shield-alert';
    import Users from '@lucide/svelte/icons/users';
    import X from '@lucide/svelte/icons/x';
    import AppHead from '@/components/AppHead.svelte';
    import VerificationMap from '@/components/VerificationMap.svelte';
    import { Button } from '@/components/ui/button';
    import { Input } from '@/components/ui/input';
    import { readXsrfToken } from '@/lib/gpsClient';

    type QueueItem = {
        kind: 'attendance' | 'visit';
        id: number;
        phase: 'clock_in' | 'clock_out' | 'visit';
        user_id: number;
        nik: string;
        name: string;
        date: string;
        location_name: string;
        target_lat: number;
        target_lng: number;
        radius_m: number;
        user_lat: number | null;
        user_lng: number | null;
        accuracy_m: number | null;
        flags: string[];
        notes: string | null;
        photo_url: string | null;
        reference_photo_url: string | null;
        bulk_eligible: boolean;
    };

    type RecapRow = {
        user_id: number;
        nik: string;
        name: string;
        role: string;
        branch: string;
        shift: string;
        clock_in: string | null;
        clock_out: string | null;
        late_minutes: number;
        visit_count: number;
        attendance_status: string;
        verification_status: string;
        source: string;
    };

    type DashboardProps = {
        selectedDate: string;
        showSingleHrBanner: boolean;
        dailyRecap: RecapRow[];
        pendingQueue: QueueItem[];
        employees: { id: number; nik: string; name: string; role: string }[];
        shifts: { id: number; code: string; name: string }[];
    };

    let {
        selectedDate,
        showSingleHrBanner,
        dailyRecap,
        pendingQueue,
        employees,
        shifts,
    }: DashboardProps = $props();

    const currentUserId = $derived(page.props.auth.user?.id ?? 0);

    let filterDate = $state('');
    let filterBranch = $state('');
    let filterRole = $state('');
    let filterStatus = $state('');
    let filterFlag = $state('');

    let activeReview = $state<QueueItem | null>(null);
    let rejectReason = $state('');
    let manualOutTime = $state('');
    let selectedKeys = $state<string[]>([]);

    let manualUserId = $state<number | ''>('');
    let manualDate = $state('');
    let manualShiftId = $state<number | ''>('');
    let manualIn = $state('08:00');
    let manualOut = $state('');
    let manualReason = $state('');

    let exportStart = $state('');
    let exportEnd = $state('');
    let feedback = $state<string | null>(null);
    let busy = $state(false);

    $effect(() => {
        if (!filterDate) {
            filterDate = selectedDate;
            manualDate = selectedDate;
            exportStart = selectedDate;
            exportEnd = selectedDate;
        }
    });

    const branches = $derived(
        Array.from(new Set(dailyRecap.map((r) => r.branch))).sort(),
    );

    const filteredRecap = $derived(
        dailyRecap.filter((r) => {
            if (filterBranch && r.branch !== filterBranch) return false;
            if (filterRole && r.role !== filterRole) return false;
            if (filterStatus && !r.attendance_status.includes(filterStatus))
                return false;
            return true;
        }),
    );

    const filteredQueue = $derived(
        pendingQueue.filter((q) => {
            if (filterFlag && !q.flags.includes(filterFlag)) return false;
            return true;
        }),
    );

    const kpi = $derived({
        total: dailyRecap.length,
        hadir: dailyRecap.filter(
            (r) =>
                r.attendance_status === 'Hadir' ||
                r.attendance_status === 'Dinas Luar',
        ).length,
        terlambat: dailyRecap.filter((r) => r.attendance_status === 'Terlambat')
            .length,
        absen: dailyRecap.filter(
            (r) =>
                r.attendance_status.startsWith('Belum Absen') ||
                r.attendance_status.startsWith('Alpa'),
        ).length,
        review: pendingQueue.length,
    });

    const badgeBase =
        'inline-flex items-center gap-1 rounded px-1.5 py-0.5 text-[11px] font-medium border whitespace-nowrap';

    function statusBadgeClass(status: string): string {
        if (
            status === 'Hadir' ||
            status === 'AUTO_APPROVED' ||
            status === 'APPROVED'
        ) {
            return `${badgeBase} text-green-700 bg-green-50 border-green-300`;
        }
        if (
            status === 'Dinas Luar' ||
            status === 'MANUAL_HR' ||
            status === 'PARTIALLY_CANCELLED'
        ) {
            return `${badgeBase} text-blue-700 bg-blue-50 border-blue-300`;
        }
        if (status.startsWith('Alpa') || status === 'REJECTED') {
            return `${badgeBase} text-red-700 bg-red-50 border-red-300`;
        }
        if (
            status === 'Terlambat' ||
            status.startsWith('Belum Absen') ||
            status === 'PENDING_REVIEW'
        ) {
            return `${badgeBase} text-yellow-800 bg-yellow-50 border-yellow-300`;
        }
        return `${badgeBase} text-stone-700 bg-stone-100 border-stone-300`;
    }

    function flagBadgeClass(flag: string): string {
        const critical = [
            'SHARED_DEVICE',
            'IMPOSSIBLE_TRAVEL',
            'REPEATED_COORDS',
            'CONSTANT_ACCURACY',
            'STALE_TIMESTAMP',
        ];
        if (critical.includes(flag)) {
            return `${badgeBase} text-rose-800 bg-rose-50 border-rose-300`;
        }
        return `${badgeBase} text-yellow-800 bg-yellow-50 border-yellow-300`;
    }

    function itemKey(item: QueueItem): string {
        return `${item.kind}:${item.id}:${item.phase}`;
    }

    function applyDateFilter() {
        if (filterDate && filterDate !== selectedDate) {
            router.get('/admin/dashboard', { date: filterDate });
        }
    }

    function openReviewModal(item: QueueItem) {
        activeReview = item;
        rejectReason = '';
        manualOutTime = '';
    }

    async function reviewOne(item: QueueItem, decision: 'APPROVED' | 'REJECTED') {
        if (decision === 'REJECTED' && !rejectReason.trim()) {
            feedback = 'Alasan penolakan wajib diisi.';
            return;
        }

        busy = true;
        feedback = null;

        try {
            const url =
                item.kind === 'attendance'
                    ? `/admin/attendance/${item.id}/review`
                    : `/admin/visits/${item.id}/review`;

            const payload: Record<string, string | null> = {
                phase: item.phase,
                decision,
                reject_reason:
                    decision === 'REJECTED' ? rejectReason.trim() : null,
            };

            if (
                decision === 'APPROVED' &&
                manualOutTime &&
                item.flags.includes('MISSED_CLOCK_OUT')
            ) {
                payload.clock_out_at = `${item.date} ${manualOutTime}:00`;
            }
            if (
                decision === 'APPROVED' &&
                manualOutTime &&
                item.flags.includes('MISSED_VISIT_OUT')
            ) {
                payload.visit_out_at = `${item.date} ${manualOutTime}:00`;
            }

            const res = await fetch(url, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-XSRF-TOKEN': readXsrfToken(),
                },
                body: JSON.stringify(payload),
            });

            if (!res.ok) {
                const err = (await res.json()) as { message?: string };
                feedback = err.message ?? 'Gagal memproses verifikasi.';
                return;
            }

            activeReview = null;
            router.reload();
        } finally {
            busy = false;
        }
    }

    async function runBulkApprove() {
        const items = filteredQueue
            .filter(
                (q) =>
                    selectedKeys.includes(itemKey(q)) &&
                    q.bulk_eligible &&
                    q.user_id !== currentUserId,
            )
            .map((q) => ({ kind: q.kind, id: q.id, phase: q.phase }));

        if (items.length === 0) return;

        busy = true;
        try {
            const res = await fetch('/admin/reviews/bulk-approve', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-XSRF-TOKEN': readXsrfToken(),
                },
                body: JSON.stringify({ items }),
            });

            if (res.ok) {
                selectedKeys = [];
                router.reload();
            }
        } finally {
            busy = false;
        }
    }

    async function submitManual() {
        busy = true;
        feedback = null;
        try {
            const res = await fetch('/admin/attendance/manual', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-XSRF-TOKEN': readXsrfToken(),
                },
                body: JSON.stringify({
                    user_id: manualUserId,
                    work_date: manualDate,
                    shift_id: manualShiftId || null,
                    clock_in_time: manualIn,
                    clock_out_time: manualOut || null,
                    reason: manualReason,
                }),
            });

            const data = (await res.json()) as { message?: string };
            if (!res.ok) {
                feedback = data.message ?? 'Input manual gagal.';
                return;
            }

            manualReason = '';
            router.reload();
        } finally {
            busy = false;
        }
    }

    async function exportFile(path: string) {
        const url = `${path}?start_date=${exportStart}&end_date=${exportEnd}`;
        if (path.includes('talenta-office')) {
            const check = await fetch(url, { credentials: 'same-origin' });
            if (check.status === 409) {
                const data = (await check.json()) as { message: string };
                if (!confirm(data.message)) return;
                window.location.href = `${url}&confirm_pending=1`;
                return;
            }
        }
        window.location.href = url;
    }
</script>

<AppHead title="Dashboard HR Admin" />

<div class="flex-1 space-y-4 p-4 text-stone-900 lg:p-5">
    {#if showSingleHrBanner}
        <div
            class="flex items-center gap-2 border border-yellow-300 bg-yellow-50 px-4 py-2 text-xs text-yellow-800 rounded-lg shadow-xs"
        >
            <AlertTriangle class="size-4 shrink-0" />
            <span>
                Perhatian: Baru terdapat 1 HR Admin aktif. Segera tambahkan
                minimal 1 HR Admin kedua agar saling verifikasi tetap berjalan.
            </span>
        </div>
    {/if}

    <!-- Header & Export Bar with #8B5E3C accent -->
    <div class="flex flex-wrap items-center justify-between gap-3 rounded-xl bg-gradient-to-r from-[#6F3E1D] via-[#8B5E3C] to-[#8B5E3C] p-4 text-white shadow-sm">
        <div>
            <h1 class="text-lg font-semibold tracking-tight text-white md:text-xl">
                Dashboard Kehadiran HR
            </h1>
            <p class="font-mono text-xs text-stone-200">
                Periode: {selectedDate}
            </p>
        </div>

                <div class="flex flex-wrap items-center gap-2">
                    <Input
                        type="date"
                        bind:value={exportStart}
                        class="h-8 w-36 border-white/30 bg-white/95 font-mono text-xs text-stone-900 shadow-xs"
                    />
                    <Input
                        type="date"
                        bind:value={exportEnd}
                        class="h-8 w-36 border-white/30 bg-white/95 font-mono text-xs text-stone-900 shadow-xs"
                    />
                    <Button
                        size="sm"
                        class="h-8 cursor-pointer bg-[#583015] px-2.5 text-xs text-white hover:bg-[#43230e]"
                        onclick={() => exportFile('/admin/exports/talenta-office')}
                    >
                        <Download class="mr-1 size-3.5" />
                        Talenta Office
                    </Button>
                    <Button
                        size="sm"
                        class="h-8 cursor-pointer border border-white/30 bg-white/15 px-2.5 text-xs text-white hover:bg-white/25"
                        onclick={() => exportFile('/admin/exports/sales-visits')}
                    >
                        <Download class="mr-1 size-3.5" />
                        Sales
                    </Button>
                    <Button
                        size="sm"
                        variant="outline"
                        class="h-8 cursor-pointer border-white/30 bg-white/10 px-2.5 text-xs text-white hover:bg-white/20 hover:text-white"
                        onclick={() => exportFile('/admin/exports/audit')}
                    >
                        Audit
                    </Button>
                </div>
            </div>

            {#if feedback}
                <div
                    class="rounded-md border border-red-300 bg-red-50 px-3 py-2 text-xs text-red-700"
                >
                    {feedback}
                </div>
            {/if}

            <!-- 5 Compact KPI Cards -->
            <div class="grid grid-cols-2 gap-3 md:grid-cols-5">
                <div class="rounded-lg border border-stone-200 border-t-2 border-t-[#8B5E3C] bg-white p-3 shadow-xs">
                    <div class="flex items-center justify-between text-xs text-stone-600">
                        <span>Total Aktif</span>
                        <Users class="size-3.5 text-stone-400" />
                    </div>
                    <p class="mt-1 font-mono text-xl font-semibold tabular-nums text-stone-900">
                        {kpi.total}
                    </p>
                </div>
                <div class="rounded-lg border border-stone-200 border-t-2 border-t-[#8B5E3C] bg-white p-3 shadow-xs">
                    <div class="flex items-center justify-between text-xs text-stone-600">
                        <span>Hadir</span>
                        <Check class="size-3.5 text-green-700" />
                    </div>
                    <p class="mt-1 font-mono text-xl font-semibold tabular-nums text-green-700">
                        {kpi.hadir}
                    </p>
                </div>
                <div class="rounded-lg border border-stone-200 border-t-2 border-t-[#8B5E3C] bg-white p-3 shadow-xs">
                    <div class="flex items-center justify-between text-xs text-stone-600">
                        <span>Terlambat</span>
                        <Clock class="size-3.5 text-yellow-800" />
                    </div>
                    <p class="mt-1 font-mono text-xl font-semibold tabular-nums text-yellow-800">
                        {kpi.terlambat}
                    </p>
                </div>
                <div class="rounded-lg border border-stone-200 border-t-2 border-t-[#8B5E3C] bg-white p-3 shadow-xs">
                    <div class="flex items-center justify-between text-xs text-stone-600">
                        <span>Belum / Alpa</span>
                        <AlertTriangle class="size-3.5 text-red-700" />
                    </div>
                    <p class="mt-1 font-mono text-xl font-semibold tabular-nums text-red-700">
                        {kpi.absen}
                    </p>
                </div>
                <div class="rounded-lg border border-stone-200 border-t-2 border-t-[#8B5E3C] bg-white p-3 shadow-xs">
                    <div class="flex items-center justify-between text-xs text-stone-600">
                        <span>Antrean Review</span>
                        <ShieldAlert class="size-3.5 text-[#6F3E1D]" />
                    </div>
                    <p class="mt-1 font-mono text-xl font-semibold tabular-nums text-[#6F3E1D]">
                        {kpi.review}
                    </p>
                </div>
            </div>

            <!-- Filter Strip -->
            <div class="flex flex-wrap items-center gap-2 rounded-lg border border-stone-200 bg-white p-3 shadow-xs">
                <Input
                    type="date"
                    bind:value={filterDate}
                    onchange={applyDateFilter}
                    class="h-8 w-36 border-stone-300 font-mono text-xs"
                />
                <select
                    bind:value={filterBranch}
                    class="h-8 rounded-md border border-stone-300 bg-white px-2 text-xs text-stone-800"
                >
                    <option value="">Semua Cabang</option>
                    {#each branches as b (b)}
                        <option value={b}>{b}</option>
                    {/each}
                </select>
                <select
                    bind:value={filterRole}
                    class="h-8 rounded-md border border-stone-300 bg-white px-2 text-xs text-stone-800"
                >
                    <option value="">Semua Role</option>
                    <option value="EMPLOYEE_OFFICE">Office</option>
                    <option value="EMPLOYEE_SALES">Sales</option>
                    <option value="HR_ADMIN">HR Admin</option>
                </select>
                <select
                    bind:value={filterStatus}
                    class="h-8 rounded-md border border-stone-300 bg-white px-2 text-xs text-stone-800"
                >
                    <option value="">Semua Status</option>
                    <option value="Hadir">Hadir</option>
                    <option value="Terlambat">Terlambat</option>
                    <option value="Dinas Luar">Dinas Luar</option>
                    <option value="Belum Absen">Belum Absen</option>
                    <option value="Alpa">Alpa</option>
                    <option value="Cuti">Cuti</option>
                    <option value="Izin">Izin</option>
                    <option value="Sakit">Sakit</option>
                </select>
                <select
                    bind:value={filterFlag}
                    class="h-8 rounded-md border border-stone-300 bg-white px-2 text-xs text-stone-800"
                >
                    <option value="">Semua Flag Anomali</option>
                    <option value="OUT_OF_RADIUS">OUT_OF_RADIUS</option>
                    <option value="UNCERTAIN_ACCURACY">UNCERTAIN_ACCURACY</option>
                    <option value="SHARED_DEVICE">SHARED_DEVICE</option>
                    <option value="IMPOSSIBLE_TRAVEL">IMPOSSIBLE_TRAVEL</option>
                    <option value="MISSED_CLOCK_OUT">MISSED_CLOCK_OUT</option>
                    <option value="SHORT_DURATION">SHORT_DURATION</option>
                </select>
            </div>

            <!-- Antrean Review & Bulk Approve Bar -->
            <section class="rounded-lg border border-stone-200 bg-white p-3 shadow-xs">
                <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
                    <h2 class="text-sm font-semibold text-stone-900">
                        Antrean Review ({filteredQueue.length})
                    </h2>
                    {#if selectedKeys.length > 0}
                        <Button
                            size="sm"
                            disabled={busy}
                            class="h-8 bg-green-700 px-3 text-xs text-white hover:bg-green-800"
                            onclick={runBulkApprove}
                        >
                            Bulk Approve ({selectedKeys.length})
                        </Button>
                    {/if}
                </div>

                {#if filteredQueue.length === 0}
                    <p class="py-3 text-xs text-stone-500">
                        Tidak ada item menunggu tinjauan.
                    </p>
                {:else}
                    <div class="space-y-2">
                        {#each filteredQueue as item (itemKey(item))}
                            {@const isSelf = item.user_id === currentUserId}
                            <div
                                class="flex flex-wrap items-center justify-between gap-2 rounded-md border border-stone-200 px-3 py-2"
                            >
                                <div class="flex items-center gap-2.5">
                                    <input
                                        type="checkbox"
                                        disabled={!item.bulk_eligible || isSelf}
                                        checked={selectedKeys.includes(itemKey(item))}
                                        aria-label="Pilih {item.name}"
                                        onchange={(e) => {
                                            const checked = (
                                                e.currentTarget as HTMLInputElement
                                            ).checked;
                                            const k = itemKey(item);
                                            selectedKeys = checked
                                                ? [...selectedKeys, k]
                                                : selectedKeys.filter((x) => x !== k);
                                        }}
                                    />
                                    <div>
                                        <p class="text-[13px] font-medium text-stone-900">
                                            <span class="font-mono text-xs text-stone-600">
                                                {item.nik}
                                            </span>
                                            {item.name}
                                            <span class="ml-1 font-mono text-xs text-stone-500">
                                                ({item.date})
                                            </span>
                                        </p>
                                        <div class="mt-1 flex flex-wrap items-center gap-1">
                                            <span class="text-xs text-stone-600">
                                                {item.location_name}
                                            </span>
                                            {#each item.flags as fl (fl)}
                                                <span class={flagBadgeClass(fl)}>
                                                    {fl}
                                                </span>
                                            {/each}
                                        </div>
                                    </div>
                                </div>

                                <Button
                                    size="sm"
                                    variant="outline"
                                    class="h-8 border-stone-300 px-2.5 text-xs"
                                    onclick={() => openReviewModal(item)}
                                >
                                    Tinjau
                                </Button>
                            </div>
                        {/each}
                    </div>
                {/if}
            </section>

            <!-- Compact Daily Recap Table -->
            <section class="overflow-x-auto rounded-lg border border-stone-200 bg-white shadow-xs">
                <div class="border-b border-stone-200 px-3 py-2.5">
                    <h2 class="text-sm font-semibold text-stone-900">
                        Rekap Kehadiran Harian ({filteredRecap.length})
                    </h2>
                </div>
                <table class="w-full text-left">
                    <thead>
                        <tr class="h-8 bg-stone-100 text-[11px] uppercase tracking-wide text-stone-600">
                            <th class="sticky left-0 bg-stone-100 px-2.5">NIK / Nama</th>
                            <th class="px-2.5">Cabang</th>
                            <th class="px-2.5">Shift</th>
                            <th class="px-2.5">Masuk</th>
                            <th class="px-2.5">Pulang</th>
                            <th class="px-2.5">Status</th>
                            <th class="px-2.5">Verifikasi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-stone-200 text-[13px] text-stone-800">
                        {#each filteredRecap as row (row.user_id)}
                            <tr class="hover:bg-stone-50">
                                <td class="sticky left-0 bg-white px-2.5 py-1.5 whitespace-nowrap">
                                    <span class="font-mono text-xs text-stone-500">
                                        {row.nik}
                                    </span>
                                    <span class="ml-1 font-medium text-stone-900">
                                        {row.name}
                                    </span>
                                    {#if row.source === 'MANUAL_HR'}
                                        <span class="{badgeBase} ml-1 border-blue-300 bg-blue-50 text-blue-700">
                                            Input Manual HR
                                        </span>
                                    {/if}
                                </td>
                                <td class="px-2.5 py-1.5 text-xs text-stone-600">
                                    {row.branch}
                                </td>
                                <td class="px-2.5 py-1.5 font-mono text-xs">
                                    {row.shift}
                                </td>
                                <td class="px-2.5 py-1.5 font-mono tabular-nums">
                                    {row.clock_in ?? '-'}
                                </td>
                                <td class="px-2.5 py-1.5 font-mono tabular-nums">
                                    {row.clock_out ?? '-'}
                                </td>
                                <td class="px-2.5 py-1.5">
                                    <span class={statusBadgeClass(row.attendance_status)}>
                                        {row.attendance_status}
                                    </span>
                                </td>
                                <td class="px-2.5 py-1.5">
                                    <span class={statusBadgeClass(row.verification_status)}>
                                        {row.verification_status}
                                    </span>
                                </td>
                            </tr>
                        {/each}
                    </tbody>
                </table>
            </section>

            <!-- Input Absen Manual Darurat HR -->
            <section class="rounded-lg border border-stone-200 bg-white p-3 shadow-xs">
                <h2 class="mb-2 text-sm font-semibold text-stone-900">
                    Input Absen Manual Darurat (HR)
                </h2>
                <div class="grid gap-2 md:grid-cols-6">
                    <select
                        bind:value={manualUserId}
                        class="h-9 rounded-md border border-stone-300 bg-white px-2 text-xs"
                    >
                        <option value="">Pilih Karyawan</option>
                        {#each employees as emp (emp.id)}
                            <option value={emp.id}>
                                {emp.nik} - {emp.name}
                            </option>
                        {/each}
                    </select>
                    <Input
                        type="date"
                        bind:value={manualDate}
                        class="h-9 border-stone-300 font-mono text-xs"
                    />
                    <select
                        bind:value={manualShiftId}
                        class="h-9 rounded-md border border-stone-300 bg-white px-2 text-xs"
                    >
                        <option value="">Shift</option>
                        {#each shifts as sh (sh.id)}
                            <option value={sh.id}>{sh.code}</option>
                        {/each}
                    </select>
                    <Input
                        type="time"
                        bind:value={manualIn}
                        class="h-9 border-stone-300 font-mono text-xs"
                    />
                    <Input
                        type="time"
                        bind:value={manualOut}
                        class="h-9 border-stone-300 font-mono text-xs"
                    />
                    <Button
                        disabled={busy}
                        class="h-9 bg-[#6F3E1D] text-xs text-white hover:bg-[#583015]"
                        onclick={submitManual}
                    >
                        Simpan Manual
                    </Button>
                </div>
                <Input
                    bind:value={manualReason}
                    placeholder="Alasan dispensasi darurat (minimal 10 karakter)"
                    class="mt-2 h-9 border-stone-300 text-xs"
                />
            </section>
        </div>

<!-- Verification Review Modal (max-w-5xl, 2-column desktop) -->
{#if activeReview}
    {@const isSelfReview = activeReview.user_id === currentUserId}
    {@const isMissed =
        activeReview.flags.includes('MISSED_CLOCK_OUT') ||
        activeReview.flags.includes('MISSED_VISIT_OUT')}
    <div
        class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4"
        role="dialog"
        aria-modal="true"
    >
        <div class="max-h-[90vh] w-full max-w-5xl overflow-y-auto rounded-lg border border-stone-200 bg-white p-4 shadow-lg">
            <div class="mb-3 flex items-center justify-between border-b border-stone-200 pb-2">
                <div>
                    <h3 class="text-sm font-semibold text-stone-900">
                        Verifikasi: <span class="font-mono">{activeReview.nik}</span>
                        {activeReview.name}
                    </h3>
                    <p class="text-xs text-stone-500">
                        {activeReview.location_name} ({activeReview.date})
                    </p>
                </div>
                <button
                    type="button"
                    aria-label="Tutup modal"
                    onclick={() => {
                        activeReview = null;
                    }}
                >
                    <X class="size-4 text-stone-500" />
                </button>
            </div>

            <div class="grid gap-4 md:grid-cols-2">
                <!-- Left Panel: Side-by-side Photos & Metadata -->
                <div class="space-y-3">
                    <div class="grid grid-cols-2 gap-2">
                        <div class="rounded-md border border-stone-200 bg-stone-50 p-1.5">
                            <p class="mb-1 text-[11px] font-medium text-stone-600">
                                Foto Referensi
                            </p>
                            {#if activeReview.reference_photo_url}
                                <img
                                    src={activeReview.reference_photo_url}
                                    alt="Foto referensi"
                                    class="h-44 w-full rounded object-cover"
                                />
                            {:else}
                                <div class="flex h-44 items-center justify-center text-xs text-stone-400">
                                    Belum ada foto profil
                                </div>
                            {/if}
                        </div>
                        <div class="rounded-md border border-stone-200 bg-stone-50 p-1.5">
                            <p class="mb-1 text-[11px] font-medium text-stone-600">
                                Foto Bukti ({activeReview.phase})
                            </p>
                            {#if activeReview.photo_url}
                                <img
                                    src={activeReview.photo_url}
                                    alt="Foto bukti"
                                    class="h-44 w-full rounded object-cover"
                                />
                            {:else}
                                <div class="flex h-44 items-center justify-center text-xs text-stone-400">
                                    Tanpa foto
                                </div>
                            {/if}
                        </div>
                    </div>

                    <div class="rounded-md border border-stone-200 bg-stone-50 p-2.5 text-xs">
                        <p class="font-mono text-stone-700">
                            Akurasi: {activeReview.accuracy_m ?? '-'}m | Radius: {activeReview.radius_m}m
                        </p>
                        <p class="mt-1 text-stone-700">
                            Catatan: {activeReview.notes ?? '-'}
                        </p>
                        <div class="mt-1.5 flex flex-wrap gap-1">
                            {#each activeReview.flags as fl (fl)}
                                <span class={flagBadgeClass(fl)}>{fl}</span>
                            {/each}
                        </div>
                    </div>

                    {#if isMissed}
                        <div>
                            <label class="text-xs font-medium text-stone-700">
                                Jam Pulang / Selesai (opsional ubah default)
                                <Input
                                    type="time"
                                    bind:value={manualOutTime}
                                    class="mt-1 h-8 border-stone-300 font-mono text-xs"
                                />
                            </label>
                        </div>
                    {/if}

                    <div>
                        <label class="text-xs font-medium text-stone-700">
                            Alasan Penolakan (wajib jika Reject)
                            <textarea
                                bind:value={rejectReason}
                                rows="2"
                                class="mt-1 w-full rounded-md border border-stone-300 p-2 text-xs"
                                placeholder="Tulis alasan penolakan..."
                            ></textarea>
                        </label>
                    </div>
                </div>

                <!-- Right Panel: Leaflet Map -->
                <div>
                    <VerificationMap
                        targetLat={activeReview.target_lat}
                        targetLng={activeReview.target_lng}
                        radiusM={activeReview.radius_m}
                        userLat={activeReview.user_lat}
                        userLng={activeReview.user_lng}
                        accuracyM={activeReview.accuracy_m}
                        label={activeReview.location_name}
                    />
                </div>
            </div>

            <div class="mt-4 flex flex-wrap items-center justify-end gap-2 border-t border-stone-200 pt-3">
                {#if isSelfReview}
                    <span class="mr-auto text-xs text-red-700">
                        Dilarang menyetujui atau menolak absensi milik sendiri.
                    </span>
                {/if}
                <Button
                    size="sm"
                    disabled={busy || isSelfReview}
                    class="h-8 bg-green-700 px-3 text-xs text-white hover:bg-green-800"
                    onclick={() =>
                        activeReview && reviewOne(activeReview, 'APPROVED')}
                >
                    Approve
                </Button>
                <Button
                    size="sm"
                    disabled={busy || isSelfReview}
                    class="h-8 bg-red-700 px-3 text-xs text-white hover:bg-red-800"
                    onclick={() =>
                        activeReview && reviewOne(activeReview, 'REJECTED')}
                >
                    Reject
                </Button>
                <Button
                    size="sm"
                    variant="outline"
                    class="h-8 border-stone-300 px-3 text-xs"
                    onclick={() => {
                        activeReview = null;
                    }}
                >
                    Tutup
                </Button>
            </div>
        </div>
    </div>
{/if}
