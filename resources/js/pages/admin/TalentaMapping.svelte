<script lang="ts">
    import AppHead from '@/components/AppHead.svelte';

    let {
        mappings,
    }: {
        mappings: {
            id: number;
            mapping_key: string;
            mapping_value: string;
            description: string | null;
        }[];
    } = $props();
</script>

<AppHead title="Mapping Kode Talenta" />

<div class="mx-auto flex w-full max-w-3xl flex-col gap-3 p-4 text-sm">
    <h1 class="text-xl font-bold">Pengaturan Mapping Kode Mekari Talenta</h1>
    {#each mappings as m (m.id)}
        <div class="flex items-center justify-between rounded-lg border bg-card p-3">
            <div>
                <p class="font-medium">{m.mapping_key}</p>
                <p class="text-xs text-muted-foreground">{m.description ?? '-'}</p>
            </div>
            <span class="font-mono font-semibold">{m.mapping_value}</span>
        </div>
    {/each}
</div>
