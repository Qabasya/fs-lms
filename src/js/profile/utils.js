/* Shared utilities: toast, HTML escaping, context menu state */

let toastTimer;

export function toast(msg) {
    const t = document.getElementById('profToast');
    if (!t) return;
    t.querySelector('span').textContent = msg;
    t.classList.add('show');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => t.classList.remove('show'), 2000);
}

export function esc(s) {
    return String(s)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

/* ── Context menu (shared) ─────────────────────────────────────── */
let ctxOnClose = null;

export function openCtxMenu(anchor, items, onPick) {
    const menu = document.getElementById('profCtxMenu');
    const backdrop = document.getElementById('profCtxBackdrop');
    if (!menu || !backdrop) return;

    menu.innerHTML = items.map(it => `<div class="ctx-item ${it.active ? 'on' : ''}" data-v="${esc(it.v)}">
        <span class="ctx-check">${it.active ? '<svg width="15" height="15" viewBox="0 0 20 20" fill="none"><path d="M4 10.5 8 14l8-8.5" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"/></svg>' : ''}</span>
        ${it.swatch ? `<span class="ctx-sw" style="background:${it.swatch}">${esc(it.chip || '')}</span>` : ''}
        <span class="ctx-lbl">${esc(it.label)}</span>
    </div>`).join('');

    menu.querySelectorAll('.ctx-item').forEach(el =>
        el.addEventListener('click', () => { onPick(el.dataset.v); closeCtxMenu(); })
    );

    const r = anchor.getBoundingClientRect();
    menu.classList.add('open');
    menu.style.minWidth = Math.max(220, r.width) + 'px';
    menu.style.left = Math.max(10, Math.min(r.left, window.innerWidth - 300)) + 'px';
    menu.style.top = (r.bottom + 6) + 'px';
    backdrop.classList.add('open');
    ctxOnClose = null;
}

export function openCtxMenuRaw(html, anchor, onClose) {
    const menu = document.getElementById('profCtxMenu');
    const backdrop = document.getElementById('profCtxBackdrop');
    if (!menu || !backdrop) return;
    menu.innerHTML = html;
    const r = anchor.getBoundingClientRect();
    menu.classList.add('open');
    let left = Math.min(r.left, window.innerWidth - 220);
    menu.style.left = Math.max(10, left) + 'px';
    menu.style.top = (r.bottom + 4) + 'px';
    backdrop.classList.add('open');
    ctxOnClose = onClose || null;
}

export function closeCtxMenu() {
    const menu = document.getElementById('profCtxMenu');
    const backdrop = document.getElementById('profCtxBackdrop');
    if (menu) menu.classList.remove('open');
    const gradePop = document.getElementById('profGradePop');
    if (!gradePop || !gradePop.classList.contains('open')) {
        if (backdrop) backdrop.classList.remove('open');
    }
    if (ctxOnClose) { ctxOnClose(); ctxOnClose = null; }
}

/* ── Grade popover (shared) ────────────────────────────────────── */
export function openGradePopPositioned(pop, anchor) {
    pop.classList.add('open');
    const r = anchor.getBoundingClientRect();
    const pw = 188, ph = pop.offsetHeight;
    let left = r.left + r.width / 2 - pw / 2;
    left = Math.max(10, Math.min(left, window.innerWidth - pw - 10));
    let top = r.bottom + 6;
    if (top + ph > window.innerHeight - 10) top = r.top - ph - 6;
    pop.style.left = left + 'px';
    pop.style.top = top + 'px';
    const backdrop = document.getElementById('profCtxBackdrop');
    if (backdrop) backdrop.classList.add('open');
}

export function closeGradePop() {
    const pop = document.getElementById('profGradePop');
    if (pop) pop.classList.remove('open');
    const menu = document.getElementById('profCtxMenu');
    if (!menu || !menu.classList.contains('open')) {
        const backdrop = document.getElementById('profCtxBackdrop');
        if (backdrop) backdrop.classList.remove('open');
    }
}
