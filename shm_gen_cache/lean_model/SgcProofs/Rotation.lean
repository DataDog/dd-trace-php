import SgcProofs.Ownership
import SgcProofs.PinVisibility

/-!
# EP1 to EP3, the epoch and the control words, and PN3 from a seal

`RotationProtocol` extends the owner protocol with what a tenure does on the
global epoch and on the arena control words:

* R0, a load of the epoch; R5, a store of that value plus one;
* R1, the seal of the current arena's control word (a read-modify-write
  that keeps the stamp and sets the sealed bit);
* R4, the reset of the next arena's control word;
* B1, the `fetch_add` any writer makes on a control word, which changes
  neither the stamp nor the sealed bit.

The stamp of a control word is modelled as the full epoch, not its low 32
bits: this is assumption A4 (within one operation a 32-bit stamp stands
for one full epoch).

Proved here for every consistent execution:

* `epoch_mono`, `ep_val_ge`, `r0_ge`, `r0_mono`, `r0_after_r5`,
  `tenure_lt_of_r0_lt` (EP1): the epoch's writes carry strictly increasing
  values along the modification order, so each value is written once, and
  a tenure that rechecked a smaller epoch is an earlier tenure
  (`CacheProtocol.publisher_exists` adds that no value is skipped);
* `seal_persists`, `sealed_has_seal` (EP2): once an incarnation is sealed,
  every later control-word value with its stamp is sealed, and every sealed
  value has a seal. A load that saw the incarnation unsealed therefore read
  a write older than the seal;
* `seal_before_under`, `seal_before_publish`, `seal_before_reset`,
  `seal_before_scan` (EP3): a tenure that publishes the next epoch does
  so, and resets the next arena, after the current incarnation's seal; and
  the tenure two epochs later acts under ownership after it. The form the
  code uses, for the W3 of the rotator's own last reservation pass, is
  `CacheProtocol.seal_before_fence`;
* `pin_visibility_of_seal`: PN3 from a seal that happens before the fence;
  `pin_visibility_closed`: the same for a variant in which the fence is
  made under the ownership of the tenure two epochs later.
-/

namespace Sgc
namespace Execution

variable {Ev Loc : Type}

