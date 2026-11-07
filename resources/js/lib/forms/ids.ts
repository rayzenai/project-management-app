/** New ids for steps (`s_`), blocks (`q_`) and options (`o_`), as FormSchema::newId makes. */
export function newId(prefix: 's' | 'q' | 'o'): string {
    const alphabet = 'abcdefghijklmnopqrstuvwxyz0123456789';
    const bytes = crypto.getRandomValues(new Uint8Array(10));

    return `${prefix}_${Array.from(bytes, (b) => alphabet[b % alphabet.length]).join('')}`;
}
