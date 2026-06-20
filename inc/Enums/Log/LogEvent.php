<?php

declare( strict_types=1 );

namespace Inc\Enums\Log;

/**
 * Каталог доменных событий шины логирования.
 *
 * Единая точка правды для источников (dispatch) и subscriber'ов (subscribe).
 * Использовать строковые значения для отладки/логирования.
 *
 * Группировка по каналам (см. LogChannel):
 *   - EntityAudit  : Entity*
 *   - Enrollment   : Enrollment* / Application*
 *   - PiiAccess    : PiiRevealed
 *   - DataChange   : PersonDataChanged
 *   - ConsentChange: ConsentChanged
 *   - Email        : EmailSent
 *   - Deletion     : EntityHardDeleted
 *   - Auth         : Auth* (обрабатываются WP-хуками в AuthLogController, не через шину)
 */
enum LogEvent: string {

	// ===== Канал: EntityAudit — действия с сущностями =====

	case SubjectCreated   = 'subject.created';
	case SubjectUpdated   = 'subject.updated';
	case SubjectDeleted   = 'subject.deleted';

	case TaxonomyCreated  = 'taxonomy.created';
	case TaxonomyUpdated  = 'taxonomy.updated';
	case TaxonomyDeleted  = 'taxonomy.deleted';

	case TermCreated = 'term.created';
	case TermUpdated = 'term.updated';
	case TermDeleted = 'term.deleted';

	case TemplateCreated  = 'template.created';
	case TemplateUpdated  = 'template.updated';
	case TemplateDeleted  = 'template.deleted';

	case BoilerplateCreated = 'boilerplate.created';
	case BoilerplateUpdated = 'boilerplate.updated';
	case BoilerplateDeleted = 'boilerplate.deleted';

	case TaskCreated      = 'task.created';
	case TaskUpdated      = 'task.updated';
	case TaskDeleted      = 'task.deleted';

	case ArticleCreated   = 'article.created';
	case ArticleUpdated   = 'article.updated';
	case ArticleDeleted   = 'article.deleted';

	case GroupCreated     = 'group.created';
	case GroupUpdated     = 'group.updated';
	case GroupDeleted     = 'group.deleted';

	case PeriodCreated    = 'period.created';
	case PeriodUpdated    = 'period.updated';
	case PeriodDeleted    = 'period.deleted';

	case UserCreated      = 'user.created';
	case UserUpdated      = 'user.updated';
	case UserDeleted      = 'user.deleted';

	// Сводка импорта CSV (отдельная per-entity запись на каждого ученика идёт через StudentEnrolled)
	case CsvImported      = 'csv.imported';

	// ===== Канал: EnrollmentAudit — путь зачисления =====

	case ApplicationCreated   = 'enrollment.application_created';
	case ApplicationExpired   = 'enrollment.application_expired';
	case ApplicationTrashed   = 'enrollment.application_trashed';
	case ApplicationRestored  = 'enrollment.application_restored';
	case ApplicationUpdated   = 'enrollment.application_updated';
	case ApplicationViewed    = 'enrollment.application_viewed';
	case ParentSigned         = 'enrollment.parent_signed';
	case EnrollmentStarted    = 'enrollment.started';
	case StudentEnrolled      = 'enrollment.student_enrolled';
	case EnrollmentFailed     = 'enrollment.failed';
	case EnrollmentCanceled   = 'enrollment.canceled';
	case StudentExpelled      = 'enrollment.student_expelled';
	case StudentRestored      = 'enrollment.student_restored';

	// ===== Канал: PiiAccess — доступ к персональным данным =====

	case PiiRevealed = 'pii.revealed';

	// ===== Канал: DataChange — изменения данных пользователя =====

	case PersonDataChanged = 'person.data_changed';
	case PersonSoftDeleted = 'person.soft_deleted';

	// ===== Канал: ConsentChange — изменения согласия =====

	case ConsentChanged = 'consent.changed';

	// ===== Канал: Email — отправка писем =====

	case EmailSent = 'email.sent';

	// ===== Канал: Deletion — GDPR hard delete =====

	case EntityHardDeleted = 'entity.hard_deleted';

	// ===== Канал: LearningEvents — программа группы (Этап 2) =====

	case CourseAssigned           = 'learning.course_assigned';
	case LessonAddedToProgram     = 'learning.lesson_added';
	case LessonRemovedFromProgram = 'learning.lesson_removed';
	case ScheduleChanged          = 'learning.schedule_changed';
	case ExtraWorksChanged        = 'learning.extra_works_changed';
	case LessonPublished          = 'learning.lesson_published';
	case LessonHidden             = 'learning.lesson_hidden';

	// ===== Канал: LearningEvents — сдача работ (Этап 3) =====

	case SubmissionMade     = 'learning.submission_made';
	case SubmissionGraded   = 'learning.submission_graded';
	case SubmissionReturned = 'learning.submission_returned';

	// ===== Канал: LearningEvents — контрольные и экзамены (Этап 4) =====

	case AttemptStarted   = 'learning.attempt_started';
	case AttemptSubmitted = 'learning.attempt_submitted';
	case AttemptGraded    = 'learning.attempt_graded';
	case AttemptExpired   = 'learning.attempt_expired';
}
