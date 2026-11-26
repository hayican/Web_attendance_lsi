<script module lang="ts">
    export const layout = {
        breadcrumbs: [
            {
                title: 'Riwayat Absensi',
                href: '/attendance/history',
            },
        ],
    };
</script>

<script lang="ts">
    import OfficeRiwayat from '@/pages/office/Riwayat.svelte';

    const props = $props();
</script>

<OfficeRiwayat {...props} />
