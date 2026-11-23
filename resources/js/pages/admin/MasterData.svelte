<script lang="ts">
    import AppHead from '@/components/AppHead.svelte';

    type MasterDataProps = {
        users: { id: number; nik: string; name: string; role: string }[];
        locations: { id: number; code: string | null; name: string; type: string; status: string }[];
        shifts: { id: number; code: string; name: string; start_time: string; end_time: string }[];
        holidays: { id: number; holiday_date: string; description: string; holiday_type: string }[];
    };

    let { users, locations, shifts, holidays }: MasterDataProps = $props();
</script>

<AppHead title="Master Data HR" />

<div class="mx-auto flex w-full max-w-5xl flex-col gap-4 p-4 text-sm">
    <h1 class="text-xl font-bold">Master Data HR</h1>
    <div class="grid gap-4 md:grid-cols-2">
        <div class="rounded-lg border bg-card p-4">
            <h2 class="mb-2 font-semibold">Karyawan ({users.length})</h2>
            {#each users as u (u.id)}
                <p>{u.nik} - {u.name} ({u.role})</p>
            {/each}
        </div>
        <div class="rounded-lg border bg-card p-4">
            <h2 class="mb-2 font-semibold">Lokasi ({locations.length})</h2>
            {#each locations as loc (loc.id)}
                <p>{loc.code ?? 'NOO'} - {loc.name} [{loc.type}/{loc.status}]</p>
            {/each}
        </div>
        <div class="rounded-lg border bg-card p-4">
            <h2 class="mb-2 font-semibold">Shift ({shifts.length})</h2>
            {#each shifts as s (s.id)}
                <p>{s.code} - {s.name} ({s.start_time}-{s.end_time})</p>
            {/each}
        </div>
        <div class="rounded-lg border bg-card p-4">
            <h2 class="mb-2 font-semibold">Hari Libur ({holidays.length})</h2>
            {#each holidays as h (h.id)}
                <p>{h.holiday_date} - {h.description} ({h.holiday_type})</p>
            {/each}
        </div>
    </div>
</div>
