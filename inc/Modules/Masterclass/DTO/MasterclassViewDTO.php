<?php

declare( strict_types=1 );

namespace Inc\Modules\Masterclass\DTO;

use Inc\Modules\Masterclass\Enums\MasterclassAccountState;

/**
 * Вид плеера для ученика с временным аккаунтом: что рисуют швы Ш2 (баннер, «Связаться», «Выйти», экран заморозки).
 *
 * Собирает {@see \Inc\Modules\Masterclass\Services\MasterclassAccessGuard::viewFor()}; `null` вместо него —
 * это не аккаунт мастер-класса, и плеер остаётся прежним.
 *
 * @package Inc\Modules\Masterclass\DTO
 */
readonly class MasterclassViewDTO {

	/**
	 * @param MasterclassAccountState $state        Состояние аккаунта
	 * @param bool                    $frozen       Доступ закрыт (наступил `freeze_at`)
	 * @param bool                    $showBanner   Показывать баннер «запишитесь на занятия»
	 * @param string                  $bannerText   Текст баннера
	 * @param string                  $phone        Телефон центра для `tel:` (пусто — кнопки нет)
	 * @param string                  $messengerUrl Ссылка на мессенджер (пусто — кнопки нет)
	 * @param string                  $applyUrl     Адрес страницы заявки
	 * @param string                  $logoutUrl    Адрес выхода
	 */
	public function __construct(
		public MasterclassAccountState $state,
		public bool $frozen,
		public bool $showBanner,
		public string $bannerText,
		public string $phone,
		public string $messengerUrl,
		public string $applyUrl,
		public string $logoutUrl,
	) {}

	/**
	 * @return array<string, scalar>
	 */
	public function toArray(): array {
		return array(
			'state'        => $this->state->value,
			'frozen'       => $this->frozen,
			'showBanner'   => $this->showBanner,
			'bannerText'   => $this->bannerText,
			'phone'        => $this->phone,
			'messengerUrl' => $this->messengerUrl,
			'applyUrl'     => $this->applyUrl,
			'logoutUrl'    => $this->logoutUrl,
		);
	}
}
