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
            color: '#6F3E1D',
            fillOpacity: 0.15,
        }).addTo(map);

        L.circleMarker([targetLat, targetLng], {
            radius: 6,
            color: '#6F3E1D',
        })
            .bindPopup(label)
            .addTo(map);

        if (userLat !== null && userLng !== null) {
            L.circleMarker([userLat, userLng], {
                radius: 6,
                color: '#1D4ED8',
            })
                .bindPopup('Posisi User')
                .addTo(map);

            if (accuracyM !== null && accuracyM > 0) {
                L.circle([userLat, userLng], {
                    radius: accuracyM,
                    color: '#1D4ED8',
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
    class="h-72 w-full rounded-md border border-stone-200"
    aria-label="Peta verifikasi lokasi"
></div>
