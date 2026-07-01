/* Data layer — initially hardcoded; replace with AJAX when backend is ready */

function mulberry32(a) {
    return function () {
        a |= 0; a = a + 0x6D2B79F5 | 0;
        let t = Math.imul(a ^ a >>> 15, 1 | a);
        t = t + Math.imul(t ^ t >>> 7, 61 | t) ^ t;
        return ((t ^ t >>> 14) >>> 0) / 4294967296;
    };
}

export const WORK_TYPES = {
    sam:    { name: 'Самостоятельная', short: 'Сам.',   color: 'var(--t-sam)',    raw: '#1c7ed6' },
    contr:  { name: 'Контрольная',     short: 'Контр.',  color: 'var(--t-contr)',  raw: '#e03131' },
    prakt:  { name: 'Практическая',    short: 'Практ.',  color: 'var(--t-prakt)',  raw: '#099268' },
    dom:    { name: 'Домашняя',        short: 'Дом.',    color: 'var(--t-dom)',    raw: '#f08c00' },
    zachet: { name: 'Зачёт / экзамен', short: 'Зачёт',   color: 'var(--t-zachet)', raw: '#7048e8' },
};

export const AVA_COLORS = ['#5c7cfa','#7048e8','#1c7ed6','#0ca678','#f08c00','#e8590c','#e64980','#9c36b5','#2f9e44','#4263eb','#0c8599','#f76707'];

export const GROUPS = [
    { id: '10a', name: '10 «А»', subject: 'Информатика', color: '#3b5bdb', students: 16, avg: 4.3, attendance: 94, active: true },
    { id: '10b', name: '10 «Б»', subject: 'Информатика', color: '#0ca678', students: 15, avg: 4.1, attendance: 91 },
    { id: '11a', name: '11 «А»', subject: 'Информатика', color: '#7048e8', students: 14, avg: 4.5, attendance: 96 },
    { id: '9v',  name: '9 «В»',  subject: 'Информатика', color: '#f08c00', students: 18, avg: 3.9, attendance: 88 },
];

const STUDENT_NAMES = [
    'Антонов Артём', 'Беляева Мария', 'Волков Денис', 'Григорьева Анна',
    'Дмитриев Кирилл', 'Егорова Полина', 'Зайцев Максим', 'Иванова Софья',
    'Козлов Алексей', 'Лебедева Виктория', 'Морозов Иван', 'Никитина Дарья',
    'Орлов Павел', 'Петрова Елизавета', 'Сидоров Роман', 'Фёдорова Алиса',
];

export const J_COLUMNS = [
    { d: '07.11', dow: 'Пт', type: null,     label: 'Условный оператор if' },
    { d: '11.11', dow: 'Вт', type: null,     label: 'Каскадные условия elif' },
    { d: '14.11', dow: 'Пт', type: 'sam',    label: 'Самостоятельная №1 «Ветвления»' },
    { d: '18.11', dow: 'Вт', type: null,     label: 'Цикл while' },
    { d: '21.11', dow: 'Пт', type: 'dom',    label: 'Домашняя работа: циклы' },
    { d: '25.11', dow: 'Вт', type: null,     label: 'Цикл for, функция range()' },
    { d: '28.11', dow: 'Пт', type: 'prakt',  label: 'Практическая: задачи на range()' },
    { d: '02.12', dow: 'Вт', type: null,     label: 'Вложенные циклы' },
    { d: '05.12', dow: 'Пт', type: 'sam',    label: 'Самостоятельная №2 «Циклы»' },
    { d: '09.12', dow: 'Вт', type: null,     label: 'Списки: основные операции' },
    { d: '12.12', dow: 'Пт', type: 'dom',    label: 'Домашняя работа: списки' },
    { d: '16.12', dow: 'Вт', type: null,     label: 'Строки и срезы' },
    { d: '19.12', dow: 'Пт', type: 'prakt',  label: 'Практическая: обработка строк' },
    { d: '23.12', dow: 'Вт', type: null,     label: 'Функции, оператор def' },
    { d: '26.12', dow: 'Пт', type: 'zachet', label: 'Контрольная работа за четверть' },
];

