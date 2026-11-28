<script module lang="ts">
    export const layout = {
        breadcrumbs: [
            {
                title: 'Mapping Talenta',
                href: '/admin/talenta-mapping',
            },
        ],
    };
</script>

<script lang="ts">
    import { Link, router } from '@inertiajs/svelte';
    import Save from '@lucide/svelte/icons/save';
    import AppHead from '@/components/AppHead.svelte';
    import { Button } from '@/components/ui/button';
    import { Input } from '@/components/ui/input';
    import { readXsrfToken } from '@/lib/gpsClient';

    type MappingItem = {
        id: number;
        mapping_key: string;
        mapping_value: string;
        description: string | null;
        updated_at: string;
        updater?: { id: number; nik: string; name: string } | null;
    };

    let { mappings }: { mappings: MappingItem[] } = $props();

    let draftValues = $state<Record<number, string>>({});
    let draftDescriptions = $state<Record<number, string>>({});
    let savingId = $state<number | null>(null);
    let errorMsg = $state<string | null>(null);
    let successMsg = $state<string | null>(null);

    $effect(() => {
        const vals: Record<number, string> = {};
        const descs: Record<number, string> = {};
        for (const m of mappings) {
            vals[m.id] = m.mapping_value;
            descs[m.id] = m.description ?? '';
        }
        draftValues = vals;
        draftDescriptions = descs;
    });

    const booleanKeys = new Set([
        'use_offsite_schedule_for_talenta',
        'export_offday_overtime_to_talenta',
    ]);

    const keyLabels: Record<string, string> = {
        attendance_present: 'Kode Hadir',
        attendance_absent: 'Kode Alpa',
        timeoff_annual: 'Cuti Tahunan',
        timeoff_collective: 'Cuti Bersama (masa kerja >= 1 tahun)',
        timeoff_collective_unpaid: 'Cuti Bersama Tanpa Gaji (< 1 tahun)',
        timeoff_izin: 'Izin',
        timeoff_sakit: 'Sakit',
        use_offsite_schedule_for_talenta: 'Pakai Jam Dinas Luar untuk Jadwal Masuk/Pulang',
        export_offday_overtime_to_talenta: 'Ekspor Lembur Hari Libur ke Kolom Lembur',
    };

    const groups = $derived([
        {
            title: 'Kode Kehadiran (File 1 Talenta)',
            subtitle: 'Kode status hadir harian staf kantor dan HR pada kolom Attendance Code.',
            items: mappings.filter((m) => m.mapping_key.startsWith('attendance_')),
        },
        {
            title: 'Kode Ketidakhadiran (File 1 Talenta)',
            subtitle: 'Kode cuti, izin, dan sakit disetujui pada kolom Time Off Code.',
            items: mappings.filter((m) => m.mapping_key.startsWith('timeoff_')),
        },
        {
            title: 'Opsi Ekspor File 1 Talenta',
            subtitle: 'Atur ekspor dinas luar dan lembur hari libur mingguan.',
            items: mappings.filter((m) => booleanKeys.has(m.mapping_key)),
        },
    ]);

    function isDirty(m: MappingItem): boolean {
        return (
            (draftValues[m.id] ?? m.mapping_value) !== m.mapping_value ||
            (draftDescriptions[m.id] ?? (m.description ?? '')) !== (m.description ?? '')
        );
    }

    async function saveMapping(m: MappingItem) {
        savingId = m.id;
        errorMsg = null;
        successMsg = null;
        try {
            const res = await fetch(`/admin/talenta-mapping/${m.id}`, {
                method: 'PATCH',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-XSRF-TOKEN': readXsrfToken(),
                },
                body: JSON.stringify({
                    mapping_value: draftValues[m.id] ?? m.mapping_value,
                    description: draftDescriptions[m.id] ?? m.description,
                }),
            });

            const data = (await res.json().catch(() => ({}))) as {
                message?: string;
                errors?: Record<string, string[]>;
            };

            if (!res.ok) {
                const firstErr = data.errors ? Object.values(data.errors).flat()[0] : null;
                errorMsg = firstErr || data.message || 'Gagal menyimpan mapping Talenta.';
                return;
            }

            const label = keyLabels[m.mapping_key] ?? m.mapping_key;
            successMsg = `Pengaturan "${label}" berhasil disimpan dan dicatat ke riwayat audit.`;
            router.reload();
        } finally {
            savingId = null;
        }
    }
