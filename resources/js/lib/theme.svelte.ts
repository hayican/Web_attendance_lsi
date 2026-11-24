import type { Appearance, ResolvedAppearance } from '@/types';

export type { Appearance, ResolvedAppearance };

export type ThemeState = {
    appearance: {
        value: Appearance;
    };
    resolvedAppearance: () => ResolvedAppearance;
    updateAppearance: (value: Appearance) => void;
};

const appearance = $state<{ value: Appearance }>({ value: 'light' });

let themeChangeMediaQuery: MediaQueryList | null = null;

const isDarkMode = (): boolean => false;

const getResolvedAppearance = (): ResolvedAppearance => {
    return 'light';
};

const setCookie = (name: string, value: string, days = 365): void => {
    if (typeof document === 'undefined') {
        return;
    }

    const maxAge = days * 24 * 60 * 60;
    document.cookie = `${name}=${value};path=/;max-age=${maxAge};SameSite=Lax`;
};

const applyTheme = (): void => {
    if (typeof document === 'undefined') {
        return;
    }

    document.documentElement.classList.remove('dark');
    document.documentElement.style.colorScheme = 'light';
};

const getStoredAppearance = (): Appearance => {
    return 'light';
};

const handleSystemThemeChange = (): void => {
    applyTheme();
};

const detachThemeChangeListener = (): void => {
    if (!themeChangeMediaQuery) {
        return;
    }

    themeChangeMediaQuery.removeEventListener(
        'change',
        handleSystemThemeChange,
    );
    themeChangeMediaQuery = null;
};

export function initializeTheme(): () => void {
    if (typeof window === 'undefined') {
        return () => {};
    }

    localStorage.setItem('appearance', 'light');
    setCookie('appearance', 'light');

    appearance.value = getStoredAppearance();
    applyTheme();
    detachThemeChangeListener();

    return detachThemeChangeListener;
}

export function updateAppearance(value: Appearance): void {
    void value;
    void isDarkMode;
    appearance.value = 'light';

    if (typeof window !== 'undefined') {
        localStorage.setItem('appearance', 'light');
    }

    setCookie('appearance', 'light');
    applyTheme();
}

export function themeState(): ThemeState {
    return {
        appearance,
        resolvedAppearance: getResolvedAppearance,
        updateAppearance,
    };
}
