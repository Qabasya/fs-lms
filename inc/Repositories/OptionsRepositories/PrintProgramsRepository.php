<?php

declare( strict_types=1 );

namespace Inc\Repositories\OptionsRepositories;

use Inc\Enums\Settings\OptionName;

/**
 * Class PrintProgramsRepository
 *
 * Название программы и стоимость месяца обучения по предмету — поля
 * `{{program}}` и `{{price}}` документов «Центра печати».
 *
 * Хранит опцию `fs_lms_print_programs`: [subject_key => ['program' => string, 'price' => string]].
 * Пока предмет не сохранён со страницы, действуют значения по умолчанию
 * ({@see DEFAULTS}) — договоры печатаются сразу после выката.
 *
 * @package Inc\Repositories\OptionsRepositories
 */
class PrintProgramsRepository {

	/**
	 * Значения по умолчанию (согласованы для договора 2026/27).
	 */
	private const array DEFAULTS = array(
		'inf_ege' => array( 'program' => 'Подготовка к ЕГЭ по информатике', 'price' => '14 000 руб.' ),
		'inf_oge' => array( 'program' => 'Подготовка к ОГЭ по информатике', 'price' => '10 000 руб.' ),
		'robo'    => array( 'program' => 'Робототехника и программирование Arduino', 'price' => '9 000 руб.' ),
		'python'  => array( 'program' => 'Программирование на Python', 'price' => '12 000 руб.' ),
	);

	/**
	 * Программа и цена предмета; пустые строки — не заданы.
	 *
	 * @param string $subjectKey Ключ предмета
	 *
	 * @return array{program: string, price: string}
	 */
	public function get( string $subjectKey ): array {
		$row = $this->readAll()[ $subjectKey ] ?? self::DEFAULTS[ $subjectKey ] ?? array();

		return array(
			'program' => (string) ( $row['program'] ?? '' ),
			'price'   => (string) ( $row['price'] ?? '' ),
		);
	}

	/**
	 * Сохраняет программу и цену одного предмета (остальные предметы не трогает).
	 *
	 * @param string $subjectKey Ключ предмета
	 * @param string $program    Название программы
	 * @param string $price      Стоимость месяца обучения, как печатается в договоре
	 */
	public function save( string $subjectKey, string $program, string $price ): void {
		$all                = $this->readAll();
		$all[ $subjectKey ] = array( 'program' => $program, 'price' => $price );

		update_option( OptionName::PrintPrograms->value, $all, false );
	}

	/**
	 * @return array<string, array{program?: string, price?: string}>
	 */
	private function readAll(): array {
		$all = get_option( OptionName::PrintPrograms->value, array() );

		return is_array( $all ) ? $all : array();
	}
}
