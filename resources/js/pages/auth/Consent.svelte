<script module lang="ts">
    export const layout = {
        title: 'Persetujuan Data Pribadi (UU PDP)',
        description:
            'Harap setujui penggunaan data lokasi GPS, kamera, dan dokumen medis untuk keperluan absensi PT. LSI.',
    };
</script>

<script lang="ts">
    import { router } from '@inertiajs/svelte';
    import AppHead from '@/components/AppHead.svelte';
    import { Button } from '@/components/ui/button';

    let processing = $state(false);

    function acceptConsent() {
        processing = true;
        router.post(
            '/consent/accept',
            {},
            {
                onFinish: () => {
                    processing = false;
                },
            },
        );
    }

    function declineConsent() {
        processing = true;
        router.post(
            '/consent/decline',
            {},
            {
                onFinish: () => {
                    processing = false;
                },
            },
        );
    }
</script>

<AppHead title="Persetujuan UU PDP" />

<div class="flex flex-col gap-5">
    <div
        class="rounded-lg border bg-muted/40 p-4 text-sm leading-relaxed text-muted-foreground"
    >
        <p class="mb-2 font-medium text-foreground">
            Dengan melanjutkan, Anda menyetujui pemrosesan data berikut oleh PT.
            LSI:
        </p>
        <ul class="list-disc space-y-1.5 pl-5">
            <li>
                Koordinat lokasi GPS saat absensi kantor atau kunjungan toko
                sales.
            </li>
            <li>
                Foto selfie langsung melalui kamera perangkat sebagai bukti
                kehadiran.
            </li>
            <li>
                Lampiran dokumen medis atau Surat Keterangan Dokter (SKD) jika
                mengajukan izin sakit.
            </li>
        </ul>
    </div>

    <div class="flex flex-col gap-3">
        <Button
            type="button"
            class="min-h-12 w-full"
            disabled={processing}
            onclick={acceptConsent}
        >
            Setuju &amp; Lanjutkan
        </Button>

        <Button
            type="button"
            variant="outline"
            class="min-h-12 w-full"
            disabled={processing}
            onclick={declineConsent}
        >
            Tolak &amp; Keluar
        </Button>
    </div>
</div>
