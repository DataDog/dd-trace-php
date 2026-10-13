import SgcProofs.Ranked
import SgcProofs.Takeover

/-!
# A witness for the second form of `TakeoverEvidence`

`TakeoverWitness.lean` witnesses the outcome `Steal::Stolen`, where the
taker steals the owner's claim itself. This file witnesses the other one,
`Steal::Free` (and with it the shape of `Other` and `Lost`): the taker's
acquire read of the owner's slot, ordered after the owner's register by
the owner-word load O1, returns a different word. Twelve events:

* thread 1 claims the slot (`c0`, `s0`, `g0`), acquires rotation ownership
  (`A1`) and then frees its slot (`x0`, a P3) without releasing ownership;
* thread 2 loads the owner word with acquire (`l`), loads the owner's slot
  state with acquire (`q`, P0) and finds it FREE, takes ownership over (`T`,
  an O3 with release ordering only) and releases it (`r2`, O4).

No liveness verdict is involved: this outcome does not use assumption A3.
The execution satisfies `Consistent`, `Liveness`, `SlotProtocol` and
`OwnerEvents`; the read `q` is shown ordered after the register by
`hb_reg_of_owner_load`, and `OwnerEvents.toProtocol` turns the evidence into
an `OwnerProtocol`, on which OW2 is instantiated.
-/

namespace Sgc
namespace TakeoverFreeWitness

open Execution

inductive E
  | Is | It | Io
  | c0 | s0 | g0 | A1 | x0
  | l | q | T | r2
  deriving DecidableEq

inductive Word
  | st | tdis | own
  deriving DecidableEq

open E

def rank : E → Nat
  | Is => 0 | It => 1 | Io => 2
  | c0 => 3 | s0 => 4 | g0 => 5 | A1 => 6 | x0 => 7
  | l => 8 | q => 9 | T => 10 | r2 => 11

def thr : E → Nat
  | Is | It | Io => 0
  | c0 | s0 | g0 | A1 | x0 => 1
  | l | q | T | r2 => 2

def locOf : E → Option Word
  | Is | c0 | g0 | x0 | q => some Word.st
  | It | s0 => some Word.tdis
  | Io | A1 | l | T | r2 => some Word.own

def isWB : E → Bool
  | Is | It | Io | c0 | s0 | g0 | A1 | x0 | T | r2 => true
  | _ => false

def isRB : E → Bool
  | c0 | g0 | A1 | x0 | l | q | T => true
  | _ => false

def src : E → Option E
  | c0 => some Is | g0 => some c0 | A1 => some Io | x0 => some g0
  | l => some A1 | q => some x0 | T => some A1
  | _ => none

/-- The start time is ten times the thread number. -/
def wv : E → Nat
  | s0 => 10
  | _ => 0

def rv (e : E) : Nat :=
  match src e with
  | some w => wv w
  | none => 0

def relB : E → Bool
  | g0 | A1 | x0 | T | r2 => true
  | _ => false

def acqB : E → Bool
  | c0 | A1 | l | q => true
  | _ => false

abbrev sbP (a b : E) : Prop := rank a < rank b ∧ (thr a = thr b ∨ thr a = 0)
abbrev rfP (w r : E) : Prop := src r = some w
abbrev moP (a b : E) : Prop :=
  isWB a = true ∧ isWB b = true ∧ locOf a = locOf b ∧ locOf a ≠ none ∧ rank a < rank b

def X : Execution E Word where
  loc := locOf
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

theorem rank_inj : ∀ a b : E, rank a = rank b → a = b := by
  intro a b; cases a <;> cases b <;> decide

theorem rf_rank : ∀ w r : E, rfP w r → rank w < rank r := by
  intro w r; cases r <;> cases w <;> decide

theorem rf_wf_raw : ∀ w r : E, rfP w r →
    isWB w = true ∧ isRB r = true ∧ locOf w = locOf r ∧ locOf w ≠ none := by
  intro w r; cases r <;> cases w <;> decide

theorem rmw_mo_raw : ∀ w u : E, rfP w u → isWB u = true → moP w u := by
  intro w u; cases u <;> cases w <;> decide

theorem rb_rank : ∀ r w w₀ : E, rfP w₀ r → moP w₀ w → rank r ≤ rank w := by
  intro r w w₀ h
  cases r <;> simp [rfP, src] at h <;> (subst h; cases w <;> decide)

