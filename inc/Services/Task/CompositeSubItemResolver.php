<?php

declare( strict_types=1 );

namespace Inc\Services\Task;

use Inc\Services\Subject\PostTypeResolver;
use Inc\Services\Template\TemplateRegistry;
use Inc\Services\Template\TemplateResolver;
use WP_Post;

/**
 * Class CompositeSubItemResolver
 *
 * Какие подпункты составного задания (Triple 19-21) участвуют в экзамене —
 * единый источник правила для показа условий, авто-проверки и листа ответов.
 *
 * Шаблон ({@see \Inc\MetaBoxes\Templates\ThreeInOneTemplate::expandsForExam()})
 * знает только про три подпункта разом и о самой записи ничего не знает. Но в
 * банке блок 19-21 заводят двумя способами:
 *  - одна запись на весь блок — тогда она даёт все три подпункта;
 *  - три записи, по одной на номер (у каждой свой номер в банке и свой терм
 *    `{key}_task_number`) — тогда запись отвечает только за свой номер, иначе
 *    один и тот же блок трижды и показывался бы, и оценивался.
 * Номер терма и решает, какой случай перед нами.
 *
 * @package Inc\Services\Task
 */
class CompositeSubItemResolver {

	/**
	 * @param TemplateResolver $resolver  Шаблон задания по записи
	 * @param TemplateRegistry $templates Реестр шаблонов заданий
	 */
	public function __construct(
		private readonly TemplateResolver $resolver,
		private readonly TemplateRegistry $templates,
	) {}

	/**
	 * Подпункты записи в порядке шаблона; пустой массив — задание не составное.
	 *
	 * @param WP_Post $post Запись задания
	 *
	 * @return array<int, array{key: string, condition_field: string, answer_field: string}>
	 */
	public function forPost( WP_Post $post ): array {
		$subItems = $this->templates->get( $this->resolver->resolveId( $post ) )?->expandsForExam() ?? array();
		if ( empty( $subItems ) ) {
			return array();
		}

		$number = $this->taskNumber( $post );
		if ( '' === $number ) {
			return $subItems;
		}

		$own = array_values( array_filter(
			$subItems,
			static fn( array $sub ): bool => (string) $sub['key'] === $number
		) );

		// Номер вне набора подпунктов (запись на весь блок помечена, например, «19-21»)
		// — правило неприменимо, отдаём блок целиком.
		return empty( $own ) ? $subItems : $own;
	}

	/**
	 * Номер задания из фиксированной таксономии предмета ('' — терма нет или
	 * запись не из банка заданий предмета).
	 *
	 * @param WP_Post $post Запись задания
	 */
	private function taskNumber( WP_Post $post ): string {
		$subjectKey = PostTypeResolver::subjectFromTaskPostType( $post->post_type );
		if ( '' === $subjectKey ) {
			return '';
		}

		$terms = wp_get_post_terms(
			$post->ID,
			PostTypeResolver::getTaskTaxonomy( $subjectKey ),
			array( 'fields' => 'names' )
		);

		return ( ! is_wp_error( $terms ) && ! empty( $terms ) ) ? trim( (string) $terms[0] ) : '';
	}
}