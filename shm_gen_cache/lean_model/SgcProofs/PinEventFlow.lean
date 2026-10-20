import SgcProofs.SourcePinFlow
import SgcProofs.ReaperPinFlow

/-!
# Source pin traces and atomic pass obligations

The trace projection records only W2, W7, API returns, rotation entries and
claim releases. Thus the next projected action after W2 must be its W7;
all payload, table and occupancy operations occur between those two events.
`next_after_w2` derives that fact from the source grammar's checked trace,
without using PinProtocol.pass_exit or PinProtocol.pass_quiet.

The event interface below states event labels, program order and the source
identity of each W7. These are independently inspectable source facts. It
contains neither pass_exit nor pass_quiet nor an assertion-read value.
The Rust-to-grammar classification is still a manual source audit, not a
mechanized Rust frontend. Interrupted calls use prefixes of the same source
traces; termination is not assumed for the pass obligations.
-/
namespace Sgc.PinFlow

/-- Ordinary execution semantics: control actions do not test or modify
the pin. Safety of those actions is proved from the source grammar. -/
def execute (pinned : Bool) : Action → Bool
  | .w2 => true
  | .w7 => false
  | .rotate | .releaseClaim | .returnCall => pinned

def executeTrace : Bool → List Action → Bool
  | pinned, [] => pinned
  | pinned, a :: rest => executeTrace (execute pinned a) rest

theorem checked_equals_execution {xs : List Action} {initial final : Bool}
    (h : run initial xs = some final) : executeTrace initial xs = final := by
  induction xs generalizing initial with
  | nil => simpa [run, executeTrace] using h
  | cons a xs ih =>
    cases a <;> cases initial <;>
      simp_all [run, step, executeTrace, execute]

theorem source_call_executes_unpinned (p : CallExecution) :
    executeTrace false p.trace = false := checked_equals_execution (source_call_returns p)

/-- If a source W2 has any later projected action, the very next action
is W7. This covers interrupted prefixes as well as completed calls. -/
theorem next_after_w2 {xs : List Action} {initial final : Bool} {i : Nat}
    (h : run initial xs = some final) (hw : xs[i]? = some .w2)
    (hlater : i + 1 < xs.length) : xs[i + 1]? = some .w7 := by
  induction xs generalizing initial i with
  | nil => simp at hw
  | cons a xs ih =>
    cases i with
    | zero =>
      simp only [List.getElem?_cons_zero, Option.some.injEq] at hw
      subst a
      cases initial with
      | true => simp [run, step] at h
      | false =>
        cases xs with
        | nil => simp at hlater
        | cons b rest =>
          cases b <;> simp_all [run, step]
    | succ i =>
      simp only [List.getElem?_cons_succ] at hw ⊢
      have hlen : i + 1 < xs.length := by simp only [List.length_cons] at hlater; omega
      cases hs : step initial a with
      | none => simp [run, hs] at h
      | some next =>
        exact ih (by simpa [run, hs] using h) hw hlen

/-- The final W2 of a completed source call also has its W7. -/
theorem completed_w2_has_successor {xs : List Action} {initial : Bool} {i : Nat}
    (h : run initial xs = some false) (hw : xs[i]? = some .w2) : i + 1 < xs.length := by
  induction xs generalizing initial i with
  | nil => simp at hw
  | cons a xs ih =>
    cases i with
    | zero =>
      simp only [List.getElem?_cons_zero, Option.some.injEq] at hw
      subst a
      cases initial with
      | true => simp [run, step] at h
      | false =>
        cases xs with
        | nil => simp [run, step] at h
        | cons b rest => simp
    | succ i =>
      simp only [List.getElem?_cons_succ] at hw
      cases hs : step initial a with
      | none => simp [run, hs] at h
      | some next =>
        have := ih (by simpa [run, hs] using h) hw
        simp only [List.length_cons]
        omega

