import assert from 'node:assert/strict';
import test from 'node:test';

import { solutionBlock } from '../../src/js/profile/work-review-solution.js';

test('эталонное решение скрыто, когда автор не заполнил текст или код', () => {
    assert.equal(solutionBlock(null), '');
    assert.equal(solutionBlock({ html: '  ', code: '' }), '');
});

test('под заданием раскрывается авторский текст решения', () => {
    const html = solutionBlock({ html: '<p>Решение по шагам</p>', code: '' });

    assert.match(html, /<details class="wr-solution">/);
    assert.doesNotMatch(html, /wr-solution-body/);
    assert.match(html, /Показать эталонное решение/);
    assert.match(html, /<p>Решение по шагам<\/p>/);
    assert.doesNotMatch(html, /Листинг кода/);
});

test('эталонный код сохраняет форматирование и экранируется как текст', () => {
    const html = solutionBlock({ html: '', code: 'if (a < b) {\n  print("ok");\n}' });

    // Листинг без подписи; `js-code` разворачивает initCodeBlocks() в блок кода с подсветкой.
    assert.doesNotMatch(html, /Листинг кода/);
    assert.match(html, /<code class="js-code">/);
    assert.doesNotMatch(html, /wr-solution-body/);
    assert.match(html, /a &lt; b/);
    assert.match(html, /print\(&quot;ok&quot;\)/);
    assert.doesNotMatch(html, /a < b/);
});
