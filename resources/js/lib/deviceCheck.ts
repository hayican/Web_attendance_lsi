export function isInAppBrowser(userAgent?: string): boolean {
    const ua =
        userAgent ??
        (typeof navigator !== 'undefined' ? navigator.userAgent : '');

    if (!ua) {
        return false;
    }

    return /(WhatsApp|Line\/|Instagram|FBAN|FBAV|BytedanceWebview|TikTok|MicroMessenger)/i.test(
        ua,
    );
}

export function recommendedExternalBrowserInstruction(
    userAgent?: string,
): string {
    const ua =
        userAgent ??
        (typeof navigator !== 'undefined' ? navigator.userAgent : '');

    if (/iPhone|iPad|iPod/i.test(ua)) {
        return 'Buka tautan ini di browser Safari (ketuk ikon bagikan lalu pilih Open in Safari) agar kamera dan GPS dapat berjalan.';
    }

    return 'Buka tautan ini di browser Google Chrome (ketuk menu titik tiga lalu pilih Buka di Chrome) agar kamera dan GPS dapat berjalan.';
}
