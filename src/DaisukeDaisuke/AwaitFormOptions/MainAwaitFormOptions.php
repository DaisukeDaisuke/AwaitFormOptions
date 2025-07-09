<?php

declare(strict_types=1);

namespace DaisukeDaisuke\AwaitFormOptions;

use pocketmine\plugin\PluginBase;
use cosmicpe\awaitform\AwaitForm;

class MainAwaitFormOptions extends PluginBase{
	protected function onEnable() : void{
		if(!AwaitForm::isRegistered()){
			AwaitForm::register($this);
		}
	}
}