</script>

<AppHead title="Pengaturan Kode Ekspor Talenta" />

<div class="space-y-4 p-4 text-stone-900 lg:p-5">
    <div
        class="flex flex-wrap items-center justify-between gap-3 rounded-xl bg-gradient-to-r from-[#6F3E1D] via-[#8B5E3C] to-[#8B5E3C] p-4 text-white shadow-sm"
    >
        <div>
            <h1 class="text-lg font-semibold tracking-tight text-white md:text-xl">
                Pengaturan Kode Ekspor Talenta
            </h1>
            <p class="text-xs text-stone-200">
                Atur kode kehadiran, cuti/izin, dan opsi ekspor ke Mekari Talenta. Semua perubahan tercatat di riwayat audit.
            </p>
        </div>
        <Link
            href="/admin/master-data"
            class="rounded-md border border-white/25 bg-white/10 px-3 py-1.5 text-xs font-medium text-white hover:bg-white/20"
        >
            Profil Talenta Karyawan
        </Link>
    </div>

    {#if errorMsg}
        <div class="rounded-md border border-red-300 bg-red-50 px-3 py-2 text-xs text-red-700">
            {errorMsg}
        </div>
    {/if}
    {#if successMsg}
        <div class="rounded-md border border-green-300 bg-green-50 px-3 py-2 text-xs text-green-700">
            {successMsg}
        </div>
    {/if}

    <div class="space-y-4">
        {#each groups as group (group.title)}
            <section class="rounded-lg border border-stone-200 bg-white shadow-xs">
                <div class="border-b border-stone-200 px-3 py-2.5">
                    <h2 class="text-sm font-semibold text-stone-900">{group.title}</h2>
                    <p class="text-[11px] text-stone-500">{group.subtitle}</p>
                </div>

                <div class="divide-y divide-stone-200">
                    {#each group.items as m (m.id)}
                        {@const isBool = booleanKeys.has(m.mapping_key)}
                        <div class="flex flex-wrap items-center justify-between gap-3 p-3 text-[13px]">
                            <div class="min-w-64 flex-1 space-y-1">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="font-medium text-stone-900">
                                        {keyLabels[m.mapping_key] ?? m.mapping_key}
                                    </span>
                                    <span class="rounded border border-stone-200 bg-stone-100 px-1.5 py-0.5 font-mono text-[11px] text-stone-600">
                                        {m.mapping_key}
                                    </span>
                                </div>
                                <Input
                                    bind:value={draftDescriptions[m.id]}
                                    placeholder="Catatan singkat (opsional)"
                                    class="h-7 max-w-md border-stone-200 text-xs text-stone-600"
                                />
                                {#if m.updater}
                                    <p class="text-[11px] text-stone-500">
                                        Diubah oleh <span class="font-mono">{m.updater.nik}</span> ({m.updater.name})
                                    </p>
                                {/if}
                            </div>

                            <div class="flex items-center gap-2">
                                {#if isBool}
                                    <select
                                        aria-label={m.mapping_key}
                                        bind:value={draftValues[m.id]}
                                        class="h-8 w-28 rounded-md border border-stone-300 bg-white px-2 font-mono text-xs text-stone-900"
                                    >
                                        <option value="true">Aktif</option>
                                        <option value="false">Nonaktif</option>
                                    </select>
                                {:else}
                                    <Input
                                        aria-label={m.mapping_key}
                                        bind:value={draftValues[m.id]}
                                        class="h-8 w-28 border-stone-300 text-center font-mono text-xs font-semibold"
                                    />
                                {/if}

                                <Button
                                    size="sm"
                                    disabled={savingId !== null || !isDirty(m)}
                                    class="h-8 cursor-pointer bg-[#6F3E1D] px-3 text-xs text-white hover:bg-[#583015]"
                                    onclick={() => saveMapping(m)}
                                >
                                    <Save class="mr-1 size-3.5" />
                                    {savingId === m.id ? 'Menyimpan...' : 'Simpan'}
                                </Button>
                            </div>
                        </div>
                    {/each}
                </div>
            </section>
        {/each}
    </div>
</div>
