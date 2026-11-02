<script lang="ts">
    import { router } from '@inertiajs/svelte';
    import { Check } from '@lucide/svelte';
    import { untrack } from 'svelte';
    import { SvelteMap } from 'svelte/reactivity';
    import AppShell from '../../components/AppShell.svelte';

    type EmailKey = 'now' | 'morning' | 'weekly';

    /**
     * Emails: which emails this person gets, reached from the account menu
     * and from the foot of every email. Each switch saves on its own
     * (PATCH /workspace/preferences) and says so beside its name.
     */
    let {
        emails,
        morningAt,
    }: { emails: Record<EmailKey, boolean>; morningAt: string } = $props();

    // "09:00" reads as "9:00".
    const at = $derived(morningAt.replace(/^0(\d)/, '$1'));

    const settings = $derived<
        { key: EmailKey; field: string; label: string; detail: string }[]
    >([
        {
            key: 'now',
            field: 'email_notifications',
            label: 'Right away',
            detail: 'When a task is given to you, you are mentioned, or you join a project.',
        },
        {
            key: 'morning',
            field: 'email_morning',
            label: 'Morning email',
            detail: `On working days at ${at}, only when something is waiting for you.`,
        },
        {
            key: 'weekly',
            field: 'email_weekly',
            label: 'Weekly brief',
            detail: 'For people who run something, on the first working day of the week.',
        },
    ]);

    const on = $state<Record<EmailKey, boolean>>(
        untrack(() => ({ ...emails })),
    );
    const status = new SvelteMap<EmailKey, 'saving' | 'saved' | 'failed'>();
    // When each "Saved" goes away; not shown, so not reactive.
    const timers: Partial<Record<EmailKey, ReturnType<typeof setTimeout>>> = {};

    function flip(key: EmailKey, field: string): void {
        const value = !on[key];
        let saved = false;

        on[key] = value;
        status.set(key, 'saving');
        clearTimeout(timers[key]);

        router.patch(
            '/workspace/preferences',
            { [field]: value },
            {
                async: true,
                preserveScroll: true,
                preserveState: true,
                onSuccess: () => {
                    saved = true;
                },
                onFinish: () => {
                    if (!saved) {
                        // Back to what the server still has; the note stays.
                        on[key] = !value;
                        status.set(key, 'failed');

                        return;
                    }

                    status.set(key, 'saved');
                    timers[key] = setTimeout(() => status.delete(key), 2500);
                },
            },
        );
    }
</script>

<svelte:head><title>Emails · Workspace</title></svelte:head>

<AppShell flush>
    <div class="mx-auto w-full max-w-[720px] pb-16 sm:px-8 sm:pt-10">
        <header class="px-5 pt-6 sm:px-0 sm:pt-0">
            <h1
                class="text-[30px] leading-[36px] font-semibold tracking-[-0.02em] sm:text-[28px] sm:leading-[34px]"
            >
                Emails
            </h1>
            <p
                class="mt-1 text-[14.5px] text-fg-muted sm:mt-1.5 sm:text-[14px]"
            >
                Everything an email tells you is also on My work and in your
                notifications.
            </p>
        </header>

        <ul class="mt-6 border-y border-line sm:mt-8">
            {#each settings as setting (setting.key)}
                {@const saveState = status.get(setting.key)}
                <li
                    class="flex items-center gap-4 border-b border-line px-5 py-3.5 last:border-b-0 sm:px-0"
                >
                    <div class="min-w-0 flex-1">
                        <div class="flex items-baseline gap-2">
                            <span
                                id={`email-${setting.key}`}
                                class="text-[15px] leading-[22px] font-medium text-fg"
                            >
                                {setting.label}
                            </span>
                            <span
                                class="inline-flex items-center gap-1 text-[13px]"
                                aria-live="polite"
                            >
                                {#if saveState === 'saved'}
                                    <Check class="size-3.5 text-success" />
                                    <span class="text-fg-muted">Saved</span>
                                {:else if saveState === 'failed'}
                                    <span class="text-danger"
                                        >Not saved. Try again.</span
                                    >
                                {/if}
                            </span>
                        </div>
                        <p
                            id={`email-${setting.key}-detail`}
                            class="mt-0.5 text-[14px] leading-[20px] text-fg-muted"
                        >
                            {setting.detail}
                        </p>
                    </div>

                    <button
                        type="button"
                        role="switch"
                        aria-checked={on[setting.key]}
                        aria-labelledby={`email-${setting.key}`}
                        aria-describedby={`email-${setting.key}-detail`}
                        disabled={saveState === 'saving'}
                        onclick={() => flip(setting.key, setting.field)}
                        class="flex h-[44px] w-[52px] shrink-0 items-center justify-end disabled:opacity-60"
                    >
                        <span
                            class={`inline-flex h-6 w-11 items-center rounded-full border px-[3px] transition-colors ${
                                on[setting.key]
                                    ? 'justify-end border-accent bg-accent'
                                    : 'justify-start border-line bg-surface-alt'
                            }`}
                        >
                            <span
                                class={`block size-4 rounded-full transition-colors ${
                                    on[setting.key] ? 'bg-white' : 'bg-fg-faint'
                                }`}
                            ></span>
                        </span>
                    </button>
                </li>
            {/each}
        </ul>
    </div>
</AppShell>
