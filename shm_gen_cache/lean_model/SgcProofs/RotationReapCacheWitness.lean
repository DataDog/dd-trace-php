import SgcProofs.Ranked
import SgcProofs.Pins
import SgcProofs.RotationScan
import SgcProofs.ReaperPinFlow

/-! Full consistent cache execution: a store pins epoch 2 and dies after
B0; tenures publish 3 and 4, then the epoch-4 reuse scan waits for and
reaps the older pin before its certificate and reset. -/

namespace Sgc
namespace RotationReapCacheWitness

open Execution

inductive E
  | iReady | Is | It | Ip | iOwn | iEp | iC0 | iC1 | iC2
  | c0 | s0 | g0 | W1 | W2 | W3 | B0
  | t1A | t1q | t1Z | t1F | t1S | t1R | t1P | t1O
  | t2A | t2q | t2Z | t2F | t2S | t2R | t2P | t2O
  | t3A | t3q | t3Z | t3F | Sc | Wait | o | t | vd | c | s | g | P6 | Rl | Sl
  | Cert | t3R | t3P | t3O | Rst | Free
  | iRS | iRT | iRP | cR | sR | gR | t1OwnS | t2OwnS | t3OwnS
  | spin0 | spin1 | spin2 | spin3 | spin4 | spin5 | spin6 | spin7
  | spin8 | spin9 | spin10 | spin11 | spin12 | spin13 | spin14 | spin15
  | spin16 | spin17 | spin18 | spin19 | spin20 | spin21 | spin22 | spin23
  | spin24 | spin25 | spin26 | spin27 | spin28 | spin29 | spin30 | spin31
  | spin32 | spin33 | spin34 | spin35 | spin36 | spin37 | spin38 | spin39
  | spin40 | spin41 | spin42 | spin43 | spin44 | spin45 | spin46 | spin47
  | spin48 | spin49 | spin50 | spin51 | spin52 | spin53 | spin54 | spin55
  | spin56 | spin57 | spin58 | spin59 | spin60 | spin61 | spin62 | spin63
  | spin64
  deriving DecidableEq

inductive Word
  | st | tdis | pin | own | ep | c0 | c1 | c2 | ready0 | ready1 | ready2
  | rotState | rotTime | rotPin
  deriving DecidableEq

open E

def rank : E → Nat
  | iRS => 0
  | iRT => 1
  | iRP => 2
  | iReady => 3
  | Is => 4
  | It => 5
  | Ip => 6
  | iOwn => 7
  | iEp => 8
  | iC0 => 9
  | iC1 => 10
  | iC2 => 11
  | c0 => 12
  | s0 => 13
  | g0 => 14
  | W1 => 15
  | W2 => 16
  | W3 => 17
  | B0 => 18
  | cR => 19
  | sR => 20
  | gR => 21
  | t1A => 22
  | t1q => 23
  | t1Z => 24
  | t1F => 25
  | t1S => 26
  | t1OwnS => 27
  | t1R => 28
  | t1P => 29
  | t1O => 30
  | t2A => 31
  | t2q => 32
  | t2Z => 33
  | t2F => 34
  | t2S => 35
  | t2OwnS => 36
  | t2R => 37
  | t2P => 38
  | t2O => 39
  | t3A => 40
  | t3q => 41
  | t3Z => 42
  | t3F => 43
  | spin0 => 44
  | spin1 => 45
  | spin2 => 46
  | spin3 => 47
  | spin4 => 48
  | spin5 => 49
  | spin6 => 50
  | spin7 => 51
  | spin8 => 52
  | spin9 => 53
  | spin10 => 54
  | spin11 => 55
  | spin12 => 56
  | spin13 => 57
  | spin14 => 58
  | spin15 => 59
  | spin16 => 60
  | spin17 => 61
  | spin18 => 62
  | spin19 => 63
  | spin20 => 64
  | spin21 => 65
  | spin22 => 66
  | spin23 => 67
  | spin24 => 68
  | spin25 => 69
  | spin26 => 70
  | spin27 => 71
  | spin28 => 72
  | spin29 => 73
  | spin30 => 74
  | spin31 => 75
  | spin32 => 76
  | spin33 => 77
  | spin34 => 78
  | spin35 => 79
  | spin36 => 80
  | spin37 => 81
  | spin38 => 82
  | spin39 => 83
  | spin40 => 84
  | spin41 => 85
  | spin42 => 86
  | spin43 => 87
  | spin44 => 88
  | spin45 => 89
  | spin46 => 90
  | spin47 => 91
  | spin48 => 92
  | spin49 => 93
  | spin50 => 94
  | spin51 => 95
  | spin52 => 96
  | spin53 => 97
  | spin54 => 98
  | spin55 => 99
  | spin56 => 100
  | spin57 => 101
  | spin58 => 102
  | spin59 => 103
  | spin60 => 104
  | spin61 => 105
  | spin62 => 106
  | spin63 => 107
  | spin64 => 108
  | Sc => 109
  | Wait => 110
  | o => 111
  | t => 112
  | vd => 113
  | c => 114
  | s => 115
  | g => 116
  | P6 => 117
  | Rst => 118
  | Rl => 119
  | Free => 120
  | Sl => 121
  | t3OwnS => 122
  | Cert => 123
  | t3R => 124
  | t3P => 125
  | t3O => 126

def thr : E → Nat
  | iRS | iRT | iRP | iReady | Is | It | Ip | iOwn | iEp | iC0 | iC1 | iC2 => 0
  | c0 | s0 | g0 | W1 | W2 | W3 | B0 => 1
  | _ => 2

def locOf : E → Option Word
  | iRS | cR | gR => some Word.rotState
  | iRT | sR => some Word.rotTime
  | iRP | t1OwnS | t2OwnS | t3OwnS => some Word.rotPin
  | Is | c0 | g0 | c | g | o | Rst | Free => some Word.st
  | It | s0 | s | t => some Word.tdis
  | Ip | W2 | P6 | Sc | Rl | Sl => some Word.pin
  | iOwn | t1A | t1O | t2A | t2O | t3A | t3O => some Word.own
  | iEp | W1 | t1q | t1P | t2q | t2P | t3q | t3P => some Word.ep
  | iC0 | t1R | t2Z => some Word.c0
  | iC1 | t2R | t3Z => some Word.c1
  | iC2 | B0 | t1Z | t3R => some Word.c2
  | spin0 | spin1 | spin2 | spin3 | spin4 | spin5 | spin6 | spin7
  | spin8 | spin9 | spin10 | spin11 | spin12 | spin13 | spin14 | spin15
  | spin16 | spin17 | spin18 | spin19 | spin20 | spin21 | spin22 | spin23
  | spin24 | spin25 | spin26 | spin27 | spin28 | spin29 | spin30 | spin31
  | spin32 | spin33 | spin34 | spin35 | spin36 | spin37 | spin38 | spin39
  | spin40 | spin41 | spin42 | spin43 | spin44 | spin45 | spin46 | spin47
  | spin48 | spin49 | spin50 | spin51 | spin52 | spin53 | spin54 | spin55
  | spin56 | spin57 | spin58 | spin59 | spin60 | spin61 | spin62 | spin63
  | spin64 => some Word.pin
  | t1S | t2S => some Word.pin
  | iReady | Cert => some Word.ready1
  | _ => none

