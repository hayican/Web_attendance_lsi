<script module lang="ts">
    export const layout = {
        title: 'Masuk ke Akun',
        description: 'Masuk dengan NIK atau email dan password Anda',
    };
</script>

<script lang="ts">
    import { Form } from '@inertiajs/svelte';
    import AppHead from '@/components/AppHead.svelte';
    import InputError from '@/components/InputError.svelte';
    import PasswordInput from '@/components/PasswordInput.svelte';
    import { Button } from '@/components/ui/button';
    import { Checkbox } from '@/components/ui/checkbox';
    import { Input } from '@/components/ui/input';
    import { Label } from '@/components/ui/label';
    import { Spinner } from '@/components/ui/spinner';
    import { store } from '@/routes/login';

    let {
        status = '',
    }: {
        status?: string;
    } = $props();
</script>

<AppHead title="Masuk" />

{#if status}
    <div class="mb-4 text-center text-sm font-medium text-green-600">
        {status}
    </div>
{/if}


<Form
    {...store.form()}
    resetOnSuccess={['password']}
    class="flex flex-col gap-6"
>
    {#snippet children({ errors, processing })}
        <div class="grid gap-6">
            <div class="grid gap-2">
                <Label for="email">NIK atau Email</Label>
                <Input
                    id="email"
                    type="text"
                    name="email"
                    required
                    autocomplete="email"
                    placeholder="NIK atau email@lsi.co.id"
                />
                <InputError message={errors.email} />
            </div>

            <div class="grid gap-2">
                <Label for="password">Password</Label>
                <PasswordInput
                    id="password"
                    name="password"
                    required
                    autocomplete="current-password"
                    placeholder="Password"
                />
                <InputError message={errors.password} />
                <p class="text-xs text-stone-600">
                    Lupa password? Hubungi HR untuk reset.
                </p>
            </div>

            <div class="flex items-center justify-between">
                <Label for="remember" class="flex items-center space-x-3">
                    <Checkbox id="remember" name="remember" />
                    <span>Ingat saya</span>
                </Label>
            </div>

            <Button
                type="submit"
                class="mt-4 min-h-12 w-full bg-[#6F3E1D] text-white hover:bg-[#583015]"
                disabled={processing}
                data-test="login-button"
            >
                {#if processing}<Spinner />{/if}
                Masuk
            </Button>
        </div>
    {/snippet}
</Form>
