import SgcProofs.Slot
import SgcProofs.Rotation

/-!
# PN1, PN2 and QU1: the pin

One slot's `pinned_epoch` word. It is written by the registrant (the pin
store W2 and the unpin W7), by a stealer after it registers (the clear P6,
which stands in for a dead registrant's W7), and by rotations that flag a
blocking pin (the announce R3A, inside R3's wait loop: a relaxed
read-modify-write that keeps the pinned epoch).

* `nonAnn_facts`, `nonAnn_serial`, `head`, `acquire_sees` (PN1): the
  non-announce writes are release writes and serial, and an acquire load
  that reads a non-announce write `N`, an announce over it, or anything
  later, is ordered after `N`;
* `attempt_before` (PN2): everything a store attempt does from W2 to W7
  is, or happens before, every later non-announce write to its pin. With
  chained steals that write may be the P6 of a steal of a reaper's claim,
  not of the attempt's registration: no claim is made about which write
  comes next;
* `quiescence_core`, `quiescence` (QU1, one scan load): if a scan load of
  the rotation that reuses the attempt's arena passes, everything the
  attempt did happens before it, and so before the reset R4.
-/

namespace Sgc
namespace Execution

variable {Ev Loc : Type}

/-- The events on one slot's pin. -/
structure PinProtocol (X : Execution Ev Loc) {Lv : Liveness X} (S : SlotProtocol X Lv) where
  pin : Loc
  pinInit : Ev
  /-- The pinned epoch of a pin value, without the `ROTATION_WAITING` flag. -/
  pinVal : Nat → Nat
  /-- R3A: a rotation's announce. -/
  isAnnounce : Ev → Prop
  /-- `w2Of c w`: `w` is a pin store W2 by registration `c`. -/
  w2Of : Ev → Ev → Prop
  /-- `unpinOf w u`: `u` is the unpin W7 that ends the attempt pinned by `w`. -/
  unpinOf : Ev → Ev → Prop
  /-- `clearOf c k`: `k` is the clear P6 by the steal `c`. -/
  clearOf : Ev → Ev → Prop
  pinInit_w : X.isW pinInit
  pinInit_loc : X.loc pinInit = some pin
  /-- Assumption A1. -/
  pinInit_first : ∀ {e}, X.isW e → X.loc e = some pin → e ≠ pinInit → X.hb pinInit e
  pin_writes : ∀ {e}, X.isW e → X.loc e = some pin →
    e = pinInit ∨ isAnnounce e ∨
    ((∃ c, w2Of c e) ∨ (∃ w, unpinOf w e) ∨ (∃ c, clearOf c e))
  /-- An announce is a read-modify-write that keeps the pinned epoch. -/
  ann_rmw : ∀ {a}, isAnnounce a → ∃ w, X.rf w a ∧ pinVal (X.wval a) = pinVal (X.wval w)
  ann_not : ∀ {a}, isAnnounce a →
    ¬ ((∃ c, w2Of c a) ∨ (∃ w, unpinOf w a) ∨ (∃ c, clearOf c a))
  init_not : ¬ ((∃ c, w2Of c pinInit) ∨ (∃ w, unpinOf w pinInit) ∨ (∃ c, clearOf c pinInit))
  w2_act : ∀ {c w}, w2Of c w → S.act c w
  w2_w : ∀ {c w}, w2Of c w → X.isW w
  w2_loc : ∀ {c w}, w2Of c w → X.loc w = some pin
  /-- W2 is a release store. -/
  w2_isRel : ∀ {c w}, w2Of c w → X.isRel w
  unpin_w2 : ∀ {w u}, unpinOf w u → ∃ c, w2Of c w ∧ S.act c u
  unpin_w : ∀ {w u}, unpinOf w u → X.isW u
  unpin_loc : ∀ {w u}, unpinOf w u → X.loc u = some pin
  /-- W7 is a release swap (the swap serves only the `ROTATION_WAITING`
  wake; a release store would do for safety). -/
  unpin_isRel : ∀ {w u}, unpinOf w u → X.isRel u
  clear_act : ∀ {c k}, clearOf c k → S.act c k
  clear_w : ∀ {c k}, clearOf c k → X.isW k
  clear_loc : ∀ {c k}, clearOf c k → X.loc k = some pin
  /-- P6 is a release store. -/
  clear_isRel : ∀ {c k}, clearOf c k → X.isRel k
  /-- What an attempt does from W2 to W7 is done by its registrant. -/
  att_act : ∀ {c w e}, w2Of c w → (e = w ∨ X.sb w e) →
    (∀ u, unpinOf w u → (e = u ∨ X.sb e u)) → S.act c e
  /-- Code fact F1, the code's structure: after W2, the registrant's next
  write to its pin is the unpin W7 of the same attempt (every exit of
  `reserve()` and `store()` included). RB5, that every return leaves the
  slot unpinned, is not formalised beyond this field. -/
  pin_next : ∀ {c w N}, w2Of c w → S.act c N →
    ((∃ c', w2Of c' N) ∨ (∃ w', unpinOf w' N) ∨ (∃ c', clearOf c' N)) →
    X.sb w N → ∃ u, unpinOf w u ∧ (N = u ∨ X.sb u N)

namespace PinProtocol

variable {X : Execution Ev Loc} {Lv : Liveness X} {S : SlotProtocol X Lv} (P : PinProtocol X S)

/-- A write to the pin other than an announce: W2, W7 or P6. -/
abbrev nonAnn (N : Ev) : Prop :=
  (∃ c, P.w2Of c N) ∨ (∃ w, P.unpinOf w N) ∨ (∃ c, P.clearOf c N)

/-- `inAttempt w e`: the attempt pinned by `w` performs `e`, from W2 to W7. -/
def inAttempt (w e : Ev) : Prop :=
  (e = w ∨ X.sb w e) ∧ ∀ u, P.unpinOf w u → (e = u ∨ X.sb e u)

/-- A non-announce write is a release write made on behalf of a claim. -/
theorem nonAnn_facts {N : Ev} (h : P.nonAnn N) :
    ∃ c, S.isClaim c ∧ S.act c N ∧ X.isW N ∧ X.loc N = some P.pin ∧ X.isRel N := by
  rcases h with ⟨c, h⟩ | ⟨w, h⟩ | ⟨c, h⟩
  · exact ⟨c, S.act_claim (P.w2_act h), P.w2_act h, P.w2_w h, P.w2_loc h, P.w2_isRel h⟩
  · obtain ⟨c, hw2, hact⟩ := P.unpin_w2 h
    exact ⟨c, S.act_claim (P.w2_act hw2), hact, P.unpin_w h, P.unpin_loc h, P.unpin_isRel h⟩
  · exact ⟨c, S.act_claim (P.clear_act h), P.clear_act h, P.clear_w h, P.clear_loc h,
      P.clear_isRel h⟩

theorem not_mo_pinInit (hc : X.Consistent) {e : Ev} (h : X.mo e P.pinInit) : False :=
  not_mo_first hc P.pinInit_w (fun he hl => P.pinInit_first he (hl.trans P.pinInit_loc)) h

/-- **PN1, first half.** The non-announce writes to a pin are serial. -/
theorem nonAnn_serial (hc : X.Consistent) {N N' : Ev} (hN : P.nonAnn N) (hN' : P.nonAnn N') :
    N = N' ∨ X.hb N N' ∨ X.hb N' N := by
  obtain ⟨c, hcC, hact, _⟩ := P.nonAnn_facts hN
  obtain ⟨c', hcC', hact', _⟩ := P.nonAnn_facts hN'
  rcases S.claim_total hc hcC hcC' with heq | h | h
  · subst heq
    rcases S.act_total hact hact' with h | h | h
    · exact Or.inl h
    · exact Or.inr (Or.inl (hb_of_sb h))
    · exact Or.inr (Or.inr (hb_of_sb h))
  · exact Or.inr (Or.inl (S.acts_ordered hc hcC hcC' h hact hact'))
  · exact Or.inr (Or.inr (S.acts_ordered hc hcC' hcC h hact' hact))

/-- Under any pin write lies a *head*: the initialisation or a non-announce
write, followed only by announces. The head has the same pinned epoch, and
every non-announce write at or before the write is at or before its head. -/
theorem head (hc : X.Consistent) (w : Ev) :
    X.isW w → X.loc w = some P.pin →
    ∃ H, (H = P.pinInit ∨ P.nonAnn H) ∧ X.rs H w ∧
      P.pinVal (X.wval H) = P.pinVal (X.wval w) ∧
      ∀ N, P.nonAnn N → (N = w ∨ X.mo N w) → (N = H ∨ X.mo N H) := by
  refine hc.mo_wellFounded.induction
    (C := fun w => X.isW w → X.loc w = some P.pin →
      ∃ H, (H = P.pinInit ∨ P.nonAnn H) ∧ X.rs H w ∧
        P.pinVal (X.wval H) = P.pinVal (X.wval w) ∧
        ∀ N, P.nonAnn N → (N = w ∨ X.mo N w) → (N = H ∨ X.mo N H)) w ?_
  intro w ih hwW hwl
  rcases P.pin_writes hwW hwl with hinit | hann | hna
  · exact ⟨w, Or.inl hinit, rs.refl w, rfl, fun _ _ h => h⟩
  · obtain ⟨w', hrf, hval⟩ := P.ann_rmw hann
    have ⟨hw'W, _, hlw', _⟩ := hc.rf_wf hrf
    obtain ⟨H, hH, hrs, hpv, hle⟩ := ih w' (hc.rmw_mo hrf hwW) hw'W (hlw'.trans hwl)
    refine ⟨H, hH, rs.rmw hrs hrf hwW, hpv.trans hval.symm, fun N hN h => ?_⟩
    rcases h with heq | hm
    · exact absurd (heq ▸ hN) (P.ann_not hann)
    · obtain ⟨_, _, _, hNW, hNl, _⟩ := P.nonAnn_facts hN
      exact hle N hN (rmw_pred_ge hc hrf hNW (hNl.trans (hlw'.trans hwl).symm) hm)
  · exact ⟨w, Or.inr hna, rs.refl w, rfl, fun _ _ h => h⟩

/-- **PN1.** An acquire load that reads a non-announce write `N`, an
announce over it, or any later write, is ordered after `N`. -/
theorem acquire_sees (hc : X.Consistent) {N w r : Ev} (hN : P.nonAnn N)
    (hrf : X.rf w r) (hr : X.isAcq r) (h : N = w ∨ X.mo N w) : X.hb N r := by
  obtain ⟨_, _, _, hNW, hNl, _⟩ := P.nonAnn_facts hN
  have ⟨hwW, _, _, _⟩ := hc.rf_wf hrf
  have hwl : X.loc w = some P.pin := by
    rcases h with heq | hm
    · rw [← heq]; exact hNl
    · exact (hc.mo_wf hm).2.2.1.symm.trans hNl
  obtain ⟨H, hH, hrs, _, hle⟩ := P.head hc w hwW hwl
  have hNH := hle N hN h
  have hHn : P.nonAnn H := by
    rcases hH with heq | h'
    · exfalso
      rcases hNH with h₁ | h₁
      · exact P.init_not (heq ▸ h₁ ▸ hN)
      · exact P.not_mo_pinInit hc (heq ▸ h₁)
    · exact h'
  obtain ⟨_, _, _, hHW, hHl, hHrel⟩ := P.nonAnn_facts hHn
  have hHr : X.hb H r := hb_of_sw (sw.relAcq hHrel hrs hrf hr)
  rcases hNH with heq | hm
  · rw [heq]; exact hHr
  · rcases P.nonAnn_serial hc hN hHn with heq | h₁ | h₁
    · rw [heq]; exact hHr
    · exact hb_trans h₁ hHr
    · exact (hc.mo_irrefl _ (hc.mo_trans hm (coh_ww_at hc hHW hHl hNW hNl h₁))).elim

/-- **PN2.** Everything an attempt does from W2 to W7 is, or happens before,
every later non-announce write to its pin. -/
theorem attempt_before (hc : X.Consistent) {c w N e : Ev} (hw2 : P.w2Of c w)
    (hN : P.nonAnn N) (hwN : X.mo w N) (he : P.inAttempt w e) : e = N ∨ X.hb e N := by
  obtain ⟨cN, hcN, hactN, hNW, hNl, _⟩ := P.nonAnn_facts hN
  have hcC := S.act_claim (P.w2_act hw2)
  have hactw := P.w2_act hw2
  have hacte := P.att_act hw2 he.1 he.2
  rcases S.claim_total hc hcC hcN with heq | h | h
  · subst heq
    rcases S.act_total hactw hactN with heq | hsb | hsb
    · subst heq; exact (hc.mo_irrefl _ hwN).elim
    · -- The same registrant wrote `N` later: it unpinned first.
      obtain ⟨u, hu, hNu⟩ := P.pin_next hw2 hactN hN hsb
      rcases he.2 u hu with heq | hsbe
      · rcases hNu with h₁ | h₁
        · exact Or.inl (heq.trans h₁.symm)
        · exact Or.inr (heq ▸ hb_of_sb h₁)
      · rcases hNu with h₁ | h₁
        · exact Or.inr (h₁ ▸ hb_of_sb hsbe)
        · exact Or.inr (hb_trans (hb_of_sb hsbe) (hb_of_sb h₁))
    · exact (hc.mo_irrefl _
        (hc.mo_trans hwN (coh_ww_at hc hNW hNl (P.w2_w hw2) (P.w2_loc hw2)
          (hb_of_sb hsb)))).elim
  · -- A later registration wrote `N`: SL1.
    exact Or.inr (S.acts_ordered hc hcC hcN h hacte hactN)
  · exact (hc.mo_irrefl _
      (hc.mo_trans hwN (coh_ww_at hc hNW hNl (P.w2_w hw2) (P.w2_loc hw2)
        (S.acts_ordered hc hcN hcC h hactN hactw)))).elim

/-- **QU1, from PN3's conclusion.** If an acquire load `Sc` of the pin reads
`W2` or a later write and returns a pinned epoch other than `W2`'s, then
everything the attempt did happens before it. -/
theorem quiescence_core (hc : X.Consistent) {c W2 p Sc a : Ev} {e : Nat}
    (hw2 : P.w2Of c W2) (hval : P.pinVal (X.wval W2) = e)
    (hrf : X.rf p Sc) (hacq : X.isAcq Sc) (hvis : p = W2 ∨ X.mo W2 p)
    (hpass : P.pinVal (X.rval Sc) ≠ e) (ha : P.inAttempt W2 a) : X.hb a Sc := by
  have hW2n : P.nonAnn W2 := Or.inl ⟨c, hw2⟩
  have ⟨hpW, _, _, _⟩ := hc.rf_wf hrf
  have hpl : X.loc p = some P.pin := by
    rcases hvis with heq | hm
    · rw [heq]; exact P.w2_loc hw2
    · exact (hc.mo_wf hm).2.2.1.symm.trans (P.w2_loc hw2)
  obtain ⟨H, hH, hrs, hpv, hle⟩ := P.head hc p hpW hpl
  have hWH := hle W2 hW2n (hvis.elim (fun h => Or.inl h.symm) Or.inr)
  -- The head is not W2: its pinned epoch is the one `Sc` returned.
  have hHne : H ≠ W2 := fun h => hpass (by rw [hc.rf_val hrf, ← hpv, h, hval])
  have hmoWH : X.mo W2 H := by
    rcases hWH with heq | h
    · exact absurd heq.symm hHne
    · exact h
  have hHn : P.nonAnn H := by
    rcases hH with heq | h'
    · exact (P.not_mo_pinInit hc (heq ▸ hmoWH)).elim
    · exact h'
  obtain ⟨_, _, _, _, _, hHrel⟩ := P.nonAnn_facts hHn
  have hHS : X.hb H Sc := hb_of_sw (sw.relAcq hHrel hrs hrf hacq)
  rcases P.attempt_before hc hw2 hHn hmoWH ha with heq | h
  · rw [heq]; exact hHS
  · exact hb_trans h hHS

/-- **QU1, one scan load.** A writer pinned for epoch `e` whose control-word
load `B0` saw `(stamp e, unsealed)`: if a scan load `Sc` of its pin follows a
`SeqCst` fence `f` that the seal of incarnation `e` happens before, and
returns a pinned epoch other than `e`, everything the attempt did from W2 to
W7 happens before `Sc`. -/
theorem quiescence (hc : X.Consistent) (R : RotationProtocol X)
    {c W2 W3 B0 f Sc u p a : Ev} {e : Nat}
    (hW3 : X.isScFence W3) (hf : X.isScFence f)
    (h_W2_W3 : X.sb W2 W3) (h_W3_B0 : X.sb W3 B0)
    (h_u_B0 : X.rf u B0) (hB0loc : X.loc B0 = some (R.ctlLoc e))
    (hstamp : R.stamp (X.wval u) = e) (hunsealed : ¬ R.isSealed (X.wval u))
    (hseal : ∃ A Z, R.sealOf A Z ∧ R.stamp (X.wval Z) = e ∧ X.hb Z f)
    (h_f_Sc : X.sb f Sc) (h_p_Sc : X.rf p Sc)
    (hw2 : P.w2Of c W2) (hval : P.pinVal (X.wval W2) = e)
    (hSloc : X.loc Sc = some P.pin) (hacq : X.isAcq Sc)
    (hpass : P.pinVal (X.rval Sc) ≠ e) (ha : P.inAttempt W2 a) : X.hb a Sc := by
  have hnot := R.pin_visibility_of_seal hc hW3 hf h_W2_W3 h_W3_B0 h_u_B0 hB0loc hstamp
    hunsealed hseal h_f_Sc h_p_Sc
  have ⟨hpW, _, hlp, hsp⟩ := hc.rf_wf h_p_Sc
  have hvis : p = W2 ∨ X.mo W2 p := by
    rcases hc.mo_total hpW (P.w2_w hw2) (hlp.trans (hSloc.trans (P.w2_loc hw2).symm)) hsp
      with heq | h | h
    · exact Or.inl heq
    · exact absurd h hnot
    · exact Or.inr h
  exact P.quiescence_core hc hw2 hval h_p_Sc hacq hvis hpass ha

/-- **QU1, as `docs/invariants.html` states it:** the attempt's actions
happen before the reset R4 that the scan precedes. -/
theorem quiescence_reset {a Sc r4 : Ev} (h : X.hb a Sc) (hS4 : X.sb Sc r4) : X.hb a r4 :=
  hb_trans h (hb_of_sb hS4)

end PinProtocol
end Execution
end Sgc
