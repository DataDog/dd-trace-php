import SgcProofs.Ownership
import SgcProofs.Slot

/-!
# The takeover handoff, from SL1 and A3

OW1. `OwnerProtocol.takeover` was a hypothesis of OW2. This file derives
it from slot serialisation (SL1: `SlotProtocol.serial`, `ender_before`) and
from what `reap_owner()` observed in the owner's slot before the takeover
CAS O3 (code fact F6). Nothing here needs O3 to be an acquire.

`TakeoverEvidence` lists the two outcomes after which `acquire_rotation()`
goes on to O3:

* `Steal::Stolen`: the taker stole the owner's claim itself;
* `Steal::Free`, `Other` or `Lost`: an acquire read of the slot's state
  (P0, or a failed P4), ordered after the owner's registering CAS, returned
  a different word.

In both, everything the owner's registration did happens before the
takeover. The evidence is about the registration `c`, not the tenure: under
owner-word ABA, O1 may have read another tenure of the same registration
(`hb_reg_of_owner_load` takes any acquiring CAS `A'` of it). The P3 case is
handled uniformly (A2: an owner acts under ownership only before its P3),
although the code cannot reach it before a successful O3 (O4 sb P3).
-/

namespace Sgc
namespace Execution

variable {Ev Loc : Type}

/-- What the taker `T` of tenure `A` saw in the slot of the registration `c`
that held `A`. -/
def TakeoverEvidence {X : Execution Ev Loc} {Lv : Liveness X}
    (O : OwnerEvents X) (S : SlotProtocol X Lv) (A T c : Ev) : Prop :=
  S.isClaim c ∧ (∀ e, O.under A e → S.act c e) ∧
  ((∃ cs, S.stealOf c cs ∧ X.sb cs T) ∨
   (∃ g q w, S.regOf c g ∧ X.hb g q ∧ X.rf w q ∧ X.isAcq q ∧
      X.loc q = some S.st ∧ w ≠ g ∧ X.sb q T))

/-- The second outcome needs the read `q` to be ordered after the owner's
registering CAS `g`. It is: the taker loaded the owner word with acquire
(O1) from an acquiring CAS `A'` of that registration, made after `g`. -/
theorem hb_reg_of_owner_load {X : Execution Ev Loc} (O : OwnerEvents X)
    {A' l q g : Ev} (hA' : O.isAcqCas A') (hrf : X.rf A' l) (hl : X.isAcq l)
    (hgA : X.sb g A') (hlq : X.sb l q) : X.hb g q :=
  hb_trans (hb_of_sb hgA)
    (hb_trans (hb_of_sw (sw.relAcq (O.acq_isRel hA') (rs.refl A') hrf hl)) (hb_of_sb hlq))

/-- **OW1.** The takeover handoff. -/
theorem takeover_of_evidence {X : Execution Ev Loc} {Lv : Liveness X}
    (hc : X.Consistent) (O : OwnerEvents X) (S : SlotProtocol X Lv) {A T c : Ev}
    (ev : TakeoverEvidence O S A T c) : ∀ e, O.under A e → X.hb e T := by
  obtain ⟨hcC, hold, hcase⟩ := ev
  intro e he
  have hact := hold e he
  rcases hcase with ⟨cs, hso, hsb⟩ | ⟨g, q, w, hreg, hgq, hwq, hqacq, hql, hne, hsb⟩
  · -- The taker stole the claim: SL1 for the pair (c, cs).
    exact hb_trans
      (S.serial hc cs (Or.inr (S.stealOf_claims hso).2) c hcC (S.mo_of_stealOf hc hso) e hact)
      (hb_of_sb hsb)
  · -- The read returned a later state: it synchronises with what ended `c`.
    rcases coh_wr hc (S.reg_w hreg) ((S.reg_loc hreg).trans hql.symm) hgq hwq with heq | hmo
    · exact absurd heq hne
    · obtain ⟨E, hE, hle⟩ := S.ender_before hc hreg w hmo
      rcases hE with hf | hso
      · have hEq : X.hb E q :=
          S.hb_release_acq hc (S.free_w hf) (S.free_loc hf) (S.free_isRel hf) hwq hqacq hle
        exact hb_trans (sbr_hb (S.act_before_free hf hact) hEq) (hb_of_sb hsb)
      · have hst := (S.stealOf_claims hso).2
        have hEq : X.hb E q :=
          S.hb_release_acq hc (S.claim_w (Or.inr hst)) (S.claim_loc (Or.inr hst))
            (S.steal_isRel hst) hwq hqacq hle
        exact hb_trans
          (hb_trans (S.serial hc E (Or.inr hst) c hcC (S.mo_of_stealOf hc hso) e hact) hEq)
          (hb_of_sb hsb)

/-- An owner protocol whose takeover handoff is proved, not assumed: every
takeover comes with its evidence. -/
def OwnerEvents.toProtocol {X : Execution Ev Loc} (O : OwnerEvents X)
    (hc : X.Consistent) {Lv : Liveness X}
    (ev : ∀ {A T}, O.isAcqCas A → O.isAcqCas T → X.rf A T →
      ∃ (S : SlotProtocol X Lv) (c : Ev), TakeoverEvidence O S A T c) :
    OwnerProtocol X :=
  { toOwnerEvents := O
    takeover := fun hA hT hrf e he => by
      obtain ⟨S, c, h⟩ := ev hA hT hrf
      exact takeover_of_evidence hc O S h e he }

end Execution
end Sgc
