/**
 * Copy without awaiting the async clipboard API. In some contexts (missing
 * permission, non-secure origin, headless) `writeText` never settles, which
 * would leave the button with no feedback at all — so we kick it off, fall
 * back to the legacy path if it rejects, and report straight away.
 */
export function writeToClipboard(text: string): void {
    if (navigator.clipboard?.writeText) {
        void navigator.clipboard.writeText(text).catch(() => legacy(text));

        return;
    }

    legacy(text);
}

function legacy(text: string): void {
    const field = document.createElement('textarea');
    field.value = text;
    field.setAttribute('readonly', '');
    field.style.position = 'fixed';
    field.style.opacity = '0';
    document.body.appendChild(field);
    field.select();

    try {
        document.execCommand('copy');
    } catch {
        /* nothing more to try; the caller's toast still says what was copied */
    }

    field.remove();
}
