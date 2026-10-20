import SgcProofs.Recovery
import SgcProofs.InitStealWitness
import SgcProofs.TakeoverWitness

/-!
# Nonempty thread lifetimes for RB1 and RB2

The witnesses reuse complete consistent executions, including the slot
protocol and real steals. A thread begins at its first action and thread 1
ends after its last action. This interpretation admits live holders before
their death, dead holders at recovery, and a later incarnation reusing the
same backend id. In particular, running does not propagate before birth.
-/

namespace Sgc
open Execution

namespace RecoveryWitness

/-- In a ranked witness, observing an action means its thread has begun. -/
theorem observed_rank {E W : Type} {X : Execution E W} {Lv : Liveness X}
    (K : Ranked X) {θ : Nat} {e : E} (h : Lv.observed θ e) :
    ∃ a, Lv.thrOf a = θ ∧ K.rank a ≤ K.rank e := by
  obtain ⟨a, r, ha, har, hre⟩ := h
  exact ⟨a, ha, Nat.le_of_lt (Nat.lt_trans
    (transGen_lt_of_rank (fun a b h => K.rf_lt (a := a) (b := b) h) har) (K.sb_lt hre))⟩

end RecoveryWitness

namespace InitStealWitness
open E

def lifetime : ThreadLifetime liveness where
  begun := fun θ e => ∃ a, thr a = θ ∧ rank a ≤ rank e
  ended := fun θ e => θ = 1 ∧ rank c0 < rank e
  action_begun := fun e => ⟨e, rfl, Nat.le_refl _⟩
  action_not_ended := by intro e; cases e <;> decide
  observed_begun := RecoveryWitness.observed_rank ranked
  ended_after := fun h ⟨hθ, hlt⟩ => ⟨hθ, Nat.lt_trans hlt (ranked.hb_lt h)⟩

def noFalseDeath : NoFalseDeath liveness where
  toThreadLifetime := lifetime
  no_false_dead := by
    intro v pid st hd θ hθ _ hseen hrun
    obtain ⟨rfl, rfl, rfl⟩ := hd
    obtain ⟨w, hw, hwv⟩ := RecoveryWitness.observed_rank ranked hseen
    have hθne : θ ≠ 3 := by
      intro h
      have hw3 : thr w = 3 := hw.trans h
      cases w <;> simp [thr, ranked, rank] at hw3 hwv
    have hθold : θ = 1 := by simpa [liveness, pidOf, hθne] using hθ
    exact hrun.2 ⟨hθold, by decide⟩

/-- RB1 has a real live claimant and a real successful P4 after its death. -/
theorem live_claim_then_steal :
    X.Consistent ∧ noFalseDeath.toThreadLifetime.running (thr c0) c0 ∧
      slot.stealOf c0 c ∧ ¬ noFalseDeath.toThreadLifetime.running (thr c0) c :=
  ⟨consistent, lifetime.running_at_action c0, ⟨rfl, rfl⟩,
    slot.no_steal_of_running noFalseDeath consistent ⟨rfl, rfl⟩⟩

/-- An id reused after a verdict is alive at its own action but not yet
born at that verdict. This refutes unrestricted backwards running. -/
theorem live_reused_id_not_yet_born :
    liveness.dead vd (liveness.pidOf (thr reuse)) 0 ∧ X.hb vd reuse ∧
      noFalseDeath.toThreadLifetime.running (thr reuse) reuse ∧
      ¬ noFalseDeath.toThreadLifetime.running (thr reuse) vd := by
  refine ⟨⟨rfl, rfl, rfl⟩, pid_reuse_after_verdict.2.2.2.2.2.1,
    lifetime.running_at_action reuse, ?_⟩
  intro h
  obtain ⟨a, ha, hle⟩ := h.1
  cases a <;> simp [thr, rank] at ha hle

end InitStealWitness

namespace TakeoverWitness
open E

def lifetime : ThreadLifetime liveness where
  begun := fun θ e => ∃ a, thr a = θ ∧ rank a ≤ rank e
  ended := fun θ e => θ = 1 ∧ rank A1 < rank e
  action_begun := fun e => ⟨e, rfl, Nat.le_refl _⟩
  action_not_ended := by intro e; cases e <;> decide
  observed_begun := RecoveryWitness.observed_rank ranked
  ended_after := fun h ⟨hθ, hlt⟩ => ⟨hθ, Nat.lt_trans hlt (ranked.hb_lt h)⟩

def noFalseDeath : NoFalseDeath liveness where
  toThreadLifetime := lifetime
  no_false_dead := by
    intro v pid st hd θ hθ _ _ hrun
    obtain ⟨rfl, rfl, rfl⟩ := hd
    exact hrun.2 ⟨hθ, by decide⟩

/-- RB2 has an owner that was live at O2, then a real registered-branch
P4 and O3 after its death. The theorem is applied to the full evidence. -/
theorem live_owner_then_takeover :
    X.Consistent ∧ noFalseDeath.toThreadLifetime.running (thr c0) A1 ∧
      TakeoverEvidence ownerEvents slot A1 T c0 ∧
      ¬ noFalseDeath.toThreadLifetime.running (thr c0) T := by
  refine ⟨consistent, lifetime.running_at_action A1, evidence, ?_⟩
  apply ownerEvents.no_takeover_of_running slot noFalseDeath consistent evidence
    (by change isAcqCasP A1; decide) (by change isAcqCasP T; decide) (by rfl)
  intro ender hx
  exact False.elim ((by decide : c0 ≠ cs) hx.1)

end TakeoverWitness
end Sgc
