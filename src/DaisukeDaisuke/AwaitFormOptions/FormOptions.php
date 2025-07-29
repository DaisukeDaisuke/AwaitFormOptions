<?php

declare(strict_types=1);

namespace DaisukeDaisuke\AwaitFormOptions;

abstract class FormOptions{
	use FormBridgeTrait;

	/**
	 * @return array<\Generator>|array<FormOptions>
	 */
	abstract public function getOptions() : array;
}
