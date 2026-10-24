import SgcProofs.ReaperPinFlow
import SgcProofs.SourcePinFlow

/-!
# RB5: reaper release from source reachability

This is the projection of ParticipantSlot::steal and its two callers,
reap_pinner/reap_owner. Every exit is listed independently of pin balance.
Only the Stolen arm calls release_claim. P5 failure returns Corrupt before
P6; all backend/identity callbacks precede P4. After P6 the code calls
wake_rotation and reset_chunk (cursor stores), then returns Stolen.

Any finite prefix is permitted, including an abort/unwind at any operation.
An interrupted steal does not return Stolen and cannot reach target release.
An interruption inside release_claim is after its entry; no no-panic premise
is needed to establish that entry's P6 origin. The optional debug pin load
is omitted in release/verify builds, as in production_assert!.

The event embedding supplies only source instruction labels, forward order,
raw P6 store semantics, and the read's access facts. In particular its three
write-position fields implement the exhaustive pinned_epoch write inventory
(W2, W7, P6), scoped to the stolen target registration, not the calling thread.
There is no quiet-interval, pin-balance, read-value or assertion-success field.
-/
namespace Sgc.ReaperSource
open PinFlow

inductive Site where
  | p0 | t0 | backend | identity | registrationId | p4 | p4Failed | t2
  | p5 | p5Failed | p6 | wake | resetChunk | stolen | releaseEntry
  | releaseState | releaseIdentity | releasePin | p3 | reserveW2 | unpinW7
  deriving DecidableEq

/-- All ordinary result branches. A prefix also covers a callback unwind,
a fatal abort/panic, and interruptions after P4/P5/P6. The two callers differ
only in their boolean result for non-Stolen outcomes, not target actions. -/
def route (result : ReapResult) (debugPinRead : Bool) : List Site :=
  match result with
  | .free | .other => [.p0]
  | .live | .backendError => [.p0, .t0, .backend]
  | .lost => [.p0, .t0, .backend, .identity, .registrationId, .p4Failed]
  | .corruptBeforeClear =>
      [.p0, .t0, .backend, .identity, .registrationId, .p4, .t2, .p5Failed]
  | .stolen =>
      [.p0, .t0, .backend, .identity, .registrationId, .p4, .t2, .p5,
       .p6, .wake, .resetChunk, .stolen, .releaseEntry, .releaseState,
       .releaseIdentity] ++ (if debugPinRead then [.releasePin] else []) ++ [.p3]

/-- Source reachability is prefix closure, not an assumption that the call
returns or that its callbacks cannot panic. -/
structure Reachable where
  result : ReapResult
  debugPinRead : Bool
  trace : List Site
  rest : List Site
  is_prefix : trace ++ rest = route result debugPinRead

theorem route_length (result : ReapResult) (debug : Bool) :
    (route result debug).length ≤ 17 := by cases result <;> cases debug <;> decide

private theorem finite_sites : ∀ (result : ReapResult) (debug : Bool) (j : Fin 17),
    ((route result debug)[j.val]? = some .p6 → j.val = 8) ∧
    ((route result debug)[j.val]? = some .releaseEntry → result = .stolen ∧ j.val = 12) ∧
    ((route result debug)[j.val]? = some .releasePin → result = .stolen ∧ debug = true ∧ j.val = 15) ∧
    (route result debug)[j.val]? ≠ some .reserveW2 ∧
    (route result debug)[j.val]? ≠ some .unpinW7 := by
  intro result debug
  cases result <;> cases debug <;> decide

theorem Reachable.route_label (R : Reachable) {j : Nat} {site : Site}
    (h : R.trace[j]? = some site) : (route R.result R.debugPinRead)[j]? = some site := by
  have hj := (List.getElem?_eq_some_iff.mp h).choose
  rw [← R.is_prefix, List.getElem?_append_left hj]
  exact h