function gradeFrom(strength, rnd, type) {
    const penalty = (type === 'contr' || type === 'zachet') ? 0.12 : (type === 'sam' ? 0.06 : 0);
    const x = strength - penalty + (rnd() - 0.5) * 0.45;
    if (x > 0.86) return 5;
    if (x > 0.6) return 4;
    if (x > 0.34) return 3;
    return 2;
}

function buildJournal() {
    const students = STUDENT_NAMES.map((name, si) => {
        const rnd = mulberry32(1000 + si * 97);
        const strength = 0.55 + rnd() * 0.45;
        const attBias = 0.86 + rnd() * 0.12;
        const cells = J_COLUMNS.map((col) => {
            const r = rnd();
            let att = 'p';
            if (r > attBias) att = 'n';
            else if (r > attBias - 0.04) att = 'l';
            let grade = null;
            if (att !== 'n') {
                if (col.type) {
                    if (rnd() < 0.94) grade = gradeFrom(strength, rnd, col.type);
                } else {
                    if (rnd() < 0.28) grade = gradeFrom(strength, rnd, null);
                }
            }
            return { att, grade };
        });
        const initials = name.split(' ').map(w => w[0]).join('').slice(0, 2);
        return { id: 's' + si, name, initials, color: AVA_COLORS[si % AVA_COLORS.length], cells };
    });
    return { group: GROUPS[0], period: '2 четверть', columns: J_COLUMNS, students };
}

export function avgOf(cells) {
    const g = cells.filter(c => c.grade != null).map(c => c.grade);
    if (!g.length) return null;
    return g.reduce((a, b) => a + b, 0) / g.length;
}

export const JOURNAL = buildJournal();

export const TODAY_LESSONS = [
    { start: '08:30', end: '09:15', group: '11 «А»', topic: 'Рекурсия. Примеры задач', room: 'каб. 305', color: '#7048e8', state: 'done' },
    { start: '09:25', end: '10:10', group: '10 «А»', topic: 'Функции, оператор def', room: 'каб. 305', color: '#3b5bdb', state: 'done' },
    { start: '10:30', end: '11:15', group: '9 «В»',  topic: 'Электронные таблицы: формулы', room: 'каб. 214', color: '#f08c00', state: 'now' },
    { start: '11:35', end: '12:20', group: '10 «Б»', topic: 'Списки: основные операции', room: 'каб. 305', color: '#0ca678', state: 'soon' },
    { start: '12:40', end: '13:25', group: '10 «А»', topic: 'Практическая: обработка строк', room: 'каб. 305', color: '#3b5bdb', state: 'soon' },
];

export const WORKLIST_ATT = [
    { group: '9 «В»',  topic: 'Системы счисления', date: '24.12', missing: 18 },
    { group: '10 «Б»', topic: 'Цикл for, range()', date: '23.12', missing: 4 },
    { group: '11 «А»', topic: 'Обработка исключений', date: '23.12', missing: 2 },
];

export const WORKLIST_REV = [
    { group: '10 «А»', work: 'Контрольная за четверть', type: 'zachet', count: 14 },
    { group: '10 «Б»', work: 'Практическая: строки', type: 'prakt', count: 9 },
    { group: '11 «А»', work: 'Зачёт по рекурсии', type: 'zachet', count: 6 },
];

export const WEEK_SCHEDULE = {
    'Понедельник': [
        { start: '09:25', group: '10 «А»', topic: 'Цикл for, range()', color: '#3b5bdb' },
        { start: '11:35', group: '9 «В»',  topic: 'Электронные таблицы', color: '#f08c00' },
    ],
    'Вторник': [
        { start: '08:30', group: '11 «А»', topic: 'Рекурсия', color: '#7048e8' },
        { start: '09:25', group: '10 «А»', topic: 'Функции, def', color: '#3b5bdb' },
        { start: '11:35', group: '10 «Б»', topic: 'Списки', color: '#0ca678' },
    ],
    'Среда': [
        { start: '10:30', group: '9 «В»',  topic: 'Условный оператор', color: '#f08c00' },
    ],
    'Четверг': [
        { start: '08:30', group: '11 «А»', topic: 'Стек и очередь', color: '#7048e8' },
        { start: '10:30', group: '10 «Б»', topic: 'Строки и срезы', color: '#0ca678' },
    ],
    'Пятница': [
        { start: '09:25', group: '10 «А»', topic: 'Контрольная работа', color: '#3b5bdb' },
        { start: '11:35', group: '9 «В»',  topic: 'Практическая', color: '#f08c00' },
    ],
};

