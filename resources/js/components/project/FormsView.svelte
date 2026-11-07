<script lang="ts">
    import type { ProjectForms } from '../../lib/forms/types';
    import type { Project } from '../../lib/types';
    import FormList from '../forms/FormList.svelte';
    import NewForm from '../forms/NewForm.svelte';

    /**
     * A project's Forms tab: its forms. The team sees each one's title,
     * phase and dates; responses only open for whoever runs the project,
     * a form's maker and the people a form is shared with.
     */
    let { project, forms }: { project: Project; forms: ProjectForms } =
        $props();
</script>

<section>
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h2 class="section-title mb-1">
                Forms <span class="section-count">{forms.forms.length}</span>
            </h2>
            <p class="text-xs text-fg-muted">
                Forms this project sends out. Their responses are the office's.
            </p>
        </div>
        {#if forms.can_create}<NewForm
                templates={forms.templates}
                project={project.slug}
            />{/if}
    </div>
    {#if forms.forms.length === 0}
        <p class="mt-3 text-[13px] text-fg-muted">No forms yet.</p>
    {:else}
        <div class="mt-3">
            <FormList forms={forms.forms} mode="manage" showProject={false} />
        </div>
    {/if}
</section>
