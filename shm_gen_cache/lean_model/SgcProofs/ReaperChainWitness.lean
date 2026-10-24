import SgcProofs.Ranked
import SgcProofs.PinRecovery

/-!
# PN2: the reaper of a reaper clears the original pin

The writer dies after W2. Its first reaper executes P0/T0/verdict/P4, then
stops before T2/P5/P6. A second reaper observes INITIALIZING at that P4,
gets a dead verdict for the first reaper's id (the stale T0 is ignored),
executes P4/T2/P5, and clears the writer's pin. `participant.rs:280-338`
permits this path: INITIALIZING uses `is_live(pid)` and P6 is after P5.

This finite execution satisfies the full memory, liveness, slot and pin
protocols. The next non-announce after W2 is the second reaper's P6;
neither the writer nor its immediate reaper makes a later pin write.
-/

namespace Sgc
namespace ReaperChainWitness

open Execution

inductive E
  | Is | It | Ip
  | c0 | s0 | g0 | W2
  | o | t | vd | c
  | o2 | t2 | vd2 | c2 | s | g | P6
  | Sl
  deriving DecidableEq

inductive Word
  | st | tdis | pin
  deriving DecidableEq

open E

def rank : E → Nat
  | Is => 0 | It => 1 | Ip => 2
  | c0 => 3 | s0 => 4 | g0 => 5 | W2 => 6
  | o => 7 | t => 8 | vd => 9 | c => 10
  | o2 => 11 | t2 => 12 | vd2 => 13 | c2 => 14 | s => 15 | g => 16 | P6 => 17
  | Sl => 18

def thr : E → Nat
  | Is | It | Ip => 0
  | c0 | s0 | g0 | W2 => 1
  | o | t | vd | c => 2
  | o2 | t2 | vd2 | c2 | s | g | P6 => 3
  | Sl => 4

def locOf : E → Option Word
  | Is | c0 | g0 | c | g | o | o2 | c2 => some Word.st
  | It | s0 | s | t | t2 => some Word.tdis
  | Ip | W2 | P6 | Sl => some Word.pin
  | vd | vd2 => none

def isWB : E → Bool
  | Is | It | Ip | c0 | s0 | g0 | W2 | c | c2 | s | g | P6 => true
  | _ => false

def isRB : E → Bool
  | c0 | g0 | c | c2 | g | o | t | o2 | t2 | Sl => true
  | _ => false

def src : E → Option E
  | c0 => some Is | g0 => some c0 | o => some g0 | t => some s0
  | c => some g0 | o2 => some c | t2 => some s0 | c2 => some c
  | g => some c2 | Sl => some P6
  | _ => none

/-- Start times are ten times the thread number; the pin holds epoch 5. -/
def wv : E → Nat
  | s0 => 10 | s => 30 | W2 => 5
  | _ => 0

def rv (e : E) : Nat :=
  match src e with
  | some w => wv w
  | none => 0

def relB : E → Bool
  | g0 | c | c2 | g | W2 | P6 => true
  | _ => false

def acqB : E → Bool
  | c0 | o | o2 | Sl => true
  | _ => false

abbrev sbP (a b : E) : Prop := rank a < rank b ∧ (thr a = thr b ∨ thr a = 0)
abbrev rfP (w r : E) : Prop := src r = some w
abbrev moP (a b : E) : Prop :=
  isWB a = true ∧ isWB b = true ∧ locOf a = locOf b ∧ locOf a ≠ none ∧ rank a < rank b
/-- Each dead verdict imports every action of its own dead thread. -/
abbrev aswP (a b : E) : Prop := (thr a = 1 ∧ b = vd) ∨ (thr a = 2 ∧ b = vd2)

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
  dead := fun v pid st => (v = vd ∧ pid = 1 ∧ st = 10) ∨
    (v = vd2 ∧ pid = 2 ∧ st = 0)
  sound := by
    intro v pid st hd θ hθ _ _ e he
    rcases hd with ⟨rfl, rfl, rfl⟩ | ⟨rfl, rfl, rfl⟩
    · exact hb_of_asw (Or.inl ⟨he.trans hθ, rfl⟩)
    · exact hb_of_asw (Or.inr ⟨he.trans hθ, rfl⟩)

/-! ## The slot protocol -/

