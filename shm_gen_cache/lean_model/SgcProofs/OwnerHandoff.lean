import SgcProofs.OwnerBounds
import SgcProofs.ClaimClosure

/-!
# OW1 from owner-word provenance and the branches of `reap_owner`

The source premises below concern stored values, registration lifetimes,
local control flow and actual reads. They do not contain a handoff or an
`hb` edge. `owner_registration_of_reads` connects the registration to the
CAS actually replaced by O3, allowing O1 to read a different tenure.

`OwnerRegistrationSource.acquire_read` records the successful registration
P2/P5 -> ParticipantLock -> insert/lookup -> rotate call chain. The slot
index belongs to that lock; the id is decoded from an actual Relaxed
state load. `own_registration_read` proves that load reads its register.
This relies on the lock's construction/lifetime contract and A2, not on
`production_assert!` (which is omitted in release/verify). `before_free`
is exactly A2's original-registration lifetime; `tenure_thread` permits
work for a second registration during reap_pinner. `id_unique` is A4,
restricted to the id held by this O1 operation in this slot: it does not
require the u32 counter never to wrap in the entire cache lifetime.

`ReapOwnerReturn` translates the four true outcomes at rotation.rs's
match. P0 is Acquire; Other compares the state's high u32 against the
owner's expected id; Lost is a strong failed CAS with Acquire failure
ordering and a value unequal to its P0 expected word. Stolen carries the
actual P4 target and the raw successful expected-value check, not a
premise that the target is the old owner. State encoding, A4 and SL1
identify the successful P4 RF source when P0 read the old register. Live
and errors have no constructor because they do not proceed to this O3.
-/

namespace Sgc.Execution
variable {Ev Loc : Type} {X : Execution Ev Loc} {Lv : Liveness X}

/-- A registered claim's own ordinary state load reads its register.
Coherence excludes earlier writes. Any later write follows its ender;
A2 or SL1 puts this same load before that ender, contradicting COH-RW.
This is the missing bridge for the Relaxed `registration_id()` load. -/
theorem own_registration_read (hc : X.Consistent) (S : SlotProtocol X Lv)
    {c g u w : Ev} (hg : S.regOf c g) (hu : S.act c u)
    (hgu : X.sb g u) (hrf : X.rf w u) (hul : X.loc u = some S.st)
    (hload : ¬ X.isW u) : w = g := by
  rcases coh_wr hc (S.reg_w hg) ((S.reg_loc hg).trans hul.symm)
      (hb_of_sb hgu) hrf with heq | hmo
  · exact heq
  · obtain ⟨E, hE, hle⟩ := S.ender_before hc hg w hmo
    have hfacts : X.isW E ∧ X.loc E = some S.st ∧ X.hb u E := by
      rcases hE with hf | hs
      · refine ⟨S.free_w hf, S.free_loc hf, ?_⟩
        rcases S.act_before_free hf hu with heq | hseq
        · subst E
          exact (hload (S.free_w hf)).elim
        · exact hb_of_sb hseq
      · have hclaim : S.isClaim E := Or.inr (S.stealOf_claims hs).2
        exact ⟨S.claim_w hclaim, S.claim_loc hclaim,
          S.serial hc E hclaim c (S.reg_claim hg) (S.mo_of_stealOf hc hs) u hu⟩
    have hback := coh_rw hc hfacts.1 (hfacts.2.1.trans hul.symm) hfacts.2.2 hrf
    rcases hle with heq | hforward
    · subst E
      exact (hc.mo_irrefl _ hback).elim
    · exact (hc.mo_irrefl _ (hc.mo_trans hback hforward)).elim

