<script lang="ts">
    import { page } from '@inertiajs/svelte';
    import { t } from '../lib/i18n';
    import { quickAdd } from '../lib/quickAdd.svelte';
    import type { SharedProps } from '../lib/types';
    import Dialog from './Dialog.svelte';
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
</script>

{#if quickAdd.isOpen}
    <Dialog label={t('New task')} width={920} onclose={() => quickAdd.close()}>
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
    </Dialog>
{/if}
