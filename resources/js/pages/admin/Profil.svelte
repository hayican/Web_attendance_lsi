<script module lang="ts">
    export const layout = {
        breadcrumbs: [
            {
                title: 'Profil',
                href: '/settings/profile',
            },
        ],
    };
</script>

<script lang="ts">
    import OfficeProfil from '@/pages/office/Profil.svelte';

    const props = $props();
</script>

<OfficeProfil {...props} />
