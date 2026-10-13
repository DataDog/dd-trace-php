import SgcProofs.Model

/-!
# PN3, pin visibility

The store-buffering argument between a pinned writer and the rotation that
reuses its arena, as a theorem about every consistent execution.

Events, with the labels of `docs/invariants.html`:

* `W2`: the writer's pin store; `W3`: its `SeqCst` fence; `B0`: its relaxed
  load of the control word, which saw the arena unsealed;
* `Z`: the seal of that arena incarnation (R1), a write to the control word;
* `f`: a `SeqCst` fence on the rotator's side; `Sc`: one of its scan loads
  (R3) of the writer's pin. In the code `f` is the W3 of the rotator's own
  last reservation pass, from the `reserve()` that called `rotate()`:
  `rotate()` has no fence of its own.

Hypotheses and where they come from:

* `W2 sb W3 sb B0` and `f sb Sc` are program order (`store.rs`, and the
  caller's W3 before `rotate()`);
* `B0` read a write `u` that precedes `Z` in the control word's `mo`: EP2,
  the shape of the control word;
* `Z hb f`: EP3 (`CacheProtocol.seal_before_fence`).

Nothing is assumed about the orderings of `W2`, `B0` or `Sc`: the result
needs only the two `SeqCst` fences.
-/

namespace Sgc
namespace Execution

variable {Ev Loc : Type}

/-- **PN3.** The scan load `Sc` does not read a pin value older than `W2`. -/
theorem pin_visibility {X : Execution Ev Loc} (hc : X.Consistent)
    {W2 W3 B0 Z f Sc u p : Ev}
    (hW3 : X.isScFence W3) (hf : X.isScFence f)
    (h_W2_W3 : X.sb W2 W3) (h_W3_B0 : X.sb W3 B0)
    (h_u_B0 : X.rf u B0) (h_u_Z : X.mo u Z)
    (h_Z_f : X.hb Z f)
    (h_f_Sc : X.sb f Sc)
    (h_p_Sc : X.rf p Sc) :
    ¬ X.mo p W2 := by
  intro h_p_W2
  -- `B0 cob Z` (or `B0 = Z`) orders W3 before `f` ...
  have c₁ : X.pscF W3 f := by
    refine ⟨hW3, hf, ?_⟩
    by_cases heq : B0 = Z
    · subst heq
      exact Or.inl (hb_trans (hb_of_sb h_W3_B0) h_Z_f)
    · exact Or.inr ⟨B0, Z, hb_of_sb h_W3_B0,
        cob_of_rb ⟨heq, u, h_u_B0, h_u_Z⟩, h_Z_f⟩
  -- ... and `Sc cob W2` (or `Sc = W2`) would order `f` before W3.
  have c₂ : X.pscF f W3 := by
    refine ⟨hf, hW3, ?_⟩
    by_cases heq : Sc = W2
    · subst heq
      exact Or.inl (hb_trans (hb_of_sb h_f_Sc) (hb_of_sb h_W2_W3))
    · exact Or.inr ⟨Sc, W2, hb_of_sb h_f_Sc,
        cob_of_rb ⟨heq, p, h_p_Sc, h_p_W2⟩, hb_of_sb h_W2_W3⟩
  exact hc.sc_fences W3 (.tail (.single c₁) c₂)

/-- **PN3**, as `docs/invariants.html` states it: `Sc` reads `W2` or a write
later in `mo`. Needs `W2` to be a write to the word `Sc` reads. -/
theorem pin_visibility' {X : Execution Ev Loc} (hc : X.Consistent)
    {W2 W3 B0 Z f Sc u p : Ev}
    (hW3 : X.isScFence W3) (hf : X.isScFence f)
    (h_W2_W3 : X.sb W2 W3) (h_W3_B0 : X.sb W3 B0)
    (h_u_B0 : X.rf u B0) (h_u_Z : X.mo u Z)
    (h_Z_f : X.hb Z f)
    (h_f_Sc : X.sb f Sc)
    (h_p_Sc : X.rf p Sc)
    (hW2 : X.isW W2) (hloc : X.loc W2 = X.loc Sc) :
    p = W2 ∨ X.mo W2 p := by
  have ⟨hp, _, hlp, hsome⟩ := hc.rf_wf h_p_Sc
  rcases hc.mo_total hp hW2 (hlp.trans hloc.symm) hsome with heq | hmo | hmo
  · exact Or.inl heq
  · exact absurd hmo
      (pin_visibility hc hW3 hf h_W2_W3 h_W3_B0 h_u_B0 h_u_Z h_Z_f h_f_Sc h_p_Sc)
  · exact Or.inr hmo

end Execution
end Sgc
