<script lang="ts">
    import { page } from '@inertiajs/svelte';
    import AppHead from '@/components/AppHead.svelte';

    type AuditLogItem = {
        id: number;
        actor: { nik: string; name: string } | null;
        entity: string;
        record_id: number;
        action: string;
        reason: string | null;
        old_values: Record<string, unknown> | null;
        new_values: Record<string, unknown> | null;
        prev_hash: string | null;
        row_hash: string | null;
        has_hash: boolean;
        hash_valid: boolean;
        chain_valid: boolean;
        created_at: string;
    };

    let { logs }: { logs: AuditLogItem[] } = $props();

    let openId = $state<number | null>(null);

    const timezone = $derived(
        (page.props.office as { timezone?: string } | undefined)?.timezone ??
            'Asia/Jakarta',
    );
    const invalidCount = $derived(
        logs.filter(
            (log) => log.has_hash && (!log.hash_valid || !log.chain_valid),
        ).length,
    );
    const legacyCount = $derived(
        logs.filter((log) => !log.has_hash).length,
    );

    function formatDateTime(value: string): string {
        return new Intl.DateTimeFormat('id-ID', {
            timeZone: timezone,
            day: '2-digit',
            month: '2-digit',
            year: 'numeric',
            hour: '2-digit',
            minute: '2-digit',
            second: '2-digit',
            hourCycle: 'h23',
        }).format(new Date(value));
    }

    function pretty(value: Record<string, unknown> | null): string {
        return value === null ? '—' : JSON.stringify(value, null, 2);
    }

    function entityLabel(entity: string): string {
        return entity.replaceAll('_', ' ');
    }

    function actionClass(action: string): string {
        const base =
            'inline-flex rounded border px-1.5 py-0.5 text-[11px] font-medium whitespace-nowrap';

        switch (action) {
            case 'CREATE':
                return `${base} border-green-300 bg-green-50 text-green-700`;
            case 'UPDATE':
            case 'CORRECT_STATUS':
                return `${base} border-blue-300 bg-blue-50 text-blue-700`;
            case 'SOFT_DELETE':
                return `${base} border-red-300 bg-red-50 text-red-700`;
            case 'RESET_PASSWORD':
            case 'UNLOCK_ACCOUNT':
            case 'OVERRIDE_LIMIT':
                return `${base} border-yellow-300 bg-yellow-50 text-yellow-800`;
            case 'MANUAL_ATTENDANCE':
            case 'CANCEL_LEAVE':
            case 'BULK_IMPORT':
            case 'EXPORT_ATTENDANCE':
                return `${base} border-stone-300 bg-stone-100 text-stone-700`;
            default:
                return `${base} border-stone-300 bg-stone-100 text-stone-700`;
        }
    }
</script>

<AppHead title="Riwayat Audit Sistem" />

