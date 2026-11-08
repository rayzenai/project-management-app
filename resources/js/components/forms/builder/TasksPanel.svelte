<script lang="ts">
    import { X } from '@lucide/svelte';
    import { say } from '../../../lib/forms/answers';
    import { FormsHttpError, send } from '../../../lib/forms/http';
    import type { PersonRef } from '../../../lib/forms/types';
    import { isQuestion } from '../../../lib/forms/types';
    import { toast } from '../../../lib/toast.svelte';
    import type { ProjectPick } from '../../../lib/types';
    import ProjectPicker from '../../ProjectPicker.svelte';
    import Spinner from '../../Spinner.svelte';
    import type { BuilderData } from './builderTypes';
    import PersonSearch from './PersonSearch.svelte';

    /**
     * A task from each new response, off until turned on: into a project of
     * the office, for one person who can be given tasks there, with only the
     * chosen answers copied into it (the project's team reads the task).
     */
    let {
        data,
        onupdate,
    }: { data: BuilderData; onupdate: (next: BuilderData) => void } = $props();

    const uid = $props.id();
    // svelte-ignore state_referenced_locally
    let enabled = $state(data.tasks.settings !== null);
    // svelte-ignore state_referenced_locally
    let project = $state<ProjectPick>(
        data.tasks.places.projects.find(
            (p) =>
                p.slug ===
                (data.tasks.project?.slug ?? data.form.project?.slug),
        )?.id ?? null,
    );
    const projectSlug = $derived(
        data.tasks.places.projects.find((p) => p.id === project)?.slug ?? '',
    );
    // svelte-ignore state_referenced_locally
    let assignee = $state<PersonRef | null>(data.tasks.assignee);
    // svelte-ignore state_referenced_locally
    let dueDays = $state(
        data.tasks.settings?.due_days === null || data.tasks.settings === null
            ? ''
            : String(data.tasks.settings.due_days),
    );
    // svelte-ignore state_referenced_locally
    let titleQuestion = $state(data.tasks.settings?.title_question ?? '');
    // svelte-ignore state_referenced_locally
    let copy = $state<string[]>([
        ...(data.tasks.settings?.copy_questions ?? []),
    ]);
    let saving = $state(false);
    let errors = $state<Record<string, string>>({});

    const questions = $derived(
        data.draft.steps
            .flatMap((s) => s.blocks)
            .filter((b) => isQuestion(b.type) && b.type !== 'file'),
    );

    async function save() {
        saving = true;
        errors = {};

        try {
            const { data: next, message } = await send<BuilderData>(
                'PUT',
                `/workspace/forms/${data.form.id}/tasks`,
                enabled
                    ? {
                          enabled: true,
                          project: projectSlug,
                          assignee_id: assignee?.id ?? null,
                          due_days:
                              dueDays.trim() === '' ? null : Number(dueDays),
                          title_question: titleQuestion || null,
                          copy_questions: copy,
                      }
                    : { enabled: false },
            );
            onupdate(next);
            toast.show(message ?? 'Saved.');
        } catch (e) {
            errors = e instanceof FormsHttpError ? e.errors : {};
            toast.error(e instanceof Error ? e.message : "That didn't save.");
        } finally {
            saving = false;
        }
    }
</script>

<div class="flex flex-col gap-4">
    <label class="flex items-center gap-2 text-[13px] text-fg">
        <input type="checkbox" bind:checked={enabled} />Make a task from each
        new response
    </label>
    {#if enabled}
        {#if data.tasks.places.projects.length === 0}
            <p class="text-xs text-danger">
                There is no project of {data.form.organization} you can add tasks
                to.
            </p>
        {:else}
            <div>
                <span class="label mb-1 block">In the project</span>
                <!-- Another project has other people: the one given the
                     tasks is chosen again. -->
                <ProjectPicker
                    projects={data.tasks.places.projects}
                    organizations={data.tasks.places.organizations}
                    bind:value={
                        () => project,
                        (next) => {
                            if (next !== project) {
                                assignee = null;
                            }

                            project = next;
                        }
                    }
                    field
                    placeholder="Pick a project"
                />
                {#if errors.project}<p class="mt-1 text-xs text-danger">
                        {errors.project}
                    </p>{/if}
            </div>
            <div>
                {#if assignee}
                    <span class="label mb-1 block">Given to</span>
                    <span class="chip flex w-fit items-center gap-1"
                        >{assignee.name}{assignee.designation
                            ? `, ${assignee.designation}`
                            : ''}<button
                            type="button"
                            aria-label="Choose someone else"
                            onclick={() => (assignee = null)}
                            ><X class="h-3 w-3" /></button
                        ></span
                    >
                {:else}
                    {#key projectSlug}
                        <PersonSearch
                            url="/workspace/forms/{data.form
                                .id}/tasks/assignees?project={encodeURIComponent(
                                projectSlug,
                            )}"
                            label="Given to"
                            placeholder="Someone on the project"
                            onpick={(p) => (assignee = p)}
                        />
                    {/key}
                {/if}
                {#if errors.assignee_id}<p class="mt-1 text-xs text-danger">
                        {errors.assignee_id}
                    </p>{/if}
            </div>
            <div>
                <label for="{uid}-due" class="label mb-1 block"
                    >Due this many days after the response (empty: no due date)</label
                >
                <input
                    id="{uid}-due"
                    class="input w-32"
                    inputmode="numeric"
                    bind:value={dueDays}
                />
            </div>
            <div>
                <label for="{uid}-title" class="label mb-1 block"
                    >The task's title</label
                >
                <select
                    id="{uid}-title"
                    class="input"
                    bind:value={titleQuestion}
                >
                    <option value=""
                        >The form's title and the response's reference</option
                    >
                    {#each questions.filter((q) => q.type === 'short_text') as q (q.id)}<option
                            value={q.id}
                            >The answer to "{say(q.label, 'en')}"</option
                        >{/each}
                </select>
            </div>
            <fieldset>
                <legend class="label mb-1">Answers copied into the task</legend>
                <p class="mb-1 text-xs text-fg-muted">
                    Everyone on the project reads its tasks. The task always
                    links to the response, which opens only for those who can
                    see responses.
                </p>
                {#each questions as q (q.id)}
                    <label
                        class="flex items-center gap-2 py-0.5 text-[13px] text-fg"
                    >
                        <input
                            type="checkbox"
                            checked={copy.includes(q.id)}
                            onchange={(e) =>
                                (copy = e.currentTarget.checked
                                    ? [...copy, q.id]
                                    : copy.filter((id) => id !== q.id))}
                        />
                        {say(q.label, 'en') || 'Untitled question'}
                    </label>
                {/each}
            </fieldset>
        {/if}
    {:else}
        <p class="text-xs text-fg-muted">
            Turned on, each new response becomes a task for one person, made by
            the form rather than by whoever answered. Responses already in make
            none.
        </p>
    {/if}
    <div>
        <button
            type="button"
            class="btn btn-primary"
            disabled={saving}
            onclick={save}
            >{#if saving}<Spinner size={14} />{/if}Save tasks</button
        >
    </div>
</div>