/-- Every terminating constructor, including errors, dropped promotion,
Full and FullRejected, has W7 directly after each projected W2. -/
theorem source_call_pairs_every_w2 (p : CallExecution) {i : Nat}
    (hw : p.trace[i]? = some .w2) : p.trace[i + 1]? = some .w7 :=
  next_after_w2 (source_call_returns p) hw
    (completed_w2_has_successor (source_call_returns p) hw)

/-- No W2 or W7 occurs in this projected interval. -/
def PinFree (xs : List Action) : Prop :=
  ∀ a ∈ xs, a ≠ .w2 ∧ a ≠ .w7

/-- Convert the selected tail into indexed source facts used by the
atomic event adapter. -/
theorem PinFree.interval {start tail rest : List Action} (h : PinFree tail)
    {j : Nat} (hlo : start.length < j) (hhi : j < start.length + 1 + tail.length) :
    (start ++ .w7 :: (tail ++ rest))[j]? ≠ some .w2 ∧
    (start ++ .w7 :: (tail ++ rest))[j]? ≠ some .w7 := by
  have hj : j - start.length = (j - start.length - 1) + 1 := by omega
  have htail : j - start.length - 1 < tail.length := by omega
  rw [List.getElem?_append_right (by omega), hj, List.getElem?_cons_succ,
    List.getElem?_append_left htail]
  constructor
  · intro he; exact (h .w2 (List.mem_of_getElem? he)).1 rfl
  · intro he; exact (h .w7 (List.mem_of_getElem? he)).2 rfl

/-- An unpinned prefix either never touched the pin, or its last pin write
was W7. This is derived from the trace, not assumed as a balance premise. -/
theorem checked_zero_origin {xs : List Action} {initial : Bool}
    (h : run initial xs = some false) :
    (initial = false ∧ PinFree xs) ∨
      ∃ before after, xs = before ++ .w7 :: after ∧ PinFree after := by
  induction xs generalizing initial with
  | nil => exact Or.inl ⟨by simpa [run] using h, by simp [PinFree]⟩
  | cons a xs ih =>
    cases a <;> cases initial <;> simp only [run, step, Bool.false_eq_true,
      ↓reduceIte, Option.bind_some, Option.bind_none] at h
    all_goals try contradiction
    case w2.false =>
      rcases ih h with ⟨hf, _⟩ | ⟨before, after, heq, hfree⟩
      · contradiction
      · exact Or.inr ⟨.w2 :: before, after, congrArg (List.cons .w2) heq, hfree⟩
    case w7.true =>
      rcases ih h with ⟨_, hfree⟩ | ⟨before, after, heq, hfree⟩
      · exact Or.inr ⟨[], xs, rfl, hfree⟩
      · exact Or.inr ⟨.w7 :: before, after, congrArg (List.cons .w7) heq, hfree⟩
    all_goals
      rcases ih h with ⟨_, hfree⟩ | ⟨before, after, heq, hfree⟩
      · apply Or.inl
        constructor
        · rfl
        · intro a ha
          simp only [List.mem_cons] at ha
          rcases ha with rfl | ha
          · decide
          · exact hfree a ha
      · first
        | exact Or.inr ⟨.rotate :: before, after, congrArg (List.cons .rotate) heq, hfree⟩
        | exact Or.inr ⟨.releaseClaim :: before, after, congrArg (List.cons .releaseClaim) heq, hfree⟩
        | exact Or.inr ⟨.returnCall :: before, after, congrArg (List.cons .returnCall) heq, hfree⟩

/-- Registration histories can contain arbitrarily many calls, followed
by Drop. A finite interrupted history is a prefix of such a trace. -/
def sourceHistory : List CallExecution → List Action
  | [] => []
  | p :: rest => p.trace ++ sourceHistory rest

theorem source_history_checked (calls : List CallExecution) :
    run false (sourceHistory calls ++ [.releaseClaim]) = some false := by
  induction calls with
  | nil => rfl
  | cons p rest ih =>
    simpa [sourceHistory, List.append_assoc, run_append, source_call_returns] using ih

