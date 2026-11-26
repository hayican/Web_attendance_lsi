<script module lang="ts">
    export const layout = {
        breadcrumbs: [
            {
                title: 'Pengajuan Saya',
                href: '/admin/pengajuan-saya',
            },
        ],
    };
</script>

<script lang="ts">
    import OfficePengajuan from '@/pages/office/Pengajuan.svelte';

    const props = $props();
</script>

<OfficePengajuan {...props} desktop />
