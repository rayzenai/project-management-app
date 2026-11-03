/**
 * A forgiving filter, as the sidebar's and the project picker's are: the
 * items holding what was typed come first, then those holding its letters
 * in order ("pms" finds PMIS), each group in list order. An empty query
 * keeps every item.
 */
export function forgivingFilter<T>(
    items: T[],
    query: string,
    text: (item: T) => string,
): T[] {
    const needle = query.trim().toLowerCase().replace(/\s+/g, ' ');

    if (needle === '') {
        return items;
    }

    const inOrder = (haystack: string) => {
        let at = 0;

        for (const ch of haystack) {
            if (ch === needle[at] && ++at === needle.length) {
                return true;
            }
        }

        return false;
    };
    const rows = items.map((item) => ({
        item,
        text: text(item).toLowerCase(),
    }));

    return [
        ...rows.filter((row) => row.text.includes(needle)),
        ...rows.filter(
            (row) => !row.text.includes(needle) && inOrder(row.text),
        ),
    ].map((row) => row.item);
}
