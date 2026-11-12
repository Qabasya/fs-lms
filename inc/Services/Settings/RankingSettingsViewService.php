<?php

declare( strict_types=1 );

namespace Inc\Services\Settings;

use Inc\Repositories\OptionsRepositories\SubjectRepository;
use Inc\Repositories\WPDBRepositories\PersonRepository;
use Inc\Repositories\WPDBRepositories\LearnerRankingRepository;
use Inc\Services\Shared\PluginConfig;

/** Данные выбора предметов и учеников в настройках рейтинга. */
class RankingSettingsViewService {
	public function __construct(
		private readonly SubjectRepository $subjects,
		private readonly PersonRepository $persons,
		private readonly PluginConfig $config,
		private readonly ?LearnerRankingRepository $ranking = null,
	) {}

	public function build(): array {
		$students = array();
		foreach ( array_merge( $this->persons->findByIsStudent( true ), $this->persons->findByIds( $this->config->rankingExcludedStudents() ) ) as $person ) {
			$students[ $person->id ] = array( 'id' => $person->id, 'name' => $person->fullName() );
		}
		foreach ( $this->config->rankingExcludedStudents() as $id ) {
			if ( ! isset( $students[ $id ] ) ) {
				$students[ $id ] = array( 'id' => $id, 'name' => 'Удалённый ученик' );
			}
		}
		$cards = array();
		foreach ( $this->config->usesSubjectRanking() ? $this->subjects->readAll() : array() as $subject ) {
			$policy = $this->config->rankingForSubject( $subject->key );
			$cards[] = array( 'subject' => $subject, 'policy' => $policy );
		}
		return array(
			'subject_settings' => $this->config->usesSubjectRanking(),
			'cards' => $cards,
			'subjects' => $this->subjects->readAll(),
			'students' => array_values( $students ),
			'hidden_subjects' => $this->config->rankingHiddenSubjects(),
			'excluded_students' => $this->config->rankingExcludedStudents(),
		);
	}
}
