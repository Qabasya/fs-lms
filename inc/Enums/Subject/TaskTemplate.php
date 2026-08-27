<?php

declare( strict_types=1 );

namespace Inc\Enums\Subject;

use Inc\MetaBoxes\Templates\AlternativeConditionsTemplate;
use Inc\MetaBoxes\Templates\AudioTaskTemplate;
use Inc\MetaBoxes\Templates\ChoiceTaskTemplate;
use Inc\MetaBoxes\Templates\CodeTaskTemplate;
use Inc\MetaBoxes\Templates\CommonConditionTemplate;
use Inc\MetaBoxes\Templates\FileAnswerTaskTemplate;
use Inc\MetaBoxes\Templates\FileCodeTaskTemplate;
use Inc\MetaBoxes\Templates\FileTaskTemplate;
use Inc\MetaBoxes\Templates\FillTaskTemplate;
use Inc\MetaBoxes\Templates\MatchingTaskTemplate;
use Inc\MetaBoxes\Templates\OrderingTaskTemplate;
use Inc\MetaBoxes\Templates\StandardTaskTemplate;
use Inc\MetaBoxes\Templates\TaskTextSolution;
use Inc\MetaBoxes\Templates\ThreeInOneTemplate;
use Inc\MetaBoxes\Templates\TwoFileCodeTaskTemplate;

/**
 * Enum TaskTemplate
 *
 * Перечисление доступных типов шаблонов заданий.
 * Используется для типобезопасного управления шаблонами метабоксов.
 *
 * Каждый кейс содержит:
 * - Строковое значение (ID шаблона) — хранится в БД
 * - Метод class() — возвращает FQCN класса для динамического создания
 * - Метод label() — человекочитаемое название для UI
 *
 * @package Inc\Enums
 */
enum TaskTemplate: string {
	/**
	 * Стандартное задание с одним условием.
	 */
	case Standard = 'standard_task';

	/**
	 * Задание "Три в одном" (для ЕГЭ 19-21).
	 */
	case Triple = 'triple_task';

	/**
	 * Задание с общим (неизменяемым) условием.
	 */
	case Common = 'common_standard_task';

	case Code        = 'code_task';
	case FileCode    = 'file_code_task';
	case File        = 'file_task';
	case TwoFile     = 'two_file_code_task';
	case TextSolution = 'text_task';

	/** Выбор варианта ответа (radio / checkbox). */
	case Choice   = 'choice_task';

	/** Сопоставление пар (drag-n-drop). */
	case Matching = 'matching_task';

	/** Сортировка элементов (drag-n-drop). */
	case Ordering = 'ordering_task';

	/** Пропуски в тексте. */
	case Fill     = 'fill_task';

	/** Аудио-плеер + текстовый ответ. */
	case Audio    = 'audio_task';

	/**
	 * Развёрнутый ответ (Эпик 13, D16): ученик прикладывает файлы (фото/pdf/pptx/py)
	 * и/или пишет текст; проверка ТОЛЬКО ручная (чекер не регистрируется).
	 * Кейсы: ЕГЭ математика ч.2, ОГЭ информатика №13/№15.
	 */
	case FileAnswer = 'file_answer_task';

	/**
	 * Два условия на выбор — один пост, ученик решает один из двух вариантов
	 * (ОГЭ информатика №13: 13.1 «презентация» / 13.2 «текстовый документ»).
	 * Ответ — файл, проверка только ручная (как FileAnswer). Один пост вместо
	 * двух отдельных — чтобы таксономический номер оставался целым (см.
	 * докблок AlternativeConditionsTemplate).
	 */
	case AlternativeConditions = 'alternative_conditions_task';

	/**
	 * Умный конструктор Enum с фолбеком на Standard.
	 *
	 * Если в БД сохранён ID шаблона, которого нет в списке
	 * (например, кастомный шаблон номера), приводим его к Standard,
	 * чтобы не ломать интерфейс.
	 *
	 * @param string|null $value Строковое значение из БД
	 *
	 * @return self Соответствующий кейс или Standard по умолчанию
	 */
	public static function fromDatabase( ?string $value ): self {
		// Если значение пустое — возвращаем Standard
		if ( ! $value ) {
			return self::Standard;
		}

		// Пытаемся найти точное совпадение
		$tryCase = self::tryFrom( $value );

		if ( $tryCase ) {
			return $tryCase;
		}

		// Если это не Triple и не Common — считаем стандартным визуальным редактором
		return self::Standard;
	}

