import { ToggleComponent } from './components/toggle.js';
import { BadgeComponent } from './components/badge.js';
import { ToggleSecretComponent } from './components/toggle-secret.js';

import { FieldValidators } from './validators/index.js';


/**
 * Функция валидации конкретного поля
 * @param {HTMLInputElement|HTMLSelectElement|HTMLTextAreaElement} input
 * @returns {boolean} True, если поле валидно
 */
function handleValidationResult(input) {
    // Получаем ключ валидатора из data-validate или по типу поля
    const validatorKey = input.dataset.validate || input.type;
    const validator = FieldValidators[validatorKey] || FieldValidators.default;

    const errorMessage = validator.validate(input);
    const formGroup = input.closest('.fs-form-group');

    if (!formGroup) {
        return null === errorMessage;
    }

    let errorElement = formGroup.querySelector('.fs-field-error');

    if (errorMessage) {
        formGroup.classList.add('form-invalid');

        if (!errorElement) {
            errorElement = document.createElement('p');
            errorElement.className = 'description error fs-field-error';

            // Если в WordPress разметке есть обычное описание, вставляем ошибку ПЕРЕД ним
            const nativeDesc = formGroup.querySelector('.description:not(.error)');
            if (nativeDesc) {
                formGroup.insertBefore(errorElement, nativeDesc);
            } else {
                formGroup.appendChild(errorElement);
            }
        }
        errorElement.textContent = errorMessage;
    } else {
        formGroup.classList.remove('form-invalid');
        if (errorElement) {
            errorElement.remove();
        }
    }

    return null === errorMessage;
}

/**
 * Автоматическая инициализация всех форм, требующих валидации
 */
function initGlobalFormValidation() {
    // Ищем формы с флагом кастомной валидации или системные формы плагина
    const forms = document.querySelectorAll('form[data-fs-validate], .fs-lms-form');

    if (0 === forms.length) {
        return;
    }

    forms.forEach(form => {
        // Отключаем дефолтные тултипы браузера, чтобы они не перебивали WP-стиль
        form.setAttribute('novalidate', 'true');

        const inputs = form.querySelectorAll('input, select, textarea');

        inputs.forEach(input => {
            // Живая проверка при потере фокуса
            input.addEventListener('blur', () => handleValidationResult(input));

            // Мягкое удаление подсветки ошибки, когда пользователь начинает вводить данные заново
            input.addEventListener('input', () => {
                const formGroup = input.closest('.fs-form-group');
                if (formGroup && formGroup.classList.contains('form-invalid')) {
                    formGroup.classList.remove('form-invalid');
                    const error = formGroup.querySelector('.fs-field-error');
                    if (error) {
                        error.remove();
                    }
                }
            });
        });

        // Жесткая проверка всех полей перед отправкой формы
        form.addEventListener('submit', (e) => {
            let isFormValid = true;

            inputs.forEach(input => {
                const isValid = handleValidationResult(input);
                if (!isValid && isFormValid) {
                    isFormValid = false;
                    input.focus(); // Скроллим/фокусируем на первом ошибочном поле
                }
            });

            if (!isFormValid) {
                e.preventDefault();
                e.stopPropagation();
            }
        });
    });
}

(function ($) {
    'use strict';

    $(document).ready(function () {
        ToggleComponent.init();
        BadgeComponent.init();
        ToggleSecretComponent.init();
        initGlobalFormValidation();
    });

})(jQuery);
