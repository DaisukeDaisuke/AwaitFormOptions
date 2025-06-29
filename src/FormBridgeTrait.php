<?php

namespace DaisukeDaisuke\AwaitFormOptions;

use pocketmine\utils\Utils;
use cosmicpe\awaitform\FormControl;
use cosmicpe\awaitform\Button;
use cosmicpe\awaitform\AwaitFormException;

trait FormBridgeTrait{
	private RequestResponseBridge $bridge;

	public function setBridge(RequestResponseBridge $bridge): void {
		$this->bridge = $bridge;
	}

	/**
	 * @param array $value
	 * @return \Generator
	 * @throws AwaitFormException
	 */
	public function request(array $value) : \Generator{
		Utils::validateArrayValueType($value, static function(FormControl|Button|array $value){});
		if(!isset($this->bridge)){
			throw new \BadFunctionCallException("bridge is not set");
		}
		return yield from $this->bridge->request($value);
	}

}