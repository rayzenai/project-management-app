<script lang="ts">
    import { router, useForm } from '@inertiajs/svelte';
    import { formatTimeAgo } from '../lib/format';
    import type { Comment, Member, Task } from '../lib/types';
    import Avatar from './Avatar.svelte';

    let {
        comments,
        task,
        members,
    }: {
        comments: Comment[];
        task: Task;
        members: Member[];
    } = $props();

    /** Matches the canonical mention token `@[Display Name](member:ID)` (mirrors the PHP MentionParser). */
    const MENTION_RE = /@\[([^\]]+)\]\(member:(\d+)\)/g;

    type Segment =
        { type: 'text'; value: string } | { type: 'mention'; name: string };

    /**
     * Split a stored comment body into plain-text and mention segments so the
     * template renders mentions as chips and leaves everything else as escaped
     * text (Svelte escapes interpolated strings by default).
     */
    function parseBody(body: string): Segment[] {
        const segments: Segment[] = [];
        let lastIndex = 0;
        MENTION_RE.lastIndex = 0;
        let match: RegExpExecArray | null;

        while ((match = MENTION_RE.exec(body)) !== null) {
            if (match.index > lastIndex) {
                segments.push({
                    type: 'text',
                    value: body.slice(lastIndex, match.index),
                });
            }

            segments.push({ type: 'mention', name: match[1] });
            lastIndex = match.index + match[0].length;
        }

        if (lastIndex < body.length) {
            segments.push({ type: 'text', value: body.slice(lastIndex) });
        }

        return segments;
    }

    const composeForm = useForm({ body: '' });

    let textarea = $state<HTMLTextAreaElement | null>(null);
    let mentionOpen = $state(false);
    let mentionQuery = $state('');
    let mentionStart = $state(-1);
    let highlighted = $state(0);

    const mentionMatches = $derived(
        mentionOpen
            ? members
                  .filter((m) =>
                      m.name.toLowerCase().includes(mentionQuery.toLowerCase()),
                  )
                  .slice(0, 8)
            : [],
    );

    /**
     * Detect an in-progress `@mention` immediately before the caret. A token is
     * active while the caret sits in a run of non-space characters that starts
     * with `@` at a word boundary; it closes on space, newline, or no match.
     */
    function detectMention(): void {
        const el = textarea;

        if (!el) {
            return;
        }

        const caret = el.selectionStart ?? 0;
        const upto = composeForm.body.slice(0, caret);
        const at = upto.lastIndexOf('@');

        if (at === -1) {
            mentionOpen = false;

            return;
        }

        const before = at === 0 ? ' ' : upto[at - 1];
        const fragment = upto.slice(at + 1);

        if (!/^\s$/.test(before) || /[\s\n]/.test(fragment)) {
            mentionOpen = false;

            return;
        }

        mentionStart = at;
        mentionQuery = fragment;
        highlighted = 0;
        mentionOpen = true;
    }

    function insertMention(member: Member): void {
        const el = textarea;

        if (!el || mentionStart < 0) {
            return;
        }

        const caret = el.selectionStart ?? composeForm.body.length;
        const token = `@[${member.name}](member:${member.id})`;
        const next =
            composeForm.body.slice(0, mentionStart) +
            token +
            composeForm.body.slice(caret);
        composeForm.body = next;
        mentionOpen = false;
        const cursor = mentionStart + token.length;
        // Restore the caret just past the inserted token on the next tick.
        queueMicrotask(() => {
            el.focus();
            el.setSelectionRange(cursor, cursor);
        });
    }

    function onComposeKeydown(e: KeyboardEvent): void {
        if (mentionOpen && mentionMatches.length > 0) {
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                highlighted = (highlighted + 1) % mentionMatches.length;

                return;
            }

            if (e.key === 'ArrowUp') {
                e.preventDefault();
                highlighted =
                    (highlighted - 1 + mentionMatches.length) %
                    mentionMatches.length;

                return;
            }

            if (e.key === 'Enter' || e.key === 'Tab') {
                e.preventDefault();
                insertMention(mentionMatches[highlighted]);

                return;
            }

            if (e.key === 'Escape') {
                e.preventDefault();
                mentionOpen = false;

                return;
            }
        }

        if (e.key === 'Enter' && (e.metaKey || e.ctrlKey)) {
            e.preventDefault();
            submitComment();
        }
    }

    function submitComment(): void {
        if (!composeForm.body.trim() || composeForm.processing) {
            return;
        }

        composeForm.post(`/workspace/tasks/${task.id}/comments`, {
            preserveScroll: true,
            onSuccess: () => {
                composeForm.reset();
                mentionOpen = false;
            },
        });
    }

    function addComment(e: SubmitEvent): void {
        e.preventDefault();
        submitComment();
    }

    let editingId = $state<number | null>(null);
    let editDraft = $state('');
    let editProcessing = $state(false);

    function startEdit(comment: Comment): void {
        editingId = comment.id;
        editDraft = comment.body;
    }

    function cancelEdit(): void {
        editingId = null;
        editDraft = '';
    }

    function saveEdit(comment: Comment): void {
        const body = editDraft.trim();

        if (!body || editProcessing) {
            return;
        }

        if (body === comment.body) {
            cancelEdit();

            return;
        }

        editProcessing = true;
        router.patch(
            `/workspace/comments/${comment.id}`,
            { body },
            {
                preserveScroll: true,
                onFinish: () => {
                    editProcessing = false;
                },
                onSuccess: () => cancelEdit(),
            },
        );
    }

    function deleteComment(comment: Comment): void {
        if (!confirm('Delete this comment?')) {
            return;
        }

        router.delete(`/workspace/comments/${comment.id}`, {
            preserveScroll: true,
        });
    }
