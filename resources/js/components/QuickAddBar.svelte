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
</script>

<QuickAddForm
    {projects}
    {team}
    {currentMemberId}
    {defaultProjectId}
    {today}
    variant="inline"
/>
