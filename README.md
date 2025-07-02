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
  
> [!IMPORTANT]
> yield from $this->request() can only be called once per generator. Calling it multiple times requires re-execution of the parent function.  
> Calling it more than twice on a one generator will raise an exception.  

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

If neverRejects is false, the child generator must handle the AwaitFormException

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
use cosmicpe\awaitform\AwaitFormException;

class NameMenuOptions extends MenuOptions{
	public function __construct(private Player $player, private array $options){
	}

	public function optionsA() : \Generator{
		try{
			$test = [];
			foreach($this->options as $item){
				$test[$item] = Button::simple($item);
			}
			$test = yield from $this->request($test);
			$this->player->sendMessage($test.", ".__FUNCTION__);
		}catch(AwaitFormException $exception){

		}
	}

	public function optionsB() : \Generator{
		try{
			$test = yield from $this->request([
				[Button::simple("a"), "a"], //Even if you use duplicate keys, Awaitformoption will resolve it
			]);
			$this->player->sendMessage($test.", ".__FUNCTION__);
		}catch(AwaitFormException $exception){

		}
	}

	public function getOptions() : array{
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
                throwExceptionInCaller: false
            );
        } catch (FormValidationException) {
        }
    });
}
```

![Image](https://github.com/user-attachments/assets/81872ca7-1e99-4919-9e5e-8c5ee3bf3045)

---

### 🧩 Menu Advanced Usage: Attaching Objects to Buttons
Normally, Button::simple("label") returns a Button that maps to a string value.
But what if you want to associate a more complex object, like a Player, Entity, or CustomData — with each button?

You can do this easily by passing `[Button::simple(...), $value]` into the menu array.

```php
$selected  = yield from $this->request([
    [Button::simple("Label A"), $someObject],
    [Button::simple("Label B"), "custom-id"],
    [Button::simple("Label C"), 123],
]);
```
#### In this format:
- The first element is always a Button object.
- The second element is the value that will be returned if the button is selected.
- The returned result is mapped correctly even for duplicate labels or repeated values.
- You can use any scalar or object, including players, entities, and custom classes.

### Example

```php
public function onUse(PlayerItemUseEvent $event): void{
    $player = $event->getPlayer();
    if(!$player->isSneaking()){
        return;
    }
    Await::f2c(function() use ($player) {
        try {

            $entities = [];
            $world = $player->getWorld();
            foreach($world->getEntities() as $entity){
                if(!$entity instanceof Living){
                    continue;
                }
                $entities[] = $entity;
            }

            yield from AwaitFormOptions::sendMenuAsync(
                player: $player,
                title: "Food Assistance",
                content: "Please select an option",
                buttons: [
                    new EntityNameMenuOptions($player, $entities),
                ],
                neverRejects: true,
                throwExceptionInCaller: false
            );
        } catch (FormValidationException) {
            // The form was cancelled or failed
        }
    });
}
```

### EntityNameMenuOptions.php

```php
<?php

namespace daisukedaisuke\test;

use DaisukeDaisuke\AwaitFormOptions\MenuOptions;
use pocketmine\player\Player;
use cosmicpe\awaitform\Button;
use pocketmine\entity\Entity;
use cosmicpe\awaitform\AwaitFormException;

class EntityNameMenuOptions extends MenuOptions {
	public function __construct(private Player $player, private array $entities) {}

	public function chooseEntity(): \Generator {
		try {
			$buttons = [];

			foreach ($this->entities as $entity) {
				// Display name, attach Entity instance
				$buttons[] = [Button::simple($entity->getName()), $entity];
			}

			/** @var Entity $selected */
			$selected = yield from $this->request($buttons);

			$this->player->sendMessage("You chose: " . $selected->getName());
			return $selected;
		} catch (AwaitFormException) {
			// Closed
		}
	}

	public function getOptions(): array {
		return [$this->chooseEntity()];
	}
}
```

---

## Generator Return Values Are Captured
Each generator that you define in your FormOptions or MenuOptions class can return a value using the return statement. When the form is submitted, all return values from each generator are automatically collected into an array and returned from AwaitFormOptions::sendFormAsync() or sendMenuAsync().

This allows you to treat each form step as a small function that produces a result, just like any other callable.

## Menu Example
Here, the selected button id is returned directly from the generator:

> [!NOTE]
> Please Note that if the form fails, any return values from the child generators will be ignored and null will be returned


```php
public function onUse(PlayerItemUseEvent $event): void{
    $player = $event->getPlayer();
    if(!$player->isSneaking()){
        return;
    }
    Await::f2c(function() use ($player) {
        try {
            $selected = yield from AwaitFormOptions::sendMenuAsync(
                player: $player,
                title: "Food Assistance",
                content: "Please select an option",
                buttons: [
                    new SimpleButton("test1", 0),
                    new SimpleButton("test2", 2),
                ],
                neverRejects: true,
                throwExceptionInCaller: false
            );
            var_dump($selected);
        } catch (FormValidationException) {
            // The form was cancelled or failed
        }
    });
}
```

### SimpleButton

```php
<?php

