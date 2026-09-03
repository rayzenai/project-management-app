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
    <div class="w-full max-w-sm">
        <div class="mb-8 text-center">
            <p class="eyebrow text-fg-faint">Workspace</p>
            <h1 class="mt-1 text-2xl font-semibold tracking-tight">Sign in</h1>
        </div>

        <form
            onsubmit={submit}
            class="rounded-2xl border border-line bg-surface p-6 shadow-sm"
        >
            <div class="flex flex-col gap-1.5">
                <label for="{uid}-email" class="text-sm font-medium"
                    >Email address</label
                >
                <input
                    id="{uid}-email"
                    type="email"
                    autocomplete="username"
                    inputmode="email"
                    bind:value={form.email}
                    required
                    class="w-full rounded-lg border border-line bg-bg px-3 py-2 text-fg outline-none focus:border-accent"
                />
                {#if form.errors.email}<p class="text-xs text-danger">
                        {form.errors.email}
                    </p>{/if}
            </div>

            <div class="mt-4 flex flex-col gap-1.5">
                <label for="{uid}-password" class="text-sm font-medium"
                    >Password</label
                >
                <div class="relative">
                    <input
                        id="{uid}-password"
                        type={showPassword ? 'text' : 'password'}
                        autocomplete="current-password"
                        bind:value={form.password}
                        required
                        class="w-full rounded-lg border border-line bg-bg px-3 py-2 pr-10 text-fg outline-none focus:border-accent"
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

            <label class="mt-4 flex items-center gap-2 text-sm text-fg-muted">
                <input
                    type="checkbox"
                    bind:checked={form.remember}
                    class="rounded border-line"
                />
                Remember me
            </label>

            <button
                type="submit"
                disabled={form.processing}
                class="mt-6 w-full rounded-lg bg-accent px-4 py-2.5 font-medium text-bg transition hover:bg-accent-dim disabled:opacity-60"
            >
                {form.processing ? 'Signing in…' : 'Sign in'}
            </button>
        </form>
    </div>
</main>