</script>

<div class="space-y-3">
    {#each comments as comment (comment.id)}
        <div
            class="group rounded-lg border border-line bg-surface-alt px-3.5 py-3"
        >
            <div class="flex items-center gap-2">
                <Avatar name={comment.author.name} size="sm" />
                <span class="font-medium text-fg"
                    >{comment.author.name ?? 'Someone'}</span
                >
                <span class="text-xs text-fg-faint"
                    >{formatTimeAgo(comment.created_at)}</span
                >
                {#if comment.updated_at && comment.updated_at !== comment.created_at}
                    <span class="text-xs text-fg-faint">edited</span>
                {/if}
                {#if comment.can_edit && editingId !== comment.id}
                    <span
                        class="ml-auto flex items-center gap-0.5 opacity-0 transition group-hover:opacity-100 focus-within:opacity-100"
                    >
                        <button
                            type="button"
                            onclick={() => startEdit(comment)}
                            class="btn-ghost h-6 px-1.5 text-xs">Edit</button
                        >
                        <button
                            type="button"
                            onclick={() => deleteComment(comment)}
                            class="btn-ghost h-6 px-1.5 text-xs hover:text-danger"
                            title="Delete comment">Delete</button
                        >
                    </span>
                {/if}
            </div>

            {#if editingId === comment.id}
                <textarea
                    bind:value={editDraft}
                    rows="3"
                    class="input mt-2 min-h-[72px] resize-y"
                ></textarea>
                <div class="mt-2 flex justify-end gap-1.5">
                    <button type="button" onclick={cancelEdit} class="btn-ghost"
                        >Cancel</button
                    >
                    <button
                        type="button"
                        onclick={() => saveEdit(comment)}
                        disabled={editProcessing || !editDraft.trim()}
                        class="btn-primary">Save</button
                    >
                </div>
            {:else}
                <p
                    class="mt-2 text-[13.5px] leading-relaxed whitespace-pre-wrap text-fg"
                >
                    {#each parseBody(comment.body) as seg, i (i)}
                        {#if seg.type === 'mention'}
                            <span
                                class="rounded-sm bg-accent-soft px-0.5 font-medium text-accent"
                                >@{seg.name}</span
                            >
                        {:else}{seg.value}{/if}
                    {/each}
                </p>
            {/if}
        </div>
    {:else}
        <p class="text-[13px] text-fg-muted">No comments yet.</p>
    {/each}

    <form
        onsubmit={addComment}
        class="rounded-lg border border-line bg-surface-alt px-3.5 py-3"
    >
        <div class="relative">
            <textarea
                bind:this={textarea}
                bind:value={composeForm.body}
                rows="3"
                placeholder="Write a comment. Type @ to mention a teammate."
                class="input min-h-[72px] resize-y border-0 bg-transparent p-0 focus:border-0"
                oninput={detectMention}
                onkeydown={onComposeKeydown}
                onclick={detectMention}
                onblur={() => queueMicrotask(() => (mentionOpen = false))}
            ></textarea>

            {#if mentionOpen && mentionMatches.length > 0}
                <ul
                    class="popover absolute right-0 left-0 z-30 mt-1 max-h-56 overflow-auto px-1"
                >
                    {#each mentionMatches as member, i (member.id)}
                        <li>
                            <button
                                type="button"
                                class={`menu-item ${i === highlighted ? 'bg-hover text-fg' : ''}`}
                                onmousedown={(e) => {
                                    e.preventDefault();
                                    insertMention(member);
                                }}
                            >
                                <Avatar name={member.name} size="sm" />
                                <span
                                    class="min-w-0 flex-1 truncate font-medium"
                                    >{member.name}</span
                                >
                            </button>
                        </li>
                    {/each}
                </ul>
            {/if}
        </div>
        <div class="mt-2 flex items-center justify-between gap-3">
            <p class="text-xs text-fg-faint">Type @ to mention a teammate</p>
            <button
                type="submit"
                disabled={composeForm.processing || !composeForm.body.trim()}
                class="btn-primary">Comment <kbd class="kbd">⌘↵</kbd></button
            >
        </div>
    </form>
</div>
