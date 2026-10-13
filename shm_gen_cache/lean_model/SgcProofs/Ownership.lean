import SgcProofs.Model

/-!
# OW2, ownership is a lock under `hb`

The rotation-owner word is written by three kinds of event: the
initialisation, an *acquiring CAS* (O2 from `NONE`, or the takeover O3 of a
dead owner) and a *release* (O4, a plain store of `NONE`). A *tenure* is
identified with its acquiring CAS.

`OwnerEvents` records what the code does on this word. `OwnerProtocol` adds
one field that is not a fact about the owner word alone: `takeover` says
that when a CAS takes ownership over from a tenure, everything that tenure's
holder did under ownership happens before the CAS. This is OW1;
`Takeover.lean` derives it from slot serialisation (SL1), the liveness
backend (A3) and what `reap_owner()` observed (code fact F6).

O3 is a release CAS, not an acquire (`rotation.rs`, success ordering
`Release`): `acq_isAcq` asks acquire only of a CAS that reads the
initialisation or an O4, so no proof uses an acquire half of O3.

From it this file proves, for every consistent execution:

* `no_release_of_takeover`: a tenure that is taken over never released;
* `release_adjacent`, `pred_or_before`, `mo_of_pred` (OW2(a)): a release
  immediately follows its acquiring CAS in the modification order, and the
  word is a sequence of blocks;
* `handoff_step`, `tenure_serial`, `tenures_ordered` (OW2(b)): tenures are
  serial. Everything done under an earlier tenure happens before the
  acquiring CAS of a later one, and before everything done under it;
* `tenure_total` (OW2(c)): tenures are totally ordered.
-/

namespace Sgc
namespace Execution

variable {Ev Loc : Type}

/-- The events on the rotation-owner word, and what the code guarantees
about them. -/
structure OwnerEvents (X : Execution Ev Loc) where
  /-- The rotation-owner word. -/
  own : Loc
  /-- Its initialisation (`NONE`). -/
  init : Ev
  /-- A successful O2 or O3. -/
  isAcqCas : Ev → Prop
  /-- `relOf A r`: `r` is the release (O4) of the tenure started by `A`. -/
  relOf : Ev → Ev → Prop
  init_w : X.isW init
  init_loc : X.loc init = some own
  /-- Assumption A1: initialisation happens before every other access. -/
  init_first : ∀ {e}, X.isW e → X.loc e = some own → e ≠ init → X.hb init e
  /-- Nothing else writes the owner word. -/
  writes : ∀ {e}, X.isW e → X.loc e = some own →
    e = init ∨ isAcqCas e ∨ ∃ A, relOf A e
  acq_w : ∀ {A}, isAcqCas A → X.isW A
  acq_loc : ∀ {A}, isAcqCas A → X.loc A = some own
  /-- An O2, a CAS that reads the initialisation or a release (O4), has
  acquire ordering, to synchronise with that release. A takeover O3 reads
  an acquiring CAS and need not be an acquire: the code makes it `Release`
  (`rotation.rs`), and its handoff (OW1) comes from O1's acquire, from
  `reap_owner()`'s acquire reads of the slot and from A3. The takeover
  witnesses make their O3 release-only. -/
  acq_isAcq : ∀ {A w}, isAcqCas A → X.rf w A → ¬ isAcqCas w → X.isAcq A
  /-- Every acquiring CAS (O2, O3) has release ordering: later O1 loads and
  takeovers import from it. -/
  acq_isRel : ∀ {A}, isAcqCas A → X.isRel A
  acq_reads : ∀ {A}, isAcqCas A → ∃ w, X.rf w A
  rel_acq : ∀ {A r}, relOf A r → isAcqCas A
  rel_w : ∀ {A r}, relOf A r → X.isW r
  rel_loc : ∀ {A r}, relOf A r → X.loc r = some own
  /-- O4 is a release store. -/
  rel_isRel : ∀ {A r}, relOf A r → X.isRel r
  rel_not_acq : ∀ {A r}, relOf A r → ¬ isAcqCas r
  /-- The holder releases after acquiring. -/
  rel_sb : ∀ {A r}, relOf A r → X.sb A r
  /-- A tenure releases at most once. -/
  rel_unique : ∀ {A r r'}, relOf A r → relOf A r' → r = r'

/-- `under A e`: the holder of tenure `A` performs `e` under ownership, that
is, from its acquiring CAS up to its release, if it has one. -/
def OwnerEvents.under {X : Execution Ev Loc} (O : OwnerEvents X) (A e : Ev) : Prop :=
  (e = A ∨ X.sb A e) ∧ ∀ r, O.relOf A r → (e = r ∨ X.sb e r)

/-- The owner word's events together with the takeover handoff. -/
structure OwnerProtocol (X : Execution Ev Loc) extends OwnerEvents X where
  /-- **Takeover handoff (OW1).** When the CAS `T` takes ownership
  over from tenure `A`, every action of `A`'s holder under ownership happens
  before `T`. `Takeover.lean` derives it from slot serialisation (SL1) and
  the liveness backend (A3). -/
  takeover : ∀ {A T}, isAcqCas A → isAcqCas T → X.rf A T →
    ∀ e, toOwnerEvents.under A e → X.hb e T

namespace OwnerProtocol

variable {X : Execution Ev Loc} (P : OwnerProtocol X)

/-- `under A e`: see `OwnerEvents.under`. -/
abbrev under (A e : Ev) : Prop := P.toOwnerEvents.under A e

theorem under_self {A : Ev} : P.under A A :=
  ⟨Or.inl rfl, fun _ hr => Or.inr (P.rel_sb hr)⟩

theorem under_release {A r : Ev} (hr : P.relOf A r) : P.under A r :=
  ⟨Or.inr (P.rel_sb hr), fun _ hr' => Or.inl (P.rel_unique hr hr')⟩

