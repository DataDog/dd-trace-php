import SgcProofs.Ranked
import SgcProofs.PinEventFlow

/-!
A consistent stolen registration clears P6 and then begins a new pass.
The first projected W2 is at index zero: P6 is deliberately not assigned
a position before zero. This witnesses the minimal source-label interface
without assuming PinProtocol.pass_exit or pass_quiet. This is protocol
nonvacuity, not a claim that Rust reapers start another pass before release.
-/

namespace Sgc
namespace StolenPinTraceWitness

open Execution

inductive E
  | Is | It | Ip
  | c0 | s0 | g0 | W2
  | o | t | vd | c | s | g | P6 | NewW2 | NewW7
  | Sl
  deriving DecidableEq

inductive Word
  | st | tdis | pin
  deriving DecidableEq

open E

def rank : E → Nat
  | Is => 0 | It => 1 | Ip => 2
  | c0 => 3 | s0 => 4 | g0 => 5 | W2 => 6
  | o => 7 | t => 8 | vd => 9 | c => 10 | s => 11 | g => 12 | P6 => 13
  | NewW2 => 14 | NewW7 => 15 | Sl => 16

def thr : E → Nat
  | Is | It | Ip => 0
  | c0 | s0 | g0 | W2 => 1
  | o | t | vd | c | s | g | P6 | NewW2 | NewW7 => 2
  | Sl => 3

def locOf : E → Option Word
  | Is | c0 | g0 | c | g | o => some Word.st
  | It | s0 | s | t => some Word.tdis
  | Ip | W2 | P6 | NewW2 | NewW7 | Sl => some Word.pin
  | vd => none

def isWB : E → Bool
  | Is | It | Ip | c0 | s0 | g0 | W2 | c | s | g | P6 | NewW2 | NewW7 => true
  | _ => false

def isRB : E → Bool
  | c0 | g0 | c | g | o | t | NewW7 | Sl => true
  | _ => false

def src : E → Option E
  | c0 => some Is | g0 => some c0 | o => some g0 | t => some s0
  | c => some g0 | g => some c | NewW7 => some NewW2 | Sl => some NewW7
  | _ => none

/-- Start times are ten times the thread number; the pin holds epoch 5. -/
def wv : E → Nat
  | s0 => 10 | s => 20 | W2 | NewW2 => 5
  | _ => 0

def rv (e : E) : Nat :=
  match src e with
  | some w => wv w
  | none => 0

def relB : E → Bool
  | g0 | c | g | W2 | P6 | NewW2 | NewW7 => true
  | _ => false

def acqB : E → Bool
  | c0 | o | Sl => true
  | _ => false

abbrev sbP (a b : E) : Prop := rank a < rank b ∧ (thr a = thr b ∨ thr a = 0)
abbrev rfP (w r : E) : Prop := src r = some w
abbrev moP (a b : E) : Prop :=
  isWB a = true ∧ isWB b = true ∧ locOf a = locOf b ∧ locOf a ≠ none ∧ rank a < rank b
/-- The backend's verdict is ordered after everything thread 1 did. -/
abbrev aswP (a b : E) : Prop := thr a = 1 ∧ b = vd

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
  asw := aswP
  wval := wv
  rval := rv

theorem rank_inj : ∀ a b : E, rank a = rank b → a = b := by
  intro a b; cases a <;> cases b <;> decide

theorem rf_rank : ∀ w r : E, rfP w r → rank w < rank r := by
  intro w r; cases r <;> cases w <;> decide

theorem asw_rank : ∀ a b : E, aswP a b → rank a < rank b := by
  intro a b; cases a <;> cases b <;> decide

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
  asw_lt := fun h => asw_rank _ _ h
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

/-! ## Assumption A3 -/

