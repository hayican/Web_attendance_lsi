<script lang="ts">
    import { Link } from '@inertiajs/svelte';
    import CalendarCheck from '@lucide/svelte/icons/calendar-check';
    import ClipboardList from '@lucide/svelte/icons/clipboard-list';
    import Clock from '@lucide/svelte/icons/clock';
    import Database from '@lucide/svelte/icons/database';
    import FileCheck from '@lucide/svelte/icons/file-check';
    import History from '@lucide/svelte/icons/history';
    import LayoutDashboard from '@lucide/svelte/icons/layout-dashboard';
    import MapPin from '@lucide/svelte/icons/map-pin';
    import type { Snippet } from 'svelte';
    import AppLogo from '@/components/AppLogo.svelte';
    import NavUser from '@/components/NavUser.svelte';
    import {
        Sidebar,
        SidebarContent,
        SidebarFooter,
        SidebarGroup,
        SidebarGroupLabel,
        SidebarHeader,
        SidebarMenu,
        SidebarMenuButton,
        SidebarMenuItem,
    } from '@/components/ui/sidebar';
    import { currentUrlState } from '@/lib/currentUrl.svelte';
    import { toUrl } from '@/lib/utils';
    import { dashboard } from '@/routes';

    let { children }: { children?: Snippet } = $props();

    const url = currentUrlState();

    const sections = [
        {
            title: 'Monitoring',
            items: [
                { title: 'Dashboard Rekap', href: '/admin/dashboard', icon: LayoutDashboard },
                { title: 'Persetujuan Cuti/Izin', href: '/admin/leaves', icon: CalendarCheck },
            ],
        },
        {
            title: 'Master Data',
            items: [
                { title: 'Data Master & Toko', href: '/admin/master-data', icon: Database },
                { title: 'Mapping Talenta', href: '/admin/talenta-mapping', icon: FileCheck },
            ],
        },
        {
            title: 'Pribadi & Audit',
            items: [
                { title: 'Absen Saya', href: '/admin/absen-saya', icon: MapPin },
                { title: 'Riwayat Absensi', href: '/attendance/history', icon: Clock },
                { title: 'Pengajuan Saya', href: '/admin/pengajuan-saya', icon: ClipboardList },
                { title: 'Riwayat Audit', href: '/admin/audit-logs', icon: History },
            ],
        },
    ];
</script>

<Sidebar collapsible="icon" variant="inset">
    <SidebarHeader class="border-b border-stone-200 bg-[#FDFCFA] text-stone-900">
        <SidebarMenu>
            <SidebarMenuItem>
                <SidebarMenuButton size="lg" asChild class="hover:bg-stone-100 text-stone-900">
                    {#snippet children(props)}
                        <Link {...props} href={toUrl(dashboard())} class={props.class}>
                            <AppLogo />
                        </Link>
                    {/snippet}
                </SidebarMenuButton>
            </SidebarMenuItem>
        </SidebarMenu>
    </SidebarHeader>

    <SidebarContent class="space-y-1 p-2">
        {#each sections as section (section.title)}
            <SidebarGroup class="px-2 py-0">
                <SidebarGroupLabel class="text-[11px] font-medium uppercase tracking-wide text-stone-500">
                    {section.title}
                </SidebarGroupLabel>
                <SidebarMenu>
                    {#each section.items as item (toUrl(item.href))}
                        <SidebarMenuItem>
                            <SidebarMenuButton
                                asChild
                                isActive={url.isCurrentUrl(item.href, url.currentUrl)}
                                tooltip={item.title}
                                class="data-[active=true]:bg-[#F5EDE6] data-[active=true]:text-[#6F3E1D] data-[active=true]:font-medium"
                            >
                                {#snippet children(props)}
                                    <Link {...props} href={toUrl(item.href)} class={props.class}>
                                        <item.icon class="size-4 shrink-0" />
                                        <span>{item.title}</span>
                                    </Link>
                                {/snippet}
                            </SidebarMenuButton>
                        </SidebarMenuItem>
                    {/each}
                </SidebarMenu>
            </SidebarGroup>
        {/each}
    </SidebarContent>

    <SidebarFooter>
        <NavUser />
    </SidebarFooter>
</Sidebar>
{@render children?.()}
