<?php

namespace DaisukeDaisuke\AwaitFormOptions;

abstract class MenuOptions{
	use FormBridgeTrait;
	/**
	 * @return array<\Generator>
	 */
	abstract public function getOptions() : array;
}