private theorem Reachable.site_facts (R : Reachable) {j : Nat} {site : Site}
    (h : R.trace[j]? = some site) :
    (site = .p6 → j = 8) ∧
    (site = .releaseEntry → R.result = .stolen ∧ j = 12) ∧
    (site = .releasePin → R.result = .stolen ∧ R.debugPinRead = true ∧ j = 15) ∧
    site ≠ .reserveW2 ∧ site ≠ .unpinW7 := by
  have hr := R.route_label h
  have hj := (List.getElem?_eq_some_iff.mp hr).choose
  have facts := finite_sites R.result R.debugPinRead ⟨j, Nat.lt_of_lt_of_le hj (route_length _ _)⟩
  simpa only [hr, ne_eq, Option.some.injEq] using facts

/-- Reaching either release boundary implies the actual P6 lies earlier in
the same executed prefix, even if execution stops before release completes. -/
theorem Reachable.release_origin (R : Reachable) {j : Nat}
    (h : R.trace[j]? = some .releaseEntry ∨ R.trace[j]? = some .releasePin) :
    R.result = .stolen ∧ R.trace[8]? = some .p6 ∧ 8 < j := by
  have info : R.result = .stolen ∧ 8 < j := by
    rcases h with h | h
    · obtain ⟨hr, rfl⟩ := (R.site_facts h).2.1 rfl
      exact ⟨hr, by decide⟩
    · obtain ⟨hr, _, rfl⟩ := (R.site_facts h).2.2.1 rfl
      exact ⟨hr, by decide⟩
  refine ⟨info.1, ?_, info.2⟩
  have hj : j < R.trace.length := by
    rcases h with h | h <;> exact (List.getElem?_eq_some_iff.mp h).choose
  have h8 : 8 < R.trace.length := Nat.lt_trans info.2 hj
  have hp : (route R.result R.debugPinRead)[8]? = some .p6 := by
    rw [info.1]
    cases R.debugPinRead <;> rfl
  rw [← R.is_prefix, List.getElem?_append_left h8] at hp
  exact hp

/-- Source reachability is nonempty both at release and for a reaper that
stops after P6 without returning Stolen to its caller. -/
theorem source_prefix_witnesses :
    (∃ R : Reachable, R.trace[12]? = some .releaseEntry ∧
      R.trace[15]? = some .releasePin) ∧
    (∃ R : Reachable, R.trace[8]? = some .p6 ∧ .releaseEntry ∉ R.trace) := by
  constructor
  · exact ⟨⟨.stolen, true, route .stolen true, [], by simp⟩, rfl, rfl⟩
  · exact ⟨⟨.stolen, true, (route .stolen true).take 9,
      (route .stolen true).drop 9, List.take_append_drop 9 _⟩, rfl, by decide⟩

end Sgc.ReaperSource
namespace Sgc.Execution
open ReaperSource
variable {Ev Loc : Type} {X : Execution Ev Loc} {Lv : Liveness X}
  {S : SlotProtocol X Lv} (P : PinProtocol X S)

/-- The source event interpretation of one target registration's reaping
invocation. No field constrains the value read at release. -/
structure ReaperSourceEvents (c : Ev) where
  path : Reachable
  event : Nat → Ev
  order : ∀ {i j}, i < j → j < path.trace.length → X.sb (event i) (event j)
  w2_position : ∀ {w}, P.w2Of c w → ∃ j, path.trace[j]? = some .reserveW2 ∧ event j = w
  unpin_position : ∀ {w u}, P.unpinOf w u → S.act c u →
    ∃ j, path.trace[j]? = some .unpinW7 ∧ event j = u
  clear_position : ∀ {d k}, P.clearOf d k → S.act c k →
    ∃ j, path.trace[j]? = some .p6 ∧ event j = k
  p6_event : ∀ {j}, path.trace[j]? = some .p6 → P.clearOf c (event j) ∧ X.wval (event j) = 0
  release_read : ∀ {j}, path.trace[j]? = some .releasePin →
    S.act c (event j) ∧ ¬ X.isW (event j) ∧ X.loc (event j) = some P.pin ∧ ∃ p, X.rf p (event j)

