import assert from 'node:assert/strict';
import { test } from 'node:test';

/* Приветствие главной: ученику «Привет, Имя», родителю «Здравствуйте, Имя Отчество»;
   в день рождения — «🎉 С днём рождения, Имя! 🎉». */

function setup(user, readOnly) {
    globalThis.window = { fsProfile: { user, readOnly } };
}

const { greetingHtml } = await import('../../src/js/profile/learner.js');

test('ученику — «Привет, Имя»: имя из Person, без него — из display name без фамилии', () => {
    setup({ name: 'Иванов Иван', firstName: 'Иван', middleName: '', birthdayToday: false }, false);
    assert.equal(greetingHtml(), '<h1>Привет,<span class="prof-hello-name"> Иван</span></h1>');

    setup({ name: 'Иванов Иван' }, false);
    assert.equal(greetingHtml(), '<h1>Привет,<span class="prof-hello-name"> Иван</span></h1>');

    setup({ name: '' }, false);
    assert.equal(greetingHtml(), '<h1>Привет,<span class="prof-hello-name"> ученик</span></h1>');
});

test('родителю — «Здравствуйте, Имя Отчество» из Person, без него — разбор display name', () => {
    setup({ name: 'Петрова Мария Сергеевна', firstName: 'Мария', middleName: 'Сергеевна', birthdayToday: false }, true);
    assert.equal(greetingHtml(), '<h1>Здравствуйте,<span class="prof-hello-name"> Мария Сергеевна</span></h1>');

    setup({ name: 'Петрова Мария Сергеевна' }, true);
    assert.equal(greetingHtml(), '<h1>Здравствуйте,<span class="prof-hello-name"> Мария Сергеевна</span></h1>');

    setup({ name: 'Петрова Мария' }, true);
    assert.equal(greetingHtml(), '<h1>Здравствуйте,<span class="prof-hello-name"> Мария</span></h1>');
});

test('в день рождения — поздравление вместо приветствия, с отчеством у родителя', () => {
    setup({ name: 'Иванов Иван', firstName: 'Иван', middleName: '', birthdayToday: true }, false);
    assert.equal(greetingHtml(), '<h1><span class="prof-hello-name">🎉 С днём рождения, Иван! 🎉</span></h1>');

    setup({ name: 'Петрова Мария Сергеевна', firstName: 'Мария', middleName: 'Сергеевна', birthdayToday: true }, true);
    assert.equal(greetingHtml(), '<h1><span class="prof-hello-name">🎉 С днём рождения, Мария Сергеевна! 🎉</span></h1>');
});

test('имя экранируется', () => {
    setup({ name: '<b>x</b>', firstName: '<b>x</b>', middleName: '', birthdayToday: false }, false);
    assert.equal(greetingHtml(), '<h1>Привет,<span class="prof-hello-name"> &lt;b&gt;x&lt;/b&gt;</span></h1>');
});
