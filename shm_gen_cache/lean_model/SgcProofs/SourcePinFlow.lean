import SgcProofs.PinFlow

/-!
# RB5: source branch composition

This model expands the Rust call graph instead of classifying calls by
whether they already have balanced traces. Each constructor names a branch
in store.rs, lookup.rs, or rotation.rs. reserve may return *pinned*; store
consumes that success and executes W7 before dispatching PutStatus. Retry
constructors allow any finite number of loop iterations. These return
theorems concern ordinary returns. RB5 also states unconditional entry
conditions for release_claim, so Drop during unwinding is a separate
obligation: it must not be dismissed merely as a nonreturn. The event
bridge and pinned-helper guard analysis address that distinction.

The source correspondence is the control-flow fact F1: these are the only
return/continue edges in the named functions. In particular, reserve has
no fallible operation between W2 and its two successful reservation exits
or the common W7; publish returns PutStatus, not Result. lookup's probes
and readiness recheck make no W2. Rotation may clear another dead slot;
that target's release is modeled separately below.
-/
namespace Sgc.PinFlow

inductive ReservationSource where
  | chunk | bump

inductive Rejection where
  | wrongStamp | sealed | occupancy | insufficientSpace | racedBump

/-- Exact exits and backedges of reserve (store.rs:269-359). A successful
reservation deliberately returns true. Rotation errors and dropped
promotions return false; retry recomputes W1 before a new W2. -/
inductive ReservePath where
  | movedPromotion
  | allocated (source : ReservationSource)
  | dropped (cause : Rejection)
  | rotationError (cause : Rejection)
  | retry (cause : Rejection) (rest : ReservePath)

def ReservePath.pinned : ReservePath → Bool
  | .allocated _ => true
  | .retry _ rest => rest.pinned
  | _ => false

def ReservePath.trace : ReservePath → List Action
  | .movedPromotion => []
  | .allocated _ => [.w2]
  | .dropped _ => [.w2, .w7]
  | .rotationError _ => [.w2, .w7, .rotate]
  | .retry _ rest => [.w2, .w7, .rotate] ++ rest.trace

theorem reserve_result (p : ReservePath) : run false p.trace = some p.pinned := by
  induction p with
  | movedPromotion => rfl
  | allocated source => rfl
  | dropped cause => rfl
  | rotationError cause => rfl
  | retry cause rest ih => simpa [ReservePath.trace, ReservePath.pinned, run, step] using ih

/-- Maintenance after publication either succeeds, suppresses an ordinary
error, or returns Corrupt; all branches have the same pin effect. -/
inductive MaintenanceResult where
  | success | ordinaryError | corrupt

/-- Each PutStatus branch after successful reserve. A failed required
rotation returns its error; success or ConcurrentOperation retries. -/
inductive StoreExecution where
  | noReservation (r : ReservePath) (exit : r.pinned = false)
  | published (r : ReservePath) (reserved : r.pinned = true)
  | full (r : ReservePath) (reserved : r.pinned = true) (result : MaintenanceResult)
  | rejectedPromotion (r : ReservePath) (reserved : r.pinned = true)
  | rejectedRotationError (r : ReservePath) (reserved : r.pinned = true)
  | rejectedRetry (r : ReservePath) (reserved : r.pinned = true) (rest : StoreExecution)

def StoreExecution.trace : StoreExecution → List Action
  | .noReservation r _ => r.trace ++ [.returnCall]
  | .published r _ | .rejectedPromotion r _ => r.trace ++ [.w7, .returnCall]
  | .full r _ _ | .rejectedRotationError r _ => r.trace ++ [.w7, .rotate, .returnCall]
  | .rejectedRetry r _ rest => r.trace ++ [.w7, .rotate] ++ rest.trace

theorem store_returns_unpinned (p : StoreExecution) : run false p.trace = some false := by
  induction p with
  | noReservation r h => simp [StoreExecution.trace, run_append, reserve_result, h, run, step]
  | published r h => simp [StoreExecution.trace, run_append, reserve_result, h, run, step]
  | full r h result => simp [StoreExecution.trace, run_append, reserve_result, h, run, step]
  | rejectedPromotion r h => simp [StoreExecution.trace, run_append, reserve_result, h, run, step]
  | rejectedRotationError r h => simp [StoreExecution.trace, run_append, reserve_result, h, run, step]
  | rejectedRetry r h rest ih =>
    simpa [StoreExecution.trace, run_append, reserve_result, h, run, step] using ih

inductive InsertExecution where
  | keyTooLarge | valueTooLarge
  | store (p : StoreExecution)

def InsertExecution.trace : InsertExecution → List Action
  | .keyTooLarge | .valueTooLarge => [.returnCall]
  | .store p => p.trace

theorem insert_returns_unpinned (p : InsertExecution) : run false p.trace = some false := by
  cases p with
  | keyTooLarge => rfl
  | valueTooLarge => rfl
  | store p => exact store_returns_unpinned p

