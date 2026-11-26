<script lang="ts">
    import { Link } from '@inertiajs/svelte';
    import ArrowLeft from '@lucide/svelte/icons/arrow-left';
    import CalendarCheck from '@lucide/svelte/icons/calendar-check';
    import Clock from '@lucide/svelte/icons/clock';
    import MapPin from '@lucide/svelte/icons/map-pin';
    import User from '@lucide/svelte/icons/user';
    import { onMount, type Component, type Snippet } from 'svelte';
    import { Toaster } from '@/components/ui/sonner';
    import { currentUrlState } from '@/lib/currentUrl.svelte';
    import { toUrl } from '@/lib/utils';
    import {
        history,
        office as attendanceOffice,
        sales as attendanceSales,
    } from '@/routes/attendance';
    import { index as leaveIndex } from '@/routes/leave';
    import { edit as profileEdit } from '@/routes/profile';

    type ShellOffice = {
        name: string;
        timezone: string;
    };

    type NavItem = {
        label: string;
        href: string;
        icon: Component;
    };

    let {
        office = null,
        serverTimeIso = null,
        auth,
        children,
    }: {
        office?: ShellOffice | null;
        serverTimeIso?: string | null;
        auth?: { user?: { role?: string } };
        children?: Snippet;
    } = $props();

    const url = currentUrlState();
    const role = $derived(auth?.user?.role ?? 'EMPLOYEE_OFFICE');
    const isHrAdmin = $derived(role === 'HR_ADMIN');
    const isSales = $derived(role === 'EMPLOYEE_SALES');
    const timezoneLabel = $derived(
        office?.timezone === 'Asia/Makassar' ? 'WITA' : 'WIB',
    );

    const navItems = $derived.by<NavItem[]>(() => {
        const primary: NavItem = isSales
            ? {
                  label: 'Kunjungan',
                  href: toUrl(attendanceSales()),
                  icon: MapPin,
              }
            : {
                  label: 'Absen',
                  href: toUrl(attendanceOffice()),
                  icon: MapPin,
              };

        return [
            primary,
            {
                label: isSales ? 'Cuti & Izin' : 'Pengajuan',
                href: toUrl(leaveIndex()),
                icon: CalendarCheck,
            },
            {
                label: 'Riwayat',
                href: toUrl(history()),
                icon: Clock,
            },
            {
                label: 'Profil',
                href: toUrl(profileEdit()),
                icon: User,
            },
        ];
    });

    let clockTickMs = $state(0);
    let clockOffsetMs = 0;
    let timer: ReturnType<typeof setInterval> | null = null;

    const nowMs = $derived(
        clockTickMs !== 0
            ? clockTickMs
            : serverTimeIso !== null
              ? Date.parse(serverTimeIso)
              : Date.now(),
    );

    onMount(() => {
        clockOffsetMs =
            (serverTimeIso !== null ? Date.parse(serverTimeIso) : Date.now()) -
            Date.now();
        clockTickMs = Date.now() + clockOffsetMs;
        timer = setInterval(() => {
            clockTickMs = Date.now() + clockOffsetMs;
        }, 1000);

        return () => {
            if (timer) {
                clearInterval(timer);
            }
        };
    });

    const clockText = $derived.by(() => {
        if (!office?.timezone) {
            return '';
        }

        return new Intl.DateTimeFormat('id-ID', {
            timeZone: office.timezone,
            hour: '2-digit',
            minute: '2-digit',
            second: '2-digit',
            hourCycle: 'h23',
        }).format(new Date(nowMs));
    });
</script>

<div class="flex min-h-dvh flex-col bg-background">
    <header
        class="sticky top-0 z-30 h-12 shrink-0 border-b border-stone-200 bg-white/95 backdrop-blur"
    >
        <div
            class="mx-auto flex h-full w-full max-w-lg items-center justify-between gap-2 px-3"
        >
            <div class="flex min-w-0 items-center gap-1.5">
                {#if isHrAdmin}
                    <Link
                        href="/admin/dashboard"
                        class="-ml-1 flex min-h-11 min-w-11 items-center justify-center rounded-md text-stone-600 hover:bg-stone-100"
                        aria-label="Kembali ke dashboard HR"
                    >
                        <ArrowLeft class="size-4" />
                    </Link>
                {/if}
                <div class="flex min-w-0 items-center gap-1.5">
                    <p class="truncate text-xs font-semibold text-stone-900">
                        {office?.name ?? 'Absensi'}
                    </p>
                    <span
                        class="shrink-0 rounded border border-stone-300 bg-stone-100 px-1 py-0.5 text-[9px] font-medium uppercase tracking-wide text-stone-600"
                    >
                        {timezoneLabel}
                    </span>
                </div>
            </div>

            {#if clockText}
                <p
                    class="font-mono text-sm font-semibold tabular-nums text-stone-900"
                    aria-label="Jam server"
                >
                    {clockText}
                </p>
            {/if}
        </div>
    </header>

    <main
        class="mx-auto w-full max-w-lg flex-1 px-3 py-3 pb-[calc(4.5rem_+_env(safe-area-inset-bottom))]"
    >
        {@render children?.()}
    </main>

    <nav
        class="fixed inset-x-0 bottom-0 z-30 border-t border-stone-200 bg-white pb-[env(safe-area-inset-bottom)]"
        aria-label="Navigasi utama"
    >
        <div class="mx-auto grid h-14 w-full max-w-lg grid-cols-4">
            {#each navItems as item (item.href)}
                {@const active = url.isCurrentUrl(item.href, url.currentUrl)}
                <Link
                    href={item.href}
                    class="flex min-h-12 flex-col items-center justify-center gap-0.5 text-[11px] font-medium transition-colors {active
                        ? 'text-primary'
                        : 'text-stone-500'}"
                    aria-current={active ? 'page' : undefined}
                >
                    <item.icon class="size-5" />
                    <span>{item.label}</span>
                </Link>
            {/each}
        </div>
    </nav>

    <Toaster />
</div>