/-- The lock provenance at one decoded participant slot. -/
structure OwnerRegistrationData (O : OwnerEvents X) (S : SlotProtocol X Lv)
    (index : Fin wordLimit) (expected : Nat) where
  id : Ev → Fin wordLimit
  acquire_read : ∀ {A}, O.isAcqCas A → OwnerWord.slot (X.wval A) = index.val →
    ∃ c g u w, S.regOf c g ∧ S.act c A ∧ S.act c u ∧
      X.sb g u ∧ X.sb g A ∧ X.rf w u ∧ X.loc u = some S.st ∧ ¬ X.isW u ∧
      X.wval A = OwnerWord.pack index.val (OwnerWord.registration (X.rval u))
  registered_id : ∀ {c g}, S.regOf c g →
    OwnerWord.registration (X.wval g) = (id c).val
  init_zero : X.wval S.init = 0
  free_zero : ∀ {c x}, S.freeOf c x → X.wval x = 0
  claim_initializing : ∀ {c}, S.isClaim c → X.wval c % 2 = 1
  register_even : ∀ {c g}, S.regOf c g → X.wval g % 2 = 0
  id_unique : ∀ {c c'}, S.isClaim c → S.isClaim c' →
    (id c).val = expected → (id c').val = expected → c = c'
  tenure_thread : ∀ {A e}, O.isAcqCas A → O.under A e → Lv.thrOf e = Lv.thrOf A

/-- Registration provenance plus tenure lifetime. OwnerCleanup derives
the latter from the actual RAII scopes before the registration's P3. -/
structure OwnerRegistrationSource (O : OwnerEvents X) (S : SlotProtocol X Lv)
    (index : Fin wordLimit) (expected : Nat)
    extends OwnerRegistrationData O S index expected where
  before_free : ∀ {c A e x}, O.isAcqCas A → S.act c A → O.under A e → S.freeOf c x →
    e = x ∨ X.sb e x

/-- An acquiring owner CAS encodes its lock's claim id, derived from
the actual registration_id load and the stored state word. -/
theorem OwnerRegistrationSource.acquire_origin
    {O : OwnerEvents X} {S : SlotProtocol X Lv} {index : Fin wordLimit} {expected : Nat}
    (R : OwnerRegistrationSource O S index expected) (hc : X.Consistent)
    {A : Ev} (hA : O.isAcqCas A) (hi : OwnerWord.slot (X.wval A) = index.val) :
    ∃ c g, S.regOf c g ∧ S.act c A ∧ X.sb g A ∧
      X.wval A = OwnerWord.pack index.val (R.id c).val := by
  obtain ⟨c, g, u, w, hg, hca, hcu, hgu, hga, hrf, hul, hnw, hv⟩ :=
    R.acquire_read hA hi
  have heq := own_registration_read hc S hg hcu hgu hrf hul hnw
  subst w
  refine ⟨c, g, hg, hca, hga, ?_⟩
  simpa only [hc.rf_val hrf, R.registered_id hg] using hv

/-- O1 and the successful O3's read. Equality here is the ordinary strong
CAS success check against the owner value loaded at O1. It is not RF
source equality: owner-word ABA is allowed. -/
structure OwnerTakeoverRead (O : OwnerEvents X) (A T A' l : Ev) : Prop where
  replaced : O.isAcqCas A
  takeover : O.isAcqCas T
  predecessor : X.rf A T
  observed : X.rf A' l
  load_location : X.loc l = some O.own
  load_acquire : X.isAcq l
  nonempty : OwnerWord.registration (X.rval l) ≠ 0
  expected : X.rval T = X.rval l

/-- The packed word identifies the *same* registered claim at O1's source
and at O3's predecessor, even when these are distinct acquiring CASes. -/
theorem owner_registration_of_reads {O : OwnerEvents X} {S : SlotProtocol X Lv}
    {capacity : Nat} (W : OwnerSource O capacity) (hc : X.Consistent)
    {index : Fin wordLimit} {A T A' l : Ev}
    (R : OwnerRegistrationSource O S index (OwnerWord.registration (X.rval l)))
    (D : OwnerTakeoverRead O A T A' l)
    (hi : OwnerWord.slot (X.rval l) = index.val) :
    O.isAcqCas A' ∧ ∃ c g, S.regOf c g ∧ S.act c A ∧ S.act c A' ∧
      X.sb g A' ∧ (R.id c).val = OwnerWord.registration (X.rval l) := by
  have hA' := (owner_load_source W hc D.observed D.load_location D.nonempty).1
  have hvA : X.wval A = X.rval l := (hc.rf_val D.predecessor).symm.trans D.expected
  have hvA' : X.wval A' = X.rval l := (hc.rf_val D.observed).symm
  obtain ⟨c, g, hg, hca, _, hv⟩ := R.acquire_origin hc D.replaced (hvA ▸ hi)
  obtain ⟨c', g', hg', hca', hga', hv'⟩ := R.acquire_origin hc hA' (hvA' ▸ hi)
  have hid : (R.id c).val = OwnerWord.registration (X.rval l) := by
    exact (OwnerWord.registration_pack index.isLt (R.id c).isLt).symm.trans
      (congrArg OwnerWord.registration (hv.symm.trans hvA))
  have hid' : (R.id c').val = OwnerWord.registration (X.rval l) := by
    exact (OwnerWord.registration_pack index.isLt (R.id c').isLt).symm.trans
      (congrArg OwnerWord.registration (hv'.symm.trans hvA'))
  have heq := R.id_unique (S.reg_claim hg) (S.reg_claim hg') hid hid'
  subst c'
  exact ⟨hA', c, g', hg', hca, hca', hga', hid⟩

/-- Source-level control flow of a reaping call returning true to O3.
The state address is that of `participant(owner.slot())`; the expected
registration is the high u32 of precisely the owner value O3 expects. -/
inductive ReapOwnerReturn (S : SlotProtocol X Lv) (l T : Ev) : Prop where
  | free (p w : Ev)
      (read : X.rf w p) (acquire : X.isAcq p) (location : X.loc p = some S.st)
      (after_load : X.sb l p) (before_cas : X.sb p T)
      (guard : X.rval p = 0) : ReapOwnerReturn S l T
  | other (p w : Ev)
      (read : X.rf w p) (acquire : X.isAcq p) (location : X.loc p = some S.st)
      (after_load : X.sb l p) (before_cas : X.sb p T)
      (guard : OwnerWord.registration (X.rval p) ≠
        OwnerWord.registration (X.rval l)) : ReapOwnerReturn S l T
  | stolen (p w c' cs v : Ev)
      (read : X.rf w p) (acquire : X.isAcq p) (location : X.loc p = some S.st)
      (after_load : X.sb l p) (before_cas : X.sb p T)
      (expected_id : OwnerWord.registration (X.rval p) =
        OwnerWord.registration (X.rval l))
      (steal : S.stealOf c' cs) (cas_read : X.rf v cs)
      (cas_expected : X.rval cs = X.rval p)
      (steal_before : X.sb cs T) : ReapOwnerReturn S l T
  | lost (p w q v : Ev)
      (read : X.rf w p) (acquire : X.isAcq p) (location : X.loc p = some S.st)
      (after_load : X.sb l p) (before_cas : X.sb p T)
      (expected_id : OwnerWord.registration (X.rval p) =
        OwnerWord.registration (X.rval l))
      (failed_read : X.rf v q) (failed_acquire : X.isAcq q)
      (failed_location : X.loc q = some S.st)
      (after_load_failed : X.sb l q) (failed_before : X.sb q T)
      (changed : X.rval q ≠ X.rval p) : ReapOwnerReturn S l T

/-- RF uniqueness and the slot protocol identify the claim actually
stolen when successful P4 reads the owner's registering write. -/
theorem steal_of_register_read (hc : X.Consistent) (S : SlotProtocol X Lv)
    {c c' g cs : Ev} (hg : S.regOf c g) (hs : S.stealOf c' cs)
    (hrf : X.rf g cs) : S.stealOf c cs := by
  obtain ⟨w, o, vd, hw, _, _, _, _, hcase⟩ := S.steal_ev hs
  have heq := hc.rf_unique hw hrf
  subst w
  rcases hcase with ⟨heq, _⟩ | ⟨hreg, _⟩
  · subst c'
    exact (S.reg_not_claim hg (S.stealOf_claims hs).1).elim
  · have heq := S.reg_owner hg hreg
    subst c'
    exact hs

/-- Decode a state write equal to the original REGISTERED word. Init
and P3 write zero; P1/P4 have the INITIALIZING bit set. The remaining
P2/P5 has the expected id, so A4 identifies its claim and SL1 identifies
its unique register event. This derives successful P4's RF identity from
its raw expected value, rather than assuming the stolen claim is G. -/
theorem register_source_of_value {O : OwnerEvents X} {S : SlotProtocol X Lv}
    (hc : X.Consistent) {index : Fin wordLimit} {expected : Nat}
    (R : OwnerRegistrationSource O S index expected) (hne : expected ≠ 0)
    {c g w : Ev} (hg : S.regOf c g) (hid : (R.id c).val = expected)
    (hw : X.isW w) (hl : X.loc w = some S.st) (hv : X.wval w = X.wval g) :
    w = g := by
  have hnonzero : X.wval g ≠ 0 := by
    intro hz
    have h := (R.registered_id hg).trans hid
    rw [hz] at h
    exact hne h.symm
  rcases S.writes hw hl with hinit | hclaim | ⟨c', hreg⟩ | ⟨c', hfree⟩
  · subst w
    exact (hnonzero (hv.symm.trans R.init_zero)).elim
  · have h := R.claim_initializing hclaim
    rw [hv, R.register_even hg] at h
    contradiction
  · have hid' : (R.id c').val = expected :=
      (R.registered_id hreg).symm.trans
        ((congrArg OwnerWord.registration hv).trans ((R.registered_id hg).trans hid))
    have heq := R.id_unique (S.reg_claim hg) (S.reg_claim hreg) hid hid'
    subst c'
    exact claim_register_unique hc S hreg hg
  · exact (hnonzero (hv.symm.trans (R.free_zero hfree))).elim

/-- All four return paths derive the handoff evidence. Neither `hb g q`
nor the identity of the stolen claim is a branch premise. -/
theorem takeover_evidence_of_source {O : OwnerEvents X} {S : SlotProtocol X Lv}
    {capacity : Nat} (W : OwnerSource O capacity) (hc : X.Consistent)
    {index : Fin wordLimit} {A T A' l : Ev}
    (R : OwnerRegistrationSource O S index (OwnerWord.registration (X.rval l)))
    (D : OwnerTakeoverRead O A T A' l)
    (hi : OwnerWord.slot (X.rval l) = index.val)
    (B : ReapOwnerReturn S l T) :
    ∃ c, S.act c A ∧ (R.id c).val = OwnerWord.registration (X.rval l) ∧
      TakeoverEvidence O S A T c := by
  obtain ⟨hA', c, g, hg, hca, _, hga, hid⟩ :=
    owner_registration_of_reads W hc R D hi
  refine ⟨c, hca, hid, S.reg_claim hg, ?_, ?_, ?_⟩
  · intro e he
    exact (R.tenure_thread D.replaced he).trans (S.act_thr hca)
  · intro e he x hx
    exact R.before_free D.replaced hca he hx
  · have read_case : ∀ {p w}, X.rf w p → X.isAcq p → X.loc p = some S.st →
        X.sb l p → X.sb p T → w ≠ g →
        ∃ g' q v, S.regOf c g' ∧ X.hb g' q ∧ X.rf v q ∧ X.isAcq q ∧
          X.loc q = some S.st ∧ v ≠ g' ∧ X.sb q T := by
      intro p w hrf ha hl hseq hbefore hne
      exact ⟨g, p, w, hg,
        hb_reg_of_owner_load O hA' D.observed D.load_acquire hga hseq,
        hrf, ha, hl, hne, hbefore⟩
    cases B with
    | free p w hrf ha hl hseq hbefore hz =>
      apply Or.inr
      apply read_case hrf ha hl hseq hbefore
      intro heq
      subst w
      have hidg := (R.registered_id hg).trans hid
      rw [← hc.rf_val hrf, hz] at hidg
      exact D.nonempty hidg.symm
    | other p w hrf ha hl hseq hbefore hne =>
      apply Or.inr
      apply read_case hrf ha hl hseq hbefore
      intro heq
      subst w
      exact hne ((congrArg OwnerWord.registration (hc.rf_val hrf)).trans
        ((R.registered_id hg).trans hid))
    | stolen p w c' cs v hrf ha hl hseq hbefore _ hs hcs hexpected hsT =>
      by_cases heq : w = g
      · subst w
        have hvloc := (hc.rf_wf hcs).2.2.1.trans
          (S.claim_loc (Or.inr (S.stealOf_claims hs).2))
        have hval : X.wval v = X.wval g :=
          (hc.rf_val hcs).symm.trans (hexpected.trans (hc.rf_val hrf))
        have heq := register_source_of_value hc R D.nonempty hg hid
          (hc.rf_wf hcs).1 hvloc hval
        subst v
        exact Or.inl ⟨cs, steal_of_register_read hc S hg hs hcs, hsT⟩
      · exact Or.inr (read_case hrf ha hl hseq hbefore heq)
    | lost p w q v hrf ha hl hseq hbefore _ hv hqa hql hlq hqT hne =>
      apply Or.inr
      by_cases heq : w = g
      · subst w
        apply read_case hv hqa hql hlq hqT
        intro heq
        subst v
        exact hne ((hc.rf_val hv).trans (hc.rf_val hrf).symm)
      · exact read_case hrf ha hl hseq hbefore heq

/-- **OW1, source-connected form.** The registration is the one whose
slot/id was stored by the replaced tenure. Its actions and all tenure
actions precede O3, and the tenure has no release O4. O3 need not acquire. -/
theorem owner_handoff_from_source {O : OwnerEvents X} {S : SlotProtocol X Lv}
    {capacity : Nat} (W : OwnerSource O capacity) (hc : X.Consistent)
    {index : Fin wordLimit} {A T A' l : Ev}
    (R : OwnerRegistrationSource O S index (OwnerWord.registration (X.rval l)))
    (D : OwnerTakeoverRead O A T A' l)
    (hi : OwnerWord.slot (X.rval l) = index.val)
    (B : ReapOwnerReturn S l T) :
    ∃ c, S.act c A ∧ (R.id c).val = OwnerWord.registration (X.rval l) ∧
      (∀ e, S.act c e → X.hb e T) ∧
      (∀ e, O.under A e → X.hb e T) ∧ (∀ r, ¬ O.relOf A r) := by
  obtain ⟨c, hca, hid, he⟩ := takeover_evidence_of_source W hc R D hi B
  exact ⟨c, hca, hid, registration_actions_before_takeover hc O S he,
    takeover_of_evidence hc O S he,
    no_release_of_takeover_evidence hc O S he D.replaced D.takeover D.predecessor⟩

/-- Build the owner protocol from code traces for each successful O3.
The caller supplies local source facts, never `TakeoverEvidence`. -/
def OwnerEvents.toProtocolFromSource (O : OwnerEvents X) (hc : X.Consistent)
    (code : ∀ {A T}, O.isAcqCas A → O.isAcqCas T → X.rf A T →
      ∃ (capacity : Nat) (_W : OwnerSource O capacity)
        (S : SlotProtocol X Lv) (index : Fin wordLimit) (A' l : Ev)
        (_R : OwnerRegistrationSource O S index (OwnerWord.registration (X.rval l))),
        OwnerTakeoverRead O A T A' l ∧ OwnerWord.slot (X.rval l) = index.val ∧
          ReapOwnerReturn S l T) : OwnerProtocol X :=
  O.toProtocol hc (fun hA hT hrf => by
    obtain ⟨capacity, W, S, index, A', l, R, D, hi, B⟩ := code hA hT hrf
    obtain ⟨c, _, _, he⟩ := takeover_evidence_of_source W hc R D hi B
    exact ⟨S, c, he⟩)

end Sgc.Execution
