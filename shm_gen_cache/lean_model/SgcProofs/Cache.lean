import SgcProofs.Pins

/-!
# The cache as a whole: EP4, QU1 in full, and SP1

`CacheProtocol` puts the pieces together: the slots and their pins, the
rotation protocol, the scan that precedes every reset (R3 before R4), and
the store attempts. A *store attempt* is identified with its pin store W2;
its epoch is `attEpoch`.

Proved here:

* `reset_before_w1` (EP4): the reset of an incarnation happens before the
  W1 of every insert that reads its epoch;
* `seal_before_fence` (EP3, the form the code uses): the seal of
  incarnation `e` happens before every fence that follows an acquire load
  of the epoch returning `e + 2`, so before the W3 of the rotator's own last
  reservation pass, which is the fence of its pin scan;
* `attempt_before_reset`, `attempt_before_resets` (QU1): everything a
  store attempt for epoch `e` does from W2 to W7 happens before the reset
  made by any tenure that rechecks `e + 2` or more (`publisher_exists`,
  part of EP1, gives the tenure that published `e + 3`);
* `attempts_ordered`, `arena_writes_ordered` (QU2(a)): everything a store
  attempt does happens before everything a store attempt three or more
  epochs later does, so the incarnations of an arena are serial and their
  writes to a word are `mo`-ordered by epoch (SP5's order);
* `no_future_read` (QU2(b)): a store attempt never reads what a store
  attempt three or more epochs later wrote;
* `ctl_base`, `stamp_le_r4`, `between_self` (EP2, the base of an
  incarnation);
* `b1_lands` (SP1): the `fetch_add` of a store attempt lands in its own
  incarnation;
* `r4_before_att`, `r4_before_b1` (SP1): every reset that writes an
  incarnation's stamp, including a repeated one after a takeover, happens
  before everything an attempt for that incarnation does from W2 on, its
  `fetch_add` included.
-/

namespace Sgc
namespace Execution

variable {Ev Loc : Type}

/-- The whole cache: slots, pins, rotation, and store attempts. -/
structure CacheProtocol (X : Execution Ev Loc) where
  Lv : Liveness X
  /-- Slot indices. -/
  ι : Type
  slot : ι → SlotProtocol X Lv
  pins : (i : ι) → PinProtocol X (slot i)
  R : RotationProtocol X
  /-- Consecutive epochs are served by different arenas. -/
  ctl_distinct : ∀ n, R.ctlLoc (n + 1) ≠ R.ctlLoc n ∧ R.ctlLoc (n + 2) ≠ R.ctlLoc n
  /-- R5 is a release store. -/
  r5_isRel : ∀ {A p}, R.r5Of A p → X.isRel p
  /-- A tenure resets the next arena before it publishes. -/
  r5_r4 : ∀ {A p}, R.r5Of A p → ∃ w4, R.r4Of A w4 ∧ X.sb w4 p
  r4_unique : ∀ {A w w'}, R.r4Of A w → R.r4Of A w' → w = w'
  /-- R4 writes the control word of the next epoch's arena. -/
  r4_loc : ∀ {A w q}, R.r4Of A w → R.r0Of A q → X.loc w = some (R.ctlLoc (X.rval q + 1))
  ctlInit_stamp : ∀ {i}, R.isCtlInit i → R.stamp (X.wval i) ≤ 2
  /-- The seal is a read-modify-write that keeps the stamp. -/
  seal_rmw : ∀ {A Z}, R.sealOf A Z →
    ∃ w, X.rf w Z ∧ R.stamp (X.wval Z) = R.stamp (X.wval w)
  /-- R3: before R4 a tenure scans every slot's pin with an acquire load that
  passes: it returns no pin, or a pin less than two epochs old. The scan
  follows a `SeqCst` fence `f`, the caller's W3, which follows the caller's
  acquire load W1 `q1` of the epoch, which returned the value R0 rechecks
  (code fact F2: `reserve()` runs W1 and W3 before every `rotate(e)`, with
  the `e` its W1 read, and `test_access::rotate` does the same).
  `rotate()` has no fence of its own, and nothing here asks `f` to be
  under ownership. -/
  scan : ∀ {B w4 qB}, R.r4Of B w4 → R.r0Of B qB →
    ∃ f q1 s, X.isScFence f ∧ X.rf s q1 ∧ X.isAcq q1 ∧ X.loc q1 = some R.ep ∧
      X.rval q1 = X.rval qB ∧ X.sb q1 f ∧
      ∀ i, ∃ Sc p, X.rf p Sc ∧ X.loc Sc = some (pins i).pin ∧ X.isAcq Sc ∧
        X.sb f Sc ∧ X.sb Sc w4 ∧
        ((pins i).pinVal (X.rval Sc) = 0 ∨ X.rval qB < (pins i).pinVal (X.rval Sc) + 2)
  /-- `isAttempt i w`: `w` is the pin store W2 of a store attempt in slot
  `i`, one whose control-word load saw its incarnation unsealed. -/
  isAttempt : ι → Ev → Prop
  /-- The epoch a store attempt pinned. -/
  attEpoch : Ev → Nat
  att_w2 : ∀ {i w}, isAttempt i w → ∃ c, (pins i).w2Of c w
  att_pin : ∀ {i w}, isAttempt i w → (pins i).pinVal (X.wval w) = attEpoch w
  /-- W1: an acquire load of the epoch, before the pin. -/
  att_w1 : ∀ {i w}, isAttempt i w → ∃ q1 s, X.rf s q1 ∧ X.isAcq q1 ∧
    X.loc q1 = some R.ep ∧ X.sb q1 w ∧ X.rval q1 = attEpoch w
  /-- W3 and B0: after the pin, a `SeqCst` fence, then a load of the
  epoch's control word that returns its stamp, unsealed. -/
  att_b0 : ∀ {i w}, isAttempt i w → ∃ f l u, X.isScFence f ∧ X.sb w f ∧ X.sb f l ∧
    X.rf u l ∧ X.loc l = some (R.ctlLoc (attEpoch w)) ∧
    R.stamp (X.wval u) = attEpoch w ∧ ¬ R.isSealed (X.wval u)
  /-- B1: an attempt's `fetch_add` is on its epoch's control word, after a
  load (B0) of that word that returned the epoch's stamp. -/
  b1_after_b0 : ∀ {i w b}, isAttempt i w → (pins i).inAttempt w b → R.isB1 b →
    X.isW b ∧ X.loc b = some (R.ctlLoc (attEpoch w)) ∧
    ∃ l u, X.sb l b ∧ X.rf u l ∧ X.loc l = some (R.ctlLoc (attEpoch w)) ∧
      R.stamp (X.wval u) = attEpoch w

namespace CacheProtocol

variable {X : Execution Ev Loc} (C : CacheProtocol X)

theorem att_ge (hc : X.Consistent) {i : C.ι} {w : Ev} (hw : C.isAttempt i w) :
    2 ≤ C.attEpoch w := by
  obtain ⟨q1, s, hrf, _, hl, _, hv⟩ := C.att_w1 hw
  have ⟨hsW, _, hls, _⟩ := hc.rf_wf hrf
  rw [← hv, hc.rf_val hrf]
  exact C.R.ep_val_ge hc hsW (hls.trans hl)

/-- **EP4.** The reset a tenure makes happens before every acquire load
of the epoch it then publishes. -/
theorem reset_before_w1 {A p w4 q1 : Ev} (h5 : C.R.r5Of A p) (h4 : C.R.r4Of A w4)
    (hrf : X.rf p q1) (hacq : X.isAcq q1) : X.hb w4 q1 := by
  obtain ⟨w4', h4', hsb⟩ := C.r5_r4 h5
  have := C.r4_unique h4 h4'
  subst this
  exact hb_trans (hb_of_sb hsb) (hb_of_sw (sw.relAcq (C.r5_isRel h5) (rs.refl p) hrf hacq))

/-- **EP3, the W3 form.** The seal of incarnation `e` happens before every
fence that follows an acquire load of the epoch returning `e + 2`: so the
rotator's own W3, after the W1 of the pass whose `e + 2` it passes to
`rotate()`, serves as the fence of the pin scan. The chain is
`Z(e) hb R5(e + 1) hb R5(e + 2) sw W1 sb W3`. -/
theorem seal_before_fence (hc : X.Consistent) {q1 s f : Ev} {e : Nat}
    (hrf : X.rf s q1) (hacq : X.isAcq q1) (hl : X.loc q1 = some C.R.ep)
    (hv : X.rval q1 = e + 2) (he : 2 ≤ e) (hf : X.sb q1 f) :
    ∃ A Z, C.R.sealOf A Z ∧ C.R.stamp (X.wval Z) = e ∧ X.hb Z f := by
  -- `q1` read `e + 2`, written by the R5 `s` of a tenure `A2` ...
  have ⟨hsW, _, hls, _⟩ := hc.rf_wf hrf
  have hvs : X.wval s = e + 2 := by rw [← hc.rf_val hrf, hv]
  rcases C.R.ep_writes hsW (hls.trans hl) with hinit | ⟨A2, h52⟩
  · subst hinit; rw [C.R.epInit_val] at hvs; omega
  obtain ⟨q2, hq2, _, hval2⟩ := C.R.r5_r0 h52
  have hrq2 : X.rval q2 = e + 1 := by omega
  -- ... whose R0 read `e + 1`, written by the R5 `w1` of a tenure `A1` ...
  obtain ⟨w1, hw1⟩ := C.R.r0_reads hq2
  have ⟨hw1W, _, hl1, _⟩ := hc.rf_wf hw1
  have hv1 : X.wval w1 = e + 1 := by rw [← hc.rf_val hw1, hrq2]
  rcases C.R.ep_writes hw1W (hl1.trans (C.R.r0_loc hq2)) with hinit | ⟨A1, h51⟩
  · subst hinit; rw [C.R.epInit_val] at hv1; omega
  obtain ⟨q1', hq1', _, hval1⟩ := C.R.r5_r0 h51
  have hrq1 : X.rval q1' = e := by omega
  -- ... which published after the seal of incarnation `e`.
  obtain ⟨Bz, Z, hZ, hstZ, hZw1⟩ := C.R.seal_before_publish hc h51 hq1'
  have h12 : X.mo A1 A2 := C.R.tenure_lt_of_r0_lt hc hq1' hq2 (by omega)
  have s₁ : X.hb w1 A2 :=
    C.R.toOwnerProtocol.tenure_serial hc A2 (C.R.r5_acq h52) A1 (C.R.r5_acq h51) h12 w1
      (C.R.r5_under h51)
  have s₂ : X.hb Z s := hb_sbr (hb_trans hZw1 s₁) (C.R.r5_under h52).1
  have s₃ : X.hb s q1 := hb_of_sw (sw.relAcq (C.r5_isRel h52) (rs.refl s) hrf hacq)
  exact ⟨Bz, Z, hZ, hstZ.trans hrq1, hb_trans (hb_trans s₂ s₃) (hb_of_sb hf)⟩

/-- **QU1**, for the tenure that rechecks exactly `e + 2`: its scan load of
the attempt's slot reads W2 or later (PN3, with the fence of
`seal_before_fence`) and passed. -/
theorem attempt_before_reset (hc : X.Consistent) {i : C.ι} {w B w4 qB a : Ev}
    (hw : C.isAttempt i w) (h4 : C.R.r4Of B w4) (hqB : C.R.r0Of B qB)
    (hv : X.rval qB = C.attEpoch w + 2) (ha : (C.pins i).inAttempt w a) : X.hb a w4 := by
  obtain ⟨f, q1, s, hf, hrf, hacq, hl, hq1v, hq1f, hscan⟩ := C.scan h4 hqB
  obtain ⟨Sc, p, hpS, hSl, hSacq, hfS, hS4, hpass⟩ := hscan i
  obtain ⟨c, hw2⟩ := C.att_w2 hw
  obtain ⟨fw, l, u, hfw, hwf, hfl, hul, hll, hst, huns⟩ := C.att_b0 hw
  have he := C.att_ge hc hw
  have hne : (C.pins i).pinVal (X.rval Sc) ≠ C.attEpoch w := by
    rcases hpass with h | h <;> omega
  have hseal := C.seal_before_fence hc hrf hacq hl (hq1v.trans hv) he hq1f
  exact hb_trans
    ((C.pins i).quiescence hc C.R hfw hf hwf hfl hul hll hst huns hseal hfS hpS hw2
      (C.att_pin hw) hSl hSacq hne ha)
    (hb_of_sb hS4)

/-- **EP1, no gaps.** Every epoch value from 3 up to one that was written
was published by some tenure's R5. -/
theorem publisher_exists (hc : X.Consistent) (p : Ev) :
    X.isW p → X.loc p = some C.R.ep → ∀ v, 3 ≤ v → v ≤ X.wval p →
    ∃ A p', C.R.r5Of A p' ∧ X.wval p' = v := by
  refine hc.mo_wellFounded.induction
    (C := fun p => X.isW p → X.loc p = some C.R.ep → ∀ v, 3 ≤ v → v ≤ X.wval p →
      ∃ A p', C.R.r5Of A p' ∧ X.wval p' = v) p ?_
  intro p ih hW hl v hv3 hvp
  rcases C.R.ep_writes hW hl with hinit | ⟨A, h5⟩
  · rw [hinit, C.R.epInit_val] at hvp; omega
  · by_cases heq : X.wval p = v
    · exact ⟨A, p, h5, heq⟩
    · obtain ⟨q, hq, hsb, hval⟩ := C.R.r5_r0 h5
      obtain ⟨w, hwq⟩ := C.R.r0_reads hq
      have ⟨hwW, _, hlw, _⟩ := hc.rf_wf hwq
      have hmo : X.mo w p :=
        coh_rw hc hW (hl.trans (C.R.r0_loc hq).symm) (hb_of_sb hsb) hwq
      have hvw : v ≤ X.wval w := by rw [← hc.rf_val hwq]; omega
      exact ih w hmo hwW (hlw.trans (C.R.r0_loc hq)) v hv3 hvw

/-- **QU1.** Everything a store attempt for epoch `e` does from W2 to W7
happens before the reset made by any tenure that rechecks `e + 2` or more. -/
theorem attempt_before_resets (hc : X.Consistent) {i : C.ι} {w B w4 qB a : Ev}
    (hw : C.isAttempt i w) (h4 : C.R.r4Of B w4) (hqB : C.R.r0Of B qB)
    (hv : C.attEpoch w + 2 ≤ X.rval qB) (ha : (C.pins i).inAttempt w a) : X.hb a w4 := by
  by_cases heq : X.rval qB = C.attEpoch w + 2
  · exact C.attempt_before_reset hc hw h4 hqB heq ha
  · -- A later tenure: the one that published `e + 3` comes before it.
    obtain ⟨x, hxq⟩ := C.R.r0_reads hqB
    have ⟨hxW, _, hlx, _⟩ := hc.rf_wf hxq
    have hxv : C.attEpoch w + 3 ≤ X.wval x := by rw [← hc.rf_val hxq]; omega
    obtain ⟨B3, p3, h53, hp3⟩ :=
      C.publisher_exists hc x hxW (hlx.trans (C.R.r0_loc hqB)) (C.attEpoch w + 3) (by omega) hxv
    obtain ⟨q3, hq3, _, hval3⟩ := C.R.r5_r0 h53
    obtain ⟨w43, h43, _⟩ := C.r5_r4 h53
    have h₁ : X.hb a w43 := C.attempt_before_reset hc hw h43 hq3 (by omega) ha
    have hmo : X.mo B3 B := C.R.tenure_lt_of_r0_lt hc hq3 hqB (by omega)
    exact hb_trans h₁
      (C.R.toOwnerProtocol.tenures_ordered hc (C.R.r0_acq hq3) (C.R.r0_acq hqB) hmo
        (C.R.r4_under h43) (C.R.r4_under h4))

/-- **QU2(a).** Everything a store attempt does happens before everything a
store attempt three or more epochs later does. Attempts on one arena are
exactly three epochs apart, so the incarnations of an arena are serial. -/
theorem attempts_ordered (hc : X.Consistent) {i j : C.ι} {w w' a a' : Ev}
    (hw : C.isAttempt i w) (hw' : C.isAttempt j w')
    (he : C.attEpoch w + 3 ≤ C.attEpoch w')
    (ha : (C.pins i).inAttempt w a) (ha' : (C.pins j).inAttempt w' a') : X.hb a a' := by
  obtain ⟨q1, x, hxq, hqacq, hql, hqw, hqv⟩ := C.att_w1 hw'
  have ⟨hxW, _, hlx, _⟩ := hc.rf_wf hxq
  have hxv : X.wval x = C.attEpoch w' := by rw [← hc.rf_val hxq]; exact hqv
  rcases C.R.ep_writes hxW (hlx.trans hql) with hinit | ⟨B', h5⟩
  · rw [hinit, C.R.epInit_val] at hxv
    have := C.att_ge hc hw
    omega
  · obtain ⟨q', hq', _, hval⟩ := C.R.r5_r0 h5
    obtain ⟨w4, h4, hsb4⟩ := C.r5_r4 h5
    have h₁ : X.hb a w4 := C.attempt_before_resets hc hw h4 hq' (by omega) ha
    have h₂ : X.hb w4 q1 := C.reset_before_w1 h5 h4 hxq hqacq
    exact hb_sbr (hb_trans h₁ (hb_trans h₂ (hb_of_sb hqw))) ha'.1

/-- **QU2(b).** A store attempt reads nothing that a store attempt three or
more epochs later wrote. -/
theorem no_future_read (hc : X.Consistent) {i j : C.ι} {w w' a a' : Ev}
    (hw : C.isAttempt i w) (hw' : C.isAttempt j w')
    (he : C.attEpoch w + 3 ≤ C.attEpoch w')
    (ha : (C.pins i).inAttempt w a) (ha' : (C.pins j).inAttempt w' a') : ¬ X.rf a' a :=
  fun hrf => hc.coherence (C.attempts_ordered hc hw hw' he ha ha') (cob_of_rf hrf)

/-- **SP5, the order.** Writes that attempts three or more epochs apart
make to one word are ordered by `mo` as their epochs are. -/
theorem arena_writes_ordered (hc : X.Consistent) {i j : C.ι} {w w' a a' : Ev}
    (hw : C.isAttempt i w) (hw' : C.isAttempt j w')
    (he : C.attEpoch w + 3 ≤ C.attEpoch w')
    (ha : (C.pins i).inAttempt w a) (ha' : (C.pins j).inAttempt w' a')
    (haW : X.isW a) (ha'W : X.isW a') (hloc : X.loc a = X.loc a') (hsome : X.loc a ≠ none) :
    X.mo a a' :=
  coh_ww hc haW ha'W hloc hsome (C.attempts_ordered hc hw hw' he ha ha')

/-- Epochs served by one arena are equal or at least three apart (code
fact F12). -/
theorem ctl_eq {a b : Nat} (h : C.R.ctlLoc a = C.R.ctlLoc b) :
    a = b ∨ a + 3 ≤ b ∨ b + 3 ≤ a := by
  have hd := C.ctl_distinct a
  have hd' := C.ctl_distinct b
  by_cases h₁ : b = a + 1
  · subst h₁; exact absurd h.symm hd.1
  · by_cases h₂ : b = a + 2
    · subst h₂; exact absurd h.symm hd.2
    · by_cases h₃ : a = b + 1
      · subst h₃; exact absurd h hd'.1
      · by_cases h₄ : a = b + 2
        · subst h₄; exact absurd h hd'.2
        · omega

/-! ## The stamps of a control word -/

theorem between_self (hc : X.Consistent) {y z : Ev} (h₁ : y = z ∨ X.mo y z)
    (h₂ : z = y ∨ X.mo z y) : z = y := by
  rcases h₂ with h | h
  · exact h
  · rcases h₁ with h' | h'
    · exact h'.symm
    · exact (hc.mo_irrefl _ (hc.mo_trans h h')).elim

/-- Under every control-word write lies a *base*: the initialisation or a
reset, followed only by `fetch_add`s and seals, all with the base's stamp. -/
theorem ctl_base (hc : X.Consistent) (y : Ev) :
    X.isW y → (∃ n, X.loc y = some (C.R.ctlLoc n)) →
    ∃ b, (C.R.isCtlInit b ∨ ∃ A, C.R.r4Of A b) ∧ (b = y ∨ X.mo b y) ∧
      ∀ z, (b = z ∨ X.mo b z) → (z = y ∨ X.mo z y) →
        C.R.stamp (X.wval z) = C.R.stamp (X.wval b) := by
  refine hc.mo_wellFounded.induction
    (C := fun y => X.isW y → (∃ n, X.loc y = some (C.R.ctlLoc n)) →
      ∃ b, (C.R.isCtlInit b ∨ ∃ A, C.R.r4Of A b) ∧ (b = y ∨ X.mo b y) ∧
        ∀ z, (b = z ∨ X.mo b z) → (z = y ∨ X.mo z y) →
          C.R.stamp (X.wval z) = C.R.stamp (X.wval b)) y ?_
  intro y ih hW ⟨n, hl⟩
  -- A read-modify-write that keeps the stamp inherits the base of what it read.
  have step : ∀ y', X.rf y' y → C.R.stamp (X.wval y) = C.R.stamp (X.wval y') →
      ∃ b, (C.R.isCtlInit b ∨ ∃ A, C.R.r4Of A b) ∧ (b = y ∨ X.mo b y) ∧
        ∀ z, (b = z ∨ X.mo b z) → (z = y ∨ X.mo z y) →
          C.R.stamp (X.wval z) = C.R.stamp (X.wval b) := by
    intro y' hrf hst
    have ⟨hy'W, _, hly', _⟩ := hc.rf_wf hrf
    have hy'y := hc.rmw_mo hrf hW
    obtain ⟨b, hb, hby', hall⟩ := ih y' hy'y hy'W ⟨n, hly'.trans hl⟩
    refine ⟨b, hb, Or.inr ?_, fun z hbz hzy => ?_⟩
    · rcases hby' with h | h
      · rw [h]; exact hy'y
      · exact hc.mo_trans h hy'y
    · rcases hzy with h | h
      · rw [h, hst]; exact hall y' hby' (Or.inl rfl)
      · have ⟨hzW, _, hlz, _⟩ := hc.mo_wf h
        exact hall z hbz (rmw_pred_ge hc hrf hzW (hlz.trans hly'.symm) h)
  rcases C.R.ctl_writes hW hl with hinit | hb1 | ⟨A, hs⟩ | ⟨A, h4⟩
  · exact ⟨y, Or.inl hinit, Or.inl rfl, fun z h₁ h₂ => by rw [between_self hc h₁ h₂]⟩
  · obtain ⟨y', hrf, hst, _⟩ := C.R.b1_rmw hb1
    exact step y' hrf hst
  · obtain ⟨y', hrf, hst⟩ := C.seal_rmw hs
    exact step y' hrf hst
  · exact ⟨y, Or.inr ⟨A, h4⟩, Or.inl rfl, fun z h₁ h₂ => by rw [between_self hc h₁ h₂]⟩

/-- Nothing precedes the initialisation of a control word. -/
theorem not_mo_ctlInit (hc : X.Consistent) {x i : Ev} (hi : C.R.isCtlInit i) (h : X.mo x i) :
    False :=
  not_mo_first hc (hc.mo_wf h).2.1 (C.R.ctlInit_first hi) h

/-- Every control-word value written before a reset has at most the stamp
the reset writes. -/
theorem stamp_le_r4 (hc : X.Consistent) {B b q x : Ev} (h4 : C.R.r4Of B b)
    (hq : C.R.r0Of B q) (hxb : X.mo x b) (hxl : ∃ n, X.loc x = some (C.R.ctlLoc n)) :
    C.R.stamp (X.wval x) ≤ X.rval q + 1 := by
  have ⟨hxW, hbW, hl, hsome⟩ := hc.mo_wf hxb
  obtain ⟨bx, hbx, hbxle, hall⟩ := C.ctl_base hc x hxW hxl
  have hst : C.R.stamp (X.wval x) = C.R.stamp (X.wval bx) := hall x hbxle (Or.inl rfl)
  have hbxb : X.mo bx b := by
    rcases hbxle with h | h
    · rw [h]; exact hxb
    · exact hc.mo_trans h hxb
  rw [hst]
  rcases hbx with hinit | ⟨A, h4x⟩
  · have := C.ctlInit_stamp hinit
    have := C.R.r0_ge hc hq
    omega
  · obtain ⟨qx, hqx, hstx⟩ := C.R.r4_r0 h4x
    rw [hstx]
    have ⟨hbxW, _, hlb, hsb⟩ := hc.mo_wf hbxb
    refine Nat.succ_le_succ (Nat.le_of_not_lt fun hlt => ?_)
    have hb₁ : X.hb b bx :=
      C.R.toOwnerProtocol.tenures_ordered hc (C.R.r4_acq h4) (C.R.r4_acq h4x)
        (C.R.tenure_lt_of_r0_lt hc hq hqx hlt) (C.R.r4_under h4) (C.R.r4_under h4x)
    exact hc.mo_irrefl _ (hc.mo_trans hbxb (coh_ww hc hbW hbxW hlb.symm (hlb ▸ hsb) hb₁))

/-- **SP1.** The `fetch_add` of a store attempt for epoch `e` reads a
control-word value with stamp `e`: it lands inside its own incarnation. -/
theorem b1_lands (hc : X.Consistent) {i : C.ι} {w b : Ev} (hw : C.isAttempt i w)
    (hb : (C.pins i).inAttempt w b) (hb1 : C.R.isB1 b) :
    C.R.stamp (X.rval b) = C.attEpoch w := by
  obtain ⟨hbW, hbl, l, u, hlb, hul, hll, hstu⟩ := C.b1_after_b0 hw hb hb1
  obtain ⟨wb, hwb, _, _⟩ := C.R.b1_rmw hb1
  have ⟨hwbW, _, hlwb, hswb⟩ := hc.rf_wf hwb
  have ⟨huW, _, hlu, _⟩ := hc.rf_wf hul
  have hwbl : X.loc wb = some (C.R.ctlLoc (C.attEpoch w)) := hlwb.trans hbl
  have hul' : X.loc u = some (C.R.ctlLoc (C.attEpoch w)) := hlu.trans hll
  have hwbb : X.mo wb b := hc.rmw_mo hwb hbW
  have hub : X.mo u b := coh_rw hc hbW (hbl.trans hll.symm) (hb_of_sb hlb) hul
  have hu_le : u = wb ∨ X.mo u wb := rmw_pred_ge hc hwb huW (hul'.trans hwbl.symm) hub
  obtain ⟨bw, hbw, hbwle, hall⟩ := C.ctl_base hc wb hwbW ⟨_, hwbl⟩
  have hst : C.R.stamp (X.wval wb) = C.R.stamp (X.wval bw) := hall wb hbwle (Or.inl rfl)
  have hbwb : X.mo bw b := by
    rcases hbwle with h | h
    · rw [h]; exact hwbb
    · exact hc.mo_trans h hwbb
  have ⟨hbwW, _, hlbw, hsbw⟩ := hc.mo_wf hbwb
  have hbwl : X.loc bw = some (C.R.ctlLoc (C.attEpoch w)) := hlbw.trans hbl
  have he := C.att_ge hc hw
  rw [hc.rf_val hwb, hst]
  -- The base is not older than what B0 read ...
  have lower : C.attEpoch w ≤ C.R.stamp (X.wval bw) := by
    rcases hc.mo_total hbwW huW (hbwl.trans hul'.symm) hsbw with heq | h | h
    · rw [heq, hstu]; exact Nat.le_refl _
    · have := hall u (Or.inr h) (hu_le.elim Or.inl Or.inr)
      omega
    · rcases hbw with hinit | ⟨B, h4⟩
      · exact (C.not_mo_ctlInit hc hinit h).elim
      · obtain ⟨q, hq, hst4⟩ := C.R.r4_r0 h4
        have := C.stamp_le_r4 hc h4 hq h ⟨_, hul'⟩
        omega
  rcases hbw with hinit | ⟨B, h4⟩
  · have := C.ctlInit_stamp hinit
    omega
  · obtain ⟨q, hq, hst4⟩ := C.R.r4_r0 h4
    -- ... and not a reset of a later incarnation: those come after the attempt.
    have upper : X.rval q < C.attEpoch w + 2 := by
      refine Nat.lt_of_not_le (fun hge => ?_)
      have hb₁ : X.hb b bw := C.attempt_before_resets hc hw h4 hq hge hb
      exact hc.mo_irrefl _
        (hc.mo_trans hbwb (coh_ww hc hbW hbwW hlbw.symm (hlbw ▸ hsbw) hb₁))
    -- It is on this arena's control word, so its stamp is not `e + 1` or `e + 2`.
    have hloc := (C.r4_loc h4 hq).symm.trans hbwl
    have hd := C.ctl_distinct (C.attEpoch w)
    have hcases : X.rval q + 1 = C.attEpoch w ∨ X.rval q + 1 = C.attEpoch w + 1 ∨
        X.rval q + 1 = C.attEpoch w + 2 := by omega
    rcases hcases with h | h | h
    · omega
    · rw [h] at hloc; exact (hd.1 (Option.some.inj hloc)).elim
    · rw [h] at hloc; exact (hd.2 (Option.some.inj hloc)).elim

/-- **SP1, the reset, for the whole attempt.** Every reset that writes an
incarnation's stamp happens before everything a store attempt for that
incarnation does from its pin W2 on. There can be several such resets: a
tenure that dies between R4 and R5 is taken over by one that rechecks the
same epoch and resets again. -/
theorem r4_before_att (hc : X.Consistent) {i : C.ι} {w a B w4 qB : Ev}
    (hw : C.isAttempt i w) (ha : a = w ∨ X.sb w a)
    (h4 : C.R.r4Of B w4) (hqB : C.R.r0Of B qB)
    (hst : X.rval qB + 1 = C.attEpoch w) : X.hb w4 a := by
  -- W1 read the epoch from the R5 of a tenure `B'` ...
  obtain ⟨q1, s, hsq, hqacq, hql, hqw, hqv⟩ := C.att_w1 hw
  have ⟨hsW, _, hls, _⟩ := hc.rf_wf hsq
  have hsv : X.wval s = C.attEpoch w := by rw [← hc.rf_val hsq]; exact hqv
  have hge := C.R.r0_ge hc hqB
  rcases C.R.ep_writes hsW (hls.trans hql) with hinit | ⟨B', h5⟩
  · rw [hinit, C.R.epInit_val] at hsv; omega
  -- ... whose reset happens before the attempt (EP4) ...
  obtain ⟨w4', h4', _⟩ := C.r5_r4 h5
  have h₁ : X.hb w4' a :=
    hb_sbr (hb_trans (C.reset_before_w1 h5 h4' hsq hqacq) (hb_of_sb hqw)) ha
  -- ... and `B` is `B'` or an earlier tenure: a later one rechecks more.
  rcases C.R.toOwnerProtocol.tenure_total hc (C.R.r4_acq h4) (C.R.r5_acq h5)
    with heq | hmo | hmo
  · subst heq
    have := C.r4_unique h4 h4'
    subst this
    exact h₁
  · exact hb_trans
      (C.R.toOwnerProtocol.tenures_ordered hc (C.R.r4_acq h4) (C.R.r5_acq h5) hmo
        (C.R.r4_under h4) (C.R.r4_under h4')) h₁
  · have := C.R.r0_after_r5 hc h5 hqB hmo
    omega

/-- **SP1, the reset.** Every reset that writes an incarnation's stamp
happens before every `fetch_add` of a store attempt for that incarnation. -/
theorem r4_before_b1 (hc : X.Consistent) {i : C.ι} {w b B w4 qB : Ev}
    (hw : C.isAttempt i w) (hb : (C.pins i).inAttempt w b)
    (h4 : C.R.r4Of B w4) (hqB : C.R.r0Of B qB)
    (hst : X.rval qB + 1 = C.attEpoch w) : X.hb w4 b :=
  C.r4_before_att hc hw hb.1 h4 hqB hst

end CacheProtocol
end Execution
end Sgc