def isWB : E → Bool
  | iRS | iRT | iRP | cR | sR | gR => true
  | Free => true
  | iReady | Cert | Is | It | Ip | c0 | s0 | g0 | W2 | c | s | g | P6
  | iOwn | iEp | iC0 | iC1 | iC2
  | t1A | t1Z | t1R | t1P | t1O | t2A | t2Z | t2R | t2P | t2O
  | t3A | t3Z | t3R | t3P | t3O => true
  | _ => false

def isRB : E → Bool
  | cR | gR | t1OwnS | t2OwnS | t3OwnS => true
  | Rst | Free => true
  | spin0 | spin1 | spin2 | spin3 | spin4 | spin5 | spin6 | spin7
  | spin8 | spin9 | spin10 | spin11 | spin12 | spin13 | spin14 | spin15
  | spin16 | spin17 | spin18 | spin19 | spin20 | spin21 | spin22 | spin23
  | spin24 | spin25 | spin26 | spin27 | spin28 | spin29 | spin30 | spin31
  | spin32 | spin33 | spin34 | spin35 | spin36 | spin37 | spin38 | spin39
  | spin40 | spin41 | spin42 | spin43 | spin44 | spin45 | spin46 | spin47
  | spin48 | spin49 | spin50 | spin51 | spin52 | spin53 | spin54 | spin55
  | spin56 | spin57 | spin58 | spin59 | spin60 | spin61 | spin62 | spin63
  | spin64 => true
  | Cert | c0 | g0 | c | g | o | t | Sc | Rl | Sl | W1 | B0
  | t1A | t1q | t1Z | t1S | t2A | t2q | t2Z | t2S | t3A | t3q | t3Z => true
  | _ => false

def src : E → Option E
  | cR => some iRS | gR => some cR
  | t1OwnS | t2OwnS | t3OwnS => some iRP
  | Rst | Free => some g
  | spin0 | spin1 | spin2 | spin3 | spin4 | spin5 | spin6 | spin7
  | spin8 | spin9 | spin10 | spin11 | spin12 | spin13 | spin14 | spin15
  | spin16 | spin17 | spin18 | spin19 | spin20 | spin21 | spin22 | spin23
  | spin24 | spin25 | spin26 | spin27 | spin28 | spin29 | spin30 | spin31
  | spin32 | spin33 | spin34 | spin35 | spin36 | spin37 | spin38 | spin39
  | spin40 | spin41 | spin42 | spin43 | spin44 | spin45 | spin46 | spin47
  | spin48 | spin49 | spin50 | spin51 | spin52 | spin53 | spin54 | spin55
  | spin56 | spin57 | spin58 | spin59 | spin60 | spin61 | spin62 | spin63
  | spin64 => some W2
  | c0 => some Is | g0 => some c0 | o => some g0 | t => some s0
  | c => some g0 | g => some c | Sc => some W2 | Rl | Sl => some P6
  | Cert => some iReady
  | W1 => some iEp | B0 => some iC2
  | t1A => some iOwn | t1q => some iEp | t1Z => some iC2 | t1S => some W2
  | t2A => some t1O | t2q => some t1P | t2Z => some t1R | t2S => some W2
  | t3A => some t2O | t3q => some t2P | t3Z => some t2R
  | _ => none

/-- Start times are ten times the thread number; the pin holds epoch 2. -/
def wv : E → Nat
  | sR => 20
  | Cert => 4
  | s0 => 10 | s => 20 | W2 => 2
  | iEp => 2 | t1P => 3 | t2P => 4 | t3P => 5
  | iC0 | iC1 => 1 | iC2 => 4 | t1Z => 5 | t1R => 6
  | t2Z => 7 | t2R => 8 | t3Z => 9 | t3R => 10
  | _ => 0

def rv (e : E) : Nat :=
  match src e with
  | some w => wv w
  | none => 0

def relB : E → Bool
  | gR => true
  | Free => true
  | Cert | g0 | c | g | W2 | P6
  | t1A | t1P | t1O | t2A | t2P | t2O | t3A | t3P | t3O => true
  | _ => false

def acqB : E → Bool
  | cR | t1OwnS | t2OwnS | t3OwnS => true
  | spin0 | spin1 | spin2 | spin3 | spin4 | spin5 | spin6 | spin7
  | spin8 | spin9 | spin10 | spin11 | spin12 | spin13 | spin14 | spin15
  | spin16 | spin17 | spin18 | spin19 | spin20 | spin21 | spin22 | spin23
  | spin24 | spin25 | spin26 | spin27 | spin28 | spin29 | spin30 | spin31
  | spin32 | spin33 | spin34 | spin35 | spin36 | spin37 | spin38 | spin39
  | spin40 | spin41 | spin42 | spin43 | spin44 | spin45 | spin46 | spin47
  | spin48 | spin49 | spin50 | spin51 | spin52 | spin53 | spin54 | spin55
  | spin56 | spin57 | spin58 | spin59 | spin60 | spin61 | spin62 | spin63
  | spin64 => true
  | c0 | o | Sc | Sl | W1
  | t1A | t1q | t1S | t2A | t2q | t2S | t3A | t3q => true
  | _ => false

def scFB : E → Bool
  | W3 | t1F | t2F | t3F => true
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
  isRelFence := fun e => scFB e = true
  isAcqFence := fun e => scFB e = true
  isScFence := fun e => scFB e = true
  sb := sbP
  rf := rfP
  mo := moP
  asw := aswP
  wval := wv
  rval := rv

instance (a b : E) : Decidable (X.sb a b) := inferInstanceAs (Decidable (sbP a b))
instance (e : E) : Decidable (X.isW e) := inferInstanceAs (Decidable (isWB e = true))
instance (e : E) : Decidable (X.isR e) := inferInstanceAs (Decidable (isRB e = true))
instance (e : E) : Decidable (X.isRel e) := inferInstanceAs (Decidable (relB e = true))
instance (e : E) : Decidable (X.isScFence e) := inferInstanceAs (Decidable (scFB e = true))

theorem rank_inj : ∀ a b : E, rank a = rank b → a = b := by
  intro a b; cases a <;> cases b <;> decide

theorem rf_rank : ∀ w r : E, rfP w r → rank w < rank r := by
  intro w r; cases r <;> cases w <;> decide

theorem asw_rank : ∀ a b : E, aswP a b → rank a < rank b := by
  intro a b; cases a <;> cases b <;> decide

