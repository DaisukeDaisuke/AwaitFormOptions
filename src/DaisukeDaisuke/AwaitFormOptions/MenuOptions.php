<?php

declare(strict_types=1);

namespace DaisukeDaisuke\AwaitFormOptions;

abstract class MenuOptions{
	use FormBridgeTrait;

	/**
	 * @return array<\Generator>|array<MenuOptions>
	 */
	abstract public function getOptions() : array;
}
