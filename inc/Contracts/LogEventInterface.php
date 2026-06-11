<?php

declare( strict_types=1 );

namespace Inc\Contracts;

/**
 * Маркерный интерфейс для payload-DTO событий шины логирования.
 *
 * Каждое событие, передаваемое через LogEventDispatcher, должно реализовывать
 * этот интерфейс. Это обеспечивает типобезопасность dispatch() и позволяет
 * subscriber'ам получать типизированный payload без мешка аргументов.
 */
interface LogEventInterface {}
