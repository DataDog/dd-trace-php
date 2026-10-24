import SgcProofs.OwnerCleanup
import SgcProofs.OwnerHandoffWitness
import SgcProofs.RecoveryWitness

namespace Sgc.OwnerCleanupWitness
open Execution

/-- A pinned-helper/backend unwind really executes the same O4 as a
normal return. The finite source projection includes both body events and
P3, with O4 strictly before P3; the unwind constructor is inhabited. -/
def unwoundScope : RotationScope Nat := ⟨0, [1], 2, .unwound⟩

theorem unwind_cleanup_before_free_witness :
    unwoundScope.trace = [0, 1, 2] ∧
      List.Pairwise Nat.lt (unwoundScope.trace ++ [3]) ∧ (2 : Nat) < 3 := by simp [unwoundScope, RotationScope.trace]

namespace Stolen
open TakeoverWitness TakeoverWitness.E
open OwnerHandoffWitness.Stolen

/-- In this consistent schedule the dead owner never reaches P3. The
reaper's distinct temporary registration reaches P3 without acquiring a
tenure. Neither case needs a fabricated O4. -/
def cleanup : OwnerCleanupSource ownerEvents slot := by
  intro c y hy
  obtain ⟨rfl, rfl⟩ := hy
  exact {
    scopes := []
    covered := by
      intro a ha hact
      rcases ha with rfl | rfl <;> exact (by decide : ¬ actP cs _) hact |>.elim
    release_label := by simp
    tenure_covered := by simp
    ordered := by simp }

/-- Nonvacuity: the decoded old owner is alive at acquisition and dead at
the actual O3. The result uses the source-derived holder and cleanup
adapter, not the abstract OwnerRecoveryFacts fields. -/
theorem source_owner_lifetime_witness :
    X.Consistent ∧ noFalseDeath.toThreadLifetime.running (thr A1) A1 ∧
      ∃ A c, X.rf A T ∧ ownerEvents.isAcqCas A ∧ slot.act c A ∧
        X.wval A = OwnerWord.pack 0 (registrations.id c).val ∧
        ¬ noFalseDeath.toThreadLifetime.running (thr c) T := by
  refine ⟨consistent, noFalseDeath.toThreadLifetime.running_at_action A1, ?_⟩
  exact no_live_code_takeover words consistent noFalseDeath
    registrations.toOwnerRegistrationData cleanup reads.takeover reads.observed
    reads.load_location reads.load_acquire reads.nonempty reads.expected (by decide) branch

end Stolen
end Sgc.OwnerCleanupWitness
