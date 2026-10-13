import SgcProofs.Ranked
import SgcProofs.Slot

/-!
# A witness for the `INITIALIZING` branch of a steal

Nine events in which a claimant dies before it registers:

* thread 1 claims the slot (`c0`, P1) and stops, leaving
  `INITIALIZING(c0)`: no start time, no register;
* thread 2 loads the state (`o`), gets a "dead" verdict about thread 1's id
  alone (`vd`, the start time 0), steals the claim (`c`, P4), stores its own
  start time (`s`) and registers (`g`, P5);
* thread incarnation 3 reuses thread 1's backend id and performs an acquire
  load (`reuse`) of `g` after the verdict.

This is the first alternative of `SlotProtocol.steal_ev`, and the `st = 0`
case of `Liveness.sound`, which `SlotWitness.lean` and
`TakeoverWitness.lean` do not exercise. P1 is not a release, so the only
path from `c0` to the steal is the verdict's `asw` edge (assumption A3). SL1
is instantiated on the execution at the end. The later incarnation's load
remains after the verdict despite sharing the dead claimant's backend id.
-/

namespace Sgc
namespace InitStealWitness

open Execution

inductive E
  | Is | It
  | c0
  | o | vd | c | s | g
  | reuse
  deriving DecidableEq

inductive Word
  | st | tdis
  deriving DecidableEq

open E

def rank : E → Nat
  | Is => 0 | It => 1
  | c0 => 2
  | o => 3 | vd => 4 | c => 5 | s => 6 | g => 7
  | reuse => 8

def thr : E → Nat
  | Is | It => 0
  | c0 => 1
  | o | vd | c | s | g => 2
  | reuse => 3

/-- Incarnation 3 reuses incarnation 1's backend id. -/
def pidOf (θ : Nat) : Nat := if θ = 3 then 1 else θ

def locOf : E → Option Word
  | Is | c0 | o | c | g | reuse => some Word.st
  | It | s => some Word.tdis
  | vd => none

def isWB : E → Bool
  | Is | It | c0 | c | s | g => true
  | _ => false

def isRB : E → Bool
  | c0 | o | c | g | reuse => true
  | _ => false

def src : E → Option E
  | c0 => some Is | o => some c0 | c => some c0 | g => some c
  | reuse => some g
  | _ => none

/-- Start times are ten times the thread number. -/
def wv : E → Nat
  | s => 20
  | _ => 0

def rv (e : E) : Nat :=
  match src e with
  | some w => wv w
  | none => 0

def relB : E → Bool
  | c | g => true
  | _ => false

def acqB : E → Bool
  | c0 | o | reuse => true
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

/-! ## Assumption A3: a verdict about the id alone -/

def liveness : Liveness X where
  thrOf := thr
  pidOf := pidOf
  startOf := fun θ => 10 * θ
  dead := fun v pid st => v = vd ∧ pid = 1 ∧ st = 0
  sound := by
    intro v pid st hd θ hθ _ hseen e he
    obtain ⟨rfl, rfl, rfl⟩ := hd
    obtain ⟨w, r, hw, hrf, hsb⟩ := hseen
    have hwv : rank w < rank vd :=
      Nat.lt_trans (transGen_lt_of_rank (fun a b h => rf_rank a b h) hrf) hsb.1
    have hθne : θ ≠ 3 := by
      intro h
      have hw3 : thr w = 3 := hw.trans h
      cases w <;> simp [thr, rank] at hw3 hwv
    have hθold : θ = 1 := by simpa [pidOf, hθne] using hθ
    exact hb_of_asw ⟨he.trans hθold, rfl⟩

/-! ## The slot protocol -/

abbrev isClaimP (e : E) : Prop := e = c0 ∨ e = c
abbrev actP (a e : E) : Prop := (a = c0 ∧ thr e = 1) ∨ (a = c ∧ thr e = 2 ∧ rank c ≤ rank e)

theorem st_first : ∀ e : E, isWB e = true → locOf e = some Word.st → e ≠ Is → sbP Is e := by
  intro e; cases e <;> decide

theorem st_writes : ∀ e : E, isWB e = true → locOf e = some Word.st →
    e = Is ∨ isClaimP e ∨ (∃ a, a = c ∧ e = g) ∨ (∃ _a : E, False) := by
  intro e hw hl
  cases e <;> first
    | exact Or.inl rfl
    | exact Or.inr (Or.inl (by decide))
    | exact Or.inr (Or.inr (Or.inl ⟨c, rfl, rfl⟩))
    | exact absurd hw (by decide)
    | exact absurd hl (by decide)

theorem claim_facts : ∀ a : E, isClaimP a → isWB a = true ∧ locOf a = some Word.st := by
  intro a; cases a <;> decide

