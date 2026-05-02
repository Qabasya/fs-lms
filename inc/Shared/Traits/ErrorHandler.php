<?php

namespace Inc\Shared\Traits;

use Inc\Enums\Capability;
use Throwable;

/**
 * Trait ErrorHandler
 *
 * Предоставляет методы для обработки и логирования ошибок в плагине.
 *
 * @package Inc\Shared\Traits
 *
 * ### Основные обязанности:
 *
 * 1. **Отправка ошибок** — унифицированная отправка ошибок в JSON или HTML формате.
 * 2. **Логирование исключений** — запись исключений с контекстом в лог.
 * 3. **Внутреннее логирование** — централизованная запись всех ошибок в error_log.
 *
 * ### Архитектурная роль:
 *
 * Используется в контроллерах и сервисах для единообразной обработки ошибок.
 * Автоматически определяет тип ответа (AJAX или обычный) и выбирает правильный формат.
 */
trait ErrorHandler
{
    /**
     * Отправка ошибки с автоматическим определением формата ответа.
     *
     * @param string          $code                Код ошибки для машинной обработки
     * @param string          $message             Сообщение для пользователя (безопасное)
     * @param int             $status              HTTP-статус
     * @param Capability|null $required_capability Проверка прав перед отправкой (опционально)
     *
     * @return never
     */
    protected function sendError(
        string      $code,
        string      $message,
        int         $status = 400,
        ?Capability $required_capability = null
    ): void
    {
        // Проверка прав, если указана
        if ( $required_capability && ! current_user_can( $required_capability->value ) ) {
            $status = 403;
            $message = 'Доступ запрещён';
        }

        // Логирование для разработчиков (не попадает в UI)
        $this->logErrorInternal( $code, $message, $status );

        // AJAX-ответ
        // wp_doing_ajax() — определяет, выполняется ли AJAX-запрос
        if ( wp_doing_ajax() ) {
            // wp_send_json_error() — отправляет JSON-ответ с success = false
            wp_send_json_error(
                [ 'code' => $code, 'message' => $message ],
                $status
            );
        }

        // Обычный HTML-ответ
        // wp_die() — завершает выполнение скрипта с выводом сообщения
        // esc_html() — экранирует HTML-символы для безопасного вывода
        wp_die(
            esc_html( $message ),
            'LMS Error',
            [ 'response' => $status, 'back_link' => true ]
        );
    }

    /**
     * Логирует исключение с дополнительным контекстом.
     *
     * @param Throwable $e       Исключение
     * @param array     $context Дополнительный контекст (массив данных)
     *
     * @return void
     */
    protected function logException( Throwable $e, array $context = [] ): void
    {
        $this->logErrorInternal(
            'exception',
            $e->getMessage(),
            500,
            array_merge( $context, [
                'file'  => $e->getFile(),
                'line'  => $e->getLine(),
                'trace' => WP_DEBUG ? $e->getTraceAsString() : null,
            ] )
        );
    }

    /**
     * Внутренний метод логирования.
     * Не вызывает завершения скрипта — только запись в лог.
     *
     * @param string $code    Код ошибки
     * @param string $message Сообщение об ошибке
     * @param int    $status  HTTP-статус
     * @param array  $context Дополнительный контекст
     *
     * @return void
     */
    private function logErrorInternal(
        string $code,
        string $message,
        int    $status,
        array  $context = []
    ): void
    {
        // Выходим, если режим отладки не включён
        if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
            return;
        }

        // current_time() — возвращает текущее время в формате MySQL
        // get_current_user_id() — ID текущего пользователя
        $log_data = array_merge( [
            'timestamp' => current_time( 'mysql' ),
            'code'      => $code,
            'status'    => $status,
            'user_id'   => get_current_user_id(),
            'ip'        => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        ], $context );

        // sprintf() — форматирует строку
        // strtoupper() — преобразует строку в верхний регистр
        // json_encode(, JSON_UNESCAPED_UNICODE) — JSON без экранирования Unicode
        $log_message = sprintf(
            '[LMS] %s: %s | Context: %s',
            strtoupper( $code ),
            $message,
            json_encode( $log_data, JSON_UNESCAPED_UNICODE )
        );

        // error_log() — записывает сообщение в лог PHP (обычно в wp-content/debug.log)
        error_log( $log_message );
    }
}