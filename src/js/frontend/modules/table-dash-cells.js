/**
 * Ячейки-прочерки в таблицах контента.
 *
 * Автор ставит в ячейку «-» (или «–», «—»), чтобы показать «здесь ничего
 * нет». Знак убираем, ячейке ставим класс is-dash — заливку тоном шапки
 * даёт общий слой таблиц (shared/_content-tables.scss).
 *
 * Общей серверной точки у условия нет (страница задания, карточки тренажёра,
 * карточка в статье, шаг курса), поэтому разбор — на клиенте. Модуль зовут
 * повторно после каждой дорисовки: обработанную ячейку выдаёт класс.
 */

/** Таблицы авторского контента — те же места, где подключён общий слой таблиц. */
const TABLES = [
    '.fs-task-condition table',
    '.tcr-condition table',
    '.fs-article-task__stmt table',
    '.fs-article-table',
    '.wpb_wrapper table',
    '.wpc table',
].join(', ');

const DASH_RE = /^[-–—]$/;

const DASH = 'is-dash';

/**
 * @param {ParentNode} [root=document] - Где искать таблицы.
 *
 * @returns {void}
 */
export function initDashCells(root = document) {
    root.querySelectorAll(TABLES).forEach(table => {
        table.querySelectorAll('td').forEach(cell => {
            if (cell.classList.contains(DASH)) return;
            if (!DASH_RE.test(cell.textContent.trim())) return;

            cell.textContent = '';
            cell.classList.add(DASH);
        });
    });
}
