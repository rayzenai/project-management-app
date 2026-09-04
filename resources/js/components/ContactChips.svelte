<script lang="ts">
    import { router } from '@inertiajs/svelte';
    import { ChevronRight, Mail, Phone } from '@lucide/svelte';
    import type { Contact, Id } from '../lib/types';
    import Avatar from './Avatar.svelte';

    let { contacts }: { contacts: Contact[] } = $props();

    let openId = $state<Id | null>(null);

    function toggle(id: Id) {
        openId = openId === id ? null : id;
    }

    function close() {
        openId = null;
    }

    function taskHref(contact: Contact): string | null {
        const task = contact.task;

        if (!task?.project?.slug || !task.slug) {
            return null;
        }

        return `/workspace/projects/${task.project.slug}/tasks/${task.slug}`;
    }

    function subtitle(contact: Contact): string {
        return [contact.role, contact.organization].filter(Boolean).join(' · ');
    }

    function goToTask(contact: Contact) {
        const href = taskHref(contact);

        if (!href) {
            return;
        }

        close();
        router.visit(href);
    }

    function onWindowKey(event: KeyboardEvent) {
        if (event.key === 'Escape' && openId !== null) {
            event.preventDefault();
            close();
        }
    }
</script>

<svelte:window onkeydown={onWindowKey} />

<div class="flex flex-wrap items-center gap-2">
    <span class="section-title">
        Contacts
        {#if contacts.length > 0}
            <span class="section-count">{contacts.length}</span>
        {/if}
    </span>

    {#if contacts.length === 0}
        <span class="text-xs text-fg-faint">
            Contacts added on tasks show up here.
        </span>
    {:else}
        {#each contacts as contact (contact.id)}
            {@const href = taskHref(contact)}
            <div class="relative">
                <button
                    type="button"
                    onclick={(e) => {
                        e.stopPropagation();
                        toggle(contact.id);
                    }}
                    aria-haspopup="dialog"
                    aria-expanded={openId === contact.id}
                    class={`inline-flex h-6 max-w-[16rem] items-center gap-1.5 rounded-md border bg-surface pr-2 pl-0.5 text-xs transition hover:bg-hover ${
                        openId === contact.id ? 'border-accent' : 'border-line'
                    }`}
                >
                    <Avatar name={contact.name} size="sm" />
                    <span class="truncate font-medium text-fg"
                        >{contact.name}</span
                    >
                    {#if subtitle(contact)}
                        <span class="truncate text-fg-faint"
                            >{subtitle(contact)}</span
                        >
                    {/if}
                </button>

                {#if openId === contact.id}
                    <div
                        class="popover absolute top-full left-0 z-30 mt-1 w-64 py-0 text-left"
                        role="dialog"
                        aria-label={`Contact ${contact.name}`}
                        tabindex="-1"
                    >
                        <div class="border-b border-line-soft px-3 py-2.5">
                            <div class="text-[13px] font-semibold text-fg">
                                {contact.name}
                            </div>
                            {#if subtitle(contact)}
                                <div class="mt-0.5 text-xs text-fg-muted">
                                    {subtitle(contact)}
                                </div>
                            {/if}
                        </div>

                        <div class="space-y-1.5 px-3 py-2.5 text-xs">
                            {#if contact.email}
                                <a
                                    href={`mailto:${contact.email}`}
                                    class="flex items-center gap-2 text-fg-muted hover:text-fg"
                                >
                                    <Mail
                                        class="h-3.5 w-3.5 shrink-0 text-fg-faint"
                                    />
                                    <span class="truncate">{contact.email}</span
                                    >
                                </a>
                            {/if}
                            {#if contact.phone}
                                <a
                                    href={`tel:${contact.phone}`}
                                    class="flex items-center gap-2 text-fg-muted hover:text-fg"
                                >
                                    <Phone
                                        class="h-3.5 w-3.5 shrink-0 text-fg-faint"
                                    />
                                    <span class="truncate">{contact.phone}</span
                                    >
                                </a>
                            {/if}
                            {#if contact.notes}
                                <p class="text-fg-muted">{contact.notes}</p>
                            {/if}
                            {#if !contact.email && !contact.phone && !contact.notes}
                                <p class="text-fg-faint">
                                    No contact details recorded.
                                </p>
                            {/if}
                        </div>

                        {#if href && contact.task}
                            <button
                                type="button"
                                onclick={() => goToTask(contact)}
                                class="flex w-full items-center gap-1.5 border-t border-line-soft px-3 py-2 text-left text-xs font-medium text-accent hover:bg-hover"
                            >
                                <span class="truncate">
                                    {contact.task.short_title ||
                                        contact.task.title}
                                </span>
                                <ChevronRight
                                    class="ml-auto h-3.5 w-3.5 shrink-0"
                                />
                            </button>
                        {/if}
                    </div>
                {/if}
            </div>
        {/each}
    {/if}
</div>

{#if openId !== null}
    <button
        type="button"
        aria-label="Close contact details"
        class="fixed inset-0 z-20 cursor-default"
        onclick={close}
    ></button>
{/if}
