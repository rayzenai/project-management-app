/**
 * The key a title suggests: the first letter of each of its first four
 * words (Latin script, folded to ASCII), "P" when it has none. The twin of
 * ProjectKeys::suggest on the server, which then makes it unique in the
 * organization (IP taken gives IPB).
 */
export function suggestProjectKey(title: string): string {
    const initials = title
        .split(/\s+/u)
        .map((word) =>
            word
                .replace(/[^\p{Script=Latin}]/gu, '')
                .normalize('NFD')
                .replace(/[^A-Za-z]/g, '')
                .charAt(0),
        )
        .filter((initial) => initial !== '')
        .slice(0, 4)
        .join('');

    return initials !== '' ? initials.toUpperCase() : 'P';
}
