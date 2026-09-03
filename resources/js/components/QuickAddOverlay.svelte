<script lang="ts">
    import { page } from '@inertiajs/svelte';
    import { quickAdd } from '../lib/quickAdd.svelte';
    import type { SharedProps } from '../lib/types';
    import QuickAddForm from './QuickAddForm.svelte';

    const shared = $derived((page.props ?? {}) as unknown as SharedProps);
    const context = $derived(
        shared.quickAddContext ?? {
            projects: [],
            team: [],
            currentMemberId: null,
        },
    );
    const currentMemberId = $derived(
        shared.quickAddContext?.currentMemberId ?? null,
    );

    let formComp = $state<{ focusInput: () => void } | null>(null);

    $effect(() => {
        if (!quickAdd.isOpen) {
            return;
        }

        queueMicrotask(() => formComp?.focusInput());
    });

    $effect(() => {
        if (typeof document === 'undefined' || !quickAdd.isOpen) {
            return;
        }

        document.body.style.overflow = 'hidden';

        return () => {
            document.body.style.overflow = '';
        };
    });

    function onPanelKeydown(e: KeyboardEvent) {
        if (e.key === 'Escape') {
            e.preventDefault();
            e.stopPropagation();
            quickAdd.close();
        }
    }
</script>

{#if quickAdd.isOpen}
    <div
        class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-black/40 px-4 py-16 backdrop-blur-md"
        onclick={() => quickAdd.close()}
        role="presentation"
    >
        <div
            class="w-full max-w-2xl overflow-hidden rounded-2xl border border-line bg-surface shadow-2xl"
            onclick={(e) => e.stopPropagation()}
            onkeydown={onPanelKeydown}
            role="dialog"
            aria-modal="true"
            aria-label="New task"
            tabindex="-1"
        >
            <div
                class="flex items-center justify-between border-b border-line px-4 py-3"
            >
                <div class="ws-eyebrow text-accent">+ New task</div>
                <kbd
                    class="rounded border border-line px-1.5 py-0.5 text-[10px] text-fg-muted"
                    >Esc</kbd
                >
            </div>
            <QuickAddForm
                bind:this={formComp}
                projects={context.projects}
                team={context.team}
                {currentMemberId}
                defaultProjectId={quickAdd.projectId}
                lockProject={quickAdd.lockProject}
                prefill={quickAdd.prefill}
                variant="overlay"
                onSuccess={() => quickAdd.close()}
                onCancel={() => quickAdd.close()}
            />
        </div>
    </div>
{/if}
