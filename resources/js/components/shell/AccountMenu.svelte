<script lang="ts">
    import { inertia, page, router } from '@inertiajs/svelte';
    import { LogOut, Plus, Search, Settings } from '@lucide/svelte';
    import type { Snippet } from 'svelte';
    import { locale, number, t } from '../../lib/i18n';
    import { currentHref } from '../../lib/navigation';
    import type { NavGroup, NavLink } from '../../lib/navigation';
    import { QUIET } from '../../lib/toast.svelte';
    import type { OrganizationSwitch } from '../../lib/types';
    import Avatar from '../Avatar.svelte';
    import LanguageMark from '../LanguageMark.svelte';
    import Popover from '../Popover.svelte';
    import Sheet from '../Sheet.svelte';
    import OrganizationSwitcher from './OrganizationSwitcher.svelte';

    /**
     * Everything the sidebar doesn't carry: your notifications, emails and
     * the guide, the office pages and reports you may open, the other
     * language (by its own name: on a phone the top bar's switch is only an
     * icon), Settings and Sign out. On a desktop it opens upward from the
     * account button in a popover; on a phone (`sheet`) it rises from the
     * bottom, under the Me tab, and also carries a super-admin's
     * organization switch, New task, Search and the pages the bar has no tab
     * for. The sheet is modal: focus moves into it, Tab stays inside, and
     * closing hands focus back through `onclose`.
     */
    let {
        variant,
        open = $bindable(false),
        trigger,
        triggerLabel,
        triggerClass = '',
        groups,
        path,
        user,
        role,
        extra = [],
        organizationSwitch = null,
        onnewtask,
        onsearch,
        onsettings,
        onlogout,
        onclose,
    }: {
        variant: 'popover' | 'sheet';
        open?: boolean;
        /** The popover's button (the sidebar's account row). */
        trigger?: Snippet<[{ open: boolean }]>;
        triggerLabel?: string;
        triggerClass?: string;
        groups: NavGroup[];
        path: string;
        user: { name: string; email?: string } | null;
        /** The line under the name. */
        role: string;
        /** Pages shown first, on a phone: those without a tab. */
        extra?: NavLink[];
        /** A super-admin's switch, at the top of the sheet. */
        organizationSwitch?: OrganizationSwitch | null;
        /** Given on a phone, where the sidebar's New task and Search aren't. */
        onnewtask?: () => void;
        onsearch?: () => void;
        onsettings: () => void;
        onlogout: () => void;
        /** The sheet closed: give focus back to what opened it. */
        onclose?: () => void;
    } = $props();

    const sheet = $derived(variant === 'sheet');
    const current = $derived(
        currentHref(path, [...extra, ...groups.flatMap((g) => g.links)]),
    );

    function close(): void {
        open = false;

        if (sheet) {
            onclose?.();
        }
    }

    // The language switch, as LanguageSwitch does it.
    const otherLanguage = $derived(
        (
            (page.props as { locales?: { value: string; label: string }[] })
                .locales ?? []
        ).find((choice) => choice.value !== locale()) ?? null,
    );

    function switchLanguage(): void {
        if (!otherLanguage) {
            return;
        }

        router.post(
            '/locale',
            { locale: otherLanguage.value },
            { preserveState: true, preserveScroll: true, headers: QUIET },
        );
        close();
    }

    const iconClass = $derived(
        `shrink-0 ${sheet ? 'h-[18px] w-[18px]' : 'h-4 w-4'}`,
    );

    function itemClass(active: boolean): string {
        return `menu-item ${sheet ? 'min-h-[44px] gap-3 text-[15px]' : ''} ${
            active
                ? 'bg-accent-soft text-accent hover:bg-accent-soft hover:text-accent'
                : ''
        }`;
    }
</script>

