<?php

declare( strict_types=1 );

namespace Inc\DTO;

/**
 * Class EnrollmentInputDTO
 *
 * Входные данные из модального окна зачисления в административной панели.
 *
 * @package Inc\DTO
 *
 * ### Основные обязанности:
 *
 * 1. **Типобезопасная передача** — инкапсулирует данные, введённые сотрудником при зачислении студента.
 *
 * ### Архитектурная роль:
 *
 * Передаётся в EnrollmentService::enroll() для создания записи зачисления.
 * Все поля уже санитизированы через Sanitizer trait до создания DTO.
 *
 * ### Примечания:
 *
 * - contractNo и contractDate — реквизиты договора на обучение
 * - orderNo и orderDate — реквизиты приказа о зачислении
 * - enrolledAt — фактическая дата зачисления (может отличаться от текущей)
 * - sendEmailAuto — флаг автоматической отправки уведомления студенту
 */
readonly class EnrollmentInputDTO {

	/**
	 * Конструктор DTO.
	 *
	 * @param int    $applicationId ID заявки, на основе которой производится зачисление
	 * @param string $contractNo    Номер договора на обучение
	 * @param string $contractDate  Дата договора (Y-m-d)
	 * @param string $orderNo       Номер приказа о зачислении
	 * @param string $orderDate     Дата приказа (Y-m-d)
	 * @param string $enrolledAt    Дата зачисления (Y-m-d H:i:s)
	 * @param string $subjectKey    Ключ предмета, на который зачисляется студент
	 * @param string $groupId       ID группы (slug из student_groups)
	 * @param string $periodKey     Ключ учебного периода
	 * @param bool   $sendEmailAuto Автоматически отправить уведомление студенту
	 */
	public function __construct(
		public int    $applicationId,
		public string $contractNo,
		public string $contractDate,
		public string $orderNo,
		public string $orderDate,
		public string $enrolledAt,
		public string $subjectKey,
		public string $groupId,
		public string $periodKey,
		public bool   $sendEmailAuto,
	) {}
}