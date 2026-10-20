import SgcProofs.PhysicalPinCleanup
import SgcProofs.Ranked

/-! A consistent finite source execution of both cleanup branches. An early
zero guard Q0/R0 precedes a later insertion that is interrupted after W2.
The actual guard Q reads W2, U swaps zero, and R observes the release
boundary. The registration dies there before P3; a later reaper clears it.
All event-index and inventory fields of PhysicalCleanupTrace are checked. -/

set_option maxHeartbeats 2000000

namespace Sgc
namespace PhysicalCleanupWitness

open Execution

inductive E
  | Is | It | Ip
  | c0 | s0 | g0 | Q0 | R0 | W2 | Q | U | R
  | o | t | vd | c | s | g | P6
  | Sc | Wait | Rl | Sl
  deriving DecidableEq

inductive Word
  | st | tdis | pin
  deriving DecidableEq

open E

def rank : E → Nat
  | Is => 0 | It => 1 | Ip => 2
  | c0 => 3 | s0 => 4 | g0 => 5 | Q0 => 6 | R0 => 7 | W2 => 8 | Q => 9 | U => 10 | R => 11
  | Sc => 12 | Wait => 13
  | o => 14 | t => 15 | vd => 16 | c => 17 | s => 18 | g => 19 | P6 => 20
  | Rl => 21 | Sl => 22

def thr : E → Nat
  | Is | It | Ip => 0
  | c0 | s0 | g0 | Q0 | R0 | W2 | Q | U | R => 1
  | Sc | Wait | o | t | vd | c | s | g | P6 | Rl | Sl => 2

def locOf : E → Option Word
  | Is | c0 | g0 | c | g | o => some Word.st
  | It | s0 | s | t => some Word.tdis
  | Ip | Q0 | R0 | W2 | Q | U | R | P6 | Sc | Rl | Sl => some Word.pin
  | vd | Wait => none

def isWB : E → Bool
  | Is | It | Ip | c0 | s0 | g0 | W2 | U | c | s | g | P6 => true
  | _ => false

def isRB : E → Bool
  | c0 | g0 | Q0 | R0 | Q | U | R | c | g | o | t | Sc | Rl | Sl => true
  | _ => false

def src : E → Option E
  | c0 => some Is | g0 => some c0 | o => some g0 | t => some s0
  | c => some g0 | g => some c | Sc | R => some U | Rl | Sl => some P6
  | Q0 | R0 => some Ip | Q | U => some W2
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
  | g0 | c | g | W2 | U | P6 => true
  | _ => false

def acqB : E → Bool
  | c0 | o | Sc | Sl => true
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

instance (e : E) : Decidable (X.isW e) := inferInstanceAs (Decidable (isWB e = true))
instance (a b : E) : Decidable (X.sb a b) := inferInstanceAs (Decidable (sbP a b))

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
  intro a e f he hf
  have ht : thr e = thr f := (act_facts a e he).2.trans (act_facts a f hf).2.symm
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
  (∃ a, a = c0 ∧ e = W2) ∨ (∃ w : E, w = W2 ∧ e = U) ∨ (∃ a, a = c ∧ e = P6)

theorem pin_first : ∀ e : E, isWB e = true → locOf e = some Word.pin → e ≠ Ip → sbP Ip e := by
  intro e; cases e <;> decide

theorem pin_writes_raw : ∀ e : E, isWB e = true → locOf e = some Word.pin →
    e = Ip ∨ False ∨ nonAnnP e := by
  intro e hw hl
  cases e <;> first
    | exact Or.inl rfl
    | exact Or.inr (Or.inr (Or.inl ⟨c0, rfl, rfl⟩))
    | exact Or.inr (Or.inr (Or.inr (Or.inl ⟨W2, rfl, rfl⟩)))
    | exact Or.inr (Or.inr (Or.inr (Or.inr ⟨c, rfl, rfl⟩)))
    | exact absurd hw (by decide)
    | exact absurd hl (by decide)

theorem att_act_raw : ∀ e : E, (e = W2 ∨ sbP W2 e) → actP c0 e := by
  intro e; cases e <;> decide

