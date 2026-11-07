<script lang="ts">
    import { router } from '@inertiajs/svelte';
    import { Plus, X } from '@lucide/svelte';
    import { portal } from '../../lib/forms/portal';
    import type { FormTemplate } from '../../lib/forms/types';
    import Spinner from '../Spinner.svelte';

    /**
     * New form: a title, where it lives (a project; fixed on a project's
     * tab) and a template to start from. The new form opens in the builder.
     */
    let {
        templates,
        project = null,
        places = [],
    }: {
        templates: FormTemplate[];
        project?: string | null;
        places?: { slug: string; label: string }[];
    } = $props();

    const uid = $props.id();
    let open = $state(false);
    let title = $state('');
    let template = $state('blank');
    // svelte-ignore state_referenced_locally
    let where = $state(project ?? places[0]?.slug ?? '');
    let errors = $state<Record<string, string>>({});
    let creating = $state(false);

    const canMake = $derived(project !== null || places.length > 0);

    function create(event: SubmitEvent) {
        event.preventDefault();
        creating = true;
        errors = {};
        router.post(
            '/workspace/forms',
            {
                title: title.trim() || 'Untitled form',
                template,
                project: project ?? where,
            },
            {
                onError: (e) => (errors = e),
                onFinish: () => (creating = false),
            },
        );
    }
</script>

{#if canMake}
    <button type="button" class="btn btn-primary" onclick={() => (open = true)}
        ><Plus class="h-3.5 w-3.5" />New form</button
    >
{/if}

{#if open}
    <div
        use:portal
        class="fixed inset-0 z-50 overflow-y-auto bg-black/40"
        role="presentation"
        onclick={(e) => e.target === e.currentTarget && (open = false)}
    >
        <div
            role="dialog"
            aria-modal="true"
            aria-label="New form"
            tabindex="-1"
            class="mx-auto mt-[10vh] mb-12 w-[min(560px,calc(100vw-32px))]"
            onkeydown={(e) => e.key === 'Escape' && (open = false)}
        >
            <form class="popover flex flex-col gap-4 p-5" onsubmit={create}>
                <div class="flex items-center justify-between">
                    <h2 class="text-[15px] font-semibold text-fg">New form</h2>
                    <button
                        type="button"
                        class="btn-icon"
                        aria-label="Close"
                        onclick={() => (open = false)}
                        ><X class="h-4 w-4" /></button
                    >
                </div>
                <div>
                    <label for="{uid}-title" class="label mb-1 block"
                        >Title</label
                    >
                    <!-- svelte-ignore a11y_autofocus -->
                    <input
                        id="{uid}-title"
                        class="input"
                        bind:value={title}
                        maxlength="200"
                        placeholder="Untitled form"
                        autofocus
                    />
                    {#if errors.title}<p class="mt-1 text-xs text-danger">
                            {errors.title}
                        </p>{/if}
                </div>
                {#if project === null}
                    <div>
                        <label for="{uid}-where" class="label mb-1 block"
                            >Project</label
                        >
                        <select
                            id="{uid}-where"
                            class="input"
                            bind:value={where}
                        >
                            {#each places as place (place.slug)}<option
                                    value={place.slug}>{place.label}</option
                                >{/each}
                        </select>
                        <p class="mt-1 text-xs text-fg-muted">
                            The form and its responses belong to this project's
                            office. Whoever runs the project can see them.
                        </p>
                        {#if errors.project}<p class="mt-1 text-xs text-danger">
                                {errors.project}
                            </p>{/if}
                    </div>
                {/if}
                <fieldset>
                    <legend class="label mb-1">Start from</legend>
                    <div class="grid gap-2 sm:grid-cols-2">
                        {#each templates as t (t.id)}
                            <label
                                class="flex cursor-pointer gap-2 rounded-md border px-3 py-2 text-[13px] {template ===
                                t.id
                                    ? 'border-accent bg-accent-soft'
                                    : 'border-line'}"
                            >
                                <input
                                    type="radio"
                                    name="{uid}-template"
                                    class="mt-0.5"
                                    value={t.id}
                                    bind:group={template}
                                />
                                <span
                                    ><span class="block font-medium text-fg"
                                        >{t.name}</span
                                    ><span class="block text-xs text-fg-muted"
                                        >{t.description}</span
                                    ></span
                                >
                            </label>
                        {/each}
                    </div>
                </fieldset>
                <div class="flex justify-end gap-2">
                    <button
                        type="button"
                        class="btn"
                        onclick={() => (open = false)}>Cancel</button
                    >
                    <button class="btn btn-primary" disabled={creating}
                        >{#if creating}<Spinner size={14} />{/if}Make it</button
                    >
                </div>
            </form>
        </div>
    </div>
{/if}
