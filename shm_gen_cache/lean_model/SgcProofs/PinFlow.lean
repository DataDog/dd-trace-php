/-!
# RB5: return-path pin balance

This is a finite control-flow abstraction of `reserve()`, `store()`,
`insert()` and `lookup()`. A `StorePath` describes a terminating execution;
the recursive constructor represents any number of reservation or
`FullRejected` retries. Every source path is one of:

* an exit before W2 (`store.rs:269-271`, input validation, or a lookup miss);
* a reservation that succeeds after W2, followed by `store()`'s W7 at
  `store.rs:175` and possibly `rotate()`;
* an exit from the reserve attempt after W2, followed by W7 at
  `store.rs:352` and either return or rotation and retry.

The `FullRejected` drop is the second case with no rotation. `lookup()`
only pins through `store()` for promotion. This proof checks the path
composition; the mapping from Rust branches to constructors is a code fact.
-/

namespace Sgc
namespace PinFlow

inductive Action where
  | w2 | w7 | rotate | releaseClaim | returnCall
  deriving DecidableEq

/-- A transition is undefined when a pin is leaked or a rotation, release
or return is attempted while pinned. -/
def step (pinned : Bool) : Action → Option Bool
  | .w2 => if pinned then none else some true
  | .w7 => if pinned then some false else none
  | .rotate | .releaseClaim | .returnCall => if pinned then none else some false

def run : Bool → List Action → Option Bool
  | pinned, [] => some pinned
  | pinned, a :: rest => (step pinned a).bind fun next => run next rest

theorem run_append (pinned : Bool) (xs ys : List Action) :
    run pinned (xs ++ ys) = (run pinned xs).bind fun next => run next ys := by
  induction xs generalizing pinned with
  | nil => rfl
  | cons a rest ih =>
    simp only [List.cons_append, run]
    cases h : step pinned a with
    | none => simp
    | some next => simpa [h] using ih next

/-- Every terminating path through `store()` or a promotion called by
`lookup()`. `afterPin` includes publication, failed reservation and dropped
promotion exits. `retry` includes the rotation after a failed reservation or
a rejected full table. -/
inductive StorePath where
  | early
  | afterPin (rotateAfter : Bool)
  | retry (rest : StorePath)

def StorePath.trace : StorePath → List Action
  | .early => [.returnCall]
  | .afterPin false => [.w2, .w7, .returnCall]
  | .afterPin true => [.w2, .w7, .rotate, .returnCall]
  | .retry rest => [.w2, .w7, .rotate] ++ rest.trace

/-- **RB5.** Every terminating insert, lookup or promotion path reaches
its return unpinned; each rotation on the path starts unpinned. -/
theorem return_unpinned (p : StorePath) : run false p.trace = some false := by
  induction p with
  | early => rfl
  | afterPin maintenance => cases maintenance <;> rfl
  | retry rest ih => simpa [StorePath.trace, run, step] using ih

def callTraces : List StorePath → List Action
  | [] => []
  | p :: rest => p.trace ++ callTraces rest

/-- Any finite sequence of completed insert and lookup calls preserves the
unpinned state. -/
theorem calls_unpinned (calls : List StorePath) :
    run false (callTraces calls) = some false := by
  induction calls with
  | nil => rfl
  | cons p rest ih =>
    simp only [callTraces, run_append, return_unpinned]
    exact ih

/-- A `rotate()` call after completed operations starts unpinned. -/
theorem rotation_entry_unpinned (calls : List StorePath) :
    run false (callTraces calls ++ [.rotate]) = some false := by
  rw [run_append, calls_unpinned]
  rfl

/-- `release_claim()` may be entered after any completed call path. -/
theorem release_entry_unpinned (p : StorePath) :
    run false (p.trace ++ [.releaseClaim]) = some false := by
  induction p with
  | early => rfl
  | afterPin maintenance => cases maintenance <;> rfl
  | retry rest ih => simpa [StorePath.trace, run, step] using ih

theorem release_after_calls_unpinned (calls : List StorePath) :
    run false (callTraces calls ++ [.releaseClaim]) = some false := by
  rw [run_append, calls_unpinned]
  rfl

end PinFlow
end Sgc
