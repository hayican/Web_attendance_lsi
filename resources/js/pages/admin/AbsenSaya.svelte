<script module lang="ts">
    export const layout = {
        breadcrumbs: [
            {
                title: 'Absen Saya',
                href: '/admin/absen-saya',
            },
        ],
    };
</script>

<script lang="ts">
    import OfficeAbsen from '@/pages/office/Absen.svelte';

    const props = $props();
</script>

<div class="mx-auto w-full max-w-2xl p-4 lg:p-5">
    <OfficeAbsen {...props} />
</div>
