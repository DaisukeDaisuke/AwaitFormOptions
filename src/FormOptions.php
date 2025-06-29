<?php

namespace DaisukeDaisuke\AwaitFormOptions;

abstract class FormOptions {
	use FormBridgeTrait;
	/**
	 * @return array<\Generator>
	 */
	abstract public function getOptions() : array;
}
