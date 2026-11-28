<script lang="ts">
    import Sun from '@lucide/svelte/icons/sun';
    import { updateAppearance } from '@/lib/theme.svelte';

    $effect(() => {
        updateAppearance('light');
    });
</script>

<div
    class="inline-flex items-center gap-2 rounded-lg border border-stone-200 bg-white px-3.5 py-2 text-sm font-medium text-stone-900 shadow-xs"
>
    <Sun class="h-4 w-4 text-[#6F3E1D]" />
    <span>Mode Terang (Standar Corporate PT. LSI)</span>
</div>