def liveness : Liveness X where
  thrOf := thr
  pidOf := id
  startOf := fun θ => 10 * θ
  dead := fun v pid st => v = vd ∧ pid = 1 ∧ st = 10
  sound := by
    intro v pid st hd θ hθ _ _ e he
    obtain ⟨rfl, rfl, rfl⟩ := hd
    exact hb_of_asw ⟨he.trans hθ, rfl⟩

/-! ## The slot protocol -/

abbrev isClaimP (e : E) : Prop := e = c0 ∨ e = c
abbrev regOfP (a b : E) : Prop := (a = c0 ∧ b = g0) ∨ (a = c ∧ b = g)
abbrev actP (a e : E) : Prop := (a = c0 ∧ thr e = 1) ∨ (a = c ∧ thr e = 2 ∧ rank c ≤ rank e)
abbrev tOfP (a x : E) : Prop := (a = c0 ∧ x = s0) ∨ (a = c ∧ x = s)

theorem st_first : ∀ e : E, isWB e = true → locOf e = some Word.st → e ≠ Is → sbP Is e := by
  intro e; cases e <;> decide

theorem st_writes : ∀ e : E, isWB e = true → locOf e = some Word.st →
    e = Is ∨ isClaimP e ∨ (∃ a, regOfP a e) ∨ (∃ _a : E, False) := by
  intro e hw hl
  cases e <;> first
    | exact Or.inl rfl
    | exact Or.inr (Or.inl (by decide))
    | exact Or.inr (Or.inr (Or.inl ⟨c0, by decide⟩))
    | exact Or.inr (Or.inr (Or.inl ⟨c, by decide⟩))
    | exact absurd hw (by decide)
    | exact absurd hl (by decide)

theorem claim_facts : ∀ a : E, isClaimP a → isWB a = true ∧ locOf a = some Word.st := by
  intro a; cases a <;> decide

theorem reg_facts : ∀ a b : E, regOfP a b →
    isClaimP a ∧ isWB b = true ∧ locOf b = some Word.st ∧ relB b = true ∧ rfP a b ∧
    ¬ isClaimP b := by
  intro a b; cases a <;> cases b <;> decide

theorem reg_owner_raw : ∀ a a' b : E, regOfP a b → regOfP a' b → a = a' := by
  intro a a' b h h'
  rcases h with ⟨rfl, rfl⟩ | ⟨rfl, rfl⟩ <;> rcases h' with ⟨rfl, h₁⟩ | ⟨rfl, h₁⟩ <;>
    first | rfl | exact absurd h₁ (by decide)

theorem act_facts : ∀ a e : E, actP a e → (e = a ∨ sbP a e) ∧ thr e = thr a := by
  intro a e; cases a <;> cases e <;> decide

theorem act_total_raw : ∀ a e f : E, actP a e → actP a f → e = f ∨ sbP e f ∨ sbP f e := by
  intro a e f; cases a <;> cases e <;> cases f <;> decide

theorem t_first : ∀ e : E, isWB e = true → locOf e = some Word.tdis → e ≠ It → sbP It e := by
  intro e; cases e <;> decide

theorem t_writes_raw : ∀ e : E, isWB e = true → locOf e = some Word.tdis →
    e = It ∨ ∃ a, tOfP a e := by
  intro e hw hl
  cases e <;> first
    | exact Or.inl rfl
    | exact Or.inr ⟨c0, by decide⟩
    | exact Or.inr ⟨c, by decide⟩
    | exact absurd hw (by decide)
    | exact absurd hl (by decide)

theorem t_facts : ∀ a x : E, tOfP a x →
    isClaimP a ∧ isWB x = true ∧ locOf x = some Word.tdis ∧ actP a x ∧
    (wv x = 0 ∨ wv x = 10 * thr a) := by
  intro a x; cases a <;> cases x <;> decide

theorem t_before_reg_raw : ∀ a x b : E, tOfP a x → regOfP a b → sbP x b := by
  intro a x b; cases a <;> cases x <;> cases b <;> decide

