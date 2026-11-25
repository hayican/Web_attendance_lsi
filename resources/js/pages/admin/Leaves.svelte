<script module lang="ts">
    export const layout = {
        breadcrumbs: [
            {
                title: 'Persetujuan Cuti/Izin',
                href: '/admin/leaves',
            },
        ],
    };
</script>

<script lang="ts">
    import { page, router } from '@inertiajs/svelte';
    import FileText from '@lucide/svelte/icons/file-text';
    import X from '@lucide/svelte/icons/x';
    import AppHead from '@/components/AppHead.svelte';
    import { Button } from '@/components/ui/button';
    import { Input } from '@/components/ui/input';
    import { readXsrfToken } from '@/lib/gpsClient';

    type LeaveDayItem = {
        id: number;
        leave_date: string;
        day_value: string | number;
        status: string;
    };

    type LeaveItem = {
        id: number;
        user_id: number;
        type: string;
        start_date: string;
        end_date: string;
        total_days: string | number;
        destination_location?: string | null;
        scheduled_start_time?: string | null;
        reason: string;
        status: string;
        attachment_url?: string | null;
        reject_reason?: string | null;
        hr_conflict_warning?: boolean;
        user: {
            id: number;
            nik: string;
            name: string;
            office_location?: { name: string } | null;
        };
        days?: LeaveDayItem[];
        reviewer?: { id: number; name: string } | null;
    };

    let { requests }: { requests: LeaveItem[] } = $props();

    const currentUserId = $derived(page.props.auth.user?.id ?? 0);

    let search = $state('');
    let filterStatus = $state('');
    let filterType = $state('');
    let busy = $state(false);
    let feedback = $state<string | null>(null);

    let overrideTarget = $state<{ id: number; message: string } | null>(null);
    let rejectTarget = $state<LeaveItem | null>(null);
    let rejectReason = $state('');
    let cancelTarget = $state<LeaveItem | null>(null);
    let cancelDayIds = $state<number[]>([]);
    let cancelReason = $state('');

    const badgeBase =
        'inline-flex items-center gap-1 rounded px-1.5 py-0.5 text-[11px] font-medium border whitespace-nowrap';

    const filteredRequests = $derived(
        requests.filter((r) => {
            if (filterStatus && r.status !== filterStatus) return false;
            if (filterType && r.type !== filterType) return false;
            if (search.trim()) {
                const q = search.trim().toLowerCase();
                const hit =
                    r.user.nik.toLowerCase().includes(q) ||
                    r.user.name.toLowerCase().includes(q);
                if (!hit) return false;
            }
            return true;
        }),
    );

    const summary = $derived({
        pending: requests.filter((r) => r.status === 'PENDING').length,
        approved: requests.filter((r) => r.status === 'APPROVED').length,
        partial: requests.filter((r) => r.status === 'PARTIALLY_CANCELLED')
            .length,
    });

    function formatDate(value: string): string {
        return value.slice(0, 10);
    }

    function statusBadgeClass(status: string): string {
        if (status === 'APPROVED') {
            return `${badgeBase} border-green-300 bg-green-50 text-green-700`;
        }
        if (status === 'PENDING') {
            return `${badgeBase} border-yellow-300 bg-yellow-50 text-yellow-800`;
        }
        if (status === 'REJECTED') {
            return `${badgeBase} border-red-300 bg-red-50 text-red-700`;
        }
        if (status === 'PARTIALLY_CANCELLED') {
            return `${badgeBase} border-blue-300 bg-blue-50 text-blue-700`;
        }
        return `${badgeBase} border-stone-300 bg-stone-100 text-stone-700`;
    }

    function typeBadgeClass(type: string): string {
        if (type === 'DINAS_LUAR') {
            return `${badgeBase} border-blue-300 bg-blue-50 text-blue-700`;
        }
        if (type === 'SAKIT') {
            return `${badgeBase} border-rose-300 bg-rose-50 text-rose-800`;
        }
        return `${badgeBase} border-stone-300 bg-stone-100 text-stone-700`;
    }

    function extractError(
        data: { message?: string; errors?: Record<string, string[]> },
        fallback: string,
    ): string {
        const firstErr = data.errors
            ? Object.values(data.errors).flat()[0]
            : undefined;
        return firstErr ?? data.message ?? fallback;
    }

    async function approve(id: number, override = false) {
        busy = true;
        feedback = null;
        try {
            const res = await fetch(`/admin/leaves/${id}/approve`, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-XSRF-TOKEN': readXsrfToken(),
                },
                body: JSON.stringify({ override_monthly_limit: override }),
            });

            const data = (await res.json()) as {
                requires_override_confirmation?: boolean;
                message?: string;
                errors?: Record<string, string[]>;
            };

            if (res.status === 409 && data.requires_override_confirmation) {
                overrideTarget = {
                    id,
                    message:
                        data.message ??
                        'Cuti 2 hari/bulan sudah terpakai. Tetap setujui pengajuan ini?',
                };
                return;
            }

            if (!res.ok) {
                feedback = extractError(data, 'Gagal menyetujui pengajuan.');
                return;
            }

            overrideTarget = null;
            router.reload();
        } finally {
            busy = false;
        }
    }

    async function submitReject() {
        if (!rejectTarget) return;
        if (rejectReason.trim().length < 3) {
            feedback = 'Alasan penolakan minimal 3 karakter.';
            return;
        }

        busy = true;
        feedback = null;
        try {
            const res = await fetch(`/admin/leaves/${rejectTarget.id}/reject`, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-XSRF-TOKEN': readXsrfToken(),
                },
                body: JSON.stringify({ reject_reason: rejectReason.trim() }),
            });

            if (!res.ok) {
                const data = (await res.json()) as {
                    message?: string;
                    errors?: Record<string, string[]>;
                };
                feedback = extractError(data, 'Gagal menolak pengajuan.');
                return;
            }

            rejectTarget = null;
            rejectReason = '';
            router.reload();
        } finally {
            busy = false;
        }
    }

    function openCancelModal(item: LeaveItem) {
        cancelTarget = item;
        cancelDayIds = [];
        cancelReason = '';
        feedback = null;
    }

    async function submitPartialCancel() {
        if (!cancelTarget) return;
        if (cancelDayIds.length === 0) {
            feedback = 'Pilih minimal 1 tanggal yang ingin dibatalkan.';
            return;
        }
        if (cancelReason.trim().length < 3) {
            feedback = 'Alasan pembatalan minimal 3 karakter.';
            return;
        }

        busy = true;
        feedback = null;
        try {
            const res = await fetch(
                `/admin/leaves/${cancelTarget.id}/partial-cancel`,
                {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                        'X-XSRF-TOKEN': readXsrfToken(),
                    },
                    body: JSON.stringify({
                        day_ids: cancelDayIds,
                        cancel_reason: cancelReason.trim(),
                    }),
                },
            );

            if (!res.ok) {
                const data = (await res.json()) as {
                    message?: string;
                    errors?: Record<string, string[]>;
                };
                feedback = extractError(data, 'Gagal membatalkan tanggal cuti.');
                return;
            }

            cancelTarget = null;
            cancelDayIds = [];
            cancelReason = '';
            router.reload();
        } finally {
            busy = false;
        }
    }
