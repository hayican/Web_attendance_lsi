<script lang="ts">
    import { onDestroy, onMount } from 'svelte';
    import { Button } from '@/components/ui/button';
    import { readXsrfToken } from '@/lib/gpsClient';
    import { compressCanvasToJpeg } from '@/lib/imageCompressor';

    let {
        facingMode = 'user',
        disabled = false,
        autoStart = false,
        onCapture,
    }: {
        facingMode?: 'user' | 'environment';
        disabled?: boolean;
        autoStart?: boolean;
        onCapture: (payload: { blob: Blob; cameraToken: string }) => void;
    } = $props();

    let videoEl = $state<HTMLVideoElement | null>(null);
    let stream = $state<MediaStream | null>(null);
    let cameraToken = $state<string | null>(null);
    let errorMessage = $state<string | null>(null);
    let busy = $state(false);
    let previewUrl = $state<string | null>(null);
    let capturedSizeKb = $state<number | null>(null);
    let destroyed = false;

    async function startCamera() {
        errorMessage = null;
        busy = true;

        try {
            if (!navigator.mediaDevices?.getUserMedia) {
                throw new Error(
                    'Browser tidak mendukung akses kamera langsung.',
                );
            }

            const tokenRes = await fetch('/api/tokens/camera', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'X-XSRF-TOKEN': readXsrfToken(),
                },
            });

            if (!tokenRes.ok) {
                throw new Error('Gagal meminta sesi kamera dari server.');
            }

            const tokenData = (await tokenRes.json()) as { token: string };
            cameraToken = tokenData.token;

            const media = await navigator.mediaDevices.getUserMedia({
                video: { facingMode },
                audio: false,
            });

            if (destroyed) {
                media.getTracks().forEach((track) => track.stop());
                return;
            }

            stream = media;
        } catch (err) {
            errorMessage =
                err instanceof Error
                    ? err.message
                    : 'Izin kamera ditolak atau kamera tidak tersedia.';
            stopStream();
        } finally {
            busy = false;
        }
    }

    async function captureFrame() {
        if (!videoEl || !cameraToken) {
            return;
        }

        busy = true;
        errorMessage = null;

        try {
            const canvas = document.createElement('canvas');
            canvas.width = videoEl.videoWidth || 640;
            canvas.height = videoEl.videoHeight || 480;

            const ctx = canvas.getContext('2d');
            if (!ctx) {
                throw new Error('Gagal membaca frame kamera.');
            }

            ctx.drawImage(videoEl, 0, 0, canvas.width, canvas.height);
            const blob = await compressCanvasToJpeg(canvas);

            if (previewUrl) {
                URL.revokeObjectURL(previewUrl);
            }
            previewUrl = URL.createObjectURL(blob);
            capturedSizeKb = Math.max(1, Math.round(blob.size / 1024));
            const tokenToEmit = cameraToken;
            stopStream();
            onCapture({ blob, cameraToken: tokenToEmit });
        } catch (err) {
            errorMessage =
                err instanceof Error ? err.message : 'Gagal mengambil foto.';
        } finally {
            busy = false;
        }
    }

    function stopStream() {
        if (stream) {
            for (const track of stream.getTracks()) {
                track.stop();
            }
            stream = null;
        }
    }

    $effect(() => {
        if (videoEl && stream) {
            videoEl.srcObject = stream;
            void videoEl.play().catch(() => {});
        }
    });

    onMount(() => {
        if (autoStart && !disabled) {
            void startCamera();
        }
    });

    onDestroy(() => {
        destroyed = true;
        stopStream();
        if (previewUrl) {
            URL.revokeObjectURL(previewUrl);
        }
    });
</script>

<div class="flex flex-col gap-3">
    {#if errorMessage}
        <p
            class="rounded-md border border-destructive/40 bg-destructive/10 p-3 text-sm text-destructive"
        >
            {errorMessage}
        </p>
    {/if}

    {#if stream}
        <div class="overflow-hidden rounded-lg border bg-black">
            <video
                bind:this={videoEl}
                autoplay
                playsinline
                muted
                class="aspect-3/4 max-h-72 w-full object-cover"
            ></video>
        </div>

        <Button
            type="button"
            class="min-h-12 w-full"
            disabled={busy || disabled}
            onclick={captureFrame}
        >
            Ambil Foto Selfie
        </Button>
    {:else}
        {#if previewUrl}
            <div class="space-y-1">
                <img
                    src={previewUrl}
                    alt="Hasil foto kamera"
                    class="aspect-3/4 max-h-72 w-full rounded-lg border object-cover"
                />
                {#if capturedSizeKb !== null}
                    <p
                        class="text-right font-mono text-[11px] tabular-nums {capturedSizeKb < 300
                            ? 'text-green-700'
                            : 'text-yellow-800'}"
                    >
                        Ukuran berkas: {capturedSizeKb} KB (&lt; 300 KB)
                    </p>
                {/if}
            </div>
        {/if}

        <Button
            type="button"
            variant="secondary"
            class="min-h-12 w-full"
            disabled={busy || disabled}
            onclick={startCamera}
        >
            {previewUrl ? 'Ulangi Foto Kamera' : 'Buka Kamera Langsung'}
        </Button>
    {/if}
</div>
