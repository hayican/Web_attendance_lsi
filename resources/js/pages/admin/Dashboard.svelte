<script lang="ts">
    import { router } from '@inertiajs/svelte';
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

    type DashboardProps = {
        selectedDate: string;
        showSingleHrBanner: boolean;
        dailyRecap: {
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
        }[];
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

    let activeReview = $state<QueueItem | null>(null);
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

    $effect(() => {
        if (!manualDate) {
            manualDate = selectedDate;
            exportStart = selectedDate;
            exportEnd = selectedDate;
        }
    });

    function itemKey(item: QueueItem): string {
        return `${item.kind}:${item.id}:${item.phase}`;
    }

    async function reviewOne(item: QueueItem, decision: 'APPROVED' | 'REJECTED') {
        const rejectReason =
            decision === 'REJECTED' ? prompt('Alasan penolakan:') : null;
        if (decision === 'REJECTED' && !rejectReason) {
            return;
        }

        const url =
            item.kind === 'attendance'
                ? `/admin/attendance/${item.id}/review`
                : `/admin/visits/${item.id}/review`;

        const res = await fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-XSRF-TOKEN': readXsrfToken(),
            },
            body: JSON.stringify({
                phase: item.phase,
                decision,
                reject_reason: rejectReason,
            }),
        });

        if (!res.ok) {
            const err = (await res.json()) as { message?: string };
            feedback = err.message ?? 'Gagal memproses verifikasi.';
            return;
        }

        activeReview = null;
        router.reload();
    }

    async function runBulkApprove() {
        const items = pendingQueue
            .filter((q) => selectedKeys.includes(itemKey(q)) && q.bulk_eligible)
            .map((q) => ({ kind: q.kind, id: q.id, phase: q.phase }));

        if (items.length === 0) {
            return;
        }

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
    }

    async function submitManual() {
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
    }

    async function exportFile(path: string) {
        const url = `${path}?start_date=${exportStart}&end_date=${exportEnd}`;
        if (path.includes('talenta-office')) {
            const check = await fetch(url, { credentials: 'same-origin' });
            if (check.status === 409) {
                const data = (await check.json()) as { message: string };
                if (!confirm(data.message)) {
                    return;
                }
                window.location.href = `${url}&confirm_pending=1`;
                return;
            }
        }
        window.location.href = url;
    }
</script>

<AppHead title="Dashboard HR Admin" />