/-- Interruption does not assume that the current call returns: any finite
prefix has a checked state, possibly pinned. -/
theorem checked_prefix {xs ys : List Action} {initial final : Bool}
    (h : run initial (xs ++ ys) = some final) : ∃ state, run initial xs = some state := by
  rw [run_append] at h
  cases hx : run initial xs with
  | none => simp [hx] at h
  | some state => exact ⟨state, rfl⟩

theorem source_history_prefix_checked (calls : List CallExecution) {before rest : List Action}
    (h : sourceHistory calls ++ [.releaseClaim] = before ++ rest) :
    ∃ state, run false before = some state := by
  apply checked_prefix (ys := rest)
  rw [← h]
  exact source_history_checked calls

/-- Rotation, Drop and returns preserve the pin in ordinary execution.
Their entry state is derived from source paths, not enforced by execute. -/
theorem source_history_entry_state (calls : List CallExecution)
    {before after : List Action} {entry : Action}
    (he : entry = .rotate ∨ entry = .releaseClaim ∨ entry = .returnCall)
    (h : sourceHistory calls ++ [.releaseClaim] = before ++ entry :: after) :
    executeTrace false before = false := by
  have hrun := source_history_checked calls
  rw [h] at hrun
  obtain ⟨state, hs, rfl⟩ := checked_entry_unpinned he hrun
  exact checked_equals_execution hs

/-- Every control entry has a source-selected zero origin: either the
registration baseline (no W2/W7), or the last W7 and a pin-write-free tail. -/
theorem source_history_entry_origin (calls : List CallExecution)
    {before after : List Action} {entry : Action}
    (he : entry = .rotate ∨ entry = .releaseClaim ∨ entry = .returnCall)
    (h : sourceHistory calls ++ [.releaseClaim] = before ++ entry :: after) :
    PinFree before ∨ ∃ start tail, before = start ++ .w7 :: tail ∧ PinFree tail := by
  have hrun := source_history_checked calls
  rw [h] at hrun
  obtain ⟨state, hs, rfl⟩ := checked_entry_unpinned he hrun
  exact (checked_zero_origin hs).elim (fun h => Or.inl h.2) Or.inr

end Sgc.PinFlow

namespace Sgc.Execution
open Sgc.PinFlow
variable {Ev Loc : Type} {X : Execution Ev Loc} {Lv : Liveness X}
  {S : SlotProtocol X Lv}

/-- Source labels and registration ownership only. This interface has no
pass-exit, pass-quiet, read-value or pin-coherence fields. W2 is the reserve
store; W7 is the same pass's unpin swap; P6 is a successful steal's clear.
The source owns W2/W7 through the same ParticipantLock registration. -/
structure PinSourceLabels (X : Execution Ev Loc) {Lv : Liveness X}
    (S : SlotProtocol X Lv) where
  w2Of : Ev → Ev → Prop
  unpinOf : Ev → Ev → Prop
  clearOf : Ev → Ev → Prop
  w2_act : ∀ {c w}, w2Of c w → S.act c w
  unpin_w2 : ∀ {w u}, unpinOf w u → ∃ c, w2Of c w ∧ S.act c u

/-- The source's complete inventory of registrant pin writes. -/
abbrev PinSourceLabels.nonAnn (L : PinSourceLabels X S) (N : Ev) : Prop :=
  (∃ c, L.w2Of c N) ∨ (∃ w, L.unpinOf w N) ∨ (∃ c, L.clearOf c N)

/-- Connect the independently proved source obligations to the atomic
protocol. Only labels and ownership are projected; no F1 field is used. -/
def PinProtocol.sourceLabels (P : PinProtocol X S) : PinSourceLabels X S where
  w2Of := P.w2Of
  unpinOf := P.unpinOf
  clearOf := P.clearOf
  w2_act := P.w2_act
  unpin_w2 := P.unpin_w2

