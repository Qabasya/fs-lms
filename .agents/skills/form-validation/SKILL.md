---
name: form-validation
description: Клиентская валидация форм fs-lms — как повесить валидатор на поле, создать новый валидатор и подключить проверку к AJAX-форме. Использовать при работе с src/js/common/validators, validation-manager.js или при добавлении полей в публичные формы (заявка, регистрация, согласие).
---

# Клиентская валидация форм

Система валидации: `src/js/common/validators/` + `src/js/common/validation-manager.js`.

**Добавить валидатор к полю:**
1. Добавить `data-validate="ключ"` к `<input>`
2. Обернуть поле в `<div class="fs-form-group">`

**Создать новый валидатор (3 шага):**
1. Создать `src/js/common/validators/MyValidator.js` — наследовать `BaseValidator`, переопределить `checkCustom(value, input)` — возвращать строку ошибки или `null`
2. Зарегистрировать в `validators/index.js`: `{ myKey: new MyValidator() }`
3. Добавить `data-validate="myKey"` к инпуту — больше ничего

**Автоматическая привязка:** формы с `data-fs-validate` или `.fs-lms-form` подхватываются `common.js` автоматически.

**Ручная привязка** (AJAX-формы со своим submit-обработчиком):
```js
import { initFormValidation } from '../../common/validation-manager.js';
const validateAll = initFormValidation( form ); // blur + input события
form.addEventListener( 'submit', async ( e ) => {
    e.preventDefault();
    if ( ! validateAll() ) { return; }
    // ... AJAX
} );
```

**Стили ошибок:** `src/scss/common/components/_validation.scss` — единственное место. Переменная `$color-danger` из admin-переменных. Не дублировать в компонентных SCSS.