<div class="mx-auto flex w-full max-w-6xl flex-col gap-5 p-4 text-sm">
    {#if showSingleHrBanner}
        <div
            class="rounded-lg border border-amber-500/60 bg-amber-50 p-3 text-amber-900"
        >
            Perhatian: Baru terdapat 1 HR Admin aktif. Segera tambahkan minimal
            1 HR Admin kedua agar pengajuan cuti dan absensi HR dapat saling
            diverifikasi.
        </div>
    {/if}

    {#if feedback}
        <p class="rounded-md bg-destructive/10 p-3 text-destructive">
            {feedback}
        </p>
    {/if}

    <div class="flex flex-wrap items-center justify-between gap-3">
        <h1 class="text-xl font-bold">Dashboard HR ({selectedDate})</h1>
        <div class="flex flex-wrap items-center gap-2">
            <Input type="date" bind:value={exportStart} class="w-40" />
            <Input type="date" bind:value={exportEnd} class="w-40" />
            <Button
                size="sm"
                variant="outline"
                onclick={() => exportFile('/admin/exports/talenta-office')}
            >
                Export Talenta Office
            </Button>
            <Button
                size="sm"
                variant="outline"
                onclick={() => exportFile('/admin/exports/sales-visits')}
            >
                Export Sales
            </Button>
            <Button
                size="sm"
                variant="outline"
                onclick={() => exportFile('/admin/exports/audit')}
            >
                Export Audit
            </Button>
        </div>
    </div>

    <div class="rounded-xl border bg-card p-4">
        <div class="mb-3 flex items-center justify-between">
            <h2 class="font-semibold">
                Antrean Review ({pendingQueue.length})
            </h2>
            {#if selectedKeys.length > 0}
                <Button size="sm" onclick={runBulkApprove}>
                    Bulk Approve ({selectedKeys.length})
                </Button>
            {/if}
        </div>

        <div class="space-y-2">
            {#each pendingQueue as item (itemKey(item))}
                <div
                    class="flex flex-wrap items-center justify-between gap-2 rounded-lg border p-3"
                >
                    <div class="flex items-center gap-2">
                        {#if item.bulk_eligible}
                            <input
                                type="checkbox"
                                checked={selectedKeys.includes(itemKey(item))}
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
                        {/if}
                        <div>
                            <p class="font-medium">
                                {item.nik} - {item.name} ({item.date})
                            </p>
                            <p class="text-xs text-muted-foreground">
                                {item.location_name} | Flag: {item.flags.join(', ') || '-'}
                            </p>
                        </div>
                    </div>
                    <Button
                        size="sm"
                        variant="secondary"
                        onclick={() => {
                            activeReview = item;
                        }}
                    >
                        Tinjau
                    </Button>
                </div>
            {/each}
        </div>
    </div>

    {#if activeReview}
        <div class="flex flex-col gap-3 rounded-xl border bg-card p-4">
            <h3 class="font-semibold">
                Tinjau: {activeReview.name} ({activeReview.location_name})
            </h3>
            <div class="grid gap-3 md:grid-cols-2">
                {#if activeReview.reference_photo_url}
                    <img
                        src={activeReview.reference_photo_url}
                        alt="Foto referensi karyawan"
                        class="h-48 w-full rounded-lg border object-cover"
                    />
                {/if}
                {#if activeReview.photo_url}
                    <img
                        src={activeReview.photo_url}
                        alt="Foto bukti absen"
                        class="h-48 w-full rounded-lg border object-cover"
                    />
                {/if}
            </div>

            <VerificationMap
                targetLat={activeReview.target_lat}
                targetLng={activeReview.target_lng}
                radiusM={activeReview.radius_m}
                userLat={activeReview.user_lat}
                userLng={activeReview.user_lng}
                accuracyM={activeReview.accuracy_m}
                label={activeReview.location_name}
            />

            <p class="text-xs">Catatan: {activeReview.notes ?? '-'}</p>
            <div class="flex gap-2">
                <Button
                    onclick={() =>
                        activeReview && reviewOne(activeReview, 'APPROVED')}
                >
                    Approve
                </Button>
                <Button
                    variant="destructive"
                    onclick={() =>
                        activeReview && reviewOne(activeReview, 'REJECTED')}
                >
                    Reject
                </Button>
                <Button
                    variant="outline"
                    onclick={() => {
                        activeReview = null;
                    }}
                >
                    Tutup
                </Button>
            </div>
        </div>
    {/if}

    <div class="rounded-xl border bg-card p-4">
        <h2 class="mb-3 font-semibold">Input Absen Manual Darurat (HR)</h2>
        <div class="grid gap-2 md:grid-cols-6">
            <select
                bind:value={manualUserId}
                class="rounded-md border bg-background px-2 py-2"
            >
                <option value="">Pilih Karyawan</option>
                {#each employees as emp (emp.id)}
                    <option value={emp.id}>{emp.nik} - {emp.name}</option>
                {/each}
            </select>
            <Input type="date" bind:value={manualDate} />
            <select
                bind:value={manualShiftId}
                class="rounded-md border bg-background px-2 py-2"
            >
                <option value="">Shift</option>
                {#each shifts as sh (sh.id)}
                    <option value={sh.id}>{sh.code}</option>
                {/each}
            </select>
            <Input type="time" bind:value={manualIn} />
            <Input type="time" bind:value={manualOut} />
            <Button onclick={submitManual}>Simpan Manual</Button>
        </div>
        <Input
            bind:value={manualReason}
            placeholder="Alasan dispensasi darurat (min 10 karakter)"
            class="mt-2"
        />
    </div>

    <div class="overflow-x-auto rounded-xl border bg-card p-4">
        <h2 class="mb-3 font-semibold">Rekap Kehadiran Harian</h2>
        <table class="w-full text-left text-xs">
            <thead>
                <tr class="border-b">
                    <th class="py-2">NIK</th>
                    <th>Nama</th>
                    <th>Cabang</th>
                    <th>Masuk</th>
                    <th>Pulang</th>
                    <th>Status</th>
                    <th>Verifikasi</th>
                </tr>
            </thead>
            <tbody>
                {#each dailyRecap as row (row.user_id)}
                    <tr class="border-b">
                        <td class="py-2">{row.nik}</td>
                        <td>{row.name}</td>
                        <td>{row.branch}</td>
                        <td>{row.clock_in ?? '-'}</td>
                        <td>{row.clock_out ?? '-'}</td>
                        <td>{row.attendance_status}</td>
                        <td>{row.verification_status}</td>
                    </tr>
                {/each}
            </tbody>
        </table>
    </div>
</div>
