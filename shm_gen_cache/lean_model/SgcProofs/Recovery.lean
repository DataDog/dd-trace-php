import SgcProofs.ClaimClosure

/-!
# RB1 and RB2: live participants are not displaced

A9 is the liveness backend's no-false-death contract, scoped to an
incarnation already observed by P0. `ThreadLifetime` gives the independent
meaning of running: the incarnation has begun and has not ended. Acting
threads are running, an observed thread has begun, and an ended incarnation
cannot resume. Running is therefore preserved backwards only after a
witness that the incarnation already existed, never before its birth.

The code mapping is `is_live()` / `is_live_since()` in `pid.rs`,
`linux_pid.rs:45-79`, and `windows_pid.rs:77-124`. Linux treats unknown
identity as possibly live; a false answer requires a terminal state,
start-time mismatch or ESRCH. Windows requires a signalled/deleted object
or a known creation-time mismatch. An already-observed incarnation that
is still running cannot have been replaced by another thread with its id.
These source facts instantiate A9; the Lean model assumes the backend
contract and does not verify the OS implementation.
-/

namespace Sgc
namespace Execution

variable {Ev Loc : Type}

/-- Thread-incarnation lifetime facts, independent of liveness verdicts.
`begun` and `ended` describe the OS lifetime, including blocked threads that
may run again. They are not the slot registration lifetime. Events of a
thread occur during its lifetime; observing one proves it has begun.
Termination is irreversible for this incarnation (a reused id is a new θ). -/
structure ThreadLifetime {X : Execution Ev Loc} (Lv : Liveness X) where
  begun : Nat → Ev → Prop
  ended : Nat → Ev → Prop
  action_begun : ∀ e, begun (Lv.thrOf e) e
  action_not_ended : ∀ e, ¬ ended (Lv.thrOf e) e
  observed_begun : ∀ {θ e}, Lv.observed θ e → begun θ e
  ended_after : ∀ {θ e e'}, X.hb e e' → ended θ e → ended θ e'

namespace ThreadLifetime

/-- A running incarnation exists and has not terminated. -/
def running {X : Execution Ev Loc} {Lv : Liveness X}
    (L : ThreadLifetime Lv) (θ : Nat) (e : Ev) : Prop := L.begun θ e ∧ ¬ L.ended θ e

/-- Running cannot be interpreted as the empty predicate: every modeled
thread action occurs while that incarnation is alive. -/
theorem running_at_action {X : Execution Ev Loc} {Lv : Liveness X}
    (L : ThreadLifetime Lv) (e : Ev) : L.running (Lv.thrOf e) e :=
  ⟨L.action_begun e, L.action_not_ended e⟩

/-- Backwards lifetime reasoning is valid only for an incarnation already
observed before the earlier event. No assumption is made about unborn
incarnations that will later reuse the pid. -/
theorem running_after_observation {X : Execution Ev Loc} {Lv : Liveness X}
    (L : ThreadLifetime Lv) {θ e e'} (hobs : Lv.observed θ e)
    (hbefore : X.hb e e') (hrun : L.running θ e') : L.running θ e :=
  ⟨L.observed_begun hobs, fun hend => hrun.2 (L.ended_after hbefore hend)⟩

end ThreadLifetime

/-- A9 applies to the observed incarnation under its own identity. The
observation restriction is the one in A3, and excludes later pid reuse. -/
structure NoFalseDeath {X : Execution Ev Loc} (Lv : Liveness X)
    extends ThreadLifetime Lv where
  no_false_dead : ∀ {vd pid st}, Lv.dead vd pid st → ∀ θ,
    Lv.pidOf θ = pid → (st = 0 ∨ Lv.startOf θ = st) →
      Lv.observed θ vd → ¬ toThreadLifetime.running θ vd

namespace SlotProtocol

variable {X : Execution Ev Loc} {Lv : Liveness X}
  (S : SlotProtocol X Lv) (A9 : NoFalseDeath Lv)

/-- **RB1, including later recovery.** A successful P4 cannot replace a
claim of an incarnation still running at P4 or at any later hb event.
The verdict's identity and observation both come from the actual P0/rf
path; no lifetime fact about an unobserved incarnation is used. -/
theorem no_steal_before_running (hc : X.Consistent) {c₀ c later : Ev}
    (hso : S.stealOf c₀ c) (hlater : c = later ∨ X.hb c later) :
    ¬ A9.toThreadLifetime.running (Lv.thrOf c₀) later := by
  intro hrun
  have hc₀ := (S.stealOf_claims hso).1
  obtain ⟨vd, st, hvdc, hdead, hst, hobs⟩ :=
    S.steal_verdict_matches hc hso (S.serial hc c₀ hc₀)
  have hbefore : X.hb vd later := by
    rcases hlater with h | h
    · exact h ▸ hb_of_sb hvdc
    · exact hb_trans (hb_of_sb hvdc) h
  exact A9.no_false_dead hdead (Lv.thrOf c₀) rfl hst hobs
    (A9.toThreadLifetime.running_after_observation hobs hbefore hrun)

/-- **RB1.** P4 never replaces the claim of a still-running incarnation. -/
theorem no_steal_of_running (hc : X.Consistent) {c₀ c : Ev}
    (hso : S.stealOf c₀ c) : ¬ A9.toThreadLifetime.running (Lv.thrOf c₀) c :=
  S.no_steal_before_running A9 hc hso (Or.inl rfl)

end SlotProtocol

namespace OwnerEvents

variable {X : Execution Ev Loc} {Lv : Liveness X}
  (O : OwnerEvents X) (S : SlotProtocol X Lv) (A9 : NoFalseDeath Lv)

/-- The code facts that connect an O3 takeover to its owning registration.
`holds A c` identifies the claim encoded by A's owner word; another
registration held by the same thread does not satisfy it. The O4/P3 field
covers normal `rotate()` return and the lock drop for that claim. -/
structure OwnerRecoveryFacts where
  holds : Ev → Ev → Prop
  takeover_owner : ∀ {A T}, O.isAcqCas A → O.isAcqCas T → X.rf A T →
    ∃ c, holds A c ∧ TakeoverEvidence O S A T c
  release_before_free : ∀ {A c}, holds A c →
    ∀ x, S.freeOf c x → ∃ r, O.relOf A r ∧ X.sb r x

/-- **RB2.** If a running owner frees its registration, `rotate()` has
already released that tenure's owner word (O4 before P3). Under this
source-level control-flow fact, O3 cannot end its live tenure. -/
theorem no_takeover_of_running (hc : X.Consistent) {A T c : Ev}
    (ev : TakeoverEvidence O S A T c)
    (hA : O.isAcqCas A) (hT : O.isAcqCas T) (hrf : X.rf A T)
    (release_before_free : ∀ x, S.freeOf c x → ∃ r, O.relOf A r ∧ X.sb r x) :
    ¬ A9.toThreadLifetime.running (Lv.thrOf c) T := by
  intro hrun
  have hev := ev
  obtain ⟨hcC, _, _, hcase⟩ := ev
  rcases hcase with ⟨cs, hso, hcsT⟩ | ⟨g, q, w, hreg, hgq, hwq, hqacq, hql, hne, hqT⟩
  · exact S.no_steal_before_running A9 hc hso (Or.inr (hb_of_sb hcsT)) hrun
  · rcases coh_wr hc (S.reg_w hreg) ((S.reg_loc hreg).trans hql.symm)
      hgq hwq with heq | hmo
    · exact hne heq
    · obtain ⟨E, hE, hle⟩ := S.ender_before hc hreg w hmo
      rcases hE with hf | hso
      · obtain ⟨r, hr, _⟩ := release_before_free E hf
        exact (no_release_of_takeover_evidence hc O S hev hA hT hrf r) hr
      · have hst := (S.stealOf_claims hso).2
        have hEq : X.hb E q :=
          S.hb_release_acq hc (S.claim_w (Or.inr hst))
            (S.claim_loc (Or.inr hst)) (S.steal_isRel hst) hwq hqacq hle
        exact S.no_steal_before_running A9 hc hso
          (Or.inr (hb_trans hEq (hb_of_sb hqT))) hrun

/-- RB2 for a code-level O3: the evidence and O4-before-P3 discipline are
supplied by the owning-registration protocol. -/
theorem no_o3_of_running (hc : X.Consistent) (F : OwnerRecoveryFacts O S)
    {A T : Ev} (hA : O.isAcqCas A) (hT : O.isAcqCas T) (hrf : X.rf A T) :
    ∃ c, F.holds A c ∧ ¬ A9.toThreadLifetime.running (Lv.thrOf c) T := by
  obtain ⟨c, hhold, hev⟩ := F.takeover_owner hA hT hrf
  exact ⟨c, hhold,
    O.no_takeover_of_running S A9 hc hev hA hT hrf (F.release_before_free hhold)⟩

end OwnerEvents
end Execution
end Sgc
