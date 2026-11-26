<script module lang="ts">
    import { edit } from '@/routes/security';

    export const layout = {
        breadcrumbs: [
            {
                title: 'Security settings',
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

    let { passwordRules }: { passwordRules: string } = $props();
</script>

<AppHead title="Keamanan Akun" />

<h1 class="sr-only">Keamanan Akun</h1>

<div class="space-y-6">
    <Heading
        variant="small"
        title="Ubah Password"
        description="Gunakan password yang kuat dan unik agar akun tetap aman."
    />

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
                    class="min-h-11"
                >
                    Simpan Password
                </Button>
            </div>
        {/snippet}
    </Form>
</div>