namespace daisukedaisuke\test;

use DaisukeDaisuke\AwaitFormOptions\MenuOptions;
use cosmicpe\awaitform\Button;
use cosmicpe\awaitform\AwaitFormException;

class SimpleButton extends MenuOptions{
	public function __construct(private string $name, private int $id){
	}

	public function choose(int $offset) : \Generator{
		try{
			yield from $this->request(
				[Button::simple($this->name), 0]
			);
			return $this->id + $offset;
		}catch(AwaitFormException){
			// Closed
		}
	}

	public function getOptions() : array{
		return [
			$this->choose(0),
			$this->choose(1),
		];
	}
}
```

### result
Any of the following
```
int(0)
int(1)
int(2)
int(3)
NULL
```


---

## Form Example
Forms can retrieve the return value of a generator in the same way, note that in this case it maps to the keys of the option array.

> [!NOTE]
> Note that when `$neverRejects` is true, child generator processing is forcefully terminated, so an empty array is returned if an error occurs in the form  
> sendFormAsync will collect all generator return values even if the form fails as long as neverRejects is false. Note that this is different behavior from menu.  

```php
public function onUse(PlayerItemUseEvent $event): void{
    $player = $event->getPlayer();
    if(!$player->isSneaking()){
        return;
    }
    Await::f2c(function() use ($player) {
        try {
            $selected = yield from AwaitFormOptions::sendFormAsync(
                player: $player,
                title: "test",
                options: [
                    "input1" => new SimpleInput("test1", "test", "test", 0),
                    "input2" => new SimpleInput("test2", "test2", "test2", 0),
                ],
                neverRejects: true,
                throwExceptionInCaller: false
            );
            var_dump($selected);
        } catch (FormValidationException) {
            // The form was cancelled or failed
        }
    });
}
```

### SimpleInput.php

```php
<?php

namespace daisukedaisuke\test;

use DaisukeDaisuke\AwaitFormOptions\FormOptions;
use cosmicpe\awaitform\FormControl;

class SimpleInput extends FormOptions{
	public function __construct(private string $text, private string $default, private string $placeholder, private int $id){
	}

	public function input(int $offset) : \Generator{
		$output = yield from $this->request([FormControl::input($this->text, $this->default, $this->placeholder), $this->id + $offset]);
		return $output[array_key_first($output)];
	}

	public function getOptions() : array{
		return [
			$this->input(0),
			$this->input(1),
		];
	}
}

```

### result

```
array(2) {
  ["input1"]=>
  array(2) {
    [0]=>
    string(4) "test"
    [1]=>
    string(4) "test"
  }
  ["input2"]=>
  array(2) {
    [0]=>
    string(5) "test2"
    [1]=>
    string(5) "test2"
  }
}
```

---

# Example

### 🐲 MobKillerOptions (Entity Interaction via Menu)
AwaitFormOptions can be used for more than just player configuration, it also allows you to handle dynamic entities such as mobs or NPCs using menu interactions.
Here is a concrete example that lets a player select entities in their current world and kill them via a menu.

![Image](https://github.com/user-attachments/assets/a7bc338d-2851-450a-8827-5bb3392d5137)

```php
public function onUse(PlayerItemUseEvent $event): void{
    $player = $event->getPlayer();
    $world = $player->getWorld();

    if(!$player->isSneaking()){
        return;
    }

    $forms = [];
    foreach($world->getEntities() as $entity){
        if($entity === $player || !$entity instanceof Living){
            continue;
        }
        $forms[] = new MobKillerForm($entity);
    }

    Await::f2c(function() use ($player, $forms) : \Generator{
        yield from AwaitFormOptions::sendMenuAsync(
            player: $player,
            title: "Mob Terminator",
            content: "Choose a mob to eliminate:",
            buttons: $forms,
            neverRejects: true,
            throwExceptionInCaller: false
        );
    });
}
```

### 🧪 Option Class Example: MobKillerForm

```php
<?php

namespace daisukedaisuke\test;

use DaisukeDaisuke\AwaitFormOptions\MenuOptions;
use pocketmine\entity\Entity;
use cosmicpe\awaitform\Button;

class MobKillerForm extends MenuOptions{

	public function __construct(private readonly Entity $entity){
	}

	public function KillerForm() : \Generator{
		yield from $this->request([
			[Button::simple($this->entity->getName() . " (" . $this->entity->getId() . ")"), "a"],
		]);
		$this->entity->kill();
	}

	public function getOptions() : array{
		return [
			$this->KillerForm(),
		];
	}
}

