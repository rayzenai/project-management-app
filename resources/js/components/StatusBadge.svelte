<script lang="ts">
    let {
        status,
        label,
    }: { status: string | null | undefined; label?: string | null } = $props();

    const bucket = $derived.by(() => {
        switch (status) {
            case 'done':
                return 'done';
            case 'in_progress':
            case 'started':
                return 'progress';
            case 'failed':
            case 'blocked':
                return 'late';
            case 'unclear':
            case 'not_started':
                return 'pending';
            default:
                return 'pending';
        }
    });

    const klass = $derived.by(() => {
        switch (bucket) {
            case 'done':
                return 'bg-emerald-100 text-emerald-800 ring-emerald-200 dark:bg-emerald-500/15 dark:text-emerald-300 dark:ring-emerald-500/30';
            case 'progress':
                return 'bg-sky-100 text-sky-800 ring-sky-200 dark:bg-sky-500/15 dark:text-sky-300 dark:ring-sky-500/30';
            case 'late':
                return 'bg-red-100 text-red-800 ring-red-200 dark:bg-red-500/15 dark:text-red-300 dark:ring-red-500/30';
            default:
                return 'bg-neutral-100 text-neutral-700 ring-neutral-200 dark:bg-neutral-800 dark:text-neutral-300 dark:ring-neutral-700';
        }
    });
</script>

<span
    class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset {klass}"
>
    {label || status || 'Unknown'}
</span>
