<?php

declare(strict_types=1);

namespace DaisukeDaisuke\AwaitFormOptions;

use cosmicpe\awaitform\AwaitForm;
use pocketmine\plugin\PluginBase;

class MainAwaitFormOptions extends PluginBase{
	protected function onEnable() : void{
		if(!AwaitForm::isRegistered()){
			AwaitForm::register($this);
		}
	}
}