```

---

## Non-Cancellable Form (Forced Confirmation)
Sometimes, you want to prevent players from skipping or cancelling a form unless they acknowledge a specific phrase or condition — such as typing "yes".
With AwaitFormOptions, this can be done cleanly by combining input validation and throwExceptionInCaller: true.

### Usage

![Image](https://github.com/user-attachments/assets/8e735c4f-c674-4e4e-8cde-79cbb8ab378f)

```php
	public function onUse(PlayerItemUseEvent $event) : void{
		$player = $event->getPlayer();
		if(!$player->isSneaking()){
			return;
		}
		Await::f2c(function() use ($player){
			while(true){
				try{
					$result = yield from AwaitFormOptions::sendFormAsync(
						player: $player,
						title: "Confirmation",
						options: ["output" => new ConfirmInputForm()],
						neverRejects: true,
						throwExceptionInCaller: true
					);
					//generator returns
					$typed = $result["output"][0];
					if(strtolower(trim($typed)) === "yes"){
						$player->sendToastNotification("Confirmed", "Thanks for typing!");
						break;
					}

				}catch(AwaitFormException $exception){
					if($exception->getCode() !== AwaitFormException::ERR_PLAYER_REJECTED){
						break;
					}
				}
				$player->sendToastNotification("You must type 'yes'.", "please Type 'Yes'");
			}
		});
	}
```

### 🧪 Option Class: ConfirmInputForm

```php
<?php

namespace daisukedaisuke\test;

use DaisukeDaisuke\AwaitFormOptions\FormOptions;
use cosmicpe\awaitform\FormControl;

class ConfirmInputForm extends FormOptions{
	public function confirmOnce(): \Generator {
		[$input] = yield from $this->request([
			FormControl::input("Type 'yes' to confirm", "yes", ""),
		]);
		return $input;
	}

	public function getOptions(): array {
		return [$this->confirmOnce()];
	}
}
```

---

## 🍖 HP-Dependent Form Options (Dynamic Option Filtering)
You can conditionally include different form options by selecting which yield generators are returned from getOptions(), this is a key strength of AwaitFormOptions over flat form construction.

![Image](https://github.com/user-attachments/assets/d3d51ed1-4b67-4530-8b9b-f95eba980cdb)

### HpBasedFoodOptions.php

```php
<?php

namespace daisukedaisuke\test;

use pocketmine\player\Player;
use pocketmine\item\VanillaItems;
use cosmicpe\awaitform\FormControl;
use cosmicpe\awaitform\Button;
use DaisukeDaisuke\AwaitFormOptions\MenuOptions;

class HpBasedFoodOptions extends MenuOptions{

	public function __construct(private readonly Player $player){
	}

	public function giveRawFish() : \Generator{
		yield from $this->request([
			Button::simple("§2You are full of strength! Enjoy this raw fish.§r"),
		]);
		$this->player->getInventory()->addItem(VanillaItems::RAW_FISH()->setCount(1));
		$this->player->sendToastNotification("Food Given", "Raw Fish");
	}

	public function giveCookedFish() : \Generator{
		yield from $this->request([
			Button::simple("§6You're moderately hurt. Take this cooked fish.§r"),
		]);
		$this->player->getInventory()->addItem(VanillaItems::COOKED_FISH()->setCount(1));
		$this->player->sendToastNotification("Food Given", "Cooked Fish");
	}

	public function giveSteak() : \Generator{
		yield from $this->request([
			Button::simple("§4You're starving! Here's a juicy steak.§r"),
		]);
		$this->player->getInventory()->addItem(VanillaItems::STEAK()->setCount(1));
		$this->player->sendToastNotification("Food Given", "Steak");
	}

	public function getOptions() : array{
		$hp = $this->player->getHealth();

		$result = [];
		if($hp <= 20){
			$result[] = $this->giveRawFish();
		}
		if($hp <= 10){
			$result[] = $this->giveCookedFish();
		}
		if($hp <= 5){
			$result[] = $this->giveSteak();
		}
		return $result;
	}
}
```

### Usage

```php
public function onUse(PlayerItemUseEvent $event): void{
    $player = $event->getPlayer();
    if(!$player->isSneaking()){
        return;
    }
    Await::f2c(function() use ($player) {
        try {
            yield from AwaitFormOptions::sendMenuAsync(
                player: $player,
                title: "Food Assistance",
                content: "Please select an option",
                buttons: [
                    new HpBasedFoodOptions($player),
                ],
                neverRejects: true,
                throwExceptionInCaller: true
            );
        } catch (FormValidationException|AwaitFormException) {
            // The form was cancelled or failed
        }
    });

}
```


## Summary

✅ Modular  
✅ Async  
✅ Clean separation of form logic  
✅ Handles multiple options, cancellations, and reuse easily

> Build dynamic, plugin-ready forms with AwaitFormOptions.

