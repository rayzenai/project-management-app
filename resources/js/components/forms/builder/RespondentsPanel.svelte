<script lang="ts">
    import { X } from '@lucide/svelte';
    import { FormsHttpError, send } from '../../../lib/forms/http';
    import type { PersonRef } from '../../../lib/forms/types';
    import { toast } from '../../../lib/toast.svelte';
    import Spinner from '../../Spinner.svelte';
    import type { BuilderData } from './builderTypes';
    import PersonSearch from './PersonSearch.svelte';

    /**
     * Who fills it in: everyone in the office, the project's team, or
     * chosen people, and other offices it is sent to (only the office's
     * admins and super-admins choose those).
     */
    let {
        data,
        onupdate,
    }: { data: BuilderData; onupdate: (next: BuilderData) => void } = $props();

    // svelte-ignore state_referenced_locally
    let respondents = $state(data.form.respondents);
    // svelte-ignore state_referenced_locally
    let people = $state<PersonRef[]>([...data.respondents.people]);
    // svelte-ignore state_referenced_locally
    let organizations = $state<number[]>(
        data.respondents.organizations.map((o) => o.id),
    );
    let filter = $state('');
    let saving = $state(false);
    let errors = $state<Record<string, string>>({});

    const shownChoices = $derived(
        data.respondents.organization_choices.filter((o) =>
            o.name.toLowerCase().includes(filter.trim().toLowerCase()),
        ),
    );

    async function save() {
        saving = true;
        errors = {};

        try {
            const { data: next, message } = await send<BuilderData>(
                'PUT',
                `/workspace/forms/${data.form.id}/respondents`,
                {
                    respondents,
                    member_ids: people.map((p) => p.id),
                    ...(data.respondents.can_add_organizations
                        ? { organization_ids: organizations }
                        : {}),
                },
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
    <fieldset>
        <legend class="label mb-1">In {data.form.organization}</legend>
        {#each [['everyone', `Everyone in ${data.form.organization}`], ['team', `The team of ${data.form.project?.label ?? 'the project'}`], ['people', 'Chosen people']] as [value, label] (value)}
            <label class="mt-1 flex items-center gap-2 text-[13px] text-fg">
                <input type="radio" {value} bind:group={respondents} />{label}
            </label>
        {/each}
    </fieldset>

    {#if respondents === 'people'}
        <div class="flex flex-col gap-2">
            <PersonSearch
                url="/workspace/forms/{data.form.id}/people"
                label="Add people"
                placeholder="Someone in {data.form.organization}"
                onpick={(p) =>
                    (people = people.some((x) => x.id === p.id)
                        ? people
                        : [...people, p])}
            />
            <div class="flex flex-wrap gap-1.5">
                {#each people as person (person.id)}
                    <span class="chip flex items-center gap-1"
                        >{person.name}<button
                            type="button"
                            aria-label="Remove {person.name}"
                            onclick={() =>
                                (people = people.filter(
                                    (p) => p.id !== person.id,
                                ))}><X class="h-3 w-3" /></button
                        ></span
                    >
                {:else}
                    <p class="text-xs text-fg-muted">Nobody chosen yet.</p>
                {/each}
            </div>
            {#if errors.member_ids}<p class="text-xs text-danger">
                    {errors.member_ids}
                </p>{/if}
        </div>
    {/if}

    <div>
        <span class="label mb-1 block">Other offices</span>
        {#if data.respondents.can_add_organizations}
            <p class="mb-2 text-xs text-fg-muted">
                Everyone in these offices can fill it in too. They see only the
                form and their own response; you see their answers with their
                names. In "who hasn't answered" they are counted, never named.
            </p>
            <input
                class="input mb-2"
                placeholder="Find an office"
                bind:value={filter}
                aria-label="Find an office"
            />
            <div
                class="max-h-60 overflow-y-auto rounded-md border border-line-soft p-2"
            >
                {#each shownChoices as choice (choice.id)}
                    <label
                        class="flex items-center gap-2 py-0.5 text-[13px] text-fg"
                    >
                        <input
                            type="checkbox"
                            checked={organizations.includes(choice.id)}
                            onchange={(e) =>
                                (organizations = e.currentTarget.checked
                                    ? [...organizations, choice.id]
                                    : organizations.filter(
                                          (id) => id !== choice.id,
                                      ))}
                        />
                        {choice.name}
                    </label>
                {:else}
                    <p class="text-xs text-fg-muted">No other offices.</p>
                {/each}
            </div>
        {:else if data.respondents.organizations.length}
            <ul class="text-[13px] text-fg">
                {#each data.respondents.organizations as o (o.id)}<li>
                        {o.name}
                    </li>{/each}
            </ul>
            <p class="mt-1 text-xs text-fg-muted">
                Only {data.form.organization}'s admins change which other
                offices it goes to.
            </p>
        {:else}
            <p class="text-xs text-fg-muted">
                Only {data.form.organization}'s admins can send a form to other
                offices.
            </p>
        {/if}
    </div>

    <div>
        <button
            type="button"
            class="btn btn-primary"
            disabled={saving}
            onclick={save}
            >{#if saving}<Spinner size={14} />{/if}Save who fills it in</button
        >
    </div>
</div>