export const KTP_CONFIG = {
    year: 2025, month: 10,
    lessonDows: [2, 5],
    holidays: [{ day: 4, name: 'День народного единства' }],
    pins: { t1: 7 },
};

export const KTP_THEMES = [
    { id: 't1',  n: 1,  title: 'Условный оператор if', hours: 2 },
    { id: 't2',  n: 2,  title: 'Каскадные условия. elif / else', hours: 2 },
    { id: 't3',  n: 3,  title: 'Самостоятельная работа №1', hours: 1, work: 'sam' },
    { id: 't4',  n: 4,  title: 'Цикл while', hours: 2 },
    { id: 't5',  n: 5,  title: 'Цикл for. Функция range()', hours: 2 },
    { id: 't6',  n: 6,  title: 'Практическая работа: range()', hours: 2, work: 'prakt' },
    { id: 't7',  n: 7,  title: 'Вложенные циклы', hours: 2 },
    { id: 't8',  n: 8,  title: 'Списки. Основные операции', hours: 2 },
    { id: 't9',  n: 9,  title: 'Строки и срезы', hours: 2 },
    { id: 't10', n: 10, title: 'Практикум: обработка списков', hours: 2, work: 'prakt' },
    { id: 't11', n: 11, title: 'Функции. Оператор def', hours: 2 },
    { id: 't12', n: 12, title: 'Контрольная работа за четверть', hours: 1, work: 'contr' },
];

export const KTP_COURSES = {
    py: { name: 'Python — основы программирования', themes: KTP_THEMES },
    inf9: {
        name: 'Информатика, 9 класс',
        themes: [
            { id: 'i1', n: 1, title: 'Информация и информационные процессы', hours: 2 },
            { id: 'i2', n: 2, title: 'Системы счисления', hours: 2 },
            { id: 'i3', n: 3, title: 'Самостоятельная: перевод чисел', hours: 1, work: 'sam' },
            { id: 'i4', n: 4, title: 'Логические операции', hours: 2 },
            { id: 'i5', n: 5, title: 'Таблицы истинности', hours: 2 },
            { id: 'i6', n: 6, title: 'Практическая: логические схемы', hours: 2, work: 'prakt' },
            { id: 'i7', n: 7, title: 'Кодирование информации', hours: 2 },
            { id: 'i8', n: 8, title: 'Контрольная работа за четверть', hours: 1, work: 'contr' },
        ],
    },
    alg: {
        name: 'Алгоритмы и структуры данных',
        themes: [
            { id: 'a1', n: 1, title: 'Введение в алгоритмы. Сложность', hours: 2 },
            { id: 'a2', n: 2, title: 'Линейный и бинарный поиск', hours: 2 },
            { id: 'a3', n: 3, title: 'Сортировки: пузырёк, выбор', hours: 2 },
            { id: 'a4', n: 4, title: 'Практическая: сортировки', hours: 2, work: 'prakt' },
            { id: 'a5', n: 5, title: 'Стек, очередь, дек', hours: 2 },
            { id: 'a6', n: 6, title: 'Зачёт по структурам данных', hours: 1, work: 'zachet' },
        ],
    },
};

export const MONTHS_RU = ['Январь','Февраль','Март','Апрель','Май','Июнь','Июль','Август','Сентябрь','Октябрь','Ноябрь','Декабрь'];
export const DOW_RU = ['Пн','Вт','Ср','Чт','Пт','Сб','Вс'];