</script>

<AppHead title="Persetujuan Cuti & Izin" />

<div class="space-y-4 p-4 text-stone-900 lg:p-5">
    <div
        class="flex flex-wrap items-center justify-between gap-3 rounded-xl bg-gradient-to-r from-[#6F3E1D] via-[#8B5E3C] to-[#8B5E3C] p-4 text-white shadow-sm"
    >
        <div>
            <h1
                class="text-lg font-semibold tracking-tight text-white md:text-xl"
            >
                Persetujuan Cuti, Izin, Sakit &amp; Dinas
            </h1>
            <p class="text-xs text-stone-200">
                Verifikasi pengajuan karyawan dan pembatalan parsial HR
            </p>
        </div>
    </div>

            {#if feedback}
                <div
                    class="rounded-md border border-red-300 bg-red-50 px-3 py-2 text-xs text-red-700"
                >
                    {feedback}
                </div>
            {/if}

            <div class="grid grid-cols-3 gap-3">
                <div
                    class="rounded-lg border border-stone-200 border-t-2 border-t-[#8B5E3C] bg-white p-3 shadow-xs"
                >
                    <p class="text-xs text-stone-600">Menunggu Approval</p>
                    <p
                        class="mt-1 font-mono text-xl font-semibold text-yellow-800 tabular-nums"
                    >
                        {summary.pending}
                    </p>
                </div>
                <div
                    class="rounded-lg border border-stone-200 border-t-2 border-t-[#8B5E3C] bg-white p-3 shadow-xs"
                >
                    <p class="text-xs text-stone-600">Disetujui</p>
                    <p
                        class="mt-1 font-mono text-xl font-semibold text-green-700 tabular-nums"
                    >
                        {summary.approved}
                    </p>
                </div>
                <div
                    class="rounded-lg border border-stone-200 border-t-2 border-t-[#8B5E3C] bg-white p-3 shadow-xs"
                >
                    <p class="text-xs text-stone-600">Batal Parsial</p>
                    <p
                        class="mt-1 font-mono text-xl font-semibold text-blue-700 tabular-nums"
                    >
                        {summary.partial}
                    </p>
                </div>
            </div>

            <div
                class="flex flex-wrap items-center gap-2 rounded-lg border border-stone-200 bg-white p-3 shadow-xs"
            >
                <Input
                    bind:value={search}
                    placeholder="Cari NIK atau nama..."
                    class="h-8 w-52 border-stone-300 bg-white text-xs text-stone-900"
                />
                <select
                    bind:value={filterStatus}
                    class="h-8 rounded-md border border-stone-300 bg-white px-2 text-xs text-stone-800"
                >
                    <option value="">Semua Status</option>
                    <option value="PENDING">PENDING</option>
                    <option value="APPROVED">APPROVED</option>
                    <option value="PARTIALLY_CANCELLED">
                        PARTIALLY_CANCELLED
                    </option>
                </select>
                <select
                    bind:value={filterType}
                    class="h-8 rounded-md border border-stone-300 bg-white px-2 text-xs text-stone-800"
                >
                    <option value="">Semua Jenis</option>
                    <option value="CUTI">CUTI</option>
                    <option value="IZIN">IZIN</option>
                    <option value="SAKIT">SAKIT</option>
                    <option value="DINAS_LUAR">DINAS_LUAR</option>
                </select>
            </div>

            <section
                class="overflow-x-auto rounded-lg border border-stone-200 bg-white shadow-xs"
            >
                <div class="border-b border-stone-200 px-3 py-2.5">
                    <h2 class="text-sm font-semibold text-stone-900">
                        Daftar Pengajuan ({filteredRequests.length})
                    </h2>
                </div>

                {#if filteredRequests.length === 0}
                    <p class="p-4 text-xs text-stone-500">
                        Tidak ada pengajuan yang sesuai filter.
                    </p>
                {:else}
                    <table class="w-full text-left">
                        <thead>
                            <tr
                                class="h-8 bg-stone-100 text-[11px] tracking-wide text-stone-600 uppercase"
                            >
                                <th class="px-2.5">Karyawan</th>
                                <th class="px-2.5">Jenis</th>
                                <th class="px-2.5">Tanggal &amp; Durasi</th>
                                <th class="px-2.5">Alasan &amp; Bukti</th>
                                <th class="px-2.5">Status</th>
                                <th class="px-2.5 text-right">Aksi</th>
                            </tr>
                        </thead>
                        <tbody
                            class="divide-y divide-stone-200 text-[13px] text-stone-800"
                        >
                            {#each filteredRequests as req (req.id)}
                                {@const isSelf = req.user_id === currentUserId}
                                <tr class="align-top hover:bg-stone-50">
                                    <td class="px-2.5 py-2 whitespace-nowrap">
                                        <span
                                            class="font-mono text-xs text-stone-500"
                                        >
                                            {req.user.nik}
                                        </span>
                                        <span
                                            class="ml-1 font-medium text-stone-900"
                                        >
                                            {req.user.name}
                                        </span>
                                        {#if req.user.office_location}
                                            <p class="text-[11px] text-stone-500">
                                                {req.user.office_location.name}
                                            </p>
                                        {/if}
                                    </td>
                                    <td class="px-2.5 py-2">
                                        <span class={typeBadgeClass(req.type)}>
                                            {req.type}
                                        </span>
                                        {#if req.type === 'DINAS_LUAR' && req.destination_location}
                                            <p
                                                class="mt-1 text-[11px] text-stone-600"
                                            >
                                                {req.destination_location}
                                                {#if req.scheduled_start_time}
                                                    ({req.scheduled_start_time.slice(
                                                        0,
                                                        5,
                                                    )})
                                                {/if}
                                            </p>
                                        {/if}
                                        {#if req.hr_conflict_warning}
                                            <p class="mt-1 max-w-56 rounded bg-amber-50 px-1.5 py-1 text-[11px] leading-snug text-amber-800">
                                                HR Admin yang masuk kerja kurang dari 2 orang pada tanggal ini.
                                            </p>
                                        {/if}
                                    </td>
                                    <td
                                        class="px-2.5 py-2 font-mono text-xs whitespace-nowrap tabular-nums"
                                    >
                                        <div>
                                            {formatDate(req.start_date)} s/d
                                            {formatDate(req.end_date)}
                                        </div>
                                        <div class="text-[11px] text-stone-500">
                                            {req.total_days} hari kerja
                                        </div>
                                    </td>
                                    <td class="max-w-xs px-2.5 py-2 text-xs">
                                        <p class="text-stone-700">{req.reason}</p>
                                        {#if req.attachment_url}
                                            <a
                                                href={req.attachment_url}
                                                target="_blank"
                                                rel="noopener noreferrer"
                                                class="mt-1 inline-flex items-center gap-1 text-[11px] font-medium text-[#6F3E1D] hover:underline"
                                            >
                                                <FileText class="size-3" />
                                                Lihat Lampiran
                                            </a>
                                        {/if}
                                    </td>
                                    <td class="px-2.5 py-2">
                                        <span
                                            class={statusBadgeClass(req.status)}
                                        >
                                            {req.status}
                                        </span>
                                        {#if req.reviewer}
                                            <p
                                                class="mt-1 text-[11px] text-stone-500"
                                            >
                                                Oleh: {req.reviewer.name}
                                            </p>
                                        {/if}
                                    </td>
                                    <td
                                        class="px-2.5 py-2 text-right whitespace-nowrap"
                                    >
                                        {#if isSelf}
                                            <span
                                                class="text-[11px] text-stone-500"
                                            >
                                                Akun sendiri
                                            </span>
                                        {:else if req.status === 'PENDING'}
                                            <div
                                                class="inline-flex items-center gap-1.5"
                                            >
                                                <Button
                                                    size="sm"
                                                    disabled={busy}
                                                    class="h-8 cursor-pointer bg-green-700 px-2.5 text-xs text-white hover:bg-green-800"
                                                    onclick={() =>
                                                        approve(req.id)}
                                                >
                                                    Approve
                                                </Button>
                                                <Button
                                                    size="sm"
                                                    disabled={busy}
                                                    class="h-8 cursor-pointer bg-red-700 px-2.5 text-xs text-white hover:bg-red-800"
                                                    onclick={() => {
                                                        rejectTarget = req;
                                                        rejectReason = '';
                                                        feedback = null;
                                                    }}
                                                >
                                                    Reject
                                                </Button>
                                            </div>
                                        {:else if (req.status === 'APPROVED' || req.status === 'PARTIALLY_CANCELLED') && req.days && req.days.some((d) => d.status === 'APPROVED')}
                                            <Button
                                                size="sm"
                                                variant="outline"
                                                disabled={busy}
                                                class="h-8 cursor-pointer border-stone-300 bg-white px-2.5 text-xs text-stone-700 hover:bg-stone-100"
                                                onclick={() =>
                                                    openCancelModal(req)}
                                            >
                                                Batal Parsial
                                            </Button>
                                        {/if}
                                    </td>
                                </tr>
                            {/each}
                        </tbody>
                    </table>
                {/if}
            </section>
        </div>

<!-- Dialog Override Batas Cuti 2 Hari/Bulan -->
{#if overrideTarget}
    <div
        class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4"
        role="dialog"
        aria-modal="true"
    >
        <div
            class="w-full max-w-md rounded-lg border border-stone-200 bg-white p-4 shadow-lg"
        >
            <h3 class="text-sm font-semibold text-stone-900">
                Konfirmasi Override Batas Cuti
            </h3>
            <p class="mt-2 text-xs text-stone-700">{overrideTarget.message}</p>
            <div class="mt-4 flex justify-end gap-2">
                <Button
                    size="sm"
                    variant="outline"
                    class="h-8 cursor-pointer border-stone-300 bg-white px-3 text-xs text-stone-700"
                    onclick={() => {
                        overrideTarget = null;
                    }}
                >
                    Batal
                </Button>
                <Button
                    size="sm"
                    disabled={busy}
                    class="h-8 cursor-pointer bg-[#6F3E1D] px-3 text-xs text-white hover:bg-[#583015]"
                    onclick={() =>
                        overrideTarget && approve(overrideTarget.id, true)}
                >
                    Lanjutkan
                </Button>
            </div>
        </div>
    </div>
{/if}

<!-- Dialog Reject Pengajuan -->
{#if rejectTarget}
    <div
        class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4"
        role="dialog"
        aria-modal="true"
    >
        <div
            class="w-full max-w-md rounded-lg border border-stone-200 bg-white p-4 shadow-lg"
        >
            <div
                class="flex items-center justify-between border-b border-stone-200 pb-2"
            >
                <h3 class="text-sm font-semibold text-stone-900">
                    Tolak Pengajuan {rejectTarget.user.name}
                </h3>
                <button
                    type="button"
                    class="cursor-pointer"
                    aria-label="Tutup"
                    onclick={() => {
                        rejectTarget = null;
                    }}
                >
                    <X class="size-4 text-stone-500" />
                </button>
            </div>
            <label class="mt-3 block text-xs font-medium text-stone-700">
                Alasan Penolakan
                <textarea
                    bind:value={rejectReason}
                    rows="3"
                    class="mt-1 w-full rounded-md border border-stone-300 bg-white p-2 text-xs text-stone-900"
                    placeholder="Tulis alasan penolakan (min. 3 karakter)..."
                ></textarea>
            </label>
            <div class="mt-4 flex justify-end gap-2">
                <Button
                    size="sm"
                    variant="outline"
                    class="h-8 cursor-pointer border-stone-300 bg-white px-3 text-xs text-stone-700"
                    onclick={() => {
                        rejectTarget = null;
                    }}
                >
                    Batal
                </Button>
                <Button
                    size="sm"
                    disabled={busy}
                    class="h-8 cursor-pointer bg-red-700 px-3 text-xs text-white hover:bg-red-800"
                    onclick={submitReject}
                >
                    Tolak Pengajuan
                </Button>
            </div>
        </div>
    </div>
{/if}

<!-- Dialog Pembatalan Parsial HR -->
{#if cancelTarget}
    <div
        class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4"
        role="dialog"
        aria-modal="true"
    >
        <div
            class="w-full max-w-md rounded-lg border border-stone-200 bg-white p-4 shadow-lg"
        >
            <div
                class="flex items-center justify-between border-b border-stone-200 pb-2"
            >
                <h3 class="text-sm font-semibold text-stone-900">
                    Batal Parsial: {cancelTarget.user.name}
                </h3>
                <button
                    type="button"
                    class="cursor-pointer"
                    aria-label="Tutup"
                    onclick={() => {
                        cancelTarget = null;
                    }}
                >
                    <X class="size-4 text-stone-500" />
                </button>
            </div>
            <div class="mt-3 max-h-48 space-y-1.5 overflow-y-auto">
                <p class="text-xs font-medium text-stone-700">
                    Pilih tanggal APPROVED yang dibatalkan:
                </p>
                {#each (cancelTarget.days ?? []).filter((d) => d.status === 'APPROVED') as day (day.id)}
                    <label
                        class="flex cursor-pointer items-center justify-between rounded border border-stone-200 px-2.5 py-1.5 text-xs hover:bg-stone-50"
                    >
                        <span class="flex items-center gap-2 font-mono">
                            <input
                                type="checkbox"
                                checked={cancelDayIds.includes(day.id)}
                                onchange={(e) => {
                                    const checked = (
                                        e.currentTarget as HTMLInputElement
                                    ).checked;
                                    cancelDayIds = checked
                                        ? [...cancelDayIds, day.id]
                                        : cancelDayIds.filter(
                                              (id) => id !== day.id,
                                          );
                                }}
                            />
                            {formatDate(day.leave_date)}
                        </span>
                        <span class="font-mono text-stone-500">
                            {day.day_value} hari
                        </span>
                    </label>
                {/each}
            </div>
            <label class="mt-3 block text-xs font-medium text-stone-700">
                Alasan Pembatalan
                <textarea
                    bind:value={cancelReason}
                    rows="2"
                    class="mt-1 w-full rounded-md border border-stone-300 bg-white p-2 text-xs text-stone-900"
                    placeholder="Tulis alasan pembatalan..."
                ></textarea>
            </label>
            <div class="mt-4 flex justify-end gap-2">
                <Button
                    size="sm"
                    variant="outline"
                    class="h-8 cursor-pointer border-stone-300 bg-white px-3 text-xs text-stone-700"
                    onclick={() => {
                        cancelTarget = null;
                    }}
                >
                    Batal
                </Button>
                <Button
                    size="sm"
                    disabled={busy}
                    class="h-8 cursor-pointer bg-[#6F3E1D] px-3 text-xs text-white hover:bg-[#583015]"
                    onclick={submitPartialCancel}
                >
                    Batalkan Tanggal Terpilih
                </Button>
            </div>
        </div>
    </div>
{/if}