theorem t_unique_raw : ∀ a x x' : E, tOfP a x → tOfP a x' → x = x' := by
  intro a x x'; cases a <;> cases x <;> cases x' <;> decide

def slot : SlotProtocol X liveness where
  st := Word.st
  tdis := Word.tdis
  init := Is
  tInit := It
  isFreeClaim := fun e => e = c0
  isSteal := fun e => e = c
  stealOf := fun a b => a = c0 ∧ b = c
  regOf := regOfP
  freeOf := fun _ _ => False
  act := actP
  tOf := tOfP
  init_w := rfl
  init_loc := rfl
  init_first := fun hw hl hne => hb_of_sb (st_first _ hw hl hne)
  writes := fun hw hl => st_writes _ hw hl
  claim_w := fun h => (claim_facts _ h).1
  claim_loc := fun h => (claim_facts _ h).2
  fc_isAcq := fun h => by subst h; rfl
  fc_reads := fun h => by subst h; exact ⟨Is, rfl, Or.inl rfl⟩
  steal_isRel := fun h => by subst h; rfl
  steal_of := fun h => by subst h; exact ⟨c0, rfl, rfl⟩
  stealOf_claims := fun h => by obtain ⟨rfl, rfl⟩ := h; exact ⟨Or.inl rfl, rfl⟩
  steal_ev := fun h => by
    obtain ⟨rfl, rfl⟩ := h
    exact ⟨g0, o, vd, rfl, rfl, rfl, (by decide : sbP o vd), (by decide : sbP vd c),
      Or.inr ⟨Or.inl ⟨rfl, rfl⟩, t, s0, rfl, rfl, (by decide : sbP o t),
        (by decide : sbP t c), rfl, rfl, rfl⟩⟩
  reg_claim := fun h => (reg_facts _ _ h).1
  reg_w := fun h => (reg_facts _ _ h).2.1
  reg_loc := fun h => (reg_facts _ _ h).2.2.1
  reg_isRel := fun h => (reg_facts _ _ h).2.2.2.1
  reg_reads := fun h => (reg_facts _ _ h).2.2.2.2.1
  reg_not_claim := fun h => (reg_facts _ _ h).2.2.2.2.2
  reg_owner := fun h h' => reg_owner_raw _ _ _ h h'
  free_w := fun h => h.elim
  free_loc := fun h => h.elim
  free_isRel := fun h => h.elim
  free_reads := fun h => h.elim
  free_not_claim := fun h => h.elim
  act_self := fun {a} h => by rcases h with rfl | rfl <;> decide
  act_sb := fun h => (act_facts _ _ h).1
  act_claim := fun h => by rcases h with ⟨rfl, _⟩ | ⟨rfl, _⟩ <;> decide
  act_thr := fun h => (act_facts _ _ h).2
  act_total := fun h h' => act_total_raw _ _ _ h h'
  act_before_free := fun h => h.elim
  tInit_w := rfl
  tInit_loc := rfl
  tInit_first := fun hw hl hne => hb_of_sb (t_first _ hw hl hne)
  t_writes := fun hw hl => t_writes_raw _ hw hl
  t_w := fun h => (t_facts _ _ h).2.1
  t_loc := fun h => (t_facts _ _ h).2.2.1
  t_act := fun h => (t_facts _ _ h).2.2.2.1
  t_before_reg := fun h h' => t_before_reg_raw _ _ _ h h'
  t_unique := fun h h' => t_unique_raw _ _ _ h h'
  t_of_reg := fun {a b} h => by
    rcases h with ⟨rfl, _⟩ | ⟨rfl, _⟩
    · exact ⟨s0, by decide⟩
    · exact ⟨s, by decide⟩
  t_val := fun h => (t_facts _ _ h).2.2.2.2


local instance (a e : E) : Decidable (slot.act a e) :=
  inferInstanceAs (Decidable (actP a e))
local instance (a b : E) : Decidable (X.sb a b) :=
  inferInstanceAs (Decidable (sbP a b))

