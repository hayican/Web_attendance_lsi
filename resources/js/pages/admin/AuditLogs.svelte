<script lang="ts">
    import AppHead from '@/components/AppHead.svelte';

    let {
        logs,
    }: {
        logs: {
            id: number;
            entity: string;
            record_id: number;
            action: string;
            reason: string | null;
            created_at: string;
            actor: { nik: string; name: string } | null;
        }[];
    } = $props();
</script>

<AppHead title="Riwayat Audit Sistem" />

<div class="mx-auto flex w-full max-w-4xl flex-col gap-3 p-4 text-sm">
    <h1 class="text-xl font-bold">Riwayat Audit Sistem</h1>
    {#each logs as log (log.id)}
        <div class="rounded-lg border bg-card p-3">
            <p class="font-medium">
                {log.action} - {log.entity} #{log.record_id}
            </p>
            <p class="text-xs text-muted-foreground">
                Oleh: {log.actor?.nik ?? '-'} {log.actor?.name ?? ''} | {log.created_at}
            </p>
            {#if log.reason}
                <p class="mt-1 text-xs">Alasan: {log.reason}</p>
            {/if}
        </div>
    {/each}
</div>
