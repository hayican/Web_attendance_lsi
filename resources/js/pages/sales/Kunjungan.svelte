<script lang="ts">
    import { onMount } from 'svelte';
    import { SvelteURLSearchParams } from 'svelte/reactivity';
    import { router } from '@inertiajs/svelte';
    import AppHead from '@/components/AppHead.svelte';
    import LiveCameraCapture from '@/components/LiveCameraCapture.svelte';
    import { Button } from '@/components/ui/button';
    import { Input } from '@/components/ui/input';
    import {
        isInAppBrowser,
        recommendedExternalBrowserInstruction,
    } from '@/lib/deviceCheck';
    import {
        type FreshGpsResult,
        readXsrfToken,
        requestFreshGps,
    } from '@/lib/gpsClient';

    type OutletItem = {
        id: number;
        code: string | null;
        name: string;
        address: string;
        radius_m: number;
        status: string;
        distance_m: number | null;
    };

    type SalesProps = {
        homeTimezone: string;
        todayDate: string;
        hasApprovedTimeOff: boolean;
        activeVisit: {
            id: number;
            outlet_name: string | null;
            outlet_code: string | null;
            outlet_address: string | null;
            outlet_radius_m: number | null;
            visit_in_at: string | null;
            verification_status: string;
            can_cancel: boolean;
        } | null;
        recentOutlets: OutletItem[];
        categories: {
            id: number;
            name: string;
            exempt_short_duration: boolean;
        }[];
    };

    let {
        homeTimezone,
        todayDate,
        hasApprovedTimeOff,
        activeVisit,
        recentOutlets,
        categories,
    }: SalesProps = $props();

    let inAppWarning = $state(false);
    let gps = $state<FreshGpsResult | null>(null);
    let searchQuery = $state('');
    let searchResults = $state<OutletItem[]>([]);
    let selectedOutlet = $state<OutletItem | null>(null);
    let capturedPhoto = $state<{ blob: Blob; cameraToken: string } | null>(
        null,
    );
    let notes = $state('');
    let cancelReason = $state('');
    let selectedCategoryId = $state<number | ''>('');
    let submitting = $state(false);
    let feedback = $state<{ type: 'error' | 'success'; message: string } | null>(
        null,
    );

    async function refreshGps() {
        gps = await requestFreshGps(
            activeVisit?.outlet_radius_m ?? selectedOutlet?.radius_m ?? 100,
        );
    }

    async function loadOutlets() {
        const params = new SvelteURLSearchParams();
        if (searchQuery.trim()) {
            params.set('q', searchQuery.trim());
        }
        if (gps?.lat !== null && gps?.lng !== null && gps) {
            params.set('lat', String(gps.lat));
            params.set('lng', String(gps.lng));
        }

        const res = await fetch(
            `/attendance/sales/outlets?${params.toString()}`,
            {
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
            },
        );
        if (res.ok) {
            const data = (await res.json()) as { outlets: OutletItem[] };
            searchResults = data.outlets;
        }
    }

    onMount(() => {
        inAppWarning = isInAppBrowser();
        void refreshGps().then(() => loadOutlets());
    });

    async function submitVisitIn() {
        if (!selectedOutlet) {
            feedback = { type: 'error', message: 'Pilih toko tujuan dulu.' };
            return;
        }
        if (!gps?.gpsSessionToken || gps.lat === null || gps.lng === null) {
            feedback = {
                type: 'error',
                message: 'Lokasi GPS belum siap. Muat ulang GPS.',
            };
            return;
        }
        if (!capturedPhoto) {
            feedback = {
                type: 'error',
                message: 'Visit In wajib ambil 1x foto selfie kamera langsung.',
            };
            return;
        }

        submitting = true;
        feedback = null;

        try {
            const form = new FormData();
            form.append('idempotency_key', crypto.randomUUID());
            form.append('outlet_location_id', String(selectedOutlet.id));
            form.append('lat', String(gps.lat));
            form.append('lng', String(gps.lng));
            form.append('accuracy_m', String(gps.accuracyM ?? 0));
            form.append('gps_session_token', gps.gpsSessionToken);
            form.append('camera_token', capturedPhoto.cameraToken);
            form.append('photo', capturedPhoto.blob, 'visit-in.jpg');
            if (notes.trim()) {
                form.append('notes', notes.trim());
            }

            const res = await fetch('/attendance/sales/visit-in', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'X-XSRF-TOKEN': readXsrfToken(),
                },
                body: form,
            });

            const data = (await res.json()) as {
                message?: string;
                errors?: Record<string, string[]>;
            };

            if (!res.ok) {
                const firstErr = data.errors
                    ? Object.values(data.errors).flat()[0]
                    : null;
                feedback = {
                    type: 'error',
                    message: firstErr ?? data.message ?? 'Visit In gagal.',
                };
                return;
            }

            capturedPhoto = null;
            notes = '';
            router.reload();
        } finally {
            submitting = false;
        }
    }

    async function submitVisitOut() {
        if (!activeVisit) {
            return;
        }
        if (!gps?.gpsSessionToken || gps.lat === null || gps.lng === null) {
            feedback = {
                type: 'error',
                message: 'Lokasi GPS belum siap. Muat ulang GPS.',
            };
            return;
        }
        if (!selectedCategoryId) {
            feedback = {
                type: 'error',
                message: 'Pilih kategori aktivitas terlebih dahulu.',
            };
            return;
        }

        submitting = true;
        feedback = null;

        try {
            const res = await fetch(
                `/attendance/sales/${activeVisit.id}/visit-out`,
                {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                        'X-XSRF-TOKEN': readXsrfToken(),
                    },
                    body: JSON.stringify({
                        idempotency_key: crypto.randomUUID(),
                        activity_category_id: selectedCategoryId,
                        visit_notes: notes.trim(),
                        lat: gps.lat,
                        lng: gps.lng,
                        accuracy_m: gps.accuracyM ?? 0,
                        gps_session_token: gps.gpsSessionToken,
                    }),
                },
            );

            const data = (await res.json()) as {
                message?: string;
                errors?: Record<string, string[]>;
            };
            if (!res.ok) {
                const firstErr = data.errors
                    ? Object.values(data.errors).flat()[0]
                    : null;
                feedback = {
                    type: 'error',
                    message: firstErr ?? data.message ?? 'Visit Out gagal.',
                };
                return;
            }

            notes = '';
            selectedCategoryId = '';
            router.reload();
        } finally {
            submitting = false;
        }
    }

    async function cancelActiveVisit() {
        if (!activeVisit || !cancelReason.trim()) {
            feedback = {
                type: 'error',
                message: 'Isi alasan pembatalan Visit In.',
            };
            return;
        }

        submitting = true;
        try {
            const res = await fetch(
                `/attendance/sales/${activeVisit.id}/cancel`,
                {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                        'X-XSRF-TOKEN': readXsrfToken(),
                    },
                    body: JSON.stringify({ cancel_reason: cancelReason.trim() }),
                },
            );

            if (!res.ok) {
                const data = (await res.json()) as { message?: string };
                feedback = {
                    type: 'error',
                    message: data.message ?? 'Gagal membatalkan Visit In.',
                };
                return;
            }

            cancelReason = '';
            router.reload();
        } finally {
            submitting = false;
        }
    }
