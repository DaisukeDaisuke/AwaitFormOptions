# AwaitFormOptions

## Overview

An option-driven form handler framework built on AwaitForm for `pmmp` plugins.  
Designed to modularize complex user interactions and support clean, reusable, async code.  

## Requirements

- [Cosmoverse/AwaitForm](https://github.com/Cosmoverse/AwaitForm)
- [SOF3/await-generator](https://github.com/SOF3/await-generator)

---

> [!IMPORTANT]
> If you want to use AwaitFormOptions, put this somewhere in your plugin:
> ```php
> if(!AwaitForm::isRegistered()){
>     AwaitForm::register($this); //$this must extend pluginbase
> }
> ```
> ```php
> use cosmicpe\awaitform\AwaitForm;
> ```

---

## ⚠️ Performance Notice
Please be advised that AwaitFormOptions is inherently demanding, both in terms of function call overhead and PHP's garbage collection behavior.  
Due to its layered design and dynamic generator usage, it may not be suitable for performance-critical paths or tight loops   
If maximum performance is your goal, we strongly recommend using AwaitForm directly, rather than through this abstraction layer.    
AwaitFormOptions is designed to simplify complex form workflows and improve developer ergonomics—not to optimize execution speed.   

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

Split your form logic into reusable option classes:  

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

> [!TIP]
> `yield from $this->request()` must be called only once per generator.  
> Calling it a second time in the same generator will throw a `AwaitFormOptionsInvalidValueException`.  
> If you need to re-show a form, return from the current generator and call it again from the parent context.
>
> Additionally, the following exceptions may be thrown from `request()`:
> - `AwaitFormOptionsInvalidValueException`: When `request()` is called more than once in the same generator.
> - `AwaitFormOptionsInvalidValueException`: When the provided form/button array is invalid.
> - `AwaitFormException`: If the player rejects the form, input is invalid, or the player logs out.


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
	
	public function userDispose() : void{
	    unset($this->player);
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

> [!TIP]
> ⚙️ **Exception Behavior with `neverRejects` and `throwExceptionInCaller`**  
>  
> - If `neverRejects` is set to `false`, each child generator will **attempt to throw an `AwaitFormException`** when the form is closed or rejected.    
>     If the exception is not caught inside the generator, it will **crash the server** with a long stack trace.   
>     Always make sure to catch `AwaitFormException` when using this setting.   
>
> - If `neverRejects` is set to `true`, `AwaitFormOptions` will **silently terminate the child generator** when a form is closed or rejected.    
>     The affected generator coroutine will be forcibly interrupted without throwing, and no return value will be collected.  
>     You do not need to catch exceptions in this case, but be aware that the logic inside the generator will not complete.  
>  
>- If `throwExceptionInCaller` is `true`, `AwaitFormOptions` will **re-throw `AwaitFormException` in the parent `f2c()` coroutine** after applying `neverRejects` behavior.   
>    ⚠️ In this mode, generator return values will **not be available if the form is rejected or closed**,  
>    because the coroutine is terminated by the thrown `AwaitFormException`.  
>    If the form completes successfully, return values will still be collected as normal.
>    
> - If `throwExceptionInCaller` is `false`, `AwaitFormException` will not be propagated to the parent coroutine.    
>     However, **`FormValidationException` may still occur** if the player submits invalid input.  
>  
> 🔸 `FormValidationException` is used to signal **player-caused input validation errors**, such as leaving a required field blank.    
>    It does **not** include form construction errors.  
>
> 🔸 If the form configuration itself is invalid (e.g., malformed option arrays, duplicate keys, missing inputs),    
>    an `AwaitFormOptionsInvalidValueException` will be thrown.    
>    This usually indicates a **programming bug** and should **not be caught in production logic**.  
>  
> ✅ To suppress all exceptions:    
> &nbsp;&nbsp;&nbsp;&nbsp;Set `neverRejects: true`, `throwExceptionInCaller: false`  
>  
> ✅ To catch rejections **only** in the parent coroutine:    
> &nbsp;&nbsp;&nbsp;&nbsp;Set `neverRejects: true`, `throwExceptionInCaller: true`  


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

sendForm and sendMenu may also be called standalone. In that case:
- No exception is thrown, even if the user cancels the form.
- The generator's return value is discarded.
- The functions always return void (null).

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

> [!TIP]
> When the form is completed, any button generators that were not selected will equally receive a `SOFe\AwaitGenerator\RaceLostException` (since 1.1.0)  
> RaceLostException is only raised when the form is completed, and if the form is rejected you will receive an AwaitFormException depending on neverRejects  

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
	
	public function userDispose() : void{
	    unset($this->player, $this->options);
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
But what if you want to associate a more complex object, like a Player, Entity, or CustomData // with each button?

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
	
	public function userDispose() : void{
	    unset($this->player, $this->entities);
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
	public function userDispose() : void{
	    unset($this->name, $this->id);
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
> ~~sendFormAsync will collect all generator return values even if the form fails as long as neverRejects is false. Note that this is different behavior from menu.~~  
> Due to the memory leak prevention measures in 2.0.1, it is no longer collected or null is returned.

> [!TIP]
> In `sendFormAsync()`, the return value preserves:  
>  
> - The keys from the top-level `options` array, and  
> - The keys from each `FormOptions::getOptions()` result.  
>  
> This allows both levels of return values to be mapped clearly.    
> For example:  
>  
> ```php
> public function getOptions(): array {
>     return ["test" => $this->confirmOnce()];
> }
> ```
>  
> And if you pass `["output" => new ConfirmInputForm()]` into `sendFormAsync()`,  
> and the generator returned `"yes"` from the `"test"` key,  
> the result will be:  
>  
> ```php
> [
>     "output" => [
>         "test" => "yes" // (← this is the value returned from the generator)
>     ]
> ]
> ```
>
>  
> However, in `sendMenuAsync()`, only the return value of the **selected generator** is returned.  
> You will either get:  
>  
> - The return value from the selected `MenuOptions` generator, or  
> - `null` if the form was cancelled or no selection was made.  
>  
> Thus:  
>
> ✅ `getOptions()` keys → respected in `sendFormAsync()`   
> ❌ `getOptions()` keys → ignored in `sendMenuAsync()` (since only one is returned)  
  

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
	public function userDispose() : void{
	    unset($this->text, $this->default, $this->placeholder, $this->id);
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
	
	public function userDispose() : void{
	    unset($this->entity);
	}
}

```

---

## Non-Cancellable Form (Forced Confirmation)
Sometimes, you want to prevent players from skipping or cancelling a form unless they acknowledge a specific phrase or condition // such as typing "yes".
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
	
	public function userDispose() : void{
	    
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
	
	public function userDispose() : void{
	    unset($this->player);
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

### Form Available elements

```php
FormControl::divider() // Adds a horizontal divider to visually separate form sections.
FormControl::dropdown(string $label, array $options, ?string $default = null) // Select from a list of options, returns the selected value.
FormControl::dropdownIndex(string $label, array $options, int $default = 0) // Select from a list of options, returns the selected index.
FormControl::dropdownMap(string $label, array $options, array $mapping, mixed $default = null) // Select from a list of options, returns a mapped value.
FormControl::header(string $label) // Adds a bold header text to highlight sections.
FormControl::input(string $label, string $placeholder = "", string $default = "") // Text input field. Returns user input as a string.
FormControl::label(string $label) // Static text label, for descriptions or instructions.
FormControl::slider(string $label, float $min, float $max, float $step = 0.0, float $default = 0.0) // A numeric slider. Returns a float value.
FormControl::stepSlider(string $label, array $steps, ?string $default = null) // A discrete slider with string options. Returns a selected step.
FormControl::toggle(string $label, bool $default = false) // A boolean toggle (checkbox). Returns true/false.
```

### Menu Available elements
```php
Button::simple(string $text) // One user selectable button with text
```

## ⚠️ Notes on `getOptions()`

The `getOptions()` method must return an **array of `\Generator` instances**. Each generator represents a step in the asynchronous form process. Misuse of this method may result in exceptions or undefined behavior.

---

### ❌ Mistake 1: Returning non-generators

```php
// ❌ This will throw an exception because the array is not a list of generators
public function getOptions(): array {
    return ["not a generator"];
}
```

✅ **Correct:** Ensure each item in the array is a generator using `yield`.  

```php
public function getOptions(): array {
    return [
        $this->confirmSomething()
    ];
}
```

---

### ❌ Mistake 2: Using `yield from` inside `getOptions()`  

```php
// ❌ Syntax error: you cannot use `yield` or `yield from` in a non-generator method
public function getOptions(): array {
    $value = yield from $this->step(); // Invalid
    return [];
}
```
 
✅ **Correct:** Move the logic into a generator method and return it from `getOptions()`

```php
public function flow(): \Generator {
    $value = yield from $this->step();
    // ...
}

public function getOptions(): array {
    return [$this->flow()];
}
```

---

### ❌ Mistake 3: Returning objects

```php
// ❌ Unrelated objects are not supported
public function getOptions(): array {
    return [
        new \stdClass($this->player),
    ];
}
```

✅ **Correct:** Returns a generator or form option, or a menu object (form and menu options cannot be mixed)

---

### ✅ Returning an empty array when no steps are needed  

```php
public function getOptions(): array {
    return []; // No form step
}
```

---

## ⚠️ What to be aware of if you don't call request first in your generator

If your generator does **not** start with `yield from $this->request(...)`, you **must** call `$this->schedule();` to inform the AwaitFormOptions system that a `request()` is coming later  
If you forget this, the system may either **ignore the `request()` entirely**, or worse, **cause a memory leak** due to improper bridge state retention　　

#### ❌ Incorrect Example

```php
public function flow(): \Generator {
    if ($someCondition) {
        yield from $this->stepA(); // Not a request, no schedule
    }
    yield from $this->request([...]); // This may be ignored or cause a leak
}
```

✅ Correct: If you think you might call await several times in advance, use `$this->schedule();` beforehand

```php
public function flow(): \Generator {
    $this->schedule(); // Declare intention to request later
    if ($input === "A") {
        yield from $this->stepA();
    }
    $input = yield from $this->request([...]);
}
```


## ❓ How can I preserve the state of a form?
Currently, this is a known limitation and an ongoing area of exploration. In the core-sod architecture, all input states are managed and validated through a flat, object-oriented structure. Each form field's value is stored in a persistent object that represents the current session.  
🧩 In principle, such an object should be sufficient to retain or finalize form state across multiple steps or deferred interactions.  
However, please note that we do not plan to fully solve this problem in the near future. The current approach is intended to be minimal, avoiding deep form serialization or complex state tracking, in order to keep the system maintainable and predictable.  
While improvements may be considered in the future, this area is not a development priority at the moment.  
  

## ❓How about PMServerUI?
PMServerUI (https://github.com/DavyCraft648/PMServerUI) is a great library for beginners who want to create simple and clean UI menus with minimal effort. Its straightforward API makes it easy to build forms quickly.  
However, AwaitFormOptions provides a more powerful and flexible system that supports deeply nested options, persistent context between form steps, and asynchronous flow control.  
While it is technically possible to recreate something like PMServerUI using AwaitFormOptions, the reverse is not true. PMServerUI cannot handle advanced patterns such as dynamic generator-based form logic, branching flows, or contextual state within multi-step UIs.  

# 1.1.0 Futures
## Nested Options

What should I do if I'm using object-oriented design, and I want to add elements but can't access the parent class?
Since version 1.1.0, this is now easy to achieve using nested options!

> [!TIP]  
> Nesting is allowed only one level deep.  
> Nested options are supported in menus as well  
> Also, the form respects all nested option keys, allowing you to map them accurately  

### main

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
					var_dump($result);
					//generator returns
					$typed = $result["output"]["confirm"];
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

### ConfirmInputForm
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
		return [
			"entity" => new SimpleInput("nested!", "nested", "nested", 0),
			"confirm" => $this->confirmOnce(),
		];
	}
	
	public function userDispose() : void{
	    
	}
}
```

### output

```php
array(1) {
  ["output"]=>
  array(2) {
    ["entity"]=>
    array(2) {
      ["a"]=>
      string(6) "a"
      [0]=>
      string(6) "nested"
    }
    ["confirm"]=>
    string(3) "yes"
  }
}
```

## finalize()

How can you collect information from nested forms **after all forms have completed**?  
As of version 1.1.0, you can use `yield from $this->finalize(int priority);`!  
This allows your code block to pause until all other forms are either completed, finalized, or in an awaiting state—after which execution resumes!  
  
> [!TIP]  
> Lower numbers mean **lower priority**, and higher numbers mean **higher priority**   
> Although finalization is supported by menu as well, there is no point in calling it    
> However, in the form menu, if the option does nothing, you can also call this as a fake function

### ConfirmInputForm
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
		yield from $this->finalize(10000);//Awaiting other generators with priority 10000
		return $input;
	}

	public function getOptions(): array {
		return [
			"entity" => new SimpleInput("nested!", "nested", "nested", 0),
			"confirm" => $this->confirmOnce(),
		];
	}
	
	public function userDispose() : void{
	    
	}
}
```

## RaceLostException
Due to the processing improvements in 1.1.0, all awaitformoption menus now receive RaceLostException equally

```php
<?php

namespace daisukedaisuke\test;

use cosmicpe\awaitform\Button;
use DaisukeDaisuke\AwaitFormOptions\MenuOptions;
use SOFe\AwaitGenerator\RaceLostException;

class HpBasedFoodOptions extends MenuOptions{

	public function giveRawFish() : \Generator{
		try{
			yield from $this->request([Button::simple("a"), 0]);
		}catch(RaceLostException){
			var_dump("!!");
		}
	}

	public function giveRawFish1() : \Generator{
		try{
			yield from $this->request([Button::simple("a"), 0]);
		}catch(RaceLostException){
			var_dump("??");
		}
	}

	public function getOptions() : array{
		return [
			$this->giveRawFish(),
			$this->giveRawFish1(),
		];
	}
	
	public function userDispose() : void{
	    
	}
}
```

## 1.3.0 Future
### schedule
Want to use some await before sending a request? Now you can with schedule() in 1.3.0!  

```php
<?php

namespace daisukedaisuke\test;

use cosmicpe\awaitform\Button;
use DaisukeDaisuke\AwaitFormOptions\MenuOptions;
use SOFe\AwaitGenerator\RaceLostException;

class HpBasedFoodOptions extends MenuOptions{
	public function giveRawFish1() : \Generator{
	    $this->schedule(); // This ensures that the awaitformoptions coroutine is temporarily suspended
	    //A few awaits
		try{
			yield from $this->request([Button::simple("a"), 0]); // Here, the suspension is lifted
		}catch(RaceLostException){
			var_dump("??");
		}
	}

	public function getOptions() : array{
		return [
			$this->giveRawFish1(),
		];
	}
	
	public function userDispose() : void{
	    
	}
}
```

## 2.0.0 Future
### A new abstract has been added: userDispose
Each option must implement userDispose to handle garbage collection  
```php
public function userDispose() : void{
	//As of 2.0.0, options must implement userDispose
}
```

### Memory leak fixed
Fixed gc leak (memory leak) when form is abandoned　　