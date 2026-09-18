1. Вобще никак не получится добавить кнопки таблиц, формул и кода на страницу редактирования Лекции? 
2. Убери border:1 у таблиц на странице тренажёра (он ставит границу всему блоку)
2. Выровняй таблицы по центру
3. Кнопка скачивания файла не работает
4. Почему списки (номера и точки) серого цвета rgb(154, 161, 173) , сделай их цвета текста rgb(60, 66, 78)
5. На страницу заданий предмета добавь фильтрацию (как в банке задач примерно) по: номеру задания, году, автору.
6. в fs-answer-value поменяй моноширный шрифт на обычный Ubuntu, arial, helvetica, sans-serif
7. Сделай всем таблицам такие стили (проверь центровку еще) (адаптируй под наш проект):
   /* Таблицы в стиле FS-LMS */
   .fs-lms-content table {
   width: 100%;
   max-width: 760px;
   margin: 28px 0;
   border-collapse: separate;
   border-spacing: 0;
   overflow: hidden;

   background: #fff;
   border: 1px solid #e5eaf1;
   border-radius: 12px;

   color: #172033;
   font-size: 15px;
   line-height: 1.4;

   box-shadow: 0 2px 8px rgba(23, 32, 51, 0.04);
   }

/* Ячейки */
.fs-lms-content table th,
.fs-lms-content table td {
padding: 13px 18px;
text-align: left;
border: 0;
border-bottom: 1px solid #e9edf3;
}

/* Заголовок */
.fs-lms-content table th {
background: #f5f8fc;
color: #1d2f4d;
font-weight: 600;
font-size: 14px;
}

/* Вертикальные разделители */
.fs-lms-content table th + th,
.fs-lms-content table td + td {
border-left: 1px solid #e9edf3;
}

/* Последняя строка без нижней границы */
.fs-lms-content table tbody tr:last-child td {
border-bottom: 0;
}

/* Hover */
.fs-lms-content table tbody tr {
transition: background-color 0.15s ease;
}

.fs-lms-content table tbody tr:hover {
background: #f8faff;
}

/* Первый столбец */
.fs-lms-content table tbody td:first-child {
font-weight: 500;
color: #243b5a;
}

/* Скругление углов */
.fs-lms-content table thead tr:first-child th:first-child {
border-top-left-radius: 11px;
}

.fs-lms-content table thead tr:first-child th:last-child {
border-top-right-radius: 11px;
}

.fs-lms-content table tbody tr:last-child td:first-child {
border-bottom-left-radius: 11px;
}

.fs-lms-content table tbody tr:last-child td:last-child {
border-bottom-right-radius: 11px;
}


/* Мобильная адаптация */
@media (max-width: 600px) {
.fs-lms-content table {
font-size: 14px;
}

    .fs-lms-content table th,
    .fs-lms-content table td {
        padding: 11px 12px;
    }
}

8. Добавь к таблицам Ученики и Родители поиск по имени (целиком по фамилии имени и отчеству) ученика
9. Поменяй цвет кнопки на формах apply и join на тот, который на кнопках сайта: rgb(59, 91, 219)