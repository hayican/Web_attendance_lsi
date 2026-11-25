<script lang="ts">
    import { router } from '@inertiajs/svelte';
    import AppHead from '@/components/AppHead.svelte';
    import { Button } from '@/components/ui/button';
    import { Input } from '@/components/ui/input';
    import { readXsrfToken } from '@/lib/gpsClient';

    type LeaveProps = {
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
        requests: {
            id: number;
            type: string;
            start_date: string;
            end_date: string;
            total_days: string;
            status: string;
            reason: string;
            reject_reason: string | null;
        }[];
        todayDate: string;
        canSubmitDinasLuar: boolean;
    };

    let {
        balance,
        requests,
        todayDate,
        canSubmitDinasLuar,
    }: LeaveProps = $props();

    let type = $state<'CUTI' | 'IZIN' | 'SAKIT' | 'DINAS_LUAR'>('IZIN');
    let startDate = $state('');
    let endDate = $state('');
    let reason = $state('');
    let destinationLocation = $state('');
    let scheduledStartTime = $state('09:00');
    let attachmentFile = $state<File | null>(null);
    let submitting = $state(false);
    let message = $state<string | null>(null);

    $effect(() => {
        if (!startDate) {
            startDate = todayDate;
            endDate = todayDate;
        }
    });

    async function submitLeave() {
        submitting = true;
        message = null;

        try {
            const form = new FormData();
            form.append('type', type);
            form.append('start_date', startDate);
            form.append('end_date', endDate);
            form.append('reason', reason);
            if (type === 'DINAS_LUAR') {
                form.append('destination_location', destinationLocation);
                form.append('scheduled_start_time', scheduledStartTime);
            }
            if (attachmentFile) {
                form.append('attachment', attachmentFile);
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
                const err = data.errors
                    ? Object.values(data.errors).flat()[0]
                    : null;
                message = err ?? data.message ?? 'Pengajuan gagal.';
                return;
            }

            reason = '';
            attachmentFile = null;
            const warnings: string[] = [];
            if (data.monthly_limit_warning) {
                warnings.push(
                    'Cuti melebihi 2 hari/bulan; HR akan meminta konfirmasi sebelum menyetujui.',
                );
            }
            if (data.hr_conflict_warning) {
                warnings.push(
                    'Jumlah HR Admin yang masuk kerja pada tanggal tersebut kurang dari 2 orang.',
                );
            }
            message = `Pengajuan berhasil dikirim.${warnings.length > 0 ? ' ' + warnings.join(' ') : ''}`;
            router.reload();
        } finally {
            submitting = false;
        }
    }

    async function cancelRequest(id: number) {
        const res = await fetch(`/leave/${id}/cancel`, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'X-XSRF-TOKEN': readXsrfToken(),
            },
        });
        if (res.ok) {
            router.reload();
        }
    }
</script>

<AppHead title="Cuti, Izin & Dinas" />

<div class="mx-auto flex w-full max-w-2xl flex-col gap-4 p-4">
    <div class="rounded-xl border bg-card p-4">
        <p class="text-xs text-muted-foreground">
            Saldo Cuti Tahunan ({balance.year})
        </p>
        <p class="text-2xl font-bold">{balance.available_balance} Hari</p>
        <p class="mt-1 text-xs text-muted-foreground">
            Jatah: {balance.base_quota} | Prorata: -{balance.prorated_deduction} |
            Cutber: -{balance.collective_leave_deduction} | Terpakai:
            {balance.used_days} | Pending: {balance.pending_days}
            {#if balance.adjustment_days !== 0}
                | Penyesuaian: +{balance.adjustment_days}
            {/if}
        </p>
        <p class="mt-1 text-[11px] text-muted-foreground">
            {#if balance.eligible_for_annual_leave}
                Potongan Cuti Bersama (Cutber) mengurangi saldo cuti tahunan sesuai kalender libur perusahaan.
            {:else}
                Masa kerja &lt; 1 tahun: Cuti Bersama tidak memotong saldo cuti (Cutber 0 hari).
            {/if}
        </p>
    </div>

    <div class="flex flex-col gap-3 rounded-xl border bg-card p-4">
        <h2 class="font-semibold">Ajukan Cuti / Izin / Sakit / Dinas Luar</h2>

        <select
            bind:value={type}
            class="min-h-11 rounded-md border bg-background px-3 text-sm"
        >
            {#if balance.eligible_for_annual_leave}
                <option value="CUTI">Cuti Tahunan</option>
            {/if}
            <option value="IZIN">Izin</option>
            <option value="SAKIT">Sakit (Wajib SKD)</option>
            {#if canSubmitDinasLuar}
                <option value="DINAS_LUAR">Dinas Luar Terjadwal</option>
            {/if}
        </select>

        <div class="grid grid-cols-2 gap-2">
            <Input type="date" bind:value={startDate} />
            <Input type="date" bind:value={endDate} />
        </div>

        {#if type === 'DINAS_LUAR'}
            <Input
                bind:value={destinationLocation}
                placeholder="Kota / lokasi tujuan dinas"
            />
            <Input type="time" bind:value={scheduledStartTime} />
        {/if}

        <textarea
            bind:value={reason}
            rows="2"
            placeholder="Alasan pengajuan"
            class="w-full rounded-md border bg-background p-2 text-sm"
        ></textarea>

        <input
            type="file"
            accept=".jpg,.jpeg,.png,.pdf"
            onchange={(e) => {
                const input = e.currentTarget as HTMLInputElement;
                attachmentFile = input.files?.[0] ?? null;
            }}
            class="text-sm"
        />

        {#if message}
            <p class="text-sm text-primary">{message}</p>
        {/if}

        <Button
            type="button"
            class="min-h-12 w-full"
            disabled={submitting}
            onclick={submitLeave}
        >
            Kirim Pengajuan
        </Button>
    </div>

    <div class="space-y-2">
        {#each requests as item (item.id)}
            <div class="rounded-lg border bg-card p-3 text-sm">
                <div class="flex items-center justify-between font-medium">
                    <span>
                        {item.type} ({item.start_date} s/d {item.end_date}, {item.total_days}
                        hari)
                    </span>
                    <span class="text-xs">{item.status}</span>
                </div>
                <p class="mt-1 text-xs text-muted-foreground">{item.reason}</p>
                {#if item.reject_reason}
                    <p class="mt-1 text-xs text-destructive">
                        Alasan Ditolak: {item.reject_reason}
                    </p>
                {/if}
                {#if item.status === 'PENDING'}
                    <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        class="mt-2"
                        onclick={() => cancelRequest(item.id)}
                    >
                        Batalkan Pengajuan
                    </Button>
                {/if}
            </div>
        {/each}
    </div>
</div>
