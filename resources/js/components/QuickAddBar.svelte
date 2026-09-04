<script lang="ts">
    import type { Member, ProjectSummary } from '../lib/types';
    import QuickAddForm from './QuickAddForm.svelte';

    let {
        projects,
        team,
        currentMemberId,
        defaultProjectId = null,
    }: {
        projects: ProjectSummary[];
        team: Member[];
        currentMemberId: number | null;
        defaultProjectId?: number | null;
    } = $props();
</script>

<div class="panel px-3 py-2">
    <QuickAddForm
        {projects}
        {team}
        {currentMemberId}
        {defaultProjectId}
        variant="inline"
    />
</div>
