<?php

declare(strict_types=1);

namespace Inc\Callbacks;

use Inc\Core\BaseController;
use Inc\DTO\TaxonomyDataDTO;
use Inc\Enums\Nonce;
use Inc\Managers\TermManager;
use Inc\Repositories\TaxonomyRepository;
use Inc\Shared\Traits\Authorizer;
use Inc\Shared\Traits\Sanitizer;

class TaxonomySettingsCallbacks extends BaseController {

	use Authorizer;
	use Sanitizer;

	public function __construct(
		private readonly TaxonomyRepository $taxonomies,
		private readonly TermManager        $terms,
	) {
		parent::__construct();
	}

	public function ajaxStoreTaxonomy(): void {
		$this->authorize( Nonce::Subject );

		$subject_key  = $this->requireKey( 'subject_key' );
		$tax_suffix   = $this->requireKey( 'tax_slug' );
		$tax_name     = $this->requireText( 'tax_name', error: 'Название таксономии обязательно' );
		$display_type = $this->getValidatedDisplayType();
		$tax_slug     = "{$subject_key}_{$tax_suffix}";

		if ( strlen( $tax_slug ) > 32 ) {
			$this->error( 'Ярлык слишком длинный (макс. ' . ( 32 - strlen( $subject_key ) - 1 ) . ' символов)' );
		}

		if ( taxonomy_exists( $tax_slug ) ) {
			$this->error( "Таксономия «{$tax_slug}» уже существует в системе", array( 'slug' => $tax_slug ) );
		}

		$result = $this->taxonomies->save( new TaxonomyDataDTO(
			slug:         $tax_slug,
			name:         $tax_name,
			subject_key:  $subject_key,
			display_type: $display_type,
			is_required:  $this->sanitizeBool( 'is_required' ),
		) );

		if ( $result ) {
			flush_rewrite_rules();
		}

		$this->respond(
			$result,
			error_msg: 'Не удалось сохранить таксономию',
			success_msg: 'Таксономия создана'
		);
	}

	public function ajaxUpdateTaxonomy(): void {
		$this->authorize( Nonce::Subject );

		$subject_key = $this->requireKey( 'subject_key' );
		$tax_slug    = $this->requireKey( 'tax_slug' );
		$tax_name    = $this->requireText( 'tax_name', error: 'Название обязательно' );

		$result = $this->taxonomies->save( new TaxonomyDataDTO(
			slug:         $tax_slug,
			name:         $tax_name,
			subject_key:  $subject_key,
			display_type: $this->getValidatedDisplayType(),
			is_required:  $this->sanitizeBool( 'is_required' ),
		) );

		if ( $result ) {
			flush_rewrite_rules();
		}

		$this->respond(
			$result,
			error_msg: 'Ошибка при обновлении таксономии',
			success_msg: 'Таксономия обновлена'
		);
	}

	public function ajaxDeleteTaxonomy(): void {
		$this->authorize( Nonce::Subject );

		$subject_key = $this->requireKey( 'subject_key' );
		$tax_slug    = $this->requireKey( 'tax_slug' );

		$this->terms->deleteAll( $tax_slug );

		$result = $this->taxonomies->remove( $subject_key, $tax_slug );

		if ( $result ) {
			flush_rewrite_rules();
		}

		$this->respond(
			$result,
			error_msg: 'Ошибка при удалении таксономии',
			success_msg: 'Таксономия удалена'
		);
	}

	private function getValidatedDisplayType(): string {
		$type = $this->sanitizeText( 'display_type' );
		return in_array( $type, array( 'select', 'radio', 'checkbox' ), true ) ? $type : 'select';
	}
}
