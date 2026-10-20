import SgcProofs.Ranked
import SgcProofs.Promotion

/-!
# A witness for the readiness word

`CertWord` and the hypotheses of `cert_read` (RD2) hold together, in the
case the code's `fetch_max` exists for: a delayed certificate. One
readiness word serves epochs 2 and 5 (they share an arena), starting at 0
(`I`). A certificate for 5 (`C5`) writes 5; a delayed certificate for 2
(`C2`), which read 5, writes `max 5 2 = 5`; an acquire load (`R`) reads
`C2`'s 5. `cert_read` then yields the certificate for 5, through the release
sequence `C5`, `C2`: the delayed certificate neither lowered the value nor
broke the sequence, and the word, shared by both epochs and never reset,
does not mistake a certificate for 2 for one for 5.

Every relation agrees with one total order (`rank`), so the execution is
consistent by `Ranked`.
-/

namespace Sgc
namespace CertWitness

open Execution

inductive E
  | I | C5 | C2 | R
  deriving DecidableEq

inductive Word
  | rdy
  deriving DecidableEq

open E

def rank : E → Nat
  | I => 0 | C5 => 1 | C2 => 2 | R => 3

/-- Thread of an event; 0 is initialisation. -/
def thr : E → Nat
  | I => 0 | C5 => 1 | C2 => 2 | R => 3

def isWB : E → Bool
  | I | C5 | C2 => true
  | R => false

def isRB : E → Bool
  | C5 | C2 | R => true
  | I => false

def src : E → Option E
  | C5 => some I | C2 => some C5 | R => some C2
  | I => none

def wv : E → Nat
  | I => 0 | C5 => 5 | C2 => 5 | R => 0

def rv (e : E) : Nat :=
  match src e with
  | some w => wv w
  | none => 0

def relB : E → Bool
  | C5 | C2 => true
  | _ => false

def acqB : E → Bool
  | R => true
  | _ => false

abbrev sbP (a b : E) : Prop := rank a < rank b ∧ (thr a = thr b ∨ thr a = 0)
abbrev rfP (w r : E) : Prop := src r = some w
abbrev moP (a b : E) : Prop := isWB a = true ∧ isWB b = true ∧ rank a < rank b

def X : Execution E Word where
  loc := fun _ => some Word.rdy
  isW := fun e => isWB e = true
  isR := fun e => isRB e = true
  isRel := fun e => relB e = true
  isAcq := fun e => acqB e = true
  isRelFence := fun _ => False
  isAcqFence := fun _ => False
  isScFence := fun _ => False
  sb := sbP
  rf := rfP
  mo := moP
  asw := fun _ _ => False
  wval := wv
  rval := rv

theorem loc_some (e : E) : X.loc e ≠ none := fun h => nomatch h

theorem rank_inj (a b : E) (h : rank a = rank b) : a = b := by
  cases a <;> cases b <;> first | rfl | exact absurd h (by decide)

theorem rf_rank : ∀ w r : E, rfP w r → rank w < rank r := by
  intro w r h
  cases r <;> simp [rfP, src] at h <;> (subst h; decide)

/-- A read takes the last write before it. -/
theorem rb_rank : ∀ r w w₀ : E, rfP w₀ r → moP w₀ w → rank r ≤ rank w := by
  intro r w w₀ h
  cases r <;> simp [rfP, src] at h <;> (subst h; cases w <;> decide)

def ranked : Ranked X where
  rank := rank
  sb_lt := fun h => h.1
  asw_lt := fun h => h.elim
  rf_lt := fun h => rf_rank _ _ h
  mo_lt := fun h => h.2.2
  rb_le := fun ⟨_, w₀, hrf, hmo⟩ => rb_rank _ _ w₀ hrf hmo

theorem consistent : X.Consistent where
  rf_wf := fun {w r} (h : src r = some w) => by
    cases r <;> simp [src] at h <;> (subst h; exact ⟨rfl, rfl, rfl, loc_some _⟩)
  mo_wf := fun h => ⟨h.1, h.2.1, rfl, loc_some _⟩
  mo_irrefl := ranked.mo_irrefl
  mo_trans := fun h₁ h₂ => ⟨h₁.1, h₂.2.1, Nat.lt_trans h₁.2.2 h₂.2.2⟩
  mo_wellFounded := ranked.mo_wellFounded
  rf_unique := fun (h : src _ = some _) h' => Option.some.inj (h.symm.trans h')
  rf_val := fun {w r} (h : src r = some w) => by simp [X, rv, h]
  mo_total := by
    intro a b ha hb _ _
    rcases Nat.lt_trichotomy (rank a) (rank b) with h | h | h
    · exact Or.inr (Or.inl ⟨ha, hb, h⟩)
    · exact Or.inl (rank_inj a b h)
    · exact Or.inr (Or.inr ⟨hb, ha, h⟩)
  scFence_relAcq := fun h => h.elim
  hb_irrefl := ranked.hb_irrefl
  coherence := ranked.coherence
  rmw_mo := fun {w u} (h : src u = some w) hu => by
    cases u <;> simp [src] at h <;> subst h
    · exact ⟨rfl, rfl, by decide⟩
    · exact ⟨rfl, rfl, by decide⟩
    · exact absurd hu (show ¬ isWB R = true by decide)
  atomicity := ranked.atomicity
  sc_fences := ranked.sc_fences

/-- The readiness word: `I` is its initialisation, `C5` and `C2` are
certificates for 5 and 2. -/
def certWord : CertWord X where
  rdyLoc := fun _ => Word.rdy
  isRdyInit := fun e => e = I
  isCert := fun e => e = C5 ∨ e = C2
  certArg := fun e => if e = C2 then 2 else 5
  rdyInit_val := fun {i} h => by subst h; rfl
  rdy_writes := fun {e n} hw _ => by
    cases e <;> first
      | exact Or.inl rfl
      | exact Or.inr (Or.inl rfl)
      | exact Or.inr (Or.inr rfl)
      | exact absurd hw (show ¬ isWB R = true by decide)
  cert_w := fun {c} h => by rcases h with rfl | rfl <;> rfl
  cert_loc := fun _ => rfl
  cert_rel := fun {c} h => by rcases h with rfl | rfl <;> rfl
  cert_rmw := fun {c} h => by
    rcases h with rfl | rfl
    · exact ⟨I, rfl, rfl⟩
    · exact ⟨C5, rfl, rfl⟩

/-- RD2 on the witness: the load that read the delayed certificate's 5
synchronises with a certificate for 5, which is `C5`. -/
theorem witness_cert_read : ∃ c, certWord.isCert c ∧ certWord.certArg c = 5 ∧ X.sw c R ∧
    c = C5 := by
  obtain ⟨c, hcert, harg, hsw⟩ :=
    certWord.cert_read consistent (r := R) (z := C2) (n := 5) rfl rfl rfl rfl (by decide)
  refine ⟨c, hcert, harg, hsw, ?_⟩
  rcases hcert with rfl | rfl
  · rfl
  · exact absurd harg (by decide)

end CertWitness
end Sgc
