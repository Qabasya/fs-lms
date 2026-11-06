<?php

declare( strict_types=1 );

namespace Inc\Services\Settings;

use Inc\Repositories\OptionsRepositories\SubjectRepository;
use Inc\Repositories\WPDBRepositories\PersonRepository;
use Inc\Services\Shared\PluginConfig;

/** Данные выбора предметов и учеников в настройках рейтинга. */
class RankingSettingsViewService {
	public function __construct(
		private readonly SubjectRepository $subjects,
		private readonly PersonRepository $persons,
		private readonly PluginConfig $config,
	) {}

	public function build(): array {
		$students = array();
		foreach ( array_merge( $this->persons->findByIsStudent( true ), $this->persons->findByIds( $this->config->rankingExcludedStudents() ) ) as $person ) {
			$students[ $person->id ] = array( 'id' => $person->id, 'name' => $person->fullName() );
		}
		return array(
			'subjects' => $this->subjects->readAll(),
			'students' => array_values( $students ),
			'hidden_subjects' => $this->config->rankingHiddenSubjects(),
			'excluded_students' => $this->config->rankingExcludedStudents(),
		);
	}
}