variable (L : PinSourceLabels X S)

/-- Event realization of a finite registration history (possibly ending
inside a pass). The checked run is supplied separately by the source grammar proof.
The write inventory is the two actual W2/W7 sites; P6 precedes every W2 of
its stealing registration and is outside this call-history projection.
Pairing identifies the same reserve/store pass, including the pinned
return from reserve to store. No protocol pass axiom is used below. -/
structure IndexedPinTrace (c : Ev) (actions : List Action) where
  event : Nat → Ev
  position : Ev → Nat
  event_position : ∀ j, j < actions.length → position (event j) = j
  event_order : ∀ i j, i < j → j < actions.length → X.sb (event i) (event j)
  w2_event : ∀ {w}, L.w2Of c w → event (position w) = w
  unpin_event : ∀ {w u}, L.w2Of c w → L.unpinOf w u → event (position u) = u
  owned : ∀ j, j < actions.length → S.act c (event j)
  w2_label : ∀ {w}, L.w2Of c w → actions[position w]? = some .w2
  event_w2 : ∀ {j}, actions[j]? = some .w2 → L.w2Of c (event j)
  event_w7 : ∀ {j}, actions[j]? = some .w7 →
    ∃ w, L.w2Of c w ∧ L.unpinOf w (event j)
  unpin_label : ∀ {w u}, L.w2Of c w → L.unpinOf w u →
    actions[position u]? = some .w7
  w7_pair : ∀ {w j}, L.w2Of c w → j = position w + 1 →
    actions[j]? = some .w7 → L.unpinOf w (event j)
  pair_position : ∀ {w u}, L.w2Of c w → L.unpinOf w u → position u = position w + 1
  clear_before : ∀ {w N d}, L.w2Of c w → S.act c N → L.clearOf d N → X.sb N w

/-- The complete own-write inventory is obtained from the minimal
source labels, with same-slot claim uniqueness resolving the owner. -/
theorem IndexedPinTrace.write_label {c N : Ev} {actions : List Action}
    (T : IndexedPinTrace L c actions) (hc : X.Consistent)
    (hact : S.act c N) (hN : L.nonAnn N) :
    (∃ d, L.clearOf d N) ∨
      (T.event (T.position N) = N ∧
        (actions[T.position N]? = some .w2 ∨ actions[T.position N]? = some .w7)) := by
  rcases hN with ⟨d, hw⟩ | ⟨w, hu⟩ | hclear
  · have heq := same_slot_action_unique hc hact (L.w2_act hw)
    subst d
    exact Or.inr ⟨T.w2_event hw, Or.inl (T.w2_label hw)⟩
  · obtain ⟨d, hw, hd⟩ := L.unpin_w2 hu
    have heq := same_slot_action_unique hc hact hd
    subst d
    exact Or.inr ⟨T.unpin_event hw hu, Or.inr (T.unpin_label hw hu)⟩
  · exact Or.inl hclear

/-- Program order agrees with positions for tracked events, by consistency
and the source's forward event ordering. -/
theorem IndexedPinTrace.position_of_sb {c a b : Ev} {actions : List Action}
    (T : IndexedPinTrace L c actions) (hc : X.Consistent)
    (ha : T.event (T.position a) = a) (hb : T.event (T.position b) = b)
    (hla : T.position a < actions.length) (hab : X.sb a b) : T.position a < T.position b := by
  by_cases heq : T.position a = T.position b
  · have he : a = b := ha.symm.trans ((congrArg T.event heq).trans hb)
    subst b
    exact (hc.hb_irrefl a (hb_of_sb hab)).elim
  by_cases h : T.position a < T.position b
  · exact h
  · have hba := T.event_order (T.position b) (T.position a) (by omega) hla
    rw [ha, hb] at hba
    exact (hc.hb_irrefl a (hb_trans (hb_of_sb hab) (hb_of_sb hba))).elim

