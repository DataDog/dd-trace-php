import SgcProofs.Model

/-!
# Executions that follow one total order

A helper for building witnesses. If every base relation of an execution
agrees with a ranking of its events (a sequentially consistent run), the
acyclicity axioms of `Consistent` hold. Weak monotonicity of reads-before
suffices for these proofs.
-/

namespace Sgc
namespace Execution

variable {Ev Loc : Type}

/-- A ranking that every base relation of `X` respects. -/
structure Ranked (X : Execution Ev Loc) where
  rank : Ev → Nat
  sb_lt : ∀ {a b}, X.sb a b → rank a < rank b
  asw_lt : ∀ {a b}, X.asw a b → rank a < rank b
  rf_lt : ∀ {a b}, X.rf a b → rank a < rank b
  mo_lt : ∀ {a b}, X.mo a b → rank a < rank b
  rb_le : ∀ {a b}, X.rb a b → rank a ≤ rank b

namespace Ranked

variable {X : Execution Ev Loc} (K : Ranked X)

theorem rs_le {w w' : Ev} (h : X.rs w w') : K.rank w ≤ K.rank w' := by
  induction h with
  | refl => exact Nat.le_refl _
  | rmw _ hrf _ ih => exact Nat.le_trans ih (Nat.le_of_lt (K.rf_lt hrf))

theorem sw_lt {a b : Ev} (h : X.sw a b) : K.rank a < K.rank b := by
  cases h with
  | relAcq _ hrs hrf _ => exact Nat.lt_of_le_of_lt (K.rs_le hrs) (K.rf_lt hrf)
  | relFence _ hrs hrf hsb _ =>
      exact Nat.lt_trans (Nat.lt_of_le_of_lt (K.rs_le hrs) (K.rf_lt hrf)) (K.sb_lt hsb)
  | fenceAcq _ hsb _ hrs hrf _ =>
      exact Nat.lt_trans (K.sb_lt hsb) (Nat.lt_of_le_of_lt (K.rs_le hrs) (K.rf_lt hrf))
  | fenceFence _ hsb _ hrs hrf hsb' _ =>
      exact Nat.lt_trans (K.sb_lt hsb)
        (Nat.lt_trans (Nat.lt_of_le_of_lt (K.rs_le hrs) (K.rf_lt hrf)) (K.sb_lt hsb'))

theorem hb_lt {a b : Ev} (h : X.hb a b) : K.rank a < K.rank b :=
  transGen_lt_of_rank
    (fun _ _ hs => hs.elim K.sb_lt (fun h => h.elim K.sw_lt K.asw_lt)) h

theorem cob_le {a b : Ev} (h : X.cob a b) : K.rank a ≤ K.rank b :=
  transGen_le_of_rank
    (fun _ _ hs => hs.elim (fun h => Nat.le_of_lt (K.rf_lt h))
      (fun h => h.elim (fun h => Nat.le_of_lt (K.mo_lt h)) K.rb_le)) h

theorem pscF_lt (a b : Ev) (h : X.pscF a b) : K.rank a < K.rank b := by
  obtain ⟨_, _, h | ⟨c, d, h₁, h₂, h₃⟩⟩ := h
  · exact K.hb_lt h
  · exact Nat.lt_of_lt_of_le (K.hb_lt h₁)
      (Nat.le_trans (K.cob_le h₂) (Nat.le_of_lt (K.hb_lt h₃)))

include K in
theorem hb_irrefl (a : Ev) : ¬ X.hb a a := fun h => Nat.lt_irrefl _ (K.hb_lt h)

include K in
theorem coherence {a b : Ev} (h₁ : X.hb a b) : ¬ X.cob b a :=
  fun h₂ => Nat.not_le_of_gt (K.hb_lt h₁) (K.cob_le h₂)

include K in
theorem atomicity {u w : Ev} (h₁ : X.rb u w) : ¬ X.mo w u :=
  fun h₂ => Nat.not_le_of_gt (K.mo_lt h₂) (K.rb_le h₁)

include K in
theorem sc_fences (f : Ev) : ¬ Relation.TransGen X.pscF f f :=
  fun h => Nat.lt_irrefl _ (transGen_lt_of_rank K.pscF_lt h)

include K in
theorem mo_irrefl (a : Ev) : ¬ X.mo a a := fun h => Nat.lt_irrefl _ (K.mo_lt h)

include K in
theorem mo_wellFounded : WellFounded X.mo :=
  Subrelation.wf (fun {a b} (h : X.mo a b) => K.mo_lt h) (measure K.rank).wf

end Ranked
end Execution
end Sgc
