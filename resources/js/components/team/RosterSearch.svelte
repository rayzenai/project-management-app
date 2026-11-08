<script lang="ts">
    import { router } from '@inertiajs/svelte';
    import type { FoundPerson, Id } from '../../lib/types';
    import PersonSearch from '../PersonSearch.svelte';

    /**
     * Adding someone to a project's team, from its People tab: the person
     * search over who can join (the team's organization, or anyone for a
     * cross-organization team, `TeamCandidatesQuery`), one add at a time.
     */
    let {
        team,
    }: {
        /** The team to add to; outsiders are offered when it is cross-organization. */
        team: { id: Id; name: string; is_cross_organization?: boolean };
    } = $props();

    let adding = $state(false);

    function add(person: FoundPerson) {
        adding = true;
        router.post(
            `/workspace/teams/${team.id}/members`,
            { member_id: person.id },
            { preserveScroll: true, onFinish: () => (adding = false) },
        );
    }
</script>

<div class="mt-2">
    <PersonSearch
        url={`/workspace/teams/${team.id}/candidates`}
        label={`Add someone to ${team.name}`}
        hideLabel
        placeholder={team.is_cross_organization
            ? 'Add someone from any organization: name or email'
            : 'Add someone: name or email'}
        empty="Nobody matches who isn't already on the team."
        detail={(person) =>
            [
                person.designation,
                person.email,
                team.is_cross_organization ? person.organization : null,
            ]
                .filter(Boolean)
                .join(' · ') || null}
        disabled={adding}
        onpick={add}
    />
</div>
