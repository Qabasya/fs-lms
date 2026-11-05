<?php
/** Safe learner projection of independent courses; never expands the learner group context. */
declare( strict_types=1 );
namespace Inc\Services\Profile\Learner;

use Inc\DTO\Course\CourseDTO;
use Inc\Managers\Course\CourseManager;
use Inc\Managers\Course\LessonManager;
use Inc\Repositories\OptionsRepositories\SubjectRepository;
use Inc\Services\Enrollment\SelfPacedAccessPolicy;

class SelfPacedCourseCatalog {
	public function __construct(
		private readonly CourseManager $courses,
		private readonly LessonManager $lessons,
		private readonly SubjectRepository $subjects,
		private readonly SelfPacedAccessPolicy $access,
	) {}

	/** Published eligible offers, excluding only an existing service enrollment. */
	public function offers( int $personId, array $groups, bool $canStart ): array {
		$started = array_column( $groups, 'self_paced_course_id' );
		$result = array();
		foreach ( $this->subjects->readAll() as $subject ) {
			foreach ( $this->courses->getBankBySubject( $subject->key, array( 'status' => 'publish', 'limit' => -1 ) ) as $course ) {
				if ( 'publish' !== $course->status || ! $course->selfPacedEnabled || in_array( $course->id, $started, true )
					|| ! $this->access->eligibleBasis( $personId, $course->audienceSubjectKeys ) ) {
					continue;
				}
				try {
					$this->access->assertReady( $course );
				} catch ( \DomainException | \InvalidArgumentException $error ) {
					continue;
				}
				$preview = $this->preview( $course, $subject->name, $canStart );
				if ( null !== $preview ) {
					$result[] = $preview;
				}
			}
		}
		usort( $result, static fn( array $a, array $b ): int => $a['course_id'] <=> $b['course_id'] );
		return $result;
	}

	/** An explicit whitelist: no authoring tree, lesson IDs, steps, resources or links. */
	private function preview( CourseDTO $course, string $subject, bool $canStart ): ?array {
		$modules = array();
		$num = 0;
		foreach ( $course->modules as $index => $module ) {
			$lessons = array();
			foreach ( $module->lessonIds as $lessonId ) {
				$lesson = $this->lessons->get( $lessonId );
				if ( ! $lesson || 'publish' !== $lesson->status ) {
					return null;
				}
				++$num;
				$lessons[] = array( 'num' => $num, 'title' => $lesson->topic,
					'date' => '', 'room' => '', 'status' => 'locked', 'player_url' => '', 'mod' => $index );
			}
			$modules[] = array( 'name' => $module->title, 'lessons' => $lessons );
		}
		return array(
			'id' => null, 'key' => 'course:' . $course->id, 'course_id' => $course->id, 'group_id' => null,
			'enrolled' => false, 'can_start' => $canStart, 'access_paused' => false,
			'code' => '', 'open' => true, 'title' => $course->title,
			'description' => $this->description( $course ), 'subject' => $subject, 'subject_key' => $course->subjectKey,
			'avatar' => $this->courses->avatarUrl( $course->id ), 'teacher' => '', 'room' => '', 'start' => '',
			'modules' => $modules, 'lessons' => null, 'passed' => 0, 'total' => $num,
			'not_started' => false, 'continue_url' => '', 'continue_num' => 0,
		);
	}

	/** Plain text only; shortcodes and HTML can contain private resource URLs. */
	public function description( CourseDTO $course ): string {
		$text = wp_strip_all_tags( (string) preg_replace( '/\[[^\]]*\]/u', '', $course->descriptionHtml ), true );
		return trim( (string) preg_replace( '~(?:https?://|s3://|//)[^\s<>]+~iu', '', $text ) );
	}
}
