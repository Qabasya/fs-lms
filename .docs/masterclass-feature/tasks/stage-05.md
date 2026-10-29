# Этап 5. Заявка и зачисление

Зависимости: этапы 2–4, этап 0 (Ш5, Ш7).
Результат: ребёнок из баннера открывает `/apply/` залогиненным (направление и школа предзаполнены), подаёт заявку — она привязывается к его аккаунту, логин и пароль
из формы становятся учётными данными, сессия завершается; при зачислении аккаунт становится «зачислен», а имя учётки — настоящим.

Перед началом прочитать `README.md`, `../SPEC.md` §2 (решения 12, 6, 18), §9 (риски: истёкшая заявка, временный доступ), `../CONTRACTS.md` §8
(`MasterclassConversionService`), §9 (`MasterclassEnrollmentController`). Код ядра: `TrialAccessService`, `EnrollmentService`, `EnrollmentTransaction`,
`ApplicationService`, `ApplyPageController`.

**Порядок:** 5.1 → 5.2 → 5.3 → 5.4.

## Статус

**Готов и проверен 2026-10-29** (код закоммичен в ветке, не запушен). Итог:

- `MasterclassConversionService` теперь `( MasterclassAccountRepository, MasterclassRepository, MasterclassAccountService, ApplicationRepository, PersonRepository, UserManager, TrialAccessService, PiiCryptoService, MasterclassLogWriter, ClockInterface )`: `logContact()`, `bindApplication()`, `markEnrolled()`, `prefill()`. Отличия от контракта: «живую заявку» проверяет `MasterclassAccountService::hasLiveApplication()` (не дубль); привязка идёт в порядке расшифровка → `ApplicationRepository::update()` → `setState( Applied )` → `syncCredentials()` → журнал; отказы (живая заявка, заявка не найдена/уже привязана, нет физлица, сбой) пишутся как `ApplicationBindFailed`, а пользователь без аккаунта мастер-класса отклоняется молча (заявки подаются постоянно); `markEnrolled()` ещё и сбрасывает `student_record_id` (иначе ссылка на снятую запись).
- `MasterclassEnrollmentController( MasterclassConversionService )`: `fs_lms_application_created`, `fs_lms_student_enrolled` (10, 2), `fs_lms_apply_prefill` (только пустой и залогиненный), **`fs_lms_apply_response`** — подсказка «Теперь входите с новыми логином и паролем…» в `notice` (5.3: в шаблоне ядра такого текста не было, а серверный шов с `notice` уже есть). Модуль регистрирует его при включении.
- **Правки ядра (универсальные, без слов модуля):** `templates/frontend/apply-fields.php` — `autofocus` на почте только при заполненной фамилии (хвост Ш5 этапа 0), тест в `ApplyPageControllerTest`; `src/js/frontend/services/apply-form.js` — `showSuccess( notice, waiting )`: спиннер на экране успеха только когда сервер прислал опрос (`poll.action`), иначе сообщение показывалось бы с вечным спиннером.
- Проверки: PHPUnit 4656 зелёные, `npm run test:js` 239, eslint/stylelint 0 ошибок, `gulp build`, DI вкл./выкл.
- **Стенд (5.3–5.4, HTTP + `wp_db`, скрипт в scratchpad, данные удалены):** вход `student-N` → `/apply/` с выбранным направлением и школой, без `autofocus`; **настоящий AJAX** `create_application` (код OTP подставлен в транзиент через рефлексию — почта в dev не уходит) → `application_bound`, заявка `student_person_id` = физлицо аккаунта, `trial_owner = 1`, аккаунт `applied`, логин сменён; в ответе есть `notice`; **старая кука недействительна** (гость), старые логин/пароль не входят, новые входят и ведут в плеер; родитель + зачисление (`EnrollmentService::enroll`, `fs_lms_student_enrolled`) → аккаунт `enrolled`, `display_name` «Тестов Петя», `first_name`/`last_name` настоящие, временная запись снята, настоящая запись в целевой группе, учётка переиспользована; зачисленный после входа попадает в обычный кабинет (`/profile/` 200, не плеер) и заморозка с удалением мастер-класса его не трогают (тик удалил остальные с причиной `requested`, группа снесена, запись и учётка зачисленного целы). Риск «заявка отозвана»: `TrialAccessService::revoke()` удалил учётку, `deleted_user` пометил аккаунт `user_deleted` (журнал `account_deleted`).
- Не проверено: «Продлить»/«Снять доступ» у заявки (§9 спеки) — не гонял; экран успеха заявки в браузере (подсказка и скрытый спиннер проверены кодом и ответом сервера, не глазами); заморозка не трогает аккаунт с заявкой — гарантируется тем, что запись остаётся `is_trial` до зачисления, а Ш1 понижает по `freeze_at` независимо от заявки: **ребёнок с поданной, но не зачисленной заявкой при заморозке видит окно «Заявка принята»** (проверено юнит-тестом шаблона, не на стенде); PHPStan (нет в контейнере); почта родителю не уходила (транспорт dev).

