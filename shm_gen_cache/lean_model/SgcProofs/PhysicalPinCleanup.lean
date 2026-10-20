import SgcProofs.PinCleanup

/-!
# RB5: the physical UnpinOnExit read selects the cleanup branch

The source event alphabet includes the guard's actual Relaxed read. Unlike
IndexedPinTrace's W2/W7-only projection it permits a read between W2 and
W7. The conditional is selected by X.rval q, exactly as lock.rs does, not
by an abstract Boolean pin state. The complete pin-write inventory derives
the two quiet intervals needed by conditional_cleanup_reads_zero.
-/
namespace Sgc.Execution
open Sgc.PinFlow

inductive CleanupEvent where
  | w2 | w7 | clear | control | guardRead | boundaryRead
  deriving DecidableEq

def CleanupEvent.isPinWrite : CleanupEvent → Prop
  | .w2 | .w7 | .clear => True
  | _ => False

def cleanupBranch (value : Nat) : List CleanupEvent :=
  if value = 0 then [] else [.w7]

/-- Includes the real guard and boundary reads. before may be an arbitrary
finite interrupted prefix; after allows later calls following a catch. -/
def physicalCleanup (before after : List CleanupEvent) (value : Nat) : List CleanupEvent :=
  before ++ .guardRead :: (cleanupBranch value ++ .boundaryRead :: after)

variable {Ev Loc : Type} {X : Execution Ev Loc} {Lv : Liveness X}
  {S : SlotProtocol X Lv} (P : PinProtocol X S)

/-- Source occurrence labels, event order and the complete own pin-write
inventory. There is no pin-value conclusion, quiet interval, no-unwind
premise or successful assertion in this interface. P6 is indexed too,
rather than assumed absent near the guard. Literal W7 stores zero.
The boundary read may be release_claim's debug load or an observation at
that entry; the argument does not require assertions to run in release. -/
structure PhysicalCleanupTrace (c : Ev) (actions : List CleanupEvent) where
  event : Nat → Ev
  position : Ev → Nat
  event_position : ∀ j, j < actions.length → position (event j) = j
  event_order : ∀ i j, i < j → j < actions.length → X.sb (event i) (event j)
  owned : ∀ j, j < actions.length → S.act c (event j)
  pin_inventory : ∀ {N}, P.nonAnn N → S.act c N →
    event (position N) = N ∧ ∃ action, actions[position N]? = some action ∧ action.isPinWrite
  pin_site : ∀ {j}, actions[j]? = some .w2 → P.w2Of c (event j)
  pin_value : ∀ {j}, actions[j]? = some .w2 → P.pinVal (X.wval (event j)) ≠ 0
  unpin_site : ∀ {j}, actions[j]? = some .w7 → ∃ w, P.unpinOf w (event j)
  unpin_value : ∀ {j}, actions[j]? = some .w7 → X.wval (event j) = 0

namespace PhysicalCleanupTrace

variable {P} {c : Ev} {actions : List CleanupEvent}

theorem position_of_sb (T : PhysicalCleanupTrace P c actions) (hc : X.Consistent)
    {a b : Ev} (ha : T.event (T.position a) = a) (hb : T.event (T.position b) = b)
    (hla : T.position a < actions.length) (hab : X.sb a b) :
    T.position a < T.position b := by
  by_cases heq : T.position a = T.position b
  · have he : a = b := ha.symm.trans ((congrArg T.event heq).trans hb)
    subst b
    exact (hc.hb_irrefl a (hb_of_sb hab)).elim
  by_cases h : T.position a < T.position b
  · exact h
  · have hba := T.event_order (T.position b) (T.position a) (by omega) hla
    rw [ha, hb] at hba
    exact (hc.hb_irrefl a (hb_trans (hb_of_sb hab) (hb_of_sb hba))).elim

