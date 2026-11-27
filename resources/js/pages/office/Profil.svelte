<script module lang="ts">
    import { edit } from '@/routes/profile';

    export const layout = {
        breadcrumbs: [
            {
                title: 'Profil',
                href: edit(),
            },
        ],
    };
</script>

<script lang="ts">
    import { Form, Link, page, router } from '@inertiajs/svelte';
    import LogOut from '@lucide/svelte/icons/log-out';
    import Palette from '@lucide/svelte/icons/palette';
    import Shield from '@lucide/svelte/icons/shield';
    import ProfileController from '@/actions/App/Http/Controllers/Settings/ProfileController';
    import AppHead from '@/components/AppHead.svelte';
    import Heading from '@/components/Heading.svelte';
    import InputError from '@/components/InputError.svelte';
    import TextLink from '@/components/TextLink.svelte';
    import {
        Avatar,
        AvatarFallback,
        AvatarImage,
    } from '@/components/ui/avatar';
    import { Button } from '@/components/ui/button';
    import { Input } from '@/components/ui/input';
    import { Label } from '@/components/ui/label';
    import { getInitials } from '@/lib/initials';
    import { toUrl } from '@/lib/utils';
    import { edit as editAppearance } from '@/routes/appearance';
    import { edit as editSecurity } from '@/routes/security';
    import { send } from '@/routes/verification';

    type ProfileUser = {
        name: string;
        email: string;
        nik?: string | null;
        role?: string | null;
        join_date?: string | null;
        organization?: string | null;
        job_position?: string | null;
        job_level?: string | null;
        employment_status?: string | null;
        consent_accepted_at?: string | null;
        email_verified_at?: string | null;
    };

    const user = $derived(page.props.auth.user as ProfileUser);
    const office = $derived(
        (page.props.office as { name?: string } | undefined) ?? null,
    );
    const profilePhotoUrl = $derived(
        (page.props.profilePhotoUrl as string | null | undefined) ?? null,
    );
    const isHrAdmin = $derived(user.role === 'HR_ADMIN');
    const initials = $derived(getInitials(user.name));

    function roleLabel(role?: string | null): string {
        switch (role) {
            case 'EMPLOYEE_OFFICE':
                return 'Staf Kantor';
            case 'EMPLOYEE_SALES':
                return 'Tim Sales';
            case 'HR_ADMIN':
                return 'HR Admin';
            default:
                return 'Karyawan';
        }
    }

    function formatDate(value?: string | null): string {
        if (!value) {
            return '—';
        }

        const [year, month, day] = value.slice(0, 10).split('-');

        return year && month && day ? `${day}-${month}-${year}` : value;
    }

    function logout() {
        router.post('/logout');
    }
</script>