theorem rf_wf_raw : ∀ w r : E, rfP w r →
    isWB w = true ∧ isRB r = true ∧ locOf w = locOf r ∧ locOf w ≠ none := by
  intro w r h
  cases r <;> simp [rfP, src] at h <;> (subst h; decide)

theorem rmw_mo_raw : ∀ w u : E, rfP w u → isWB u = true → moP w u := by
  intro w u h
  cases u <;> simp [rfP, src] at h <;> (subst h; decide)

set_option maxHeartbeats 400000 in
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
  scFence_relAcq := fun h => ⟨h, h⟩
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
abbrev actP (a e : E) : Prop := (a = c0 ∧ thr e = 1) ∨ (a = c ∧ thr e = 2 ∧ rank c ≤ rank e ∧ rank e ≤ rank Free)
abbrev tOfP (a x : E) : Prop := (a = c0 ∧ x = s0) ∨ (a = c ∧ x = s)

theorem st_first : ∀ e : E, isWB e = true → locOf e = some Word.st → e ≠ Is → sbP Is e := by
  intro e; cases e <;> decide

theorem st_writes : ∀ e : E, isWB e = true → locOf e = some Word.st →
    e = Is ∨ isClaimP e ∨ (∃ a, regOfP a e) ∨ (∃ a : E, a = c ∧ e = Free) := by
  intro e hw hl
  cases e <;> first
    | exact Or.inl rfl
    | exact Or.inr (Or.inl (by decide))
    | exact Or.inr (Or.inr (Or.inl ⟨c0, by decide⟩))
    | exact Or.inr (Or.inr (Or.inl ⟨c, by decide⟩))
    | exact Or.inr (Or.inr (Or.inr ⟨c, rfl, rfl⟩))
    | exact absurd hw (by decide)
    | exact absurd hl (by decide)

theorem claim_facts : ∀ a : E, isClaimP a → isWB a = true ∧ locOf a = some Word.st := by
  intro a; cases a <;> decide

theorem reg_facts : ∀ a b : E, regOfP a b →
    isClaimP a ∧ isWB b = true ∧ locOf b = some Word.st ∧ relB b = true ∧ rfP a b ∧
    ¬ isClaimP b := by
  rintro a b (⟨rfl, rfl⟩ | ⟨rfl, rfl⟩) <;> decide

theorem reg_owner_raw : ∀ a a' b : E, regOfP a b → regOfP a' b → a = a' := by
  intro a a' b h h'
  rcases h with ⟨rfl, rfl⟩ | ⟨rfl, rfl⟩ <;> rcases h' with ⟨rfl, h₁⟩ | ⟨rfl, h₁⟩ <;>
    first | rfl | exact absurd h₁ (by decide)

theorem act_facts : ∀ a e : E, actP a e → (e = a ∨ sbP a e) ∧ thr e = thr a := by
  rintro a e (⟨rfl, h⟩ | ⟨rfl, h, hr⟩) <;> cases e <;> simp_all [thr, sbP, rank]

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
  rintro a x (⟨rfl, rfl⟩ | ⟨rfl, rfl⟩) <;> decide

theorem t_before_reg_raw : ∀ a x b : E, tOfP a x → regOfP a b → sbP x b := by
  rintro a x b (⟨rfl, rfl⟩ | ⟨rfl, rfl⟩) (⟨h, rfl⟩ | ⟨h, rfl⟩) <;> first | contradiction | decide

theorem t_unique_raw : ∀ a x x' : E, tOfP a x → tOfP a x' → x = x' := by
  rintro a x x' (⟨rfl, rfl⟩ | ⟨rfl, rfl⟩) (⟨_, rfl⟩ | ⟨_, rfl⟩) <;> first | rfl | contradiction

def slot : SlotProtocol X liveness where
  st := Word.st
  tdis := Word.tdis
  init := Is
  tInit := It
  isFreeClaim := fun e => e = c0
  isSteal := fun e => e = c
  stealOf := fun a b => a = c0 ∧ b = c
  regOf := regOfP
  freeOf := fun a f => a = c ∧ f = Free
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
  free_w := fun h => by obtain ⟨rfl, rfl⟩ := h; decide
  free_loc := fun h => by obtain ⟨rfl, rfl⟩ := h; decide
  free_isRel := fun h => by obtain ⟨rfl, rfl⟩ := h; decide
  free_reads := fun h => by obtain ⟨rfl, rfl⟩ := h; exact ⟨g, Or.inr ⟨rfl, rfl⟩, rfl⟩
  free_not_claim := fun h => by obtain ⟨rfl, rfl⟩ := h; decide
  act_self := fun {a} h => by rcases h with rfl | rfl <;> decide
  act_sb := fun h => (act_facts _ _ h).1
  act_claim := fun h => by rcases h with ⟨rfl, _⟩ | ⟨rfl, _⟩ <;> decide
  act_thr := fun h => (act_facts _ _ h).2
  act_total := fun h h' => act_total_raw _ _ _ h h'
  act_before_free := by
    intro a f e hf he
    obtain ⟨rfl, rfl⟩ := hf
    rcases he with ⟨h, _⟩ | ⟨_, ht, hlo, hhi⟩
    · contradiction
    · by_cases h : e = Free
      · exact Or.inl h
      · exact Or.inr ⟨Nat.lt_of_le_of_ne hhi (fun heq => h (rank_inj _ _ heq)), Or.inl ht⟩
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
  pass_quiet := fun _ hu _ _ _ _ => hu.elim
  pass_exit := fun h hact hN hsb => by
    obtain ⟨rfl, rfl⟩ := h
    rcases hN with ⟨a, _, rfl⟩ | ⟨_, h⟩ | ⟨a, _, rfl⟩
    · exact False.elim (Nat.lt_irrefl _ hsb.1)
    · exact h.elim
    · exact False.elim ((by decide : ¬ actP c0 P6) hact)


