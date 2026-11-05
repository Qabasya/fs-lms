/** UI type mirrors GroupType; storage flags remain independent for legacy rows. */
export function groupTypeFromStorage(mode = 'scheduled', shared = false) {
    return shared ? (mode === 'scheduled' ? 'shared' : `legacy_${mode}_shared`) : mode;
}

export function groupTypeHasSchedule(type) {
    return type === 'scheduled' || type === 'shared';
}
