<?php

declare(strict_types=1);

use cosmicpe\awaitform\MenuElement;
use cosmicpe\awaitform\FormControl;
use DaisukeDaisuke\AwaitFormOptions\exception\AwaitFormOptionsChildException;
use DaisukeDaisuke\AwaitFormOptions\RequestResponseBridge;
use PHPUnit\Framework\TestCase;
use SOFe\AwaitGenerator\Await;
use SOFe\AwaitGenerator\Channel;

final class RequestResponseBridgeTest extends TestCase{
	public function testGetAllExpectedReturnsRequestsInIssuedRequestIdOrder() : void{
		$bridge = new RequestResponseBridge();
		$requests = null;

		Await::g2c((function() use ($bridge) : Generator{
			yield from $bridge->request([FormControl::input("name")]);
		})());

		Await::g2c((function() use ($bridge) : Generator{
			yield from $bridge->request([MenuElement::button("open")]);
		})());

		Await::f2c(function() use ($bridge, &$requests) : Generator{
			$requests = yield from $bridge->getAllExpected();
		});

		self::assertSame([0, 1], array_keys($requests));
		self::assertInstanceOf(FormControl::class, $requests[0][0]);
		self::assertInstanceOf(MenuElement::class, $requests[1][0]);

		$bridge->abortAll();
	}

	public function testMenuRaceStoresReturnBySolvedRequestIdAfterScheduledDelay() : void{
		$bridge = new RequestResponseBridge();
		$gate = new Channel();

		$late = (function() use ($bridge, $gate) : Generator{
			$reserve = $bridge->schedule();
			yield from $gate->receive();
			$selected = yield from $bridge->request([MenuElement::button("late")], $reserve);
			return "late:" . $selected;
		})();

		$fast = (function() use ($bridge) : Generator{
			try{
				$selected = yield from $bridge->request([MenuElement::button("fast")]);
				return "fast:" . $selected;
			}catch(AwaitFormOptionsChildException){
				return "fast-aborted";
			}
		})();

		$bridge->race(0, [$late, $fast]);

		$requests = null;
		Await::f2c(function() use ($bridge, &$requests) : Generator{
			$requests = yield from $bridge->getAllExpected();
		});

		$gate->sendWithoutWait(null);

		self::assertSame([0, 1], array_keys($requests));

		$bridge->solve(1, "selected");

		self::assertSame([1 => "late:selected"], $bridge->getReturns());
	}

	public function testMenuRaceStoresReturnAfterSelectedGeneratorFinalizes() : void{
		$bridge = new RequestResponseBridge();

		$selected = (function() use ($bridge) : Generator{
			yield from $bridge->request([MenuElement::button("selected")]);
			yield from $bridge->finalize();
			return "selected";
		})();

		$loser = (function() use ($bridge) : Generator{
			try{
				yield from $bridge->request([MenuElement::button("loser")]);
				return "loser";
			}catch(AwaitFormOptionsChildException){
				return "loser-aborted";
			}
		})();

		$bridge->race(0, [$selected, $loser]);

		$requests = null;
		Await::f2c(function() use ($bridge, &$requests) : Generator{
			$requests = yield from $bridge->getAllExpected();
		});

		self::assertSame([0, 1], array_keys($requests));

		$bridge->solve(0, "selected");

		self::assertSame([], $bridge->getReturns());

		$bridge->tryFinalize();

		self::assertSame([0 => "selected"], $bridge->getReturns());
	}

	public function testOneStoresGeneratorReturnUnderOwnerKey() : void{
		$bridge = new RequestResponseBridge();

		$bridge->one(7, "child", self::resolved("done"));

		self::assertSame([7 => ["child" => "done"]], $bridge->getReturns());
	}

	public function testAllStoresGeneratorReturnsWithProvidedKeys() : void{
		$bridge = new RequestResponseBridge();

		$bridge->all(3, "owner", [
			self::resolved("alpha"),
			self::resolved("beta"),
		], ["first", "second"]);

		self::assertSame([
			3 => [
				"owner" => [
					"first" => "alpha",
					"second" => "beta",
				],
			],
		], $bridge->getReturns());
	}

	public function testAllWithEmptyGeneratorListStoresEmptyOwnerResult() : void{
		$bridge = new RequestResponseBridge();

		$bridge->all(4, "empty", [], []);

		self::assertSame([4 => ["empty" => []]], $bridge->getReturns());
	}

	public function testFinalizeReleasesHigherPriorityWaitersFirst() : void{
		$bridge = new RequestResponseBridge();
		$order = [];

		Await::g2c((function() use ($bridge, &$order) : Generator{
			yield from $bridge->finalize(0);
			$order[] = "low";
		})());

		Await::g2c((function() use ($bridge, &$order) : Generator{
			yield from $bridge->finalize(10);
			$order[] = "high";
		})());

		$bridge->tryFinalize();

		self::assertSame(["high", "low"], $order);
	}

	public function testRejectsAllPropagatesProvidedChildExceptionToWaitingRequests() : void{
		$bridge = new RequestResponseBridge();
		$caughtCode = null;
		$returned = null;

		Await::g2c((function() use ($bridge, &$caughtCode) : Generator{
			try{
				yield from $bridge->request([FormControl::input("name")]);
			}catch(AwaitFormOptionsChildException $exception){
				$caughtCode = $exception->getCode();
				return "caught";
			}
			return "missed";
		})(), static function(string $result) use (&$returned) : void{
			$returned = $result;
		});

		$bridge->rejectsAll(new AwaitFormOptionsChildException("", 12345));

		self::assertSame(12345, $caughtCode);
		self::assertSame("caught", $returned);
	}

	public function testAbortAllUsesCoroutineAbortedCode() : void{
		$bridge = new RequestResponseBridge();
		$caughtCode = null;

		Await::g2c((function() use ($bridge, &$caughtCode) : Generator{
			try{
				yield from $bridge->request([FormControl::input("name")]);
			}catch(AwaitFormOptionsChildException $exception){
				$caughtCode = $exception->getCode();
			}
		})());

		$bridge->abortAll();

		self::assertSame(AwaitFormOptionsChildException::ERR_COROUTINE_ABORTED, $caughtCode);
	}

	public function testSolveUnknownRequestIdThrowsLogicException() : void{
		$bridge = new RequestResponseBridge();

		$this->expectException(LogicException::class);

		$bridge->solve(999, "value");
	}

	private static function resolved(mixed $value) : Generator{
		return yield from Await::promise(static function(\Closure $resolve) use ($value) : void{
			$resolve($value);
		});
	}
}
