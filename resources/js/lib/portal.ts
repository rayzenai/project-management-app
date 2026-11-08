/**
 * Moves an element to the end of <body> while it is mounted
 * (`use:portal`), so no container can clip or cover it: a popover's panel,
 * a bottom sheet, a dialog opened from the top bar (whose blur makes it the
 * box fixed elements are placed in), the organogram's print-only chart.
 */
export function portal(node: HTMLElement): { destroy: () => void } {
    document.body.appendChild(node);

    return {
        destroy: () => node.remove(),
    };
}
