import assert from 'node:assert/strict';
import { readFileSync, readdirSync } from 'node:fs';
import test from 'node:test';

/* Правило кабинета (src/js/CLAUDE.md, «Выпадающие списки»): выбор из списка — пикер (picker.js: kp-btn + контекстное меню),
   а не родной select. Старые списки переводятся по мере правки экранов, новые не появляются: тест держит «потолок» по файлам. */

const ROOT = new URL('../../src/js/profile/', import.meta.url);

/** Наследие: сколько родных select осталось в файле. Перевёл список на пикер — уменьши число (или убери строку). */
const LEGACY = {
    'activity.js': 1,
    'exams/exam-conduct.js': 3,
    'exams/exam-event-form.js': 1,
    'exams/exam-payments.js': 2,
    'exams/exam-results.js': 6,
    'exams/exam-row-filters.js': 2,
    'exams/exam-session-actions.js': 1,
    'exams/exam-session-form.js': 2,
    'exams/exam-sources.js': 1,
    'exams/exam-stats.js': 2,
    'indi-modal.js': 4,
    'ktp/ktp-templates.js': 2,
    'learner-child.js': 1,
    'substitutions.js': 2,
    'task-render.js': 1,
    'work-review.js': 1,
};

function nativeSelects() {
    const found = {};
    for (const entry of readdirSync(ROOT, { recursive: true })) {
        const file = String(entry).replaceAll('\\', '/');
        if (!file.endsWith('.js')) { continue; }
        const count = (readFileSync(new URL(file, ROOT), 'utf8').match(/<select\b/g) || []).length;
        if (count) { found[file] = count; }
    }

    return found;
}

test('в кабинете нет новых родных select: выбор из списка — пикер из picker.js', () => {
    const found = nativeSelects();
    const added = Object.entries(found)
        .filter(([file, count]) => count > (LEGACY[file] ?? 0))
        .map(([file, count]) => `${file}: ${count} (допустимо ${LEGACY[file] ?? 0})`);

    assert.deepEqual(added, [], 'Новый <select> в кабинете запрещён: optionPickerBtnHtml + openOptionPicker (src/js/profile/picker.js)');
});

test('список наследия актуален: переведённые на пикер select из него убраны', () => {
    const found = nativeSelects();
    const stale = Object.entries(LEGACY)
        .filter(([file, count]) => (found[file] ?? 0) < count)
        .map(([file, count]) => `${file}: в списке ${count}, в файле ${found[file] ?? 0}`);

    assert.deepEqual(stale, [], 'Уменьши число в LEGACY (tests/js/profile-no-native-select.test.mjs) — этот select уже заменён пикером');
});