/-- Consecutive indexed source events have no intervening own pin write.
This derives quietness even if other threads execute arbitrary R3A writes. -/
theorem adjacent_quiet (T : PhysicalCleanupTrace P c actions) (hc : X.Consistent)
    {j : Nat} (hj : j + 1 < actions.length) :
    ∀ {N}, P.nonAnn N → S.act c N →
      X.sb (T.event j) N → X.sb N (T.event (j + 1)) → False := by
  intro N hN hNc h₁ h₂
  obtain ⟨hNe, a, ha, _⟩ := T.pin_inventory hN hNc
  have hNlen := (List.getElem?_eq_some_iff.mp ha).choose
  have hjlen : j < actions.length := by omega
  have hjpos := T.event_position j hjlen
  have hj1pos := T.event_position (j + 1) hj
  have hje : T.event (T.position (T.event j)) = T.event j := by rw [hjpos]
  have hj1e : T.event (T.position (T.event (j + 1))) = T.event (j + 1) := by rw [hj1pos]
  have hlo := T.position_of_sb hc hje hNe (by rw [hjpos]; exact hjlen) h₁
  have hhi := T.position_of_sb hc hNe hj1e hNlen h₂
  rw [hjpos] at hlo
  rw [hj1pos] at hhi
  omega

end PhysicalCleanupTrace

