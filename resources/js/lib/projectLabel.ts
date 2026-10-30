/**
 * How text names a project: its title, with its program's name when it has
 * one ("General (Revenue)"), since every program has its own General. The
 * twin of Project::label() on the server.
 */
export function projectLabel(project: {
    title: string;
    program?: string | null;
}): string {
    return project.program
        ? `${project.title} (${project.program})`
        : project.title;
}