abbrev isClaimP (e : E) : Prop := e = c0 ∨ e = c ∨ e = c2
abbrev regOfP (a b : E) : Prop := (a = c0 ∧ b = g0) ∨ (a = c2 ∧ b = g)
abbrev actP (a e : E) : Prop :=
  (a = c0 ∧ thr e = 1) ∨ (a = c ∧ e = c) ∨
    (a = c2 ∧ thr e = 3 ∧ rank c2 ≤ rank e)
abbrev tOfP (a x : E) : Prop := (a = c0 ∧ x = s0) ∨ (a = c2 ∧ x = s)

theorem st_first : ∀ e : E, isWB e = true → locOf e = some Word.st → e ≠ Is → sbP Is e := by
  intro e; cases e <;> decide

theorem st_writes : ∀ e : E, isWB e = true → locOf e = some Word.st →
    e = Is ∨ isClaimP e ∨ (∃ a, regOfP a e) ∨ (∃ _a : E, False) := by
  intro e hw hl
  cases e <;> first
    | exact Or.inl rfl
    | exact Or.inr (Or.inl (by decide))
    | exact Or.inr (Or.inr (Or.inl ⟨c0, by decide⟩))
    | exact Or.inr (Or.inr (Or.inl ⟨c2, by decide⟩))
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
  intro a e f he hf
  have ht := (act_facts a e he).2.trans (act_facts a f hf).2.symm
  rcases Nat.lt_trichotomy (rank e) (rank f) with h | h | h
  · exact Or.inr (Or.inl ⟨h, Or.inl ht⟩)
  · exact Or.inl (rank_inj e f h)
  · exact Or.inr (Or.inr ⟨h, Or.inl ht.symm⟩)

theorem t_first : ∀ e : E, isWB e = true → locOf e = some Word.tdis → e ≠ It → sbP It e := by
  intro e; cases e <;> decide

theorem t_writes_raw : ∀ e : E, isWB e = true → locOf e = some Word.tdis →
    e = It ∨ ∃ a, tOfP a e := by
  intro e hw hl
  cases e <;> first
    | exact Or.inl rfl
    | exact Or.inr ⟨c0, by decide⟩
    | exact Or.inr ⟨c2, by decide⟩
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
  isSteal := fun e => e = c ∨ e = c2
  stealOf := fun a b => (a = c0 ∧ b = c) ∨ (a = c ∧ b = c2)
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
  steal_isRel := fun h => by rcases h with rfl | rfl <;> rfl
  steal_of := fun h => by
    rcases h with rfl | rfl
    · exact ⟨c0, Or.inl ⟨rfl, rfl⟩⟩
    · exact ⟨c, Or.inr ⟨rfl, rfl⟩⟩
  stealOf_claims := fun h => by
    rcases h with ⟨rfl, rfl⟩ | ⟨rfl, rfl⟩ <;> decide
  steal_ev := fun h => by
    rcases h with ⟨rfl, rfl⟩ | ⟨rfl, rfl⟩
    · exact ⟨g0, o, vd, rfl, rfl, rfl, (by decide : sbP o vd), (by decide : sbP vd c),
        Or.inr ⟨Or.inl ⟨rfl, rfl⟩, t, s0, rfl, rfl, (by decide : sbP o t),
          (by decide : sbP t c), Or.inl ⟨rfl, rfl, rfl⟩⟩⟩
    · exact ⟨c, o2, vd2, rfl, rfl, rfl, (by decide : sbP o2 vd2),
        (by decide : sbP vd2 c2), Or.inl ⟨rfl, Or.inr ⟨rfl, rfl, rfl⟩⟩⟩
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
  act_self := fun {a} h => by rcases h with rfl | rfl | rfl <;> decide
  act_sb := fun h => (act_facts _ _ h).1
  act_claim := fun h => by rcases h with ⟨rfl, _⟩ | ⟨rfl, _⟩ | ⟨rfl, _⟩ <;> decide
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
  (∃ a, a = c0 ∧ e = W2) ∨ (∃ _w : E, False) ∨ (∃ a, a = c2 ∧ e = P6)

theorem pin_first : ∀ e : E, isWB e = true → locOf e = some Word.pin → e ≠ Ip → sbP Ip e := by
  intro e; cases e <;> decide

