import assert from 'node:assert/strict';
import test from 'node:test';
import { showExamSignupNotice } from '../../src/js/profile/exams/exam-signup-notice.js';

test('уведомление в кабинете показывается один раз и ссылка на экран сохраняется', () => {
    let message = '';
    let url = '';
    const location = { search: '?screen=learner-exams&exam_signup_notice=cabinet', pathname: '/profile/', hash: '' };
    const history = { state: {}, replaceState: (_state, _title, value) => { url = value; } };
    showExamSignupNotice(location, history, text => { message = text; });
    assert.match(message, /запись на экзамен доступна в личном кабинете/);
    assert.equal(url, '/profile/?screen=learner-exams');
});

test('произвольный параметр не создаёт уведомление', () => {
    showExamSignupNotice({ search: '?exam_signup_notice=other' }, {}, () => assert.fail('Лишнее уведомление'));
});
