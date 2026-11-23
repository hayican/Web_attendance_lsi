const MAX_BYTES = 300 * 1024;
const MAX_DIMENSION = 1280;

export async function compressCanvasToJpeg(
    canvas: HTMLCanvasElement,
): Promise<Blob> {
    const scale = Math.min(
        1,
        MAX_DIMENSION / Math.max(canvas.width, canvas.height),
    );

    const target = document.createElement('canvas');
    target.width = Math.max(1, Math.round(canvas.width * scale));
    target.height = Math.max(1, Math.round(canvas.height * scale));

    const ctx = target.getContext('2d');
    if (!ctx) {
        throw new Error('Gagal memproses kanvas kamera.');
    }

    ctx.drawImage(canvas, 0, 0, target.width, target.height);

    for (const quality of [0.82, 0.7, 0.55, 0.42, 0.3]) {
        const blob = await new Promise<Blob | null>((resolve) =>
            target.toBlob(resolve, 'image/jpeg', quality),
        );

        if (blob && blob.size <= MAX_BYTES) {
            return blob;
        }

        if (blob && quality === 0.3) {
            return blob;
        }
    }

    throw new Error('Gagal mengompresi foto kamera.');
}
