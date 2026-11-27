<script lang="ts">
    import { cn } from '@/lib/utils';

    let {
        class: className = '',
        ...rest
    }: {
        class?: string;
        [key: string]: unknown;
    } = $props();
</script>

<img
    src="/images/logo-ladang-lima.svg"
    alt="Ladang Lima"
    class={cn('object-contain', className)}
    {...rest}
/>
