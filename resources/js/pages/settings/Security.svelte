<script module lang="ts">
    import { edit } from '@/routes/security';

    export const layout = {
        breadcrumbs: [
            {
                title: 'Keamanan Akun',
                href: edit(),
            },
        ],
    };
</script>

<script lang="ts">
    import {
        Form ,
    } from '@inertiajs/svelte';
    import SecurityController from '@/actions/App/Http/Controllers/Settings/SecurityController';
    import AppHead from '@/components/AppHead.svelte';
    import Heading from '@/components/Heading.svelte';
    import InputError from '@/components/InputError.svelte';
    import PasswordInput from '@/components/PasswordInput.svelte';
    import { Button } from '@/components/ui/button';
    import { Label } from '@/components/ui/label';

    let {
        passwordRules,
        mustChangePassword = false,
    }: {
        passwordRules: string;
        mustChangePassword?: boolean;
    } = $props();
</script>

<AppHead title="Keamanan Akun" />

<h1 class="sr-only">Keamanan Akun</h1>

<div class="space-y-6">
    <Heading
        variant="small"
        title="Ubah Password"
        description="Pilih password yang kuat agar akun Anda aman."
    />

    {#if mustChangePassword}
        <div
            role="alert"
            class="rounded-lg border border-yellow-300 bg-yellow-50 p-3 text-xs text-yellow-800"
        >
            Ganti password Anda dulu sebelum masuk ke menu utama.
        </div>
    {/if}

    <Form
        {...SecurityController.update.form()}
        class="space-y-6"
        options={{ preserveScroll: true }}
        resetOnSuccess
        resetOnError={['password', 'password_confirmation', 'current_password']}
    >
        {#snippet children({ errors, processing })}
            <div class="grid gap-2">
                <Label for="current_password">Password saat ini</Label>
                <PasswordInput
                    id="current_password"
                    name="current_password"
                    class="mt-1 block h-11 w-full"
                    autocomplete="current-password"
                    placeholder="Password saat ini"
                />
                <InputError message={errors.current_password} />
            </div>

            <div class="grid gap-2">
                <Label for="password">Password baru</Label>
                <PasswordInput
                    id="password"
                    name="password"
                    class="mt-1 block h-11 w-full"
                    autocomplete="new-password"
                    placeholder="Password baru"
                    passwordrules={passwordRules}
                />
                <InputError message={errors.password} />
            </div>

            <div class="grid gap-2">
                <Label for="password_confirmation">Konfirmasi password</Label>
                <PasswordInput
                    id="password_confirmation"
                    name="password_confirmation"
                    class="mt-1 block h-11 w-full"
                    autocomplete="new-password"
                    placeholder="Konfirmasi password"
                    passwordrules={passwordRules}
                />
                <InputError message={errors.password_confirmation} />
            </div>

            <div class="flex items-center gap-4">
                <Button
                    type="submit"
                    disabled={processing}
                    data-test="update-password-button"
                    class="min-h-12 md:min-h-9"
                >
                    Simpan Password
                </Button>
            </div>
        {/snippet}
    </Form>
</div>


