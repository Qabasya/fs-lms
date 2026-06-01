/**
 * Базовый класс для всех валидаторов
 */
export class BaseValidator {
    /**
     * Запускает проверку поля
     * @param {HTMLInputElement|HTMLSelectElement} input
     * @returns {string|null} Текст ошибки или null, если всё ок
     */
    validate(input) {
        // 1. Сначала всегда проверяем нативные правила HTML5 (required, minlength и т.д.)
        const nativeError = this.checkNative(input);
        if (null !== nativeError) {
            return nativeError;
        }

        const value = input.value.trim();

        // Если поле пустое и не вызвало ошибку valueMissing (значит оно необязательное),
        // кастомные правила к нему применять не нужно.
        if (!value) {
            return null;
        }

        // 2. Вызываем кастомную логику конкретного класса
        return this.checkCustom(value, input);
    }

    /**
     * Проверка встроенных атрибутов браузера через ValidityState
     */
    checkNative(input) {
        const validity = input.validity;

        if (validity.valid) {
            return null;
        }

        if (validity.valueMissing) {
            return 'Поле обязательно для заполнения.';
        }

        if (validity.typeMismatch && 'email' === input.type) {
            return 'Введите корректный адрес электронной почты.';
        }

        if (validity.tooShort) {
            return `Минимальное количество символов: ${input.minLength}. Вы ввели: ${input.value.length}.`;
        }

        if (validity.patternMismatch) {
            return 'Значение заполнено неверно.';
        }

        return null;
    }

    /**
     * Метод-заглушка для переопределения в дочерних классах
     */
    checkCustom(value, input) {
        return null;
    }
}