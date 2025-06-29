# AwaitFormOptions

## Overview

An option-driven form handler framework built on AwaitForm for `pmmp` plugins.  
Designed to modularize complex user interactions and support clean, reusable, async code.  

## Requirements

- [Cosmoverse/AwaitForm](https://github.com/Cosmoverse/AwaitForm)
- [SOF3/await-generator](https://github.com/SOF3/await-generator)

---

## Why?

Using AwaitForm directly is simple for small forms:  

```php
public function a(PlayerItemUseEvent $event): void {
	$player = $event->getPlayer();
	try {
		await::f2c(function() use ($player) {
			$form = AwaitForm::form("form", [
				FormControl::input("Current HP:", "20", (string) $player->getHealth()),
				FormControl::input("Max HP:", "20", (string) $player->getMaxHealth()),
			]);
			[$current, $max] = yield from $form->request($player);
			$player->setHealth((float) $current);
			$player->setMaxHealth((int) $max);
			$player->sendMessage("HP: {$current}/{$max}");
		});
	} catch (AwaitFormException | FormValidationException) {
		// Cancelled or invalid input
	}
}
```

But when handling multiple related form steps in one screen, things get messy fast.   
**Too many responsibilities are packed into one place.**    
<details align="center">
	<summary>See demo</summary>

https://github.com/user-attachments/assets/5be701db-4a41-4f04-bb49-693a8d40fdb8

</details>


---

## Solution: AwaitFormOptions

Split your form logic into reusable, testable option classes:  

```php
public function a(PlayerItemUseEvent $event) : void{
    $player = $event->getPlayer();

    Await::f2c(function() use ($player){
        try{
            yield from AwaitFormOptions::sendFormAsync(
                player: $player,
                title: "test",
                options: [
                    new HPFormOptions($player),
                ],
                neverRejects: false, // If false, the awaitFormOption propagates the AwaitFormException to the generator.
                throwExceptionInCaller: false, // If true, awaitFormOption will throw an exception on the caller
            );
        }catch(FormValidationException){
            // Form failed validation
        }
    });
}
```

---

## Example Option Class

Each option will yield from `$this->request($form);` and wait for the response. No more losing context!

```php
<?php

namespace daisukedaisuke\test;

use DaisukeDaisuke\AwaitFormOptions\FormOptions;
use cosmicpe\awaitform\FormControl;
use pocketmine\player\Player;
use cosmicpe\awaitform\AwaitFormException;

class HPFormOptions extends FormOptions {
	public function __construct(private Player $player) {}

	public function maxHP(): \Generator {
		try {
			$form = [
				FormControl::input("Max HP:", "20", (string) $this->player->getMaxHealth()),
			];
			[$maxHP] = yield from $this->request($form); // awaiting response
			$this->player->setMaxHealth((int) $maxHP);
			$this->player->sendMessage("Max HP: {$maxHP}");
		} catch (AwaitFormException $e) {
			var_dump($e->getCode());
		}
	}

	public function currentHP(): \Generator {
		try {
			$form = [
				FormControl::input("Current HP:", "20", (string) $this->player->getHealth()),
			];
			[$currentHP] = yield from $this->request($form); // awaiting response
			$this->player->setHealth((float) $currentHP);
			$this->player->sendMessage("Current HP: {$currentHP}");
		} catch (AwaitFormException $e) {
			var_dump($e->getCode());
		}
	}

	public function getOptions(): array {
		return [
			$this->maxHP(),
			$this->currentHP(),
		];
	}
}
```

![Image](https://github.com/user-attachments/assets/59789bc9-438f-485a-8ca9-625841ce0c66)

---

## Reusability

Yes, option classes are reusable!   
Try passing the same class multiple times:  

```php
public function a(PlayerItemUseEvent $event): void {
	$player = $event->getPlayer();
    Await::f2c(function () use ($player) {
        try {
            yield from AwaitFormOptions::sendFormAsync(
                player: $player,
                title: "test",
                options: [
                    new HPFormOptions($player),
                    new HPFormOptions($player),
                    new HPFormOptions($player),
                    new HPFormOptions($player),
                    new HPFormOptions($player),
                    new HPFormOptions($player),
                ],
                neverRejects: false,
                throwExceptionInCaller: true,
            );
        } catch (FormValidationException|AwaitFormException) {
        }
    });
}
```

![Image](https://github.com/user-attachments/assets/29ac3350-7368-4e00-aac8-caadbfabd75a)

Each instance is handled independently.  

---

## `neverRejects` and `throwExceptionInCaller`

If neverRejects is false, the child generator must handle the exception

If throwExceptionInCaller is true, the parent generator will receive an AwaitFormException  

```php
public function a(PlayerItemUseEvent $event) : void{
    $player = $event->getPlayer();

    Await::f2c(function() use ($player){
        try{
            yield from AwaitFormOptions::sendFormAsync(
                player: $player,
                title: "test",
                options: [
                    new HPFormOptions($player),
                ],
                neverRejects: false, // If false, the awaitFormOption propagates the AwaitFormException to the generator.
                throwExceptionInCaller: true, // If true, awaitFormOption will throw an exception on the caller
            );
        }catch(FormValidationException|AwaitFormException){
            // Form failed validation
        }
    });
}
```

---

## Standalone

sendForm and sendMenu can also be called completely standalone, without receiving exceptions  

```php
public function a(PlayerItemUseEvent $event): void {
    $player = $event->getPlayer();
    AwaitFormOptions::sendForm(
        player: $player,
        title: "test",
        options: [
            new HPFormOptions($player),
        ],
        neverRejects: true,
    );
}
```

---

## Menu Support

AwaitFormOptions also supports `menu` interactions.   
Unselected menu options are discarded and not executed.  

```php
public function a(PlayerItemUseEvent $event): void {
    $player = $event->getPlayer();
    Await::f2c(function() use ($player): \Generator{
        try{
            yield from AwaitFormOptions::sendMenuAsync(
                player: $player,
                title: "test",
                content: "a",
                buttons: [
                    new NameMenuOptions($player, ["f", "a"]),
                ],
                neverRejects: false,
                throwExceptionInCaller: false,
            );
        }catch(FormValidationException){

        }
    });
}
```

---

## Example: MenuOptions

Even if multiple buttons share the same label or value, AwaitFormOptions resolves conflicts automatically.  

```php
<?php

namespace daisukedaisuke\test;

use cosmicpe\awaitform\Button;
use DaisukeDaisuke\AwaitFormOptions\MenuOptions;
use pocketmine\player\Player;

class NameMenuOptions extends MenuOptions {
	public function __construct(private Player $player, private array $options) {}

	public function optionsA(): \Generator {
		$buttons = [];
		foreach ($this->options as $item) {
			$buttons[$item] = Button::simple($item);
		}
		$selected = yield from $this->request($buttons);
		$this->player->sendMessage($selected . ", " . __FUNCTION__);
	}

	public function optionsB(): \Generator {
		$selected = yield from $this->request([
			[Button::simple("a"), "a"], // Even duplicate keys are resolved correctly
		]);
		$this->player->sendMessage($selected . ", " . __FUNCTION__);
	}

	public function getOptions(): array {
		return [
			$this->optionsB(),
			$this->optionsA(),
		];
	}
}
```

---

## Reusing Menu Options

Just like form options, menu options can be reused as well:  

```php
public function a(PlayerItemUseEvent $event): void {
    $player = $event->getPlayer();
    Await::f2c(function () use ($player) : \Generator{
        try {
            yield from AwaitFormOptions::sendMenuAsync(
                player: $player,
                title: "test",
                content: "a",
                buttons: [
                    new NameMenuOptions($player, ["a", "b"]),
                    new NameMenuOptions($player, ["c", "d"]),
                    new NameMenuOptions($player, ["e", "f"]),
                    new NameMenuOptions($player, ["g", "h"]),
                    new NameMenuOptions($player, ["i", "j"]),
                ],
                neverRejects: false,
            );
        } catch (FormValidationException) {
        }
    });
}
```

![Image](https://github.com/user-attachments/assets/81872ca7-1e99-4919-9e5e-8c5ee3bf3045)

---

## Summary

✅ Modular  
✅ Async  
✅ Clean separation of form logic  
✅ Handles multiple options, cancellations, and reuse easily

> Build dynamic, plugin-ready forms with AwaitFormOptions.

