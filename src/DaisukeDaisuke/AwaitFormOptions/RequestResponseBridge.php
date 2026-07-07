<?php

declare(strict_types=1);

namespace DaisukeDaisuke\AwaitFormOptions;

use DaisukeDaisuke\AwaitFormOptions\exception\AwaitFormOptionsChildException;
use DaisukeDaisuke\AwaitFormOptions\exception\AwaitFormOptionsException;
use SOFe\AwaitGenerator\Await;
use SOFe\AwaitGenerator\AwaitException;
use SOFe\AwaitGenerator\Channel;
use function array_combine;
use function count;
use function krsort;
use cosmicpe\awaitform\FormControl;
use cosmicpe\awaitform\MenuElement;

class RequestResponseBridge{
	private int $nextId = 0;

	private int $reservesId = 0;

	/** @var array<int, Channel<list<FormControl|MenuElement|array<mixed>>>> Request payload channels keyed by request ID. */
	private array $pendingRequest = [];

	/** @var array<int, \Closure(mixed=): void> Response resolvers keyed by request ID. */
	private array $pendingSend = [];
	/** @var array<int, \Closure(\Throwable): void> Response rejectors keyed by request ID. */
	private array $rejects = [];

	/** @var array<int, mixed> Child generator return values keyed by owner/request ID. */
	private array $returns = [];
	/** @var array<int, list<Channel<null>>> */
	private array $finalizeList = [];
	/** @var array<int, Channel<int>> Future request reservations keyed by reservation ID. */
	private array $reserves = [];
	/** Request ID currently being solved; used to map menu race returns back to the selected request. */
	private ?int $solvingRequestId = null;
	/** Most recent solved request ID; used when a selected menu generator completes after finalize(). */
	private ?int $lastSolvedRequestId = null;
	/** Whether a menu race has already stored the selected generator's return value. */
	private bool $raceResolved = false;

	/**
	 * Registers a request payload and suspends until the parent coroutine provides
	 * the response for this request ID.
	 *
	 * If $reserved is provided, this request fulfills the matching schedule()
	 * reservation and unblocks getAllExpected().
	 *
	 * @param list<array<mixed>>|list<FormControl|MenuElement> $value    要求する値
	 * @param ?int  $reserved 予約id
	 * @return \Generator<mixed, mixed, mixed, mixed> 応答値
	 *
	 * @throws AwaitFormOptionsChildException
	 */
	public function request(mixed $value, ?int $reserved = null) : \Generator{
		$id = $this->nextId++;

		$this->pendingRequest[$id] = new Channel();
		$this->pendingRequest[$id]->sendWithoutWait($value);

		return yield from Await::promise(function(\Closure $resolve, \Closure $reject) use ($reserved, $id){
			$this->pendingSend[$id] = $resolve;
			$this->rejects[$id] = $reject;

			if($reserved !== null && isset($this->reserves[$reserved])){
				$this->reserves[$reserved]->sendWithoutWait($id);
				unset($this->reserves[$reserved]);
			}
		});
	}

	/**
	 * Reserves one future request() call and returns its reservation ID.
	 *
	 * The reservation is consumed when request() is later called with the returned
	 * ID. getAllExpected() waits for all outstanding reservations before returning
	 * the collected request payloads.
	 */
	public function schedule() : int{
		$id = $this->reservesId++;
		$this->reserves[$id] = new Channel();
		return $id;
	}

	/**
	 * Waits for all scheduled requests, then returns every registered request
	 * payload keyed by request ID.
	 *
	 * A reservation created by schedule() must eventually be fulfilled by request().
	 * Otherwise this generator intentionally remains suspended because the parent
	 * cannot safely assemble the form/menu with an unknown future request.
	 *
	 * @return \Generator<mixed, mixed, mixed, array<int, list<FormControl|MenuElement|array<mixed>>>>
	 */
	public function getAllExpected() : \Generator{
		$result = [];

		//予約を待つ
		foreach($this->reserves as $reserve){
			yield from $reserve->receive();
		}

		unset($this->reserves);

		foreach($this->pendingRequest as $id => $channel){
			$result[$id] = yield from $channel->receive();
		}

		unset($this->pendingRequest);

		return $result;
	}

	/**
	 * Sends the parent response to a waiting child request.
	 *
	 * During the resolver call, $solvingRequestId is set so a completing menu race
	 * can store the selected generator return value under the actual request ID.
	 * $lastSolvedRequestId remains available after the resolver returns, because a
	 * selected menu generator may suspend at finalize() before it returns.
	 *
	 * @param int   $id    応答先ID
	 * @param mixed $value 応答する値
	 * @throws \LogicException If the request ID is not waiting for a response.
	 */
	public function solve(int $id, mixed $value) : void{
		if(!isset($this->pendingSend[$id])){
			throw new \LogicException("未登録のIDです: $id");
		}
		$resolve = $this->pendingSend[$id];
		unset($this->rejects[$id], $this->pendingSend[$id]);

		$this->solvingRequestId = $id;
		$this->lastSolvedRequestId = $id;
		try{
			$resolve($value);
		}finally{
			$this->solvingRequestId = null;
		}
	}

