<script lang="ts">
    import AppShell from '../components/AppShell.svelte';

    /**
     * My work: the person's own work, grouped by when it is due
     * (MyWorkQuery). A plain list of each group's rows until the page
     * proper replaces it.
     */
    type Row = {
        key: string;
        task: { title: string };
        subtask: { body: string } | null;
    };

    let {
        today,
        groups,
    }: {
        today: { date: string; label: string; bs: string | null };
        groups: { key: string; label: string; rows: Row[] }[];
    } = $props();
</script>

<AppShell title="My work">
    {#snippet bar()}
        <span class="font-medium">My work</span>
    {/snippet}

    <p class="text-fg-muted">
        {today.label}{#if today.bs}
            · <span class="font-np">{today.bs}</span>{/if}
    </p>

    {#each groups as group (group.key)}
        <section class="mt-6">
            <h2 class="section-title">{group.label}</h2>
            <ul>
                {#each group.rows as row (row.key)}
                    <li class="row">{row.subtask?.body ?? row.task.title}</li>
                {/each}
            </ul>
        </section>
    {/each}
</AppShell>