{#snippet profileForm()}
    <Form
        {...ProfileController.update.form()}
        class="space-y-3"
        options={{ preserveScroll: true }}
    >
        {#snippet children({ errors, processing, recentlySuccessful })}
            <div class="grid gap-1.5">
                <Label for="name" class="text-xs text-stone-700">
                    Nama lengkap
                </Label>
                <Input
                    id="name"
                    name="name"
                    class="h-11"
                    value={user.name}
                    required
                    autocomplete="name"
                    placeholder="Nama lengkap"
                />
                <InputError message={errors.name} />
            </div>

            <div class="grid gap-1.5">
                <Label for="email" class="text-xs text-stone-700">
                    Alamat email
                </Label>
                <Input
                    id="email"
                    type="email"
                    name="email"
                    class="h-11"
                    value={user.email}
                    required
                    autocomplete="username"
                    placeholder="Alamat email"
                />
                <InputError message={errors.email} />
            </div>

            {#if !user.email_verified_at}
                <p class="text-[11px] leading-snug text-yellow-800">
                    Email belum terverifikasi.
                    <TextLink href={send()} as="button">
                        Kirim ulang tautan verifikasi
                    </TextLink>
                    .
                </p>

                {#if page.props.status === 'verification-link-sent'}
                    <p class="text-[11px] font-medium text-green-700">
                        Tautan verifikasi baru telah dikirim ke email Anda.
                    </p>
                {/if}
            {/if}

            <Button
                type="submit"
                class="min-h-11 w-full"
                disabled={processing}
                data-test="update-profile-button"
            >
                {processing ? 'Menyimpan…' : 'Simpan Perubahan'}
            </Button>

            {#if recentlySuccessful}
                <p class="text-[11px] font-medium text-green-700">
                    Profil berhasil diperbarui.
                </p>
            {/if}
        {/snippet}
    </Form>
{/snippet}

<AppHead title="Profil Saya" />

<h1 class="sr-only">Profil Saya</h1>

{#if isHrAdmin}
    <div class="flex flex-col space-y-6">
        <Heading
            variant="small"
            title="Profil"
            description="Perbarui nama dan alamat email akun Anda"
        />

        {@render profileForm()}

    </div>
{:else}
    <div class="flex flex-col gap-3">
        <section
            class="rounded-xl border border-stone-200 bg-white p-3 shadow-xs"
        >
            <div class="flex items-center gap-3">
                <Avatar class="size-14 border border-stone-200">
                    {#if profilePhotoUrl}
                        <AvatarImage src={profilePhotoUrl} alt={user.name} />
                    {/if}
                    <AvatarFallback class="text-sm font-semibold">
                        {initials}
                    </AvatarFallback>
                </Avatar>

                <div class="min-w-0">
                    <p class="truncate text-sm font-semibold text-stone-900">
                        {user.name}
                    </p>
                    <p class="font-mono text-[11px] text-stone-500">
                        {user.nik ?? '—'}
                    </p>
                    <span
                        class="mt-1 inline-flex rounded border border-primary/30 bg-accent px-1.5 py-0.5 text-[10px] font-medium text-accent-foreground"
                    >
                        {roleLabel(user.role)}
                    </span>
                </div>
            </div>

            <dl class="mt-3 grid grid-cols-2 gap-x-3 gap-y-2 text-[11px]">
                <div>
                    <dt class="text-stone-500">Cabang</dt>
                    <dd class="mt-0.5 font-medium text-stone-900">
                        {office?.name ?? '—'}
                    </dd>
                </div>
                <div>
                    <dt class="text-stone-500">Bergabung</dt>
                    <dd class="mt-0.5 font-mono text-stone-900">
                        {formatDate(user.join_date)}
                    </dd>
                </div>
                <div>
                    <dt class="text-stone-500">Organisasi</dt>
                    <dd class="mt-0.5 text-stone-900">
                        {user.organization ?? '—'}
                    </dd>
                </div>
                <div>
                    <dt class="text-stone-500">Jabatan</dt>
                    <dd class="mt-0.5 text-stone-900">
                        {user.job_position ?? '—'}
                    </dd>
                </div>
                <div>
                    <dt class="text-stone-500">Level</dt>
                    <dd class="mt-0.5 text-stone-900">
                        {user.job_level ?? '—'}
                    </dd>
                </div>
                <div>
                    <dt class="text-stone-500">Status Kepegawaian</dt>
                    <dd class="mt-0.5 text-stone-900">
                        {user.employment_status ?? '—'}
                    </dd>
                </div>
                <div class="col-span-2">
                    <dt class="text-stone-500">Persetujuan UU PDP</dt>
                    <dd class="mt-0.5 font-mono text-stone-900">
                        {formatDate(user.consent_accepted_at)}
                    </dd>
                </div>
            </dl>
        </section>

        <section
            class="rounded-xl border border-stone-200 bg-white p-3 shadow-xs"
        >
            <h2 class="text-sm font-semibold text-stone-900">Data Akun</h2>
            <p class="mt-0.5 text-[11px] leading-snug text-stone-500">
                Perubahan alamat email akan meminta verifikasi ulang.
            </p>

            <div class="mt-3">
                {@render profileForm()}
            </div>
        </section>

        <section
            class="rounded-xl border border-stone-200 bg-white p-3 shadow-xs"
        >
            <h2 class="text-sm font-semibold text-stone-900">
                Keamanan & Tampilan
            </h2>

            <div class="mt-2 divide-y divide-stone-100">
                <Link
                    href={toUrl(editSecurity())}
                    class="flex min-h-12 items-center justify-between gap-2 text-sm text-stone-800"
                >
                    <span class="flex items-center gap-2">
                        <Shield class="size-4 text-stone-500" />
                        Keamanan Akun
                    </span>
                    <span class="text-[11px] text-stone-500">
                        Password & 2FA
                    </span>
                </Link>

                <Link
                    href={toUrl(editAppearance())}
                    class="flex min-h-12 items-center justify-between gap-2 text-sm text-stone-800"
                >
                    <span class="flex items-center gap-2">
                        <Palette class="size-4 text-stone-500" />
                        Tampilan
                    </span>
                    <span class="text-[11px] text-stone-500">
                        Mode terang / gelap
                    </span>
                </Link>
            </div>
        </section>

        <Button
            type="button"
            variant="outline"
            class="min-h-12 w-full text-red-700 hover:text-red-800"
            onclick={logout}
        >
            <LogOut class="size-4" />
            Keluar dari Akun
        </Button>
    </div>
{/if}