def ranked : Ranked X where
  rank := rank
  sb_lt := fun h => h.1
  asw_lt := fun h => h.elim
  rf_lt := fun h => rf_rank _ _ h
  mo_lt := fun h => h.2.2.2.2
  rb_le := fun ⟨_, w₀, hrf, hmo⟩ => rb_rank _ _ w₀ hrf hmo

theorem consistent : X.Consistent where
  rf_wf := fun h => rf_wf_raw _ _ h
  mo_wf := fun h => ⟨h.1, h.2.1, h.2.2.1, h.2.2.2.1⟩
  mo_irrefl := ranked.mo_irrefl
  mo_trans := fun h₁ h₂ =>
    ⟨h₁.1, h₂.2.1, h₁.2.2.1.trans h₂.2.2.1, h₁.2.2.2.1, Nat.lt_trans h₁.2.2.2.2 h₂.2.2.2.2⟩
  mo_wellFounded := ranked.mo_wellFounded
  rf_unique := fun (h : src _ = some _) h' => Option.some.inj (h.symm.trans h')
  rf_val := fun {w r} (h : src r = some w) => by simp [X, rv, h]
  mo_total := by
    intro a b ha hb hloc hsome
    rcases Nat.lt_trichotomy (rank a) (rank b) with h | h | h
    · exact Or.inr (Or.inl ⟨ha, hb, hloc, hsome, h⟩)
    · exact Or.inl (rank_inj a b h)
    · exact Or.inr (Or.inr ⟨hb, ha, hloc.symm, fun h' => hsome (hloc.trans h'), h⟩)
  scFence_relAcq := fun h => h.elim
  hb_irrefl := ranked.hb_irrefl
  coherence := ranked.coherence
  rmw_mo := fun h hu => rmw_mo_raw _ _ h hu
  atomicity := ranked.atomicity
  sc_fences := ranked.sc_fences

/-! ## Assumption A3: no verdict is ever given -/

def liveness : Liveness X where
  thrOf := thr
  pidOf := id
  startOf := fun θ => 10 * θ
  dead := fun _ _ _ => False
  sound := fun h => h.elim

/-! ## The slot protocol: one registration, ended by a P3 -/

abbrev actP (a e : E) : Prop := a = c0 ∧ thr e = 1

theorem st_first : ∀ e : E, isWB e = true → locOf e = some Word.st → e ≠ Is → sbP Is e := by
  intro e; cases e <;> decide

theorem st_writes : ∀ e : E, isWB e = true → locOf e = some Word.st →
    e = Is ∨ (e = c0 ∨ False) ∨ (∃ a, a = c0 ∧ e = g0) ∨ (∃ a, a = c0 ∧ e = x0) := by
  intro e hw hl
  cases e <;> first
    | exact Or.inl rfl
    | exact Or.inr (Or.inl (Or.inl rfl))
    | exact Or.inr (Or.inr (Or.inl ⟨c0, rfl, rfl⟩))
    | exact Or.inr (Or.inr (Or.inr ⟨c0, rfl, rfl⟩))
    | exact absurd hw (by decide)
    | exact absurd hl (by decide)

theorem act_sb_raw : ∀ e : E, thr e = 1 → e = c0 ∨ sbP c0 e := by
  intro e; cases e <;> decide

theorem act_before_x0 : ∀ e : E, thr e = 1 → e = x0 ∨ sbP e x0 := by
  intro e; cases e <;> decide

theorem t_first : ∀ e : E, isWB e = true → locOf e = some Word.tdis → e ≠ It → sbP It e := by
  intro e; cases e <;> decide

theorem t_writes_raw : ∀ e : E, isWB e = true → locOf e = some Word.tdis →
    e = It ∨ ∃ a, a = c0 ∧ e = s0 := by
  intro e hw hl
  cases e <;> first
    | exact Or.inl rfl
    | exact Or.inr ⟨c0, rfl, rfl⟩
    | exact absurd hw (by decide)
    | exact absurd hl (by decide)

