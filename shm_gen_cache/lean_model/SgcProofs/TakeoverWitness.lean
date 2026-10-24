import SgcProofs.Ranked
import SgcProofs.Pins
import SgcProofs.Takeover

/-!
# A witness with an unpin, an announce, a P3 and a takeover

Twenty-seven events on one slot and the rotation-owner word:

* thread 1 claims the slot (`c0`, `s0`, `g0`), pins (`W2`) and unpins (`W7`),
  then reads its registration id (`d0`), acquires rotation ownership
  (`A1`) and stops while holding it;
* thread 4, a rotation, announces on the pin (`R3A`) while it is set;
* thread 2 loads the owner word (`l`, O1), reaps the owner's slot (`o` P0,
  `t` T0, the verdict `vd`, the steal `cs` P4, `s` T2, `g` P5, the clear
  `P6`), releases the stolen slot (`x`, a P3), takes ownership over (`T`, an
  O3 with release ordering only), scans the pin (`Sc`) and releases
  ownership (`r2`, O4);
* thread 3 claims the freed slot (`c1`, `s1`, `g1`).

It satisfies `Consistent`, `Liveness`, `SlotProtocol`, `PinProtocol` and
`OwnerEvents`, and it comes with the `TakeoverEvidence` of its one takeover,
so `OwnerEvents.toProtocol` yields an `OwnerProtocol` whose handoff is
proved rather than assumed.
-/

namespace Sgc
namespace TakeoverWitness

open Execution

inductive E
  | Is | It | Ip | Io
  | d0
  | c0 | s0 | g0 | W2 | R3A | W7 | A1
  | l | o | t | vd | cs | s | g | P6 | x | T | Sc | r2
  | c1 | s1 | g1
  deriving DecidableEq

inductive Word
  | st | tdis | pin | own
  deriving DecidableEq

open E

def rank : E → Nat
  | Is => 0 | It => 2 | Ip => 4 | Io => 6
  | c0 => 8 | s0 => 10 | g0 => 12 | W2 => 14 | R3A => 16 | W7 => 18 | A1 => 20
  | l => 22 | o => 24 | t => 26 | vd => 28 | cs => 30 | s => 32 | g => 34 | P6 => 36
  | x => 38 | T => 40 | Sc => 42 | r2 => 44
  | c1 => 46 | s1 => 48 | g1 => 50
  | d0 => 19

def thr : E → Nat
  | Is | It | Ip | Io => 0
  | d0 | c0 | s0 | g0 | W2 | W7 | A1 => 1
  | R3A => 4
  | l | o | t | vd | cs | s | g | P6 | x | T | Sc | r2 => 2
  | c1 | s1 | g1 => 3

def locOf : E → Option Word
  | d0 | Is | c0 | g0 | o | cs | g | x | c1 | g1 => some Word.st
  | It | s0 | t | s | s1 => some Word.tdis
  | Ip | W2 | R3A | W7 | P6 | Sc => some Word.pin
  | Io | A1 | l | T | r2 => some Word.own
  | vd => none

def isWB : E → Bool
  | Is | It | Ip | Io | c0 | s0 | g0 | W2 | R3A | W7 | A1 | cs | s | g | P6 | x | T | r2
  | c1 | s1 | g1 => true
  | _ => false

def isRB : E → Bool
  | d0 | c0 | g0 | R3A | A1 | l | o | t | cs | g | x | T | Sc | c1 | g1 => true
  | _ => false

def src : E → Option E
  | d0 => some g0
  | c0 => some Is | g0 => some c0 | R3A => some W2 | A1 => some Io | l => some A1
  | o => some g0 | t => some s0 | cs => some g0 | g => some cs | x => some g
  | T => some A1 | Sc => some P6 | c1 => some x | g1 => some c1
  | _ => none

/-- Start times are ten times the thread number; the pin holds epoch 5.
State words encode pid/id and the INITIALIZING bit. Owner words encode
slot 0/id 11 and slot 1/id 44. -/
def wv : E → Nat
  | s0 => 10 | s => 20 | s1 => 30 | W2 => 5 | R3A => 5
  | c0 => 11 * 2^32 + 3 | g0 => 11 * 2^32 + 2
  | cs => 22 * 2^32 + 5 | g => 22 * 2^32 + 4
  | c1 => 33 * 2^32 + 7 | g1 => 33 * 2^32 + 6
  | A1 => 11 * 2^32 | T => 44 * 2^32 + 1
  | _ => 0

