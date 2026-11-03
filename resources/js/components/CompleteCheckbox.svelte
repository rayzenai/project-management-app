<script lang="ts">
    import { page, router } from '@inertiajs/svelte';
    import { Check } from '@lucide/svelte';
    import { QUIET, toast } from '../lib/toast.svelte';
    import type { SharedProps, Task } from '../lib/types';

    let {
        task,
        projectSlug,
        onCompleted,
        round = false,
    }: {
        task: Pick<Task, 'id' | 'slug' | 'status' | 'title' | 'short_title'>;
        projectSlug: string;
        onCompleted?: (previousStatus: string) => void;
        /**
         * My work's circle: 24px on a phone, 18px from `sm`, 44px to tap,
         * half filled while the task is started.
         */
        round?: boolean;
    } = $props();

    let optimistic = $state<string | null>(null);
    let failed = $state(false);

    const shared = $derived((page.props ?? {}) as unknown as SharedProps);
    const statuses = $derived(shared.statuses ?? []);
    const completeStatus = $derived(shared.completeStatus ?? 'done');
    const shown = $derived(optimistic ?? task.status);
    const isComplete = $derived(
        statuses.find((s) => s.value === shown)?.is_complete ?? false,
    );
    const isStarted = $derived(
        round && (statuses.find((s) => s.value === shown)?.is_started ?? false),
    );
    const firstIncomplete = $derived(
        statuses.find((s) => !s.is_complete)?.value ?? 'not_started',
    );

    $effect(() => {
        if (optimistic !== null && task.status === optimistic) {
            optimistic = null;
        }
    });

    /**
     * `quiet` for completing and its Undo, which the toast below announces:
     * the server then flashes nothing, so one click shows one toast.
     * Reopening keeps the server's own message.
     */
    function patchStatus(
        value: string,
        options: { onSuccess?: () => void; quiet?: boolean } = {},
    ) {
        const previous = shown;
        optimistic = value;

        router.patch(
            `/workspace/projects/${projectSlug}/tasks/${task.slug}`,
            { status: value },
            {
                headers: options.quiet ? QUIET : {},
                preserveState: true,
                preserveScroll: true,
                onSuccess: options.onSuccess,
                onError: () => {
                    optimistic = previous === task.status ? null : previous;
                    failed = true;
                    setTimeout(() => (failed = false), 2000);
                },
            },
        );
    }

    function toggle(event: MouseEvent) {
        event.stopPropagation();

        if (isComplete) {
            patchStatus(firstIncomplete);

            return;
        }

        const previous = shown;
        patchStatus(completeStatus, {
            quiet: true,
            onSuccess: () => {
                onCompleted?.(previous);
                toast.show(`Completed "${task.short_title ?? task.title}"`, {
                    undo: {
                        run: () => patchStatus(previous, { quiet: true }),
                    },
                });
            },
        });
    }
</script>

<button
    type="button"
    role="checkbox"
    aria-checked={isComplete}
    aria-label={isComplete
        ? 'Mark not complete'
        : isStarted
          ? 'Started, mark complete'
          : 'Mark complete'}
    class={`group/check flex shrink-0 items-center justify-center border-[1.5px] transition ${
        round
            ? "relative size-[24px] rounded-full before:absolute before:-inset-[10px] before:content-[''] sm:size-[18px] sm:before:-inset-[13px]"
            : 'h-4 w-4 rounded-sm'
    } ${
        isComplete
            ? 'border-success bg-success text-white'
            : `${isStarted ? 'border-accent' : 'border-fg-faint'} bg-transparent text-transparent hover:border-success hover:text-success`
    } ${failed ? 'ring-1 ring-danger' : ''}`}
    onclick={toggle}
    onkeydown={(e) => e.stopPropagation()}
>
    {#if isStarted && !isComplete}
        <!-- Started: the left half filled; the check takes over on hover. -->
        <span
            class="pointer-events-none absolute inset-y-0 left-0 w-1/2 rounded-l-full bg-accent transition group-hover/check:opacity-0"
            aria-hidden="true"
        ></span>
    {/if}
    <Check
        class={round ? 'relative size-[14px] sm:size-[11px]' : 'h-3 w-3'}
        strokeWidth={3}
    />
</button>