/-- F1 quiet follows from adjacent source positions, independently of the
full PinProtocol and its field of that name. -/
theorem IndexedPinTrace.derives_pass_quiet {c w u N : Ev} {actions : List Action}
    (T : IndexedPinTrace L c actions) (hc : X.Consistent) (hw : L.w2Of c w) (hu : L.unpinOf w u)
    (hNact : S.act c N) (hN : L.nonAnn N) (hwN : X.sb w N) (hNu : X.sb N u) : False := by
  rcases IndexedPinTrace.write_label L T hc hNact hN with ⟨d, hd⟩ | ⟨hNe, hlabel⟩
  · exact hc.hb_irrefl w (hb_trans (hb_of_sb hwN)
      (hb_of_sb (T.clear_before hw hNact hd)))
  · have hwlen := (List.getElem?_eq_some_iff.mp (T.w2_label hw)).choose
    have hNlen : T.position N < actions.length := by
      rcases hlabel with h | h <;> exact (List.getElem?_eq_some_iff.mp h).choose
    have h₁ := IndexedPinTrace.position_of_sb L T hc (T.w2_event hw) hNe hwlen hwN
    have h₂ := IndexedPinTrace.position_of_sb L T hc hNe (T.unpin_event hw hu) hNlen hNu
    have hpair := T.pair_position hw hu
    omega

/-- F1 exit is derived for every later own pin write. Interrupted prefixes
are sufficient: existence of a later write forces the W7 successor. -/
theorem IndexedPinTrace.derives_pass_exit {c w N : Ev} {actions : List Action}
    (T : IndexedPinTrace L c actions) (hc : X.Consistent) {final : Bool}
    (checked : run false actions = some final) (hw : L.w2Of c w)
    (hNact : S.act c N) (hN : L.nonAnn N) (hwN : X.sb w N) :
    ∃ u, L.unpinOf w u ∧ S.act c u := by
  rcases IndexedPinTrace.write_label L T hc hNact hN with ⟨d, hd⟩ | ⟨hNe, hlabel⟩
  · exact (hc.hb_irrefl w (hb_trans (hb_of_sb hwN)
      (hb_of_sb (T.clear_before hw hNact hd)))).elim
  · have hlen : T.position N < actions.length := by
      rcases hlabel with h | h <;> exact (List.getElem?_eq_some_iff.mp h).choose
    have hwlen := (List.getElem?_eq_some_iff.mp (T.w2_label hw)).choose
    have hpos := IndexedPinTrace.position_of_sb L T hc (T.w2_event hw) hNe hwlen hwN
    have hnext : T.position w + 1 < actions.length := by omega
    have hlabel := next_after_w2 checked (T.w2_label hw) hnext
    exact ⟨T.event (T.position w + 1), T.w7_pair hw rfl hlabel,
      T.owned _ hnext⟩

/-- Every completed source API call pairs each physical W2 with a physical
W7. W7's value comes from the actual swap(UNPINNED), independently of the
abstract Boolean state. Early returns have no W2 and need no invented W7. -/
theorem source_call_w7_events (p : CallExecution) {c w : Ev}
    (T : IndexedPinTrace L c p.trace) (hw : L.w2Of c w)
    (unpin_zero : ∀ {w u}, L.unpinOf w u → X.wval u = 0) :
    ∃ u, L.unpinOf w u ∧ S.act c u ∧ X.sb w u ∧ X.wval u = 0 := by
  have hnext := completed_w2_has_successor (source_call_returns p) (T.w2_label hw)
  have hu := T.w7_pair hw rfl (source_call_pairs_every_w2 p (T.w2_label hw))
  exact ⟨_, hu, T.owned _ hnext, by
    simpa only [T.w2_event hw] using T.event_order (T.position w) (T.position w + 1)
      (by omega) hnext, unpin_zero hu⟩

