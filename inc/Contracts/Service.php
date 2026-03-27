<?php

namespace Inc\Contracts;
/**
 * Это интерфейс для сервисов
 * Нужен для гарантии реализации метода register
 * Чтобы не было проверок в Init
 */
interface Service {
	public function register(): void;
}