/-- Nothing precedes the initialisation. -/
theorem not_mo_init (hc : X.Consistent) {e : Ev} (h : X.mo e P.init) : False :=
  not_mo_first hc P.init_w (fun he hl => P.init_first he (hl.trans P.init_loc)) h

/-- The initialisation is not an acquiring CAS: those read an earlier
write. -/
theorem init_not_acq (hc : X.Consistent) : ¬ P.isAcqCas P.init := fun h =>
  let ⟨_, hrf⟩ := P.acq_reads h; P.not_mo_init hc (hc.rmw_mo hrf P.init_w)

/-- A tenure that is taken over has no release (OW1, its consequence). -/
theorem no_release_of_takeover (hc : X.Consistent) {A T : Ev}
    (hA : P.isAcqCas A) (hT : P.isAcqCas T) (hrf : X.rf A T) {r : Ev}
    (hr : P.relOf A r) : False := by
  have hu := P.under_release hr
  have h₁ : X.mo r T :=
    coh_ww_at hc (P.rel_w hr) (P.rel_loc hr) (P.acq_w hT) (P.acq_loc hT)
      (P.takeover hA hT hrf r hu)
  have h₀ : X.mo A r :=
    coh_ww_at hc (P.acq_w hA) (P.acq_loc hA) (P.rel_w hr) (P.rel_loc hr)
      (hb_of_sb (P.rel_sb hr))
  exact rmw_adjacent hc hrf h₀ h₁

