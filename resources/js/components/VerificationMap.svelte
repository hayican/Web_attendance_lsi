<script lang="ts">
    import { onDestroy, onMount } from 'svelte';
    import L from 'leaflet';
    import 'leaflet/dist/leaflet.css';

    let {
        targetLat,
        targetLng,
        radiusM,
        userLat = null,
        userLng = null,
        accuracyM = null,
        label = 'Lokasi',
    }: {
        targetLat: number;
        targetLng: number;
        radiusM: number;
        userLat?: number | null;
        userLng?: number | null;
        accuracyM?: number | null;
        label?: string;
    } = $props();

    let container = $state<HTMLDivElement | null>(null);
    let map: L.Map | null = null;

    onMount(() => {
        if (!container) {
            return;
        }

        map = L.map(container).setView([targetLat, targetLng], 17);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '&copy; OpenStreetMap',
        }).addTo(map);

        L.circle([targetLat, targetLng], {
            radius: radiusM,
            color: '#2563eb',
            fillOpacity: 0.15,
        }).addTo(map);

        L.circleMarker([targetLat, targetLng], {
            radius: 6,
            color: '#1d4ed8',
        })
            .bindPopup(label)
            .addTo(map);

        if (userLat !== null && userLng !== null) {
            L.circleMarker([userLat, userLng], {
                radius: 6,
                color: '#dc2626',
            })
                .bindPopup('Posisi User')
                .addTo(map);

            if (accuracyM !== null && accuracyM > 0) {
                L.circle([userLat, userLng], {
                    radius: accuracyM,
                    color: '#dc2626',
                    dashArray: '4 4',
                    fillOpacity: 0.08,
                }).addTo(map);
            }
        }
    });

    onDestroy(() => {
        map?.remove();
        map = null;
    });
</script>

<div
    bind:this={container}
    class="h-56 w-full rounded-lg border"
    aria-label="Peta verifikasi lokasi"
></div>