/-- The rotator keeps its own registration in a distinct, unpinned slot
while temporarily owning the dead writer's slot between P4 and P3. -/
def rotatorSlot : SlotProtocol X liveness where
  st := Word.rotState
  tdis := Word.rotTime
  init := iRS
  tInit := iRT
  isFreeClaim := fun e => e = cR
  isSteal := fun _ => False
  stealOf := fun _ _ => False
  regOf := fun claim reg => claim = cR ∧ reg = gR
  freeOf := fun _ _ => False
  act := fun claim e => claim = cR ∧ thr e = 2 ∧ rank cR ≤ rank e
  tOf := fun claim time => claim = cR ∧ time = sR
  init_w := rfl
  init_loc := rfl
  init_first := by
    intro e hw hl hn
    apply hb_of_sb
    cases e <;> first
      | decide
      | exact absurd hw (by decide)
      | exact absurd hl (by decide)
      | exact False.elim (hn rfl)
  writes := by
    intro e hw hl
    cases e <;> first
      | exact Or.inl rfl
      | exact Or.inr (Or.inl (Or.inl rfl))
      | exact Or.inr (Or.inr (Or.inl ⟨cR, rfl, rfl⟩))
      | exact absurd hw (by decide)
      | exact absurd hl (by decide)
  claim_w := by rintro e (rfl | h); rfl; exact h.elim
  claim_loc := by rintro e (rfl | h); rfl; exact h.elim
  fc_isAcq := fun h => by subst h; rfl
  fc_reads := fun h => by subst h; exact ⟨iRS, rfl, Or.inl rfl⟩
  steal_isRel := fun h => h.elim
  steal_of := fun h => h.elim
  stealOf_claims := fun h => h.elim
  steal_ev := fun h => h.elim
  reg_claim := fun h => Or.inl h.1
  reg_w := by rintro claim reg ⟨rfl, rfl⟩; rfl
  reg_loc := by rintro claim reg ⟨rfl, rfl⟩; rfl
  reg_isRel := by rintro claim reg ⟨rfl, rfl⟩; rfl
  reg_reads := by rintro claim reg ⟨rfl, rfl⟩; rfl
  reg_not_claim := by rintro claim reg ⟨rfl, rfl⟩; decide
  reg_owner := fun h h' => h.1.trans h'.1.symm
  free_w := fun h => h.elim
  free_loc := fun h => h.elim
  free_isRel := fun h => h.elim
  free_reads := fun h => h.elim
  free_not_claim := fun h => h.elim
  act_self := by rintro claim (rfl | h); exact ⟨rfl, rfl, Nat.le_refl _⟩; exact h.elim
  act_sb := by
    rintro claim e ⟨rfl, ht, hr⟩
    by_cases h : e = cR
    · exact Or.inl h
    · exact Or.inr ⟨Nat.lt_of_le_of_ne hr (fun he => h (rank_inj _ _ he.symm)),
        Or.inl ht.symm⟩
  act_claim := fun h => Or.inl h.1
  act_thr := by rintro claim e ⟨rfl, ht, _⟩; exact ht
  act_total := by
    rintro claim e f ⟨_, he, _⟩ ⟨_, hf, _⟩
    rcases Nat.lt_trichotomy (rank e) (rank f) with h | h | h
    · exact Or.inr (Or.inl ⟨h, Or.inl (he.trans hf.symm)⟩)
    · exact Or.inl (rank_inj _ _ h)
    · exact Or.inr (Or.inr ⟨h, Or.inl (hf.trans he.symm)⟩)
  act_before_free := fun h => h.elim
  tInit_w := rfl
  tInit_loc := rfl
  tInit_first := by
    intro e hw hl hn
    apply hb_of_sb
    cases e <;> first
      | decide
      | exact absurd hw (by decide)
      | exact absurd hl (by decide)
      | exact False.elim (hn rfl)
  t_writes := by
    intro e hw hl
    cases e <;> first
      | exact Or.inl rfl
      | exact Or.inr ⟨cR, rfl, rfl⟩
      | exact absurd hw (by decide)
      | exact absurd hl (by decide)
  t_w := by rintro claim time ⟨rfl, rfl⟩; rfl
  t_loc := by rintro claim time ⟨rfl, rfl⟩; rfl
  t_act := by rintro claim time ⟨rfl, rfl⟩; decide
  t_before_reg := by rintro claim time reg ⟨rfl, rfl⟩ ⟨_, rfl⟩; decide
  t_unique := fun h h' => h.2.trans h'.2.symm
  t_of_reg := fun h => ⟨sR, h.1, rfl⟩
  t_val := by rintro claim time ⟨rfl, rfl⟩; exact Or.inr rfl

def rotatorPins : PinProtocol X rotatorSlot where
  pin := Word.rotPin
  pinInit := iRP
  pinVal := id
  isAnnounce := fun _ => False
  w2Of := fun _ _ => False
  unpinOf := fun _ _ => False
  clearOf := fun _ _ => False
  pinInit_w := rfl
  pinInit_loc := rfl
  pinInit_first := by
    intro e hw hl hn
    cases e <;> first
      | exact False.elim (hn rfl)
      | exact absurd hw (by decide)
      | exact absurd hl (by decide)
  pin_writes := by
    intro e hw hl
    cases e <;> first
      | exact Or.inl rfl
      | exact absurd hw (by decide)
      | exact absurd hl (by decide)
  ann_rmw := fun h => h.elim
  ann_not := fun h => h.elim
  init_not := by rintro (⟨_, h⟩ | ⟨_, h⟩ | ⟨_, h⟩) <;> exact h
  w2_act := fun h => h.elim
  w2_w := fun h => h.elim
  w2_loc := fun h => h.elim
  w2_isRel := fun h => h.elim
  unpin_w2 := fun h => h.elim
  unpin_w := fun h => h.elim
  unpin_loc := fun h => h.elim
  unpin_isRel := fun h => h.elim
  clear_act := fun h => h.elim
  clear_w := fun h => h.elim
  clear_loc := fun h => h.elim
  clear_isRel := fun h => h.elim
  att_act := fun h => h.elim
  pass_quiet := fun h => h.elim
  pass_exit := fun h => h.elim

abbrev isTenure (e : E) : Prop := e = t1A ∨ e = t2A ∨ e = t3A

/-- The event of each tenure, by its acquiring CAS. -/
def perTenure (x₁ x₂ x₃ : E) : E → E
  | t1A => x₁ | t2A => x₂ | t3A => x₃ | _ => iOwn

abbrev ofTenure (x₁ x₂ x₃ : E) (A e : E) : Prop := isTenure A ∧ e = perTenure x₁ x₂ x₃ A

abbrev relOfP := ofTenure t1O t2O t3O

theorem tenure_cases {P : E → Prop} (h₁ : P t1A) (h₂ : P t2A) (h₃ : P t3A) :
    ∀ A, isTenure A → P A := by
  rintro A (rfl | rfl | rfl)
  · exact h₁
  · exact h₂
  · exact h₃

theorem own_first : ∀ e : E, isWB e = true → locOf e = some Word.own → e ≠ iOwn →
    sbP iOwn e := by
  intro e; cases e <;> decide

theorem own_writes : ∀ e : E, isWB e = true → locOf e = some Word.own →
    e = iOwn ∨ isTenure e ∨ ∃ A, relOfP A e := by
  intro e hw hl
  cases e <;> first
    | exact Or.inl rfl
    | exact Or.inr (Or.inl (by decide))
    | exact Or.inr (Or.inr ⟨t1A, by decide⟩)
    | exact Or.inr (Or.inr ⟨t2A, by decide⟩)
    | exact Or.inr (Or.inr ⟨t3A, by decide⟩)
    | exact absurd hw (by decide)
    | exact absurd hl (by decide)

theorem acq_facts : ∀ A : E, isTenure A →
    isWB A = true ∧ locOf A = some Word.own ∧ acqB A = true ∧ relB A = true ∧
    (src A).isSome = true :=
  tenure_cases (by decide) (by decide) (by decide)