/-- **OW2(a), O4 adjacency.** No write lies between an acquiring CAS and
its release in the modification order. -/
theorem release_adjacent (hc : X.Consistent) (A : Ev) :
    P.isAcqCas A → ∀ r, P.relOf A r → ∀ v, X.mo A v → X.mo v r → False := by
  refine hc.mo_wellFounded.induction
    (C := fun A => P.isAcqCas A → ∀ r, P.relOf A r → ∀ v, X.mo A v → X.mo v r → False) A ?_
  intro A ihA hA r hr v
  refine hc.mo_wellFounded.induction (C := fun v => X.mo A v → X.mo v r → False) v ?_
  intro v ihv hAv hvr
  have ⟨_, hvW, hl, _⟩ := hc.mo_wf hAv
  have hlv : X.loc v = some P.own := hl.symm.trans (P.acq_loc hA)
  have hsome : X.loc v ≠ none := some_ne_none hlv
  rcases P.writes hvW hlv with hinit | hvacq | ⟨A₀, hr₀⟩
  · -- `v` is the initialisation: nothing precedes it.
    subst hinit; exact P.not_mo_init hc hAv
  · -- `v` is an acquiring CAS; look at the write `w` it read.
    obtain ⟨w, hwv⟩ := P.acq_reads hvacq
    have ⟨hwW, _, hlw, hsw⟩ := hc.rf_wf hwv
    rcases hc.mo_total hwW (P.acq_w hA) (hlw.trans (hlv.trans (P.acq_loc hA).symm)) hsw
      with heq | hwA | hAw
    · subst heq; exact P.no_release_of_takeover hc hA hvacq hwv hr
    · exact rmw_adjacent hc hwv hwA hAv
    · exact ihv w (hc.rmw_mo hwv hvW) hAw (hc.mo_trans (hc.rmw_mo hwv hvW) hvr)
  · -- `v` is the release of another tenure `A₀`.
    have hA₀ := P.rel_acq hr₀
    have h₀v : X.mo A₀ v :=
      coh_ww_at hc (P.acq_w hA₀) (P.acq_loc hA₀) hvW hlv (hb_of_sb (P.rel_sb hr₀))
    rcases hc.mo_total (P.acq_w hA₀) (P.acq_w hA)
        ((P.acq_loc hA₀).trans (P.acq_loc hA).symm)
        (some_ne_none (P.acq_loc hA₀)) with heq | h₀A | hA₀'
    · subst heq
      have := P.rel_unique hr hr₀
      subst this
      exact hc.mo_irrefl _ hvr
    · exact ihA A₀ h₀A hA₀ v hr₀ A h₀A hAv
    · exact ihv A₀ h₀v hA₀' (hc.mo_trans h₀v hvr)

/-- `pred A B`: tenure `A` hands ownership to tenure `B`, either through
its release (O4, read by O2) or by being taken over (O3). -/
def pred (A B : Ev) : Prop :=
  P.isAcqCas A ∧ P.isAcqCas B ∧ (X.rf A B ∨ ∃ r, P.relOf A r ∧ X.rf r B)

theorem mo_of_pred (hc : X.Consistent) {A B : Ev} (h : P.pred A B) : X.mo A B := by
  obtain ⟨hA, hB, h | ⟨r, hr, hrf⟩⟩ := h
  · exact hc.rmw_mo h (P.acq_w hB)
  · exact hc.mo_trans
      (coh_ww_at hc (P.acq_w hA) (P.acq_loc hA) (P.rel_w hr) (P.rel_loc hr)
        (hb_of_sb (P.rel_sb hr)))
      (hc.rmw_mo hrf (P.acq_w hB))

/-- **OW2(b), one handoff.** Everything done under a tenure happens
before the acquiring CAS of the next. -/
theorem handoff_step {A B : Ev} (h : P.pred A B) {e : Ev} (he : P.under A e) :
    X.hb e B := by
  obtain ⟨hA, hB, h | ⟨r, hr, hrf⟩⟩ := h
  · exact P.takeover hA hB h e he
  · -- REL-ACQ: the release O4 synchronises with the acquiring CAS O2.
    have hsw : X.hb r B :=
      hb_of_sw (sw.relAcq (P.rel_isRel hr) (rs.refl r) hrf (P.acq_isAcq hB hrf (P.rel_not_acq hr)))
    exact sbr_hb (he.2 r hr) hsw

