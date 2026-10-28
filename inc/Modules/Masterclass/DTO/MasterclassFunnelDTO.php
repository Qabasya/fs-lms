<?php

declare( strict_types=1 );

namespace Inc\Modules\Masterclass\DTO;

/**
 * Воронка мастер-класса: создано → входили → вернулись домой → нажали «Связаться» → подали заявку → зачислены.
 *
 * Считается по колонкам аккаунтов, включая мягко удалённые (строки не удаляются — воронка не «худеет»
 * после чистки неиспользованных).
 *
 * @package Inc\Modules\Masterclass\DTO
 */
readonly class MasterclassFunnelDTO {

	/**
	 * @param int $created   Всего выдано аккаунтов
	 * @param int $loggedIn  Входили хотя бы раз
	 * @param int $atHome    Возвращались домой
	 * @param int $contacted Нажимали «Связаться»
	 * @param int $applied   Подали заявку (состояние `Applied` или `Enrolled`)
	 * @param int $enrolled  Зачислены
	 */
	public function __construct(
		public int $created,
		public int $loggedIn,
		public int $atHome,
		public int $contacted,
		public int $applied,
		public int $enrolled,
	) {}

	/**
	 * @return array<string, int>
	 */
	public function toArray(): array {
		return array(
			'created'   => $this->created,
			'loggedIn'  => $this->loggedIn,
			'atHome'    => $this->atHome,
			'contacted' => $this->contacted,
			'applied'   => $this->applied,
			'enrolled'  => $this->enrolled,
		);
	}
}
