/**
 * Dependencies on the board: within a column every task sits below each task
 * it waits for (directly or through others), and dotted lines join a task to
 * what it waits for. Loops are refused when links are made, so such an order
 * always exists; apart from that the board keeps the order people gave it.
 */

interface Linked {
    id: number;
    dependencies?: { id: number }[];
}

/** For each task, every task it waits for, directly or through others. */
export function prerequisitesOf(tasks: Linked[]): Map<number, Set<number>> {
    const direct = new Map(
        tasks.map((t) => [t.id, (t.dependencies ?? []).map((d) => d.id)]),
    );
    const all = new Map<number, Set<number>>();

    const visit = (id: number, trail: Set<number>): Set<number> => {
        const known = all.get(id);

        if (known) {
            return known;
        }

        const found = new Set<number>();
        trail.add(id);

        for (const dep of direct.get(id) ?? []) {
            found.add(dep);

            if (!trail.has(dep)) {
                visit(dep, trail).forEach((p) => found.add(p));
            }
        }

        trail.delete(id);
        all.set(id, found);

        return found;
    };

    tasks.forEach((t) => visit(t.id, new Set()));

    return all;
}

/** `list` with each task after everything it waits for, otherwise as given. */
export function orderByDependencies<T extends { id: number }>(
    list: T[],
    prerequisites: Map<number, Set<number>>,
): T[] {
    const ids = new Set(list.map((t) => t.id));
    const placed = new Set<number>();
    const rest = [...list];
    const ordered: T[] = [];

    while (rest.length > 0) {
        const next = rest.findIndex((t) =>
            [...(prerequisites.get(t.id) ?? [])].every(
                (p) => !ids.has(p) || placed.has(p),
            ),
        );
        // -1 only if the data holds a loop: keep the given order then.
        const [task] = rest.splice(Math.max(next, 0), 1);
        placed.add(task.id);
        ordered.push(task);
    }

    return ordered;
}

/**
 * Where a dragged task may land in a column (slots as displayed, the task
 * itself included): below what it waits for, above what waits for it.
 */
export function allowedSlots(
    list: { id: number }[],
    taskId: number,
    prerequisites: Map<number, Set<number>>,
): { min: number; max: number } {
    let min = 0;
    let max = list.length;

    list.forEach((t, i) => {
        if (prerequisites.get(taskId)?.has(t.id)) {
            min = Math.max(min, i + 1);
        }

        if (prerequisites.get(t.id)?.has(taskId)) {
            max = Math.min(max, i);
        }
    });

    return { min, max };
}

export interface CardBox {
    id: number;
    /** Which column, counted from the left. */
    col: number;
    left: number;
    top: number;
    width: number;
    height: number;
}

/** Below a card's top edge, level with its code: where lines meet it. */
const ANCHOR = 21;
/** Above a card's bottom edge: where a bracket to the card below leaves. */
const FOOT = 14;
/** Keeps a line's starting dot clear of the card edge. */
const GAP = 3;
/** Same-column brackets, from the cards' right edge; the gutter is 20px. */
const LANES = [8, 12.5, 17];
/** How far a far link's stubs reach out of their cards. */
const STUB = 15;

export type LinkKind = 'bracket' | 'curve' | 'far';

/**
 * SVG paths for the links whose two cards are both on the board, in board
 * coordinates. Each runs from the task waited for to the task waiting, and
 * stays in the gutters between cards: in one column a bracket on the right
 * (nested ones step outwards), between neighbouring columns a curve. A link
 * that would cross a whole column (`far`) is drawn as two `stub`s leaving
 * the cards toward each other; its full curve `d` crosses the cards between.
 */
export function linkPaths<T extends { from: number; to: number }>(
    cards: CardBox[],
    links: T[],
): (T & { kind: LinkKind; d: string; stub: string | null })[] {
    const at = new Map(cards.map((c) => [c.id, c]));
    const taken: { col: number; lo: number; hi: number; lane: number }[] = [];

    const drawn = links.flatMap((link) => {
        const a = at.get(link.from);
        const b = at.get(link.to);

        return a && b ? [{ link, a, b }] : [];
    });

    const brackets = drawn
        .filter(({ a, b }) => a.col === b.col)
        .map(({ link, a, b }) => {
            const down = a.top < b.top;

            return {
                link,
                col: a.col,
                x: Math.max(a.left + a.width, b.left + b.width),
                y0: down ? a.top + a.height - FOOT : a.top + ANCHOR,
                y1: down ? b.top + ANCHOR : b.top + b.height - FOOT,
            };
        })
        // Shortest first, so inner brackets take the inner lanes.
        .sort((p, q) => Math.abs(p.y1 - p.y0) - Math.abs(q.y1 - q.y0))
        .map((b) => {
            const lo = Math.min(b.y0, b.y1);
            const hi = Math.max(b.y0, b.y1);
            let lane = 0;

            while (
                lane < LANES.length - 1 &&
                taken.some(
                    (t) =>
                        t.col === b.col &&
                        t.lane === lane &&
                        t.lo <= hi &&
                        lo <= t.hi,
                )
            ) {
                lane++;
            }

            taken.push({ col: b.col, lo, hi, lane });

            const xl = b.x + LANES[lane];
            const s = b.y1 >= b.y0 ? 1 : -1;
            const r = Math.min(4, Math.abs(b.y1 - b.y0) / 2);

            return {
                ...b.link,
                kind: 'bracket' as const,
                stub: null,
                d: `M${b.x + GAP} ${b.y0} H${xl - r} Q${xl} ${b.y0} ${xl} ${b.y0 + s * r} V${b.y1 - s * r} Q${xl} ${b.y1} ${xl - r} ${b.y1} H${b.x}`,
            };
        });

    const curves = drawn
        .filter(({ a, b }) => a.col !== b.col)
        .map(({ link, a, b }) => {
            const dir = b.col > a.col ? 1 : -1;
            const x0 = dir > 0 ? a.left + a.width + GAP : a.left - GAP;
            const x1 = dir > 0 ? b.left : b.left + b.width;
            const y0 = a.top + ANCHOR;
            const y1 = b.top + ANCHOR;
            const c = (x1 - x0) / 2;
            const far = Math.abs(b.col - a.col) > 1;

            return {
                ...link,
                kind: far ? ('far' as const) : ('curve' as const),
                d: `M${x0} ${y0} C${x0 + c} ${y0} ${x1 - c} ${y1} ${x1} ${y1}`,
                stub: far
                    ? `M${x0} ${y0} H${x0 + dir * STUB} M${x1 - dir * STUB} ${y1} H${x1}`
                    : null,
            };
        });

    return [...brackets, ...curves];
}