	/**
	 * Rejects every waiting child request with the given child exception.
	 *
	 * AwaitFormOptionsChildException leaking out of a child generator is treated as
	 * a completed abort and is swallowed by abort(). If a child catches this
	 * exception and throws a different exception, that exception is allowed to
	 * propagate and the loop stops.
	 *
	 * Note: non-child exceptions thrown by child code may propagate to the caller.
	 *
	 * @param AwaitFormOptionsChildException $throwable The exception to pass to each waiting child.
	 */
	public function rejectsAll(AwaitFormOptionsChildException $throwable) : void{
		foreach($this->rejects as $id => $reject){
			$this->abort($id, $throwable);
		}
	}

	/**
	 * Aborts every waiting child request with ERR_COROUTINE_ABORTED.
	 *
	 * AwaitFormOptionsChildException itself is swallowed by abort(). Non-child
	 * exceptions thrown by child code are still allowed to propagate.
	 */
	public function abortAll() : void{
		foreach($this->rejects as $id => $reject){
			$this->abort($id);
		}
	}

	/**
	 * Aborts a single pending request.
	 *
	 * If $throwable is omitted, the child receives AwaitFormOptionsChildException
	 * with ERR_COROUTINE_ABORTED. A leaked child exception is treated as a successful
	 * abort because it means the child coroutine has been released.
	 *
	 * @param int $id The unique identifier of the asynchronous operation to be aborted.
	 * @param AwaitFormOptionsChildException|null $throwable Exception to send to the child.
	 *
	 * @return bool Returns true if a reject handler was found or the child exception leaked; false otherwise.
	 *
	 * Note: non-child exceptions thrown by child code may propagate to the caller.
	 */
	public function abort(int $id, ?AwaitFormOptionsChildException $throwable = null) : bool{
		$throwable ??= new AwaitFormOptionsChildException("", AwaitFormOptionsChildException::ERR_COROUTINE_ABORTED);
		try{
			return $this->reject($id, $throwable);
		}catch(AwaitFormOptionsChildException){
			return true;
		}
	}

	/**
	 * Rejects a waiting request by invoking its Await promise reject handler.
	 *
	 * The purpose of this method is to interrupt the child generator, let it reach
	 * a completed/released state, and avoid retaining coroutine resources forever.
	 * The child may catch the AwaitFormOptionsException passed in $throwable and
	 * finish cleanup normally, or it may intentionally let that exception leak to
	 * the caller. Child code may also throw a different exception while handling
	 * the rejection; in that case reject() propagates that exception and the caller
	 * should treat it as a crash.
	 *
	 * If the reject handler exists and the rejection does not propagate out of the
	 * child generator, this method returns true. If no reject handler exists for
	 * the ID, it returns false.
	 *
	 * Resolver and rejector references are removed regardless of the outcome.
	 *
	 * @param int                      $id        The unique identifier for the request to reject.
	 * @param AwaitFormOptionsException $throwable The throwable used to reject the request.
	 * @return bool Returns true if a rejection handler was found and successfully invoked; otherwise, returns false.
	 *
	 * Note: leaking the provided AwaitFormOptionsException is an intentional
	 * control-flow option for callers that want the rejection to crash upward.
	 */
	public function reject(int $id, AwaitFormOptionsException $throwable) : bool{
		try{
			if(isset($this->rejects[$id])){
				($this->rejects[$id])($throwable);
				return true;
			}
		}catch(AwaitException $exception){
			/**
			 * HACK: Workaround to suppress nested AwaitException wrapping
			 * Normally, SOFe\AwaitGenerator wraps thrown exceptions in AwaitException at every yield-from level.
			 * This leads to deep nesting like AwaitException → AwaitException → OriginalException,
			 * which makes debugging more difficult.
			 *
			 * By catching the outer AwaitException and re-throwing only its $previous,
			 * we intentionally skip one layer of wrapping to improve clarity in error messages.
			 *
			 * ⚠ WARNING: This assumes that the caught AwaitException always has a valid ->getPrevious()
			 * and that ignoring one layer of wrapping does not break Await's internal logic.
			 * Use with caution and test thoroughly if changes are made to AwaitGenerator internals.
			 *
			 * @See Await::reject() for reference.
			 */
			throw $exception->getPrevious() ?? $exception;
		}finally{
			unset($this->rejects[$id], $this->pendingSend[$id]);
		}
		return false;
	}

