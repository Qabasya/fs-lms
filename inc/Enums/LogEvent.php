<?php

declare( strict_types=1 );

namespace Inc\Enums;

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
}
