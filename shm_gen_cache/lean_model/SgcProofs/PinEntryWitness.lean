import SgcProofs.RotationReapCacheWitness
import SgcProofs.PinEventFlow
import SgcProofs.RecoveryWitness

/-! Concrete instances of the registration baseline theorem in the full
consistent cache witness. The caller's own first registration reads its
initialized zero; the stolen registration reads P6 at release_claim's
actual assertion. Neither instance assumes the result of an assertion. -/
namespace Sgc.PinEntryWitness
open Execution RotationReapCacheWitness RotationReapCacheWitness.E

/-- First P1/P2 in the rotator's own slot, before any W2. -/
theorem first_registration_scan_reads_zero :
    X.Consistent ∧ X.rval t1OwnS = 0 ∧ X.mo cR gR ∧ X.hb gR t1OwnS := by
  have B := initial_registration_zero_prefix rotatorPins consistent
    (show rotatorSlot.isFreeClaim cR from rfl) (show X.rf iRS cR from rfl) rfl
  refine ⟨consistent, ?_⟩
  apply registration_entry_reads_zero rotatorPins consistent B
    (show rotatorSlot.regOf cR gR from ⟨rfl, rfl⟩)
    (show X.sb gR t1OwnS by decide)
    (show rotatorSlot.act cR t1OwnS from ⟨rfl, rfl, by decide⟩)
    (show X.loc t1OwnS = some rotatorPins.pin from rfl)
    (show X.rf iRP t1OwnS from rfl)
    (hb_of_sb (show X.sb iRP t1OwnS by decide)) rfl
  · rintro N (⟨_, h⟩ | ⟨_, h⟩ | ⟨_, h⟩) _ <;> exact h.elim
  · intro _ h; exact h.elim

/-- The successful steal's P5/P6 baseline, at the actual target release
assertion load Rl. The rotator still owns its distinct original slot. -/
theorem stolen_registration_release_reads_zero :
    X.Consistent ∧ X.rval Rl = 0 ∧ X.mo c g ∧ X.hb g Rl := by
  have B := stolen_registration_zero_prefix pins consistent
    (show pins.clearOf c P6 from ⟨rfl, rfl⟩) rfl
  refine ⟨consistent, ?_⟩
  apply registration_entry_reads_zero pins consistent B
    (show slot.regOf c g from Or.inr ⟨rfl, rfl⟩)
    (show X.sb g Rl by decide) (show slot.act c Rl from (by change actP c Rl; decide))
    (show X.loc Rl = some pins.pin from rfl) (show X.rf P6 Rl from rfl)
    (hb_of_sb (show X.sb P6 Rl by decide)) rfl
  · rintro N (⟨a, ha⟩ | ⟨_, hu⟩ | ⟨a, ha⟩) hact
    · obtain ⟨rfl, rfl⟩ := ha
      exact False.elim ((by decide : ¬ actP c W2) hact)
    · exact hu.elim
    · obtain ⟨rfl, rfl⟩ := ha
      exact Or.inl rfl
  · intro _ h; exact h.elim

/-- A9 allows the dead target while the calling thread remains running. -/
def runningRotator : NoFalseDeath cache.Lv where
  begun := fun θ e => ∃ a, thr a = θ ∧ rank a ≤ rank e
  ended := fun θ e => θ = 1 ∧ rank B0 < rank e
  action_begun := fun e => ⟨e, rfl, Nat.le_refl _⟩
  action_not_ended := by intro e; cases e <;> decide
  observed_begun := RecoveryWitness.observed_rank ranked
  ended_after := fun h ⟨hθ, hlt⟩ => ⟨hθ, Nat.lt_trans hlt (ranked.hb_lt h)⟩
  no_false_dead := by
    intro v pid st hd θ hθ _ _ hrun
    obtain ⟨rfl, rfl, rfl⟩ := hd
    exact hrun.2 ⟨hθ, by decide⟩

/-- The A9 proof has a consistent instance with a running caller and a
successful P4 on the target. It does not classify P4 as a caller action. -/
theorem running_reaper_target_distinct :
    X.Consistent ∧ (true : cache.ι) ≠ false := by
  refine ⟨consistent, ?_⟩
  apply reaper_target_distinct cache consistent runningRotator
    (caller := cR) (registered := gR) (steal := c)
  · exact ⟨rfl, rfl⟩
  · decide
  · intro f hf; exact hf.elim
  · exact runningRotator.toThreadLifetime.running_at_action c
  · rfl

end Sgc.PinEntryWitness