theorem rel_facts : ∀ A r : E, relOfP A r →
    isWB r = true ∧ locOf r = some Word.own ∧ relB r = true ∧ ¬ isTenure r ∧ sbP A r := by
  rintro A r ⟨hA, rfl⟩
  exact tenure_cases (P := fun A => isWB (perTenure t1O t2O t3O A) = true ∧
      locOf (perTenure t1O t2O t3O A) = some Word.own ∧
      relB (perTenure t1O t2O t3O A) = true ∧ ¬ isTenure (perTenure t1O t2O t3O A) ∧
      sbP A (perTenure t1O t2O t3O A))
    (by decide) (by decide) (by decide) A hA

/-- No tenure is taken over: every acquiring CAS reads a `NONE`. -/
theorem no_takeover : ∀ A T : E, isTenure A → isTenure T → ¬ rfP A T := by
  rintro A T (rfl | rfl | rfl) (rfl | rfl | rfl) <;> decide

def owner : OwnerProtocol X where
  own := Word.own
  init := iOwn
  isAcqCas := isTenure
  relOf := relOfP
  init_w := rfl
  init_loc := rfl
  init_first := fun hw hl hne => hb_of_sb (own_first _ hw hl hne)
  writes := fun hw hl => own_writes _ hw hl
  acq_w := fun h => (acq_facts _ h).1
  acq_loc := fun h => (acq_facts _ h).2.1
  acq_isAcq := fun h _ _ => (acq_facts _ h).2.2.1
  acq_isRel := fun h => (acq_facts _ h).2.2.2.1
  acq_reads := fun {A} h => by
    have := (acq_facts A h).2.2.2.2
    cases hs : src A with
    | none => rw [hs] at this; exact absurd this (by decide)
    | some w => exact ⟨w, hs⟩
  rel_acq := fun h => h.1
  rel_w := fun h => (rel_facts _ _ h).1
  rel_loc := fun h => (rel_facts _ _ h).2.1
  rel_isRel := fun h => (rel_facts _ _ h).2.2.1
  rel_not_acq := fun h => (rel_facts _ _ h).2.2.2.1
  rel_sb := fun h => (rel_facts _ _ h).2.2.2.2
  rel_unique := fun h h' => h.2.trans h'.2.symm
  takeover := fun hA hT hrf => absurd hrf (no_takeover _ _ hA hT)

/-- The two halves of `under`, in a form that can be decided. -/
abbrev underP (A e : E) : Prop :=
  (e = A ∨ sbP A e) ∧ (isTenure A → e = perTenure t1O t2O t3O A ∨ sbP e (perTenure t1O t2O t3O A))

theorem under_of {A e : E} (h : underP A e) : owner.under A e :=
  ⟨h.1, fun _ hr => hr.2 ▸ h.2 hr.1⟩

/-! ## The rotation protocol -/

abbrev r0OfP := ofTenure t1q t2q t3q
abbrev r5OfP := ofTenure t1P t2P t3P
abbrev sealOfP := ofTenure t1Z t2Z t3Z
abbrev r4OfP := ofTenure t1R t2R t3R

/-- Three arenas, used in turn: epoch `n` is served by `c (n % 3)`. -/
def ctlLocF (n : Nat) : Word :=
  if n % 3 = 0 then Word.c0 else if n % 3 = 1 then Word.c1 else Word.c2

theorem ctlLoc_cases (n : Nat) :
    ctlLocF n = Word.c0 ∨ ctlLocF n = Word.c1 ∨ ctlLocF n = Word.c2 := by
  unfold ctlLocF
  split
  · exact Or.inl rfl
  · split
    · exact Or.inr (Or.inl rfl)
    · exact Or.inr (Or.inr rfl)

theorem ctl_distinct_raw (n : Nat) :
    ctlLocF (n + 1) ≠ ctlLocF n ∧ ctlLocF (n + 2) ≠ ctlLocF n := by
  unfold ctlLocF
  have : n % 3 = 0 ∨ n % 3 = 1 ∨ n % 3 = 2 := by omega
  rcases this with h | h | h
  · have h₁ : (n + 1) % 3 = 1 := by omega
    have h₂ : (n + 2) % 3 = 2 := by omega
    simp [h, h₁, h₂]
  · have h₁ : (n + 1) % 3 = 2 := by omega
    have h₂ : (n + 2) % 3 = 0 := by omega
    simp [h, h₁, h₂]
  · have h₁ : (n + 1) % 3 = 0 := by omega
    have h₂ : (n + 2) % 3 = 1 := by omega
    simp [h, h₁, h₂]

abbrev isCtlInitP (e : E) : Prop := e = iC0 ∨ e = iC1 ∨ e = iC2
abbrev isB1P (_e : E) : Prop := False

theorem ep_first : ∀ e : E, isWB e = true → locOf e = some Word.ep → e ≠ iEp → sbP iEp e := by
  intro e; cases e <;> decide

theorem ep_writes_raw : ∀ e : E, isWB e = true → locOf e = some Word.ep →
    e = iEp ∨ ∃ A, r5OfP A e := by
  intro e hw hl
  cases e <;> first
    | exact Or.inl rfl
    | exact Or.inr ⟨t1A, by decide⟩
    | exact Or.inr ⟨t2A, by decide⟩
    | exact Or.inr ⟨t3A, by decide⟩
    | exact absurd hw (by decide)
    | exact absurd hl (by decide)

theorem ctl_writes_raw : ∀ (e : E) (n : Nat), isWB e = true → locOf e = some (ctlLocF n) →
    isCtlInitP e ∨ isB1P e ∨ (∃ A, sealOfP A e) ∨ (∃ A, r4OfP A e) := by
  intro e n hw hl
  cases e <;> first
    | exact Or.inl (by decide)
    | exact Or.inr (Or.inl (by decide))
    | exact Or.inr (Or.inr (Or.inl ⟨t1A, by decide⟩))
    | exact Or.inr (Or.inr (Or.inl ⟨t2A, by decide⟩))
    | exact Or.inr (Or.inr (Or.inl ⟨t3A, by decide⟩))
    | exact Or.inr (Or.inr (Or.inr ⟨t1A, by decide⟩))
    | exact Or.inr (Or.inr (Or.inr ⟨t2A, by decide⟩))
    | exact Or.inr (Or.inr (Or.inr ⟨t3A, by decide⟩))
    | exact absurd hw (by decide)
    | (rcases ctlLoc_cases n with h | h | h <;> rw [h] at hl <;> exact absurd hl (by decide))

theorem ctlInit_first_raw : ∀ i e : E, isCtlInitP i → isWB e = true → locOf e = locOf i →
    e ≠ i → sbP i e := by
  rintro i e (rfl | rfl | rfl) <;> cases e <;> decide