</script>

<AppHead title="Kunjungan Sales" />

<div class="mx-auto flex w-full max-w-md flex-col gap-4 p-4">
    {#if inAppWarning}
        <div
            class="rounded-lg border border-amber-500/50 bg-amber-50 p-3 text-sm text-amber-900"
        >
            {recommendedExternalBrowserInstruction()}
        </div>
    {/if}

    <div class="rounded-xl border bg-card p-4">
        <p class="text-xs text-muted-foreground">
            Kunjungan Sales - {todayDate} ({homeTimezone})
        </p>
        <div class="mt-2 flex items-center justify-between gap-2">
            <span class="text-sm font-medium">
                GPS:
                {#if gps?.status === 'OK'}
                    Akurat (+/-{Math.round(gps.accuracyM ?? 0)}m)
                {:else if gps?.status === 'POOR_ACCURACY'}
                    Kurang akurat (+/-{Math.round(gps.accuracyM ?? 0)}m)
                {:else}
                    Belum siap
                {/if}
            </span>
            <Button
                type="button"
                size="sm"
                variant="outline"
                onclick={refreshGps}
            >
                Muat Ulang GPS
            </Button>
        </div>
    </div>

    {#if hasApprovedTimeOff}
        <div class="rounded-lg border bg-muted p-4 text-sm">
            Anda tidak dapat berkunjung karena sedang dalam status
            Cuti/Izin/Sakit yang telah disetujui hari ini.
        </div>
    {:else if activeVisit}
        <div class="flex flex-col gap-3 rounded-xl border bg-card p-4">
            <p class="text-xs font-medium text-blue-600">KUNJUNGAN BERJALAN</p>
            <h2 class="text-base font-semibold">{activeVisit.outlet_name}</h2>
            <p class="text-xs text-muted-foreground">
                {activeVisit.outlet_address}
            </p>

            <select
                bind:value={selectedCategoryId}
                class="min-h-11 rounded-md border bg-background px-3 text-sm"
            >
                <option value="">Pilih Kategori Aktivitas...</option>
                {#each categories as cat (cat.id)}
                    <option value={cat.id}>{cat.name}</option>
                {/each}
            </select>

            <textarea
                bind:value={notes}
                rows="3"
                placeholder="Catatan hasil kunjungan (tanpa foto saat Visit Out)"
                class="w-full rounded-md border bg-background p-2 text-sm"
            ></textarea>

            <Button
                type="button"
                class="min-h-12 w-full"
                disabled={submitting}
                onclick={submitVisitOut}
            >
                Visit Out (Selesai Kunjungan)
            </Button>

            {#if activeVisit.can_cancel}
                <div class="mt-2 border-t pt-3">
                    <Input
                        bind:value={cancelReason}
                        placeholder="Alasan salah pilih toko (maks 5 menit)"
                    />
                    <Button
                        type="button"
                        variant="outline"
                        class="mt-2 min-h-12 w-full"
                        disabled={submitting}
                        onclick={cancelActiveVisit}
                    >
                        Batalkan Visit In
                    </Button>
                </div>
            {/if}
        </div>
    {:else}
        <div class="flex flex-col gap-3 rounded-xl border bg-card p-4">
            <div class="flex gap-2">
                <Input
                    bind:value={searchQuery}
                    placeholder="Cari nama, kode, atau alamat toko..."
                />
                <Button type="button" variant="secondary" onclick={loadOutlets}>
                    Cari
                </Button>
            </div>

            {#if recentOutlets.length > 0}
                <div>
                    <p class="mb-1 text-xs font-medium text-muted-foreground">
                        Recently Visited Outlets
                    </p>
                    <div class="flex flex-wrap gap-1.5">
                        {#each recentOutlets as outlet (outlet.id)}
                            <Button
                                type="button"
                                size="sm"
                                variant={selectedOutlet?.id === outlet.id
                                    ? 'default'
                                    : 'outline'}
                                onclick={() => {
                                    selectedOutlet = outlet;
                                }}
                            >
                                {outlet.name}
                            </Button>
                        {/each}
                    </div>
                </div>
            {/if}

            <div class="max-h-48 space-y-1.5 overflow-y-auto">
                {#each searchResults as outlet (outlet.id)}
                    <button
                        type="button"
                        class="w-full rounded-md border p-2 text-left text-sm {selectedOutlet?.id ===
                        outlet.id
                            ? 'border-primary bg-primary/5'
                            : ''}"
                        onclick={() => {
                            selectedOutlet = outlet;
                        }}
                    >
                        <div class="flex justify-between font-medium">
                            <span>{outlet.name}</span>
                            {#if outlet.distance_m !== null}
                                <span class="text-xs text-muted-foreground">
                                    {Math.round(outlet.distance_m)}m
                                </span>
                            {/if}
                        </div>
                        <p class="text-xs text-muted-foreground">
                            {outlet.address}
                        </p>
                    </button>
                {/each}
            </div>

            {#if selectedOutlet}
                <LiveCameraCapture
                    facingMode="user"
                    disabled={submitting}
                    onCapture={(payload) => {
                        capturedPhoto = payload;
                    }}
                />

                <textarea
                    bind:value={notes}
                    rows="2"
                    placeholder="Alasan Visit In (wajib jika toko NOO, luar radius, atau akurasi buruk)"
                    class="w-full rounded-md border bg-background p-2 text-sm"
                ></textarea>

                <Button
                    type="button"
                    class="min-h-12 w-full"
                    disabled={submitting}
                    onclick={submitVisitIn}
                >
                    Visit In ke {selectedOutlet.name}
                </Button>
            {/if}
        </div>
    {/if}

    {#if feedback}
        <p
            class="rounded-md p-3 text-sm {feedback.type === 'error'
                ? 'bg-destructive/10 text-destructive'
                : 'bg-green-50 text-green-800'}"
        >
            {feedback.message}
        </p>
    {/if}
</div>
