<?php
/** Student-only self-start transport. */
declare( strict_types=1 );
namespace Inc\Callbacks\Profile;
use Inc\Core\BaseController;
use Inc\Enums\Access\Capability;
use Inc\Shared\Traits\Authorizer;
use Inc\Enums\Wp\Nonce;
use Inc\Services\Enrollment\SelfPacedEnrollmentService;
use Inc\Shared\Traits\Sanitizer;
class SelfPacedEnrollmentCallbacks extends BaseController {
	use Sanitizer;
	use Authorizer;
	public function __construct( private readonly SelfPacedEnrollmentService $service ) { parent::__construct(); }
	public function ajaxStartSelfPacedCourse(): void {
		$this->authorize( Nonce::LearnerProfile, Capability::Read );
		if ( ! is_user_logged_in() ) {
			$this->error( 'Требуется вход.' );
			return;
		}
		try {
			$raw = $this->unslashRawString( 'course_id' );
			if ( ! preg_match( '/^[1-9][0-9]*$/D', $raw ) || (string) (int) $raw !== $raw ) {
				throw new \InvalidArgumentException( 'Некорректный курс.' );
			}
			$result = $this->service->start( (int) $raw, get_current_user_id() );
		} catch ( \DomainException | \InvalidArgumentException $error ) {
			$this->error( $error->getMessage() );
			return;
		} catch ( \Throwable $error ) {
			\Inc\Shared\PluginLogger::exception( 'SelfPacedEnrollment', $error, array(), true );
			$this->error( 'Не удалось начать курс. Повторите попытку позже.' );
			return;
		}
		$this->success( $result );
	}
}