/-- The initial probe and the optional reprobe have the same three error
branches. Retry starts a new lookup pass without ever pinning. -/
inductive LookupExit where
  | keyTooLarge | overlap | miss | insufficientCapacity | corruptLength | hit

inductive LookupExecution where
  | exit (reason : LookupExit)
  | retry (reprobe : Bool) (rest : LookupExecution)
  | promote (p : StoreExecution)

def LookupExecution.trace : LookupExecution → List Action
  | .exit _ => [.returnCall]
  | .retry _ rest => rest.trace
  | .promote p => p.trace ++ [.returnCall]

theorem lookup_returns_unpinned (p : LookupExecution) : run false p.trace = some false := by
  induction p with
  | exit reason => rfl
  | retry reprobe rest ih => exact ih
  | promote p => simp [LookupExecution.trace, run_append, store_returns_unpinned, run, step]

/-- run would be undefined if rotate or releaseClaim were reached pinned.
This lemma exposes that prefix safety, beyond just the final pin value. -/
theorem checked_entry_unpinned {initial final : Bool} {before after : List Action}
    {entry : Action} (hentry : entry = .rotate ∨ entry = .releaseClaim ∨ entry = .returnCall)
    (h : run initial (before ++ entry :: after) = some final) :
    ∃ state, run initial before = some state ∧ state = false := by
  rw [run_append] at h
  cases hb : run initial before with
  | none => simp [hb] at h
  | some state =>
    refine ⟨state, rfl, ?_⟩
    cases state with
    | false => rfl
    | true => rcases hentry with rfl | rfl | rfl <;> simp [hb, run, step] at h

/-- Lock drop follows completed calls. Its release_claim entry is checked
by the same run semantics as every rotate inside reserve and store. -/
theorem release_after_source_call (p : StoreExecution) :
    run false (p.trace ++ [.releaseClaim]) = some false := by
  simp [run_append, store_returns_unpinned, run, step]

/-- Reaper target state is distinct from the caller's own slot. Only
Steal::Stolen reaches release_claim in reap_pinner/reap_owner. steal runs
P5 before P6 (store UNPINNED); failed P5 returns Corrupt without clearing
or releasing.
All other steal results and backend errors skip target release. -/
inductive ReapResult where
  | free | other | lost | live | backendError | corruptBeforeClear | stolen
  deriving DecidableEq

def reapTarget (initial : Bool) (r : ReapResult) : Bool × Bool :=
  match r with
  | .stolen => (false, true)
  | _ => (initial, false)

theorem reaper_release_unpinned (initial : Bool) (r : ReapResult)
    (hreleases : (reapTarget initial r).2 = true) : (reapTarget initial r).1 = false := by
  cases r <;> simp_all [reapTarget]

/-- P5 and P6 are separate source actions. The successful reaper registers,
clears the dead pin, then its caller releases the stolen registration.
A failed P5 returns before clearing and never releases the target. -/
inductive TargetAction where
  | registerClaim | clearPin | releaseClaim

def targetStep (pinned : Bool) : TargetAction → Option Bool
  | .registerClaim => some pinned
  | .clearPin => some false
  | .releaseClaim => if pinned then none else some false

def targetRun : Bool → List TargetAction → Option Bool
  | pinned, [] => some pinned
  | pinned, action :: rest => (targetStep pinned action).bind fun next => targetRun next rest

def ReapResult.trace : ReapResult → List TargetAction
  | .stolen => [.registerClaim, .clearPin, .releaseClaim]
  | _ => []

theorem reaper_source_trace (initial : Bool) (r : ReapResult) :
    targetRun initial r.trace = some (reapTarget initial r).1 := by
  cases r <;> rfl

inductive CallExecution where
  | insert (p : InsertExecution)
  | lookup (p : LookupExecution)

def CallExecution.trace : CallExecution → List Action
  | .insert p => p.trace
  | .lookup p => p.trace

theorem source_call_returns (p : CallExecution) : run false p.trace = some false := by
  cases p with
  | insert p => exact insert_returns_unpinned p
  | lookup p => exact lookup_returns_unpinned p

/-- Drop may follow either API entry point, including all error returns. -/
theorem release_after_api_call (p : CallExecution) :
    run false (p.trace ++ [.releaseClaim]) = some false := by
  simp [run_append, source_call_returns, run, step]

/-- Both reservation success branches and every retry can coexist with the
return invariant. These are concrete executions, not implications from
an impossible successful path. -/
def retryWitness : StoreExecution :=
  .rejectedRetry (.retry .racedBump (.allocated .chunk)) rfl
    (.full (.allocated .bump) rfl .corrupt)

theorem retry_witness : run false retryWitness.trace = some false := by decide

theorem dropped_witness : run false
    (StoreExecution.noReservation (.dropped .sealed) rfl).trace = some false := by decide

end Sgc.PinFlow
