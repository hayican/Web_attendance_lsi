<script lang="ts">
    import { Link, router } from '@inertiajs/svelte';
    import ArrowLeft from '@lucide/svelte/icons/arrow-left';
    import Bell from '@lucide/svelte/icons/bell';
    import CalendarCheck from '@lucide/svelte/icons/calendar-check';
    import Clock from '@lucide/svelte/icons/clock';
    import MapPin from '@lucide/svelte/icons/map-pin';
    import User from '@lucide/svelte/icons/user';
    import { onMount, type Component, type Snippet } from 'svelte';
    import { Sheet, SheetContent } from '@/components/ui/sheet';
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

    type AppNotification = {
        id: string;
        title: string;
        message: string;
        read: boolean;
        url: string | null;
        created_at: string | null;
    };

    let {
        office = null,
        serverTimeIso = null,
        auth,
        notifications = [],
        unreadNotifications = 0,
        children,
    }: {
        office?: ShellOffice | null;
        serverTimeIso?: string | null;
        auth?: { user?: { role?: string } };
        notifications?: AppNotification[];
        unreadNotifications?: number;
        children?: Snippet;
    } = $props();

    let notificationSheetOpen = $state(false);

    function openNotifications() {
        notificationSheetOpen = true;

        if (unreadNotifications > 0) {
            router.post('/notifications/read-all', {}, { preserveScroll: true });
        }
    }

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

            <div class="flex items-center gap-1">
                <button
                    type="button"
                    class="relative flex min-h-11 min-w-11 items-center justify-center rounded-md text-stone-600 hover:bg-stone-100"
                    aria-label="Notifikasi"
                    onclick={openNotifications}
                >
                    <Bell class="size-4" />
                    {#if unreadNotifications > 0}
                        <span
                            class="absolute top-1.5 right-1.5 flex size-4 items-center justify-center rounded-full bg-red-600 text-[9px] font-semibold text-white"
                        >
                            {unreadNotifications > 9 ? '9+' : unreadNotifications}
                        </span>
                    {/if}
                </button>
                {#if clockText}
                    <p
                        class="font-mono text-sm font-semibold tabular-nums text-stone-900"
                        aria-label="Jam server"
                    >
                        {clockText}
                    </p>
                {/if}
            </div>
        </div>
    </header>

    <Sheet bind:open={notificationSheetOpen}>
        <SheetContent
            side="bottom"
            class="max-h-[80dvh] overflow-y-auto rounded-t-xl p-4 pb-[calc(1rem_+_env(safe-area-inset-bottom))]"
        >
            <h2 class="text-base font-semibold">Notifikasi</h2>
            {#if notifications.length === 0}
                <p class="text-sm text-stone-500">Belum ada notifikasi.</p>
            {:else}
                <div class="flex flex-col gap-2">
                    {#each notifications as item (item.id)}
                        {#if item.url}
                            <a
                                href={item.url}
                                class="rounded-md border border-stone-200 p-2 text-xs hover:bg-stone-50"
                            >
                                <p class="text-sm font-medium text-stone-900">
                                    {item.title}
                                </p>
                                <p class="text-stone-600">{item.message}</p>
                            </a>
                        {:else}
                            <div class="rounded-md border border-stone-200 p-2 text-xs">
                                <p class="text-sm font-medium text-stone-900">
                                    {item.title}
                                </p>
                                <p class="text-stone-600">{item.message}</p>
                            </div>
                        {/if}
                    {/each}
                </div>
            {/if}
        </SheetContent>
    </Sheet>

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
