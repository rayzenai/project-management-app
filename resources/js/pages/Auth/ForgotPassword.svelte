<script lang="ts">
    import { Link, useForm } from '@inertiajs/svelte';
    import LanguageSwitch from '../../components/LanguageSwitch.svelte';
    import { t } from '../../lib/i18n';

    let { status = null }: { status?: string | null } = $props();

    const uid = $props.id();
    const form = useForm({ email: '' });

    function submit(e: SubmitEvent) {
        e.preventDefault();
        form.post('/workspace/forgot-password');
    }
</script>

<svelte:head><title>{t('Forgot your password · Workspace')}</title></svelte:head
>

<main
    class="flex min-h-screen items-center justify-center bg-bg px-4 py-12 text-fg"
>
    <div class="fixed top-3 right-3">
        <LanguageSwitch />
    </div>
    <div class="w-full max-w-[360px]">
        <div class="mb-6 flex items-center gap-2.5">
            <span
                class="grid h-7 w-7 place-items-center rounded-md bg-accent text-xs font-semibold text-white"
                >W</span
            >
            <h1 class="text-[15px] font-semibold">
                {t('Forgot your password?')}
            </h1>
        </div>

        {#if status}
            <div class="panel flex flex-col gap-2 p-5" role="status">
                <p>{status}</p>
                <p class="text-[13px] text-fg-muted">
                    {t(
                        'If nothing arrives in a few minutes, look in your spam folder.',
                    )}
                </p>
            </div>
        {:else}
            <form onsubmit={submit} class="panel flex flex-col gap-4 p-5">
                <p class="text-[13px] text-fg-muted">
                    {t(
                        'Enter the email you sign in with. We will send you a link to set a new password.',
                    )}
                </p>
                <div class="flex flex-col gap-1.5">
                    <label for="{uid}-email" class="label"
                        >{t('Email address')}</label
                    >
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

                <button
                    type="submit"
                    disabled={form.processing}
                    class="btn-primary btn-lg mt-1 w-full justify-center"
                >
                    {form.processing ? t('Sending') : t('Send link')}
                </button>
            </form>
        {/if}

        <p class="mt-4 text-center text-[13px]">
            <Link href="/workspace/login" class="text-accent hover:underline"
                >{t('Back to sign in')}</Link
            >
        </p>
    </div>
</main>