def rv (e : E) : Nat :=
  match src e with
  | some w => wv w
  | none => 0

def relB : E → Bool
  | g0 | W2 | W7 | A1 | cs | g | P6 | x | T | r2 | g1 => true
  | _ => false

def acqB : E → Bool
  | c0 | A1 | l | o | Sc | c1 => true
  | _ => false

abbrev sbP (a b : E) : Prop := rank a < rank b ∧ (thr a = thr b ∨ thr a = 0)
abbrev rfP (w r : E) : Prop := src r = some w
abbrev moP (a b : E) : Prop :=
  isWB a = true ∧ isWB b = true ∧ locOf a = locOf b ∧ locOf a ≠ none ∧ rank a < rank b
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

/-- Two events of one thread are the same or sequenced. -/
theorem thread_total {e f : E} (h : thr e = thr f) : e = f ∨ sbP e f ∨ sbP f e := by
  rcases Nat.lt_trichotomy (rank e) (rank f) with h' | h' | h'
  · exact Or.inr (Or.inl ⟨h', Or.inl h⟩)
  · exact Or.inl (rank_inj e f h')
  · exact Or.inr (Or.inr ⟨h', Or.inl h.symm⟩)

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

abbrev isClaimP (e : E) : Prop := (e = c0 ∨ e = c1) ∨ e = cs
abbrev regOfP (a b : E) : Prop := (a = c0 ∧ b = g0) ∨ (a = cs ∧ b = g) ∨ (a = c1 ∧ b = g1)
abbrev actP (a e : E) : Prop :=
  (a = c0 ∧ thr e = 1) ∨ (a = cs ∧ thr e = 2 ∧ 30 ≤ rank e ∧ rank e ≤ 38) ∨
  (a = c1 ∧ thr e = 3)
abbrev tOfP (a y : E) : Prop := (a = c0 ∧ y = s0) ∨ (a = cs ∧ y = s) ∨ (a = c1 ∧ y = s1)

theorem st_first : ∀ e : E, isWB e = true → locOf e = some Word.st → e ≠ Is → sbP Is e := by
  intro e; cases e <;> decide

theorem st_writes : ∀ e : E, isWB e = true → locOf e = some Word.st →
    e = Is ∨ isClaimP e ∨ (∃ a, regOfP a e) ∨ (∃ a, a = cs ∧ e = x) := by
  intro e hw hl
  cases e <;> first
    | exact Or.inl rfl
    | exact Or.inr (Or.inl (by decide))
    | exact Or.inr (Or.inr (Or.inl ⟨c0, by decide⟩))
    | exact Or.inr (Or.inr (Or.inl ⟨cs, by decide⟩))
    | exact Or.inr (Or.inr (Or.inl ⟨c1, by decide⟩))
    | exact Or.inr (Or.inr (Or.inr ⟨cs, rfl, rfl⟩))
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
  rcases h with ⟨rfl, rfl⟩ | ⟨rfl, rfl⟩ | ⟨rfl, rfl⟩ <;>
    rcases h' with ⟨rfl, h₁⟩ | ⟨rfl, h₁⟩ | ⟨rfl, h₁⟩ <;>
    first | rfl | exact absurd h₁ (by decide)

theorem act_facts : ∀ a e : E, actP a e → (e = a ∨ sbP a e) ∧ thr e = thr a := by
  intro a e; cases a <;> cases e <;> decide

theorem act_before_x : ∀ e : E, actP cs e → e = x ∨ sbP e x := by
  intro e; cases e <;> decide

theorem t_first : ∀ e : E, isWB e = true → locOf e = some Word.tdis → e ≠ It → sbP It e := by
  intro e; cases e <;> decide

theorem t_writes_raw : ∀ e : E, isWB e = true → locOf e = some Word.tdis →
    e = It ∨ ∃ a, tOfP a e := by
  intro e hw hl
  cases e <;> first
    | exact Or.inl rfl
    | exact Or.inr ⟨c0, by decide⟩
    | exact Or.inr ⟨cs, by decide⟩
    | exact Or.inr ⟨c1, by decide⟩
    | exact absurd hw (by decide)
    | exact absurd hl (by decide)

theorem t_facts : ∀ a y : E, tOfP a y →
    isClaimP a ∧ isWB y = true ∧ locOf y = some Word.tdis ∧ actP a y ∧
    (wv y = 0 ∨ wv y = 10 * thr a) := by
  intro a y; cases a <;> cases y <;> decide

theorem t_before_reg_raw : ∀ a y b : E, tOfP a y → regOfP a b → sbP y b := by
  intro a y b h h'
  rcases h with ⟨rfl, rfl⟩ | ⟨rfl, rfl⟩ | ⟨rfl, rfl⟩ <;>
    rcases h' with ⟨h₁, rfl⟩ | ⟨h₁, rfl⟩ | ⟨h₁, rfl⟩ <;>
    first | decide | exact absurd h₁ (by decide)

theorem t_unique_raw : ∀ a y y' : E, tOfP a y → tOfP a y' → y = y' := by
  intro a y y' h h'
  rcases h with ⟨rfl, rfl⟩ | ⟨rfl, rfl⟩ | ⟨rfl, rfl⟩ <;>
    rcases h' with ⟨h₁, rfl⟩ | ⟨h₁, rfl⟩ | ⟨h₁, rfl⟩ <;>
    first | rfl | exact absurd h₁ (by decide)

def slot : SlotProtocol X liveness where
  st := Word.st
  tdis := Word.tdis
  init := Is
  tInit := It
  isFreeClaim := fun e => e = c0 ∨ e = c1
  isSteal := fun e => e = cs
  stealOf := fun a b => a = c0 ∧ b = cs
  regOf := regOfP
  freeOf := fun a b => a = cs ∧ b = x
  act := actP
  tOf := tOfP
  init_w := rfl
  init_loc := rfl
  init_first := fun hw hl hne => hb_of_sb (st_first _ hw hl hne)
  writes := fun hw hl => st_writes _ hw hl
  claim_w := fun h => (claim_facts _ h).1
  claim_loc := fun h => (claim_facts _ h).2
  fc_isAcq := fun h => by rcases h with rfl | rfl <;> rfl
  fc_reads := fun h => by
    rcases h with rfl | rfl
    · exact ⟨Is, rfl, Or.inl rfl⟩
    · exact ⟨x, rfl, Or.inr ⟨cs, rfl, rfl⟩⟩
  steal_isRel := fun h => by subst h; rfl
  steal_of := fun h => by subst h; exact ⟨c0, rfl, rfl⟩
  stealOf_claims := fun h => by obtain ⟨rfl, rfl⟩ := h; exact ⟨Or.inl (Or.inl rfl), rfl⟩
  steal_ev := fun h => by
    obtain ⟨rfl, rfl⟩ := h
    exact ⟨g0, o, vd, rfl, rfl, rfl, (by decide : sbP o vd), (by decide : sbP vd cs),
      Or.inr ⟨Or.inl ⟨rfl, rfl⟩, t, s0, rfl, rfl, (by decide : sbP o t),
        (by decide : sbP t cs), rfl, rfl, rfl⟩⟩
  reg_claim := fun h => (reg_facts _ _ h).1
  reg_w := fun h => (reg_facts _ _ h).2.1
  reg_loc := fun h => (reg_facts _ _ h).2.2.1
  reg_isRel := fun h => (reg_facts _ _ h).2.2.2.1
  reg_reads := fun h => (reg_facts _ _ h).2.2.2.2.1
  reg_not_claim := fun h => (reg_facts _ _ h).2.2.2.2.2
  reg_owner := fun h h' => reg_owner_raw _ _ _ h h'
  free_w := fun h => by obtain ⟨rfl, rfl⟩ := h; rfl
  free_loc := fun h => by obtain ⟨rfl, rfl⟩ := h; rfl
  free_isRel := fun h => by obtain ⟨rfl, rfl⟩ := h; rfl
  free_reads := fun h => by
    obtain ⟨rfl, rfl⟩ := h
    exact ⟨g, Or.inr (Or.inl ⟨rfl, rfl⟩), rfl⟩
  free_not_claim := fun h => by obtain ⟨rfl, rfl⟩ := h; decide
  act_self := fun {a} h => by rcases h with (rfl | rfl) | rfl <;> decide
  act_sb := fun h => (act_facts _ _ h).1
  act_claim := fun h => by rcases h with ⟨rfl, _⟩ | ⟨rfl, _⟩ | ⟨rfl, _⟩ <;> decide
  act_thr := fun h => (act_facts _ _ h).2
  act_total := fun h h' =>
    thread_total ((act_facts _ _ h).2.trans (act_facts _ _ h').2.symm)
  act_before_free := fun h hact => by obtain ⟨rfl, rfl⟩ := h; exact act_before_x _ hact
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
    rcases h with ⟨rfl, _⟩ | ⟨rfl, _⟩ | ⟨rfl, _⟩
    · exact ⟨s0, by decide⟩
    · exact ⟨s, by decide⟩
    · exact ⟨s1, by decide⟩
  t_val := fun h => (t_facts _ _ h).2.2.2.2

/-! ## The pin protocol -/

abbrev nonAnnP (e : E) : Prop :=
  (∃ a, a = c0 ∧ e = W2) ∨ (∃ w, w = W2 ∧ e = W7) ∨ (∃ a, a = cs ∧ e = P6)

theorem pin_first : ∀ e : E, isWB e = true → locOf e = some Word.pin → e ≠ Ip → sbP Ip e := by
  intro e; cases e <;> decide

theorem pin_writes_raw : ∀ e : E, isWB e = true → locOf e = some Word.pin →
    e = Ip ∨ e = R3A ∨ nonAnnP e := by
  intro e hw hl
  cases e <;> first
    | exact Or.inl rfl
    | exact Or.inr (Or.inl rfl)
    | exact Or.inr (Or.inr (Or.inl ⟨c0, rfl, rfl⟩))
    | exact Or.inr (Or.inr (Or.inr (Or.inl ⟨W2, rfl, rfl⟩)))
    | exact Or.inr (Or.inr (Or.inr (Or.inr ⟨cs, rfl, rfl⟩)))
    | exact absurd hw (by decide)
    | exact absurd hl (by decide)

theorem att_act_raw : ∀ e : E, (e = W2 ∨ sbP W2 e) → actP c0 e := by
  intro e; cases e <;> decide

def pins : PinProtocol X slot where
  pin := Word.pin
  pinInit := Ip
  pinVal := id
  isAnnounce := fun e => e = R3A
  w2Of := fun a w => a = c0 ∧ w = W2
  unpinOf := fun w u => w = W2 ∧ u = W7
  clearOf := fun a k => a = cs ∧ k = P6
  pinInit_w := rfl
  pinInit_loc := rfl
  pinInit_first := fun hw hl hne => hb_of_sb (pin_first _ hw hl hne)
  pin_writes := fun hw hl => pin_writes_raw _ hw hl
  ann_rmw := fun h => by subst h; exact ⟨W2, rfl, rfl⟩
  ann_not := fun h => by
    subst h
    rintro (⟨_, _, h⟩ | ⟨_, _, h⟩ | ⟨_, _, h⟩) <;> exact absurd h (by decide)
  init_not := by
    rintro (⟨_, _, h⟩ | ⟨_, _, h⟩ | ⟨_, _, h⟩) <;> exact absurd h (by decide)
  w2_act := fun h => by obtain ⟨rfl, rfl⟩ := h; exact (by decide : actP c0 W2)
  w2_w := fun h => by obtain ⟨rfl, rfl⟩ := h; rfl
  w2_loc := fun h => by obtain ⟨rfl, rfl⟩ := h; rfl
  w2_isRel := fun h => by obtain ⟨rfl, rfl⟩ := h; rfl
  unpin_w2 := fun h => by
    obtain ⟨rfl, rfl⟩ := h
    exact ⟨c0, ⟨rfl, rfl⟩, (by decide : actP c0 W7)⟩
  unpin_w := fun h => by obtain ⟨rfl, rfl⟩ := h; rfl
  unpin_loc := fun h => by obtain ⟨rfl, rfl⟩ := h; rfl
  unpin_isRel := fun h => by obtain ⟨rfl, rfl⟩ := h; rfl
  clear_act := fun h => by obtain ⟨rfl, rfl⟩ := h; exact (by decide : actP cs P6)
  clear_w := fun h => by obtain ⟨rfl, rfl⟩ := h; rfl
  clear_loc := fun h => by obtain ⟨rfl, rfl⟩ := h; rfl
  clear_isRel := fun h => by obtain ⟨rfl, rfl⟩ := h; rfl
  att_act := fun h he _ => by obtain ⟨rfl, rfl⟩ := h; exact att_act_raw _ he
  pass_quiet := fun {a w u N} h hu hact hN hsb hNu => by
    obtain ⟨rfl, rfl⟩ := h
    obtain ⟨-, rfl⟩ := hu
    rcases hN with ⟨_, _, rfl⟩ | ⟨_, _, rfl⟩ | ⟨_, _, rfl⟩
    · exact absurd hsb (by decide : ¬ sbP W2 W2)
    · exact absurd hNu (by decide : ¬ sbP W7 W7)
    · exact absurd hact (by decide : ¬ actP c0 P6)
  pass_exit := fun {a w N} h _ _ _ => by
    obtain ⟨rfl, rfl⟩ := h
    exact ⟨W7, ⟨rfl, rfl⟩, (by decide : actP c0 W7)⟩

/-! ## The owner word and its takeover -/

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
`T` reads `A1` and is release-only, like O3 in the code
(`rotation.rs:333-335`, `acquire_rotation()`). -/
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

/-- Everything thread 1 does under its tenure is done as registration `c0`. -/
theorem under_A1 : ∀ e : E, (e = A1 ∨ sbP A1 e) → actP c0 e := by
  intro e; cases e <;> decide

/-- The one takeover: `T` reads `A1`'s word. -/
theorem takeover_pair : ∀ a b : E, isAcqCasP a → isAcqCasP b → rfP a b → a = A1 ∧ b = T := by
  intro a b; cases a <;> cases b <;> decide

/-- What the taker saw: it stole the owner's claim itself (`Steal::Stolen`). -/
theorem evidence : TakeoverEvidence ownerEvents slot A1 T c0 :=
  ⟨Or.inl (Or.inl rfl),
    fun e he => slot.act_thr (under_A1 e he.1),
    fun _ _ _ hx => False.elim ((by decide : c0 ≠ cs) hx.1),
    Or.inl ⟨cs, ⟨rfl, rfl⟩, (by decide : sbP cs T)⟩⟩

/-- The owner protocol, with its takeover handoff proved from the evidence. -/
def owner : OwnerProtocol X :=
  ownerEvents.toProtocol consistent (Lv := liveness) (fun {a b} ha hb hrf => by
    obtain ⟨rfl, rfl⟩ := takeover_pair a b ha hb hrf
    exact ⟨slot, c0, evidence⟩)

/-! ## The theorems on this execution -/

/-- OW2: the dead owner's acquisition happens before the takeover. -/
example : X.hb A1 T :=
  owner.tenures_ordered consistent (Or.inl rfl) (Or.inr rfl) (by decide : moP A1 T)
    owner.under_self owner.under_self

/-- SL1: the first registration's unpin happens before the claim that follows
the P3. -/
example : X.hb W7 c1 :=
  slot.serial consistent c1 (Or.inl (Or.inr rfl)) c0 (Or.inl (Or.inl rfl))
    (by decide : moP c0 c1) W7 (by decide : actP c0 W7)

/-- PN2: the pin store is, or happens before, the unpin. -/
example : W2 = W7 ∨ X.hb W2 W7 :=
  pins.attempt_before consistent (c := c0) ⟨rfl, rfl⟩ (Or.inr (Or.inl ⟨W2, rfl, rfl⟩))
    (by decide : moP W2 W7) ⟨Or.inl rfl, fun _ h => Or.inr (h.2 ▸ (by decide : sbP W2 W7))⟩

/-- PN1: the scan load, which reads the clear, is ordered after the unpin. -/
example : X.hb W7 Sc :=
  pins.acquire_sees consistent (N := W7) (w := P6) (r := Sc) (Or.inr (Or.inl ⟨W2, rfl, rfl⟩))
    (rfl : src Sc = some P6) rfl (Or.inr (by decide : moP W7 P6))

/-- QU1: the scan load is ordered after the pin store. -/
example : X.hb W2 Sc :=
  pins.quiescence_core consistent (c := c0) (W2 := W2) (p := P6) (Sc := Sc) (a := W2) (e := 5)
    ⟨rfl, rfl⟩ rfl (rfl : src Sc = some P6) rfl (Or.inr (by decide : moP W2 P6))
    (by decide : id (rv Sc) ≠ 5)
    ⟨Or.inl rfl, fun _ h => Or.inr (h.2 ▸ (by decide : sbP W2 W7))⟩

end TakeoverWitness
end Sgc