	/**
	 * Runs multiple child generators and stores all of their return values.
	 *
	 * This is used for nested FormOptions. When $keys is not null, generator
	 * returns are remapped with array_combine($keys, $return). When $keys is null,
	 * the raw return array from Await::all() is stored.
	 *
	 * The stored shape is $returns[$id][$owenr].
	 *
	 * @param int                      $id    Unique identifier for storing the results.
	 * @param int|string                                     $owenr Identifier for the owner of the result.
	 * @param array<int|string, \Generator<mixed, mixed, mixed, mixed>> $array An array of tasks (generators) to be processed asynchronously.
	 * @param list<int|string>|null                          $keys  Optional array of keys for mapping the results.
	 */
	public function all(int $id, int|string $owenr, array $array, ?array $keys = []) : void{
		Await::f2c(function() use ($owenr, $id, $array, $keys){
			$return = yield from Await::All($array);
			if($keys !== null){
				$this->returns[$id][$owenr] = array_combine($keys, $return);
			}else{
				$this->returns[$id][$owenr] = $return;
			}
		});
	}

	/**
	 * Starts menu child generators and records only the selected generator result.
	 *
	 * A menu generator normally completes after solve() resumes its request. The
	 * return value is stored under the solved request ID, not under the generator
	 * array index. Once the first generator result is stored, remaining waiting
	 * generators are aborted. If they catch the abort and return normally, their
	 * return values are ignored.
	 *
	 * @param int                      $id    Retained for call-site compatibility; not used for result mapping.
	 * @param array<int|string, \Generator<mixed, mixed, mixed, mixed>> $array An array of child generators to execute in a race.
	 * @throws \LogicException If a generator completes without being resumed by solve().
	 *
	 * Note: aborting losing generators may propagate non-child exceptions thrown
	 * by child code.
	 *
	 * @see solve Refer to the `solve` method for additional context on related functionality.
	 */
	public function race(int $id, array $array) : void{
		foreach($array as $generator){
			Await::g2c($generator, function($result) : void{
				if($this->raceResolved){
					return;
				}
				$requestId = $this->solvingRequestId ?? $this->lastSolvedRequestId;
				if($requestId === null){
					throw new \LogicException("Menu race completed without a solved request ID");
				}
				$this->raceResolved = true;
				$this->returns[$requestId] = $result;
				$this->abortAll();
			});
		}
	}

	/**
	 * Runs one child generator and stores its return value.
	 *
	 * This is used for direct FormOptions child generators. The stored shape is
	 * $returns[$id][$owenr].
	 *
	 * @param int               $id        Unique identifier for storing the result.
	 * @param int|string        $owenr     The key used to store the generator's return value.
	 * @param \Generator<mixed, mixed, mixed, mixed> $generator The child generator to execute.
	 */
	public function one(int $id, int|string $owenr, \Generator $generator) : void{
		Await::f2c(function() use ($owenr, $id, $generator){
			$return = yield from $generator;
			$this->returns[$id][$owenr] = $return;
		});
	}

	/**
	 * Returns the number of request payloads that have been registered and not yet collected.
	 */
	public function count() : int{
		return count($this->pendingRequest);
	}

	/**
	 * @return array<int, mixed>
	 */
	public function getReturns() : array{
		return $this->returns;
	}

	/**
	 * Registers a finalization request with a specific priority.
	 *
	 * This method schedules finalization within an asynchronous coroutine by
	 * creating a new Channel instance and appending it to the internal finalization list.
	 * The coroutine is then blocked by yielding from the channel's receive method until
	 * a signal is sent to complete the finalization.
	 *
	 * @param int $priority Priority level for the finalization process (default is 0).
	 *                      Higher numbers indicate higher processing priority.
	 *
	 * @return \Generator<mixed, mixed, mixed, void> Yields until the finalization process is completed.
	 */
	public function finalize(int $priority = 0) : \Generator{
		$obj = new Channel();
		$this->finalizeList[$priority][] = $obj;
		yield from $obj->receive();
	}

	/**
	 * Resolves all scheduled finalization reservations and unblocks their associated child generators.
	 *
	 * This method processes all finalization channels in descending order based on their priority
	 * (i.e., channels with higher priority values are processed first). For each scheduled finalization,
	 * it sends a null value using non-blocking send to unblock any awaiting generator.
	 * If no finalization reservations have been scheduled, the method performs no actions.
	 */
	public function tryFinalize() : void{
		krsort($this->finalizeList); // 高い優先度（数値が大きい）順に処理
		foreach($this->finalizeList as $group){
			foreach($group as $item){
				$item->sendWithoutWait(null);
			}
		}
		unset($this->finalizeList);
	}

	/**
	 * Releases all internal bridge state.
	 *
	 * This object is single-use after dispose(); accessing it again is intentionally
	 * invalid because all coroutine coordination arrays have been unset.
	 */
	public function dispose() : void{
		unset($this->pendingRequest, $this->pendingSend, $this->rejects, $this->returns, $this->finalizeList, $this->reserves, $this->reservesId, $this->nextId, $this->solvingRequestId, $this->lastSolvedRequestId, $this->raceResolved);
	}
}