/-- Two events of one thread are the same or sequenced. -/
theorem thread_total {e f : E} (h : thr e = thr f) : e = f ∨ sbP e f ∨ sbP f e := by
  rcases Nat.lt_trichotomy (rank e) (rank f) with h' | h' | h'
  · exact Or.inr (Or.inl ⟨h', Or.inl h⟩)
  · exact Or.inl (rank_inj e f h')
  · exact Or.inr (Or.inr ⟨h', Or.inl h.symm⟩)

def slot : SlotProtocol X liveness where
  st := Word.st
  tdis := Word.tdis
  init := Is
  tInit := It
  isFreeClaim := fun e => e = c0
  isSteal := fun _ => False
  stealOf := fun _ _ => False
  regOf := fun a b => a = c0 ∧ b = g0
  freeOf := fun a b => a = c0 ∧ b = x0
  act := actP
  tOf := fun a b => a = c0 ∧ b = s0
  init_w := rfl
  init_loc := rfl
  init_first := fun hw hl hne => hb_of_sb (st_first _ hw hl hne)
  writes := fun hw hl => st_writes _ hw hl
  claim_w := fun h => by rcases h with rfl | h <;> first | rfl | exact h.elim
  claim_loc := fun h => by rcases h with rfl | h <;> first | rfl | exact h.elim
  fc_isAcq := fun h => by subst h; rfl
  fc_reads := fun h => by subst h; exact ⟨Is, rfl, Or.inl rfl⟩
  steal_isRel := fun h => h.elim
  steal_of := fun h => h.elim
  stealOf_claims := fun h => h.elim
  steal_ev := fun h => h.elim
  reg_claim := fun h => Or.inl h.1
  reg_w := fun h => by obtain ⟨rfl, rfl⟩ := h; rfl
  reg_loc := fun h => by obtain ⟨rfl, rfl⟩ := h; rfl
  reg_isRel := fun h => by obtain ⟨rfl, rfl⟩ := h; rfl
  reg_reads := fun h => by obtain ⟨rfl, rfl⟩ := h; rfl
  reg_not_claim := fun h => by obtain ⟨rfl, rfl⟩ := h; decide
  reg_owner := fun h h' => h.1.trans h'.1.symm
  free_w := fun h => by obtain ⟨rfl, rfl⟩ := h; rfl
  free_loc := fun h => by obtain ⟨rfl, rfl⟩ := h; rfl
  free_isRel := fun h => by obtain ⟨rfl, rfl⟩ := h; rfl
  free_reads := fun h => by obtain ⟨rfl, rfl⟩ := h; exact ⟨g0, ⟨rfl, rfl⟩, rfl⟩
  free_not_claim := fun h => by obtain ⟨rfl, rfl⟩ := h; decide
  act_self := fun h => by rcases h with rfl | h <;> first | decide | exact h.elim
  act_sb := fun h => by obtain ⟨rfl, h⟩ := h; exact act_sb_raw _ h
  act_claim := fun h => by obtain ⟨rfl, _⟩ := h; decide
  act_thr := fun h => by obtain ⟨rfl, h⟩ := h; exact h
  act_total := fun h h' => by
    obtain ⟨rfl, h⟩ := h
    exact thread_total (h.trans h'.2.symm)
  act_before_free := fun h he => by
    obtain ⟨rfl, rfl⟩ := h
    exact act_before_x0 _ he.2
  tInit_w := rfl
  tInit_loc := rfl
  tInit_first := fun hw hl hne => hb_of_sb (t_first _ hw hl hne)
  t_writes := fun hw hl => t_writes_raw _ hw hl
  t_w := fun h => by obtain ⟨rfl, rfl⟩ := h; rfl
  t_loc := fun h => by obtain ⟨rfl, rfl⟩ := h; rfl
  t_act := fun h => by obtain ⟨rfl, rfl⟩ := h; decide
  t_before_reg := fun h h' => by
    obtain ⟨rfl, rfl⟩ := h
    obtain ⟨-, rfl⟩ := h'
    exact (by decide : sbP s0 g0)
  t_unique := fun h h' => h.2.trans h'.2.symm
  t_of_reg := fun h => ⟨s0, h.1, rfl⟩
  t_val := fun h => by obtain ⟨rfl, rfl⟩ := h; decide

/-! ## The owner word -/

abbrev isAcqCasP (e : E) : Prop := e = A1 ∨ e = T

theorem own_first : ∀ e : E, isWB e = true → locOf e = some Word.own → e ≠ Io → sbP Io e := by
  intro e; cases e <;> decide

theorem own_writes : ∀ e : E, isWB e = true → locOf e = some Word.own →
    e = Io ∨ isAcqCasP e ∨ ∃ a, a = T ∧ e = r2 := by
  intro e hw hl
  cases e <;> first
    | exact Or.inl rfl
    | exact Or.inr (Or.inl (by decide))
    | exact Or.inr (Or.inr ⟨T, rfl, rfl⟩)
    | exact absurd hw (by decide)
    | exact absurd hl (by decide)

theorem acq_facts : ∀ a : E, isAcqCasP a →
    isWB a = true ∧ locOf a = some Word.own ∧ relB a = true ∧
    (src a).isSome = true := by
  intro a; cases a <;> decide

/-- Only `A1`, which reads the initialisation, is an acquire: the takeover
`T` reads `A1` and is release-only, like O3 in the code (`rotation.rs`). -/
theorem acq_of_noncas : ∀ a w : E, isAcqCasP a → rfP w a → ¬ isAcqCasP w → acqB a = true := by
  intro a w; cases a <;> cases w <;> decide

def ownerEvents : OwnerEvents X where
  own := Word.own
  init := Io
  isAcqCas := isAcqCasP
  relOf := fun a r => a = T ∧ r = r2
  init_w := rfl
  init_loc := rfl
  init_first := fun hw hl hne => hb_of_sb (own_first _ hw hl hne)
  writes := fun hw hl => own_writes _ hw hl
  acq_w := fun h => (acq_facts _ h).1
  acq_loc := fun h => (acq_facts _ h).2.1
  acq_isAcq := fun {a w} h hrf hn => acq_of_noncas a w h hrf hn
  acq_isRel := fun h => (acq_facts _ h).2.2.1
  acq_reads := fun {a} h => by
    have := (acq_facts a h).2.2.2
    cases hs : src a with
    | none => rw [hs] at this; exact absurd this (by decide)
    | some w => exact ⟨w, hs⟩
  rel_acq := fun h => Or.inr h.1
  rel_w := fun h => by obtain ⟨rfl, rfl⟩ := h; rfl
  rel_loc := fun h => by obtain ⟨rfl, rfl⟩ := h; rfl
  rel_isRel := fun h => by obtain ⟨rfl, rfl⟩ := h; rfl
  rel_not_acq := fun h => by obtain ⟨rfl, rfl⟩ := h; decide
  rel_sb := fun h => by obtain ⟨rfl, rfl⟩ := h; exact (by decide : sbP T r2)
  rel_unique := fun h h' => h.2.trans h'.2.symm

/-! ## The evidence -/

/-- Everything thread 1 does under its tenure is done as registration `c0`. -/
theorem under_A1 : ∀ e : E, (e = A1 ∨ sbP A1 e) → actP c0 e := by
  intro e; cases e <;> decide

/-- The one takeover: `T` reads `A1`'s word. -/
theorem takeover_pair : ∀ a b : E, isAcqCasP a → isAcqCasP b → rfP a b → a = A1 ∧ b = T := by
  intro a b; cases a <;> cases b <;> decide

/-- The read `q` is ordered after the owner's register `g0`: the taker's O1
load `l` read the owner's acquiring CAS, which follows `g0`. -/
theorem g0_before_q : X.hb g0 q :=
  hb_reg_of_owner_load ownerEvents (A' := A1) (l := l) (q := q) (g := g0) (Or.inl rfl) rfl rfl
    (by decide : sbP g0 A1) (by decide : sbP l q)

/-- What the taker saw: the owner's slot is FREE (`Steal::Free`), a word
other than the owner's register. -/
theorem evidence : TakeoverEvidence ownerEvents slot A1 T c0 :=
  ⟨Or.inl rfl, fun e he => under_A1 e he.1,
    Or.inr ⟨g0, q, x0, ⟨rfl, rfl⟩, g0_before_q, rfl, rfl, rfl, by decide,
      (by decide : sbP q T)⟩⟩

/-- The owner protocol, with its takeover handoff proved from the evidence. -/
def owner : OwnerProtocol X :=
  ownerEvents.toProtocol consistent (Lv := liveness) (fun {a b} ha hb hrf => by
    obtain ⟨rfl, rfl⟩ := takeover_pair a b ha hb hrf
    exact ⟨slot, c0, evidence⟩)

/-! ## The theorems on this execution -/

/-- OW1: the owner's P3, done under its tenure, happens before the
takeover, through the second form of the evidence. -/
theorem free_witness_takeover : X.hb x0 T :=
  takeover_of_evidence consistent ownerEvents slot evidence x0
    ⟨Or.inr (by decide : sbP A1 x0), fun _ h => absurd h.1 (by decide)⟩

/-- OW2: everything done under the first tenure happens before everything
done under the one that took it over. -/
theorem free_witness_tenures_ordered : X.hb x0 r2 :=
  owner.tenures_ordered consistent (Or.inl rfl) (Or.inr rfl) (by decide : moP A1 T)
    ⟨Or.inr (by decide : sbP A1 x0), fun _ h => absurd h.1 (by decide)⟩
    ⟨Or.inr (by decide : sbP T r2), fun _ h => Or.inl h.2.symm⟩

end TakeoverFreeWitness
end Sgc
