import SgcProofs.PinEventFlow

/-!
# RB5: physical pin observation at an ordinary API return

The source grammar selects the zero origin for every return, including
input errors, failed/dropped promotions and FullRejected. An actual relaxed
read at that boundary sees raw zero. It may be the public-call guard's
read, the next assert_usable read, or a diagnostic observation; the theorem
does not assume the read succeeds or that a debug assertion executes.

The source labels are still the actual reserve W2, unpin swap W7, and
steal P6. W7 stores literal UNPINNED, decode(0)=0, and R3A expects a nonzero
pin. Those are instruction-value facts, not assumed return-read values.
RegistrationZeroPrefix is established by initialization, FREE handoff or
P6 in ReaperPinFlow. The only additional clear-origin fact identifies the
unique P6 of a stolen registration; ordinary P1 registrations have none.
-/
namespace Sgc.Execution
open Sgc.PinFlow
variable {Ev Loc : Type} {X : Execution Ev Loc} {Lv : Liveness X}
  {S : SlotProtocol X Lv}

/-- If no W2/W7 precedes a boundary, every own pin write before it is
its registration's P6, if present. Tracked-event ordering is derived from
consistency, and no position is imposed on P6. -/
theorem IndexedPinTrace.quiet_before_first_pass (P : PinProtocol X S)
    {c k r : Ev} {actions before after : List Action}
    (T : IndexedPinTrace P.sourceLabels c actions)
    (shape : actions = before ++ .returnCall :: after)
    (hc : X.Consistent) (hfree : PinFree before)
    (hr : T.event before.length = r)
    (hclear : ∀ {d N}, P.clearOf d N → S.act c N → N = k) :
    ∀ {N}, P.nonAnn N → S.act c N → X.sb N r → N = k ∨ X.sb N k := by
  have hrlen : before.length < actions.length := by rw [shape]; simp
  have hrpos : T.position r = before.length := by
    rw [← hr]
    exact T.event_position _ hrlen
  have hre : T.event (T.position r) = r := by rw [hrpos]; exact hr
  intro N hN hNc hNr
  rcases IndexedPinTrace.write_label P.sourceLabels T hc hNc hN with
    ⟨d, hd⟩ | ⟨hNe, hlabel⟩
  · exact Or.inl (hclear hd hNc)
  · have hNlen : T.position N < actions.length := by
      rcases hlabel with h | h <;> exact (List.getElem?_eq_some_iff.mp h).choose
    have hpos := IndexedPinTrace.position_of_sb P.sourceLabels T hc hNe hre hNlen hNr
    rw [hrpos] at hpos
    simp only [shape, List.getElem?_append_left hpos] at hlabel
    rcases hlabel with h | h
    · exact ((hfree .w2 (List.mem_of_getElem? h)).1 rfl).elim
    · exact ((hfree .w7 (List.mem_of_getElem? h)).2 rfl).elim

/-- Every ordinary API return reads the raw UNPINNED word. This covers all
returns inside any finite registration history, not only its last call.
The source trace proves the quiet interval; no pin balance, no return-read
value and no assertion success is a premise. -/
theorem source_return_reads_zero (P : PinProtocol X S) (calls : List CallExecution)
    {c g k r p : Ev} {before after : List Action}
    (shape : sourceHistory calls = before ++ .returnCall :: after)
    (T : IndexedPinTrace P.sourceLabels c (sourceHistory calls))
    (hc : X.Consistent) (B : RegistrationZeroPrefix P c k)
    (hreg : S.regOf c g) (hgr : X.sb g r) (hkr : X.hb k r)
    (hr : T.event before.length = r) (hrnw : ¬ X.isW r)
    (hrl : X.loc r = some P.pin) (hrf : X.rf p r)
    (hdecode : P.pinVal 0 = 0)
    (unpin_zero : ∀ {w u}, P.unpinOf w u → X.wval u = 0)
    (hclear : ∀ {d N}, P.clearOf d N → S.act c N → N = k)
    (guard : ∀ {a}, P.isAnnounce a → P.pinVal (X.rval a) ≠ 0) : X.rval r = 0 := by
  have hrlen : before.length < (sourceHistory calls).length := by rw [shape]; simp
  have hrc : S.act c r := by rw [← hr]; exact T.owned _ hrlen
  have hrpos : T.position r = before.length := by rw [← hr]; exact T.event_position _ hrlen
  have hre : T.event (T.position r) = r := by rw [hrpos]; exact hr
  have shape' : sourceHistory calls ++ [.releaseClaim] =
      before ++ .returnCall :: (after ++ [.releaseClaim]) := by
    rw [shape, List.append_assoc]
    rfl
  rcases source_history_entry_origin calls (Or.inr (Or.inr rfl)) shape' with
    hfree | ⟨start, tail, hb, htail⟩
  · have hquiet : ∀ {N}, P.nonAnn N → S.act c N → X.sb N r → N = k ∨ X.sb N k :=
      IndexedPinTrace.quiet_before_first_pass P T shape hc hfree hr hclear
    exact (registration_entry_reads_zero P hc B hreg hgr hrc hrl hrf hkr hdecode
      (registration_entry_prefix P hrc hrnw hquiet) guard).1
  · have hshape : sourceHistory calls = start ++ .w7 :: (tail ++ .returnCall :: after) := by
      rw [shape, hb, List.append_assoc]
      rfl
    have hulen : start.length < (sourceHistory calls).length := by rw [hshape]; simp
    have hlabel : (sourceHistory calls)[start.length]? = some .w7 := by
      rw [hshape, List.getElem?_append_right (Nat.le_refl _)]
      simp
    obtain ⟨w, hw, hu⟩ := T.event_w7 hlabel
    have hup : T.position (T.event start.length) = start.length := T.event_position _ hulen
    have hlt : start.length < before.length := by rw [hb]; simp
    have hur : X.sb (T.event start.length) r := by
      rw [← hr]
      exact T.event_order _ _ hlt hrlen
    apply source_entry_after_w7_reads_zero P T hc hw hu (T.owned _ hulen)
      (unpin_zero hu) hdecode hur hre hrc hrnw hrl hrf
    · intro j hj₁ hj₂
      rw [hup] at hj₁
      rw [hrpos, hb] at hj₂
      have hj₂' : j < start.length + 1 + tail.length := by simp only [List.length_append, List.length_cons] at hj₂; omega
      rw [hshape]
      exact PinFree.interval htail hj₁ hj₂'
    · exact guard

end Sgc.Execution
