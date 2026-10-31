import type { Id, Member } from './types';

/**
 * An organization's structure: its designations and who reports to whom,
 * as OrganogramQuery returns them (the organogram page and the API).
 */

export interface Designation {
    id: Id;
    organization_id: Id;
    name: string;
    /** Smaller is more senior; orders the list. */
    rank: number;
    /** Active people who hold it. */
    people_count?: number;
}

/** A designation the person form offers. */
export interface DesignationOption {
    id: Id;
    organization_id: Id;
    name: string;
}

export interface OrgPerson {
    id: Id;
    name: string;
    designation_id: Id | null;
    designation: string | null;
    /** Null when they report to no active person of the organization. */
    reports_to_id: Id | null;
    is_office_admin: boolean;
    direct_reports_count: number;
}

export interface Organogram {
    organization: { id: Id; name: string };
    designations: Designation[];
    people: OrgPerson[];
    /** The viewer may change designations and reporting lines. */
    can_manage: boolean;
}

/** A person with everyone who reports to them, any number of levels down. */
export interface OrgNode {
    person: OrgPerson;
    children: OrgNode[];
    /** Everyone below, at any depth. */
    below: number;
}

/** A person on the People page, with their place in the structure. */
export type StructuredMember = Member & {
    designation?: { id: Id; name: string } | null;
    reports_to_id?: Id | null;
    attendance_exempt?: boolean;
};

/**
 * The reporting lines as a forest, siblings in the order given (the query
 * orders by designation rank, then name). Anyone the walk from the top never
 * reaches (a loop in old data) is shown at the top rather than lost.
 */
export function buildOrgTree(people: OrgPerson[]): OrgNode[] {
    const nodes = new Map<Id, OrgNode>(
        people.map((person) => [person.id, { person, children: [], below: 0 }]),
    );
    const roots: OrgNode[] = [];

    for (const node of nodes.values()) {
        const boss =
            node.person.reports_to_id === null
                ? undefined
                : nodes.get(node.person.reports_to_id);

        if (boss && boss !== node) {
            boss.children.push(node);
        } else {
            roots.push(node);
        }
    }

    const seen = new Set<Id>();

    const count = (node: OrgNode): number => {
        seen.add(node.person.id);
        node.below = node.children.reduce(
            (sum, child) => sum + 1 + count(child),
            0,
        );

        return node.below;
    };

    roots.forEach(count);

    for (const node of nodes.values()) {
        if (!seen.has(node.person.id)) {
            const boss = nodes.get(node.person.reports_to_id ?? -1);
            boss?.children.splice(boss.children.indexOf(node), 1);
            roots.push(node);
            count(node);
        }
    }

    return roots;
}

/** The ids of everyone below a person in the tree. */
export function idsBelow(node: OrgNode): Id[] {
    return node.children.flatMap((child) => [
        child.person.id,
        ...idsBelow(child),
    ]);
}

/** "1 person", "8 people". */
export function peopleCount(n: number): string {
    return n === 1 ? '1 person' : `${n} people`;
}

/** The person form's structure fields. */
export interface StructureFields {
    designation_id: Id | null;
    reports_to_id: Id | null;
    /** Left out of attendance's expected count. */
    attendance_exempt: boolean;
}

/** The person form's designations for one organization. */
export function designationsFor(
    options: DesignationOption[],
    organizationId: Id | null,
): DesignationOption[] {
    return options.filter(
        (option) => option.organization_id === organizationId,
    );
}

/**
 * Whom the person form offers as "Reports to": the organization's active
 * people other than the person (and their current higher authority, even
 * if deactivated, so the form shows it). The server refuses a loop.
 */
export function bossCandidates(
    members: StructuredMember[],
    organizationId: Id | null,
    person: StructuredMember | null,
): StructuredMember[] {
    return members.filter(
        (member) =>
            member.organization_id === organizationId &&
            member.id !== person?.id &&
            (member.is_active !== false || member.id === person?.reports_to_id),
    );
}

/** The structure fields that changed, for the person form to send. */
export function structureToSend(
    data: StructureFields,
    person: StructuredMember | null,
): Partial<StructureFields> {
    const sent: Partial<StructureFields> = {};

    if (data.designation_id !== (person?.designation?.id ?? null)) {
        sent.designation_id = data.designation_id;
    }

    if (data.reports_to_id !== (person?.reports_to_id ?? null)) {
        sent.reports_to_id = data.reports_to_id;
    }

    if (data.attendance_exempt !== (person?.attendance_exempt ?? false)) {
        sent.attendance_exempt = data.attendance_exempt;
    }

    return sent;
}
