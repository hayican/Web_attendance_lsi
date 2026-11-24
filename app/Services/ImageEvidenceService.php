<?php

namespace App\Services;

use Carbon\CarbonInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\Format;
use Intervention\Image\ImageManager;
use Intervention\Image\Typography\FontFactory;
use Throwable;

class ImageEvidenceService
{
    public const int MAX_IMAGE_BYTES = 2 * 1024 * 1024;

    public const int SIGNED_URL_MINUTES = 10;

    /**
     * Store a live-camera photo on private local storage with server-side watermark.
     *
     * @throws ValidationException
     */
    public function storeWatermarkedPhoto(
        UploadedFile $file,
        int $userId,
        string $nik,
        string $locationLabel,
        float $lat,
        float $lng,
        float $accuracyM,
        CarbonInterface $serverTime,
        string $timezone,
    ): string {
        $this->assertValidImage($file);

        $realPath = $file->getRealPath();
        if ($realPath === false) {
            throw ValidationException::withMessages([
                'photo' => ['Berkas foto tidak valid atau rusak.'],
            ]);
        }

        try {
            $image = ImageManager::usingDriver(GdDriver::class)->decodePath($realPath);
        } catch (Throwable) {
            throw ValidationException::withMessages([
                'photo' => ['Berkas foto tidak valid atau rusak.'],
            ]);
        }

        if ($image->width() > 1280) {
            $image->scale(width: 1280);
        }

        $localTime = $serverTime->copy()->setTimezone($timezone);
        $lines = [
            sprintf('%s | %s', $nik, $localTime->format('d-m-Y H:i:s T')),
            sprintf('%s | GPS: %.6f, %.6f (+/-%.0fm)', Str::limit($locationLabel, 28, ''), $lat, $lng, $accuracyM),
        ];

        $height = $image->height();
        $yBase = max(24, $height - 34);

        foreach ($lines as $idx => $text) {
            $y = $yBase + ($idx * 16);
            $image->text($text, 12, $y, function (FontFactory $font): void {
                $font->size(13);
                $font->color('#ffffff');
                $font->stroke('#000000', 2);
            });
        }

        $encoded = $image->encodeUsingFormat(Format::JPEG, quality: 80);
        $relativePath = sprintf(
            'evidence/%d/photos/%s/%s.jpg',
            $userId,
            $localTime->format('Y/m'),
            (string) Str::uuid()
        );

        Storage::disk('local')->put($relativePath, (string) $encoded);

        return $relativePath;
    }

    /**
     * Store an SKD / leave attachment (JPG, PNG, or PDF <= 2MB) on private storage.
     *
     * @throws ValidationException
     */
    public function storeLeaveAttachment(UploadedFile $file, int $userId): string
    {
        if ($file->getSize() > self::MAX_IMAGE_BYTES) {
            throw ValidationException::withMessages([
                'attachment' => ['Ukuran lampiran maksimal 2 MB.'],
            ]);
        }

        $mime = $this->detectRealMime($file);
        $extension = match ($mime) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'application/pdf' => 'pdf',
            default => throw ValidationException::withMessages([
                'attachment' => ['Lampiran wajib berupa file asli JPG, PNG, atau PDF.'],
            ]),
        };

        $relativePath = sprintf(
            'evidence/%d/leave/%s/%s.%s',
            $userId,
            now()->format('Y/m'),
            (string) Str::uuid(),
            $extension
        );

        Storage::disk('local')->putFileAs(
            dirname($relativePath),
            $file,
            basename($relativePath)
        );

        return $relativePath;
    }

    /**
     * Store a user's reference profile photo on private local storage.
     *
     * @throws ValidationException
     */
    public function storeReferencePhoto(UploadedFile $file, int $userId): string
    {
        $this->assertValidImage($file);

        $realPath = $file->getRealPath();
        if ($realPath === false) {
            throw ValidationException::withMessages([
                'photo' => ['Berkas foto tidak valid atau rusak.'],
            ]);
        }

        try {
            $image = ImageManager::usingDriver(GdDriver::class)->decodePath($realPath);
        } catch (Throwable) {
            throw ValidationException::withMessages([
                'photo' => ['Berkas foto tidak valid atau rusak.'],
            ]);
        }

        if ($image->width() > 800) {
            $image->scale(width: 800);
        }

        $relativePath = sprintf('evidence/%d/reference/%s.jpg', $userId, (string) Str::uuid());
        Storage::disk('local')->put($relativePath, (string) $image->encodeUsingFormat(Format::JPEG, quality: 85));

        return $relativePath;
    }

    public function signedUrl(string $relativePath, int $minutes = self::SIGNED_URL_MINUTES): string
    {
        return URL::temporarySignedRoute(
            'evidence.show',
            now()->addMinutes($minutes),
            ['path' => $relativePath]
        );
    }

    /**
     * @throws ValidationException
     */
    private function assertValidImage(UploadedFile $file): void
    {
        if ($file->getSize() > self::MAX_IMAGE_BYTES) {
            throw ValidationException::withMessages([
                'photo' => ['Ukuran foto terlalu besar (maksimal 2 MB).'],
            ]);
        }

        $mime = $this->detectRealMime($file);

        if (! in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            throw ValidationException::withMessages([
                'photo' => ['Format foto wajib gambar asli (JPG, PNG, atau WebP).'],
            ]);
        }
    }

    private function detectRealMime(UploadedFile $file): string
    {
        $realPath = $file->getRealPath();
        if ($realPath === false) {
            return '';
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);

        return (string) $finfo->file($realPath);
    }
}
