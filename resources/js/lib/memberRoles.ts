import type { Id, Member, MemberRoleFields, RoleOptions } from './types';

const FIELDS = [
    'managed_program_ids',
    'managed_project_ids',
    'team_ids',
] as const;

/** The programs and projects of one organization, as the person form lists them. */
export function roleOptionsFor(
    options: RoleOptions,
    organizationId: Id | null | undefined,
): RoleOptions {
    return {
        programs: options.programs.filter(
            (p) => p.organization_id === organizationId,
        ),
        projects: options.projects.filter(
            (p) => p.organization_id === organizationId,
        ),
    };
}

/** What the person holds now; nothing while they are being added. */
export function heldRoles(person: Member | null): MemberRoleFields {
    return {
        managed_program_ids: [...(person?.managed_program_ids ?? [])],
        managed_project_ids: [...(person?.managed_project_ids ?? [])],
        team_ids: [...(person?.team_ids ?? [])],
    };
}

/**
 * The role fields to send. Each is the complete wanted set: what is ticked
 * among the listed options, plus what the person holds outside them (left
 * as it is). Only the fields that changed go, so a save that touches no
 * role changes none.
 */
export function rolesToSend(
    ticked: MemberRoleFields,
    options: RoleOptions,
    person: Member | null,
): Partial<MemberRoleFields> {
    const held = heldRoles(person);
    const listed: MemberRoleFields = {
        managed_program_ids: options.programs.map((p) => p.id),
        managed_project_ids: options.projects.map((p) => p.id),
        team_ids: options.projects.flatMap((p) =>
            p.team_id === null ? [] : [p.team_id],
        ),
    };
    const send: Partial<MemberRoleFields> = {};

    for (const field of FIELDS) {
        const wanted = [
            ...held[field].filter((id) => !listed[field].includes(id)),
            ...ticked[field].filter((id) => listed[field].includes(id)),
        ];
        const unchanged =
            wanted.length === held[field].length &&
            wanted.every((id) => held[field].includes(id));

        if (!unchanged) {
            send[field] = wanted;
        }
    }

    return send;
}

/** Ticks or unticks one id. */
export function toggled(ids: Id[], id: Id, on: boolean): Id[] {
    if (!on) {
        return ids.filter((x) => x !== id);
    }

    return ids.includes(id) ? ids : [...ids, id];
}