theorem pin_writes_raw : ∀ e : E, isWB e = true → locOf e = some Word.pin →
    e = Ip ∨ False ∨ nonAnnP e := by
  intro e hw hl
  cases e <;> first
    | exact Or.inl rfl
    | exact Or.inr (Or.inr (Or.inl ⟨c0, rfl, rfl⟩))
    | exact Or.inr (Or.inr (Or.inr (Or.inr ⟨c2, rfl, rfl⟩)))
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
  clearOf := fun a k => a = c2 ∧ k = P6
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
  clear_act := fun h => by obtain ⟨rfl, rfl⟩ := h; exact (by decide : actP c2 P6)
  clear_w := fun h => by obtain ⟨rfl, rfl⟩ := h; rfl
  clear_loc := fun h => by obtain ⟨rfl, rfl⟩ := h; rfl
  clear_isRel := fun h => by obtain ⟨rfl, rfl⟩ := h; rfl
  att_act := fun h he _ => by obtain ⟨rfl, rfl⟩ := h; exact att_act_raw _ he
  pass_quiet := fun _ hu _ _ _ _ => hu.elim
  pass_exit := fun h hact _ hsb => by
    obtain ⟨rfl, rfl⟩ := h
    exact (no_later_pin_write _ hact hsb).elim

/-- The source-order facts are realized by this interrupted steal schedule. -/
theorem reaperFacts : ReaperPinFacts pins := {
  clear_registered := fun h => by
    obtain ⟨rfl, rfl⟩ := h
    exact ⟨g, Or.inr ⟨rfl, rfl⟩, (by decide : sbP g P6)⟩
  clear_before_pin := fun hk hw => by
    obtain ⟨rfl, rfl⟩ := hk
    exact absurd hw.1 (by decide)
  steal_pin_clear := fun hs hw => by
    obtain ⟨rfl, rfl⟩ := hw
    exact absurd hs (by decide : ¬ (c0 = c ∨ c0 = c2))
  steal_free_clear := fun _ h => h.elim
  unpin_after := fun h => h.elim
}

/-- The first reaper stops after P4, before successful P5. -/
theorem first_reaper_no_register : ∀ k, ¬ slot.regOf c k := by
  intro k h
  rcases h with ⟨h, _⟩ | ⟨h, _⟩ <;> exact absurd h (by decide)

/-- **PN2 witness.** Both steals occur, the immediate reaper never clears,
and the second reaper's P6 is the next non-announce after the dead pin. -/
theorem reaper_of_reaper :
    X.Consistent ∧ slot.stealOf c0 c ∧ slot.stealOf c c2 ∧
    (∀ k, ¬ pins.clearOf c k) ∧ pins.clearOf c2 P6 ∧
    X.mo W2 P6 ∧
    (∀ N, pins.nonAnn N → X.mo W2 N → N = P6) ∧ X.hb W2 P6 := by
  have hclose := pins.attempt_before consistent (c := c0) ⟨rfl, rfl⟩
    (Or.inr (Or.inr ⟨c2, rfl, rfl⟩)) (by decide : moP W2 P6)
    ⟨Or.inl rfl, fun _ h => h.elim⟩
  refine ⟨consistent, Or.inl ⟨rfl, rfl⟩, Or.inr ⟨rfl, rfl⟩,
    ?_, ⟨rfl, rfl⟩, (by decide : moP W2 P6), ?_, ?_⟩
  · exact pins.no_clear_before_registration reaperFacts first_reaper_no_register
  · intro N h hm
    rcases h with ⟨a, _, rfl⟩ | ⟨a, h⟩ | ⟨a, _, rfl⟩
    · exact (consistent.mo_irrefl W2 hm).elim
    · exact h.elim
    · rfl
  · exact hclose.resolve_left (by decide)

/-- Apply the universal provenance theorem to the concrete nonempty chain.
The first reaper's lack of P6 follows from its interrupted P5 protocol. -/
theorem next_clear_protocol_provenance :
    ∃ d, Relation.TransGen slot.stealOf c d ∧ pins.clearOf d P6 := by
  obtain ⟨hc, hs, _, hstop, _, hm, hnext, _⟩ := reaper_of_reaper
  apply pins.next_clear_descends reaperFacts hc (G := c0) (w := W2)
    ⟨rfl, rfl⟩ (fun _ h => h.elim) hs hstop
    (Or.inr (Or.inr ⟨c2, rfl, rfl⟩)) hm
  intro k hk hwk hkN
  exact hc.mo_irrefl P6 (hnext k hk hwk ▸ hkN)

end ReaperChainWitness
end Sgc
