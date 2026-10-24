import SgcProofs.Takeover
import SgcProofs.Reserve

/-!
# Closure lemmas for the HTML claims audit

These lemmas make consequences of the existing protocol theorems explicit.
They do not add protocol assumptions. In particular, the OW1 lemma still
requires the code evidence that `TakeoverEvidence` records.
-/

namespace Sgc
namespace Execution

variable {Ev Loc : Type}

/-- SL1: a claim has at most one successful registration CAS. -/
theorem claim_register_unique {X : Execution Ev Loc} {Lv : Liveness X}
    (hc : X.Consistent) (S : SlotProtocol X Lv) {c g g' : Ev}
    (hg : S.regOf c g) (hg' : S.regOf c g') : g = g' := by
  have hrf := S.reg_reads hg
  have hrf' := S.reg_reads hg'
  rcases hc.mo_total (S.reg_w hg) (S.reg_w hg')
      ((S.reg_loc hg).trans (S.reg_loc hg').symm)
      (some_ne_none (S.reg_loc hg)) with heq | hmo | hmo
  · exact heq
  · exact (rmw_adjacent hc hrf' (hc.rmw_mo hrf (S.reg_w hg)) hmo).elim
  · exact (rmw_adjacent hc hrf (hc.rmw_mo hrf' (S.reg_w hg')) hmo).elim

/-- SL1: a claim has at most one successful P3 release. -/
theorem claim_free_unique {X : Execution Ev Loc} {Lv : Liveness X}
    (hc : X.Consistent) (S : SlotProtocol X Lv) {c x x' : Ev}
    (hx : S.freeOf c x) (hx' : S.freeOf c x') : x = x' := by
  obtain ⟨g, hg, hrf⟩ := S.free_reads hx
  obtain ⟨g', hg', hrf'⟩ := S.free_reads hx'
  have heq := claim_register_unique hc S hg hg'
  subst g'
  rcases hc.mo_total (S.free_w hx) (S.free_w hx')
      ((S.free_loc hx).trans (S.free_loc hx').symm)
      (some_ne_none (S.free_loc hx)) with heq | hmo | hmo
  · exact heq
  · exact (rmw_adjacent hc hrf' (hc.rmw_mo hrf (S.free_w hx)) hmo).elim
  · exact (rmw_adjacent hc hrf (hc.rmw_mo hrf' (S.free_w hx')) hmo).elim

/-- SL1: no state write can occur between a claim and its register. -/
theorem claim_register_adjacent {X : Execution Ev Loc} {Lv : Liveness X}
    (hc : X.Consistent) (S : SlotProtocol X Lv) {c g v : Ev}
    (hg : S.regOf c g) (hcv : X.mo c v) (hvg : X.mo v g) : False :=
  rmw_adjacent hc (S.reg_reads hg) hcv hvg

/-- SL1: no state write can occur between a register and its P3. -/
theorem claim_free_adjacent {X : Execution Ev Loc} {Lv : Liveness X}
    (hc : X.Consistent) (S : SlotProtocol X Lv) {c x : Ev}
    (hx : S.freeOf c x) :
    ∃ g, S.regOf c g ∧ ∀ v, X.mo g v → X.mo v x → False := by
  obtain ⟨g, hg, hrf⟩ := S.free_reads hx
  exact ⟨g, hg, fun v hgv hvx => rmw_adjacent hc hrf hgv hvx⟩

/-- Two successful state RMWs cannot replace the same state write. -/
private theorem same_state_predecessor {X : Execution Ev Loc} (hc : X.Consistent)
    {p x y : Ev} (hx : X.rf p x) (hy : X.rf p y)
    (hxW : X.isW x) (hyW : X.isW y) : x = y := by
  have ⟨_, _, hpx, hsome⟩ := hc.rf_wf hx
  have ⟨_, _, hpy, _⟩ := hc.rf_wf hy
  rcases hc.mo_total hxW hyW (hpx.symm.trans hpy) (hpx ▸ hsome) with h | h | h
  · exact h
  · exact (rmw_adjacent hc hy (hc.rmw_mo hx hxW) h).elim
  · exact (rmw_adjacent hc hx (hc.rmw_mo hy hyW) h).elim

/-- A P4 replaces either the claim itself or its unique register word. -/
private theorem steal_predecessor {X : Execution Ev Loc} {Lv : Liveness X}
    (S : SlotProtocol X Lv) {c x : Ev} (hx : S.stealOf c x) :
    ∃ p, X.rf p x ∧ (p = c ∨ S.regOf c p) := by
  obtain ⟨p, _, _, hrf, _, _, _, _, hcase⟩ := S.steal_ev hx
  rcases hcase with ⟨heq, _⟩ | ⟨hreg, _⟩
  · exact ⟨p, hrf, Or.inl heq⟩
  · exact ⟨p, hrf, Or.inr hreg⟩

/-- A P3 word cannot also be a register word. -/
private theorem free_not_register {X : Execution Ev Loc} {Lv : Liveness X}
    (hc : X.Consistent) (S : SlotProtocol X Lv) {c x c' : Ev}
    (hx : S.freeOf c x) (hg : S.regOf c' x) : False := by
  obtain ⟨g, hreg, hrf⟩ := S.free_reads hx
  have heq := hc.rf_unique hrf (S.reg_reads hg)
  subst g
  exact S.reg_not_claim hreg (S.reg_claim hg)

/-- **SL1(a), local block shape.** Every non-initial state write is a
successful RMW of the preceding state: P1 follows init or P3; P4 follows a
claim or its register; P2/P5 follows its claim; and P3 follows its register.
RMW adjacency makes these the immediate transitions of `mo(state)`. -/
theorem state_write_step {X : Execution Ev Loc} {Lv : Liveness X}
    (S : SlotProtocol X Lv) {w : Ev} (hwW : X.isW w)
    (hwl : X.loc w = some S.st) :
    w = S.init ∨ ∃ p, X.rf p w ∧
      ((S.isFreeClaim w ∧ (p = S.init ∨ ∃ c, S.freeOf c p)) ∨
       (∃ c, S.stealOf c w ∧ (p = c ∨ S.regOf c p)) ∨
       (∃ c, S.regOf c w ∧ p = c) ∨
       (∃ c, S.freeOf c w ∧ ∃ g, S.regOf c g ∧ p = g)) := by
  rcases S.writes hwW hwl with hinit | (hfc | hst) | ⟨c, hreg⟩ | ⟨c, hfree⟩
  · exact Or.inl hinit
  · obtain ⟨p, hrf, hp⟩ := S.fc_reads hfc
    exact Or.inr ⟨p, hrf, Or.inl ⟨hfc, hp⟩⟩
  · obtain ⟨c, hso⟩ := S.steal_of hst
    obtain ⟨p, hrf, hp⟩ := steal_predecessor S hso
    exact Or.inr ⟨p, hrf, Or.inr (Or.inl ⟨c, hso, hp⟩)⟩
  · exact Or.inr ⟨c, S.reg_reads hreg,
      Or.inr (Or.inr (Or.inl ⟨c, hreg, rfl⟩))⟩
  · obtain ⟨g, hreg, hrf⟩ := S.free_reads hfree
    exact Or.inr ⟨g, hrf,
      Or.inr (Or.inr (Or.inr ⟨c, hfree, g, hreg, rfl⟩))⟩

/-- **SL1(a).** A claim cannot have two ending writes, whether they are
both P4s, both P3s, or one of each. -/
theorem claim_ender_unique {X : Execution Ev Loc} {Lv : Liveness X}
    (hc : X.Consistent) (S : SlotProtocol X Lv) {c x y : Ev}
    (hx : S.freeOf c x ∨ S.stealOf c x)
    (hy : S.freeOf c y ∨ S.stealOf c y) : x = y := by
  rcases hx with hx | hx <;> rcases hy with hy | hy
  · exact claim_free_unique hc S hx hy
  · obtain ⟨g, hg, hrf⟩ := S.free_reads hx
    obtain ⟨p, hprf, hp⟩ := steal_predecessor S hy
    rcases hp with rfl | hp
    · have h := same_state_predecessor hc (S.reg_reads hg) hprf
        (S.reg_w hg) (S.claim_w (Or.inr (S.stealOf_claims hy).2))
      subst y
      exact (S.reg_not_claim hg (Or.inr (S.stealOf_claims hy).2)).elim
    · have heq := claim_register_unique hc S hg hp
      subst p
      have h := same_state_predecessor hc hrf hprf
        (S.free_w hx) (S.claim_w (Or.inr (S.stealOf_claims hy).2))
      subst y
      exact (S.free_not_claim hx (Or.inr (S.stealOf_claims hy).2)).elim
  · obtain ⟨g, hg, hrf⟩ := S.free_reads hy
    obtain ⟨p, hprf, hp⟩ := steal_predecessor S hx
    rcases hp with rfl | hp
    · have h := same_state_predecessor hc (S.reg_reads hg) hprf
        (S.reg_w hg) (S.claim_w (Or.inr (S.stealOf_claims hx).2))
      subst x
      exact (S.reg_not_claim hg (Or.inr (S.stealOf_claims hx).2)).elim
    · have heq := claim_register_unique hc S hg hp
      subst p
      have h := same_state_predecessor hc hrf hprf
        (S.free_w hy) (S.claim_w (Or.inr (S.stealOf_claims hx).2))
      subst x
      exact (S.free_not_claim hy (Or.inr (S.stealOf_claims hx).2)).elim
  · obtain ⟨p, hprf, hp⟩ := steal_predecessor S hx
    obtain ⟨q, hqrf, hq⟩ := steal_predecessor S hy
    rcases hp with rfl | hp <;> rcases hq with rfl | hq
    · exact same_state_predecessor hc hprf hqrf
        (S.claim_w (Or.inr (S.stealOf_claims hx).2))
        (S.claim_w (Or.inr (S.stealOf_claims hy).2))
    · have h := same_state_predecessor hc hprf (S.reg_reads hq)
        (S.claim_w (Or.inr (S.stealOf_claims hx).2)) (S.reg_w hq)
      subst x
      exact (S.reg_not_claim hq (Or.inr (S.stealOf_claims hx).2)).elim
    · have h := same_state_predecessor hc (S.reg_reads hp) hqrf
        (S.reg_w hp) (S.claim_w (Or.inr (S.stealOf_claims hy).2))
      subst y
      exact (S.reg_not_claim hp (Or.inr (S.stealOf_claims hy).2)).elim
    · have heq := claim_register_unique hc S hp hq
      subst q
      exact same_state_predecessor hc hprf hqrf
        (S.claim_w (Or.inr (S.stealOf_claims hx).2))
        (S.claim_w (Or.inr (S.stealOf_claims hy).2))

/-- **SL1(a).** A claim that never registered can only be replaced by a
P4 that stole that very claim. -/
theorem unregistered_claim_successor {X : Execution Ev Loc} {Lv : Liveness X}
    (hc : X.Consistent) (S : SlotProtocol X Lv) {c y : Ev}
    (hcC : S.isClaim c) (hyW : X.isW y) (hyl : X.loc y = some S.st)
    (hrf : X.rf c y) (hnoreg : ∀ g, ¬ S.regOf c g) : S.stealOf c y := by
  rcases S.writes hyW hyl with hinit | (hfc | hst) | ⟨c', hg⟩ | ⟨c', hf⟩
  · subst y; exact (S.not_mo_init hc (hc.rmw_mo hrf S.init_w)).elim
  · obtain ⟨p, hprf, hp⟩ := S.fc_reads hfc
    have heq := hc.rf_unique hrf hprf
    subst p
    rcases hp with h | ⟨c', hf⟩
    · subst c; exact (S.init_not_claim hc hcC).elim
    · exact (S.free_not_claim hf hcC).elim
  · obtain ⟨c', hso⟩ := S.steal_of hst
    obtain ⟨p, hprf, hp⟩ := steal_predecessor S hso
    have heq := hc.rf_unique hrf hprf
    subst p
    rcases hp with h | h
    · subst c'; exact hso
    · exact (S.reg_not_claim h hcC).elim
  · have heq := hc.rf_unique hrf (S.reg_reads hg)
    subst c'
    exact (hnoreg y hg).elim
  · obtain ⟨g, hg, hgrf⟩ := S.free_reads hf
    have heq := hc.rf_unique hrf hgrf
    subst g
    exact (S.reg_not_claim hg hcC).elim

/-- **SL1(a).** The write following a P3 is a new P1 claim. A P4 ender
is itself the next claim. -/
theorem free_successor_claim {X : Execution Ev Loc} {Lv : Liveness X}
    (hc : X.Consistent) (S : SlotProtocol X Lv) {c x y : Ev}
    (hx : S.freeOf c x) (hyW : X.isW y) (hyl : X.loc y = some S.st)
    (hrf : X.rf x y) : S.isFreeClaim y := by
  rcases S.writes hyW hyl with hinit | (hfc | hst) | ⟨c', hg⟩ | ⟨c', hf⟩
  · subst y; exact (S.not_mo_init hc (hc.rmw_mo hrf S.init_w)).elim
  · exact hfc
  · obtain ⟨c', hso⟩ := S.steal_of hst
    obtain ⟨p, hprf, hp⟩ := steal_predecessor S hso
    have heq := hc.rf_unique hrf hprf
    subst p
    rcases hp with h | h
    · subst c'; exact (S.free_not_claim hx (S.stealOf_claims hso).1).elim
    · exact (free_not_register hc S hx h).elim
  · have heq := hc.rf_unique hrf (S.reg_reads hg)
    subst c'
    exact (S.free_not_claim hx (S.reg_claim hg)).elim
  · obtain ⟨g, hg, hgrf⟩ := S.free_reads hf
    have heq := hc.rf_unique hrf hgrf
    subst g
    exact (free_not_register hc S hx hg).elim

/-- OW1's no-O4 consequence from the same evidence used for the handoff. -/
theorem no_release_of_takeover_evidence {X : Execution Ev Loc} {Lv : Liveness X}
    (hc : X.Consistent) (O : OwnerEvents X) (S : SlotProtocol X Lv) {A T c : Ev}
    (ev : TakeoverEvidence O S A T c) (hA : O.isAcqCas A)
    (hT : O.isAcqCas T) (hrf : X.rf A T) : ∀ r, ¬ O.relOf A r := by
  intro r hr
  have hu : O.under A r :=
    ⟨Or.inr (O.rel_sb hr), fun r' hr' => Or.inl (O.rel_unique hr hr')⟩
  have hAr : X.mo A r :=
    coh_ww_at hc (O.acq_w hA) (O.acq_loc hA) (O.rel_w hr) (O.rel_loc hr)
      (hb_of_sb (O.rel_sb hr))
  have hrT : X.mo r T :=
    coh_ww_at hc (O.rel_w hr) (O.rel_loc hr) (O.acq_w hT) (O.acq_loc hT)
      (takeover_of_evidence hc O S ev r hu)
  exact rmw_adjacent hc hrf hAr hrT

/-- OW1: the takeover follows every action of the replaced registration,
including actions outside its owner tenure. -/
theorem registration_actions_before_takeover
    {X : Execution Ev Loc} {Lv : Liveness X}
    (hc : X.Consistent) (O : OwnerEvents X) (S : SlotProtocol X Lv)
    {A T c : Ev} (ev : TakeoverEvidence O S A T c) :
    ∀ e, S.act c e → X.hb e T := by
  obtain ⟨hcC, _, _, hcase⟩ := ev
  intro e hact
  rcases hcase with ⟨cs, hso, hsb⟩ | ⟨g, q, w, hreg, hgq, hwq, hqacq, hql, hne, hsb⟩
  · exact hb_trans
      (S.serial hc cs (Or.inr (S.stealOf_claims hso).2) c hcC
        (S.mo_of_stealOf hc hso) e hact)
      (hb_of_sb hsb)
  · rcases coh_wr hc (S.reg_w hreg) ((S.reg_loc hreg).trans hql.symm)
      hgq hwq with heq | hmo
    · exact (hne heq).elim
    · obtain ⟨E, hE, hle⟩ := S.ender_before hc hreg w hmo
      rcases hE with hf | hso
      · have hEq : X.hb E q :=
          S.hb_release_acq hc (S.free_w hf) (S.free_loc hf)
            (S.free_isRel hf) hwq hqacq hle
        exact hb_trans (sbr_hb (S.act_before_free hf hact) hEq) (hb_of_sb hsb)
      · have hst := (S.stealOf_claims hso).2
        have hEq : X.hb E q :=
          S.hb_release_acq hc (S.claim_w (Or.inr hst))
            (S.claim_loc (Or.inr hst)) (S.steal_isRel hst) hwq hqacq hle
        exact hb_trans
          (hb_trans (S.serial hc E (Or.inr hst) c hcC
            (S.mo_of_stealOf hc hso) e hact) hEq)
          (hb_of_sb hsb)

/-- OW2: an O2 is the acquiring CAS that reads NONE or a release O4. -/
def OwnerEvents.isO2 {X : Execution Ev Loc} (O : OwnerEvents X) (A : Ev) : Prop :=
  O.isAcqCas A ∧ ∃ w, X.rf w A ∧ (w = O.init ∨ ∃ B, O.relOf B w)

/-- OW2: an O3 is the takeover CAS that reads an acquiring CAS. -/
def OwnerEvents.isO3 {X : Execution Ev Loc} (O : OwnerEvents X) (A : Ev) : Prop :=
  O.isAcqCas A ∧ ∃ w, X.rf w A ∧ O.isAcqCas w

/-- OW2: every successful acquiring CAS has one of the two read sources. -/
theorem owner_acq_kind {X : Execution Ev Loc} (hc : X.Consistent)
    (O : OwnerEvents X) {A : Ev} (hA : O.isAcqCas A) :
    O.isO2 A ∨ O.isO3 A := by
  obtain ⟨w, hrf⟩ := O.acq_reads hA
  have ⟨hwW, _, hlw, _⟩ := hc.rf_wf hrf
  rcases O.writes hwW (hlw.trans (O.acq_loc hA)) with hinit | hacq | ⟨B, hrel⟩
  · exact Or.inl ⟨hA, w, hrf, Or.inl hinit⟩
  · exact Or.inr ⟨hA, w, hrf, hacq⟩
  · exact Or.inl ⟨hA, w, hrf, Or.inr ⟨B, hrel⟩⟩

/-- OW2(a): each acquiring CAS reads its immediate predecessor in the
owner word's modification order, an initial value, release, or CAS. -/
theorem owner_acq_predecessor {X : Execution Ev Loc} (hc : X.Consistent)
    (O : OwnerEvents X) {A : Ev} (hA : O.isAcqCas A) :
    ∃ w, X.rf w A ∧ X.mo w A ∧
      (w = O.init ∨ (∃ B, O.relOf B w) ∨ O.isAcqCas w) ∧
      ∀ v, X.mo w v → X.mo v A → False := by
  obtain ⟨w, hrf⟩ := O.acq_reads hA
  have ⟨hwW, _, hlw, _⟩ := hc.rf_wf hrf
  have hk := O.writes hwW (hlw.trans (O.acq_loc hA))
  refine ⟨w, hrf, hc.rmw_mo hrf (O.acq_w hA), ?_, ?_⟩
  · rcases hk with hi | ha | ⟨B, hr⟩
    · exact Or.inl hi
    · exact Or.inr (Or.inr ha)
    · exact Or.inr (Or.inl ⟨B, hr⟩)
  · intro v h₁ h₂
    exact rmw_adjacent hc hrf h₁ h₂

/-- EP1's monotonicity for two sequenced epoch loads. -/
theorem epoch_loads_monotone {X : Execution Ev Loc} (hc : X.Consistent)
    (P : RotationProtocol X) {w w' q q' : Ev}
    (hq : X.loc q = some P.ep) (hq' : X.loc q' = some P.ep)
    (hqq' : X.sb q q') (hrf : X.rf w q) (hrf' : X.rf w' q') :
    X.rval q ≤ X.rval q' := by
  rcases coh_rr hc (hq.trans hq'.symm) (hb_of_sb hqq') hrf hrf' with heq | hmo
  · rw [hc.rf_val hrf, hc.rf_val hrf', heq]
    exact Nat.le_refl _
  · rw [hc.rf_val hrf, hc.rf_val hrf']
    exact Nat.le_of_lt (P.epoch_mono hc w' (hc.rf_wf hrf').1
      ((hc.rf_wf hrf').2.2.1.trans hq') w hmo)

/-- EP1's published epoch is visible to every later tenure's R0. -/
theorem later_recheck_after_publication {X : Execution Ev Loc} (hc : X.Consistent)
    (P : RotationProtocol X) {A B p q qA : Ev}
    (hqA : P.r0Of A qA) (h5 : P.r5Of A p) (hq : P.r0Of B q)
    (hAB : X.mo A B) : X.rval qA + 1 ≤ X.rval q := by
  obtain ⟨q₀, hq₀, _, hval⟩ := P.r5_r0 h5
  have heq := P.r0_unique hqA hq₀
  subst q₀
  rw [← hval]
  exact P.r0_after_r5 hc h5 hq hAB

/-- SP1: B1 follows the base of the control value read by B0. -/
theorem b1_after_b0_base {X : Execution Ev Loc} (hc : X.Consistent)
    (C : CacheProtocol X) {i : C.ι} {w b : Ev} (hw : C.isAttempt i w)
    (hb : (C.pins i).inAttempt w b) (hb1 : C.R.isB1 b) :
    ∃ u base, (∃ l, X.rf u l ∧ X.sb l b) ∧
      (C.R.isCtlInit base ∨ ∃ A, C.R.r4Of A base) ∧
      X.mo base b ∧ C.R.stamp (X.wval base) = C.attEpoch w := by
  obtain ⟨hbW, hbl, l, u, hlb, hul, hll, hstu⟩ := C.b1_after_b0 hw hb hb1
  have ⟨huW, _, hlu, _⟩ := hc.rf_wf hul
  have hub : X.mo u b := coh_rw hc hbW (hbl.trans hll.symm) (hb_of_sb hlb) hul
  obtain ⟨base, hbase, hbaseu, hall⟩ :=
    C.ctl_base hc u huW ⟨C.attEpoch w, hlu.trans hll⟩
  have hbaseb : X.mo base b := by
    rcases hbaseu with heq | hmo
    · rw [heq]; exact hub
    · exact hc.mo_trans hmo hub
  exact ⟨u, base, ⟨l, hul, hlb⟩, hbase, hbaseb,
    (hall u hbaseu (Or.inl rfl)).symm.trans hstu⟩

/-- SP1: B1 precedes every reset that stamps the next use of its arena. -/
theorem b1_before_next_cycle_reset {X : Execution Ev Loc} (hc : X.Consistent)
    (C : CacheProtocol X) {i : C.ι} {w b B w4 qB : Ev}
    (hw : C.isAttempt i w) (hb : (C.pins i).inAttempt w b)
    (hb1 : C.R.isB1 b) (h4 : C.R.r4Of B w4) (hqB : C.R.r0Of B qB)
    (hst : X.rval qB + 1 = C.attEpoch w + 3) : X.mo b w4 := by
  obtain ⟨hbW, hbl, _, _, _, _, _, _⟩ := C.b1_after_b0 hw hb hb1
  have hqge : C.attEpoch w + 2 ≤ X.rval qB := by omega
  have hhb := C.attempt_before_resets hc hw h4 hqB hqge hb
  have hw4l : X.loc w4 = some (C.R.ctlLoc (C.attEpoch w)) := by
    rw [C.r4_loc h4 hqB, hst, C.ctl_cycle]
  exact coh_ww hc hbW (C.R.r4_w h4) (hbl.trans hw4l.symm)
    (some_ne_none hbl) hhb

/-- SP1: every reset of the current incarnation precedes B1 in `mo`. -/
theorem current_reset_before_b1 {X : Execution Ev Loc} (hc : X.Consistent)
    (C : CacheProtocol X) {i : C.ι} {w b B w4 qB : Ev}
    (hw : C.isAttempt i w) (hb : (C.pins i).inAttempt w b)
    (hb1 : C.R.isB1 b) (h4 : C.R.r4Of B w4) (hqB : C.R.r0Of B qB)
    (hst : X.rval qB + 1 = C.attEpoch w) : X.mo w4 b := by
  obtain ⟨hbW, hbl, _, _, _, _, _, _⟩ := C.b1_after_b0 hw hb hb1
  have hhb := C.r4_before_att hc hw hb.1 h4 hqB hst
  have hw4l : X.loc w4 = some (C.R.ctlLoc (C.attEpoch w)) := by
    rw [C.r4_loc h4 hqB, hst]
  exact coh_ww hc (C.R.r4_w h4) hbW (hw4l.trans hbl.symm)
    (some_ne_none hw4l) hhb

/-- SP5: all stores to one record word are unique within an incarnation and
ordered by epoch across incarnations. -/
theorem record_word_serial {X : Execution Ev Loc} (hc : X.Consistent)
    (C : CacheProtocol X) (Rv : ReserveProtocol X C)
    {i j : C.ι} {w w' x x' : Ev} {l : Loc}
    (hw : C.isAttempt i w) (hx : (C.pins i).inAttempt w x)
    (hw' : C.isAttempt j w') (hx' : (C.pins j).inAttempt w' x')
    (hxW : X.isW x) (hx'W : X.isW x')
    (hxl : X.loc x = some l) (hx'l : X.loc x' = some l)
    (hl : Rv.isRecord l) :
    x = x' ∨ (C.attEpoch w + 3 ≤ C.attEpoch w' ∧ X.mo x x') ∨
      (C.attEpoch w' + 3 ≤ C.attEpoch w ∧ X.mo x' x) := by
  have har := Rv.rec_arena hw hx hxW hxl hl
  have har' := Rv.rec_arena hw' hx' hx'W hx'l hl
  rcases C.ctl_eq (har.symm.trans har') with heq | hle | hle
  · exact Or.inl (Rv.record_write_once hc hw hx hw' hx' hxW hx'W hxl hx'l hl heq)
  · exact Or.inr (Or.inl ⟨hle, C.arena_writes_ordered hc hw hw' hle hx hx'
      hxW hx'W (hxl.trans hx'l.symm) (some_ne_none hxl)⟩)
  · exact Or.inr (Or.inr ⟨hle, C.arena_writes_ordered hc hw' hw hle hx' hx
      hx'W hxW (hx'l.trans hxl.symm) (some_ne_none hx'l)⟩)

/-- EP2: a successful seal CAS is unique for its epoch stamp. -/
theorem seal_unique {X : Execution Ev Loc} (hc : X.Consistent)
    (C : CacheProtocol X) {A A' Z Z' : Ev}
    (hZ : C.R.sealOf A Z) (hZ' : C.R.sealOf A' Z')
    (hst : C.R.stamp (X.wval Z) = C.R.stamp (X.wval Z')) : Z = Z' := by
  have no_later : ∀ {A B z z' : Ev}, C.R.sealOf A z → C.R.sealOf B z' →
      C.R.stamp (X.wval z) = C.R.stamp (X.wval z') → ¬ X.mo z z' := by
    intro A B z z' hz hz' hst' hmo
    obtain ⟨q, _, hval, hlz⟩ := C.R.seal_r0 hz
    obtain ⟨q', _, hval', hlz'⟩ := C.R.seal_r0 hz'
    have hv : X.rval q = X.rval q' := hval.symm.trans (hst'.trans hval')
    have hloc : X.loc z = X.loc z' := by rw [hlz, hlz', hv]
    obtain ⟨pred, hrf, hpredst, hunsealed⟩ := C.seal_rmw hz'
    have ⟨_, _, hlpred, _⟩ := hc.rf_wf hrf
    rcases rmw_pred_ge hc hrf (C.R.seal_w hz) (hloc.trans hlpred.symm) hmo with heq | hprev
    · exact hunsealed (heq ▸ C.R.seal_sealed hz)
    · exact hunsealed (C.R.seal_persists hc hz pred hprev (hst'.trans hpredst).symm)
  obtain ⟨q, _, hs, hlZ⟩ := C.R.seal_r0 hZ
  obtain ⟨q', _, hs', hlZ'⟩ := C.R.seal_r0 hZ'
  have hv : X.rval q = X.rval q' := hs.symm.trans (hst.trans hs')
  have hloc : X.loc Z = X.loc Z' := by rw [hlZ, hlZ', hv]
  rcases hc.mo_total (C.R.seal_w hZ) (C.R.seal_w hZ') hloc
      (some_ne_none hlZ) with heq | hmo | hmo
  · exact heq
  · exact (no_later hZ hZ' hst hmo).elim
  · exact (no_later hZ' hZ hst.symm hmo).elim

/-- EP2: a load of an unsealed value with the seal's stamp precedes it in
coherence order. -/
theorem unsealed_load_before_seal {X : Execution Ev Loc} (hc : X.Consistent)
    (P : RotationProtocol X) {A Z w l : Ev}
    (hZ : P.sealOf A Z) (hrf : X.rf w l)
    (hloc : X.loc l = X.loc Z)
    (hst : P.stamp (X.wval w) = P.stamp (X.wval Z))
    (hunsealed : ¬ P.isSealed (X.wval w)) (hne : l ≠ Z) : X.cob l Z := by
  have ⟨hwW, _, hlw, hsome⟩ := hc.rf_wf hrf
  rcases hc.mo_total hwW (P.seal_w hZ) (hlw.trans hloc)
      hsome with heq | hmo | hmo
  · exact (hunsealed (heq ▸ P.seal_sealed hZ)).elim
  · exact cob_of_rb ⟨hne, w, hrf, hmo⟩
  · exact (hunsealed (P.seal_persists hc hZ w hmo hst)).elim

/-- EP2(iii) for an ordinary control-word load: the load cannot be the
successful sealing RMW, so the coherence edge is strict. -/
theorem ordinary_unsealed_load_before_seal {X : Execution Ev Loc} (hc : X.Consistent)
    (P : RotationProtocol X) {A Z w l : Ev}
    (hZ : P.sealOf A Z) (hrf : X.rf w l)
    (hloc : X.loc l = X.loc Z)
    (hst : P.stamp (X.wval w) = P.stamp (X.wval Z))
    (hunsealed : ¬ P.isSealed (X.wval w))
    (hload : ¬ X.isW l) : X.cob l Z :=
  unsealed_load_before_seal hc P hZ hrf hloc hst hunsealed
    (by intro heq; subst heq; exact hload (P.seal_w hZ))

/-- EP2(iii), with the modification-order edge stated explicitly. -/
theorem unsealed_read_before_seal {X : Execution Ev Loc} (hc : X.Consistent)
    (P : RotationProtocol X) {A Z w l : Ev}
    (hZ : P.sealOf A Z) (hrf : X.rf w l)
    (hloc : X.loc l = X.loc Z)
    (hst : P.stamp (X.wval w) = P.stamp (X.wval Z))
    (hunsealed : ¬ P.isSealed (X.wval w))
    (hload : ¬ X.isW l) : X.mo w Z ∧ X.cob l Z := by
  have ⟨hwW, _, hlw, hsome⟩ := hc.rf_wf hrf
  have hmo : X.mo w Z := by
    rcases hc.mo_total hwW (P.seal_w hZ) (hlw.trans hloc) hsome with heq | h | h
    · exact (hunsealed (heq ▸ P.seal_sealed hZ)).elim
    · exact h
    · exact (hunsealed (P.seal_persists hc hZ w h hst)).elim
  exact ⟨hmo, ordinary_unsealed_load_before_seal hc P hZ hrf hloc hst hunsealed hload⟩

/-- EP2: every reset of stamp `e` precedes an event under a tenure that
rechecked at least `e`. -/
theorem reset_before_later_tenure {X : Execution Ev Loc} (hc : X.Consistent)
    (P : RotationProtocol X) {A B w4 q q' f : Ev}
    (h4 : P.r4Of A w4) (hq : P.r0Of A q)
    (hq' : P.r0Of B q') (hf : P.toOwnerProtocol.under B f)
    (hstamp : P.stamp (X.wval w4) = X.rval q + 1)
    (hlater : P.stamp (X.wval w4) ≤ X.rval q') : X.hb w4 f := by
  have hAB : X.mo A B := P.tenure_lt_of_r0_lt hc hq hq' (by omega)
  exact P.toOwnerProtocol.tenures_ordered hc (P.r4_acq h4) (P.r0_acq hq')
    hAB (P.r4_under h4) hf

end Execution
end Sgc
