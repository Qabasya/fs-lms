import { escapeHtml } from './utils.js';

const KEYWORDS = new Set('alignas auto bool break case char class const constexpr continue default do double else enum extern false float for if inline int long namespace new nullptr private protected public return short signed sizeof static struct switch template true typedef union unsigned using void volatile while'.split(' '));
const BUILTINS = new Set('HIGH LOW INPUT OUTPUT INPUT_PULLUP LED_BUILTIN A0 A1 A2 A3 A4 A5 Serial Wire SPI String byte boolean word pinMode digitalRead digitalWrite analogRead analogWrite analogReference delay delayMicroseconds millis micros pulseIn tone noTone shiftIn shiftOut map constrain random randomSeed attachInterrupt detachInterrupt'.split(' '));

/** Подсветка листинга Arduino/Wiring. Исходный текст всегда экранируется. */
export function highlightWiring( code ) {
    const tokens = /\/\/[^\n]*|\/\*[\s\S]*?(?:\*\/|$)|"(?:\\[\s\S]|[^"\\\n])*"|'(?:\\[\s\S]|[^'\\\n])*'|#\s*[a-zA-Z_]\w*|\b(?:0[xX][\da-fA-F]+|0[bB][01]+|\d+(?:\.\d*)?(?:[eE][+-]?\d+)?)[uUlLfF]*\b|[a-zA-Z_]\w*/g;
    let html = '';
    let end = 0;
    for ( const match of code.matchAll( tokens ) ) {
        const token = match[ 0 ];
        let type;
        if ( token.startsWith( '/' ) ) type = 'comment';
        else if ( token.startsWith( '"' ) || token.startsWith( "'" ) ) type = 'string';
        else if ( /^\d/.test( token ) ) type = 'number';
        else if ( token.startsWith( '#' ) || KEYWORDS.has( token ) ) type = 'keyword';
        else if ( BUILTINS.has( token ) ) type = 'builtin';
        else if ( /^[ \t]*\(/.test( code.slice( match.index + token.length ) ) ) type = 'function';

        html += escapeHtml( code.slice( end, match.index ) );
        const escaped = escapeHtml( token );
        html += type ? '<span class="fs-hl-' + type + '">' + escaped + '</span>' : escaped;
        end = match.index + token.length;
    }
    return html + escapeHtml( code.slice( end ) );
}