def labels : PinSourceLabels X slot where
  w2Of := fun a w => (a = c0 ∧ w = W2) ∨ (a = c ∧ w = NewW2)
  unpinOf := fun w u => w = NewW2 ∧ u = NewW7
  clearOf := fun a k => a = c ∧ k = P6
  w2_act := by
    intro a w h
    rcases h with ⟨rfl, rfl⟩ | ⟨rfl, rfl⟩ <;> decide
  unpin_w2 := by
    intro w u h
    obtain ⟨rfl, rfl⟩ := h
    exact ⟨c, Or.inr ⟨rfl, rfl⟩, by decide⟩

local instance (a w : E) : Decidable (labels.w2Of a w) :=
  inferInstanceAs (Decidable ((a = c0 ∧ w = W2) ∨ (a = c ∧ w = NewW2)))
local instance (w u : E) : Decidable (labels.unpinOf w u) :=
  inferInstanceAs (Decidable (w = NewW2 ∧ u = NewW7))

open PinFlow

def eventAt (j : Nat) : E := if j = 0 then NewW2 else NewW7

def positionOf (e : E) : Nat := if e = NewW7 then 1 else 0

def trace : IndexedPinTrace labels c [.w2, .w7] where
  event := eventAt
  position := positionOf
  event_position := by
    intro j hj
    have h : j = 0 ∨ j = 1 := by simp at hj; omega
    rcases h with rfl | rfl <;> decide
  event_order := by
    intro i j hij hj
    have hi : i = 0 := by simp at hj; omega
    have hj' : j = 1 := by simp at hj; omega
    subst i; subst j; decide
  w2_event := by intro w; cases w <;> decide
  unpin_event := by intro w u; cases w <;> cases u <;> decide
  owned := by
    intro j hj
    have h : j = 0 ∨ j = 1 := by simp at hj; omega
    rcases h with rfl | rfl <;> decide
  w2_label := by intro w; cases w <;> decide
  event_w2 := by
    intro j hj
    cases j with
    | zero => exact Or.inr ⟨rfl, rfl⟩
    | succ j => cases j <;> simp at hj
  event_w7 := by
    intro j hj
    cases j with
    | zero => simp at hj
    | succ j =>
        cases j with
        | zero => exact ⟨NewW2, Or.inr ⟨rfl, rfl⟩, rfl, rfl⟩
        | succ j => simp at hj
  unpin_label := by intro w u; cases w <;> cases u <;> decide
  w7_pair := by
    intro w j hw hj
    subst j
    revert hw
    cases w <;> decide
  pair_position := by intro w u; cases w <;> cases u <;> decide
  clear_before := by
    intro w N d hw _ hd
    obtain ⟨rfl, rfl⟩ := hd
    rcases hw with ⟨h, _⟩ | ⟨_, rfl⟩
    · cases h
    · decide

/-- P6 precedes a real release W2, yet W2 has trace index zero. The
source-derived exit theorem produces its real W7 in this consistent model. -/
theorem stolen_first_w2_at_zero :
    X.Consistent ∧ slot.isSteal c ∧ slot.act c P6 ∧ X.sb P6 NewW2 ∧
    X.isW NewW2 ∧ X.isRel NewW2 ∧ X.loc NewW2 = some Word.pin ∧
    trace.position NewW2 = 0 ∧
    ∃ u, labels.unpinOf NewW2 u ∧ slot.act c u := by
  refine ⟨consistent, rfl, by decide, by decide, rfl, rfl, rfl, rfl, ?_⟩
  exact IndexedPinTrace.derives_pass_exit labels trace consistent
    (show run false [.w2, .w7] = some false from rfl)
    (Or.inr ⟨rfl, rfl⟩) (show slot.act c NewW7 by decide)
    (Or.inr (Or.inl ⟨NewW2, rfl, rfl⟩)) (by decide)

end StolenPinTraceWitness
end Sgc