/-- The source path and the exhaustive source write labels imply that all
own non-announce writes of this stolen target registration are its P6. -/
theorem ReaperSourceEvents.only_p6 {c : Ev} (T : ReaperSourceEvents P c)
    (hc : X.Consistent) {N : Ev} (hN : P.nonAnn N) (hNc : S.act c N) :
    N = T.event 8 := by
  rcases hN with ⟨d, hw⟩ | ⟨w, hu⟩ | ⟨d, hk⟩
  · have hd : d = c := same_slot_action_unique hc (P.w2_act hw) hNc
    subst d
    obtain ⟨j, hj, _⟩ := T.w2_position hw
    exact ((T.path.site_facts hj).2.2.2.1 rfl).elim
  · obtain ⟨j, hj, _⟩ := T.unpin_position hu hNc
    exact ((T.path.site_facts hj).2.2.2.2 rfl).elim
  · obtain ⟨j, hj, he⟩ := T.clear_position hk hNc
    have h8 := (T.path.site_facts hj).1 rfl
    subst j
    exact he.symm

/-- Every reachable release entry, including prefixes ending in fatal or
unwind inside release_claim, has an earlier physical raw-zero P6. -/
theorem reaper_source_release_origin {c : Ev} (T : ReaperSourceEvents P c)
    {j : Nat} (h : T.path.trace[j]? = some .releaseEntry ∨ T.path.trace[j]? = some .releasePin) :
    P.clearOf c (T.event 8) ∧ X.wval (T.event 8) = 0 ∧ X.sb (T.event 8) (T.event j) := by
  obtain ⟨_, hk, hlt⟩ := T.path.release_origin h
  have hj : j < T.path.trace.length := by
    rcases h with h | h <;> exact (List.getElem?_eq_some_iff.mp h).choose
  exact ⟨(T.p6_event hk).1, (T.p6_event hk).2, T.order hlt hj⟩

/-- Universal source adapter, not a concrete-execution instantiation.
ReaperReleaseFacts is constructed from reachable source instructions;
its quiet field is proved from the write labels and path, not assumed. -/
theorem reaper_source_release_reads_zero {c : Ev} (T : ReaperSourceEvents P c)
    (hc : X.Consistent) {j : Nat} (h : T.path.trace[j]? = some .releasePin)
    (decode : P.pinVal 0 = 0)
    (guard : ∀ {a}, P.isAnnounce a → P.pinVal (X.rval a) ≠ 0) :
    X.rval (T.event j) = 0 := by
  obtain ⟨hk, hk0, hkr⟩ := reaper_source_release_origin P T (Or.inr h)
  obtain ⟨hrc, hrnw, hrl, p, hrf⟩ := T.release_read h
  let F : ReaperReleaseFacts P := {
    releaseRead := fun d r => d = c ∧ r = T.event j
    source := by
      intro d r hr
      obtain ⟨rfl, rfl⟩ := hr
      exact ⟨T.event 8, p, hk, hk0, decode, hkr, hrc, hrnw, hrl, hrf⟩
    quiet := by
      intro d k r N hr hclear hN hNc hkN _
      obtain ⟨rfl, rfl⟩ := hr
      have hkEq := ReaperSourceEvents.only_p6 P T hc (Or.inr (Or.inr ⟨d, hclear⟩)) (P.clear_act hclear)
      have hNEq := ReaperSourceEvents.only_p6 P T hc hN hNc
      rw [hkEq, hNEq] at hkN
      exact hc.hb_irrefl _ (hb_of_sb hkN)
    announce_guard := guard }
  exact reaper_release_reads_zero P F hc ⟨rfl, rfl⟩

end Sgc.Execution