/-- **OW2(a), blocks.** An earlier tenure is the predecessor of a
later one, or precedes its predecessor. -/
theorem pred_or_before (hc : X.Consistent) {A B : Ev}
    (hA : P.isAcqCas A) (hB : P.isAcqCas B) (hAB : X.mo A B) :
    P.pred A B ∨ ∃ A', P.pred A' B ∧ X.mo A A' := by
  obtain ⟨w, hwB⟩ := P.acq_reads hB
  have ⟨hwW, _, hlw, hsw⟩ := hc.rf_wf hwB
  have hlw' : X.loc w = some P.own := hlw.trans (P.acq_loc hB)
  have hAw := hc.mo_total (P.acq_w hA) hwW ((P.acq_loc hA).trans hlw'.symm)
    (some_ne_none (P.acq_loc hA))
  rcases P.writes hwW hlw' with hinit | hwacq | ⟨A', hr'⟩
  · -- `B` read the initialisation: no tenure can precede it.
    subst hinit
    rcases hAw with heq | h | h
    · subst heq; exact absurd hA (P.init_not_acq hc)
    · exact absurd h (P.not_mo_init hc)
    · exact absurd (rmw_adjacent hc hwB h hAB) id
  · -- `B` took over from the acquiring CAS `w`.
    rcases hAw with heq | h | h
    · subst heq; exact Or.inl ⟨hA, hB, Or.inl hwB⟩
    · exact Or.inr ⟨w, ⟨hwacq, hB, Or.inl hwB⟩, h⟩
    · exact absurd (rmw_adjacent hc hwB h hAB) id
  · -- `B` read the release `w` of tenure `A'`.
    have hA' := P.rel_acq hr'
    rcases hAw with heq | hAw' | h
    · subst heq; exact absurd hA (P.rel_not_acq hr')
    · rcases hc.mo_total (P.acq_w hA) (P.acq_w hA')
          ((P.acq_loc hA).trans (P.acq_loc hA').symm)
          (some_ne_none (P.acq_loc hA)) with heq | h | h
      · subst heq; exact Or.inl ⟨hA, hB, Or.inr ⟨w, hr', hwB⟩⟩
      · exact Or.inr ⟨A', ⟨hA', hB, Or.inr ⟨w, hr', hwB⟩⟩, h⟩
      · exact absurd (P.release_adjacent hc A' hA' w hr' A h hAw') id
    · exact absurd (rmw_adjacent hc hwB h hAB) id

/-- **OW2(b).** Everything done under a tenure happens before the acquiring
CAS of every later tenure. -/
theorem tenure_serial (hc : X.Consistent) (B : Ev) :
    P.isAcqCas B → ∀ A, P.isAcqCas A → X.mo A B → ∀ e, P.under A e → X.hb e B := by
  refine hc.mo_wellFounded.induction
    (C := fun B => P.isAcqCas B → ∀ A, P.isAcqCas A → X.mo A B →
      ∀ e, P.under A e → X.hb e B) B ?_
  intro B ih hB A hA hAB e he
  rcases P.pred_or_before hc hA hB hAB with h | ⟨A', hpred, hAA'⟩
  · exact P.handoff_step h he
  · exact hb_trans (ih A' (P.mo_of_pred hc hpred) hpred.1 A hA hAA' e he)
      (P.handoff_step hpred P.under_self)

/-- **OW2, mutual exclusion under `hb`.** Everything done under an earlier
tenure happens before everything done under a later one. -/
theorem tenures_ordered (hc : X.Consistent) {A B e f : Ev}
    (hA : P.isAcqCas A) (hB : P.isAcqCas B) (hAB : X.mo A B)
    (he : P.under A e) (hf : P.under B f) : X.hb e f :=
  hb_sbr (P.tenure_serial hc B hB A hA hAB e he) hf.1

/-- **OW2(c).** Two tenures are the same or ordered by `mo`. -/
theorem tenure_total (hc : X.Consistent) {A B : Ev}
    (hA : P.isAcqCas A) (hB : P.isAcqCas B) : A = B ∨ X.mo A B ∨ X.mo B A :=
  hc.mo_total (P.acq_w hA) (P.acq_w hB) ((P.acq_loc hA).trans (P.acq_loc hB).symm)
    (some_ne_none (P.acq_loc hA))

end OwnerProtocol
end Execution
end Sgc