def rotation : RotationProtocol X where
  toOwnerProtocol := owner
  ep := Word.ep
  epInit := iEp
  r0Of := r0OfP
  r5Of := r5OfP
  ctlLoc := ctlLocF
  stamp := fun v => v % 100 / 2
  isSealed := fun v => v % 2 = 1
  isCtlInit := isCtlInitP
  isB1 := isB1P
  sealOf := sealOfP
  r4Of := r4OfP
  r1fOf := fun A q f =>
    (A = t1A ∧ q = t1q ∧ f = t1F) ∨
    (A = t2A ∧ q = t2q ∧ f = t2F) ∨
    (A = t3A ∧ q = t3q ∧ f = t3F)
  epInit_w := rfl
  epInit_loc := rfl
  epInit_val := rfl
  epInit_first := fun hw hl hne => hb_of_sb (ep_first _ hw hl hne)
  ep_writes := fun hw hl => ep_writes_raw _ hw hl
  r0_acq := fun h => h.1
  r0_under := fun {A q} h => by
    obtain ⟨hA, rfl⟩ := h
    rcases hA with rfl | rfl | rfl <;> exact under_of (by decide)
  r0_loc := fun {A q} h => by obtain ⟨hA, rfl⟩ := h; rcases hA with rfl | rfl | rfl <;> rfl
  r0_reads := fun {A q} h => by
    obtain ⟨hA, rfl⟩ := h
    rcases hA with rfl | rfl | rfl <;> exact ⟨_, rfl⟩
  r0_unique := fun h h' => h.2.trans h'.2.symm
  r5_acq := fun h => h.1
  r5_under := fun {A p} h => by
    obtain ⟨hA, rfl⟩ := h
    rcases hA with rfl | rfl | rfl <;> exact under_of (by decide)
  r5_w := fun {A p} h => by obtain ⟨hA, rfl⟩ := h; rcases hA with rfl | rfl | rfl <;> rfl
  r5_loc := fun {A p} h => by obtain ⟨hA, rfl⟩ := h; rcases hA with rfl | rfl | rfl <;> rfl
  r5_unique := fun h h' => h.2.trans h'.2.symm
  r5_r0 := fun {A p} h => by
    obtain ⟨hA, rfl⟩ := h
    exact ⟨_, ⟨hA, rfl⟩, by rcases hA with rfl | rfl | rfl <;> decide,
      by rcases hA with rfl | rfl | rfl <;> decide⟩
  ctl_writes := fun hw hl => ctl_writes_raw _ _ hw hl
  ctlInit_first := fun hi hw hl hne => hb_of_sb (ctlInit_first_raw _ _ hi hw hl hne)
  ctlInit_val := fun {i} hi => by rcases hi with rfl | rfl | rfl <;> decide
  b1_rmw := fun h => h.elim
  seal_acq := fun h => h.1
  seal_under := fun {A Z} h => by
    obtain ⟨hA, rfl⟩ := h
    rcases hA with rfl | rfl | rfl <;> exact under_of (by decide)
  seal_w := fun {A Z} h => by obtain ⟨hA, rfl⟩ := h; rcases hA with rfl | rfl | rfl <;> rfl
  seal_sealed := fun {A Z} h => by
    obtain ⟨hA, rfl⟩ := h; rcases hA with rfl | rfl | rfl <;> decide
  seal_r0 := fun {A Z} h => by
    obtain ⟨hA, rfl⟩ := h
    exact ⟨_, ⟨hA, rfl⟩, by rcases hA with rfl | rfl | rfl <;> decide,
      by rcases hA with rfl | rfl | rfl <;> decide⟩
  seal_before_r5 := fun {A Z p} h h' => by
    obtain ⟨hA, rfl⟩ := h
    obtain ⟨-, rfl⟩ := h'
    rcases hA with rfl | rfl | rfl <;> decide
  seal_before_r4 := fun {A Z w} h h' => by
    obtain ⟨hA, rfl⟩ := h
    obtain ⟨-, rfl⟩ := h'
    rcases hA with rfl | rfl | rfl <;> decide
  r4_acq := fun h => h.1
  r4_under := fun {A w} h => by
    obtain ⟨hA, rfl⟩ := h
    rcases hA with rfl | rfl | rfl <;> exact under_of (by decide)
  r4_w := fun {A w} h => by
    obtain ⟨hA, rfl⟩ := h
    rcases hA with rfl | rfl | rfl <;> decide
  r4_r0 := fun {A w} h => by
    obtain ⟨hA, rfl⟩ := h
    exact ⟨_, ⟨hA, rfl⟩, by rcases hA with rfl | rfl | rfl <;> decide⟩
  r4_unsealed := fun {A w} h => by
    obtain ⟨hA, rfl⟩ := h; rcases hA with rfl | rfl | rfl <;> decide
  loop_exit := fun {A p q} h _ => Or.inl ⟨_, h.1, rfl⟩
  r1f_facts := fun {A q f} h => by
    rcases h with ⟨rfl, rfl, rfl⟩ | ⟨rfl, rfl, rfl⟩ | ⟨rfl, rfl, rfl⟩
    · refine ⟨by decide, by decide, under_of (by decide), ?_, Or.inl ⟨t1Z, by decide⟩⟩
      intro Z hZ
      obtain ⟨_, rfl⟩ := hZ
      decide
    · refine ⟨by decide, by decide, under_of (by decide), ?_, Or.inl ⟨t2Z, by decide⟩⟩
      intro Z hZ
      obtain ⟨_, rfl⟩ := hZ
      decide
    · refine ⟨by decide, by decide, under_of (by decide), ?_, Or.inl ⟨t3Z, by decide⟩⟩
      intro Z hZ
      obtain ⟨_, rfl⟩ := hZ
      decide

instance (v : Nat) : Decidable (rotation.isSealed v) := inferInstanceAs (Decidable (v % 2 = 1))

/-! ## The cache protocol: distinct writer and rotator slots -/

