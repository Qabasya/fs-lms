/** Scoped schedule/KTP/journal selector; work and participant views keep the complete group list. */
export function ordinaryGroups(groups) {
    return Array.isArray(groups) ? groups.filter(g => !Number(g.self_paced_course_id)) : [];
}