def pins : PinProtocol X slot where
  pin := Word.pin
  pinInit := Ip
  pinVal := id
  isAnnounce := fun _ => False
  w2Of := fun a w => a = c0 ∧ w = W2
  unpinOf := fun w u => w = W2 ∧ u = U
  clearOf := fun a k => a = c ∧ k = P6
  pinInit_w := rfl
  pinInit_loc := rfl
  pinInit_first := fun hw hl hne => hb_of_sb (pin_first _ hw hl hne)
  pin_writes := fun hw hl => pin_writes_raw _ hw hl
  ann_rmw := fun h => h.elim
  ann_not := fun h => h.elim
  init_not := by
    rintro (⟨_, _, h⟩ | ⟨_, _, h⟩ | ⟨_, _, h⟩)
    · exact absurd h (by decide)
    · exact absurd h (by decide)
    · exact absurd h (by decide)
  w2_act := fun h => by obtain ⟨rfl, rfl⟩ := h; exact (by decide : actP c0 W2)
  w2_w := fun h => by obtain ⟨rfl, rfl⟩ := h; rfl
  w2_loc := fun h => by obtain ⟨rfl, rfl⟩ := h; rfl
  w2_isRel := fun h => by obtain ⟨rfl, rfl⟩ := h; rfl
  unpin_w2 := fun h => by obtain ⟨rfl, rfl⟩ := h; exact ⟨c0, ⟨rfl, rfl⟩, (by decide : actP c0 U)⟩
  unpin_w := fun h => by obtain ⟨rfl, rfl⟩ := h; rfl
  unpin_loc := fun h => by obtain ⟨rfl, rfl⟩ := h; rfl
  unpin_isRel := fun h => by obtain ⟨rfl, rfl⟩ := h; rfl
  clear_act := fun h => by obtain ⟨rfl, rfl⟩ := h; exact (by decide : actP c P6)
  clear_w := fun h => by obtain ⟨rfl, rfl⟩ := h; rfl
  clear_loc := fun h => by obtain ⟨rfl, rfl⟩ := h; rfl
  clear_isRel := fun h => by obtain ⟨rfl, rfl⟩ := h; rfl
  att_act := fun h he _ => by obtain ⟨rfl, rfl⟩ := h; exact att_act_raw _ he
  pass_quiet := by
    intro a w u N hw hu hact hN hsb hsb'
    obtain ⟨rfl, rfl⟩ := hw
    obtain ⟨_, rfl⟩ := hu
    rcases hN with ⟨_, _, rfl⟩ | ⟨_, _, rfl⟩ | ⟨_, _, rfl⟩ <;>
      simp [X, sbP, rank] at hsb hsb'
  pass_exit := fun h _ _ _ => by
    obtain ⟨rfl, rfl⟩ := h
    exact ⟨U, ⟨rfl, rfl⟩, (by decide : actP c0 U)⟩


/-- A source projection containing both guard loads and all own pin writes. -/
def actions : List CleanupEvent := [.guardRead, .boundaryRead, .w2, .guardRead, .w7, .boundaryRead]

def eventAt : Nat → E
  | 0 => Q0 | 1 => R0 | 2 => W2 | 3 => Q | 4 => U | 5 => R | _ => Q0

def positionOf : E → Nat
  | Q0 => 0 | R0 => 1 | W2 => 2 | Q => 3 | U => 4 | R => 5 | _ => 0

private theorem six {n : Nat} (h : n < 6) :
    n = 0 ∨ n = 1 ∨ n = 2 ∨ n = 3 ∨ n = 4 ∨ n = 5 := by omega

