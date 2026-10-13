import SgcProofs.PinVisibility

/-!
# A witness execution

The axioms of `Consistent` and the hypotheses of `pin_visibility` are
satisfiable together: this file builds the seven-event execution in which
the scan reads the pin, and proves it consistent. Without it, a mistake in
the axioms that made them contradictory would go unnoticed, since every
theorem would then hold vacuously.

The execution is the "good" one: every relation agrees with one total order
of the events (`rank`), which is what makes consistency easy to prove.
-/

namespace Sgc
namespace Witness

/-- The events of PN3, with `F` the rotator's fence `f`, plus `Wc`, the
control-word write that `B0` reads. -/
inductive E
  | Wc | W2 | W3 | B0 | Z | F | S
  deriving DecidableEq

/-- The two words involved. -/
inductive Word
  | ctl | pin
  deriving DecidableEq

open E

/-- A total order compatible with every relation of the execution. -/
def rank : E → Nat
  | Wc => 0 | W2 => 1 | W3 => 2 | B0 => 3 | Z => 4 | F => 5 | S => 6

def sbB : E → E → Bool
  | W2, W3 | W3, B0 | W2, B0 | Z, F | F, S | Z, S => true
  | _, _ => false

def rfB : E → E → Bool
  | Wc, B0 | W2, S => true
  | _, _ => false

def moB : E → E → Bool
  | Wc, Z => true
  | _, _ => false

def isWB : E → Bool
  | Wc | W2 | Z => true
  | _ => false

def isRB : E → Bool
  | B0 | S => true
  | _ => false

def isFenceB : E → Bool
  | W3 | F => true
  | _ => false

def locOf : E → Option Word
  | Wc | B0 | Z => some Word.ctl
  | W2 | S => some Word.pin
  | W3 | F => none

/-- The execution: the writer pins and loads the control word before the
seal; the rotation seals, fences and scans, and reads the pin. -/
def X : Execution E Word where
  loc := locOf
  isW := fun e => isWB e = true
  isR := fun e => isRB e = true
  isRel := fun _ => False
  isAcq := fun _ => False
  isRelFence := fun e => isFenceB e = true
  isAcqFence := fun e => isFenceB e = true
  isScFence := fun e => isFenceB e = true
  sb := fun a b => sbB a b = true
  rf := fun a b => rfB a b = true
  mo := fun a b => moB a b = true
  asw := fun _ _ => False
  wval := fun _ => 0
  rval := fun _ => 0

theorem sb_rank (a b : E) (h : X.sb a b) : rank a < rank b := by
  cases a <;> cases b <;> simp [X, sbB, rank] at h ⊢

theorem rf_rank (a b : E) (h : X.rf a b) : rank a < rank b := by
  cases a <;> cases b <;> simp [X, rfB, rank] at h ⊢

theorem mo_rank (a b : E) (h : X.mo a b) : rank a < rank b := by
  cases a <;> cases b <;> simp [X, moB, rank] at h ⊢

theorem rb_rank (a b : E) (h : X.rb a b) : rank a < rank b := by
  obtain ⟨_, w, hrf, hmo⟩ := h
  cases a <;> cases b <;> cases w <;> simp [X, rfB, moB, rank] at hrf hmo ⊢

/-- No write is sequenced after a fence in this execution. -/
theorem no_write_after_fence {f w : E} (hf : X.isRelFence f) (hsb : X.sb f w)
    (hw : X.isW w) : False := by
  cases f <;> cases w <;> simp [X, sbB, isWB, isFenceB] at hf hsb hw

/-- Nothing is release or acquire, and no write follows a fence, so nothing
synchronises. -/
theorem no_sw (a b : E) : ¬ X.sw a b := by
  intro h
  cases h with
  | relAcq hrel _ _ _ => exact hrel
  | relFence hrel _ _ _ _ => exact hrel
  | fenceAcq hf hsb hw _ _ _ => exact no_write_after_fence hf hsb hw
  | fenceFence hf hsb hw _ _ _ _ => exact no_write_after_fence hf hsb hw

