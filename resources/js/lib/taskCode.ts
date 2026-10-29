/**
 * A task's code as people and commit messages write it: SG-12, or #12 when
 * the project's code is not known.
 */
export function taskCode(
    projectCode: string | null | undefined,
    number: number | null | undefined,
): string {
    if (number === null || number === undefined) {
        return projectCode ?? '';
    }

    return projectCode ? `${projectCode}-${number}` : `#${number}`;
}
