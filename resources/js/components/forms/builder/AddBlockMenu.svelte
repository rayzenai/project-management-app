<script lang="ts">
    import { Plus } from '@lucide/svelte';
    import type { ContentType, QuestionType } from '../../../lib/forms/types';
    import { CONTENT_MENU, GROUP_MENU, KINDS, QUESTION_MENU } from './catalog';

    /** Add a question or a content block: a menu of every kind, with what each is for. */
    let {
        onadd,
        inGroup = false,
        label = 'Add',
    }: {
        onadd: (type: ContentType | QuestionType) => void;
        inGroup?: boolean;
        label?: string;
    } = $props();

    let open = $state(false);
    const questions = $derived(inGroup ? GROUP_MENU : QUESTION_MENU);
    const kinds = KINDS as Record<
        string,
        { name: string; icon: typeof Plus; hint: string }
    >;
</script>

<div class="relative">
    <button
        type="button"
        class="btn"
        aria-expanded={open}
        onclick={() => (open = !open)}
        ><Plus class="h-3.5 w-3.5" />{label}</button
    >
    {#if open}
        <div
            class="fixed inset-0 z-30"
            role="presentation"
            onclick={() => (open = false)}
        ></div>
        <div
            class="popover absolute left-0 z-40 mt-1 w-[min(560px,calc(100vw-48px))] p-2"
            role="menu"
        >
            <p
                class="px-2 pb-1 text-[11px] font-semibold tracking-wide text-fg-faint uppercase"
            >
                Questions
            </p>
            <div class="grid gap-0.5 sm:grid-cols-2">
                {#each questions as type (type)}
                    {@const kind = kinds[type]}
                    <button
                        type="button"
                        role="menuitem"
                        class="menu-item flex items-start gap-2 text-left"
                        onclick={() => {
                            onadd(type);
                            open = false;
                        }}
                    >
                        <kind.icon
                            class="mt-0.5 h-4 w-4 shrink-0 text-fg-muted"
                        />
                        <span
                            ><span class="block text-[13px] text-fg"
                                >{kind.name}</span
                            ><span class="block text-xs text-fg-muted"
                                >{kind.hint}</span
                            ></span
                        >
                    </button>
                {/each}
            </div>
            {#if !inGroup}
                <p
                    class="px-2 pt-2 pb-1 text-[11px] font-semibold tracking-wide text-fg-faint uppercase"
                >
                    Content
                </p>
                <div class="grid gap-0.5 sm:grid-cols-2">
                    {#each CONTENT_MENU as type (type)}
                        {@const kind = kinds[type]}
                        <button
                            type="button"
                            role="menuitem"
                            class="menu-item flex items-start gap-2 text-left"
                            onclick={() => {
                                onadd(type);
                                open = false;
                            }}
                        >
                            <kind.icon
                                class="mt-0.5 h-4 w-4 shrink-0 text-fg-muted"
                            />
                            <span
                                ><span class="block text-[13px] text-fg"
                                    >{kind.name}</span
                                ><span class="block text-xs text-fg-muted"
                                    >{kind.hint}</span
                                ></span
                            >
                        </button>
                    {/each}
                </div>
            {/if}
        </div>
    {/if}
</div>