/-- The source API grammar discharges the run premise of the event-level
exit result. It does not import PinProtocol.pass_exit as a premise. -/
theorem source_call_pass_exit (p : CallExecution) {c w N : Ev}
    (T : IndexedPinTrace L c p.trace) (hc : X.Consistent)
    (hw : L.w2Of c w) (hNact : S.act c N) (hN : L.nonAnn N) (hwN : X.sb w N) :
    ∃ u, L.unpinOf w u ∧ S.act c u :=
  IndexedPinTrace.derives_pass_exit L T hc (source_call_returns p) hw hNact hN hwN

/-- Whole registrations, including interrupted calls, discharge F1 exit
from the same exhaustive source constructors. P6 is handled by its source
position before any call of a successful stealing registration. -/
theorem source_history_pass_exit (calls : List CallExecution)
    {before rest : List Action} (h : sourceHistory calls ++ [.releaseClaim] = before ++ rest)
    {c w N : Ev} (T : IndexedPinTrace L c before) (hc : X.Consistent)
    (hw : L.w2Of c w) (hNact : S.act c N) (hN : L.nonAnn N) (hwN : X.sb w N) :
    ∃ u, L.unpinOf w u ∧ S.act c u := by
  obtain ⟨state, hstate⟩ := source_history_prefix_checked calls h
  exact IndexedPinTrace.derives_pass_exit L T hc hstate hw hNact hN hwN

/-- The pin-free tail selected by checked_zero_origin rules out each own
write in the corresponding atomic interval. Foreign writes are handled
later by SL1, rather than excluded by a source assumption. -/
theorem IndexedPinTrace.quiet_after_w7 {c w u r : Ev} {actions : List Action}
    (T : IndexedPinTrace L c actions) (hc : X.Consistent)
    (hw : L.w2Of c w) (hu : L.unpinOf w u)
    (hr : T.event (T.position r) = r)
    (free : ∀ j, T.position u < j → j < T.position r →
      actions[j]? ≠ some .w2 ∧ actions[j]? ≠ some .w7) :
    ∀ {N}, L.nonAnn N → S.act c N → X.sb u N → X.sb N r → False := by
  have hulen := (List.getElem?_eq_some_iff.mp (T.unpin_label hw hu)).choose
  have hwulen : T.position w < T.position u := by rw [T.pair_position hw hu]; omega
  have hwu := T.event_order _ _ hwulen hulen
  rw [T.w2_event hw, T.unpin_event hw hu] at hwu
  intro N hN hact huN hNr
  rcases IndexedPinTrace.write_label L T hc hact hN with ⟨d, hd⟩ | ⟨hNe, hlabel⟩
  · exact hc.hb_irrefl w (hb_trans (hb_of_sb hwu)
      (hb_trans (hb_of_sb huN) (hb_of_sb (T.clear_before hw hact hd))))
  · have hNlen : T.position N < actions.length := by
      rcases hlabel with h | h <;> exact (List.getElem?_eq_some_iff.mp h).choose
    have hi := IndexedPinTrace.position_of_sb L T hc (T.unpin_event hw hu) hNe hulen huN
    have hj := IndexedPinTrace.position_of_sb L T hc hNe hr hNlen hNr
    exact hlabel.elim (free _ hi hj).1 (free _ hi hj).2

