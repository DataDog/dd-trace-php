import SgcProofs.Ranked
import SgcProofs.Pins

/-!
# A witness for the slot and pin protocols

Fifteen events in which a writer dies pinned and its slot is reaped:

* thread 1 claims the slot (`c0`), stores its start time (`s0`), registers
  (`g0`), pins (`W2`) and stops;
* thread 2 loads the state (`o`, P0) and the start time (`t`, T0), gets a
  "dead" verdict (`vd`), steals the claim (`c`, P4), stores its own start
  time (`s`, T2), registers (`g`, P5) and clears the dead pin (`P6`);
* thread 3 scans the pin with an acquire load (`Sl`) and reads the clear.

The verdict's synchronisation with the dead thread (assumption A3) is the
execution's `asw` relation. The execution satisfies `Consistent`,
`Liveness`, `SlotProtocol` and `PinProtocol`, so none of them is vacuous,
and SL1, PN2 and QU1 are instantiated on it at the end.
-/

namespace Sgc
namespace SlotWitness

open Execution

inductive E
  | Is | It | Ip
  | c0 | s0 | g0 | W2
  | o | t | vd | c | s | g | P6
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
  | Sl => 14

def thr : E → Nat
  | Is | It | Ip => 0
  | c0 | s0 | g0 | W2 => 1
  | o | t | vd | c | s | g | P6 => 2
  | Sl => 3

def locOf : E → Option Word
  | Is | c0 | g0 | c | g | o => some Word.st
  | It | s0 | s | t => some Word.tdis
  | Ip | W2 | P6 | Sl => some Word.pin
  | vd => none

def isWB : E → Bool
  | Is | It | Ip | c0 | s0 | g0 | W2 | c | s | g | P6 => true
  | _ => false

def isRB : E → Bool
  | c0 | g0 | c | g | o | t | Sl => true
  | _ => false

def src : E → Option E
  | c0 => some Is | g0 => some c0 | o => some g0 | t => some s0
  | c => some g0 | g => some c | Sl => some P6
  | _ => none

/-- Start times are ten times the thread number; the pin holds epoch 5. -/
def wv : E → Nat
  | s0 => 10 | s => 20 | W2 => 5
  | _ => 0

def rv (e : E) : Nat :=
  match src e with
  | some w => wv w
  | none => 0

def relB : E → Bool
  | g0 | c | g | W2 | P6 => true
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

/-! ## The pin protocol -/

abbrev nonAnnP (e : E) : Prop :=
  (∃ a, a = c0 ∧ e = W2) ∨ (∃ _w : E, False) ∨ (∃ a, a = c ∧ e = P6)

theorem pin_first : ∀ e : E, isWB e = true → locOf e = some Word.pin → e ≠ Ip → sbP Ip e := by
  intro e; cases e <;> decide

theorem pin_writes_raw : ∀ e : E, isWB e = true → locOf e = some Word.pin →
    e = Ip ∨ False ∨ nonAnnP e := by
  intro e hw hl
  cases e <;> first
    | exact Or.inl rfl
    | exact Or.inr (Or.inr (Or.inl ⟨c0, rfl, rfl⟩))
    | exact Or.inr (Or.inr (Or.inr (Or.inr ⟨c, rfl, rfl⟩)))
    | exact absurd hw (by decide)
    | exact absurd hl (by decide)

theorem att_act_raw : ∀ e : E, (e = W2 ∨ sbP W2 e) → actP c0 e := by
  intro e; cases e <;> decide

theorem no_later_pin_write : ∀ N : E, actP c0 N → sbP W2 N → False := by
  intro N; cases N <;> decide

def pins : PinProtocol X slot where
  pin := Word.pin
  pinInit := Ip
  pinVal := id
  isAnnounce := fun _ => False
  w2Of := fun a w => a = c0 ∧ w = W2
  unpinOf := fun _ _ => False
  clearOf := fun a k => a = c ∧ k = P6
  pinInit_w := rfl
  pinInit_loc := rfl
  pinInit_first := fun hw hl hne => hb_of_sb (pin_first _ hw hl hne)
  pin_writes := fun hw hl => pin_writes_raw _ hw hl
  ann_rmw := fun h => h.elim
  ann_not := fun h => h.elim
  init_not := by
    rintro (⟨_, _, h⟩ | ⟨_, h⟩ | ⟨_, _, h⟩)
    · exact absurd h (by decide)
    · exact h
    · exact absurd h (by decide)
  w2_act := fun h => by obtain ⟨rfl, rfl⟩ := h; exact (by decide : actP c0 W2)
  w2_w := fun h => by obtain ⟨rfl, rfl⟩ := h; rfl
  w2_loc := fun h => by obtain ⟨rfl, rfl⟩ := h; rfl
  w2_isRel := fun h => by obtain ⟨rfl, rfl⟩ := h; rfl
  unpin_w2 := fun h => h.elim
  unpin_w := fun h => h.elim
  unpin_loc := fun h => h.elim
  unpin_isRel := fun h => h.elim
  clear_act := fun h => by obtain ⟨rfl, rfl⟩ := h; exact (by decide : actP c P6)
  clear_w := fun h => by obtain ⟨rfl, rfl⟩ := h; rfl
  clear_loc := fun h => by obtain ⟨rfl, rfl⟩ := h; rfl
  clear_isRel := fun h => by obtain ⟨rfl, rfl⟩ := h; rfl
  att_act := fun h he _ => by obtain ⟨rfl, rfl⟩ := h; exact att_act_raw _ he
  pin_next := fun h hact _ hsb => by
    obtain ⟨rfl, rfl⟩ := h
    exact (no_later_pin_write _ hact hsb).elim

/-! ## The theorems on this execution -/

/-- SL1: the dead writer's pin store happens before the steal. -/
example : X.hb W2 c :=
  slot.serial consistent c (Or.inr rfl) c0 (Or.inl rfl) (by decide : moP c0 c) W2
    (by decide : actP c0 W2)

/-- PN2: the pin store is ordered before the reaper's clear. -/
example : W2 = P6 ∨ X.hb W2 P6 :=
  pins.attempt_before consistent (c := c0) ⟨rfl, rfl⟩ (Or.inr (Or.inr ⟨c, rfl, rfl⟩))
    (by decide : moP W2 P6) ⟨Or.inl rfl, fun _ h => h.elim⟩

/-- QU1: the scan load, which reads the clear, is ordered after the dead
writer's pin store. -/
example : X.hb W2 Sl :=
  pins.quiescence_core consistent (c := c0) (W2 := W2) (p := P6) (Sc := Sl) (a := W2) (e := 5)
    ⟨rfl, rfl⟩ rfl (rfl : src Sl = some P6) rfl (Or.inr (by decide : moP W2 P6))
    (by decide : id (rv Sl) ≠ 5) ⟨Or.inl rfl, fun _ h => h.elim⟩

end SlotWitness
end Sgc
