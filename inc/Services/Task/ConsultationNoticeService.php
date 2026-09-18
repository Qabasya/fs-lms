<?php

declare( strict_types=1 );

namespace Inc\Services\Task;

use Inc\Enums\Subject\TaskTemplate;
use Inc\Services\Shared\PluginConfig;

/**
 * Class ConsultationNoticeService
 *
 * Приглашение на консультацию вместо правильного ответа.
 *
 * @package Inc\Services\Task
 *
 * У заданий с ручной проверкой (развёрнутый ответ, два условия на выбор —
 * ОГЭ №13–15, вторая часть ЕГЭ по математике) правильного ответа нет и быть не
 * может: их проверяет преподаватель по критериям. Поэтому кнопка «Показать
 * ответ» в тренажёре у них раньше не появлялась вовсе — показывать было нечего.
 *
 * Вместо ответа такие задания приглашают на разбор с педагогом. Текст и адрес
 * собираются здесь, а не в шаблоне и не в JS: блок печатают ДВА разных
 * потребителя — страница задания (PHP) и карточка каталога (рисуется на
 * клиенте), и разъехавшийся текст в них никто бы не заметил.
 *
 * Адрес формы живёт в настройках плагина ({@see PluginConfig::consultationUrl()}):
 * смена лендинга не должна требовать релиза. Адрес не задан — приглашения нет,
 * и поведение остаётся прежним.
 */
readonly class ConsultationNoticeService {

	/** Текст приглашения; подпись ссылки отделена, чтобы не собирать HTML в шаблоне. */
	private const TEXT       = 'Для разбора задания запишитесь на консультацию к педагогу:';
	private const LINK_LABEL = 'Записаться';

	public function __construct( private PluginConfig $config ) {}

	/**
	 * Приглашение для задания или null, если его показывать не нужно:
	 * у задания есть обычный ответ, либо адрес формы не настроен.
	 *
	 * @param TaskTemplate $template Шаблон задания
	 * @param string       $answer   Правильный ответ задания (пустой у ручной проверки)
	 *
	 * @return array{text: string, url: string, label: string}|null
	 */
	public function forTask( TaskTemplate $template, string $answer ): ?array {
		if ( '' !== trim( $answer ) || ! $template->isFileAnswerShape() ) {
			return null;
		}

		$url = $this->config->consultationUrl();

		if ( '' === $url ) {
			return null;
		}

		return array(
			'text'  => self::TEXT,
			'url'   => $url,
			'label' => self::LINK_LABEL,
		);
	}
}
