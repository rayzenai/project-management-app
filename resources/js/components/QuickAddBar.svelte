<script lang="ts">
    import type { Member, QuickAddProject } from '../lib/types';
    import QuickAddForm from './QuickAddForm.svelte';

    let {
        projects,
        team,
        currentMemberId,
        defaultProjectId = null,
        today,
    }: {
        projects: QuickAddProject[];
        team: Member[];
        currentMemberId: number | null;
        defaultProjectId?: number | null;
        /** The office's today, Y-m-d. */
        today?: string;
    } = $props();

    let form = $state<{ chooseProject: (projectId: number) => void } | null>(
        null,
    );

    /** Focuses the field with this project chosen in its project chip. */
    export function addTo(projectId: number): void {
        form?.chooseProject(projectId);
    }
</script>

<QuickAddForm
    bind:this={form}
    {projects}
    {team}
    {currentMemberId}
    {defaultProjectId}
    {today}
    variant="inline"
/>
