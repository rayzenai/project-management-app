/**
 * Moves an element to the end of <body>, so a dialog opened from the top
 * bar (whose blur makes it the box fixed elements are placed in) covers the
 * whole page.
 */
export function portal(node: HTMLElement): { destroy: () => void } {
    document.body.appendChild(node);

    return {
        destroy: () => node.remove(),
    };
}
