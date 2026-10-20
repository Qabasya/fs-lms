/**
 * Плашка «Временный доступ» в шапке кабинета ученика.
 *
 * Данные — `window.fsProfile.trial.active` (ProfileViewResolver): у ученика есть запись временного доступа.
 * Плашка стоит в шапке слева от баннера «Идёт сейчас» (live-banner.js): когда у ученика идёт занятие,
 * видны обе рядом. Сама ничего не опрашивает.
 */
export function initTrialBanner() {
    const box = document.getElementById('profTrial');
    if (!box || !window.fsProfile?.trial?.active) { return; }

    box.hidden = false;
}