	/**
	 * Возвращает FQCN (полное имя класса) шаблона.
	 *
	 * Используется для динамического создания экземпляра шаблона
	 * через new $className() или Reflection.
	 *
	 * @return string Полное имя класса шаблона
	 */
	public function class(): string {
		return match ( $this ) {
			self::Standard => StandardTaskTemplate::class,
			self::Triple => ThreeInOneTemplate::class,
			self::Common => CommonConditionTemplate::class,

			self::Code        => CodeTaskTemplate::class,
			self::FileCode    => FileCodeTaskTemplate::class,
			self::File        => FileTaskTemplate::class,
			self::TwoFile     => TwoFileCodeTaskTemplate::class,
			self::TextSolution => TaskTextSolution::class,
			self::Choice   => ChoiceTaskTemplate::class,
			self::Matching => MatchingTaskTemplate::class,
			self::Ordering => OrderingTaskTemplate::class,
			self::Fill     => FillTaskTemplate::class,
			self::Audio    => AudioTaskTemplate::class,
			self::FileAnswer => FileAnswerTaskTemplate::class,
			self::AlternativeConditions => AlternativeConditionsTemplate::class,
		};
	}

	/**
	 * Возвращает человекочитаемое название шаблона для boilerplate.
	 *
	 * Используется в выпадающих списках и метках интерфейса.
	 *
	 * @return string Название шаблона
	 */
	public function label(): string {
		return match ( $this ) {
			self::Standard     => 'Стандартное задание',
			self::Triple       => 'Три в одном (ЕГЭ 19-21)',
			self::Common       => 'Общее условие',
			self::Code         => 'Задание с кодом',
			self::FileCode     => 'Задание с файлом и кодом',
			self::File         => 'Задание с файлом',
			self::TwoFile      => 'Задание с двумя файлами и кодом',
			self::TextSolution => 'Задание с решением',
			self::Choice       => 'Выбор варианта ответа',
			self::Matching     => 'Сопоставление',
			self::Ordering     => 'Сортировка',
			self::Fill         => 'Пропуски в тексте',
			self::Audio        => 'Задание с аудио',
			self::FileAnswer   => 'Развёрнутый ответ (файл, ручная проверка)',
			self::AlternativeConditions => 'Два условия на выбор (ОГЭ №13)',
		};
	}

	/**
	 * Ответ — файл (+опционально текст), проверка ТОЛЬКО ручная: нет чекера в
	 * `TaskCheckerRegistry`, нет строки `task_answer`, есть поле `task_materials`.
	 * Единая точка вместо разбросанных по коду `=== TaskTemplate::FileAnswer` —
	 * `AlternativeConditions` («Два условия на выбор», ОГЭ №13) устроен так же,
	 * просто с двумя условиями вместо одного (см. докблок класса шаблона).
	 * Используется станцией (`kege/exam.php`, тип ответа «файл»), générique-флоу
	 * попытки (`attempt-question.php`), листами результатов
	 * (`AttemptResultService`/`WorkDetailService`) и `AttemptTaskViewBuilder`
	 * (откуда брать материалы — `task_materials`, а не файловые LinkField-поля).
	 */
	public function isFileAnswerShape(): bool {
		return match ( $this ) {
			self::FileAnswer, self::AlternativeConditions => true,
			default => false,
		};
	}

	/**
	 * Задания с кодом получают необязательное доп.поле «Код» рядом с обычным
	 * текстовым ответом — авто-проверка (`TextAnswerChecker`) по-прежнему идёт
	 * только по `task_answer`/`text`, код виден учителю чисто информационно.
	 */
	public function hasCodeField(): bool {
		return match ( $this ) {
			self::Code, self::FileCode, self::TwoFile => true,
			default => false,
		};
	}
}