def cache : CacheProtocol X where
  Lv := liveness
  ι := Bool
  slot := fun i => if i then rotatorSlot else slot
  pins := fun i => match i with
    | false => pins
    | true => rotatorPins
  R := rotation
  ctl_distinct := ctl_distinct_raw
  ctl_cycle := by
    intro n
    change ctlLocF (n + 3) = ctlLocF n
    simp only [ctlLocF, show (n + 3) % 3 = n % 3 by omega]
  r5_isRel := fun {A p} h => by
    obtain ⟨hA, rfl⟩ := h; rcases hA with rfl | rfl | rfl <;> rfl
  r5_r4 := fun {A p} h => by
    obtain ⟨hA, rfl⟩ := h
    exact ⟨_, ⟨hA, rfl⟩, by rcases hA with rfl | rfl | rfl <;> decide⟩
  r4_unique := fun h h' => h.2.trans h'.2.symm
  r4_loc := fun {A w q} h hq => by
    obtain ⟨hA, rfl⟩ := h
    obtain ⟨-, rfl⟩ := hq
    rcases hA with rfl | rfl | rfl <;> decide
  ctlInit_stamp := fun {i} hi => by rcases hi with rfl | rfl | rfl <;> decide
  seal_rmw := fun {A Z} h => by
    obtain ⟨hA, rfl⟩ := h
    rcases hA with rfl | rfl | rfl <;> exact ⟨_, rfl, by decide, by decide⟩
  scan := fun {B w4 qB} h4 hq => by
    obtain ⟨hB, rfl⟩ := h4
    obtain ⟨-, rfl⟩ := hq
    rcases hB with rfl | rfl | rfl
    · refine ⟨t1F, rfl, under_of (by decide), ?_⟩
      intro i
      cases i
      · exact ⟨t1S, W2, rfl, rfl, rfl, by decide, by decide, by decide⟩
      · exact ⟨t1OwnS, iRP, rfl, rfl, rfl, by decide, by decide, by decide⟩
    · refine ⟨t2F, rfl, under_of (by decide), ?_⟩
      intro i
      cases i
      · exact ⟨t2S, W2, rfl, rfl, rfl, by decide, by decide, by decide⟩
      · exact ⟨t2OwnS, iRP, rfl, rfl, rfl, by decide, by decide, by decide⟩
    · refine ⟨t3F, rfl, under_of (by decide), ?_⟩
      intro i
      cases i
      · exact ⟨Sl, P6, rfl, rfl, rfl, by decide, by decide, by decide⟩
      · exact ⟨t3OwnS, iRP, rfl, rfl, rfl, by decide, by decide, by decide⟩
  isAttempt := fun i w => i = false ∧ w = W2
  attEpoch := wv
  att_w2 := by rintro i w ⟨rfl, rfl⟩; exact ⟨c0, rfl, rfl⟩
  att_pin := by rintro i w ⟨rfl, rfl⟩; rfl
  att_w1 := fun {i w} h => by
    obtain ⟨rfl, rfl⟩ := h
    exact ⟨W1, iEp, rfl, rfl, rfl, by decide, rfl⟩
  att_b0 := fun {i w} h => by
    obtain ⟨rfl, rfl⟩ := h
    exact ⟨W3, B0, iC2, rfl, by decide, by decide, rfl, by decide, by decide, by decide⟩
  b1_after_b0 := fun _ _ h => h.elim

/-- Linux and non-Linux production waits first spin 64 times, then make
one monitored pause. Descheduling the rotator beyond the newly established
deadline makes the next pause exhaust its budget before any futex stage,
so this execution needs no R3A announcement. The 65 preceding R3 loads
are retained in the scan trace. -/
def priorEvents : List E := [
  spin0, spin1, spin2, spin3, spin4, spin5, spin6, spin7,
  spin8, spin9, spin10, spin11, spin12, spin13, spin14, spin15,
  spin16, spin17, spin18, spin19, spin20, spin21, spin22, spin23,
  spin24, spin25, spin26, spin27, spin28, spin29, spin30, spin31,
  spin32, spin33, spin34, spin35, spin36, spin37, spin38, spin39,
  spin40, spin41, spin42, spin43, spin44, spin45, spin46, spin47,
  spin48, spin49, spin50, spin51, spin52, spin53, spin54, spin55,
  spin56, spin57, spin58, spin59, spin60, spin61, spin62, spin63,
  spin64]

def priorLoad (r : E) (hr : r ∈ priorEvents) : ScanLoad cache t3F Cert where
  slot := false
  read := r
  source := W2
  rf := by cases r <;> first | rfl | simp [priorEvents] at hr
  loc := by cases r <;> first | rfl | simp [priorEvents] at hr
  acquire := by cases r <;> first | rfl | simp [priorEvents] at hr
  afterFence := by cases r <;> first | decide | simp [priorEvents] at hr
  beforeCert := by cases r <;> first | decide | simp [priorEvents] at hr

def priorLoads : List (ScanLoad cache t3F Cert) := priorEvents.attach.map fun r => priorLoad r.val r.property

def firstLoad : ScanLoad cache t3F Cert where
  slot := false
  read := Sc
  source := W2
  rf := rfl
  loc := rfl
  acquire := rfl
  afterFence := by decide
  beforeCert := by decide

def reload : ScanLoad cache t3F Cert where
  slot := false
  read := Sl
  source := P6
  rf := rfl
  loc := rfl
  acquire := rfl
  afterFence := by decide
  beforeCert := by decide

def ownLoad : ScanLoad cache t3F Cert where
  slot := true
  read := t3OwnS
  source := iRP
  rf := rfl
  loc := rfl
  acquire := rfl
  afterFence := by decide
  beforeCert := by decide

theorem prior_slots : ∀ l ∈ priorLoads, l.slot = false := by
  intro l h
  obtain ⟨r, _, rfl⟩ := List.mem_map.mp h
  rfl

def trace : RotationScanTrace cache t3F Cert 4 where
  loads := priorLoads ++ [firstLoad, reload, ownLoad]
  covered := fun i => by
    cases i
    · exact ⟨reload, by simp, rfl⟩
    · exact ⟨ownLoad, by simp, rfl⟩
  slotRuns := fun i => by
    cases i
    · let run : SlotScan 4 := (List.replicate 65 ()).foldr
        (fun _ rest => SlotScan.again 2 .pauseContinue rfl rest)
        (SlotScan.again 2 .reapSucceeded rfl (.done 0 rfl))
      refine ⟨run, priorLoads ++ [firstLoad, reload], ?_, rfl⟩
      intro l
      simp only [List.mem_append, List.mem_cons, List.not_mem_nil, or_false]
      constructor
      · intro h
        rcases h with h | rfl | rfl
        · exact ⟨Or.inl h, prior_slots l h⟩
        · exact ⟨Or.inr (Or.inl rfl), rfl⟩
        · exact ⟨Or.inr (Or.inr (Or.inl rfl)), rfl⟩
      · rintro ⟨h, hs⟩
        rcases h with h | rfl | rfl | rfl
        · exact Or.inl h
        · exact Or.inr (Or.inl rfl)
        · exact Or.inr (Or.inr rfl)
        · contradiction
    · refine ⟨.done 0 rfl, [ownLoad], ?_, rfl⟩
      intro l
      simp only [List.mem_append, List.mem_cons, List.not_mem_nil, or_false]
      constructor
      · intro h; subst l; exact ⟨Or.inr (Or.inr (Or.inr rfl)), rfl⟩
      · rintro ⟨h, hs⟩
        rcases h with h | rfl | rfl | rfl
        · have := prior_slots l h; rw [this] at hs; contradiction
        · contradiction
        · contradiction
        · rfl
  guard := rfl