<div class="mx-auto flex w-full max-w-7xl flex-col gap-3 p-4 lg:p-5">
    <div>
        <h1 class="text-lg font-semibold tracking-tight text-stone-900 md:text-xl">
            Riwayat Audit Sistem
        </h1>
        <p class="mt-0.5 text-[11px] text-stone-500">
            Read-only. Setiap perubahan master data dan tindakan HR tercatat
            dengan hash chain.
        </p>
        <p class="mt-1 text-[11px] text-stone-500">
            Menampilkan {logs.length} catatan terbaru ·
            {logs.length - invalidCount - legacyCount} hash valid
            {#if legacyCount > 0}
                · {legacyCount} legacy tanpa hash
            {/if}
            {#if invalidCount > 0}
                <span class="font-medium text-red-700">
                    · {invalidCount} tidak valid
                </span>
            {/if}
        </p>
    </div>

    {#if logs.length === 0}
        <div
            class="rounded-xl border border-dashed border-stone-300 bg-white p-6 text-center"
        >
            <p class="text-sm font-medium text-stone-700">Belum ada audit log</p>
            <p class="mt-0.5 text-[11px] text-stone-500">
                Perubahan data dan tindakan HR akan tercatat di sini.
            </p>
        </div>
    {:else}
        <div
            class="overflow-x-auto rounded-xl border border-stone-200 bg-white shadow-xs"
        >
            <table class="min-w-full text-[13px]">
                <thead
                    class="bg-stone-100 text-[11px] uppercase tracking-wide text-stone-500"
                >
                    <tr>
                        <th class="px-2.5 py-2 text-left font-medium">Waktu</th>
                        <th class="px-2.5 py-2 text-left font-medium">Aktor</th>
                        <th class="px-2.5 py-2 text-left font-medium">Aksi</th>
                        <th class="px-2.5 py-2 text-left font-medium">Entitas</th>
                        <th class="px-2.5 py-2 text-left font-medium">Alasan</th>
                        <th class="px-2.5 py-2 text-left font-medium">Hash</th>
                        <th class="px-2.5 py-2 text-right font-medium"></th>
                    </tr>
                </thead>
                <tbody>
                    {#each logs as log (log.id)}
                        {@const valid =
                            log.has_hash && log.hash_valid && log.chain_valid}
                        <tr class="border-t border-stone-200 align-top">
                            <td
                                class="whitespace-nowrap px-2.5 py-2 font-mono text-[11px] text-stone-700"
                            >
                                {formatDateTime(log.created_at)}
                            </td>
                            <td class="px-2.5 py-2">
                                {#if log.actor}
                                    <p class="font-medium text-stone-900">
                                        {log.actor.name}
                                    </p>
                                    <p class="font-mono text-[10px] text-stone-500">
                                        {log.actor.nik}
                                    </p>
                                {:else}
                                    —
                                {/if}
                            </td>
                            <td class="px-2.5 py-2">
                                <span class={actionClass(log.action)}>
                                    {log.action}
                                </span>
                            </td>
                            <td class="px-2.5 py-2">
                                <p class="font-medium capitalize text-stone-800">
                                    {entityLabel(log.entity)}
                                </p>
                                <p class="font-mono text-[10px] text-stone-500">
                                    #{log.record_id}
                                </p>
                            </td>
                            <td
                                class="max-w-56 px-2.5 py-2 text-[12px] text-stone-600"
                            >
                                {log.reason ?? '—'}
                            </td>
                            <td class="px-2.5 py-2">
                                {#if !log.has_hash}
                                    <span
                                        class="inline-flex rounded border border-stone-300 bg-stone-100 px-1.5 py-0.5 text-[11px] font-medium text-stone-700"
                                    >
                                        Legacy
                                    </span>
                                {:else}
                                    <span
                                        class="inline-flex rounded border px-1.5 py-0.5 text-[11px] font-medium {valid
                                            ? 'border-green-300 bg-green-50 text-green-700'
                                            : 'border-red-300 bg-red-50 text-red-700'}"
                                    >
                                        {valid
                                            ? 'Terverifikasi'
                                            : 'Tidak valid'}
                                    </span>
                                    {#if log.row_hash}
                                        <p
                                            class="mt-0.5 max-w-36 truncate font-mono text-[10px] text-stone-500"
                                            title={log.row_hash}
                                        >
                                            {log.row_hash.slice(0, 16)}…
                                        </p>
                                    {/if}
                                {/if}
                            </td>
                            <td class="px-2.5 py-2 text-right">
                                <button
                                    type="button"
                                    class="min-h-9 rounded-md border border-stone-300 px-2 text-[11px] font-medium text-stone-700 hover:bg-stone-50"
                                    aria-expanded={openId === log.id}
                                    onclick={() => {
                                        openId =
                                            openId === log.id ? null : log.id;
                                    }}
                                >
                                    {openId === log.id ? 'Tutup' : 'Detail'}
                                </button>
                            </td>
                        </tr>

                        {#if openId === log.id}
                            <tr class="border-t border-stone-100 bg-stone-50">
                                <td colspan="7" class="p-3">
                                    <div class="grid gap-3 lg:grid-cols-2">
                                        <div>
                                            <p
                                                class="text-[10px] font-medium uppercase tracking-wide text-stone-500"
                                            >
                                                Nilai lama
                                            </p>
                                            <pre
                                                class="mt-1 max-h-64 overflow-auto rounded-md border border-stone-200 bg-white p-2 font-mono text-[11px] leading-snug text-stone-700">{pretty(
                                                    log.old_values,
                                                )}</pre>
                                        </div>
                                        <div>
                                            <p
                                                class="text-[10px] font-medium uppercase tracking-wide text-stone-500"
                                            >
                                                Nilai baru
                                            </p>
                                            <pre
                                                class="mt-1 max-h-64 overflow-auto rounded-md border border-stone-200 bg-white p-2 font-mono text-[11px] leading-snug text-stone-700">{pretty(
                                                    log.new_values,
                                                )}</pre>
                                        </div>
                                    </div>

                                    {#if log.reason}
                                        <p class="mt-2 text-[12px] text-stone-700">
                                            <span class="font-medium">Alasan:</span>
                                            {log.reason}
                                        </p>
                                    {/if}

                                    <div
                                        class="mt-2 grid gap-2 font-mono text-[10px] text-stone-500 lg:grid-cols-2"
                                    >
                                        <p class="break-all">
                                            prev_hash: {log.prev_hash ?? '—'}
                                        </p>
                                        <p class="break-all">
                                            row_hash: {log.row_hash ?? '—'}
                                        </p>
                                    </div>

                                    {#if log.has_hash}
                                        <p class="mt-1 text-[10px] text-stone-500">
                                            Hash valid:
                                            {log.hash_valid ? 'ya' : 'tidak'} ·
                                            Rantai valid:
                                            {log.chain_valid ? 'ya' : 'tidak'}
                                        </p>
                                    {:else}
                                        <p class="mt-1 text-[10px] text-stone-500">
                                            Log legacy: dibuat sebelum hash chain
                                            aktif.
                                        </p>
                                    {/if}

                                    {#if log.has_hash && !valid}
                                        <p
                                            class="mt-1 text-[11px] font-medium text-red-700"
                                        >
                                            Hash tidak cocok. Data audit mungkin
                                            berubah di luar aplikasi.
                                        </p>
                                    {/if}
                                </td>
                            </tr>
                        {/if}
                    {/each}
                </tbody>
            </table>
        </div>
    {/if}
</div>
