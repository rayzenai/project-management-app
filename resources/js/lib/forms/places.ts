/**
 * Nepal's provinces, districts and local levels (with ward counts), loaded
 * only when a form asks for a place: resources/data/nepal-places.json, the
 * list the server checks a place against.
 */

export interface Place {
    id: string;
    en: string;
    ne: string;
    wards?: number;
}

export interface Places {
    provinces: Place[];
    districts: Place[];
    locals: Place[];
}

let loading: Promise<Places> | null = null;

export function loadPlaces(): Promise<Places> {
    loading ??= import('../../../data/nepal-places.json').then(
        (module) => module.default as unknown as Places,
    );

    return loading;
}

/** The places directly under a parent id (a province's districts, ...). */
export function under(list: Place[], parent: string): Place[] {
    return list.filter((place) => place.id.startsWith(`${parent}.`));
}
