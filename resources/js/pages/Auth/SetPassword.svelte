<script lang="ts">
    import { Link, useForm } from '@inertiajs/svelte';
    import { Eye, EyeOff } from '@lucide/svelte';
    import { untrack } from 'svelte';

    let {
        kind,
        token,
        email,
        status = null,
    }: {
        kind: 'reset' | 'invite';
        token: string;
        email: string;
        status?: string | null;
    } = $props();

    const uid = $props.id();
    let showPassword = $state(false);

    // The link's own fields ride along; the page is opened once per link.
    const form = useForm(
        untrack(() => ({
            kind,
            token,
            email,
            password: '',
            password_confirmation: '',
        })),
    );
    // "Send me a new link" when this one no longer works.
    const resend = useForm(untrack(() => ({ email })));

    const heading = $derived(
        kind === 'invite' ? 'Set your password' : 'Set a new password',
    );
    const linkError = $derived(
        form.errors.token ?? form.errors.email ?? form.errors.kind ?? null,
    );

    function submit(e: SubmitEvent) {
        e.preventDefault();
        form.post('/workspace/set-password', {
            onFinish: () => form.reset('password', 'password_confirmation'),
        });
    }

    function sendNewLink() {
        resend.post('/workspace/forgot-password', { preserveScroll: true });
    }
</script>

<svelte:head><title>{heading} · Workspace</title></svelte:head>

<main
    class="flex min-h-screen items-center justify-center bg-bg px-4 py-12 text-fg"
>
    <div class="w-full max-w-[360px]">
        <div class="mb-6 flex items-center gap-2.5">
            <span
                class="grid h-7 w-7 place-items-center rounded-md bg-accent text-xs font-semibold text-white"
                >W</span
            >
            <div class="min-w-0">
                <h1 class="text-[15px] font-semibold">{heading}</h1>
                {#if email}<p class="truncate text-[13px] text-fg-muted">
                        {email}
                    </p>{/if}
            </div>
        </div>

        {#if status}
            <div class="panel flex flex-col gap-2 p-5" role="status">
                <p>{status}</p>
                <p class="text-[13px] text-fg-muted">
                    If nothing arrives in a few minutes, look in your spam
                    folder.
                </p>
            </div>
        {:else if linkError}
            <div class="panel flex flex-col gap-4 p-5" role="alert">
                <p class="text-danger">{linkError}</p>
                {#if email}
                    <button
                        type="button"
                        onclick={sendNewLink}
                        disabled={resend.processing}
                        class="btn-primary btn-lg w-full justify-center"
                    >
                        {resend.processing ? 'Sending' : 'Send me a new link'}
                    </button>
                    {#if resend.errors.email}<p class="text-xs text-danger">
                            {resend.errors.email}
                        </p>{/if}
                {:else}
                    <Link
                        href="/workspace/forgot-password"
                        class="btn-primary btn-lg w-full justify-center"
                        >Send me a new link</Link
                    >
                {/if}
            </div>
        {:else}
            <form onsubmit={submit} class="panel flex flex-col gap-4 p-5">
                <div class="flex flex-col gap-1.5">
                    <label for="{uid}-password" class="label"
                        >New password</label
                    >
                    <div class="relative">
                        <input
                            id="{uid}-password"
                            type={showPassword ? 'text' : 'password'}
                            autocomplete="new-password"
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
                            {#if showPassword}<EyeOff
                                    class="h-4 w-4"
                                />{:else}<Eye class="h-4 w-4" />{/if}
                        </button>
                    </div>
                    {#if form.errors.password}<p class="text-xs text-danger">
                            {form.errors.password}
                        </p>{/if}
                </div>

                <div class="flex flex-col gap-1.5">
                    <label for="{uid}-confirm" class="label"
                        >Type it again</label
                    >
                    <input
                        id="{uid}-confirm"
                        type={showPassword ? 'text' : 'password'}
                        autocomplete="new-password"
                        bind:value={form.password_confirmation}
                        required
                        class="input h-9"
                    />
                </div>

                <button
                    type="submit"
                    disabled={form.processing}
                    class="btn-primary btn-lg mt-1 w-full justify-center"
                >
                    {form.processing ? 'Saving' : 'Set my password'}
                </button>
            </form>
        {/if}

        <p class="mt-4 text-center text-[13px]">
            <Link href="/workspace/login" class="text-accent hover:underline"
                >Back to sign in</Link
            >
        </p>
    </div>
</main>
