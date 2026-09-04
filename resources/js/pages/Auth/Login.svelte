<script lang="ts">
    import { useForm } from '@inertiajs/svelte';
    import { Eye, EyeOff } from '@lucide/svelte';

    const uid = $props.id();
    let showPassword = $state(false);

    const form = useForm({ email: '', password: '', remember: false });

    function submit(e: SubmitEvent) {
        e.preventDefault();
        form.post('/workspace/login', {
            onFinish: () => form.reset('password'),
        });
    }
</script>

<svelte:head><title>Sign in · Workspace</title></svelte:head>

<main
    class="flex min-h-screen items-center justify-center bg-bg px-4 py-12 text-fg"
>
    <div class="w-full max-w-[360px]">
        <div class="mb-6 flex items-center gap-2.5">
            <span
                class="grid h-7 w-7 place-items-center rounded-md bg-accent text-xs font-semibold text-white"
                >W</span
            >
            <div>
                <h1 class="text-[15px] font-semibold">Sign in to Workspace</h1>
            </div>
        </div>

        <form onsubmit={submit} class="panel flex flex-col gap-4 p-5">
            <div class="flex flex-col gap-1.5">
                <label for="{uid}-email" class="label">Email address</label>
                <input
                    id="{uid}-email"
                    type="email"
                    autocomplete="username"
                    inputmode="email"
                    bind:value={form.email}
                    required
                    class="input h-9"
                />
                {#if form.errors.email}<p class="text-xs text-danger">
                        {form.errors.email}
                    </p>{/if}
            </div>

            <div class="flex flex-col gap-1.5">
                <label for="{uid}-password" class="label">Password</label>
                <div class="relative">
                    <input
                        id="{uid}-password"
                        type={showPassword ? 'text' : 'password'}
                        autocomplete="current-password"
                        bind:value={form.password}
                        required
                        class="input h-9 pr-10"
                    />
                    <button
                        type="button"
                        onclick={() => (showPassword = !showPassword)}
                        aria-label={showPassword
                            ? 'Hide password'
                            : 'Show password'}
                        class="absolute inset-y-0 right-0 flex items-center px-3 text-fg-faint hover:text-fg"
                    >
                        {#if showPassword}<EyeOff class="h-4 w-4" />{:else}<Eye
                                class="h-4 w-4"
                            />{/if}
                    </button>
                </div>
                {#if form.errors.password}<p class="text-xs text-danger">
                        {form.errors.password}
                    </p>{/if}
            </div>

            <label class="flex items-center gap-2 text-[13px] text-fg-muted">
                <input
                    type="checkbox"
                    bind:checked={form.remember}
                    class="h-3.5 w-3.5 rounded-sm border-line accent-accent"
                />
                Remember me
            </label>

            <button
                type="submit"
                disabled={form.processing}
                class="btn-primary btn-lg mt-1 w-full justify-center"
            >
                {form.processing ? 'Signing in' : 'Sign in'}
            </button>
        </form>
    </div>
</main>