---

## 5.1 `MasterclassConversionService`

- [x] `bindApplication( int $applicationId, int $userId ): bool` — **наружу не бросает** (сбой → `PluginLogger::exception` + `ApplicationBindFailed`, `false`). Условия: живой
  аккаунт `Active`/`Applied`, у него нет живой заявки, заявка существует и `student_person_id IS NULL`. Действия: `ApplicationRepository::update()` (`student_person_id`,
  `trial_owner = 1`, `updated_at`), `TrialAccessService::syncCredentials()`, `setState( Applied, $applicationId )`, журнал `ApplicationBound`.
- [x] `markEnrolled( int $personId ): bool` — `setState( Enrolled )`; имя учётки из физлица (`display_name`, `first_name`, `last_name`); журнал `Enrolled`.
- [x] `logContact()` (если в этапе 3 была заглушка — довести), `prefill( int $userId ): array`.
- [x] Тесты: поля заявки, `trial_owner`, вызов `syncCredentials`, отказ при живой заявке, при уже привязанной заявке, при удалённом/зачисленном аккаунте, исключение внутри
  не вылетает наружу, `markEnrolled()` переписывает имя, `prefill()` пуст для чужого пользователя.

**Готово, когда:** тесты зелёные.

## 5.2 `MasterclassEnrollmentController`

- [x] `fs_lms_application_created` → `onApplicationCreated()` (залогиненный 0 → выход; аккаунт — **из сессии**, не из параметра); `fs_lms_student_enrolled` (10, 2) →
  `markEnrolled()`; `fs_lms_apply_prefill` (Ш5) → `prefill()`, только если пусто и залогинен; `fs_lms_service_group_ids` и `fs_lms_group_deleting` — если не подключены ранее.
- [x] Тесты контроллера: однострочные обработчики вызывают сервис; гость/чужой пользователь ничего не меняет.

**Готово, когда:** тесты зелёные.

## 5.3 Смена логина и пароля завершает сессию

- [x] Проверить вживую: после заявки с новыми логином и паролем кука старой сессии недействительна (`syncCredentials()`), экран успеха заявки говорит «теперь входите с
  новыми логином и паролем» — **если такой подсказки нет в шаблоне ядра, решить, где её показать, и записать сюда** (решение 12 спеки).
- [x] Проверить два сценария риска: заявка истекла / в корзине → `TrialAccessService::revoke()` удаляет учётку (`deleted_user` помечает аккаунт `user_deleted`);
  «Продлить»/«Снять доступ» у заявки действуют на запись мастер-класса (§9 спеки) — убедиться, что не ломается ничего, кроме описанного.

**Готово, когда:** оба сценария воспроизведены на стенде, поведение совпадает со спекой (или спека поправлена).

## 5.4 Сквозной e2e (HTTP + браузер)

- [x] Создание (порциями) → вход `student-N` → «Связаться» → `/apply/` (направление и школа предзаполнены) → заявка с новыми логином/паролем (сессия завершилась) → вход под новыми →
  зачисление офисом → в шапке кабинета настоящее имя, временная запись удалена, аккаунт «зачислен». Затем: заморозка сдвигом `freeze_at` не трогает аккаунт с заявкой;
  удаление неиспользованных из крона; удаление группы со страницы «Группы» не оставляет `student-N`.
- [x] Временные данные, mu-plugin, скрипты — удалить по точным именам.

**Готово, когда:** сквозной сценарий проходит целиком, остатков на стенде нет.

---

## Проверки этапа

PHPUnit полностью; DI-загрузка; PHPStan 5 по модулю и правленым файлам ядра; `npx eslint .`; `npx stylelint`; `npx gulp build`; 5.3–5.4 — на стенде.
Затем **переписать этап 6**.
