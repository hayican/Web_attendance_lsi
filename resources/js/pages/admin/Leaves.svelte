<script lang="ts">
    import { router } from '@inertiajs/svelte';
    import AppHead from '@/components/AppHead.svelte';
    import { Button } from '@/components/ui/button';
    import { readXsrfToken } from '@/lib/gpsClient';

    type ApprovalProps = {
        requests: {
            id: number;
            type: string;
            start_date: string;
            end_date: string;
            total_days: string;
            status: string;
            reason: string;
            user: {
                nik: string;
                name: string;
            };
        }[];
    };

    let { requests }: ApprovalProps = $props();
    let feedback = $state<string | null>(null);

    async function approve(id: number, override = false) {
        const res = await fetch(`/admin/leaves/${id}/approve`, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-XSRF-TOKEN': readXsrfToken(),
            },
            body: JSON.stringify({ override_monthly_limit: override }),
        });

        const data = (await res.json()) as {
            requires_override_confirmation?: boolean;
            message?: string;
        };

        if (res.status === 409 && data.requires_override_confirmation) {
            if (confirm(data.message ?? 'Tetap setujui?')) {
                await approve(id, true);
            }
            return;
        }

        if (!res.ok) {
            feedback = data.message ?? 'Gagal menyetujui.';
            return;
        }

        router.reload();
    }

    async function reject(id: number) {
        const reason = prompt('Alasan penolakan:');
        if (!reason) {
            return;
        }

        const res = await fetch(`/admin/leaves/${id}/reject`, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-XSRF-TOKEN': readXsrfToken(),
            },
            body: JSON.stringify({ reject_reason: reason }),
        });

        if (res.ok) {
            router.reload();
        }
    }
</script>

<AppHead title="Persetujuan Cuti & Izin" />

<div class="mx-auto flex w-full max-w-4xl flex-col gap-4 p-4">
    <h1 class="text-xl font-bold">Persetujuan Cuti, Izin, Sakit &amp; Dinas</h1>

    {#if feedback}
        <p class="rounded-md bg-destructive/10 p-3 text-sm text-destructive">
            {feedback}
        </p>
    {/if}

    <div class="space-y-2">
        {#each requests as req (req.id)}
            <div
                class="flex flex-col gap-2 rounded-lg border bg-card p-4 text-sm"
            >
                <div class="flex items-center justify-between">
                    <span class="font-semibold">
                        {req.user.nik} - {req.user.name} ({req.type})
                    </span>
                    <span class="text-xs font-medium">{req.status}</span>
                </div>
                <p class="text-xs text-muted-foreground">
                    {req.start_date} s/d {req.end_date} ({req.total_days} hari)
                    - {req.reason}
                </p>
                {#if req.status === 'PENDING'}
                    <div class="flex gap-2">
                        <Button size="sm" onclick={() => approve(req.id)}>
                            Approve
                        </Button>
                        <Button
                            size="sm"
                            variant="destructive"
                            onclick={() => reject(req.id)}
                        >
                            Reject
                        </Button>
                    </div>
                {/if}
            </div>
        {/each}
    </div>
</div>
