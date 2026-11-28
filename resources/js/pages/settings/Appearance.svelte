<script module lang="ts">
    import { edit as editAppearance } from '@/routes/appearance';

    export const layout = {
        breadcrumbs: [
            {
                title: 'Appearance settings',
                href: editAppearance(),
            },
        ],
    };
</script>

<script lang="ts">
    import AppearanceTabs from '@/components/AppearanceTabs.svelte';
    import AppHead from '@/components/AppHead.svelte';
    import Heading from '@/components/Heading.svelte';
</script>

<AppHead title="Tampilan" />

<h1 class="sr-only">Tampilan</h1>

<div class="space-y-6">
    <Heading
        variant="small"
        title="Tampilan"
        description="Aplikasi ini menggunakan Mode Terang sebagai standar tampilan corporate PT. LSI."
    />
    <AppearanceTabs />
</div>
