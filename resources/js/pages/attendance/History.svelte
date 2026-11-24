<script lang="ts">
    import AppHead from '@/components/AppHead.svelte';

    let {
        mode,
        month,
        items,
    }: {
        mode: 'office' | 'sales';
        month: string;
        items: Record<string, unknown>[];
    } = $props();
</script>

<AppHead title="Riwayat Absensi" />

<div class="mx-auto flex w-full max-w-2xl flex-col gap-3 p-4 text-sm">
    <h1 class="text-xl font-bold">
        Riwayat {mode === 'sales' ? 'Kunjungan Sales' : 'Absensi Kantor'} ({month})
    </h1>

    {#each items as item, idx (idx)}
        <div class="rounded-lg border bg-card p-3">
            {#if mode === 'sales'}
                <p class="font-medium">
                    {item.date} - {item.outlet_name} ({item.visit_status} / {item.verification_status})
                </p>
                <p class="text-xs text-muted-foreground">
                    In: {item.visit_in_at ?? '-'} | Out: {item.visit_out_at ?? '-'}
                </p>
                {#if item.reject_reason}
                    <p class="mt-1 text-xs text-destructive">
                        Ditolak: {item.reject_reason}
                    </p>
                {/if}
            {:else}
                <p class="font-medium">
                    {item.date} (Percobaan #{item.attempt_no}) - Shift {item.shift}
                </p>
                <p class="text-xs text-muted-foreground">
                    Masuk: {item.clock_in_at ?? '-'} [{item.clock_in_status}] |
                    Pulang: {item.clock_out_at ?? '-'} [{item.clock_out_status ?? '-'}]
                </p>
                {#if item.clock_in_reject_reason}
                    <p class="mt-1 text-xs text-destructive">
                        Reject Masuk: {item.clock_in_reject_reason}
                    </p>
                {/if}
            {/if}
        </div>
    {/each}
</div>
