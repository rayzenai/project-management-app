<script lang="ts">
    import { X } from '@lucide/svelte';
    import { onMount } from 'svelte';
    import { FormsHttpError, send } from '../../../lib/forms/http';
    import type { FormAccess, PersonRef } from '../../../lib/forms/types';
    import { toast } from '../../../lib/toast.svelte';
    import Avatar from '../../Avatar.svelte';
    import Spinner from '../../Spinner.svelte';
    import PersonSearch from './PersonSearch.svelte';

    /**
     * Who builds the form or handles its responses, beyond whoever runs the
     * project and its maker: chosen people of the office, each Can edit or
     * Can see responses.
     */
    let {
        formId,
        organization,
        project,
    }: { formId: number; organization: string | null; project: string } =
        $props();

    type Share = {
        id: number;
        member: PersonRef;
        permission: FormAccess;
        effective: boolean;
    };
    let shares = $state<Share[] | null>(null);
    let permission = $state<FormAccess>('responses');
    let busy = $state(false);

    async function load() {
        const { data } = await send<{ shares: Share[] }>(
            'GET',
            `/workspace/forms/${formId}/sharing`,
        );
        shares = data.shares;
    }

    onMount(load);

    async function act(run: () => Promise<unknown>) {
        busy = true;

        try {
            const result = (await run()) as
                { message?: string | null } | undefined;
            await load();
            toast.show(result?.message ?? 'Saved.');
        } catch (e) {
            toast.error(
                e instanceof FormsHttpError
                    ? (Object.values(e.errors)[0] ?? e.message)
                    : "That didn't save.",
            );
        } finally {
            busy = false;
        }
    }
</script>

<div class="flex flex-col gap-4">
    <p class="text-xs text-fg-muted">
        Whoever runs {project} and the form's maker can always edit it and see its
        responses. The rest of the team can't, unless you add them here.
    </p>
    <div class="flex items-end gap-2">
        <div class="min-w-0 flex-1">
            <PersonSearch
                url="/workspace/forms/{formId}/people?unshared=1"
                label="Add people"
                placeholder="Someone in {organization ?? 'the office'}"
                disabled={busy}
                onpick={(p) =>
                    act(() =>
                        send('POST', `/workspace/forms/${formId}/shares`, {
                            member_id: p.id,
                            permission,
                        }),
                    )}
            />
        </div>
        <label for="share-level" class="sr-only">Their access</label>
        <select
            id="share-level"
            class="input w-44 shrink-0"
            bind:value={permission}
        >
            <option value="responses">Can see responses</option>
            <option value="edit">Can edit</option>
        </select>
    </div>
    {#if shares === null}
        <p class="flex items-center gap-2 text-[13px] text-fg-muted">
            <Spinner size={14} />Loading
        </p>
    {:else}
        <ul class="border-t border-line-soft">
            {#each shares as share (share.id)}
                <li
                    class="flex min-h-11 items-center gap-2.5 border-b border-line-soft py-1.5"
                >
                    <Avatar name={share.member.name} size="md" />
                    <div class="min-w-0 flex-1 leading-tight">
                        <div class="truncate text-[13px] font-medium text-fg">
                            {share.member.name}
                        </div>
                        <div class="truncate text-xs text-fg-muted">
                            {share.effective
                                ? (share.member.designation ?? '')
                                : "Can't open it now (inactive or in another office)"}
                        </div>
                    </div>
                    <label for="share-{share.id}" class="sr-only"
                        >{share.member.name}'s access</label
                    >
                    <select
                        id="share-{share.id}"
                        class="input h-7 w-44 shrink-0"
                        value={share.permission}
                        disabled={busy}
                        onchange={(e) =>
                            act(() =>
                                send(
                                    'PATCH',
                                    `/workspace/forms/${formId}/shares/${share.id}`,
                                    { permission: e.currentTarget.value },
                                ),
                            )}
                    >
                        <option value="responses">Can see responses</option>
                        <option value="edit">Can edit</option>
                    </select>
                    <button
                        type="button"
                        class="btn-icon shrink-0"
                        aria-label="Remove {share.member.name}"
                        disabled={busy}
                        onclick={() =>
                            act(() =>
                                send(
                                    'DELETE',
                                    `/workspace/forms/${formId}/shares/${share.id}`,
                                ),
                            )}><X class="h-3.5 w-3.5" /></button
                    >
                </li>
            {:else}
                <li class="py-2 text-[13px] text-fg-muted">
                    Not shared with anyone else.
                </li>
            {/each}
        </ul>
    {/if}
</div>
