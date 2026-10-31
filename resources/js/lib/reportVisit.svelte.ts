import { router } from '@inertiajs/svelte';
import type { OrganizationOption } from './types';

/**
 * A report page's filter change (the attendance register, the work
 * reports): a visit to the page with its filters in the query string,
 * keeping state and scroll, with `loading` and the first `error` for the
 * page to show. Empty filters are left out, and so is the organization
 * unless the viewer may switch it.
 */
export class ReportVisit {
    loading = $state(false);
    error = $state<string | null>(null);

    go(
        path: string,
        filters: Record<string, string | number | null>,
        organizations: OrganizationOption[] = [],
    ): void {
        const params = Object.fromEntries(
            Object.entries(filters).filter(
                ([key, value]) =>
                    value !== null &&
                    value !== '' &&
                    (key !== 'organization' || organizations.length > 0),
            ),
        );

        router.get(path, params, {
            preserveState: true,
            preserveScroll: true,
            onStart: () => (this.loading = true),
            onSuccess: () => (this.error = null),
            onError: (errors) =>
                (this.error = Object.values(errors)[0] ?? null),
            onFinish: () => (this.loading = false),
        });
    }
}