theorem hb_rank {a b : E} (h : X.hb a b) : rank a < rank b :=
  transGen_lt_of_rank (fun a b hs => hs.elim (sb_rank a b)
    (fun h => h.elim (fun h => absurd h (no_sw a b)) False.elim)) h

theorem cob_rank {a b : E} (h : X.cob a b) : rank a < rank b :=
  transGen_lt_of_rank
    (fun a b hs => hs.elim (rf_rank a b) (fun h => h.elim (mo_rank a b) (rb_rank a b))) h

theorem pscF_rank (a b : E) (h : X.pscF a b) : rank a < rank b := by
  obtain ⟨_, _, h | ⟨c, d, h₁, h₂, h₃⟩⟩ := h
  · exact hb_rank h
  · exact Nat.lt_trans (hb_rank h₁) (Nat.lt_trans (cob_rank h₂) (hb_rank h₃))

/-- The witness execution is consistent. -/
theorem consistent : X.Consistent where
  rf_wf := by
    intro w r h
    cases w <;> cases r <;> simp [X, rfB, isWB, isRB, locOf] at h ⊢
  mo_wf := by
    intro a b h
    cases a <;> cases b <;> simp [X, moB, isWB, locOf] at h ⊢
  mo_irrefl := fun a h => Nat.lt_irrefl _ (mo_rank a a h)
  mo_trans := by
    intro a b c h₁ h₂
    cases a <;> cases b <;> cases c <;> simp [X, moB] at h₁ h₂ ⊢
  mo_wellFounded := Subrelation.wf (fun {a b} h => mo_rank a b h) (measure rank).wf
  rf_val := fun _ => rfl
  rf_unique := by
    intro w w' r h h'
    cases r <;> cases w <;> simp [X, rfB] at h <;> cases w' <;> simp [X, rfB] at h' ⊢
  mo_total := by
    intro a b ha hb hloc hsome
    cases a <;> cases b <;> simp [X, moB, isWB, locOf] at ha hb hloc hsome ⊢
  scFence_relAcq := fun h => ⟨h, h⟩
  hb_irrefl := fun a h => Nat.lt_irrefl _ (hb_rank h)
  coherence := fun h₁ h₂ => Nat.lt_asymm (hb_rank h₁) (cob_rank h₂)
  rmw_mo := by
    intro w u h hu
    cases w <;> cases u <;> simp [X, rfB, isWB] at h hu
  atomicity := fun h₁ h₂ => Nat.lt_asymm (rb_rank _ _ h₁) (mo_rank _ _ h₂)
  sc_fences := fun f h => Nat.lt_irrefl _ (transGen_lt_of_rank pscF_rank h)

/-- Every hypothesis of `pin_visibility'` holds in the witness, with the scan
reading `W2` itself. -/
theorem hypotheses_hold :
    X.isScFence W3 ∧ X.isScFence F ∧ X.sb W2 W3 ∧ X.sb W3 B0 ∧
    X.rf Wc B0 ∧ X.mo Wc Z ∧ X.hb Z F ∧ X.sb F S ∧ X.rf W2 S ∧
    X.isW W2 ∧ X.loc W2 = X.loc S := by
  refine ⟨rfl, rfl, rfl, rfl, rfl, rfl, Execution.hb_of_sb rfl, rfl, rfl, rfl, rfl⟩

/-- The theorem applied to the witness: the scan read `W2` or a later pin. -/
example : W2 = W2 ∨ X.mo W2 W2 :=
  have ⟨h1, h2, h3, h4, h5, h6, h7, h8, h9, h10, h11⟩ := hypotheses_hold
  Execution.pin_visibility' consistent h1 h2 h3 h4 h5 h6 h7 h8 h9 h10 h11

end Witness
end Sgc