/-- Every field is proved against concrete source occurrences and the
consistent atomic execution. In particular both physical guard reads are
indexed, and the inventory includes every pin write of this registration. -/
def trace : PhysicalCleanupTrace pins c0 actions where
  event := eventAt
  position := positionOf
  event_position := by
    intro j hj
    rcases six hj with rfl | rfl | rfl | rfl | rfl | rfl <;> rfl
  event_order := by
    intro i j hij hj
    change j < 6 at hj
    rcases six hj with rfl | rfl | rfl | rfl | rfl | rfl
    all_goals rcases six (show i < 6 by omega) with rfl | rfl | rfl | rfl | rfl | rfl
    all_goals first | exact (by decide) | omega
  owned := by
    intro j hj
    change actP c0 (eventAt j)
    rcases six hj with rfl | rfl | rfl | rfl | rfl | rfl <;> decide
  pin_inventory := by
    intro N hN hact
    change nonAnnP N at hN
    rcases hN with ⟨d, rfl, rfl⟩ | ⟨w, rfl, rfl⟩ | ⟨d, rfl, rfl⟩
    · exact ⟨rfl, .w2, rfl, trivial⟩
    · exact ⟨rfl, .w7, rfl, trivial⟩
    · exact False.elim ((by decide : ¬ actP c0 P6) hact)
  pin_site := by
    intro j hj
    have hlen := (List.getElem?_eq_some_iff.mp hj).choose
    rcases six hlen with rfl | rfl | rfl | rfl | rfl | rfl <;>
      first | exact ⟨rfl, rfl⟩ | simp [actions] at hj
  pin_value := by
    intro j hj
    have hlen := (List.getElem?_eq_some_iff.mp hj).choose
    rcases six hlen with rfl | rfl | rfl | rfl | rfl | rfl <;>
      first | exact (by decide) | simp [actions] at hj
  unpin_site := by
    intro j hj
    have hlen := (List.getElem?_eq_some_iff.mp hj).choose
    rcases six hlen with rfl | rfl | rfl | rfl | rfl | rfl <;>
      first | exact ⟨W2, rfl, rfl⟩ | simp [actions] at hj
  unpin_value := by
    intro j hj
    have hlen := (List.getElem?_eq_some_iff.mp hj).choose
    rcases six hlen with rfl | rfl | rfl | rfl | rfl | rfl <;>
      first | rfl | simp [actions] at hj

/-- The early zero branch performs no W7 and the later boundary reads zero. -/
theorem zero_cleanup_execution : X.Consistent ∧ X.rval Q0 = 0 ∧ X.rval R0 = 0 := by
  refine ⟨consistent, rfl, ?_⟩
  exact physical_cleanup_release_reads_zero pins (q := Q0) (r := R0)
    (before := []) (after := [.w2, .guardRead, .w7, .boundaryRead])
    trace consistent rfl rfl (by decide) (by decide) rfl rfl
    (show X.rf Ip Q0 from rfl) (show X.rf Ip R0 from rfl) rfl (fun h => h.elim)

/-- The interrupted W2 forces the physical nonzero branch; its W7 clears
the pin before the actual later boundary read. -/
theorem pinned_cleanup_execution : X.Consistent ∧ X.rval Q ≠ 0 ∧ X.rval R = 0 := by
  refine ⟨consistent, ?_, ?_⟩
  · exact physical_cleanup_load_sees_outstanding_pin pins (q := Q)
      (start := [.guardRead, .boundaryRead]) (after := []) trace consistent rfl
      (by decide) rfl (show X.rf W2 Q from rfl) rfl
  · exact physical_cleanup_release_reads_zero pins (q := Q) (r := R)
      (before := [.guardRead, .boundaryRead, .w2]) (after := []) trace consistent rfl rfl
      (by decide) (by decide) rfl rfl (show X.rf W2 Q from rfl)
      (show X.rf U R from rfl) rfl (fun h => h.elim)

/-- The same concrete events realize an arbitrary-prefix source adapter
instance: an insert interrupted immediately after its successful W2. -/
theorem interrupted_insert_execution :
    (∃ state, PinFlow.run false [.w2] = some state) ∧ X.rval R = 0 := by
  let call : PinFlow.CallExecution := .insert (.store (.published (.allocated .chunk) rfl))
  exact interrupted_physical_release_reads_zero pins call
    (pre := [.w2]) (suffix := [.w7, .returnCall]) rfl
    (q := Q) (r := R) (registrationPrefix := [.guardRead, .boundaryRead]) (later := [])
    trace consistent rfl rfl (by decide) (by decide) rfl rfl
    (show X.rf W2 Q from rfl) (show X.rf U R from rfl) rfl (fun h => h.elim)

end PhysicalCleanupWitness
end Sgc