/-- The events of a rotation, and what the code guarantees about them. -/
structure RotationProtocol (X : Execution Ev Loc) extends OwnerProtocol X where
  /-- The global epoch word. -/
  ep : Loc
  /-- Its published initial value, `INITIAL_EPOCH`. -/
  epInit : Ev
  /-- `r0Of A q`: `q` is the epoch recheck R0 of tenure `A`. -/
  r0Of : Ev → Ev → Prop
  /-- `r5Of A p`: `p` is the epoch publication R5 of tenure `A`. -/
  r5Of : Ev → Ev → Prop
  /-- The control word of the arena that serves a given epoch. -/
  ctlLoc : Nat → Loc
  /-- The epoch stamp of a control-word value. -/
  stamp : Nat → Nat
  /-- The sealed bit of a control-word value. -/
  isSealed : Nat → Prop
  /-- The initialisation of a control word. -/
  isCtlInit : Ev → Prop
  /-- A reservation `fetch_add` (B1). -/
  isB1 : Ev → Prop
  /-- `sealOf A Z`: `Z` is the successful seal R1 of tenure `A`. -/
  sealOf : Ev → Ev → Prop
  /-- `r4Of A w`: `w` is the control-word reset R4 of tenure `A`. -/
  r4Of : Ev → Ev → Prop
  -- The epoch word.
  epInit_w : X.isW epInit
  epInit_loc : X.loc epInit = some ep
  epInit_val : X.wval epInit = 2
  /-- Assumption A1. -/
  epInit_first : ∀ {e}, X.isW e → X.loc e = some ep → e ≠ epInit → X.hb epInit e
  /-- Only R5 writes the epoch. -/
  ep_writes : ∀ {e}, X.isW e → X.loc e = some ep → e = epInit ∨ ∃ A, r5Of A e
  r0_acq : ∀ {A q}, r0Of A q → isAcqCas A
  r0_under : ∀ {A q}, r0Of A q → toOwnerProtocol.under A q
  r0_loc : ∀ {A q}, r0Of A q → X.loc q = some ep
  r0_reads : ∀ {A q}, r0Of A q → ∃ w, X.rf w q
  /-- A tenure rechecks the epoch once. -/
  r0_unique : ∀ {A q q'}, r0Of A q → r0Of A q' → q = q'
  r5_acq : ∀ {A p}, r5Of A p → isAcqCas A
  r5_under : ∀ {A p}, r5Of A p → toOwnerProtocol.under A p
  r5_w : ∀ {A p}, r5Of A p → X.isW p
  r5_loc : ∀ {A p}, r5Of A p → X.loc p = some ep
  /-- A tenure publishes at most once. -/
  r5_unique : ∀ {A p p'}, r5Of A p → r5Of A p' → p = p'
  /-- R5 stores the value R0 read, plus one, after R0. -/
  r5_r0 : ∀ {A p}, r5Of A p → ∃ q, r0Of A q ∧ X.sb q p ∧ X.wval p = X.rval q + 1
  -- The control words.
  /-- Only these four kinds of event write a control word. -/
  ctl_writes : ∀ {e n}, X.isW e → X.loc e = some (ctlLoc n) →
    isCtlInit e ∨ isB1 e ∨ (∃ A, sealOf A e) ∨ (∃ A, r4Of A e)
  /-- Assumption A1. -/
  ctlInit_first : ∀ {i e}, isCtlInit i → X.isW e → X.loc e = X.loc i → e ≠ i → X.hb i e
  /-- A control word starts as `(0, sealed)` or `(2, unsealed)`. -/
  ctlInit_val : ∀ {i}, isCtlInit i → isSealed (X.wval i) → stamp (X.wval i) = 0
  /-- B1 is a read-modify-write that keeps the stamp and the sealed bit. -/
  b1_rmw : ∀ {e}, isB1 e → ∃ w, X.rf w e ∧
    stamp (X.wval e) = stamp (X.wval w) ∧ (isSealed (X.wval e) ↔ isSealed (X.wval w))
  seal_acq : ∀ {A Z}, sealOf A Z → isAcqCas A
  seal_under : ∀ {A Z}, sealOf A Z → toOwnerProtocol.under A Z
  seal_w : ∀ {A Z}, sealOf A Z → X.isW Z
  seal_sealed : ∀ {A Z}, sealOf A Z → isSealed (X.wval Z)
  /-- R1 seals the arena of the epoch R0 read, keeping that stamp. -/
  seal_r0 : ∀ {A Z}, sealOf A Z → ∃ q, r0Of A q ∧
    stamp (X.wval Z) = X.rval q ∧ X.loc Z = some (ctlLoc (X.rval q))
  /-- A tenure seals before it publishes. -/
  seal_before_r5 : ∀ {A Z p}, sealOf A Z → r5Of A p → X.sb Z p
  /-- ... and before it resets the next arena. -/
  seal_before_r4 : ∀ {A Z w}, sealOf A Z → r4Of A w → X.sb Z w
  r4_acq : ∀ {A w}, r4Of A w → isAcqCas A
  r4_under : ∀ {A w}, r4Of A w → toOwnerProtocol.under A w
  /-- R4 stores the next epoch's stamp, unsealed. -/
  r4_r0 : ∀ {A w}, r4Of A w → ∃ q, r0Of A q ∧ stamp (X.wval w) = X.rval q + 1
  r4_unsealed : ∀ {A w}, r4Of A w → ¬ isSealed (X.wval w)
  /-- The sealing loop: a tenure reaches R5 only after its own R1 (the seal
  Z(e)) succeeded or after it loaded a control-word value with the epoch's
  stamp, sealed. -/
  loop_exit : ∀ {A p q}, r5Of A p → r0Of A q →
    (∃ Z, sealOf A Z) ∨
    (∃ w n, X.isW w ∧ X.loc w = some (ctlLoc n) ∧
      stamp (X.wval w) = X.rval q ∧ isSealed (X.wval w))

namespace RotationProtocol

variable {X : Execution Ev Loc} (P : RotationProtocol X)

/-- Nothing precedes the epoch's initial value. -/
theorem not_mo_epInit (hc : X.Consistent) {e : Ev} (h : X.mo e P.epInit) : False :=
  not_mo_first hc P.epInit_w (fun he hl => P.epInit_first he (hl.trans P.epInit_loc)) h

/-- **EP1.** Along the modification order of the epoch, values strictly
increase: each rotation publishes exactly one more than the last. -/
theorem epoch_mono (hc : X.Consistent) (p' : Ev) :
    X.isW p' → X.loc p' = some P.ep → ∀ p, X.mo p p' → X.wval p < X.wval p' := by
  refine hc.mo_wellFounded.induction
    (C := fun p' => X.isW p' → X.loc p' = some P.ep →
      ∀ p, X.mo p p' → X.wval p < X.wval p') p' ?_
  intro p' ih hp'W hp'l p hpp'
  have ⟨hpW, _, hl, _⟩ := hc.mo_wf hpp'
  have hpl : X.loc p = some P.ep := hl.trans hp'l
  rcases P.ep_writes hp'W hp'l with hinit | ⟨A', h5'⟩
  · subst hinit; exact (P.not_mo_epInit hc hpp').elim
  · -- `p'` is an R5; `q'` is its R0, which read `w'`.
    obtain ⟨q', hq', hsb, hval⟩ := P.r5_r0 h5'
    obtain ⟨w', hw'q'⟩ := P.r0_reads hq'
    have ⟨hw'W, _, hlw', _⟩ := hc.rf_wf hw'q'
    have hw'l : X.loc w' = some P.ep := hlw'.trans (P.r0_loc hq')
    have hw'p' : X.mo w' p' :=
      coh_rw hc hp'W (hp'l.trans (P.r0_loc hq').symm) (hb_of_sb hsb) hw'q'
    have hle : X.wval p ≤ X.wval w' := by
      rcases hc.mo_total hpW hw'W (hpl.trans hw'l.symm)
          (some_ne_none hpl) with heq | h | h
      · subst heq; exact Nat.le_refl _
      · exact Nat.le_of_lt (ih w' hw'p' hw'W hw'l p h)
      · -- `w'` before `p` before `p'`: R0 would have missed `p`.
        exfalso
        rcases P.ep_writes hpW hpl with hinit | ⟨A, h5⟩
        · subst hinit; exact P.not_mo_epInit hc h
        · rcases P.toOwnerProtocol.tenure_total hc (P.r5_acq h5) (P.r5_acq h5')
            with heq | hAA' | hA'A
          · subst heq
            have := P.r5_unique h5 h5'
            subst this
            exact hc.mo_irrefl _ hpp'
          · have hb₁ : X.hb p q' :=
              P.toOwnerProtocol.tenures_ordered hc (P.r5_acq h5) (P.r5_acq h5') hAA'
                (P.r5_under h5) (P.r0_under hq')
            rcases coh_wr hc hpW (hpl.trans (P.r0_loc hq').symm) hb₁ hw'q' with heq | hm
            · subst heq; exact hc.mo_irrefl _ h
            · exact hc.mo_irrefl _ (hc.mo_trans h hm)
          · have hb₁ : X.hb p' p :=
              P.toOwnerProtocol.tenures_ordered hc (P.r5_acq h5') (P.r5_acq h5) hA'A
                (P.r5_under h5') (P.r5_under h5)
            exact hc.mo_irrefl _
              (hc.mo_trans hpp' (coh_ww_at hc hp'W hp'l hpW hpl hb₁))
    rw [hval, hc.rf_val hw'q']
    exact Nat.lt_succ_of_le hle

/-- Every value the epoch takes is at least 2. -/
theorem ep_val_ge (hc : X.Consistent) {w : Ev} (hW : X.isW w) (hl : X.loc w = some P.ep) :
    2 ≤ X.wval w := by
  by_cases h : w = P.epInit
  · rw [h, P.epInit_val]; exact Nat.le_refl 2
  · have hm : X.mo P.epInit w :=
      coh_ww_at hc P.epInit_w P.epInit_loc hW hl (P.epInit_first hW hl h)
    have := P.epoch_mono hc w hW hl _ hm
    rw [P.epInit_val] at this
    omega

/-- Every recheck R0 returns at least 2. -/
theorem r0_ge (hc : X.Consistent) {A q : Ev} (hq : P.r0Of A q) : 2 ≤ X.rval q := by
  obtain ⟨w, hw⟩ := P.r0_reads hq
  have ⟨hwW, _, hl, _⟩ := hc.rf_wf hw
  rw [hc.rf_val hw]
  exact P.ep_val_ge hc hwW (hl.trans (P.r0_loc hq))

/-- The epoch a later tenure rechecks is at least the one an earlier tenure
rechecked. -/
theorem r0_mono (hc : X.Consistent) {A B q q' : Ev}
    (hq : P.r0Of A q) (hq' : P.r0Of B q') (hAB : X.mo A B) :
    X.rval q ≤ X.rval q' := by
  obtain ⟨w, hw⟩ := P.r0_reads hq
  obtain ⟨w', hw'⟩ := P.r0_reads hq'
  have hb₁ : X.hb q q' :=
    P.toOwnerProtocol.tenures_ordered hc (P.r0_acq hq) (P.r0_acq hq') hAB
      (P.r0_under hq) (P.r0_under hq')
  have ⟨hw'W, _, hl', _⟩ := hc.rf_wf hw'
  rw [hc.rf_val hw, hc.rf_val hw']
  rcases coh_rr hc ((P.r0_loc hq).trans (P.r0_loc hq').symm) hb₁ hw hw' with heq | hm
  · subst heq; exact Nat.le_refl _
  · exact Nat.le_of_lt (P.epoch_mono hc w' hw'W (hl'.trans (P.r0_loc hq')) w hm)

/-- A tenure that rechecked a smaller epoch is an earlier tenure (EP1
with OW2). -/
theorem tenure_lt_of_r0_lt (hc : X.Consistent) {A B q q' : Ev}
    (hq : P.r0Of A q) (hq' : P.r0Of B q') (h : X.rval q < X.rval q') : X.mo A B := by
  rcases P.toOwnerProtocol.tenure_total hc (P.r0_acq hq) (P.r0_acq hq') with heq | h₁ | h₁
  · subst heq
    have := P.r0_unique hq hq'
    subst this
    omega
  · exact h₁
  · have := P.r0_mono hc hq' hq h₁
    omega

/-- A later tenure's recheck sees an earlier tenure's publication. -/
theorem r0_after_r5 (hc : X.Consistent) {A B p q : Ev}
    (h5 : P.r5Of A p) (hq : P.r0Of B q) (hAB : X.mo A B) :
    X.wval p ≤ X.rval q := by
  obtain ⟨w, hw⟩ := P.r0_reads hq
  have hb₁ : X.hb p q :=
    P.toOwnerProtocol.tenures_ordered hc (P.r5_acq h5) (P.r0_acq hq) hAB
      (P.r5_under h5) (P.r0_under hq)
  have ⟨hwW, _, hl, _⟩ := hc.rf_wf hw
  rw [hc.rf_val hw]
  rcases coh_wr hc (P.r5_w h5) ((P.r5_loc h5).trans (P.r0_loc hq).symm) hb₁ hw
    with heq | hm
  · subst heq; exact Nat.le_refl _
  · exact Nat.le_of_lt (P.epoch_mono hc w hwW (hl.trans (P.r0_loc hq)) p hm)

/-- **EP2.** After the seal `Z` of an incarnation, every control-word
value carrying that incarnation's stamp is sealed. -/
theorem seal_persists (hc : X.Consistent) {A Z : Ev} (hZ : P.sealOf A Z) (w : Ev) :
    X.mo Z w → P.stamp (X.wval w) = P.stamp (X.wval Z) → P.isSealed (X.wval w) := by
  refine hc.mo_wellFounded.induction
    (C := fun w => X.mo Z w → P.stamp (X.wval w) = P.stamp (X.wval Z) →
      P.isSealed (X.wval w)) w ?_
  intro w ih hZw hst
  have ⟨hZW, hwW, hl, _⟩ := hc.mo_wf hZw
  obtain ⟨qz, hqz, hstz, hlZ⟩ := P.seal_r0 hZ
  have hlw : X.loc w = some (P.ctlLoc (X.rval qz)) := hl.symm.trans hlZ
  rcases P.ctl_writes hwW hlw with hinit | hb1 | ⟨A', hs⟩ | ⟨A', h4⟩
  · -- The initialisation precedes the seal.
    exact (not_mo_first hc hwW (P.ctlInit_first hinit) hZw).elim
  · -- A `fetch_add` copies the bit from the write it read.
    obtain ⟨w', hrf, hst', hse'⟩ := P.b1_rmw hb1
    have ⟨hw'W, _, hlw', hsw'⟩ := hc.rf_wf hrf
    rcases hc.mo_total hw'W hZW (hlw'.trans hl.symm) hsw' with heq | h | h
    · subst heq; exact hse'.mpr (P.seal_sealed hZ)
    · exact (rmw_adjacent hc hrf h hZw).elim
    · exact hse'.mpr (ih w' (hc.rmw_mo hrf hwW) h (hst'.symm.trans hst))
  · exact P.seal_sealed hs
  · -- A reset with this stamp belongs to the previous epoch's rotation,
    -- which is an earlier tenure, so the reset precedes the seal.
    exfalso
    obtain ⟨q, hq, hst4⟩ := P.r4_r0 h4
    have hv : X.rval qz = X.rval q + 1 := by rw [← hstz, ← hst, hst4]
    have hb₁ : X.hb w Z :=
      P.toOwnerProtocol.tenures_ordered hc (P.r4_acq h4) (P.seal_acq hZ)
        (P.tenure_lt_of_r0_lt hc hq hqz (by omega)) (P.r4_under h4) (P.seal_under hZ)
    exact hc.mo_irrefl _ (hc.mo_trans hZw (coh_ww_at hc hwW hlw hZW hlZ hb₁))

/-- A sealed control-word value with a non-zero stamp was sealed by some
tenure's R1 with that stamp. -/
theorem sealed_has_seal (hc : X.Consistent) (w : Ev) :
    X.isW w → (∃ n, X.loc w = some (P.ctlLoc n)) → P.isSealed (X.wval w) →
    P.stamp (X.wval w) ≠ 0 →
    ∃ A Z, P.sealOf A Z ∧ P.stamp (X.wval Z) = P.stamp (X.wval w) := by
  refine hc.mo_wellFounded.induction
    (C := fun w => X.isW w → (∃ n, X.loc w = some (P.ctlLoc n)) →
      P.isSealed (X.wval w) → P.stamp (X.wval w) ≠ 0 →
      ∃ A Z, P.sealOf A Z ∧ P.stamp (X.wval Z) = P.stamp (X.wval w)) w ?_
  intro w ih hwW ⟨n, hlw⟩ hse hne
  rcases P.ctl_writes hwW hlw with hinit | hb1 | ⟨A', hs⟩ | ⟨A', h4⟩
  · exact absurd (P.ctlInit_val hinit hse) hne
  · obtain ⟨w', hrf, hst', hse'⟩ := P.b1_rmw hb1
    have ⟨hw'W, _, hlw', _⟩ := hc.rf_wf hrf
    obtain ⟨A, Z, hZ, hstZ⟩ :=
      ih w' (hc.rmw_mo hrf hwW) hw'W ⟨n, hlw'.trans hlw⟩ (hse'.mp hse) (by rw [← hst']; exact hne)
    exact ⟨A, Z, hZ, hstZ.trans hst'.symm⟩
  · exact ⟨A', w, hs, rfl⟩
  · exact absurd hse (P.r4_unsealed h4)

/-- **EP3, the general form.** An event `f` that a publishing tenure
performs under ownership, after its own seal if it sealed, happens after the
seal of the incarnation it rechecked. -/
theorem seal_before_under (hc : X.Consistent) {A p q f : Ev}
    (h5 : P.r5Of A p) (hq : P.r0Of A q) (hf : P.toOwnerProtocol.under A f)
    (hself : ∀ {Z}, P.sealOf A Z → X.sb Z f) :
    ∃ B Z, P.sealOf B Z ∧ P.stamp (X.wval Z) = X.rval q ∧ X.hb Z f := by
  rcases P.loop_exit h5 hq with ⟨Z, hZ⟩ | ⟨w, n, hwW, hwl, hst, hse⟩
  · -- The tenure sealed the arena itself.
    obtain ⟨q', hq', hst', _⟩ := P.seal_r0 hZ
    have := P.r0_unique hq hq'
    subst this
    exact ⟨A, Z, hZ, hst', hb_of_sb (hself hZ)⟩
  · -- It found the arena sealed: some tenure `B` with the same epoch did.
    obtain ⟨B, Z, hZ, hstZ⟩ :=
      P.sealed_has_seal hc w hwW ⟨n, hwl⟩ hse (by rw [hst]; have := P.r0_ge hc hq; omega)
    obtain ⟨qB, hqB, hstB, _⟩ := P.seal_r0 hZ
    have hv : X.rval qB = X.rval q := by rw [← hstB, hstZ, hst]
    refine ⟨B, Z, hZ, hstZ.trans hst, ?_⟩
    rcases P.toOwnerProtocol.tenure_total hc (P.seal_acq hZ) (P.r5_acq h5) with heq | h | h
    · subst heq; exact hb_of_sb (hself hZ)
    · exact P.toOwnerProtocol.tenures_ordered hc (P.seal_acq hZ) (P.r5_acq h5) h
        (P.seal_under hZ) hf
    · -- A later tenure would have rechecked a larger epoch.
      exfalso
      obtain ⟨q', hq', _, hval⟩ := P.r5_r0 h5
      have := P.r0_unique hq hq'
      subst this
      have := P.r0_after_r5 hc h5 hqB h
      omega

/-- **EP3, R5.** A tenure that publishes the epoch does so after the seal
of the incarnation it rechecked. -/
theorem seal_before_publish (hc : X.Consistent) {A p q : Ev}
    (h5 : P.r5Of A p) (hq : P.r0Of A q) :
    ∃ B Z, P.sealOf B Z ∧ P.stamp (X.wval Z) = X.rval q ∧ X.hb Z p :=
  P.seal_before_under hc h5 hq (P.r5_under h5) (fun hZ => P.seal_before_r5 hZ h5)

/-- **EP3, R4.** A tenure that publishes the epoch resets the next arena
after the seal of the incarnation it rechecked. -/
theorem seal_before_reset (hc : X.Consistent) {A p q w : Ev}
    (h5 : P.r5Of A p) (hq : P.r0Of A q) (h4 : P.r4Of A w) :
    ∃ B Z, P.sealOf B Z ∧ P.stamp (X.wval Z) = X.rval q ∧ X.hb Z w :=
  P.seal_before_under hc h5 hq (P.r4_under h4) (fun hZ => P.seal_before_r4 hZ h4)

/-- The seal of incarnation `e` happens before everything the tenure that
rechecks `e + 2` does under ownership. So a `SeqCst` fence under that
ownership would serve as the fence `f` of PN3. The code makes no such
fence; it relies on the caller's W3 (`CacheProtocol.seal_before_fence`). -/
theorem seal_before_scan (hc : X.Consistent) {B qB f : Ev} {e : Nat}
    (hqB : P.r0Of B qB) (hv : X.rval qB = e + 2) (he : 2 ≤ e)
    (hf : P.toOwnerProtocol.under B f) :
    ∃ A Z, P.sealOf A Z ∧ P.stamp (X.wval Z) = e ∧ X.hb Z f := by
  -- `qB` read `e + 2`, written by the R5 `w2` of a tenure `A2` ...
  obtain ⟨w2, hw2⟩ := P.r0_reads hqB
  have ⟨hw2W, _, hl2, _⟩ := hc.rf_wf hw2
  have hv2 : X.wval w2 = e + 2 := by rw [← hc.rf_val hw2, hv]
  rcases P.ep_writes hw2W (hl2.trans (P.r0_loc hqB)) with hinit | ⟨A2, h52⟩
  · subst hinit; rw [P.epInit_val] at hv2; omega
  obtain ⟨q2, hq2, _, hval2⟩ := P.r5_r0 h52
  have hrq2 : X.rval q2 = e + 1 := by omega
  -- ... whose R0 read `e + 1`, written by the R5 `w1` of a tenure `A1` ...
  obtain ⟨w1, hw1⟩ := P.r0_reads hq2
  have ⟨hw1W, _, hl1, _⟩ := hc.rf_wf hw1
  have hv1 : X.wval w1 = e + 1 := by rw [← hc.rf_val hw1, hrq2]
  rcases P.ep_writes hw1W (hl1.trans (P.r0_loc hq2)) with hinit | ⟨A1, h51⟩
  · subst hinit; rw [P.epInit_val] at hv1; omega
  obtain ⟨q1, hq1, _, hval1⟩ := P.r5_r0 h51
  have hrq1 : X.rval q1 = e := by omega
  -- ... which published after the seal of incarnation `e`.
  obtain ⟨Bz, Z, hZ, hstZ, hZw1⟩ := P.seal_before_publish hc h51 hq1
  have h12 : X.mo A1 A2 := P.tenure_lt_of_r0_lt hc hq1 hq2 (by omega)
  have h2B : X.mo A2 B := P.tenure_lt_of_r0_lt hc hq2 hqB (by omega)
  have s₁ : X.hb w1 A2 :=
    P.toOwnerProtocol.tenure_serial hc A2 (P.r5_acq h52) A1 (P.r5_acq h51) h12 w1
      (P.r5_under h51)
  have s₂ : X.hb A2 B :=
    P.toOwnerProtocol.tenure_serial hc B (P.r0_acq hqB) A2 (P.r5_acq h52) h2B A2
      P.toOwnerProtocol.under_self
  have s₃ : X.hb Z B := hb_trans hZw1 (hb_trans s₁ s₂)
  exact ⟨Bz, Z, hZ, hstZ.trans hrq1, hb_sbr s₃ hf.1⟩

/-- PN3 from a seal of incarnation `e` that happens before the fence `f`,
wherever that fence is. -/
theorem pin_visibility_of_seal (hc : X.Consistent)
    {W2 W3 B0 f Sc u p : Ev} {e : Nat}
    (hW3 : X.isScFence W3) (hf : X.isScFence f)
    (h_W2_W3 : X.sb W2 W3) (h_W3_B0 : X.sb W3 B0)
    (h_u_B0 : X.rf u B0) (hB0loc : X.loc B0 = some (P.ctlLoc e))
    (hstamp : P.stamp (X.wval u) = e) (hunsealed : ¬ P.isSealed (X.wval u))
    (hseal : ∃ A Z, P.sealOf A Z ∧ P.stamp (X.wval Z) = e ∧ X.hb Z f)
    (h_f_Sc : X.sb f Sc) (h_p_Sc : X.rf p Sc) :
    ¬ X.mo p W2 := by
  obtain ⟨A, Z, hZ, hstZ, hZf⟩ := hseal
  obtain ⟨q, hq, hstq, hlZ⟩ := P.seal_r0 hZ
  have ⟨huW, _, hlu, hsu⟩ := hc.rf_wf h_u_B0
  have hlZ' : X.loc Z = some (P.ctlLoc e) := by rw [hlZ, ← hstq, hstZ]
  have h_u_Z : X.mo u Z := by
    rcases hc.mo_total huW (P.seal_w hZ) ((hlu.trans hB0loc).trans hlZ'.symm) hsu
      with heq | h | h
    · subst heq; exact absurd (P.seal_sealed hZ) hunsealed
    · exact h
    · exact absurd (P.seal_persists hc hZ u h (hstamp.trans hstZ.symm)) hunsealed
  exact pin_visibility hc hW3 hf h_W2_W3 h_W3_B0 h_u_B0 h_u_Z hZf h_f_Sc h_p_Sc

/-- **PN3 with OW2 and EP1 to EP3 discharged, for a fence under ownership.**
A writer pinned for epoch `e` whose control-word load `B0` saw `(stamp e,
unsealed)` is seen by every scan load `Sc` that follows a `SeqCst` fence `f`
made under the ownership of the tenure that rechecks `e + 2`: `Sc` reads no
pin older than `W2`. This covers a variant with a fence under ownership;
the code's own fence, the caller's W3, is covered by
`CacheProtocol.seal_before_fence` with `pin_visibility_of_seal`. -/
theorem pin_visibility_closed (hc : X.Consistent)
    {W2 W3 B0 f Sc u p B qB : Ev} {e : Nat}
    (hW3 : X.isScFence W3) (hf : X.isScFence f)
    (h_W2_W3 : X.sb W2 W3) (h_W3_B0 : X.sb W3 B0)
    (h_u_B0 : X.rf u B0) (hB0loc : X.loc B0 = some (P.ctlLoc e))
    (hstamp : P.stamp (X.wval u) = e) (hunsealed : ¬ P.isSealed (X.wval u))
    (he : 2 ≤ e)
    (hqB : P.r0Of B qB) (hv : X.rval qB = e + 2)
    (hfB : P.toOwnerProtocol.under B f)
    (h_f_Sc : X.sb f Sc) (h_p_Sc : X.rf p Sc) :
    ¬ X.mo p W2 :=
  P.pin_visibility_of_seal hc hW3 hf h_W2_W3 h_W3_B0 h_u_B0 hB0loc hstamp hunsealed
    (P.seal_before_scan hc hqB hv he hfB) h_f_Sc h_p_Sc

end RotationProtocol
end Execution
end Sgc
