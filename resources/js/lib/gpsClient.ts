export type GpsReadStatus =
    | 'OK'
    | 'POOR_ACCURACY'
    | 'PERMISSION_DENIED'
    | 'GPS_UNAVAILABLE';

export type FreshGpsResult = {
    status: GpsReadStatus;
    lat: number | null;
    lng: number | null;
    accuracyM: number | null;
    gpsSessionToken: string | null;
    issuedAt: number | null;
    message: string | null;
};

export function readXsrfToken(): string {
    if (typeof document === 'undefined') {
        return '';
    }

    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);
    return match ? decodeURIComponent(match[1]) : '';
}

export function haversineDistanceMeters(
    lat1: number,
    lng1: number,
    lat2: number,
    lng2: number,
): number {
    const earthRadiusM = 6371000;
    const dLat = ((lat2 - lat1) * Math.PI) / 180;
    const dLng = ((lng2 - lng1) * Math.PI) / 180;
    const a =
        Math.sin(dLat / 2) ** 2 +
        Math.cos((lat1 * Math.PI) / 180) *
            Math.cos((lat2 * Math.PI) / 180) *
            Math.sin(dLng / 2) ** 2;
    const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(Math.max(0, 1 - a)));

    return Math.round(earthRadiusM * c * 100) / 100;
}

export async function issueGpsSessionToken(): Promise<{
    token: string;
    issued_at: number;
    expires_at: number;
}> {
    const response = await fetch('/api/tokens/gps', {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'X-XSRF-TOKEN': readXsrfToken(),
        },
    });

    if (!response.ok) {
        throw new Error('Gagal menerbitkan token sesi GPS dari server.');
    }

    return (await response.json()) as {
        token: string;
        issued_at: number;
        expires_at: number;
    };
}

export async function requestFreshGps(
    radiusM: number,
): Promise<FreshGpsResult> {
    if (typeof navigator === 'undefined' || !navigator.geolocation) {
        return {
            status: 'GPS_UNAVAILABLE',
            lat: null,
            lng: null,
            accuracyM: null,
            gpsSessionToken: null,
            issuedAt: null,
            message: 'Perangkat tidak mendukung fitur lokasi GPS.',
        };
    }

    try {
        const position = await new Promise<GeolocationPosition>(
            (resolve, reject) => {
                navigator.geolocation.getCurrentPosition(resolve, reject, {
                    enableHighAccuracy: true,
                    maximumAge: 0,
                    timeout: 15000,
                });
            },
        );

        const tokenPayload = await issueGpsSessionToken();
        const accuracyM = Math.round(position.coords.accuracy * 100) / 100;
        const isPoor = accuracyM > 100 || accuracyM > radiusM;

        return {
            status: isPoor ? 'POOR_ACCURACY' : 'OK',
            lat: position.coords.latitude,
            lng: position.coords.longitude,
            accuracyM,
            gpsSessionToken: tokenPayload.token,
            issuedAt: tokenPayload.issued_at,
            message: isPoor
                ? `Sinyal GPS kurang akurat (+/-${Math.round(accuracyM)}m). Anda dapat menekan Muat Ulang GPS atau tetap melanjutkan dengan mengisi alasan.`
                : null,
        };
    } catch (error) {
        const geoError = error as GeolocationPositionError | undefined;
        if (geoError && geoError.code === 1) {
            return {
                status: 'PERMISSION_DENIED',
                lat: null,
                lng: null,
                accuracyM: null,
                gpsSessionToken: null,
                issuedAt: null,
                message:
                    'Izin lokasi GPS ditolak. Aktifkan izin lokasi di pengaturan browser untuk melanjutkan absen.',
            };
        }

        return {
            status: 'GPS_UNAVAILABLE',
            lat: null,
            lng: null,
            accuracyM: null,
            gpsSessionToken: null,
            issuedAt: null,
            message:
                'Gagal mendapatkan lokasi GPS. Pastikan GPS aktif lalu tekan Muat Ulang GPS.',
        };
    }
}
