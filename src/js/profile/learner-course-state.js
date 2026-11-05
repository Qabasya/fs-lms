/** Keys distinguish an unjoined course from existing groups, including equal numeric IDs. */
export const courseKey = (course) => course.key || `group:${course.group_id ?? course.id}`;
export const isCourseOffer = (course) => course.enrolled === false;
export const canStartCourse = (course, { parent = false, examLock = null } = {}) =>
    isCourseOffer(course) && course.can_start === true && !parent && !examLock;

export function initialCourseKey(courses, active, wantedGroup) {
    if (courses.some(course => courseKey(course) === active)) { return active; }
    const wanted = courses.find(course => !isCourseOffer(course) && Number(course.group_id ?? course.id) === wantedGroup);
    return courseKey(wanted || courses[0]);
}

/** Preview never carries a playable link, even if a stale response includes one. */
export const previewLessons = (lessons) => lessons.map(lesson => ({
    num: lesson.num, title: lesson.title, date: '', room: '', status: 'locked', player_url: '', mod: lesson.mod,
}));

/** One in-flight start across re-renders; rejection allows retry without optimistic enrollment. */
export function createCourseStarter({ start, reload, select, render, current }) {
    let busy = false;
    return {
        isBusy: () => busy,
        async run(course, context) {
            if (busy || !canStartCourse(course, context)) { return false; }
            busy = true;
            try {
                const result = await start(course.course_id);
                const profile = await reload();
                if (context.isCurrent && !context.isCurrent()) { return true; }
                if (!current || current() === courseKey(course)) { select(`group:${result.group_id}`); }
                busy = false;
                render(profile);
                return true;
            } finally { busy = false; }
        },
    };
}