def branches : ScanBranches pins where
  epoch := fun _ => 4
  isR3 := fun r => r ∈ priorEvents ∨ r = Sc ∨ r = Sl
  waitAfter := fun r a => r = Sc ∧ a = Wait
  reapAfter := fun r a => r = Sc ∧ a = c
  decoded_bound := by
    intro r h
    rcases h with h | rfl | rfl
    · cases r <;> first | decide | simp [priorEvents] at h
    · decide
    · decide
  wait_guard := by
    intro r a h; obtain ⟨rfl, rfl⟩ := h
    exact ⟨Or.inr (Or.inl rfl), by decide, by decide, by decide⟩
  reap_guard := by
    intro r a h; obtain ⟨rfl, rfl⟩ := h
    exact ⟨Or.inr (Or.inl rfl), by decide, by decide, by decide⟩

def ownBranches : ScanBranches rotatorPins where
  epoch := fun _ => 4
  isR3 := fun r => r = t3OwnS
  waitAfter := fun _ _ => False
  reapAfter := fun _ _ => False
  decoded_bound := by intro r h; subst r; decide
  wait_guard := fun h => h.elim
  reap_guard := fun h => h.elim

def tenureScan : TenureScan cache t3A t3q t3F Cert where
  r0 := ⟨Or.inr (Or.inr rfl), rfl⟩
  trace := trace
  branches := fun i => match i with
    | false => branches
    | true => ownBranches
  branch_epoch := by intro i r _; cases i <;> rfl
  all_r3 := by
    intro i r hr
    cases i
    · rcases hr with hr | rfl | rfl
      · refine ⟨priorLoad r hr, ?_, rfl, rfl⟩
        apply List.mem_append_left
        exact List.mem_map.mpr ⟨⟨r, hr⟩, List.mem_attach _ _, rfl⟩
      · exact ⟨firstLoad, by simp [trace], rfl, rfl⟩
      · exact ⟨reload, by simp [trace], rfl, rfl⟩
    · change r = t3OwnS at hr
      subst r
      exact ⟨ownLoad, by simp [trace], rfl, rfl⟩

def certifiedTenure : CertifiedTenure cache Cert where
  owner := t3A
  r0 := t3q
  fence := t3F
  scan := tenureScan
  sc := rfl
  under := under_of (by decide)
  beforeCert := by decide
  ownSeal := fun {Z} hZ => by obtain ⟨_, rfl⟩ := hZ; decide
  sealed := Or.inl ⟨t3Z, ⟨Or.inr (Or.inr rfl), rfl⟩⟩

def rdyLoc (n : Nat) : Word :=
  if n % 3 = 0 then Word.ready0 else if n % 3 = 1 then Word.ready1 else Word.ready2

theorem rdyLoc_cases (n : Nat) :
    rdyLoc n = Word.ready0 ∨ rdyLoc n = Word.ready1 ∨ rdyLoc n = Word.ready2 := by
  unfold rdyLoc
  split
  · exact Or.inl rfl
  · split
    · exact Or.inr (Or.inl rfl)
    · exact Or.inr (Or.inr rfl)

def readiness : RawReadinessProtocol X cache where
  rdyLoc := rdyLoc
  isRdyInit := fun e => e = iReady
  isCert := fun e => e = Cert
  certArg := fun _ => 4
  rdyInit_val := fun h => by subst h; rfl
  rdy_writes := by
    intro e n hw hl
    cases e <;> first
      | exact Or.inl rfl
      | exact Or.inr rfl
      | exact absurd hw (by decide)
      | (rcases rdyLoc_cases n with h | h | h <;> rw [h] at hl <;> exact absurd hl (by decide))
  cert_w := fun h => by subst h; rfl
  cert_loc := fun h => by subst h; rfl
  cert_rel := fun h => by subst h; rfl
  cert_rmw := fun h => by subst h; exact ⟨iReady, rfl, rfl⟩
  cert_source := fun h => by subst h; exact Or.inl certifiedTenure.raw_certificate

/-- The older-pin branch occurs in a consistent complete cache protocol,
with a nonempty store attempt, actual owner tenure and epoch recheck,
actual readiness RMW, full scan/reload trace, and following reuse reset.
It is an existence result, not a guarantee that wait or reaping succeeds. -/
theorem older_pin_full_cache_execution :
    X.Consistent ∧ cache.isAttempt false W2 ∧ cache.attEpoch W2 = 2 ∧
    cache.R.r0Of t3A t3q ∧ X.rval t3q = 4 ∧
    X.rf W2 Sc ∧ X.isAcq Sc ∧ pins.pinVal (X.rval Sc) = 2 ∧
    branches.waitAfter Sc Wait ∧ branches.reapAfter Sc c ∧
    liveness.dead vd 1 10 ∧ slot.stealOf c0 c ∧ pins.clearOf c P6 ∧
    X.sb Sc Wait ∧ X.sb Wait vd ∧ X.sb vd c ∧ X.sb c P6 ∧
    X.rf P6 Sl ∧ X.isAcq Sl ∧ X.rval Sl = 0 ∧
    readiness.isCert Cert ∧ readiness.certArg Cert = 4 ∧
    RotCert cache Cert 4 ∧ cache.R.r4Of t3A t3R ∧ X.sb Cert t3R := by
  exact ⟨consistent, ⟨rfl, rfl⟩, rfl, ⟨Or.inr (Or.inr rfl), rfl⟩, rfl,
    rfl, rfl, rfl, ⟨rfl, rfl⟩, ⟨rfl, rfl⟩, ⟨rfl, rfl, rfl⟩,
    ⟨rfl, rfl⟩, ⟨rfl, rfl⟩, by decide, by decide, by decide, by decide,
    rfl, rfl, rfl, rfl, rfl, raw_rotation_certificate certifiedTenure.raw_certificate,
    ⟨Or.inr (Or.inr rfl), rfl⟩, by decide⟩

/-- The same rotator acts under its own claim and its temporary stolen
claim, on distinct slots. It releases the stolen claim before reloading;
its own slot remains unpinned throughout. -/
theorem distinct_rotator_and_reaper_registrations :
    (cache.slot true).act cR c ∧ (cache.slot false).act c c ∧
    (cache.slot true).st ≠ (cache.slot false).st ∧
    (cache.pins true).pin ≠ (cache.pins false).pin ∧
    (cache.slot false).freeOf c Free ∧ X.sb Free Sl ∧
    X.rf iRP t3OwnS ∧ X.rval t3OwnS = 0 := by
  exact ⟨⟨rfl, rfl, by decide⟩, Or.inr ⟨rfl, rfl, Nat.le_refl _, by decide⟩,
    by decide, by decide, ⟨rfl, rfl⟩, by decide, rfl, rfl⟩

/-- The older attempt's last action is ordered before the reuse reset. -/
theorem older_attempt_before_reuse : X.hb B0 t3R :=
  cache.attempt_before_reset consistent (i := false) (w := W2) ⟨rfl, rfl⟩
    ⟨Or.inr (Or.inr rfl), rfl⟩ ⟨Or.inr (Or.inr rfl), rfl⟩ rfl
    ⟨Or.inr (by decide), fun _ h => h.elim⟩

end RotationReapCacheWitness
end Sgc
