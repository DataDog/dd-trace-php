import SgcProofs.RegressionWitness

/-!
# A full protocol witness with only the chunk-epoch reset

RegressionWitness's complete consistent cache execution models H0's epoch
store only. Its cursor/end words have no reset stores and it satisfies
ReserveProtocol. This witnesses consistency of the epoch-only regime and
the first nonmatching H1 test: the epoch load reads H0 zero and skips
cursor/end. It has no matching H1 chunk probe. The universal matching-probe
theorem for that regime is `ReserveProtocol.epoch_only_probe`. CacheWitness
separately exercises matching H1 probes with the production resets present.
-/

namespace Sgc
namespace EpochResetWitness

open Execution

/-- The full memory/cache/reservation model admits epoch-only resets. -/
theorem epoch_only_model : RegressionWitness.X.Consistent ∧
    RegressionWitness.reserve.EpochOnlyResets := by
  refine ⟨RegressionWitness.consistent, ?_⟩
  intro i c x h
  cases i
  obtain ⟨rfl, rfl⟩ := h
  exact ⟨rfl, by decide, by decide⟩

open RegressionWitness RegressionWitness.E

/-- Establish the weaker interface directly from the finite event order,
without projecting the stronger `reserve.reset_before` field. -/
theorem epoch_order : reserve.toReserveData.EpochResetOrder := by
  intro i c x w h _ h2
  obtain ⟨_, rfl⟩ := h
  obtain ⟨hw, _⟩ := h2
  rcases hw with rfl | rfl <;> decide

/-- The first H1 reads this registration's H0 zero, while pinned for 2.
The real execution continues to B1 without loading cursor or end. -/
def firstEpochLoad : ReserveProtocol.H1Load reserve where
  read := aLe
  source := aHe
  rf := rfl
  slot := ()
  attempt := aW
  isAttempt := Or.inl rfl
  inAttempt := inAtt_mk (by decide) (by decide)
  chunkWord := Or.inl rfl
  epochRead := aLe
  path := .epoch rfl (by decide) rfl
  epoch_ne_cursor := by decide
  epoch_ne_end := by decide
  not_write := by decide
  init_before := by intro z h; exact h.elim
  before_b1 := by
    intro b _ hB
    change isB1P b at hB
    rcases hB with rfl | rfl <;> decide

/-- Instantiate the new sufficiency theorem, whose only reset-order input
is `epoch_order`. The epoch-only constructor has identical source data. -/
theorem weak_interface_first_load :
    (∃ c, (cache.pins ()).w2Of c aW ∧ (cache.slot ()).act c aHe) ∧
    X.hb aHe aLe ∧
    ∀ z, X.isW z → X.loc z = X.loc aLe → X.hb z aLe →
      z = aHe ∨ X.mo z aHe := by
  exact ReserveProtocol.epoch_only_latest_own reserve.toReserveData
    epoch_only_model.2 epoch_order consistent firstEpochLoad

/-- A complete execution with epoch-only H0 and a nonmatching first H1.
The universal path theorem forces any load of this invocation to be its
epoch test; the concrete execution has no cursor/end read at all. -/
theorem first_epoch_mismatch : X.Consistent ∧ reserve.EpochOnlyResets ∧
    X.rval aLe = 0 ∧ X.rval aLe ≠ cache.attEpoch aW ∧
    (∃ c, (cache.pins ()).w2Of c aW ∧ (cache.slot ()).act c aHe) ∧
    (∀ H : ReserveProtocol.H1Load reserve,
      H.epochRead = aLe → H.attempt = aW → H.read = aLe) ∧
    (∀ r, X.isR r → X.loc r ≠ some Word.cc ∧ X.loc r ≠ some Word.cn) := by
  refine ⟨consistent, epoch_only_model.2, rfl, by decide,
    reserve.h1_own consistent firstEpochLoad, ?_, ?_⟩
  · intro H he hw
    have hm : X.rval H.epochRead ≠ cache.attEpoch H.attempt := by
      rw [he, hw]; decide
    exact (reserve.h1_nonmatching_epoch_only H hm).1.trans he
  · intro r _
    cases r <;> decide

end EpochResetWitness
end Sgc
