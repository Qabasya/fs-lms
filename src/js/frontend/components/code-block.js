import { icoCheck, icoCopy } from '../../common/icons.js';

// ── Ключевые слова и встроенные функции Python ──────────────────────────────

const KEYWORDS = new Set([
    'False','None','True','and','as','assert','async','await',
    'break','class','continue','def','del','elif','else','except',
    'finally','for','from','global','if','import','in','is',
    'lambda','nonlocal','not','or','pass','raise','return',
    'try','while','with','yield',
]);

const BUILTINS = new Set([
    'abs','all','any','bin','bool','bytes','callable','chr',
    'dict','dir','divmod','enumerate','eval','filter','float',
    'format','frozenset','getattr','globals','hasattr','hash',
    'hex','id','input','int','isinstance','issubclass','iter',
    'len','list','locals','map','max','min','next','object',
    'oct','open','ord','pow','print','property','range','repr',
    'reversed','round','set','setattr','slice','sorted',
    'staticmethod','str','sum','super','tuple','type','vars','zip',
]);

// Порядок альтернатив важен: тройные кавычки > одиночные > комментарий > число > идентификатор
const TOKEN_RE = /[brfuBRFU]{0,2}(?:"""[\s\S]*?"""|'''[\s\S]*?''')|[brfuBRFU]{0,2}(?:"(?:[^"\\]|\\.)*"|'(?:[^'\\]|\\.)*')|#[^\n]*|\b0x[\da-fA-F]+\b|\b\d+\.?\d*(?:[eE][+-]?\d+)?\b|[A-Za-z_]\w*/g;

// ── Точка входа ──────────────────────────────────────────────────────────────

// Хук — класс .js-code на <code>: разметку печатает шаблон (сырой листинг из
// TaskContentDTO), редактор с подсветкой собирается здесь.
export function initCodeBlocks() {
    document.querySelectorAll('code.js-code').forEach(code => {
        const block = code.closest('pre') || code;
        const text  = normalize(code.textContent);
        const lang  = code.dataset.lang || 'Python';

        block.replaceWith(buildEditor(text, lang));
    });
}

// Срезаем весь хвост из пустых и пробельных строк (\s включает пробелы, табы,
// переводы строк), чтобы gutter и <pre> не показывали лишние строки в конце.
function normalize(text) {
    return text.replace(/\s+$/, '');
}

// ── Сборка редактора (read-only) ─────────────────────────────────────────────

function buildEditor(text, lang) {
    const editor = document.createElement('div');
    editor.className = 'fs-code-editor';

    editor.appendChild(buildHeader(lang, () => navigator.clipboard.writeText(text)));
    editor.appendChild(buildBody(text));

    return editor;
}

function buildBody(text) {
    const lineCount = text.split('\n').length;

    const gutter = document.createElement('div');
    gutter.className = 'fs-code-gutter';
    gutter.setAttribute('aria-hidden', 'true');
    for (let i = 1; i <= lineCount; i++) {
        const span = document.createElement('span');
        span.textContent = String(i);
        gutter.appendChild(span);
    }

    const highlight = document.createElement('pre');
    highlight.className = 'fs-code-highlight';
    highlight.innerHTML = tokenize(text);

    const area = document.createElement('div');
    area.className = 'fs-code-area';
    area.appendChild(highlight);

    const body = document.createElement('div');
    body.className = 'fs-code-editor__body';
    body.appendChild(gutter);
    body.appendChild(area);

    return body;
}

// ── Сборка шапки ─────────────────────────────────────────────────────────────
// Принимает callback вместо ссылки на DOM тела (принцип IoC / SRP)

function buildHeader(lang, onCopy) {
    const header = document.createElement('div');
    header.className = 'fs-code-editor__header';

    const badge = document.createElement('span');
    badge.className   = 'fs-code-editor__lang';
    badge.textContent = lang;

    const copyBtn = document.createElement('button');
    copyBtn.type      = 'button';
    copyBtn.className  = 'fs-code-editor__btn';
    copyBtn.setAttribute('aria-label', 'Скопировать код');
    copyBtn.innerHTML = icoCopy();

    copyBtn.addEventListener('click', () => {
        onCopy().then(() => {
            copyBtn.innerHTML = icoCheck();
            copyBtn.classList.add('is-copied');
            setTimeout(() => {
                copyBtn.innerHTML = icoCopy();
                copyBtn.classList.remove('is-copied');
            }, 2000);
        });
    });

    header.appendChild(badge);
    header.appendChild(copyBtn);

    return header;
}

// ── Токенайзер ───────────────────────────────────────────────────────────────

function tokenize(code) {
    TOKEN_RE.lastIndex = 0;
    const tokens = [];
    let last = 0;
    let m;

    while ((m = TOKEN_RE.exec(code)) !== null) {
        if (m.index > last) {
            tokens.push({ type: null, value: code.slice(last, m.index) });
        }
        tokens.push({ type: classify(m[0]), value: m[0] });
        last = m.index + m[0].length;
    }

    if (last < code.length) {
        tokens.push({ type: null, value: code.slice(last) });
    }

    // Имя функции — первый идентификатор после ключевого слова def
    for (let i = 0; i < tokens.length; i++) {
        if (tokens[i].type === 'keyword' && tokens[i].value === 'def') {
            for (let j = i + 1; j < tokens.length; j++) {
                if (/^\s*$/.test(tokens[j].value)) continue;
                if (/^[A-Za-z_]\w*$/.test(tokens[j].value)) tokens[j].type = 'function';
                break;
            }
        }
    }

    return tokens
        .map(({ type, value }) => {
            const esc = escHtml(value);
            return type ? `<span class="fs-hl-${type}">${esc}</span>` : esc;
        })
        .join('');
}

// Строки гарантированно содержат кавычки (по структуре TOKEN_RE), поэтому
// проверка через includes безопасна — идентификаторы и числа кавычек не содержат
function classify(val) {
    if (val[0] === '#') return 'comment';
    if (val.includes('"') || val.includes("'")) return 'string';
    if (/^\d/.test(val)) return 'number';
    if (KEYWORDS.has(val)) return 'keyword';
    if (BUILTINS.has(val)) return 'builtin';
    return null;
}

// ── Вспомогательные функции ──────────────────────────────────────────────────

function escHtml(str) {
    return str
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}