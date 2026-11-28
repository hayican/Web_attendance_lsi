<script module lang="ts">
    export const layout = {
        breadcrumbs: [
            {
                title: 'Data Master & Toko',
                href: '/admin/master-data',
            },
        ],
    };
</script>

<script lang="ts">
    import { page, router } from '@inertiajs/svelte';
    import { SvelteMap } from 'svelte/reactivity';
    import AlertTriangle from '@lucide/svelte/icons/alert-triangle';
    import CalendarDays from '@lucide/svelte/icons/calendar-days';
    import KeyRound from '@lucide/svelte/icons/key-round';
    import MapPin from '@lucide/svelte/icons/map-pin';
    import Pencil from '@lucide/svelte/icons/pencil';
    import Plus from '@lucide/svelte/icons/plus';
    import ShieldCheck from '@lucide/svelte/icons/shield-check';
    import Unlock from '@lucide/svelte/icons/unlock';
    import Upload from '@lucide/svelte/icons/upload';
    import X from '@lucide/svelte/icons/x';
    import AppHead from '@/components/AppHead.svelte';
    import { Button } from '@/components/ui/button';
    import { Input } from '@/components/ui/input';
    import { readXsrfToken, requestFreshGps } from '@/lib/gpsClient';

    type LeaveBalanceItem = {
        year: number;
        base_quota: string | number;
        prorated_deduction: string | number;
        collective_leave_deduction: string | number;
        used_days: string | number;
        pending_days: string | number;
        adjustment_days: string | number;
    };

    type UserItem = {
        id: number;
        nik: string;
        email: string;
        name: string;
        role: 'EMPLOYEE_OFFICE' | 'EMPLOYEE_SALES' | 'HR_ADMIN';
        office_location_id: number;
        default_shift_pattern_id: number | null;
        join_date: string;
        is_active: boolean;
        organization?: string | null;
        job_position?: string | null;
        job_level?: string | null;
        employment_status?: string | null;
        reference_photo_signed_url?: string | null;
        office_location?: { id: number; name: string; timezone?: string } | null;
        default_shift_pattern?: { id: number; name: string } | null;
        leave_balances?: LeaveBalanceItem[];
    };

    type LocationItem = {
        id: number;
        code: string | null;
        name: string;
        type: 'OFFICE' | 'OUTLET';
        address: string;
        latitude: string | number;
        longitude: string | number;
        radius_m: number;
        timezone: string;
        work_days: number[];
        status: 'ACTIVE' | 'PENDING_VERIFICATION' | 'INACTIVE';
        reject_reason?: string | null;
    };

    type ShiftItem = {
        id: number;
        code: string;
        name: string;
        start_time: string;
        end_time: string;
        grace_period_minutes: number;
        auto_close_hours: number;
        is_active: boolean;
    };

    type PatternItem = {
        id: number;
        name: string;
        mon_shift_id: number | null;
        tue_shift_id: number | null;
        wed_shift_id: number | null;
        thu_shift_id: number | null;
        fri_shift_id: number | null;
        sat_shift_id: number | null;
        sun_shift_id: number | null;
        is_active: boolean;
    };

    type HolidayItem = {
        id: number;
        holiday_date: string;
        location_id: number | null;
        description: string;
        holiday_type: 'NATIONAL' | 'LOCAL' | 'COLLECTIVE_LEAVE';
        location?: { id: number; name: string } | null;
    };

    type CategoryItem = {
        id: number;
        name: string;
        exempt_short_duration: boolean;
        is_active: boolean;
    };

    type EligibleGantiHariOrigin = {
        user_id: number;
        source_date: string;
        source_type: 'ATTENDANCE_LOG' | 'SALES_VISIT';
        source_id: number;
        deadline_date: string;
    };

    type UpcomingGantiHariItem = {
        id: number;
        user_id: number;
        work_date: string;
        compensation_source_date: string;
        notes: string | null;
    };

    type MasterDataProps = {
        currentYear: number;
        activeHrCount: number;
        users: UserItem[];
        locations: LocationItem[];
        shifts: ShiftItem[];
        patterns: PatternItem[];
        holidays: HolidayItem[];
        categories: CategoryItem[];
        eligibleGantiHariOrigins?: EligibleGantiHariOrigin[];
        upcomingGantiHari?: UpcomingGantiHariItem[];
    };

    let {
        currentYear,
        activeHrCount,
        users,
        locations,
        shifts,
        patterns,
        holidays,
        categories,
        eligibleGantiHariOrigins = [],
        upcomingGantiHari = [],
    }: MasterDataProps = $props();

    const currentUserId = $derived(page.props.auth.user?.id ?? 0);
    const currentUserNik = $derived(
        (page.props.auth.user as { nik?: string } | undefined)?.nik?.toUpperCase() ?? '',
    );

    type TabKey =
        | 'users'
        | 'shifts'
        | 'offices'
        | 'outlets'
        | 'categories'
        | 'holidays';

    let activeTab = $state<TabKey>('users');
    let busy = $state(false);
    let errorMsg = $state<string | null>(null);
    let successMsg = $state<string | null>(null);

    // Filters
    let userSearch = $state('');
    let userRoleFilter = $state('');
    let userOrgFilter = $state('');
    let outletStatusFilter = $state('');
    let outletSearch = $state('');
    let holidayYearFilter = $state('');

    // Derived lists
    const offices = $derived(locations.filter((l) => l.type === 'OFFICE'));
    const outlets = $derived(locations.filter((l) => l.type === 'OUTLET'));
    const pendingNooCount = $derived(
        outlets.filter((o) => o.status === 'PENDING_VERIFICATION').length,
    );
    const activeOffices = $derived(offices.filter((o) => o.status === 'ACTIVE'));
    const activeShifts = $derived(shifts.filter((s) => s.is_active));
    const activePatterns = $derived(patterns.filter((p) => p.is_active));
    const userOrganizations = $derived(
        users
            .map((u) => u.organization?.trim() ?? '')
            .filter((org, idx, arr) => org !== '' && arr.indexOf(org) === idx)
            .sort(),
    );

    const filteredUsers = $derived(
        users.filter((u) => {
            if (userRoleFilter && u.role !== userRoleFilter) return false;
            if (userOrgFilter && (u.organization?.trim() ?? '') !== userOrgFilter) return false;
            if (userSearch.trim()) {
                const q = userSearch.trim().toLowerCase();
                if (
                    !u.nik.toLowerCase().includes(q) &&
                    !u.name.toLowerCase().includes(q) &&
                    !u.email.toLowerCase().includes(q)
                ) {
                    return false;
                }
            }
            return true;
        }),
    );

    const filteredOutlets = $derived(
        outlets.filter((o) => {
            if (outletStatusFilter && o.status !== outletStatusFilter)
                return false;
            if (outletSearch.trim()) {
                const q = outletSearch.trim().toLowerCase();
                if (
                    !(o.code ?? '').toLowerCase().includes(q) &&
                    !o.name.toLowerCase().includes(q) &&
                    !o.address.toLowerCase().includes(q)
                ) {
                    return false;
                }
            }
            return true;
        }),
    );

    const badgeBase =
        'inline-flex items-center gap-1 rounded px-1.5 py-0.5 text-[11px] font-medium border whitespace-nowrap';

    const holidayYears = $derived(
        Array.from(new Set(holidays.map((h) => h.holiday_date.slice(0, 4)))).sort((a, b) =>
            b.localeCompare(a),
        ),
    );

    const effectiveHolidayYear = $derived(
        holidayYears.includes(holidayYearFilter) ? holidayYearFilter : '',
    );

    const filteredHolidays = $derived(
        effectiveHolidayYear === ''
            ? holidays
            : holidays.filter((h) => h.holiday_date.startsWith(effectiveHolidayYear)),
    );

    function activeBadge(active: boolean): string {
        return active
            ? `${badgeBase} border-green-300 bg-green-50 text-green-700`
            : `${badgeBase} border-stone-300 bg-stone-100 text-stone-700`;
    }

    function locStatusBadge(status: string): string {
        if (status === 'ACTIVE')
            return `${badgeBase} border-green-300 bg-green-50 text-green-700`;
        if (status === 'PENDING_VERIFICATION')
            return `${badgeBase} border-yellow-300 bg-yellow-50 text-yellow-800`;
        return `${badgeBase} border-red-300 bg-red-50 text-red-700`;
    }

    function roleLabel(role: UserItem['role']): string {
        if (role === 'EMPLOYEE_OFFICE') return 'Staf Kantor';
        if (role === 'EMPLOYEE_SALES') return 'Tim Sales';
        return 'HR Admin';
    }

    function locStatusLabel(status: LocationItem['status']): string {
        if (status === 'ACTIVE') return 'Aktif';
        if (status === 'PENDING_VERIFICATION') return 'Menunggu verifikasi';
        return 'Nonaktif';
    }

    function holidayTypeBadge(type: HolidayItem['holiday_type']): string {
        if (type === 'COLLECTIVE_LEAVE')
            return `${badgeBase} border-violet-300 bg-violet-50 text-violet-700`;
        if (type === 'LOCAL')
            return `${badgeBase} border-amber-300 bg-amber-50 text-amber-800`;
        return `${badgeBase} border-sky-300 bg-sky-50 text-sky-700`;
    }

    function holidayTypeLabel(type: HolidayItem['holiday_type']): string {
        if (type === 'COLLECTIVE_LEAVE') return 'Cuti Bersama';
        if (type === 'LOCAL') return 'Lokal';
        return 'Nasional';
    }

    function holidaySuccessText(base: string) {
        return (data: JsonResponseData): string => {
            const count = data.recalculated_leave_request_ids?.length ?? 0;
            return count > 0 ? `${base} ${count} pengajuan cuti/izin direkalkulasi.` : base;
        };
    }

    function shiftLabel(shiftId: number | null): string {
        if (!shiftId) return 'LIBUR';
        return shifts.find((s) => s.id === shiftId)?.code ?? `#${shiftId}`;
    }

    function availableLeave(u: UserItem): string {
        const b = u.leave_balances?.[0];
        if (!b) return '-';
        const avail =
            Number(b.base_quota) -
            Number(b.prorated_deduction) -
            Number(b.collective_leave_deduction) -
            Number(b.used_days) -
            Number(b.pending_days) +
            Number(b.adjustment_days);
        return `${avail}`;
    }

    function extractError(
        data: { message?: string; errors?: Record<string, string[]> },
        fallback: string,
    ): string {
        if (data.errors) {
            const first = Object.values(data.errors).flat()[0];
            if (first) return first;
        }
        return data.message || fallback;
    }

    type JsonResponseData = {
        message?: string;
        errors?: Record<string, string[]>;
        recalculated_leave_request_ids?: number[];
    };

    async function sendJson(
        url: string,
        method: 'POST' | 'PATCH' | 'DELETE',
        payload: unknown,
        okText: string | ((data: JsonResponseData) => string),
    ): Promise<boolean> {
        busy = true;
        errorMsg = null;
        successMsg = null;
        try {
            const res = await fetch(url, {
                method,
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-XSRF-TOKEN': readXsrfToken(),
                },
                body: JSON.stringify(payload),
            });
            const data = (await res.json().catch(() => ({}))) as JsonResponseData;
            if (!res.ok) {
                errorMsg = extractError(data, 'Operasi gagal diproses.');
                return false;
            }
            successMsg = typeof okText === 'function' ? okText(data) : okText;
            router.reload();
            return true;
        } finally {
            busy = false;
        }
    }

    async function sendForm(
        url: string,
        formData: FormData,
        okText: string,
    ): Promise<boolean> {
        busy = true;
        errorMsg = null;
        successMsg = null;
        try {
            const res = await fetch(url, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'X-XSRF-TOKEN': readXsrfToken(),
                },
                body: formData,
            });
            const data = (await res.json().catch(() => ({}))) as {
                message?: string;
                errors?: Record<string, string[]>;
            };
            if (!res.ok) {
                errorMsg = extractError(data, 'Operasi gagal diproses.');
                return false;
            }
            successMsg = okText;
            router.reload();
            return true;
        } finally {
            busy = false;
        }
    }

    // --- User Modal State ---
    let userModalOpen = $state(false);
    let editingUser = $state<UserItem | null>(null);
    let uNik = $state('');
    let uEmail = $state('');
    let uName = $state('');
    let uPassword = $state('');
    let uRole = $state<'EMPLOYEE_OFFICE' | 'EMPLOYEE_SALES' | 'HR_ADMIN'>(
        'EMPLOYEE_OFFICE',
    );
    let uOfficeId = $state<number>(0);
    let uPatternId = $state<number | ''>('');
    let uJoinDate = $state('');
    let uOrg = $state('');
    let uPos = $state('');
    let uLevel = $state('');
    let uEmpStatus = $state('');
    let uActive = $state(true);
    let uPhotoFile = $state<File | null>(null);

    function openCreateUser() {
        editingUser = null;
        uNik = '';
        uEmail = '';
        uName = '';
        uPassword = '';
        uRole = 'EMPLOYEE_OFFICE';
        uOfficeId = activeOffices[0]?.id ?? offices[0]?.id ?? 0;
        uPatternId = activePatterns[0]?.id ?? '';
        uJoinDate = new Date().toISOString().slice(0, 10);
        uOrg = '';
        uPos = '';
        uLevel = '';
        uEmpStatus = 'Karyawan Tetap';
        uActive = true;
        uPhotoFile = null;
        userModalOpen = true;
    }

    function openEditUser(u: UserItem) {
        editingUser = u;
        uNik = u.nik;
        uEmail = u.email;
        uName = u.name;
        uPassword = '';
        uRole = u.role;
        uOfficeId = u.office_location_id;
        uPatternId = u.default_shift_pattern_id ?? '';
        uJoinDate = u.join_date.slice(0, 10);
        uOrg = u.organization ?? '';
        uPos = u.job_position ?? '';
        uLevel = u.job_level ?? '';
        uEmpStatus = u.employment_status ?? '';
        uActive = u.is_active;
        uPhotoFile = null;
        userModalOpen = true;
    }

    async function submitUser() {
        const fd = new FormData();
        if (editingUser) {
            fd.append('_method', 'PATCH');
            fd.append('name', uName.trim());
            fd.append('organization', uOrg.trim());
            fd.append('job_position', uPos.trim());
            fd.append('job_level', uLevel.trim());
            fd.append('employment_status', uEmpStatus.trim());

            if (editingUser.id !== currentUserId) {
                fd.append('role', uRole);
                fd.append('office_location_id', String(uOfficeId));
                if (uRole !== 'EMPLOYEE_SALES' && uPatternId !== '') {
                    fd.append('default_shift_pattern_id', String(uPatternId));
                } else {
                    fd.append('default_shift_pattern_id', '');
                }
                fd.append('join_date', uJoinDate);
                fd.append('is_active', uActive ? '1' : '0');
                if (uPhotoFile) {
                    fd.append('reference_photo', uPhotoFile);
                }
            }

            const ok = await sendForm(
                `/admin/users/${editingUser.id}`,
                fd,
                `Data karyawan ${uNik} berhasil diperbarui.`,
            );
            if (ok) userModalOpen = false;
            return;
        }

        fd.append('nik', uNik.trim());
        fd.append('email', uEmail.trim());
        fd.append('name', uName.trim());
        fd.append('password', uPassword);
        fd.append('role', uRole);
        fd.append('office_location_id', String(uOfficeId));
        if (uRole !== 'EMPLOYEE_SALES' && uPatternId !== '') {
            fd.append('default_shift_pattern_id', String(uPatternId));
        }
        fd.append('join_date', uJoinDate);
        if (uOrg.trim()) fd.append('organization', uOrg.trim());
        if (uPos.trim()) fd.append('job_position', uPos.trim());
        if (uLevel.trim()) fd.append('job_level', uLevel.trim());
        if (uEmpStatus.trim()) fd.append('employment_status', uEmpStatus.trim());
        if (uPhotoFile) fd.append('reference_photo', uPhotoFile);

        const ok = await sendForm(
            '/admin/users',
            fd,
            'Karyawan baru berhasil ditambahkan & jadwal 90 hari dibuat.',
        );
        if (ok) userModalOpen = false;
    }

    // --- User Secondary Actions (Reset PW, Unlock, Balance, Override) ---
    let resetTarget = $state<UserItem | null>(null);
    let resetPasswordVal = $state('');

    let balanceTarget = $state<UserItem | null>(null);
    let balYear = $state(0);
    let balAdj = $state(0);
    let balReason = $state('');

    let overrideTarget = $state<UserItem | null>(null);
    let ovDate = $state('');
    let ovDayOff = $state(false);
    let ovShiftId = $state<number | ''>('');
    let ovReplacementDate = $state('');
    let ovNotes = $state('');
    const overrideEligibleOrigins = $derived(
        overrideTarget
            ? eligibleGantiHariOrigins.filter((o) => o.user_id === overrideTarget?.id)
            : [],
    );
    const overrideUpcomingGantiHari = $derived(
        overrideTarget
            ? upcomingGantiHari.filter((g) => g.user_id === overrideTarget?.id)
            : [],
    );

    let csvBalanceOpen = $state(false);
    let csvBalanceYear = $state(0);
    let csvBalanceText = $state('nik,year,adjustment_days,notes\nOFF001,2026,2,Saldo awal\nSLS001,2026,-1.5,Koreksi');
    const balancePreview = $derived.by(() => {
        const b = balanceTarget?.leave_balances?.[0];
        if (!b) return null;
        const adj = Number(balAdj);

        return (
            Number(b.base_quota) -
            Number(b.prorated_deduction) -
            Number(b.collective_leave_deduction) -
            Number(b.used_days) -
            Number(b.pending_days) +
            (Number.isFinite(adj) ? adj : 0)
        );
    });
    const csvBalanceRows = $derived.by(() => {
        const lines = csvBalanceText
            .replace(/^\uFEFF/, '')
            .split(/\r?\n/)
            .map((l) => l.trim().replace(/"/g, ''))
            .filter(Boolean);

        const rows = lines
            .filter((line, idx) => {
                if (idx !== 0) return true;
                const firstCell = line.split(/[,;]/)[0]?.trim().toLowerCase() ?? '';
                return firstCell !== 'nik';
            })
            .map((line) => {
                const parts = line.split(/[,;]/).map((s) => s.trim());
                const nikRaw = parts[0] ?? '';
                const hasYearCol = parts.length >= 3;
                const yearRaw = hasYearCol ? (parts[1] ?? '') : String(csvBalanceYear);
                const adjRaw = hasYearCol ? (parts[2] ?? '') : (parts[1] ?? '');
                const notesRaw = hasYearCol ? parts.slice(3).join(',').trim() : '';

                const nikUpper = nikRaw.toUpperCase();
                const formulaUnsafe =
                    /^[=+\-@\t\r]/.test(nikRaw) ||
                    /^[=+@\t\r]/.test(adjRaw) ||
                    (adjRaw.startsWith('-') && Number.isNaN(Number(adjRaw))) ||
                    /^[=+\-@\t\r]/.test(notesRaw);
                const yearNum = yearRaw === '' ? Number(csvBalanceYear) : Number(yearRaw);
                const invalidYear = !Number.isInteger(yearNum) || yearNum < 2000 || yearNum > 2100;
                const adjNum = Number(adjRaw);
                const invalidAdj =
                    adjRaw === '' || Number.isNaN(adjNum) || adjNum < -30 || adjNum > 30;
                const invalidStep = !invalidAdj && !Number.isInteger(adjNum * 2);
                const matchedUser = users.find((u) => u.nik.toUpperCase() === nikUpper);
                const isSelf = nikUpper !== '' && nikUpper === currentUserNik;
                const notFound = !formulaUnsafe && !matchedUser;
                const joinDateStr = matchedUser?.join_date?.slice(0, 10) ?? '';
                const oneYearDate = joinDateStr
                    ? `${Number(joinDateStr.slice(0, 4)) + 1}${joinDateStr.slice(4)}`
                    : '';
                const todayStr = new Date().toISOString().slice(0, 10);
                const refDate = yearNum === currentYear ? todayStr : `${yearNum}-12-31`;
                const ineligibleTenure =
                    !invalidAdj && adjNum > 0 && oneYearDate !== '' && oneYearDate > refDate;

                return {
                    nik: nikRaw,
                    year: Number.isInteger(yearNum) ? yearNum : Number(csvBalanceYear),
                    userName: matchedUser?.name ?? '-',
                    adjustment_days: Number.isNaN(adjNum) ? 0 : adjNum,
                    notes: notesRaw,
                    unsafe: formulaUnsafe,
                    invalidYear,
                    invalidAdj,
                    invalidStep,
                    ineligibleTenure,
                    notFound,
                    isSelf,
                    duplicateNik: false,
                    hasError:
                        formulaUnsafe ||
                        invalidYear ||
                        invalidAdj ||
                        invalidStep ||
                        ineligibleTenure ||
                        notFound,
                };
            });

        const nikCounts = new SvelteMap<string, number>();
        for (const row of rows) {
            const key = row.nik.trim().toUpperCase();
            if (key !== '') nikCounts.set(key, (nikCounts.get(key) ?? 0) + 1);
        }

        return rows.map((row) => {
            const duplicateNik = (nikCounts.get(row.nik.trim().toUpperCase()) ?? 0) > 1;
            return { ...row, duplicateNik, hasError: row.hasError || duplicateNik };
        });
    });

    // --- Shift Modal State ---
    let shiftModalOpen = $state(false);
    let editingShift = $state<ShiftItem | null>(null);
    let sCode = $state('');
    let sName = $state('');
    let sStart = $state('08:00');
    let sEnd = $state('16:00');
    let sGrace = $state(10);
    let sAutoClose = $state(5);

    function openShiftModal(s: ShiftItem | null) {
        editingShift = s;
        sCode = s?.code ?? '';
        sName = s?.name ?? '';
        sStart = s ? s.start_time.slice(0, 5) : '08:00';
        sEnd = s ? s.end_time.slice(0, 5) : '16:00';
        sGrace = s?.grace_period_minutes ?? 10;
        sAutoClose = s?.auto_close_hours ?? 5;
        shiftModalOpen = true;
    }

    async function submitShift() {
        const payload = {
            code: sCode.trim().toUpperCase(),
            name: sName.trim(),
            start_time: sStart,
            end_time: sEnd,
            grace_period_minutes: Number(sGrace),
            auto_close_hours: Number(sAutoClose),
        };
        const ok = editingShift
            ? await sendJson(
                  `/admin/shifts/${editingShift.id}`,
                  'PATCH',
                  payload,
                  `Shift ${payload.code} diperbarui.`,
              )
            : await sendJson(
                  '/admin/shifts',
                  'POST',
                  payload,
                  `Shift ${payload.code} ditambahkan.`,
              );
        if (ok) shiftModalOpen = false;
    }

    // --- Shift Pattern Modal State ---
    let patternModalOpen = $state(false);
    let editingPattern = $state<PatternItem | null>(null);
    let pName = $state('');
    let pDays = $state<(number | '')[]>(['', '', '', '', '', '', '']);
    const dayLabels = ['Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu', 'Minggu'];

    function openPatternModal(p: PatternItem | null) {
        editingPattern = p;
        pName = p?.name ?? '';
        pDays = [
            p?.mon_shift_id ?? '',
            p?.tue_shift_id ?? '',
            p?.wed_shift_id ?? '',
            p?.thu_shift_id ?? '',
            p?.fri_shift_id ?? '',
            p?.sat_shift_id ?? '',
            p?.sun_shift_id ?? '',
        ];
        patternModalOpen = true;
    }

    async function submitPattern() {
        const toId = (v: number | '') => (v === '' ? null : Number(v));
        const payload = {
            name: pName.trim(),
            mon_shift_id: toId(pDays[0]),
            tue_shift_id: toId(pDays[1]),
            wed_shift_id: toId(pDays[2]),
            thu_shift_id: toId(pDays[3]),
            fri_shift_id: toId(pDays[4]),
            sat_shift_id: toId(pDays[5]),
            sun_shift_id: toId(pDays[6]),
        };
        const ok = editingPattern
            ? await sendJson(
                  `/admin/shift-patterns/${editingPattern.id}`,
                  'PATCH',
                  payload,
                  'Pola jadwal diperbarui & jadwal 90 hari karyawan terkait disinkronkan.',
              )
            : await sendJson(
                  '/admin/shift-patterns',
                  'POST',
                  payload,
                  'Pola jadwal baru berhasil ditambahkan.',
              );
        if (ok) patternModalOpen = false;
    }

    // --- Location (Office & Outlet) Modal State ---
    let locModalOpen = $state(false);
    let editingLoc = $state<LocationItem | null>(null);
    let lType = $state<'OFFICE' | 'OUTLET'>('OFFICE');
    let lCode = $state('');
    let lName = $state('');
    let lAddress = $state('');
    let lLat = $state(-7.2575);
    let lLng = $state(112.7521);
    let lRadius = $state(100);
    let lTimezone = $state('Asia/Jakarta');
    let lWorkDays = $state<number[]>([1, 2, 3, 4, 5]);

    function openLocationModal(
        type: 'OFFICE' | 'OUTLET',
        loc: LocationItem | null,
    ) {
        editingLoc = loc;
        lType = loc?.type ?? type;
        lCode = loc?.code ?? '';
        lName = loc?.name ?? '';
        lAddress = loc?.address ?? '';
        lLat = loc ? Number(loc.latitude) : -7.2575;
        lLng = loc ? Number(loc.longitude) : 112.7521;
        lRadius = loc?.radius_m ?? 100;
        lTimezone = loc?.timezone ?? 'Asia/Jakarta';
        lWorkDays = loc?.work_days ? [...loc.work_days] : [1, 2, 3, 4, 5, 6];
        locModalOpen = true;
    }

    let locGpsBusy = $state(false);

    async function fillCurrentGpsForLocation() {
        locGpsBusy = true;
        try {
            const res = await requestFreshGps(Number(lRadius) || 100);
            if (res.lat !== null && res.lng !== null) {
                lLat = Number(res.lat.toFixed(6));
                lLng = Number(res.lng.toFixed(6));
            } else if (res.message) {
                alert(res.message);
            }
        } finally {
            locGpsBusy = false;
        }
    }

    function toggleWorkDay(day: number) {
        lWorkDays = lWorkDays.includes(day)
            ? lWorkDays.filter((d) => d !== day)
            : [...lWorkDays, day].sort((a, b) => a - b);
    }

    async function submitLocation() {
        const payload = {
            type: lType,
            code: lCode.trim() || null,
            name: lName.trim(),
            address: lAddress.trim(),
            latitude: Number(lLat),
            longitude: Number(lLng),
            radius_m: Number(lRadius),
            timezone: lTimezone,
            work_days: lWorkDays.length ? lWorkDays : [1, 2, 3, 4, 5],
        };
        const ok = editingLoc
            ? await sendJson(
                  `/admin/locations/${editingLoc.id}`,
                  'PATCH',
                  payload,
                  `${lType === 'OFFICE' ? 'Kantor' : 'Outlet'} berhasil diperbarui.`,
              )
            : await sendJson(
                  '/admin/locations',
                  'POST',
                  payload,
                  `${lType === 'OFFICE' ? 'Kantor' : 'Outlet'} baru berhasil ditambahkan.`,
              );
        if (ok) locModalOpen = false;
    }

    // --- NOO Verification Modal ---
    let nooTarget = $state<LocationItem | null>(null);
    let nooCode = $state('');
    let nooRadius = $state(100);
    let nooTimezone = $state('Asia/Jakarta');
    let nooRejectReason = $state('');

    function openNooModal(loc: LocationItem) {
        nooTarget = loc;
        nooCode = loc.code ?? `OUT-${loc.id}`;
        nooRadius = loc.radius_m;
        nooTimezone = loc.timezone;
        nooRejectReason = '';
    }

    async function submitNoo(status: 'ACTIVE' | 'INACTIVE') {
        if (!nooTarget) return;
        const ok = await sendJson(
            `/admin/locations/${nooTarget.id}/verify-noo`,
            'POST',
            {
                status,
                code: nooCode.trim() || null,
                radius_m: Number(nooRadius),
                timezone: nooTimezone,
                reject_reason:
                    status === 'INACTIVE' ? nooRejectReason.trim() : null,
            },
            status === 'ACTIVE'
                ? `Toko baru ${nooTarget.name} disetujui dan aktif.`
                : `Toko baru ${nooTarget.name} ditolak.`,
        );
        if (ok) nooTarget = null;
    }

    // --- Outlet CSV Import Modal ---
    let csvOutletOpen = $state(false);
    let csvOutletText = $state(
        'OUT-101,Toko Maju Jaya,Jl. Basuki Rahmat 12 Surabaya,-7.2655,112.7422,100,Asia/Jakarta',
    );
    const csvOutletRows = $derived(
        csvOutletText
            .split(/\r?\n/)
            .map((l) => l.trim())
            .filter(Boolean)
            .map((line) => {
                const [
                    code = '',
                    name = '',
                    address = '',
                    lat = '0',
                    lng = '0',
                    radius = '100',
                    tz = 'Asia/Jakarta',
                ] = line.split(/[,;]/).map((s) => s.trim());
                const unsafe = [code, name, address].some((v) =>
                    /^[=+\-@]/.test(v),
                );
                return {
                    code,
                    name,
                    address,
                    latitude: Number(lat),
                    longitude: Number(lng),
                    radius_m: Number(radius) || 100,
                    timezone: tz || 'Asia/Jakarta',
                    unsafe,
                };
            }),
    );

    // --- Activity Category Modal State ---
    let catModalOpen = $state(false);
    let editingCat = $state<CategoryItem | null>(null);
    let cName = $state('');
    let cExempt = $state(false);

    function openCatModal(c: CategoryItem | null) {
        editingCat = c;
        cName = c?.name ?? '';
        cExempt = c?.exempt_short_duration ?? false;
        catModalOpen = true;
    }

    async function submitCategory() {
        const payload = { name: cName.trim(), exempt_short_duration: cExempt };
        const ok = editingCat
            ? await sendJson(
                  `/admin/activity-categories/${editingCat.id}`,
                  'PATCH',
                  payload,
                  'Kategori aktivitas diperbarui.',
              )
            : await sendJson(
                  '/admin/activity-categories',
                  'POST',
                  payload,
                  'Kategori aktivitas ditambahkan.',
              );
        if (ok) catModalOpen = false;
    }

    // --- Holiday Modal State ---
    let holidayModalOpen = $state(false);
    let editingHoliday = $state<HolidayItem | null>(null);
    let deleteHolidayTarget = $state<HolidayItem | null>(null);
    let hDate = $state('');
    let hType = $state<'NATIONAL' | 'LOCAL' | 'COLLECTIVE_LEAVE'>('NATIONAL');
    let hLocationId = $state<number | ''>('');
    let hDesc = $state('');

    function openHolidayModal(h: HolidayItem | null) {
        editingHoliday = h;
        hDate = h ? h.holiday_date.slice(0, 10) : new Date().toISOString().slice(0, 10);
        hType = h?.holiday_type ?? 'NATIONAL';
        hLocationId = h?.location_id ?? activeOffices[0]?.id ?? '';
        hDesc = h?.description ?? '';
        holidayModalOpen = true;
    }

    async function submitHoliday() {
        const payload = {
            holiday_date: hDate,
            holiday_type: hType,
            location_id: hType === 'LOCAL' && hLocationId !== '' ? Number(hLocationId) : null,
            description: hDesc.trim(),
        };
        const ok = editingHoliday
            ? await sendJson(
                  `/admin/holidays/${editingHoliday.id}`,
                  'PATCH',
                  payload,
                  holidaySuccessText(
                      'Hari libur diperbarui & pengajuan cuti/saldo terkait disinkronkan.',
                  ),
              )
            : await sendJson(
                  '/admin/holidays',
                  'POST',
                  payload,
                  holidaySuccessText(
                      'Hari libur ditambahkan & pengajuan cuti/saldo terkait disinkronkan.',
                  ),
              );
        if (ok) holidayModalOpen = false;
    }

    function readCsvFile(
        e: Event,
        setter: (content: string) => void,
    ): void {
        const input = e.currentTarget as HTMLInputElement;
        const file = input.files?.[0];
        if (!file) return;
        file.text().then((txt) => setter(txt));
    }
</script>

<AppHead title="Master Data HR" />

<div class="space-y-4 p-4 text-stone-900 lg:p-5">
    <div
        class="flex flex-wrap items-center justify-between gap-3 rounded-xl bg-gradient-to-r from-[#6F3E1D] via-[#8B5E3C] to-[#8B5E3C] p-4 text-white shadow-sm"
    >
        <div>
            <h1 class="text-lg font-semibold tracking-tight text-white md:text-xl">
                Master Data HR &amp; Operasional
            </h1>
            <p class="text-xs text-stone-200">
                Kelola karyawan, pola jadwal &amp; jam shift, kantor cabang, outlet NOO, kategori aktivitas, dan hari libur
            </p>
        </div>
        <div class="rounded-lg border border-white/20 bg-white/10 px-3 py-1.5 text-xs">
            HR Admin Aktif: <span class="font-mono font-semibold">{activeHrCount}</span>
        </div>
    </div>

    {#if activeHrCount < 2}
        <div
            class="flex items-center gap-2 rounded-md border border-yellow-300 bg-yellow-50 px-3 py-2 text-xs text-yellow-800"
        >
            <AlertTriangle class="size-4 shrink-0" />
            <span>
                Perhatian: Baru terdapat {activeHrCount} HR Admin aktif. Minimal wajib 2 HR Admin aktif agar proses saling verifikasi berjalan.
            </span>
        </div>
    {/if}

    {#if errorMsg}
        <div class="rounded-md border border-red-300 bg-red-50 px-3 py-2 text-xs text-red-700">
            {errorMsg}
        </div>
    {/if}
    {#if successMsg}
        <div class="rounded-md border border-green-300 bg-green-50 px-3 py-2 text-xs text-green-700">
            {successMsg}
        </div>
    {/if}

    <!-- Tabs -->
    <div class="flex flex-wrap gap-1.5 rounded-lg border border-stone-200 bg-white p-1.5 shadow-xs">
        {#each [
            { key: 'users', label: `Karyawan (${users.length})` },
            { key: 'shifts', label: `Pola & Jam Shift (${patterns.length}/${shifts.length})` },
            { key: 'offices', label: `Kantor (${offices.length})` },
            {
                key: 'outlets',
                label: `Outlet & NOO (${outlets.length})${pendingNooCount ? ` • ${pendingNooCount} NOO` : ''}`,
            },
            { key: 'categories', label: `Kategori Aktivitas (${categories.length})` },
            { key: 'holidays', label: `Hari Libur (${holidays.length})` },
        ] as tab (tab.key)}
            <button
                type="button"
                class={`cursor-pointer rounded-md px-3 py-1.5 text-xs font-medium transition ${
                    activeTab === tab.key
                        ? 'bg-[#6F3E1D] text-white'
                        : 'text-stone-700 hover:bg-[#F5EDE6] hover:text-[#6F3E1D]'
                }`}
                onclick={() => {
                    activeTab = tab.key as TabKey;
                }}
            >
                {tab.label}
            </button>
        {/each}
    </div>

    <!-- TAB 1: KARYAWAN -->
    {#if activeTab === 'users'}
        <section class="rounded-lg border border-stone-200 bg-white shadow-xs">
            <div class="flex flex-wrap items-center justify-between gap-2 border-b border-stone-200 p-3">
                <div class="flex flex-wrap items-center gap-2">
                    <Input
                        bind:value={userSearch}
                        placeholder="Cari NIK, nama, email..."
                        class="h-8 w-52 border-stone-300 text-xs"
                    />
                    <select
                        bind:value={userRoleFilter}
                        class="h-8 rounded-md border border-stone-300 bg-white px-2 text-xs text-stone-800"
                    >
                        <option value="">Semua Peran</option>
                        <option value="HR_ADMIN">HR Admin</option>
                        <option value="EMPLOYEE_OFFICE">Staf Kantor</option>
                        <option value="EMPLOYEE_SALES">Tim Sales</option>
                    </select>
                    <select
                        bind:value={userOrgFilter}
                        class="h-8 rounded-md border border-stone-300 bg-white px-2 text-xs text-stone-800"
                    >
                        <option value="">Semua Organisasi</option>
                        {#each userOrganizations as org (org)}
                            <option value={org}>{org}</option>
                        {/each}
                    </select>
                </div>
                <div class="flex flex-wrap gap-2">
                    <Button
                        size="sm"
                        variant="outline"
                        class="h-8 cursor-pointer border-stone-300 text-xs"
                        onclick={() => {
                            csvBalanceYear = currentYear;
                            csvBalanceOpen = true;
                        }}
                    >
                        <Upload class="mr-1 size-3.5" /> Impor CSV Saldo Cuti
                    </Button>
                    <Button
                        size="sm"
                        class="h-8 cursor-pointer bg-[#6F3E1D] text-xs text-white hover:bg-[#583015]"
                        onclick={openCreateUser}
                    >
                        <Plus class="mr-1 size-3.5" /> Tambah Karyawan
                    </Button>
                </div>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-left text-[13px]">
                    <thead class="h-8 bg-stone-100 text-[11px] tracking-wide text-stone-600 uppercase">
                        <tr>
                            <th class="px-2.5">NIK / Nama</th>
                            <th class="px-2.5">Role</th>
                            <th class="px-2.5">Organisasi</th>
                            <th class="px-2.5">Kantor</th>
                            <th class="px-2.5">Pola Shift</th>
                            <th class="px-2.5">Tgl Masuk</th>
                            <th class="px-2.5">Sisa Cuti</th>
                            <th class="px-2.5">Status</th>
                            <th class="px-2.5">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-stone-200">
                        {#each filteredUsers as u (u.id)}
                            {@const isSelf = u.id === currentUserId}
                            <tr class="hover:bg-stone-50">
                                <td class="px-2.5 py-2">
                                    <div class="flex items-center gap-2">
                                        {#if u.reference_photo_signed_url}
                                            <img
                                                src={u.reference_photo_signed_url}
                                                alt={u.name}
                                                class="size-7 rounded-full border border-stone-200 object-cover"
                                            />
                                        {/if}
                                        <div>
                                            <p class="font-medium text-stone-900">
                                                <span class="font-mono">{u.nik}</span> • {u.name}
                                                {#if isSelf}
                                                    <span class={`${badgeBase} ml-1 border-blue-300 bg-blue-50 text-blue-700`}>
                                                        Akun Anda
                                                    </span>
                                                {/if}
                                            </p>
                                            <p class="text-[11px] text-stone-500">{u.email}</p>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-2.5 py-2 text-xs">{roleLabel(u.role)}</td>
                                <td class="px-2.5 py-2 text-xs">{u.organization ?? '-'}</td>
                                <td class="px-2.5 py-2 text-xs">{u.office_location?.name ?? '-'}</td>
                                <td class="px-2.5 py-2 text-xs">{u.default_shift_pattern?.name ?? 'Fleksibel'}</td>
                                <td class="px-2.5 py-2 font-mono text-xs">{u.join_date.slice(0, 10)}</td>
                                <td class="px-2.5 py-2 font-mono text-xs">{availableLeave(u)} hr</td>
                                <td class="px-2.5 py-2">
                                    <span class={activeBadge(u.is_active)}>
                                        {u.is_active ? 'AKTIF' : 'NONAKTIF'}
                                    </span>
                                </td>
                                <td class="px-2.5 py-2">
                                    <div class="flex flex-wrap gap-1">
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            class="h-7 cursor-pointer border-stone-300 px-2 text-[11px]"
                                            onclick={() => openEditUser(u)}
                                        >
                                            <Pencil class="mr-1 size-3" /> Edit
                                        </Button>
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            disabled={isSelf || busy}
                                            class="h-7 cursor-pointer border-stone-300 px-2 text-[11px]"
                                            onclick={() => {
                                                resetTarget = u;
                                                resetPasswordVal = '';
                                            }}
                                        >
                                            <KeyRound class="mr-1 size-3" /> Reset PW
                                        </Button>
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            disabled={isSelf || busy}
                                            class="h-7 cursor-pointer border-stone-300 px-2 text-[11px]"
                                            onclick={() =>
                                                sendJson(
                                                    `/admin/users/${u.id}/unlock`,
                                                    'POST',
                                                    {},
                                                    `Kunci login ${u.nik} dibuka.`,
                                                )}
                                        >
                                            <Unlock class="mr-1 size-3" /> Unlock
                                        </Button>
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            disabled={isSelf || busy}
                                            class="h-7 cursor-pointer border-stone-300 px-2 text-[11px]"
                                            onclick={() => {
                                                balanceTarget = u;
                                                balYear = currentYear;
                                                balAdj = Number(u.leave_balances?.[0]?.adjustment_days ?? 0);
                                                balReason = '';
                                            }}
                                        >
                                            Saldo
                                        </Button>
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            disabled={isSelf || busy}
                                            class="h-7 cursor-pointer border-stone-300 px-2 text-[11px]"
                                            onclick={() => {
                                                overrideTarget = u;
                                                ovDate = new Date().toISOString().slice(0, 10);
                                                ovDayOff = false;
                                                ovShiftId = activeShifts[0]?.id ?? '';
                                                ovReplacementDate = '';
                                                ovNotes = '';
                                            }}
                                        >
                                            <CalendarDays class="mr-1 size-3" /> Jadwal
                                        </Button>
                                    </div>
                                </td>
                            </tr>
                        {/each}
                    </tbody>
                </table>
            </div>
        </section>
    {/if}

    <!-- TAB 2: POLA JADWAL & MASTER JAM SHIFT -->
    {#if activeTab === 'shifts'}
        <div class="space-y-4">
            <section class="rounded-lg border border-stone-200 bg-white shadow-xs">
                <div class="flex items-center justify-between border-b border-stone-200 p-3">
                    <div>
                        <h2 class="text-sm font-semibold">Pola Jadwal Mingguan</h2>
                        <p class="text-[11px] text-stone-500">
                            Mengubah pola jadwal otomatis memperbarui jadwal kerja karyawan. Jadwal khusus per tanggal tidak ikut tertimpa.
                        </p>
                    </div>
                    <Button
                        size="sm"
                        class="h-8 cursor-pointer bg-[#6F3E1D] text-xs text-white hover:bg-[#583015]"
                        onclick={() => openPatternModal(null)}
                    >
                        <Plus class="mr-1 size-3.5" /> Tambah Pola
                    </Button>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-[13px]">
                        <thead class="h-8 bg-stone-100 text-[11px] tracking-wide text-stone-600 uppercase">
                            <tr>
                                <th class="px-2.5">Nama Pola</th>
                                <th class="px-2.5">Sen</th>
                                <th class="px-2.5">Sel</th>
                                <th class="px-2.5">Rab</th>
                                <th class="px-2.5">Kam</th>
                                <th class="px-2.5">Jum</th>
                                <th class="px-2.5">Sab</th>
                                <th class="px-2.5">Min</th>
                                <th class="px-2.5">Status</th>
                                <th class="px-2.5">Aksi</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-stone-200">
                            {#each patterns as p (p.id)}
                                <tr class="hover:bg-stone-50">
                                    <td class="px-2.5 py-2 font-medium">{p.name}</td>
                                    {#each [p.mon_shift_id, p.tue_shift_id, p.wed_shift_id, p.thu_shift_id, p.fri_shift_id, p.sat_shift_id, p.sun_shift_id] as sid, i (i)}
                                        <td class="px-2 py-2 font-mono text-[11px]">
                                            <span class={sid ? 'text-stone-800' : 'text-stone-400'}>
                                                {shiftLabel(sid)}
                                            </span>
                                        </td>
                                    {/each}
                                    <td class="px-2.5 py-2">
                                        <span class={activeBadge(p.is_active)}>
                                            {p.is_active ? 'AKTIF' : 'NONAKTIF'}
                                        </span>
                                    </td>
                                    <td class="px-2.5 py-2">
                                        <div class="flex flex-wrap gap-1">
                                            <Button
                                                size="sm"
                                                variant="outline"
                                                class="h-7 cursor-pointer border-stone-300 px-2 text-[11px]"
                                                onclick={() => openPatternModal(p)}
                                            >
                                                Edit
                                            </Button>
                                            {#if p.is_active}
                                                <Button
                                                    size="sm"
                                                    variant="outline"
                                                    disabled={busy}
                                                    class="h-7 cursor-pointer border-red-300 px-2 text-[11px] text-red-700 hover:bg-red-50"
                                                    onclick={() =>
                                                        sendJson(
                                                            `/admin/shift-patterns/${p.id}/deactivate`,
                                                            'POST',
                                                            {},
                                                            `Pola ${p.name} dinonaktifkan.`,
                                                        )}
                                                >
                                                    Nonaktifkan
                                                </Button>
                                            {/if}
                                        </div>
                                    </td>
                                </tr>
                            {/each}
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="rounded-lg border border-stone-200 bg-white shadow-xs">
                <div class="flex items-center justify-between border-b border-stone-200 p-3">
                    <h2 class="text-sm font-semibold">Master Jam Shift</h2>
                    <Button
                        size="sm"
                        class="h-8 cursor-pointer bg-[#6F3E1D] text-xs text-white hover:bg-[#583015]"
                        onclick={() => openShiftModal(null)}
                    >
                        <Plus class="mr-1 size-3.5" /> Tambah Shift
                    </Button>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-[13px]">
                        <thead class="h-8 bg-stone-100 text-[11px] tracking-wide text-stone-600 uppercase">
                            <tr>
                                <th class="px-2.5">Kode</th>
                                <th class="px-2.5">Nama Shift</th>
                                <th class="px-2.5">Jam Kerja</th>
                                <th class="px-2.5">Toleransi</th>
                                <th class="px-2.5">Tutup Otomatis</th>
                                <th class="px-2.5">Status</th>
                                <th class="px-2.5">Aksi</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-stone-200">
                            {#each shifts as s (s.id)}
                                <tr class="hover:bg-stone-50">
                                    <td class="px-2.5 py-2 font-mono font-semibold">{s.code}</td>
                                    <td class="px-2.5 py-2">{s.name}</td>
                                    <td class="px-2.5 py-2 font-mono text-xs">
                                        {s.start_time.slice(0, 5)} – {s.end_time.slice(0, 5)}
                                    </td>
                                    <td class="px-2.5 py-2 font-mono text-xs">{s.grace_period_minutes} mnt</td>
                                    <td class="px-2.5 py-2 font-mono text-xs">{s.auto_close_hours} jam</td>
                                    <td class="px-2.5 py-2">
                                        <span class={activeBadge(s.is_active)}>
                                            {s.is_active ? 'AKTIF' : 'NONAKTIF'}
                                        </span>
                                    </td>
                                    <td class="px-2.5 py-2">
                                        <div class="flex gap-1.5">
                                            <Button
                                                size="sm"
                                                variant="outline"
                                                class="h-7 cursor-pointer border-stone-300 px-2 text-[11px]"
                                                onclick={() => openShiftModal(s)}
                                            >
                                                Edit
                                            </Button>
                                            {#if s.is_active}
                                                <Button
                                                    size="sm"
                                                    variant="outline"
                                                    disabled={busy}
                                                    class="h-7 cursor-pointer border-red-300 px-2 text-[11px] text-red-700 hover:bg-red-50"
                                                    onclick={() =>
                                                        sendJson(
                                                            `/admin/shifts/${s.id}/deactivate`,
                                                            'POST',
                                                            {},
                                                            `Shift ${s.code} dinonaktifkan.`,
                                                        )}
                                                >
                                                    Nonaktifkan
                                                </Button>
                                            {/if}
                                        </div>
                                    </td>
                                </tr>
                            {/each}
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    {/if}

    <!-- TAB 4: KANTOR -->
    {#if activeTab === 'offices'}
        <section class="rounded-lg border border-stone-200 bg-white shadow-xs">
            <div class="flex items-center justify-between border-b border-stone-200 p-3">
                <h2 class="text-sm font-semibold">Lokasi Kantor Cabang</h2>
                <Button
                    size="sm"
                    class="h-8 cursor-pointer bg-[#6F3E1D] text-xs text-white hover:bg-[#583015]"
                    onclick={() => openLocationModal('OFFICE', null)}
                >
                    <Plus class="mr-1 size-3.5" /> Tambah Kantor
                </Button>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-[13px]">
                    <thead class="h-8 bg-stone-100 text-[11px] tracking-wide text-stone-600 uppercase">
                        <tr>
                            <th class="px-2.5">Kode</th>
                            <th class="px-2.5">Nama &amp; Alamat</th>
                            <th class="px-2.5">Koordinat / Radius</th>
                            <th class="px-2.5">Timezone</th>
                            <th class="px-2.5">Hari Kerja</th>
                            <th class="px-2.5">Status</th>
                            <th class="px-2.5">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-stone-200">
                        {#each offices as loc (loc.id)}
                            <tr class="hover:bg-stone-50">
                                <td class="px-2.5 py-2 font-mono font-semibold">{loc.code ?? '-'}</td>
                                <td class="px-2.5 py-2">
                                    <p class="font-medium">{loc.name}</p>
                                    <p class="text-[11px] text-stone-500">{loc.address}</p>
                                </td>
                                <td class="px-2.5 py-2 font-mono text-xs">
                                    {loc.latitude}, {loc.longitude} • R: {loc.radius_m}m
                                </td>
                                <td class="px-2.5 py-2 font-mono text-xs">{loc.timezone}</td>
                                <td class="px-2.5 py-2 font-mono text-xs">{loc.work_days?.join(',')}</td>
                                <td class="px-2.5 py-2">
                                    <span class={locStatusBadge(loc.status)}>{locStatusLabel(loc.status)}</span>
                                </td>
                                <td class="px-2.5 py-2">
                                    <div class="flex gap-1.5">
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            class="h-7 cursor-pointer border-stone-300 px-2 text-[11px]"
                                            onclick={() => openLocationModal('OFFICE', loc)}
                                        >
                                            Edit
                                        </Button>
                                        {#if loc.status === 'ACTIVE'}
                                            <Button
                                                size="sm"
                                                variant="outline"
                                                disabled={busy}
                                                class="h-7 cursor-pointer border-red-300 px-2 text-[11px] text-red-700 hover:bg-red-50"
                                                onclick={() =>
                                                    sendJson(
                                                        `/admin/locations/${loc.id}/deactivate`,
                                                        'POST',
                                                        {},
                                                        `Kantor ${loc.name} dinonaktifkan.`,
                                                    )}
                                            >
                                                Nonaktifkan
                                            </Button>
                                        {/if}
                                    </div>
                                </td>
                            </tr>
                        {/each}
                    </tbody>
                </table>
            </div>
        </section>
    {/if}

    <!-- TAB 5: OUTLET & VERIFIKASI NOO -->
    {#if activeTab === 'outlets'}
        <section class="rounded-lg border border-stone-200 bg-white shadow-xs">
            <div class="flex flex-wrap items-center justify-between gap-2 border-b border-stone-200 p-3">
                <div class="flex flex-wrap items-center gap-2">
                    <Input
                        bind:value={outletSearch}
                        placeholder="Cari kode/nama toko..."
                        class="h-8 w-48 border-stone-300 text-xs"
                    />
                    <select
                        bind:value={outletStatusFilter}
                        class="h-8 rounded-md border border-stone-300 bg-white px-2 text-xs text-stone-800"
                    >
                        <option value="">Semua Status</option>
                        <option value="PENDING_VERIFICATION">Menunggu verifikasi (Toko baru)</option>
                        <option value="ACTIVE">Aktif</option>
                        <option value="INACTIVE">Nonaktif</option>
                    </select>
                </div>
                <div class="flex flex-wrap gap-2">
                    <Button
                        size="sm"
                        variant="outline"
                        class="h-8 cursor-pointer border-stone-300 text-xs"
                        onclick={() => {
                            csvOutletOpen = true;
                        }}
                    >
                        <Upload class="mr-1 size-3.5" /> Impor CSV Outlet
                    </Button>
                    <Button
                        size="sm"
                        class="h-8 cursor-pointer bg-[#6F3E1D] text-xs text-white hover:bg-[#583015]"
                        onclick={() => openLocationModal('OUTLET', null)}
                    >
                        <Plus class="mr-1 size-3.5" /> Tambah Outlet
                    </Button>
                </div>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-left text-[13px]">
                    <thead class="h-8 bg-stone-100 text-[11px] tracking-wide text-stone-600 uppercase">
                        <tr>
                            <th class="px-2.5">Kode</th>
                            <th class="px-2.5">Nama &amp; Alamat Toko</th>
                            <th class="px-2.5">Koordinat / Radius</th>
                            <th class="px-2.5">Timezone</th>
                            <th class="px-2.5">Status</th>
                            <th class="px-2.5">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-stone-200">
                        {#if filteredOutlets.length === 0}
                            <tr>
                                <td colspan="6" class="p-4 text-center text-xs text-stone-500">
                                    Belum ada data outlet yang cocok.
                                </td>
                            </tr>
                        {:else}
                            {#each filteredOutlets as loc (loc.id)}
                                <tr class="hover:bg-stone-50">
                                    <td class="px-2.5 py-2 font-mono text-xs font-semibold">
                                        {loc.code ?? 'NOO'}
                                    </td>
                                    <td class="px-2.5 py-2">
                                        <p class="font-medium">{loc.name}</p>
                                        <p class="text-[11px] text-stone-500">{loc.address}</p>
                                        {#if loc.reject_reason}
                                            <p class="text-[11px] text-red-700">Alasan tolak: {loc.reject_reason}</p>
                                        {/if}
                                    </td>
                                    <td class="px-2.5 py-2 font-mono text-xs">
                                        {loc.latitude}, {loc.longitude} • R: {loc.radius_m}m
                                    </td>
                                    <td class="px-2.5 py-2 font-mono text-xs">{loc.timezone}</td>
                                    <td class="px-2.5 py-2">
                                        <span class={locStatusBadge(loc.status)}>{locStatusLabel(loc.status)}</span>
                                    </td>
                                    <td class="px-2.5 py-2">
                                        <div class="flex flex-wrap gap-1">
                                            {#if loc.status === 'PENDING_VERIFICATION'}
                                                <Button
                                                    size="sm"
                                                    class="h-7 cursor-pointer bg-[#6F3E1D] px-2 text-[11px] text-white hover:bg-[#583015]"
                                                    onclick={() => openNooModal(loc)}
                                                >
                                                    <ShieldCheck class="mr-1 size-3" /> Verifikasi NOO
                                                </Button>
                                            {/if}
                                            <Button
                                                size="sm"
                                                variant="outline"
                                                class="h-7 cursor-pointer border-stone-300 px-2 text-[11px]"
                                                onclick={() => openLocationModal('OUTLET', loc)}
                                            >
                                                Edit
                                            </Button>
                                            {#if loc.status !== 'INACTIVE'}
                                                <Button
                                                    size="sm"
                                                    variant="outline"
                                                    disabled={busy}
                                                    class="h-7 cursor-pointer border-red-300 px-2 text-[11px] text-red-700 hover:bg-red-50"
                                                    onclick={() =>
                                                        sendJson(
                                                            `/admin/locations/${loc.id}/deactivate`,
                                                            'POST',
                                                            {},
                                                            `Outlet ${loc.name} dinonaktifkan.`,
                                                        )}
                                                >
                                                    Nonaktifkan
                                                </Button>
                                            {/if}
                                        </div>
                                    </td>
                                </tr>
                            {/each}
                        {/if}
                    </tbody>
                </table>
            </div>
        </section>
    {/if}

    <!-- TAB 6: KATEGORI AKTIVITAS -->
    {#if activeTab === 'categories'}
        <section class="rounded-lg border border-stone-200 bg-white shadow-xs">
            <div class="flex items-center justify-between border-b border-stone-200 p-3">
                <h2 class="text-sm font-semibold">Kategori Aktivitas Sales</h2>
                <Button
                    size="sm"
                    class="h-8 cursor-pointer bg-[#6F3E1D] text-xs text-white hover:bg-[#583015]"
                    onclick={() => openCatModal(null)}
                >
                    <Plus class="mr-1 size-3.5" /> Tambah Kategori
                </Button>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-[13px]">
                    <thead class="h-8 bg-stone-100 text-[11px] tracking-wide text-stone-600 uppercase">
                        <tr>
                            <th class="px-2.5">Nama Kategori</th>
                            <th class="px-2.5">Bebas Short Duration</th>
                            <th class="px-2.5">Status</th>
                            <th class="px-2.5">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-stone-200">
                        {#each categories as cat (cat.id)}
                            <tr class="hover:bg-stone-50">
                                <td class="px-2.5 py-2 font-medium">{cat.name}</td>
                                <td class="px-2.5 py-2 text-xs">
                                    {cat.exempt_short_duration ? 'Ya (Bebas flag <10 mnt)' : 'Tidak'}
                                </td>
                                <td class="px-2.5 py-2">
                                    <span class={activeBadge(cat.is_active)}>
                                        {cat.is_active ? 'AKTIF' : 'NONAKTIF'}
                                    </span>
                                </td>
                                <td class="px-2.5 py-2">
                                    <div class="flex gap-1.5">
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            class="h-7 cursor-pointer border-stone-300 px-2 text-[11px]"
                                            onclick={() => openCatModal(cat)}
                                        >
                                            Edit
                                        </Button>
                                        {#if cat.is_active}
                                            <Button
                                                size="sm"
                                                variant="outline"
                                                disabled={busy}
                                                class="h-7 cursor-pointer border-red-300 px-2 text-[11px] text-red-700 hover:bg-red-50"
                                                onclick={() =>
                                                    sendJson(
                                                        `/admin/activity-categories/${cat.id}/deactivate`,
                                                        'POST',
                                                        {},
                                                        `Kategori ${cat.name} dinonaktifkan.`,
                                                    )}
                                            >
                                                Nonaktifkan
                                            </Button>
                                        {/if}
                                    </div>
                                </td>
                            </tr>
                        {/each}
                    </tbody>
                </table>
            </div>
        </section>
    {/if}

    <!-- TAB 7: HARI LIBUR & CUTI BERSAMA -->
    {#if activeTab === 'holidays'}
        <section class="rounded-lg border border-stone-200 bg-white shadow-xs">
            <div class="flex flex-wrap items-center justify-between gap-2 border-b border-stone-200 p-3">
                <div>
                    <h2 class="text-sm font-semibold">Kalender Hari Libur &amp; Cuti Bersama</h2>
                    <p class="text-[11px] text-stone-500">
                        Tambah/ubah/hapus otomatis merekalkulasi pengajuan cuti/izin (&gt;= hari ini); Cuti Bersama hanya memotong saldo masa kerja &ge; 1 tahun.
                    </p>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <select
                        value={effectiveHolidayYear}
                        onchange={(e) => (holidayYearFilter = e.currentTarget.value)}
                        class="h-8 rounded-md border border-stone-300 bg-white px-2 text-xs"
                    >
                        <option value="">Semua Tahun</option>
                        {#each holidayYears as year (year)}
                            <option value={year}>{year}</option>
                        {/each}
                    </select>
                    <Button
                        size="sm"
                        class="h-8 cursor-pointer bg-[#6F3E1D] text-xs text-white hover:bg-[#583015]"
                        onclick={() => openHolidayModal(null)}
                    >
                        <Plus class="mr-1 size-3.5" /> Tambah Hari Libur
                    </Button>
                </div>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-[13px]">
                    <thead class="h-8 bg-stone-100 text-[11px] tracking-wide text-stone-600 uppercase">
                        <tr>
                            <th class="px-2.5">Tanggal</th>
                            <th class="px-2.5">Tipe</th>
                            <th class="px-2.5">Cabang</th>
                            <th class="px-2.5">Keterangan</th>
                            <th class="px-2.5">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-stone-200">
                        {#if filteredHolidays.length === 0}
                            <tr>
                                <td colspan="5" class="px-2.5 py-6 text-center text-xs text-stone-500">
                                    Tidak ada hari libur tercatat untuk filter tahun ini.
                                </td>
                            </tr>
                        {/if}
                        {#each filteredHolidays as h (h.id)}
                            <tr class="hover:bg-stone-50">
                                <td class="px-2.5 py-2 font-mono text-xs">{h.holiday_date.slice(0, 10)}</td>
                                <td class="px-2.5 py-2">
                                    <span class={holidayTypeBadge(h.holiday_type)}>
                                        {holidayTypeLabel(h.holiday_type)}
                                    </span>
                                </td>
                                <td class="px-2.5 py-2 text-xs">{h.location?.name ?? 'Semua Cabang'}</td>
                                <td class="px-2.5 py-2">{h.description}</td>
                                <td class="px-2.5 py-2">
                                    <div class="flex gap-1.5">
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            class="h-7 cursor-pointer border-stone-300 px-2 text-[11px]"
                                            onclick={() => openHolidayModal(h)}
                                        >
                                            Edit
                                        </Button>
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            disabled={busy}
                                            class="h-7 cursor-pointer border-red-300 px-2 text-[11px] text-red-700 hover:bg-red-50"
                                            onclick={() => (deleteHolidayTarget = h)}
                                        >
                                            Hapus
                                        </Button>
                                    </div>
                                </td>
                            </tr>
                        {/each}
                    </tbody>
                </table>
            </div>
        </section>
    {/if}
</div>

<!-- MODAL: USER CREATE/EDIT -->
{#if userModalOpen}
    {@const isSelfEdit = editingUser !== null && editingUser.id === currentUserId}
    <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" role="dialog" aria-modal="true">
        <div class="max-h-[90vh] w-full max-w-lg overflow-y-auto rounded-lg border border-stone-200 bg-white p-4 shadow-lg">
            <div class="mb-3 flex items-center justify-between border-b border-stone-200 pb-2">
                <h3 class="text-sm font-semibold">
                    {editingUser ? `Edit Karyawan (${editingUser.nik})` : 'Tambah Karyawan Baru'}
                </h3>
                <button type="button" onclick={() => (userModalOpen = false)}><X class="size-4" /></button>
            </div>
            {#if isSelfEdit}
                <p class="mb-3 rounded border border-yellow-300 bg-yellow-50 p-2 text-xs text-yellow-800">
                    Anti Self-Manipulation: Anda tidak diperbolehkan mengubah role, lokasi kantor, pola shift, tanggal masuk, foto referensi, atau status aktif akun sendiri.
                </p>
            {/if}
            <div class="grid gap-2.5 text-xs md:grid-cols-2">
                {#if !editingUser}
                    <label class="block">
                        <span class="mb-1 block font-medium">NIK</span>
                        <Input bind:value={uNik} placeholder="LSI001" class="h-8 text-xs" />
                    </label>
                    <label class="block">
                        <span class="mb-1 block font-medium">Email</span>
                        <Input bind:value={uEmail} type="email" placeholder="nama@lsi.co.id" class="h-8 text-xs" />
                    </label>
                {/if}
                <label class="block md:col-span-2">
                    <span class="mb-1 block font-medium">Nama Lengkap</span>
                    <Input bind:value={uName} class="h-8 text-xs" />
                </label>
                {#if !editingUser}
                    <label class="block md:col-span-2">
                        <span class="mb-1 block font-medium">Password Awal (min 8 karakter)</span>
                        <Input bind:value={uPassword} type="password" class="h-8 text-xs" />
                    </label>
                {/if}
                <label class="block">
                    <span class="mb-1 block font-medium">Peran</span>
                    <select
                        bind:value={uRole}
                        disabled={isSelfEdit}
                        class="h-8 w-full rounded-md border border-stone-300 bg-white px-2 text-xs disabled:bg-stone-100"
                    >
                        <option value="EMPLOYEE_OFFICE">Staf Kantor</option>
                        <option value="EMPLOYEE_SALES">Tim Sales</option>
                        <option value="HR_ADMIN">HR Admin</option>
                    </select>
                </label>
                <label class="block">
                    <span class="mb-1 block font-medium">Kantor Cabang (Homebase)</span>
                    <select
                        bind:value={uOfficeId}
                        disabled={isSelfEdit}
                        class="h-8 w-full rounded-md border border-stone-300 bg-white px-2 text-xs disabled:bg-stone-100"
                    >
                        {#each offices as o (o.id)}
                            <option value={o.id}>{o.name}</option>
                        {/each}
                    </select>
                </label>
                {#if uRole !== 'EMPLOYEE_SALES'}
                    <label class="block">
                        <span class="mb-1 block font-medium">Pola Shift Default</span>
                        <select
                            bind:value={uPatternId}
                            disabled={isSelfEdit}
                            class="h-8 w-full rounded-md border border-stone-300 bg-white px-2 text-xs disabled:bg-stone-100"
                        >
                            <option value="">-- Pilih Pola --</option>
                            {#each activePatterns as p (p.id)}
                                <option value={p.id}>{p.name}</option>
                            {/each}
                        </select>
                    </label>
                {/if}
                <label class="block">
                    <span class="mb-1 block font-medium">Tanggal Masuk (Join Date)</span>
                    <Input bind:value={uJoinDate} type="date" disabled={isSelfEdit} class="h-8 text-xs" />
                </label>
                <label class="block">
                    <span class="mb-1 block font-medium">Organisasi / Divisi</span>
                    <Input bind:value={uOrg} class="h-8 text-xs" />
                </label>
                <label class="block">
                    <span class="mb-1 block font-medium">Jabatan</span>
                    <Input bind:value={uPos} class="h-8 text-xs" />
                </label>
                <label class="block">
                    <span class="mb-1 block font-medium">Job Level</span>
                    <Input bind:value={uLevel} class="h-8 text-xs" />
                </label>
                <label class="block">
                    <span class="mb-1 block font-medium">Status Karyawan</span>
                    <Input bind:value={uEmpStatus} class="h-8 text-xs" />
                </label>
                <label class="block md:col-span-2">
                    <span class="mb-1 block font-medium">Foto Profil Referensi (JPG/PNG/WebP max 2MB)</span>
                    <input
                        type="file"
                        accept="image/jpeg,image/png,image/webp"
                        disabled={isSelfEdit}
                        onchange={(e) => {
                            uPhotoFile = e.currentTarget.files?.[0] ?? null;
                        }}
                        class="block w-full text-xs text-stone-700"
                    />
                </label>
                {#if editingUser}
                    <label class="flex items-center gap-2 md:col-span-2">
                        <input type="checkbox" bind:checked={uActive} disabled={isSelfEdit} />
                        <span>Akun Aktif</span>
                    </label>
                {/if}
            </div>
            <div class="mt-4 flex justify-end gap-2">
                <Button size="sm" variant="outline" onclick={() => (userModalOpen = false)}>Batal</Button>
                <Button
                    size="sm"
                    disabled={busy}
                    class="bg-[#6F3E1D] text-white hover:bg-[#583015]"
                    onclick={submitUser}
                >
                    Simpan
                </Button>
            </div>
        </div>
    </div>
{/if}

<!-- MODAL: RESET PASSWORD -->
{#if resetTarget}
    <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" role="dialog" aria-modal="true">
        <div class="w-full max-w-sm rounded-lg border border-stone-200 bg-white p-4 shadow-lg">
            <h3 class="text-sm font-semibold">Reset Password: {resetTarget.nik}</h3>
            <p class="mt-1 text-xs text-stone-600">Karyawan wajib mengganti password saat login berikutnya.</p>
            <Input
                bind:value={resetPasswordVal}
                type="password"
                placeholder="Password baru (min 8 karakter)"
                class="mt-3 h-8 text-xs"
            />
            <div class="mt-4 flex justify-end gap-2">
                <Button size="sm" variant="outline" onclick={() => (resetTarget = null)}>Batal</Button>
                <Button
                    size="sm"
                    disabled={busy}
                    class="bg-[#6F3E1D] text-white hover:bg-[#583015]"
                    onclick={async () => {
                        if (!resetTarget) return;
                        const ok = await sendJson(
                            `/admin/users/${resetTarget.id}/reset-password`,
                            'POST',
                            { new_password: resetPasswordVal },
                            `Password ${resetTarget.nik} direset.`,
                        );
                        if (ok) resetTarget = null;
                    }}
                >
                    Reset Password
                </Button>
            </div>
        </div>
    </div>
{/if}

<!-- MODAL: ADJUST LEAVE BALANCE -->
{#if balanceTarget}
    {@const curBal = balanceTarget.leave_balances?.[0]}
    <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" role="dialog" aria-modal="true">
        <div class="w-full max-w-sm rounded-lg border border-stone-200 bg-white p-4 shadow-lg">
            <h3 class="text-sm font-semibold">Penyesuaian Saldo Cuti: {balanceTarget.nik}</h3>
            {#if curBal}
                <div class="mt-2 grid grid-cols-3 gap-1 rounded border border-stone-200 bg-stone-50 p-2 font-mono text-[11px] text-stone-700">
                    <span>Jatah: {curBal.base_quota}</span>
                    <span>Prorata: -{curBal.prorated_deduction}</span>
                    <span>Cutber: -{curBal.collective_leave_deduction}</span>
                    <span>Terpakai: {curBal.used_days}</span>
                    <span>Pending: {curBal.pending_days}</span>
                    <span>Adj: {curBal.adjustment_days}</span>
                </div>
                {#if balancePreview !== null}
                    <p class="mt-1 text-[11px] font-medium">
                        Sisa setelah penyesuaian: <span class="font-mono">{balancePreview.toFixed(1)}</span> Hari
                    </p>
                {/if}
                {#if Number(balYear) !== currentYear}
                    <p class="mt-1 text-[11px] text-yellow-800">
                        Rincian di atas adalah saldo tahun {currentYear}; tahun {balYear} dihitung ulang setelah disimpan.
                    </p>
                {/if}
            {/if}
            <div class="mt-3 space-y-2 text-xs">
                <label class="block">
                    <span class="mb-1 block font-medium">Tahun</span>
                    <Input bind:value={balYear} type="number" class="h-8 text-xs" />
                </label>
                <label class="block">
                    <span class="mb-1 block font-medium">Adjustment Days (-30 s/d 30)</span>
                    <Input bind:value={balAdj} type="number" step="0.5" class="h-8 text-xs" />
                </label>
                <label class="block">
                    <span class="mb-1 block font-medium">Alasan Penyesuaian</span>
                    <Input bind:value={balReason} placeholder="Minimal 3 karakter" class="h-8 text-xs" />
                </label>
            </div>
            <div class="mt-4 flex justify-end gap-2">
                <Button size="sm" variant="outline" onclick={() => (balanceTarget = null)}>Batal</Button>
                <Button
                    size="sm"
                    disabled={busy}
                    class="bg-[#6F3E1D] text-white hover:bg-[#583015]"
                    onclick={async () => {
                        if (!balanceTarget) return;
                        const ok = await sendJson(
                            `/admin/users/${balanceTarget.id}/leave-balance`,
                            'POST',
                            { year: Number(balYear), adjustment_days: Number(balAdj), reason: balReason },
                            `Saldo cuti ${balanceTarget.nik} diperbarui.`,
                        );
                        if (ok) balanceTarget = null;
                    }}
                >
                    Simpan Saldo
                </Button>
            </div>
        </div>
    </div>
{/if}

<!-- MODAL: OVERRIDE JADWAL HARIAN -->
{#if overrideTarget}
    <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" role="dialog" aria-modal="true">
        <div class="max-h-[90vh] w-full max-w-md overflow-y-auto rounded-lg border border-stone-200 bg-white p-4 shadow-lg">
            <h3 class="text-sm font-semibold">Atur Jadwal Khusus: {overrideTarget.nik}</h3>
            <div class="mt-3 space-y-2 text-xs">
                <label class="block">
                    <span class="mb-1 block font-medium">Tanggal (&gt;= Hari Ini)</span>
                    <Input bind:value={ovDate} type="date" class="h-8 text-xs" />
                </label>
                <label class="flex items-center gap-2">
                    <input type="checkbox" bind:checked={ovDayOff} />
                    <span>Tandai sebagai Hari Libur (OFF)</span>
                </label>
                {#if ovDayOff}
                    {#if overrideTarget.role === 'EMPLOYEE_OFFICE' || overrideTarget.role === 'EMPLOYEE_SALES'}
                        <label class="block">
                            <span class="mb-1 block font-medium">Tanggal Asal Ganti Hari (opsional)</span>
                            <Input bind:value={ovReplacementDate} type="date" class="h-8 text-xs" />
                            <span class="mt-1 block text-[11px] text-stone-500">
                                Pilih tanggal libur dengan kehadiran di hari libur yang sudah disetujui.
                            </span>
                        </label>
                        {#if overrideEligibleOrigins.length > 0}
                            <div class="rounded border border-stone-200 bg-stone-50 p-2">
                                <p class="mb-1 text-[11px] font-medium text-stone-700">
                                    Kehadiran hari libur yang memenuhi syarat:
                                </p>
                                <div class="flex flex-wrap gap-1.5">
                                    {#each overrideEligibleOrigins as origin (`${origin.source_type}-${origin.source_id}`)}
                                        <button
                                            type="button"
                                            class={`cursor-pointer rounded border px-2 py-1 font-mono text-[11px] tabular-nums transition ${
                                                ovReplacementDate === origin.source_date
                                                    ? 'border-[#6F3E1D] bg-[#6F3E1D] text-white'
                                                    : 'border-stone-300 bg-white text-stone-800 hover:border-[#6F3E1D]'
                                            }`}
                                            onclick={() => (ovReplacementDate = origin.source_date)}
                                        >
                                            {origin.source_date} (≤ {origin.deadline_date})
                                        </button>
                                    {/each}
                                </div>
                            </div>
                        {/if}
                    {/if}
                    <label class="block">
                        <span class="mb-1 block font-medium">Catatan / Alasan</span>
                        <Input bind:value={ovNotes} placeholder="Wajib untuk ganti hari" class="h-8 text-xs" />
                    </label>
                {/if}
                {#if !ovDayOff}
                    <label class="block">
                        <span class="mb-1 block font-medium">Shift</span>
                        <select bind:value={ovShiftId} class="h-8 w-full rounded-md border border-stone-300 px-2 text-xs">
                            {#each activeShifts as s (s.id)}
                                <option value={s.id}>{s.code} - {s.name}</option>
                            {/each}
                        </select>
                    </label>
                {/if}
            </div>
            {#if overrideUpcomingGantiHari.length > 0}
                <div class="mt-3 rounded border border-stone-200 bg-stone-50 p-2.5 text-xs">
                    <p class="mb-1.5 font-medium text-stone-700">Ganti Hari Mendatang</p>
                    <div class="space-y-1.5">
                        {#each overrideUpcomingGantiHari as item (item.id)}
                            <div class="flex items-center justify-between gap-2 rounded border border-stone-200 bg-white px-2 py-1.5">
                                <div class="min-w-0">
                                    <p class="font-mono text-[11px] font-medium text-stone-900 tabular-nums">
                                        {item.work_date}
                                        <span class="font-normal text-stone-500">
                                            ← {item.compensation_source_date}
                                        </span>
                                    </p>
                                    {#if item.notes}
                                        <p class="truncate text-[11px] text-stone-500">{item.notes}</p>
                                    {/if}
                                </div>
                                <Button
                                    size="sm"
                                    variant="outline"
                                    disabled={busy}
                                    class="h-6 shrink-0 cursor-pointer border-red-300 px-2 text-[11px] text-red-700 hover:bg-red-50"
                                    onclick={async () => {
                                        if (!overrideTarget) return;
                                        await sendJson(
                                            `/admin/users/${overrideTarget.id}/schedule-override/cancel`,
                                            'POST',
                                            { work_date: item.work_date },
                                            `Ganti hari ${overrideTarget.nik} pada ${item.work_date} dibatalkan.`,
                                        );
                                    }}
                                >
                                    Batalkan
                                </Button>
                            </div>
                        {/each}
                    </div>
                </div>
            {/if}
            <div class="mt-4 flex justify-end gap-2">
                <Button size="sm" variant="outline" onclick={() => (overrideTarget = null)}>Batal</Button>
                <Button
                    size="sm"
                    disabled={busy}
                    class="bg-[#6F3E1D] text-white hover:bg-[#583015]"
                    onclick={async () => {
                        if (!overrideTarget) return;
                        const ok = await sendJson(
                            `/admin/users/${overrideTarget.id}/schedule-override`,
                            'POST',
                            {
                                work_date: ovDate,
                                is_day_off: ovDayOff,
                                shift_id: ovDayOff || ovShiftId === '' ? null : Number(ovShiftId),
                                replacement_for_date: ovDayOff && ovReplacementDate !== '' ? ovReplacementDate : null,
                                notes: ovNotes.trim() || null,
                            },
                            `Jadwal ${overrideTarget.nik} pada ${ovDate} diperbarui.`,
                        );
                        if (ok) overrideTarget = null;
                    }}
                >
                    Simpan Override
                </Button>
            </div>
        </div>
    </div>
{/if}

<!-- MODAL: CSV IMPORT SALDO CUTI -->
{#if csvBalanceOpen}
    <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" role="dialog" aria-modal="true">
        <div class="max-h-[90vh] w-full max-w-xl overflow-y-auto rounded-lg border border-stone-200 bg-white p-4 shadow-lg">
            <h3 class="text-sm font-semibold">Impor CSV Saldo Awal Cuti</h3>
            <p class="mt-1 text-xs text-stone-600">
                Format: `nik,year,adjustment_days,notes` (kelipatan 0.5; 2 kolom `nik,adjustment_days` juga didukung). Jika ada satu baris salah, impor batal semua. NIK duplikat ditolak. Cuti terpakai dan cuti menunggu tetap aman.
            </p>
            <div class="mt-3 space-y-2 text-xs">
                <div class="flex items-center gap-2">
                    <label class="flex items-center gap-2 font-medium">
                        <span>Tahun:</span>
                        <Input bind:value={csvBalanceYear} type="number" class="h-8 w-28 text-xs" />
                    </label>
                    <input type="file" accept=".csv,text/csv" onchange={(e) => readCsvFile(e, (t) => (csvBalanceText = t))} />
                    <a
                        class="ml-auto font-medium text-[#6F3E1D] underline"
                        href="data:text/csv;charset=utf-8,nik%2Cyear%2Cadjustment_days%2Cnotes%0AOFF001%2C2026%2C2%2CSaldo%20awal%0ASLS001%2C2026%2C-1.5%2CKoreksi"
                        download="template-saldo-cuti.csv"
                    >
                        Unduh Template
                    </a>
                </div>
                <textarea bind:value={csvBalanceText} rows="4" class="w-full rounded-md border border-stone-300 p-2 font-mono text-xs"></textarea>
                <div class="max-h-44 overflow-y-auto rounded border border-stone-200">
                    <table class="w-full text-left text-xs">
                        <thead class="bg-stone-100">
                            <tr>
                                <th class="px-2 py-1">NIK</th>
                                <th class="px-2 py-1">Nama</th>
                                <th class="px-2 py-1">Tahun</th>
                                <th class="px-2 py-1">Adjustment</th>
                                <th class="px-2 py-1">Catatan</th>
                                <th class="px-2 py-1">Validasi</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-stone-200">
                            {#each csvBalanceRows as row, idx (idx)}
                                <tr>
                                    <td class="px-2 py-1 font-mono">{row.nik}</td>
                                    <td class="px-2 py-1">{row.userName}</td>
                                    <td class="px-2 py-1 font-mono tabular-nums">{row.year}</td>
                                    <td class="px-2 py-1 font-mono tabular-nums">{row.adjustment_days}</td>
                                    <td class="px-2 py-1 text-stone-600">{row.notes || '-'}</td>
                                    <td class="px-2 py-1">
                                        {#if row.unsafe}
                                            <span class="text-red-700">Formula berbahaya!</span>
                                        {:else if row.invalidYear}
                                            <span class="text-red-700">Tahun tidak valid</span>
                                        {:else if row.invalidAdj}
                                            <span class="text-red-700">Angka di luar -30..30</span>
                                        {:else if row.invalidStep}
                                            <span class="text-red-700">Harus kelipatan 0.5</span>
                                        {:else if row.ineligibleTenure}
                                            <span class="text-red-700">Masa kerja &lt; 1 thn (saldo + ditolak)</span>
                                        {:else if row.duplicateNik}
                                            <span class="text-red-700">NIK duplikat</span>
                                        {:else if row.notFound}
                                            <span class="text-red-700">NIK tidak ditemukan</span>
                                        {:else if row.isSelf}
                                            <span class="text-yellow-800">Akun sendiri (dilewati)</span>
                                        {:else}
                                            <span class="text-green-700">Siap</span>
                                        {/if}
                                    </td>
                                </tr>
                            {/each}
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="mt-4 flex justify-end gap-2">
                <Button size="sm" variant="outline" onclick={() => (csvBalanceOpen = false)}>Batal</Button>
                <Button
                    size="sm"
                    disabled={busy || csvBalanceRows.length === 0 || csvBalanceRows.some((r) => r.hasError)}
                    class="bg-[#6F3E1D] text-white hover:bg-[#583015]"
                    onclick={async () => {
                        const ok = await sendJson(
                            '/admin/users/leave-balances/import',
                            'POST',
                            {
                                year: Number(csvBalanceYear),
                                rows: csvBalanceRows.map((r) => ({
                                    nik: r.nik,
                                    year: r.year,
                                    adjustment_days: r.adjustment_days,
                                    notes: r.notes || null,
                                })),
                            },
                            'Saldo cuti CSV berhasil diimpor.',
                        );
                        if (ok) csvBalanceOpen = false;
                    }}
                >
                    Proses Impor
                </Button>
            </div>
        </div>
    </div>
{/if}

<!-- MODAL: SHIFT -->
{#if shiftModalOpen}
    <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" role="dialog" aria-modal="true">
        <div class="w-full max-w-md rounded-lg border border-stone-200 bg-white p-4 shadow-lg">
            <h3 class="text-sm font-semibold">{editingShift ? `Edit Shift ${editingShift.code}` : 'Tambah Shift Baru'}</h3>
            <div class="mt-3 grid gap-2.5 text-xs md:grid-cols-2">
                <label class="block">
                    <span class="mb-1 block font-medium">Kode Shift</span>
                    <Input bind:value={sCode} placeholder="JWD" class="h-8 text-xs uppercase" />
                </label>
                <label class="block">
                    <span class="mb-1 block font-medium">Nama Shift</span>
                    <Input bind:value={sName} placeholder="Jakarta Weekday" class="h-8 text-xs" />
                </label>
                <label class="block">
                    <span class="mb-1 block font-medium">Jam Masuk</span>
                    <Input bind:value={sStart} type="time" class="h-8 text-xs" />
                </label>
                <label class="block">
                    <span class="mb-1 block font-medium">Jam Pulang</span>
                    <Input bind:value={sEnd} type="time" class="h-8 text-xs" />
                </label>
                <label class="block">
                    <span class="mb-1 block font-medium">Toleransi Telat (Menit)</span>
                    <Input bind:value={sGrace} type="number" class="h-8 text-xs" />
                </label>
                <label class="block">
                    <span class="mb-1 block font-medium">Batas Tutup Otomatis (Jam)</span>
                    <Input bind:value={sAutoClose} type="number" class="h-8 text-xs" />
                </label>
            </div>
            <div class="mt-4 flex justify-end gap-2">
                <Button size="sm" variant="outline" onclick={() => (shiftModalOpen = false)}>Batal</Button>
                <Button size="sm" disabled={busy} class="bg-[#6F3E1D] text-white hover:bg-[#583015]" onclick={submitShift}>
                    Simpan
                </Button>
            </div>
        </div>
    </div>
{/if}

<!-- MODAL: SHIFT PATTERN -->
{#if patternModalOpen}
    <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" role="dialog" aria-modal="true">
        <div class="w-full max-w-md rounded-lg border border-stone-200 bg-white p-4 shadow-lg">
            <h3 class="text-sm font-semibold">{editingPattern ? 'Edit Pola Jadwal' : 'Tambah Pola Jadwal'}</h3>
            <div class="mt-3 space-y-2 text-xs">
                <label class="block">
                    <span class="mb-1 block font-medium">Nama Pola</span>
                    <Input bind:value={pName} placeholder="Office Jakarta 5 Hari" class="h-8 text-xs" />
                </label>
                <div class="grid grid-cols-2 gap-2">
                    {#each dayLabels as day, i (day)}
                        <label class="block">
                            <span class="mb-0.5 block font-medium">{day}</span>
                            <select bind:value={pDays[i]} class="h-8 w-full rounded-md border border-stone-300 bg-white px-2 text-xs">
                                <option value="">LIBUR / OFF</option>
                                {#each activeShifts as s (s.id)}
                                    <option value={s.id}>{s.code} ({s.start_time.slice(0, 5)}-{s.end_time.slice(0, 5)})</option>
                                {/each}
                            </select>
                        </label>
                    {/each}
                </div>
            </div>
            <div class="mt-4 flex justify-end gap-2">
                <Button size="sm" variant="outline" onclick={() => (patternModalOpen = false)}>Batal</Button>
                <Button size="sm" disabled={busy} class="bg-[#6F3E1D] text-white hover:bg-[#583015]" onclick={submitPattern}>
                    Simpan Pola
                </Button>
            </div>
        </div>
    </div>
{/if}

<!-- MODAL: LOCATION (OFFICE / OUTLET) -->
{#if locModalOpen}
    <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" role="dialog" aria-modal="true">
        <div class="max-h-[90vh] w-full max-w-lg overflow-y-auto rounded-lg border border-stone-200 bg-white p-4 shadow-lg">
            <h3 class="text-sm font-semibold">
                {editingLoc ? `Edit ${lType === 'OFFICE' ? 'Kantor' : 'Outlet'}` : `Tambah ${lType === 'OFFICE' ? 'Kantor' : 'Outlet'}`}
            </h3>
            <div class="mt-3 grid gap-2.5 text-xs md:grid-cols-2">
                <label class="block">
                    <span class="mb-1 block font-medium">Kode {lType === 'OFFICE' ? '(Wajib)' : '(Opsional)'}</span>
                    <Input bind:value={lCode} placeholder={lType === 'OFFICE' ? 'JKT' : 'OUT-001'} class="h-8 text-xs uppercase" />
                </label>
                <label class="block">
                    <span class="mb-1 block font-medium">Nama Lokasi</span>
                    <Input bind:value={lName} class="h-8 text-xs" />
                </label>
                <label class="block md:col-span-2">
                    <span class="mb-1 block font-medium">Alamat</span>
                    <Input bind:value={lAddress} class="h-8 text-xs" />
                </label>
                <div class="flex items-center justify-between md:col-span-2">
                    <span class="font-medium text-stone-700">Koordinat Lokasi</span>
                    <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        disabled={locGpsBusy}
                        class="h-7 border-stone-300 px-2 text-[11px]"
                        onclick={fillCurrentGpsForLocation}
                    >
                        <MapPin class="mr-1 size-3" />
                        {locGpsBusy ? 'Mengambil GPS...' : 'Ambil GPS Saat Ini'}
                    </Button>
                </div>
                <label class="block">
                    <span class="mb-1 block font-medium">Latitude</span>
                    <Input bind:value={lLat} type="number" step="0.000001" class="h-8 text-xs font-mono" />
                </label>
                <label class="block">
                    <span class="mb-1 block font-medium">Longitude</span>
                    <Input bind:value={lLng} type="number" step="0.000001" class="h-8 text-xs font-mono" />
                </label>
                <label class="block">
                    <span class="mb-1 block font-medium">Radius Geofence (meter)</span>
                    <Input bind:value={lRadius} type="number" class="h-8 text-xs font-mono" />
                </label>
                <label class="block">
                    <span class="mb-1 block font-medium">Timezone</span>
                    <select bind:value={lTimezone} class="h-8 w-full rounded-md border border-stone-300 bg-white px-2 text-xs">
                        <option value="Asia/Jakarta">WIB (Asia/Jakarta)</option>
                        <option value="Asia/Makassar">WITA (Asia/Makassar)</option>
                        <option value="Asia/Jayapura">WIT (Asia/Jayapura)</option>
                    </select>
                </label>
                <div class="md:col-span-2">
                    <p class="mb-1 font-medium">Hari Kerja Cabang</p>
                    <div class="flex flex-wrap gap-2">
                        {#each dayLabels as day, i (day)}
                            <label class="flex items-center gap-1">
                                <input type="checkbox" checked={lWorkDays.includes(i + 1)} onchange={() => toggleWorkDay(i + 1)} />
                                <span>{day.slice(0, 3)}</span>
                            </label>
                        {/each}
                    </div>
                </div>
            </div>
            <div class="mt-4 flex justify-end gap-2">
                <Button size="sm" variant="outline" onclick={() => (locModalOpen = false)}>Batal</Button>
                <Button size="sm" disabled={busy} class="bg-[#6F3E1D] text-white hover:bg-[#583015]" onclick={submitLocation}>
                    Simpan
                </Button>
            </div>
        </div>
    </div>
{/if}

<!-- MODAL: VERIFY NOO -->
{#if nooTarget}
    <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" role="dialog" aria-modal="true">
        <div class="w-full max-w-md rounded-lg border border-stone-200 bg-white p-4 shadow-lg">
            <h3 class="text-sm font-semibold">Verifikasi Toko Baru (NOO): {nooTarget.name}</h3>
            <p class="mt-1 text-xs text-stone-600">{nooTarget.address} ({nooTarget.latitude}, {nooTarget.longitude})</p>
            <div class="mt-3 space-y-2 text-xs">
                <div class="grid grid-cols-2 gap-2">
                    <label class="block">
                        <span class="mb-1 block font-medium">Kode Toko</span>
                        <Input bind:value={nooCode} class="h-8 text-xs uppercase" />
                    </label>
                    <label class="block">
                        <span class="mb-1 block font-medium">Radius (m)</span>
                        <Input bind:value={nooRadius} type="number" class="h-8 text-xs font-mono" />
                    </label>
                </div>
                <label class="block">
                    <span class="mb-1 block font-medium">Timezone</span>
                    <select bind:value={nooTimezone} class="h-8 w-full rounded-md border border-stone-300 bg-white px-2 text-xs">
                        <option value="Asia/Jakarta">WIB (Asia/Jakarta)</option>
                        <option value="Asia/Makassar">WITA (Asia/Makassar)</option>
                        <option value="Asia/Jayapura">WIT (Asia/Jayapura)</option>
                    </select>
                </label>
                <label class="block">
                    <span class="mb-1 block font-medium">Alasan Penolakan (wajib jika ditolak)</span>
                    <Input bind:value={nooRejectReason} placeholder="Contoh: Duplikat toko eksisting" class="h-8 text-xs" />
                </label>
            </div>
            <div class="mt-4 flex justify-end gap-2">
                <Button size="sm" variant="outline" onclick={() => (nooTarget = null)}>Batal</Button>
                <Button size="sm" disabled={busy} class="bg-red-700 text-white hover:bg-red-800" onclick={() => submitNoo('INACTIVE')}>
                    Tolak Toko Baru
                </Button>
                <Button size="sm" disabled={busy} class="bg-green-700 text-white hover:bg-green-800" onclick={() => submitNoo('ACTIVE')}>
                    Setujui &amp; Aktifkan
                </Button>
            </div>
        </div>
    </div>
{/if}

<!-- MODAL: CSV IMPORT OUTLET -->
{#if csvOutletOpen}
    <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" role="dialog" aria-modal="true">
        <div class="max-h-[90vh] w-full max-w-2xl overflow-y-auto rounded-lg border border-stone-200 bg-white p-4 shadow-lg">
            <h3 class="text-sm font-semibold">Impor CSV Daftar Outlet</h3>
            <p class="mt-1 text-xs text-stone-600">
                Format: `code,name,address,latitude,longitude,radius_m,timezone` per baris. Jika ada satu baris salah, impor batal semua.
            </p>
            <div class="mt-3 space-y-2 text-xs">
                <input type="file" accept=".csv,text/csv" onchange={(e) => readCsvFile(e, (t) => (csvOutletText = t))} />
                <textarea bind:value={csvOutletText} rows="4" class="w-full rounded-md border border-stone-300 p-2 font-mono text-xs"></textarea>
                <div class="max-h-44 overflow-y-auto rounded border border-stone-200">
                    <table class="w-full text-left text-xs">
                        <thead class="bg-stone-100">
                            <tr><th class="px-2 py-1">Kode</th><th class="px-2 py-1">Nama</th><th class="px-2 py-1">Lat/Lng</th><th class="px-2 py-1">Validasi</th></tr>
                        </thead>
                        <tbody class="divide-y divide-stone-200">
                            {#each csvOutletRows as row, idx (idx)}
                                <tr>
                                    <td class="px-2 py-1 font-mono">{row.code}</td>
                                    <td class="px-2 py-1">{row.name}</td>
                                    <td class="px-2 py-1 font-mono">{row.latitude}, {row.longitude}</td>
                                    <td class="px-2 py-1">
                                        {#if row.unsafe}
                                            <span class="text-red-700">Formula berbahaya!</span>
                                        {:else}
                                            <span class="text-green-700">Siap</span>
                                        {/if}
                                    </td>
                                </tr>
                            {/each}
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="mt-4 flex justify-end gap-2">
                <Button size="sm" variant="outline" onclick={() => (csvOutletOpen = false)}>Batal</Button>
                <Button
                    size="sm"
                    disabled={busy || csvOutletRows.length === 0 || csvOutletRows.some((r) => r.unsafe)}
                    class="bg-[#6F3E1D] text-white hover:bg-[#583015]"
                    onclick={async () => {
                        const ok = await sendJson(
                            '/admin/locations/outlets/import',
                            'POST',
                            { rows: csvOutletRows },
                            'Daftar outlet CSV berhasil diimpor.',
                        );
                        if (ok) csvOutletOpen = false;
                    }}
                >
                    Proses Impor
                </Button>
            </div>
        </div>
    </div>
{/if}

<!-- MODAL: ACTIVITY CATEGORY -->
{#if catModalOpen}
    <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" role="dialog" aria-modal="true">
        <div class="w-full max-w-sm rounded-lg border border-stone-200 bg-white p-4 shadow-lg">
            <h3 class="text-sm font-semibold">{editingCat ? 'Edit Kategori Aktivitas' : 'Tambah Kategori Aktivitas'}</h3>
            <div class="mt-3 space-y-2.5 text-xs">
                <label class="block">
                    <span class="mb-1 block font-medium">Nama Kategori</span>
                    <Input bind:value={cName} placeholder="Contoh: Kunjungan Rutin" class="h-8 text-xs" />
                </label>
                <label class="flex items-center gap-2">
                    <input type="checkbox" bind:checked={cExempt} />
                    <span>Bebas flag Short Duration (&lt;10 menit)</span>
                </label>
            </div>
            <div class="mt-4 flex justify-end gap-2">
                <Button size="sm" variant="outline" onclick={() => (catModalOpen = false)}>Batal</Button>
                <Button size="sm" disabled={busy} class="bg-[#6F3E1D] text-white hover:bg-[#583015]" onclick={submitCategory}>
                    Simpan
                </Button>
            </div>
        </div>
    </div>
{/if}

<!-- MODAL: HOLIDAY -->
{#if holidayModalOpen}
    <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" role="dialog" aria-modal="true">
        <div class="w-full max-w-sm rounded-lg border border-stone-200 bg-white p-4 shadow-lg">
            <h3 class="text-sm font-semibold">
                {editingHoliday ? 'Edit Hari Libur / Cuti Bersama' : 'Tambah Hari Libur / Cuti Bersama'}
            </h3>
            <div class="mt-3 space-y-2 text-xs">
                <label class="block">
                    <span class="mb-1 block font-medium">Tanggal</span>
                    <Input bind:value={hDate} type="date" class="h-8 text-xs" />
                </label>
                <label class="block">
                    <span class="mb-1 block font-medium">Tipe Libur</span>
                    <select bind:value={hType} class="h-8 w-full rounded-md border border-stone-300 bg-white px-2 text-xs">
                        <option value="NATIONAL">NATIONAL (Nasional)</option>
                        <option value="LOCAL">LOCAL (Cabang Tertentu)</option>
                        <option value="COLLECTIVE_LEAVE">COLLECTIVE_LEAVE (Cuti Bersama)</option>
                    </select>
                </label>
                {#if hType === 'LOCAL'}
                    <label class="block">
                        <span class="mb-1 block font-medium">Pilih Cabang</span>
                        <select bind:value={hLocationId} class="h-8 w-full rounded-md border border-stone-300 bg-white px-2 text-xs">
                            {#each offices as o (o.id)}
                                <option value={o.id}>{o.name}</option>
                            {/each}
                        </select>
                    </label>
                {/if}
                <label class="block">
                    <span class="mb-1 block font-medium">Keterangan</span>
                    <Input bind:value={hDesc} placeholder="Contoh: Cuti Bersama Idul Fitri" class="h-8 text-xs" />
                </label>
            </div>
            <div class="mt-4 flex justify-end gap-2">
                <Button size="sm" variant="outline" onclick={() => (holidayModalOpen = false)}>Batal</Button>
                <Button size="sm" disabled={busy} class="bg-[#6F3E1D] text-white hover:bg-[#583015]" onclick={submitHoliday}>
                    Simpan
                </Button>
            </div>
        </div>
    </div>
{/if}

<!-- MODAL: DELETE HOLIDAY CONFIRM -->
{#if deleteHolidayTarget}
    <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" role="dialog" aria-modal="true">
        <div class="w-full max-w-sm rounded-lg border border-stone-200 bg-white p-4 shadow-lg">
            <h3 class="text-sm font-semibold">Hapus Hari Libur?</h3>
            <p class="mt-1 text-xs text-stone-600">
                Tanggal <span class="font-mono font-semibold">{deleteHolidayTarget.holiday_date.slice(0, 10)}</span>
                ({deleteHolidayTarget.description}) akan dihapus dan pengajuan cuti/saldo terkait akan dihitung ulang.
            </p>
            <div class="mt-4 flex justify-end gap-2">
                <Button size="sm" variant="outline" onclick={() => (deleteHolidayTarget = null)}>Batal</Button>
                <Button
                    size="sm"
                    disabled={busy}
                    class="bg-red-700 text-white hover:bg-red-800"
                    onclick={async () => {
                        if (!deleteHolidayTarget) return;
                        const ok = await sendJson(
                            `/admin/holidays/${deleteHolidayTarget.id}`,
                            'DELETE',
                            {},
                            holidaySuccessText(
                                'Hari libur dihapus & saldo/cuti disinkronkan.',
                            ),
                        );
                        if (ok) deleteHolidayTarget = null;
                    }}
                >
                    Hapus
                </Button>
            </div>
        </div>
    </div>
{/if}
