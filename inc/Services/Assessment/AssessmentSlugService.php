<?php

declare( strict_types=1 );

namespace Inc\Services\Assessment;

use Inc\Managers\Wp\PostManager;
use Inc\Services\Subject\PostTypeResolver;

/**
 * Class AssessmentSlugService
 *
 * Адрес экзамена — его ID (`/{key}_assessments/723/`), а не транслитерированное или
 * percent-encoded название («%d0%b4%d0%b5…»): название правится и повторяется, кириллица
 * в ссылке нечитаема, а публичные экзамены раздают ссылкой. ID стабилен и уникален.
 *
 * Старый адрес после смены слага WordPress ведёт на новый сам (`_wp_old_slug`).
 *
 * @package Inc\Services\Assessment
 */
class AssessmentSlugService {

	public function __construct(
		private readonly PostManager $posts,
	) {}

	/**
	 * Приводит слаг экзамена к его ID. Идемпотентно: пост со слагом-ID не трогаем
	 * (вызов из хука сохранения не зацикливается).
	 */
	public function ensure( \WP_Post $post ): void {
		if ( ! PostTypeResolver::isAssessmentPostType( $post->post_type ) ) {
			return;
		}

		// Черновик-заготовка WP и ревизии слага не получают: ID у них служебный.
		if ( in_array( $post->post_status, array( 'auto-draft', 'inherit', 'trash' ), true ) ) {
			return;
		}

		if ( (string) $post->ID === $post->post_name ) {
			return;
		}

		$this->posts->update( $post->ID, array( 'post_name' => (string) $post->ID ) );
	}
}