/-- The physical guard's zero/nonzero source branch establishes an actual
raw-zero read at the later return/release entry. Both zeroQuiet and
nonzeroBranch are derived from indexed source occurrences and their order;
neither is a hypothesis. No abstract pin Boolean selects this trace. -/
theorem physical_cleanup_release_reads_zero {c q r k p : Ev}
    {before after : List CleanupEvent}
    (T : PhysicalCleanupTrace P c (physicalCleanup before after (X.rval q)))
    (hc : X.Consistent)
    (hq : T.event before.length = q)
    (hr : T.event (before.length + 1 + (cleanupBranch (X.rval q)).length) = r)
    (hqnw : ¬ X.isW q) (hrnw : ¬ X.isW r)
    (hql : X.loc q = some P.pin) (hrl : X.loc r = some P.pin)
    (hqrf : X.rf k q) (hrf : X.rf p r) (hdecode : P.pinVal 0 = 0)
    (guard : ∀ {a}, P.isAnnounce a → P.pinVal (X.rval a) ≠ 0) : X.rval r = 0 := by
  have hqlen : before.length < (physicalCleanup before after (X.rval q)).length := by
    simp [physicalCleanup]
  have hrlen : before.length + 1 + (cleanupBranch (X.rval q)).length <
      (physicalCleanup before after (X.rval q)).length := by
    simp only [physicalCleanup, List.length_append, List.length_cons]
    omega
  have hqc : S.act c q := hq ▸ T.owned _ hqlen
  have hrc : S.act c r := hr ▸ T.owned _ hrlen
  have hqr : X.sb q r := by
    rw [← hq, ← hr]
    exact T.event_order _ _ (by omega) hrlen
  apply conditional_cleanup_reads_zero P hc hqc hrc hqr hqnw hrnw hql hrl hqrf hrf hdecode
  · intro hz
    have hr' : T.event (before.length + 1) = r := by simpa [cleanupBranch, hz] using hr
    have hnext : before.length + 1 < (physicalCleanup before after (X.rval q)).length := by
      simpa [cleanupBranch, hz] using hrlen
    intro N hN hNc hqN hNr
    apply T.adjacent_quiet hc hnext hN hNc
    · simpa only [hq] using hqN
    · simpa only [hr'] using hNr
  · intro hn
    have hlabel : (physicalCleanup before after (X.rval q))[before.length + 1]? = some .w7 := by
      simp [physicalCleanup, cleanupBranch, hn]
    obtain ⟨w, hu⟩ := T.unpin_site hlabel
    have hulen : before.length + 1 < (physicalCleanup before after (X.rval q)).length := by
      simp [physicalCleanup, cleanupBranch, hn]
    have hr' : T.event (before.length + 2) = r := by simpa [cleanupBranch, hn] using hr
    have hrlen' : before.length + 2 < (physicalCleanup before after (X.rval q)).length := by
      simpa [cleanupBranch, hn] using hrlen
    refine ⟨w, T.event (before.length + 1), hu, T.owned _ hulen, T.unpin_value hlabel, ?_, ?_⟩
    · rw [← hr']
      exact T.event_order _ _ (by omega) hrlen'
    · intro N hN hNc huN hNr
      apply T.adjacent_quiet hc (j := before.length + 1) (by omega) hN hNc huN
      simpa only [Nat.add_assoc, show 1 + 1 = 2 from rfl, hr'] using hNr
  · exact guard

/-- The outstanding-W2 interruption is the important unwind case.
The physical guard cannot read zero, including when an R3A adds its flag.
Its quiet interval is derived from adjacent indexed source events. -/
theorem physical_cleanup_load_sees_outstanding_pin {c q k : Ev}
    {start after : List CleanupEvent}
    (T : PhysicalCleanupTrace P c (physicalCleanup (start ++ [.w2]) after (X.rval q)))
    (hc : X.Consistent) (hq : T.event (start.length + 1) = q)
    (hqnw : ¬ X.isW q) (hql : X.loc q = some P.pin)
    (hqrf : X.rf k q) (hdecode : P.pinVal 0 = 0) : X.rval q ≠ 0 := by
  have hwlabel : (physicalCleanup (start ++ [.w2]) after (X.rval q))[start.length]? =
      some .w2 := by simp [physicalCleanup, List.append_assoc]
  have hqlen : start.length + 1 <
      (physicalCleanup (start ++ [.w2]) after (X.rval q)).length := by
    simp [physicalCleanup]
  have hwq : X.sb (T.event start.length) q := by
    simpa only [hq] using T.event_order start.length (start.length + 1) (by omega) hqlen
  apply cleanup_load_sees_pin P hc (T.pin_site hwlabel) (T.pin_value hwlabel)
    hdecode hwq (hq ▸ T.owned _ hqlen) hqnw hql hqrf
  intro N hN hNc hwN hNq
  apply T.adjacent_quiet hc hqlen hN hNc hwN
  simpa only [hq] using hNq

/-- Projection of actual call events into the richer physical alphabet;
control markers remain read-free control events until an observation is
inserted explicitly by physicalCleanup. -/
def cleanupSourceEvent : Action → CleanupEvent
  | .w2 => .w2
  | .w7 => .w7
  | _ => .control

/-- An interrupted insert/lookup prefix followed by the actual guard and
release observation. The prefix can stop immediately after W2 or inside
any helper. Physical branch choice, rather than normal-return balance,
establishes the raw pin value at release_claim. -/
theorem interrupted_physical_release_reads_zero (call : CallExecution)
    {pre suffix : List Action} (hprefix : call.trace = pre ++ suffix)
    {c q r k p : Ev} {registrationPrefix later : List CleanupEvent}
    (T : PhysicalCleanupTrace P c
      (physicalCleanup (registrationPrefix ++ pre.map cleanupSourceEvent) later (X.rval q)))
    (hc : X.Consistent)
    (hq : T.event (registrationPrefix ++ pre.map cleanupSourceEvent).length = q)
    (hr : T.event ((registrationPrefix ++ pre.map cleanupSourceEvent).length + 1 +
      (cleanupBranch (X.rval q)).length) = r)
    (hqnw : ¬ X.isW q) (hrnw : ¬ X.isW r)
    (hql : X.loc q = some P.pin) (hrl : X.loc r = some P.pin)
    (hqrf : X.rf k q) (hrf : X.rf p r) (hdecode : P.pinVal 0 = 0)
    (guard : ∀ {a}, P.isAnnounce a → P.pinVal (X.rval a) ≠ 0) :
    (∃ state, run false pre = some state) ∧ X.rval r = 0 := by
  have hchecked := source_call_returns call
  rw [hprefix] at hchecked
  exact ⟨checked_prefix hchecked,
    physical_cleanup_release_reads_zero P T hc hq hr hqnw hrnw hql hrl hqrf hrf hdecode guard⟩

end Sgc.Execution