/-- The physical assertion read at a source entry after its final W7 is
zero. Source trace positions supply the quiet window, W7 supplies the raw
zero store, and the atomic theorem excludes foreign writes and announces. -/
theorem source_entry_after_w7_reads_zero (P : PinProtocol X S) {c w u r rfSource : Ev} {actions : List Action}
    (T : IndexedPinTrace P.sourceLabels c actions) (hc : X.Consistent)
    (hw : P.w2Of c w) (hu : P.unpinOf w u) (huc : S.act c u)
    (hu0 : X.wval u = 0) (hdecode : P.pinVal 0 = 0) (hur : X.sb u r)
    (hr : T.event (T.position r) = r) (hrc : S.act c r) (hrnw : ¬ X.isW r)
    (hrl : X.loc r = some P.pin) (hrf : X.rf rfSource r)
    (free : ∀ j, T.position u < j → j < T.position r →
      actions[j]? ≠ some .w2 ∧ actions[j]? ≠ some .w7)
    (guard : ∀ {a}, P.isAnnounce a → P.pinVal (X.rval a) ≠ 0) : X.rval r = 0 :=
  own_entry_after_w7_reads_zero P hc hu huc hu0 hdecode hur hrc hrnw hrl hrf
    (IndexedPinTrace.quiet_after_w7 P.sourceLabels T hc hw hu hr free) guard

/-- The actual final W7 supplies the terminal-write premise of a normal
FREE handoff. This uses the full source inventory, not release assertions. -/
theorem IndexedPinTrace.terminal_w7 {c w u : Ev} {actions : List Action}
    (T : IndexedPinTrace L c actions) (hc : X.Consistent)
    (hw : L.w2Of c w) (hu : L.unpinOf w u) (huc : S.act c u)
    (last : ∀ j, T.position u < j → j < actions.length →
      actions[j]? ≠ some .w2 ∧ actions[j]? ≠ some .w7) :
    ∀ {N}, L.nonAnn N → S.act c N → N = u ∨ X.sb N u := by
  have hulen := (List.getElem?_eq_some_iff.mp (T.unpin_label hw hu)).choose
  have hwu := T.event_order (T.position w) (T.position u)
    (by rw [T.pair_position hw hu]; omega) hulen
  rw [T.w2_event hw, T.unpin_event hw hu] at hwu
  intro N hN hact
  rcases IndexedPinTrace.write_label L T hc hact hN with ⟨d, hd⟩ | ⟨hNe, hlabel⟩
  · have hNu := hb_trans (hb_of_sb (T.clear_before hw hact hd)) (hb_of_sb hwu)
    rcases S.act_total hact huc with heq | h | h
    · exact Or.inl heq
    · exact Or.inr h
    · exact (hc.hb_irrefl N (hb_trans hNu (hb_of_sb h))).elim
  · have hNlen : T.position N < actions.length := by
      rcases hlabel with h | h <;> exact (List.getElem?_eq_some_iff.mp h).choose
    have hle : T.position N ≤ T.position u := by
      by_cases h : T.position u < T.position N
      · exact (hlabel.elim (last _ h hNlen).1 (last _ h hNlen).2).elim
      · omega
    rcases Nat.eq_or_lt_of_le hle with heq | hlt
    · exact Or.inl (hNe.symm.trans ((congrArg T.event heq).trans (T.unpin_event hw hu)))
    · exact Or.inr (by simpa only [hNe, T.unpin_event hw hu] using T.event_order _ _ hlt hulen)

/-- A normal registration followed by FREE/P1 inherits the source-selected
last W7 as the next registration's zero baseline. -/
theorem source_free_handoff_zero_prefix (P : PinProtocol X S) {d c f w u : Ev} {actions : List Action}
    (T : IndexedPinTrace P.sourceLabels d actions) (hc : X.Consistent)
    (hw : P.w2Of d w) (hu : P.unpinOf w u) (hud : S.act d u)
    (hu0 : X.wval u = 0) (hf : S.freeOf d f) (hfc : S.isFreeClaim c) (hrf : X.rf f c)
    (last : ∀ j, T.position u < j → j < actions.length →
      actions[j]? ≠ some .w2 ∧ actions[j]? ≠ some .w7) :
    RegistrationZeroPrefix P c u ∧ X.hb u c :=
  free_registration_zero_prefix P hc hf hfc hrf (Or.inr (Or.inl ⟨w, hu⟩)) hud hu0
    (IndexedPinTrace.terminal_w7 P.sourceLabels T hc hw hu hud last)

end Sgc.Execution