{#snippet link(item: NavLink)}
    {@const active = current === item.href}
    {@const Icon = item.icon}
    <a
        href={item.href}
        use:inertia
        data-popover-item
        aria-current={active ? 'page' : undefined}
        class={itemClass(active)}
        onclick={close}
    >
        <Icon class={iconClass} />
        <span class="min-w-0 flex-1 truncate">{t(item.label)}</span>
        {#if (item.count ?? 0) > 0}
            <span
                class={`text-xs tabular-nums ${active ? 'text-accent' : 'text-fg-faint'}`}
                >{(item.count ?? 0) > 99
                    ? `${number(99)}+`
                    : number(item.count ?? 0)}</span
            >
        {/if}
    </a>
{/snippet}

{#snippet body()}
    <div
        class={`max-h-[min(560px,80vh)] overflow-y-auto ${sheet ? 'px-[12px] pt-3 pb-3' : ''}`}
    >
        {#if sheet && organizationSwitch}
            <div class="flex pb-3">
                <OrganizationSwitcher
                    {organizationSwitch}
                    sheet
                    onswitch={close}
                />
            </div>
        {/if}

        {#if sheet && user}
            <div class="flex items-center gap-3 px-2 pt-1 pb-3">
                <Avatar name={user.name} size="lg" />
                <div class="min-w-0 flex-1 leading-tight">
                    <div class="truncate text-[15px] font-medium text-fg">
                        {user.name}
                    </div>
                    <div class="mt-0.5 truncate text-[13px] text-fg-muted">
                        {role}
                    </div>
                </div>
            </div>
        {/if}

        {#if onnewtask || onsearch || extra.length > 0}
            <div class="pb-1">
                {#if onnewtask}
                    <button
                        type="button"
                        data-popover-item
                        class={itemClass(false)}
                        onclick={() => {
                            close();
                            onnewtask?.();
                        }}
                    >
                        <Plus class={`${iconClass} text-accent`} />
                        <span class="flex-1">{t('New task')}</span>
                    </button>
                {/if}
                {#if onsearch}
                    <button
                        type="button"
                        data-popover-item
                        class={itemClass(false)}
                        onclick={() => {
                            close();
                            onsearch?.();
                        }}
                    >
                        <Search class={iconClass} />
                        <span class="flex-1">{t('Search')}</span>
                    </button>
                {/if}
                {#each extra as item (item.href)}
                    {@render link(item)}
                {/each}
            </div>
        {/if}

        {#each groups as group, i (group.label)}
            <div
                role="group"
                aria-labelledby={`account-${variant}-${i}`}
                class={i > 0 || onnewtask || onsearch || extra.length > 0
                    ? 'mt-1 border-t border-line-soft pt-1'
                    : ''}
            >
                <div
                    id={`account-${variant}-${i}`}
                    class={`px-2 pt-1.5 pb-1 font-medium text-fg-faint ${sheet ? 'text-[12.5px]' : 'text-xs'}`}
                >
                    {t(group.label)}
                </div>
                {#each group.links as item (item.href)}
                    {@render link(item)}
                {/each}
            </div>
        {/each}

        <div class="mt-1 border-t border-line-soft pt-1">
            {#if otherLanguage}
                <button
                    type="button"
                    data-popover-item
                    class={itemClass(false)}
                    onclick={switchLanguage}
                >
                    <LanguageMark class={iconClass} />
                    <span class="flex-1" lang={otherLanguage.value}
                        >{otherLanguage.label}</span
                    >
                </button>
            {/if}
            <button
                type="button"
                data-popover-item
                class={itemClass(false)}
                onclick={() => {
                    close();
                    onsettings();
                }}
            >
                <Settings class={iconClass} />
                <span class="flex-1">{t('Settings')}</span>
            </button>
            <button
                type="button"
                data-popover-item
                class={itemClass(false)}
                onclick={onlogout}
            >
                <LogOut class={iconClass} />
                <span class="flex-1">{t('Sign out')}</span>
            </button>
        </div>
    </div>
{/snippet}

{#if variant === 'popover' && trigger}
    <Popover
        bind:open
        {trigger}
        {triggerLabel}
        {triggerClass}
        wrapperClass="w-full"
        panelClass="w-[240px]"
    >
        {@render body()}
    </Popover>
{:else if sheet && open}
    <!-- The sheet opens with the focus on its first item. -->
    <Sheet
        label={t('Me')}
        closeLabel={t('Close the menu')}
        onclose={close}
        focusFirst
        wrapperClass="md:hidden"
    >
        {@render body()}
    </Sheet>
{/if}