theorem act_facts : ∀ a e : E, actP a e → (e = a ∨ sbP a e) ∧ thr e = thr a := by
  intro a e; cases a <;> cases e <;> decide

theorem act_total_raw : ∀ a e f : E, actP a e → actP a f → e = f ∨ sbP e f ∨ sbP f e := by
  intro a e f; cases a <;> cases e <;> cases f <;> decide

theorem t_first : ∀ e : E, isWB e = true → locOf e = some Word.tdis → e ≠ It → sbP It e := by
  intro e; cases e <;> decide

theorem t_writes_raw : ∀ e : E, isWB e = true → locOf e = some Word.tdis →
    e = It ∨ ∃ a, a = c ∧ e = s := by
  intro e hw hl
  cases e <;> first
    | exact Or.inl rfl
    | exact Or.inr ⟨c, rfl, rfl⟩
    | exact absurd hw (by decide)
    | exact absurd hl (by decide)

def slot : SlotProtocol X liveness where
  st := Word.st
  tdis := Word.tdis
  init := Is
  tInit := It
  isFreeClaim := fun e => e = c0
  isSteal := fun e => e = c
  stealOf := fun a b => a = c0 ∧ b = c
  regOf := fun a b => a = c ∧ b = g
  freeOf := fun _ _ => False
  act := actP
  tOf := fun a x => a = c ∧ x = s
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
    -- The state still holds the claim's own word: `INITIALIZING(c0)`.
    exact ⟨c0, o, vd, rfl, rfl, rfl, (by decide : sbP o vd), (by decide : sbP vd c),
      Or.inl ⟨rfl, rfl, rfl, rfl⟩⟩
  reg_claim := fun h => by obtain ⟨rfl, rfl⟩ := h; exact Or.inr rfl
  reg_w := fun h => by obtain ⟨rfl, rfl⟩ := h; rfl
  reg_loc := fun h => by obtain ⟨rfl, rfl⟩ := h; rfl
  reg_isRel := fun h => by obtain ⟨rfl, rfl⟩ := h; rfl
  reg_reads := fun h => by obtain ⟨rfl, rfl⟩ := h; rfl
  reg_not_claim := fun h => by obtain ⟨rfl, rfl⟩ := h; decide
  reg_owner := fun h h' => h.1.trans h'.1.symm
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
  t_w := fun h => by obtain ⟨rfl, rfl⟩ := h; rfl
  t_loc := fun h => by obtain ⟨rfl, rfl⟩ := h; rfl
  t_act := fun h => by obtain ⟨rfl, rfl⟩ := h; decide
  t_before_reg := fun h h' => by
    obtain ⟨rfl, rfl⟩ := h
    obtain ⟨-, rfl⟩ := h'
    exact (by decide : sbP s g)
  t_unique := fun h h' => h.2.trans h'.2.symm
  t_of_reg := fun h => by obtain ⟨rfl, rfl⟩ := h; exact ⟨s, rfl, rfl⟩
  t_val := fun h => by obtain ⟨rfl, rfl⟩ := h; exact Or.inr rfl

/-! ## The theorem on this execution -/

/-- SL1: the dead claimant's claim happens before the steal, through the
verdict about its id alone. -/
example : X.hb c0 c :=
  slot.serial consistent c (Or.inr rfl) c0 (Or.inl rfl) (by decide : moP c0 c) c0
    (by decide : actP c0 c0)

/-- ... and so before everything the stealer does, up to its register. -/
example : X.hb c0 g :=
  slot.acts_ordered consistent (Or.inl rfl) (Or.inr rfl) (by decide : moP c0 c)
    (by decide : actP c0 c0) (by decide : actP c g)

/-- A consistent execution admits a new incarnation with the dead thread's
backend id after the verdict; the verdict does not order it backwards. -/
theorem pid_reuse_after_verdict :
    X.Consistent ∧ liveness.dead vd (liveness.pidOf (thr c0)) 0 ∧
      thr c0 ≠ thr reuse ∧ liveness.pidOf (thr c0) = liveness.pidOf (thr reuse) ∧
      liveness.startOf (thr c0) ≠ liveness.startOf (thr reuse) ∧
      X.hb vd reuse ∧ ¬ X.hb reuse vd := by
  have hafter : X.hb vd reuse :=
    hb_trans (hb_of_sb (by decide : sbP vd g))
      (hb_of_sw (sw.relAcq rfl (rs.refl g) (by rfl : X.rf g reuse) rfl))
  exact ⟨consistent, ⟨rfl, rfl, rfl⟩, by decide, rfl, by decide, hafter,
    fun h => consistent.hb_irrefl vd (hb_trans hafter h)⟩

end InitStealWitness
end Sgc
