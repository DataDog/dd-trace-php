import SgcProofs.Ranked
import SgcProofs.Recency

/-!
# A witness for the regression scenario

`regression` (`Recency.lean`) assumes that no epoch above 3 is ever
published. This file shows that hypothesis satisfiable together with every
other hypothesis of `regression`, by one consistent execution of 65 events
that follows the GenMC program `promotion_keeps_newer_stale_epoch_insert`:
setup inserts `K -> V1` at epoch 2; then writer `W` inserts `K -> V2`,
pinned to epoch 2, and publishes after rotator `R` has rotated from 2 to 3;
lookup `L` reads `V1` from epoch 2's arena; and a final lookup after the
joins returns `V2`.

Key `K` is 1, with a 1-byte key and an 8-byte value: each record takes
32 bytes (header, lengths, key word, value word). The record area holds
two records, and chunks are 8 bytes, so every B1 claims exactly its
record, as in the GenMC program (`reservation_chunk_size: 8`).

* Thread 0 initialises the owner word, the epoch (2), `c2`'s control word
  (`(2, unsealed)`; `c2` serves epoch 2, `c0` epoch 3, and `c1` is never
  written), the slot's state, start-time and pin words, and the index slots
  `idx` (of `c2`'s arena) and `idx3` (of `c0`'s).
* Thread 1 is participant `p1` of the GenMC program, which makes the setup
  insert, `W`'s insert and the final lookup. The program runs `W`'s insert
  on a spawned thread that the main thread joins before its final lookup,
  so `p1` is used sequentially; its spawn and join edges are folded into
  `sb`. It claims the slot (`aC`, `aT`, `aG`), resetting `chunk_epoch`
  (`aHe`). The setup insert loads epoch 2 (`aQ`), pins it (`aW`), fences
  (`aF`), loads `c2` unsealed (`aL`), reads its H0 epoch zero (`aLe`) and
  skips the cursor/end loads, then reserves bytes 0 to 31 (`aB`), stores
  the lengths, key, value (`V1`) and header words (`aRl`, `aRk`, `aRv`,
  `aR`), publishes with a CAS on `idx` (`aI`) and unpins (`aU`). `W`'s
  insert loads epoch 2 (`bQ`), pins it (`bW`), fences (`bF`), loads `c2`
  unsealed (`bL`) and reserves bytes 32 to 63 (`bB`).
* Thread 2 is `R`. It acquires the owner word (`tA`), rechecks epoch 2
  (`tq`), seals `c2` over `bB` (`tZ`), fences (`tF`), scans the pin and
  reads `W`'s pin of 2, which passes (`tS`: an epoch-2 pin does not block
  the rotation from 2), resets `c0` for epoch 3 (`tR`), publishes 3 (`tP`)
  and releases (`tO`). `W`'s pin keeps `R` from certifying epoch 2, so the
  execution has no certificate and no promotion.
* Thread 3 is `L`: it loads epoch 3 (`lV1`); its probe of `c0`'s arena,
  which misses, is not modelled. Its probe of the previous arena loads
  `idx` (`lJ`, the setup's entry), fences (`lJF`), loads the setup's header,
  lengths, key and value words (`lY`, `lYl`, `lYk`, `lYv`), fences (`lD`)
  and reloads epoch 3 (`lV2`), which passes: a stale hit of `V1` (`hitL`).
  Its readiness load is not modelled; it promotes nothing.
* Thread 1 then completes `W`'s insert: it stores its record at bytes 32 to
  63 (`bRl`, `bRk`, `bRv` with `V2`, `bR`), loads `idx` (`bJ`, the setup's
  entry), checks its key with a header and a lengths load of the setup's
  record (`bK`, `bKl`), replaces the entry with a store (`bS`) and unpins
  (`bU`). Its insert returns `Ok`.
* After the joins (`asw` from `tO` and `lV2`; the spawns are `asw` from
  `aU`), thread 1 looks `K` up: it loads epoch 3 (`fV1`), misses in `c0`'s
  arena (`fI1c` reads the zero fill of `idx3`), and hits `W`'s entry in the
  previous arena (`fJ`, `fJF`, `fY`, `fYl`, `fYk`, `fYv`, `fD`, `fV2`:
  `hitF`), returning `V2`.

Event names follow `CacheWitness.lean`: in a writer's events `Q` is W1, `W`
the pin W2, `F` the fence W3, `L` the precheck B0, `B` the B1, `R...` the
record stores W4 and W5, `I`/`S` the publication I2, `U` the unpin W7,
`C`/`G` P1/P2 and `He` the `chunk_epoch` store of H0; `bJ` is I0 and `bK`,
`bKl` the key check's D4 and D5. In a lookup, `V1` and `V2` are V1 and V2,
`J`/`I1c` the index load I1, `JF` I1F, `Y...` the record loads D4 and D5,
and `D` the fence D6.

Every relation agrees with one total order (`rank`), so the execution is
consistent by `Ranked`. At the end, `regression` is instantiated with `W`'s
insert as `I` and the final lookup as `L`: it does not miss, and its hit
has root `W`'s insert, not the setup's.
-/

namespace Sgc
namespace RegressionWitness

open Execution

inductive E
  | iOwn | iEp | iC2 | iSt | iT | iPin | iIdx | iIdx3
  | aC | aT | aHe | aG | aQ | aW | aF | aL | aLe | aB | aRl | aRk | aRv | aR | aI | aU
  | bQ | bW | bF | bL | bB
  | tA | tq | tZ | tF | tS | tR | tP | tO
  | lV1 | lJ | lJF | lY | lYl | lYk | lYv | lD | lV2
  | bRl | bRk | bRv | bR | bJ | bK | bKl | bS | bU
  | fV1 | fI1c | fJ | fJF | fY | fYl | fYk | fYv | fD | fV2
  deriving DecidableEq

inductive Word
  | own | ep | c0 | c1 | c2 | st | tdis | pin | ce | cc | cn | idx | idx3
  | r0 | r8 | r16 | r24 | r32 | r40 | r48 | r56 | rdy
  deriving DecidableEq

open E

def rank : E → Nat
  | iOwn => 0 | iEp => 1 | iC2 => 2 | iSt => 3 | iT => 4 | iPin => 5 | iIdx => 6 | iIdx3 => 7
  | aC => 8 | aT => 9 | aHe => 10 | aG => 11 | aQ => 12 | aW => 13 | aF => 14 | aL => 15
  | aLe => 16 | aB => 17 | aRl => 18 | aRk => 19 | aRv => 20 | aR => 21 | aI => 22 | aU => 23
  | bQ => 24 | bW => 25 | bF => 26 | bL => 27 | bB => 28
  | tA => 29 | tq => 30 | tZ => 31 | tF => 32 | tS => 33 | tR => 34 | tP => 35 | tO => 36
  | lV1 => 37 | lJ => 38 | lJF => 39 | lY => 40 | lYl => 41 | lYk => 42 | lYv => 43
  | lD => 44 | lV2 => 45
  | bRl => 46 | bRk => 47 | bRv => 48 | bR => 49 | bJ => 50 | bK => 51 | bKl => 52
  | bS => 53 | bU => 54
  | fV1 => 55 | fI1c => 56 | fJ => 57 | fJF => 58 | fY => 59 | fYl => 60 | fYk => 61
  | fYv => 62 | fD => 63 | fV2 => 64

/-- The inverse of `rank`. -/
def ofRank : Nat → E
  | 0 => iOwn | 1 => iEp | 2 => iC2 | 3 => iSt | 4 => iT | 5 => iPin | 6 => iIdx | 7 => iIdx3
  | 8 => aC | 9 => aT | 10 => aHe | 11 => aG | 12 => aQ | 13 => aW | 14 => aF | 15 => aL
  | 16 => aLe | 17 => aB | 18 => aRl | 19 => aRk | 20 => aRv | 21 => aR | 22 => aI | 23 => aU
  | 24 => bQ | 25 => bW | 26 => bF | 27 => bL | 28 => bB
  | 29 => tA | 30 => tq | 31 => tZ | 32 => tF | 33 => tS | 34 => tR | 35 => tP | 36 => tO
  | 37 => lV1 | 38 => lJ | 39 => lJF | 40 => lY | 41 => lYl | 42 => lYk | 43 => lYv
  | 44 => lD | 45 => lV2
  | 46 => bRl | 47 => bRk | 48 => bRv | 49 => bR | 50 => bJ | 51 => bK | 52 => bKl
  | 53 => bS | 54 => bU
  | 55 => fV1 | 56 => fI1c | 57 => fJ | 58 => fJF | 59 => fY | 60 => fYl | 61 => fYk
  | 62 => fYv | 63 => fD | _ => fV2

/-- Thread of an event; 0 is initialisation. -/
def thr : E → Nat
  | iOwn | iEp | iC2 | iSt | iT | iPin | iIdx | iIdx3 => 0
  | aC | aT | aHe | aG | aQ | aW | aF | aL | aLe | aB | aRl | aRk | aRv | aR | aI | aU => 1
  | bQ | bW | bF | bL | bB | bRl | bRk | bRv | bR | bJ | bK | bKl | bS | bU => 1
  | fV1 | fI1c | fJ | fJF | fY | fYl | fYk | fYv | fD | fV2 => 1
  | tA | tq | tZ | tF | tS | tR | tP | tO => 2
  | lV1 | lJ | lJF | lY | lYl | lYk | lYv | lD | lV2 => 3

def locOf : E → Option Word
  | iOwn | tA | tO => some Word.own
  | iEp | aQ | bQ | tq | tP | lV1 | lV2 | fV1 | fV2 => some Word.ep
  | iC2 | aL | aB | bL | bB | tZ => some Word.c2
  | tR => some Word.c0
  | iSt | aC | aG => some Word.st
  | iT | aT => some Word.tdis
  | iPin | aW | aU | bW | bU | tS => some Word.pin
  | aHe | aLe => some Word.ce
  | iIdx | aI | lJ | bJ | bS | fJ => some Word.idx
  | iIdx3 | fI1c => some Word.idx3
  | aR | lY | bK => some Word.r0
  | aRl | lYl | bKl => some Word.r8
  | aRk | lYk => some Word.r16
  | aRv | lYv => some Word.r24
  | bR | fY => some Word.r32
  | bRl | fYl => some Word.r40
  | bRk | fYk => some Word.r48
  | bRv | fYv => some Word.r56
  | aF | bF | tF | lJF | lD | fJF | fD => none

def isWB : E → Bool
  | iOwn | iEp | iC2 | iSt | iT | iPin | iIdx | iIdx3 => true
  | aC | aT | aHe | aG | aW | aB | aRl | aRk | aRv | aR | aI | aU => true
  | bW | bB | bRl | bRk | bRv | bR | bS | bU => true
  | tA | tZ | tR | tP | tO => true
  | _ => false

def isRB : E → Bool
  | aC | aG | aQ | aL | aLe | aB | aI => true
  | bQ | bL | bB | bJ | bK | bKl => true
  | tA | tq | tZ | tS => true
  | lV1 | lJ | lY | lYl | lYk | lYv | lV2 => true
  | fV1 | fI1c | fJ | fY | fYl | fYk | fYv | fV2 => true
  | _ => false

/-- The write each read takes its value from. -/
def src : E → Option E
  | aC => some iSt | aG => some aC | aQ => some iEp | aL => some iC2 | aB => some iC2
  | aLe => some aHe | aI => some iIdx
  | bQ => some iEp | bL => some aB | bB => some aB
  | tA => some iOwn | tq => some iEp | tZ => some bB | tS => some bW
  | lV1 => some tP | lJ => some aI | lY => some aR | lYl => some aRl | lYk => some aRk
  | lYv => some aRv | lV2 => some tP
  | bJ => some aI | bK => some aR | bKl => some aRl
  | fV1 => some tP | fI1c => some iIdx3 | fJ => some bS | fY => some bR | fYl => some bRl
  | fYk => some bRk | fYv => some bRv | fV2 => some tP
  | _ => none

/-- Values written. A control word is `2 * stamp + sealed`, plus 100 per
reserved byte; the start time is ten times the thread number; a pin holds
its epoch, or 0 when unpinned. An index entry is `stamp + 2^32 * (offset /
8)`, a record header `stamp + 2^32 * len` and a lengths word `key_len + 2^32
* value_len`. The key word holds the key 1; the value word holds 1 for
`V1` and 2 for `V2`. -/
def wv : E → Nat
  | iEp => 2 | tP => 3
  | iC2 => 4 | aB => 3204 | bB => 6404 | tZ => 6405 | tR => 6
  | aT => 10
  | aW => 2 | bW => 2
  | aI => 2 | bS => 17179869186
  | aRl => 34359738369 | bRl => 34359738369
  | aR => 103079215106 | bR => 103079215106
  | aRk => 1 | bRk => 1 | aRv => 1 | bRv => 2
  | _ => 0

def rv (e : E) : Nat :=
  match src e with
  | some w => wv w
  | none => 0

def relB : E → Bool
  | aG | aW | aI | aU | bW | bS | bU | tA | tP | tO => true
  | _ => false

def acqB : E → Bool
  | aC | aQ | bQ | bJ | tA | tq | tS => true
  | _ => false

/-- The `SeqCst` fences: W3 of each attempt and `R`'s R1F. -/
def scFB : E → Bool
  | aF | bF | tF => true
  | _ => false

/-- The lookups' acquire fences: I1F and D6. -/
def acqFB : E → Bool
  | lJF | lD | fJF | fD => true
  | _ => false

/-- Thread creation and join: the program spawns `R` and `L` after the
setup insert returned, and joins them before the final lookup. -/
def aswB (a b : E) : Bool :=
  (a == aU && (b == tA || b == lV1)) || (b == fV1 && (a == tO || a == lV2))

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
  isRelFence := fun e => scFB e = true
  isAcqFence := fun e => (scFB e || acqFB e) = true
  isScFence := fun e => scFB e = true
  sb := sbP
  rf := rfP
  mo := moP
  asw := fun a b => aswB a b = true
  wval := wv
  rval := rv

/-! The relations of `X`, decidable as their definitions are. -/

instance (e : E) : Decidable (X.isW e) := inferInstanceAs (Decidable (isWB e = true))
instance (e : E) : Decidable (X.isR e) := inferInstanceAs (Decidable (isRB e = true))
instance (e : E) : Decidable (X.isRel e) := inferInstanceAs (Decidable (relB e = true))
instance (e : E) : Decidable (X.isAcq e) := inferInstanceAs (Decidable (acqB e = true))
instance (e : E) : Decidable (X.isAcqFence e) :=
  inferInstanceAs (Decidable ((scFB e || acqFB e) = true))
instance (e : E) : Decidable (X.isScFence e) := inferInstanceAs (Decidable (scFB e = true))
instance (a b : E) : Decidable (X.sb a b) := inferInstanceAs (Decidable (sbP a b))
instance (a b : E) : Decidable (X.rf a b) := inferInstanceAs (Decidable (rfP a b))
instance (a b : E) : Decidable (X.mo a b) := inferInstanceAs (Decidable (moP a b))

/-! ## Consistency -/

theorem ofRank_rank : ∀ a : E, ofRank (rank a) = a := by
  intro a; cases a <;> rfl

theorem rank_inj (a b : E) (h : rank a = rank b) : a = b := by
  rw [← ofRank_rank a, ← ofRank_rank b, h]

theorem rf_rank : ∀ w r : E, rfP w r → rank w < rank r := by
  intro w r h
  cases r <;> simp [rfP, src] at h <;> (subst h; decide)

theorem rf_wf_raw : ∀ w r : E, rfP w r →
    isWB w = true ∧ isRB r = true ∧ locOf w = locOf r ∧ locOf w ≠ none := by
  intro w r h
  cases r <;> simp [rfP, src] at h <;> (subst h; decide)

theorem rmw_mo_raw : ∀ w u : E, rfP w u → isWB u = true → moP w u := by
  intro w u h
  cases u <;> simp [rfP, src] at h <;> (subst h; decide)

/-- A read takes the last write before it. -/
theorem rb_rank : ∀ r w w₀ : E, rfP w₀ r → moP w₀ w → rank r ≤ rank w := by
  intro r w w₀ h
  cases r <;> simp [rfP, src] at h <;> (subst h; cases w <;> decide)

theorem asw_rank : ∀ a b : E, aswB a b = true → rank a < rank b := by
  intro a b; cases a <;> cases b <;> decide

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
  scFence_relAcq := fun {f} (h : scFB f = true) =>
    ⟨h, show (scFB f || acqFB f) = true by rw [h]; rfl⟩
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

/-! ## Assumption A3: no verdict is ever given -/

def liveness : Liveness X where
  thrOf := thr
  pidOf := id
  startOf := fun θ => 10 * θ
  dead := fun _ _ _ => False
  sound := fun h => h.elim

/-! ## The slot protocol: one registration, never released -/

abbrev actP (c e : E) : Prop := c = aC ∧ thr e = 1

theorem st_first : ∀ e : E, isWB e = true → locOf e = some Word.st → e ≠ iSt → sbP iSt e := by
  intro e; cases e <;> decide

theorem act_sb_raw : ∀ c e : E, actP c e → e = c ∨ sbP c e := by
  rintro c e ⟨rfl, h⟩; revert h; cases e <;> decide

theorem t_first : ∀ e : E, isWB e = true → locOf e = some Word.tdis → e ≠ iT → sbP iT e := by
  intro e; cases e <;> decide

def slotP : SlotProtocol X liveness where
  st := Word.st
  tdis := Word.tdis
  init := iSt
  tInit := iT
  isFreeClaim := fun c => c = aC
  isSteal := fun _ => False
  stealOf := fun _ _ => False
  regOf := fun c g => c = aC ∧ g = aG
  freeOf := fun _ _ => False
  act := actP
  tOf := fun c s => c = aC ∧ s = aT
  init_w := rfl
  init_loc := rfl
  init_first := fun hw hl hne => hb_of_sb (st_first _ hw hl hne)
  writes := fun {e} hw hl => by
    cases e <;> first
      | exact Or.inl rfl
      | exact Or.inr (Or.inl (Or.inl rfl))
      | exact Or.inr (Or.inr (Or.inl ⟨aC, rfl, rfl⟩))
      | exact absurd hw (by decide)
      | exact absurd hl (by decide)
  claim_w := fun {c} h => by rcases h with rfl | h; rfl; exact h.elim
  claim_loc := fun {c} h => by rcases h with rfl | h; rfl; exact h.elim
  fc_isAcq := fun {c} h => by subst h; rfl
  fc_reads := fun {c} h => by subst h; exact ⟨iSt, rfl, Or.inl rfl⟩
  steal_isRel := fun h => h.elim
  steal_of := fun h => h.elim
  stealOf_claims := fun h => h.elim
  steal_ev := fun h => h.elim
  reg_claim := fun h => Or.inl h.1
  reg_w := fun {c g} h => by obtain ⟨rfl, rfl⟩ := h; rfl
  reg_loc := fun {c g} h => by obtain ⟨rfl, rfl⟩ := h; rfl
  reg_isRel := fun {c g} h => by obtain ⟨rfl, rfl⟩ := h; rfl
  reg_reads := fun {c g} h => by obtain ⟨rfl, rfl⟩ := h; rfl
  reg_not_claim := fun {c g} h => by
    obtain ⟨rfl, rfl⟩ := h
    rintro (h | h)
    · exact absurd h (by decide)
    · exact h
  reg_owner := fun h h' => h.1.trans h'.1.symm
  free_w := fun h => h.elim
  free_loc := fun h => h.elim
  free_isRel := fun h => h.elim
  free_reads := fun h => h.elim
  free_not_claim := fun h => h.elim
  act_self := fun {c} h => by rcases h with rfl | h; exact ⟨rfl, rfl⟩; exact h.elim
  act_sb := fun h => act_sb_raw _ _ h
  act_claim := fun h => Or.inl h.1
  act_thr := fun {c e} h => by obtain ⟨rfl, h⟩ := h; exact h
  act_total := fun {c e f} h h' => by
    obtain ⟨rfl, h⟩ := h
    exact thread_total (h.trans h'.2.symm)
  act_before_free := fun h => h.elim
  tInit_w := rfl
  tInit_loc := rfl
  tInit_first := fun hw hl hne => hb_of_sb (t_first _ hw hl hne)
  t_writes := fun {e} hw hl => by
    cases e <;> first
      | exact Or.inl rfl
      | exact Or.inr ⟨aC, rfl, rfl⟩
      | exact absurd hw (by decide)
      | exact absurd hl (by decide)
  t_w := fun {c s} h => by obtain ⟨rfl, rfl⟩ := h; rfl
  t_loc := fun {c s} h => by obtain ⟨rfl, rfl⟩ := h; rfl
  t_act := fun {c s} h => by obtain ⟨rfl, rfl⟩ := h; exact ⟨rfl, rfl⟩
  t_before_reg := fun {c s g} h h' => by
    obtain ⟨rfl, rfl⟩ := h
    obtain ⟨-, rfl⟩ := h'
    decide
  t_unique := fun h h' => h.2.trans h'.2.symm
  t_of_reg := fun h => ⟨aT, h.1, rfl⟩
  t_val := fun {c s} h => by obtain ⟨rfl, rfl⟩ := h; exact Or.inr rfl

/-! ## The pin protocol: two attempts, each ended by its unpin -/

abbrev isAttP (w : E) : Prop := w = aW ∨ w = bW

def unpinF : E → E
  | aW => aU | bW => bU | _ => iPin

theorem pin_first : ∀ e : E, isWB e = true → locOf e = some Word.pin → e ≠ iPin →
    sbP iPin e := by
  intro e; cases e <;> decide

theorem att_act_raw : ∀ w e : E, isAttP w → (e = w ∨ sbP w e) → actP aC e := by
  rintro w e (rfl | rfl) (rfl | h)
  · exact ⟨rfl, rfl⟩
  · exact ⟨rfl, (h.2.resolve_right (by decide)).symm⟩
  · exact ⟨rfl, rfl⟩
  · exact ⟨rfl, (h.2.resolve_right (by decide)).symm⟩

/-- Both attempts are the registration's: W2 and W7 are made by `aC`. -/
def pinsP : PinProtocol X slotP where
  pin := Word.pin
  pinInit := iPin
  pinVal := id
  isAnnounce := fun _ => False
  w2Of := fun c w => isAttP w ∧ c = aC
  unpinOf := fun w u => isAttP w ∧ u = unpinF w
  clearOf := fun _ _ => False
  pinInit_w := rfl
  pinInit_loc := rfl
  pinInit_first := fun hw hl hne => hb_of_sb (pin_first _ hw hl hne)
  pin_writes := fun {e} hw hl => by
    cases e <;> first
      | exact Or.inl rfl
      | exact Or.inr (Or.inr (Or.inl ⟨aC, Or.inl rfl, rfl⟩))
      | exact Or.inr (Or.inr (Or.inl ⟨aC, Or.inr rfl, rfl⟩))
      | exact Or.inr (Or.inr (Or.inr (Or.inl ⟨aW, Or.inl rfl, rfl⟩)))
      | exact Or.inr (Or.inr (Or.inr (Or.inl ⟨bW, Or.inr rfl, rfl⟩)))
      | exact absurd hw (by decide)
      | exact absurd hl (by decide)
  ann_rmw := fun h => h.elim
  ann_not := fun h => h.elim
  init_not := by
    rintro (⟨c, hw, _⟩ | ⟨w, hw, h⟩ | ⟨_, h⟩)
    · exact absurd hw (by decide)
    · rcases hw with rfl | rfl <;> exact absurd h (by decide)
    · exact h
  w2_act := fun {c w} h => by obtain ⟨hw, rfl⟩ := h; exact att_act_raw w w hw (Or.inl rfl)
  w2_w := fun {c w} h => by obtain ⟨hw, -⟩ := h; rcases hw with rfl | rfl <;> rfl
  w2_loc := fun {c w} h => by obtain ⟨hw, -⟩ := h; rcases hw with rfl | rfl <;> rfl
  w2_isRel := fun {c w} h => by obtain ⟨hw, -⟩ := h; rcases hw with rfl | rfl <;> rfl
  unpin_w2 := fun {w u} h => by
    obtain ⟨hw, rfl⟩ := h
    exact ⟨aC, ⟨hw, rfl⟩, att_act_raw w _ hw (Or.inr (by rcases hw with rfl | rfl <;> decide))⟩
  unpin_w := fun {w u} h => by obtain ⟨hw, rfl⟩ := h; rcases hw with rfl | rfl <;> rfl
  unpin_loc := fun {w u} h => by obtain ⟨hw, rfl⟩ := h; rcases hw with rfl | rfl <;> rfl
  unpin_isRel := fun {w u} h => by obtain ⟨hw, rfl⟩ := h; rcases hw with rfl | rfl <;> rfl
  clear_act := fun h => h.elim
  clear_w := fun h => h.elim
  clear_loc := fun h => h.elim
  clear_isRel := fun h => h.elim
  att_act := fun {c w e} h he _ => by obtain ⟨hw, rfl⟩ := h; exact att_act_raw w e hw he
  pass_quiet := fun {c w u N} h hu _ hN hsb hNu => by
    obtain ⟨hw, rfl⟩ := h
    obtain ⟨-, rfl⟩ := hu
    rcases hN with ⟨_, hN', -⟩ | ⟨w', hw', rfl⟩ | ⟨_, h⟩
    · rcases hN' with rfl | rfl <;> rcases hw with rfl | rfl <;>
        first | exact absurd hsb (by decide) | exact absurd hNu (by decide)
    · rcases hw' with rfl | rfl <;> rcases hw with rfl | rfl <;>
        first | exact absurd hsb (by decide) | exact absurd hNu (by decide)
    · exact h.elim
  pass_exit := fun {c w N} h _ _ _ => by
    obtain ⟨hw, rfl⟩ := h
    exact ⟨_, ⟨hw, rfl⟩, att_act_raw w _ hw (Or.inr (by rcases hw with rfl | rfl <;> decide))⟩

theorem inAtt_mk {w e : E} (h₁ : e = w ∨ sbP w e)
    (h₂ : e = unpinF w ∨ sbP e (unpinF w)) : pinsP.inAttempt w e :=
  ⟨h₁, fun _ hu => hu.2 ▸ h₂⟩

theorem inAtt_dec {w e : E} (hw : isAttP w) (h : pinsP.inAttempt w e) :
    (e = w ∨ sbP w e) ∧ (e = unpinF w ∨ sbP e (unpinF w)) :=
  ⟨h.1, h.2 _ ⟨hw, rfl⟩⟩

/-! ## The owner protocol: one tenure, released -/

theorem own_first : ∀ e : E, isWB e = true → locOf e = some Word.own → e ≠ iOwn →
    sbP iOwn e := by
  intro e; cases e <;> decide

def owner : OwnerProtocol X where
  own := Word.own
  init := iOwn
  isAcqCas := fun A => A = tA
  relOf := fun A r => A = tA ∧ r = tO
  init_w := rfl
  init_loc := rfl
  init_first := fun hw hl hne => hb_of_sb (own_first _ hw hl hne)
  writes := fun {e} hw hl => by
    cases e <;> first
      | exact Or.inl rfl
      | exact Or.inr (Or.inl rfl)
      | exact Or.inr (Or.inr ⟨tA, rfl, rfl⟩)
      | exact absurd hw (by decide)
      | exact absurd hl (by decide)
  acq_w := fun h => by subst h; rfl
  acq_loc := fun h => by subst h; rfl
  acq_isAcq := fun h _ _ => by subst h; rfl
  acq_isRel := fun h => by subst h; rfl
  acq_reads := fun h => by subst h; exact ⟨iOwn, rfl⟩
  rel_acq := fun h => h.1
  rel_w := fun {A r} h => by obtain ⟨rfl, rfl⟩ := h; rfl
  rel_loc := fun {A r} h => by obtain ⟨rfl, rfl⟩ := h; rfl
  rel_isRel := fun {A r} h => by obtain ⟨rfl, rfl⟩ := h; rfl
  rel_not_acq := fun {A r} h => by obtain ⟨rfl, rfl⟩ := h; decide
  rel_sb := fun {A r} h => by obtain ⟨rfl, rfl⟩ := h; decide
  rel_unique := fun h h' => h.2.trans h'.2.symm
  takeover := fun {A T} hA hT hrf => by subst hA; subst hT; exact absurd hrf (by decide)

/-- `under`, in a form that can be decided. -/
abbrev underP (A e : E) : Prop := (e = A ∨ sbP A e) ∧ (A = tA → e = tO ∨ sbP e tO)

theorem under_of {A e : E} (h : underP A e) : owner.under A e :=
  ⟨h.1, fun _ hr => hr.2 ▸ h.2 hr.1⟩

/-! ## The rotation protocol: `R` rotates from 2 to 3 -/

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

abbrev isB1P (e : E) : Prop := e = aB ∨ e = bB

theorem ep_first : ∀ e : E, isWB e = true → locOf e = some Word.ep → e ≠ iEp → sbP iEp e := by
  intro e; cases e <;> decide

theorem ctl_writes_raw : ∀ (e : E) (n : Nat), isWB e = true → locOf e = some (ctlLocF n) →
    e = iC2 ∨ isB1P e ∨ (∃ A, A = tA ∧ e = tZ) ∨ (∃ A, A = tA ∧ e = tR) := by
  intro e n hw hl
  cases e <;> first
    | exact Or.inl rfl
    | exact Or.inr (Or.inl (by decide))
    | exact Or.inr (Or.inr (Or.inl ⟨tA, rfl, rfl⟩))
    | exact Or.inr (Or.inr (Or.inr ⟨tA, rfl, rfl⟩))
    | exact absurd hw (by decide)
    | (rcases ctlLoc_cases n with h | h | h <;> rw [h] at hl <;> exact absurd hl (by decide))

theorem ctlInit_first_raw : ∀ e : E, isWB e = true → locOf e = locOf iC2 → e ≠ iC2 →
    sbP iC2 e := by
  intro e; cases e <;> decide

def rotation : RotationProtocol X where
  toOwnerProtocol := owner
  ep := Word.ep
  epInit := iEp
  r0Of := fun A q => A = tA ∧ q = tq
  r5Of := fun A p => A = tA ∧ p = tP
  ctlLoc := ctlLocF
  stamp := fun v => v % 100 / 2
  isSealed := fun v => v % 2 = 1
  isCtlInit := fun e => e = iC2
  isB1 := isB1P
  sealOf := fun A Z => A = tA ∧ Z = tZ
  r4Of := fun A w => A = tA ∧ w = tR
  r1fOf := fun A q f => A = tA ∧ q = tq ∧ f = tF
  epInit_w := rfl
  epInit_loc := rfl
  epInit_val := rfl
  epInit_first := fun hw hl hne => hb_of_sb (ep_first _ hw hl hne)
  ep_writes := fun {e} hw hl => by
    cases e <;> first
      | exact Or.inl rfl
      | exact Or.inr ⟨tA, rfl, rfl⟩
      | exact absurd hw (by decide)
      | exact absurd hl (by decide)
  r0_acq := fun h => h.1
  r0_under := fun {A q} h => by obtain ⟨rfl, rfl⟩ := h; exact under_of (by decide)
  r0_loc := fun {A q} h => by obtain ⟨rfl, rfl⟩ := h; rfl
  r0_reads := fun {A q} h => by obtain ⟨rfl, rfl⟩ := h; exact ⟨iEp, rfl⟩
  r0_unique := fun h h' => h.2.trans h'.2.symm
  r5_acq := fun h => h.1
  r5_under := fun {A p} h => by obtain ⟨rfl, rfl⟩ := h; exact under_of (by decide)
  r5_w := fun {A p} h => by obtain ⟨rfl, rfl⟩ := h; rfl
  r5_loc := fun {A p} h => by obtain ⟨rfl, rfl⟩ := h; rfl
  r5_unique := fun h h' => h.2.trans h'.2.symm
  r5_r0 := fun {A p} h => by obtain ⟨rfl, rfl⟩ := h; exact ⟨tq, ⟨rfl, rfl⟩, by decide, by decide⟩
  ctl_writes := fun hw hl => ctl_writes_raw _ _ hw hl
  ctlInit_first := fun {i e} hi hw hl hne => by
    subst hi; exact hb_of_sb (ctlInit_first_raw _ hw hl hne)
  ctlInit_val := fun {i} hi => by subst hi; decide
  b1_rmw := fun {e} h => by
    rcases h with rfl | rfl
    · exact ⟨iC2, rfl, by decide, by decide⟩
    · exact ⟨aB, rfl, by decide, by decide⟩
  seal_acq := fun h => h.1
  seal_under := fun {A Z} h => by obtain ⟨rfl, rfl⟩ := h; exact under_of (by decide)
  seal_w := fun {A Z} h => by obtain ⟨rfl, rfl⟩ := h; rfl
  seal_sealed := fun {A Z} h => by obtain ⟨rfl, rfl⟩ := h; decide
  seal_r0 := fun {A Z} h => by
    obtain ⟨rfl, rfl⟩ := h; exact ⟨tq, ⟨rfl, rfl⟩, by decide, by decide⟩
  seal_before_r5 := fun {A Z p} h h' => by
    obtain ⟨rfl, rfl⟩ := h; obtain ⟨-, rfl⟩ := h'; decide
  seal_before_r4 := fun {A Z w} h h' => by
    obtain ⟨rfl, rfl⟩ := h; obtain ⟨-, rfl⟩ := h'; decide
  r4_acq := fun h => h.1
  r4_under := fun {A w} h => by obtain ⟨rfl, rfl⟩ := h; exact under_of (by decide)
  r4_w := fun {A w} h => by obtain ⟨rfl, rfl⟩ := h; decide
  r4_r0 := fun {A w} h => by obtain ⟨rfl, rfl⟩ := h; exact ⟨tq, ⟨rfl, rfl⟩, by decide⟩
  r4_unsealed := fun {A w} h => by obtain ⟨rfl, rfl⟩ := h; decide
  loop_exit := fun {A p q} h _ => Or.inl ⟨tZ, h.1, rfl⟩
  r1f_facts := fun {A q f} h => by
    obtain ⟨rfl, rfl, rfl⟩ := h
    refine ⟨⟨rfl, rfl⟩, by decide, under_of (by decide), ?_, Or.inl ⟨tZ, rfl, rfl⟩⟩
    intro Z hZ
    obtain ⟨-, rfl⟩ := hZ
    decide

instance (v : Nat) : Decidable (rotation.isSealed v) := inferInstanceAs (Decidable (v % 2 = 1))

/-! ## The cache protocol: one slot -/

def cache : CacheProtocol X where
  Lv := liveness
  ι := Unit
  slot := fun _ => slotP
  pins := fun _ => pinsP
  R := rotation
  ctl_distinct := ctl_distinct_raw
  ctl_cycle := by
    intro n
    change ctlLocF (n + 3) = ctlLocF n
    simp only [ctlLocF, show (n + 3) % 3 = n % 3 by omega]
  r5_isRel := fun {A p} h => by obtain ⟨rfl, rfl⟩ := h; rfl
  r5_r4 := fun {A p} h => by obtain ⟨rfl, rfl⟩ := h; exact ⟨tR, ⟨rfl, rfl⟩, by decide⟩
  r4_unique := fun h h' => h.2.trans h'.2.symm
  r4_loc := fun {A w q} h hq => by obtain ⟨rfl, rfl⟩ := h; obtain ⟨-, rfl⟩ := hq; decide
  ctlInit_stamp := fun {i} hi => by subst hi; decide
  seal_rmw := fun {A Z} h => by obtain ⟨rfl, rfl⟩ := h; exact ⟨bB, rfl, by decide, by decide⟩
  scan := fun {B w4 qB} h4 hq => by
    obtain ⟨rfl, rfl⟩ := h4
    obtain ⟨-, rfl⟩ := hq
    -- `R`'s scan reads `W`'s pin of 2, which does not block the rotation from 2.
    exact ⟨tF, rfl, under_of (by decide),
      fun _ => ⟨tS, bW, rfl, rfl, rfl, by decide, by decide, by decide⟩⟩
  isAttempt := fun _ w => isAttP w
  attEpoch := wv
  att_w2 := fun h => ⟨aC, h, rfl⟩
  att_pin := fun _ => rfl
  att_w1 := fun {i w} h => by
    rcases h with rfl | rfl
    · exact ⟨aQ, iEp, rfl, rfl, rfl, by decide, rfl⟩
    · exact ⟨bQ, iEp, rfl, rfl, rfl, by decide, rfl⟩
  att_b0 := fun {i w} h => by
    rcases h with rfl | rfl
    · exact ⟨aF, aL, iC2, rfl, by decide, by decide, rfl, by decide, by decide, by decide⟩
    · exact ⟨bF, bL, aB, rfl, by decide, by decide, rfl, by decide, by decide, by decide⟩
  b1_after_b0 := fun {i w b} hw hb hb1 => by
    have hd := inAtt_dec hw hb
    rcases hw with rfl | rfl <;> rcases hb1 with rfl | rfl <;>
      first
        | exact absurd hd (by decide)
        | exact ⟨rfl, by decide, aL, iC2, by decide, rfl, by decide, by decide⟩
        | exact ⟨rfl, by decide, bL, aB, by decide, rfl, by decide, by decide⟩

/-! ## The arena -/

abbrev isRecordP (l : Word) : Prop :=
  l = Word.r0 ∨ l = Word.r8 ∨ l = Word.r16 ∨ l = Word.r24 ∨ l = Word.r32 ∨ l = Word.r40 ∨
    l = Word.r48 ∨ l = Word.r56

abbrev isArenaP (l : Word) : Prop := isRecordP l ∨ l = Word.idx ∨ l = Word.idx3
abbrev isArenaInitP (e : E) : Prop := e = iIdx ∨ e = iIdx3

/-- The arena of an arena word, named by its control word: the record words
and `idx` belong to `c2`'s arena (epoch 2), `idx3` to `c0`'s (epoch 3). -/
def arenaW : Word → Word
  | Word.idx3 => Word.c0
  | _ => Word.c2

theorem arenaInit_first_raw : ∀ i x : E, isArenaInitP i → isWB x = true →
    locOf x = locOf i → x ≠ i → sbP i x := by
  rintro i x (rfl | rfl) <;> cases x <;> decide

/-- An attempt writes only the arena words of its own epoch's arena. -/
theorem att_arena : ∀ (w x : E) (l : Word), isAttP w →
    (x = w ∨ sbP w x) ∧ (x = unpinF w ∨ sbP x (unpinF w)) → isWB x = true →
    locOf x = some l → isArenaP l → arenaW l = ctlLocF (wv w) := by
  intro w x l hw hd hW hl ha
  rcases ha with (rfl | rfl | rfl | rfl | rfl | rfl | rfl | rfl) | rfl | rfl <;>
    rcases hw with rfl | rfl <;>
    first
      | decide
      | (revert hd hW hl; cases x <;> decide)

def arena : ArenaProtocol X cache where
  isArena := isArenaP
  isArenaInit := isArenaInitP
  arenaInit_first := fun hi hw hl hne => hb_of_sb (arenaInit_first_raw _ _ hi hw hl hne)
  arenaInit_not_att := fun {x i w} hx hw ha => by
    have hd := inAtt_dec hw ha
    rcases hx with rfl | rfl <;> rcases hw with rfl | rfl <;> exact absurd hd (by decide)
  aw_owner := fun {x l} hw hl ha => by
    rcases ha with (rfl | rfl | rfl | rfl | rfl | rfl | rfl | rfl) | rfl | rfl <;> cases x <;>
      first
      | exact Or.inl (by decide)
      | exact Or.inr ⟨(), aW, Or.inl rfl, inAtt_mk (by decide) (by decide)⟩
      | exact Or.inr ⟨(), bW, Or.inr rfl, inAtt_mk (by decide) (by decide)⟩
      | exact absurd hw (by decide)
      | exact absurd hl (by decide)
  owner_epoch := fun {x i w j w'} hw _ hw' _ => by
    rcases hw with rfl | rfl <;> rcases hw' with rfl | rfl <;> rfl
  same_arena := fun {x x' l i w j w'} _ _ _ _ _ hw _ hw' _ => by
    rcases hw with rfl | rfl <;> rcases hw' with rfl | rfl <;> exact Or.inl rfl
  aw_after_fence := fun {x l i w} hW hl ha hw hx => by
    have hd := inAtt_dec hw hx
    rcases hw with rfl | rfl
    · refine ⟨aQ, iEp, aF, rfl, rfl, rfl, by decide, rfl, ?_⟩
      rcases ha with (rfl | rfl | rfl | rfl | rfl | rfl | rfl | rfl) | rfl | rfl <;> cases x <;>
        first
        | decide
        | exact absurd hl (by decide)
        | exact absurd hW (by decide)
        | exact absurd hd (by decide)
    · refine ⟨bQ, iEp, bF, rfl, rfl, rfl, by decide, rfl, ?_⟩
      rcases ha with (rfl | rfl | rfl | rfl | rfl | rfl | rfl | rfl) | rfl | rfl <;> cases x <;>
        first
        | decide
        | exact absurd hl (by decide)
        | exact absurd hW (by decide)
        | exact absurd hd (by decide)

/-! ## The reservations -/

instance (v : Nat) : Decidable (cache.R.isSealed v) := inferInstanceAs (Decidable (v % 2 = 1))

/-- The byte offset of each record word in `c2`'s record area. -/
def recOffF : Word → Nat
  | Word.r8 => 8 | Word.r16 => 16 | Word.r24 => 24 | Word.r32 => 32 | Word.r40 => 40
  | Word.r48 => 48 | Word.r56 => 56
  | _ => 0

theorem rec_ext_raw : ∀ l l' : Word, isRecordP l → isRecordP l' → arenaW l = arenaW l' →
    recOffF l = recOffF l' → l = l' := by
  intro l l' hl hl'
  rcases hl with rfl | rfl | rfl | rfl | rfl | rfl | rfl | rfl <;>
    rcases hl' with rfl | rfl | rfl | rfl | rfl | rfl | rfl | rfl <;> decide

/-- A record area of 64 bytes and chunks of 8: each 32-byte record is
claimed exactly at B1, the setup's at offset 0 (`aB`), `W`'s at offset 32
(`bB`). No chunk is kept or cut, so only the registration's `chunk_epoch`
reset (`aHe`) is modelled. -/
def reserve : ReserveProtocol X cache where
  cap := 64
  chunk := 8
  maxClaim := 32
  bump := fun v => v / 100
  need := fun _ => 32
  claim := fun _ => 32
  slots := [()]
  slots_nodup := List.nodup_cons.mpr ⟨List.not_mem_nil, List.nodup_nil⟩
  slots_all := fun _ => List.mem_singleton.mpr rfl
  lens := fun _ => 34359738369
  need_lens := fun _ => by decide
  need_le := fun _ => by decide
  chunk_le := by decide
  a5 := by decide
  att_unique := fun {i j w w' x} hw hw' hx hx' => by
    cases i; cases j
    have hd := inAtt_dec hw hx
    have hd' := inAtt_dec hw' hx'
    refine ⟨rfl, ?_⟩
    rcases hw with rfl | rfl <;> rcases hw' with rfl | rfl <;>
      first | rfl | (revert hd hd'; cases x <;> decide)
  att_slot := fun _ _ => rfl
  ctlInit_bump := fun {x} h => by subst h; decide
  r4_bump := fun {A x} h => by obtain ⟨rfl, rfl⟩ := h; decide
  seal_bump := fun {A Z} h => by obtain ⟨rfl, rfl⟩ := h; exact ⟨bB, rfl, by decide⟩
  b1_bump := fun {b} h => by rcases h with rfl | rfl <;> decide
  b1_att := fun {b} h => by
    rcases h with rfl | rfl
    · exact ⟨(), aW, Or.inl rfl, inAtt_mk (by decide) (by decide)⟩
    · exact ⟨(), bW, Or.inr rfl, inAtt_mk (by decide) (by decide)⟩
  b1_unique := fun {i w b b'} hw hb hb' hB hB' => by
    have hd := inAtt_dec hw hb
    have hd' := inAtt_dec hw hb'
    rcases hw with rfl | rfl <;> rcases hB with rfl | rfl <;> rcases hB' with rfl | rfl <;>
      first | rfl | exact absurd hd (by decide) | exact absurd hd' (by decide)
  b1_b0 := fun {i w b} hw hb hB => by
    have hd := inAtt_dec hw hb
    rcases hw with rfl | rfl <;> rcases hB with rfl | rfl <;>
      first
        | exact absurd hd (by decide)
        | exact ⟨aL, iC2, by decide, by decide, rfl, by decide, by decide, by decide,
            by decide⟩
        | exact ⟨bL, aB, by decide, by decide, rfl, by decide, by decide, by decide,
            by decide⟩
  ceLoc := fun _ => Word.ce
  ccLoc := fun _ => Word.cc
  cnLoc := fun _ => Word.cn
  isChunkInit := fun _ => False
  chunkInit_w := fun h => h.elim
  chunkInit_val := fun h => h.elim
  chunkInit_first := fun h => h.elim
  resetOf := fun _ c x => c = aC ∧ x = aHe
  reset_act := fun {i c x} h => by obtain ⟨rfl, rfl⟩ := h; exact ⟨rfl, rfl⟩
  reset_val := fun {i c x} h => by obtain ⟨rfl, rfl⟩ := h; rfl
  reset_before := fun {i c x w} h h2 => by
    obtain ⟨-, rfl⟩ := h
    obtain ⟨hw, -⟩ := h2
    rcases hw with rfl | rfl <;> decide
  reset_ce := fun {i c w} h2 => by
    obtain ⟨-, rfl⟩ := h2
    exact ⟨aHe, ⟨rfl, rfl⟩, rfl, rfl⟩
  chunkOf := fun _ _ _ _ _ _ => False
  chunk_b1 := fun h => h.elim
  chunk_after_b1 := fun h => h.elim
  chunk_after_b1_c := fun h => h.elim
  chunk_after_b1_n := fun h => h.elim
  chunk_ev := fun h => h.elim
  chunk_unique := fun h => h.elim
  useOf := fun _ _ _ _ _ _ => False
  use_ev := fun h => h.elim
  use_no_b1 := fun h => h.elim
  use_unique := fun h => h.elim
  ce_writes := fun {i x} hw hl => by
    cases x <;> first
      | exact Or.inr (Or.inl ⟨aC, rfl, rfl⟩)
      | exact absurd hw (by decide)
      | exact absurd hl (by decide)
  cc_writes := fun {i x} hw hl => by
    cases x <;> first
      | exact absurd hw (by decide)
      | exact absurd hl (by decide)
  cn_writes := fun {i x} hw hl => by
    cases x <;> first
      | exact absurd hw (by decide)
      | exact absurd hl (by decide)
  isRecord := isRecordP
  recOff := recOffF
  recArena := arenaW
  rec_ext := fun {l l'} h h' ha ho => rec_ext_raw l l' h h' ha ho
  rec_arena := fun {i w x l} hw hx hW hl hr => by
    cases i
    exact att_arena w x l hw (inAtt_dec hw hx) hW hl (Or.inl hr)
  rec_in_res := fun {i w x l} hw hx hW hl hr => by
    cases i
    have hd := inAtt_dec hw hx
    rcases hw with rfl | rfl
    · refine ⟨0, Or.inl ⟨aB, inAtt_mk (by decide) (by decide), Or.inl rfl, by decide,
        by decide⟩, ?_⟩
      rcases hr with rfl | rfl | rfl | rfl | rfl | rfl | rfl | rfl <;>
        first | decide | (revert hd hW hl; cases x <;> decide)
    · refine ⟨32, Or.inl ⟨bB, inAtt_mk (by decide) (by decide), Or.inr rfl, by decide,
        by decide⟩, ?_⟩
      rcases hr with rfl | rfl | rfl | rfl | rfl | rfl | rfl | rfl <;>
        first | decide | (revert hd hW hl; cases x <;> decide)
  rec_once := fun {i w x x' l} hw hx hx' hW hW' hl hl' hr => by
    cases i
    have hd := inAtt_dec hw hx
    have hd' := inAtt_dec hw hx'
    rcases hr with rfl | rfl | rfl | rfl | rfl | rfl | rfl | rfl <;>
      rcases hw with rfl | rfl <;> cases x <;>
      first
      | exact absurd hl (by decide)
      | exact absurd hW (by decide)
      | exact absurd hd (by decide)
      | (cases x' <;> first
          | rfl
          | exact absurd hl' (by decide)
          | exact absurd hW' (by decide)
          | exact absurd hd' (by decide))

/-! ## The index -/

/-- Every epoch load returns at most 3. -/
theorem ep_small : ∀ e : E, X.loc e = some Word.ep → X.rval e ≤ 3 := by
  intro e; cases e <;> decide

/-- The words of a 32-byte record at offset `off`. -/
theorem cover4 {o off : Nat} (h₁ : off ≤ o) (h₂ : o + 8 ≤ off + 32) (h₃ : o % 8 = 0)
    (h₄ : off % 8 = 0) : o = off ∨ o = off + 8 ∨ o = off + 16 ∨ o = off + 24 := by omega

/-- The setup publishes with a CAS over the zero fill of `idx`; `W` finds
its key there, with a header and a lengths load (`bK`, `bKl`) of the setup's
record, and replaces the entry with a store. Key 1's probe sequence starts
at `idx`. -/
def index : IndexProtocol X arena reserve where
  isPubCas := fun e => e = aI
  isPubStore := fun e => e = bS
  pub_kinds_disjoint := fun h h' => by subst h; cases h'
  pubKey := fun _ => 1
  pub_rel := fun {x} h => by rcases h with rfl | rfl <;> rfl
  isIndex := fun l => l = Word.idx ∨ l = Word.idx3
  index_arena := fun {l} h => by
    rcases h with rfl | rfl
    · exact Or.inr (Or.inl rfl)
    · exact Or.inr (Or.inr rfl)
  record_arena := fun {l} h => Or.inl h
  arenaOf := arenaW
  pub_slot := fun {x i w l} hxk hw hx hl => by
    have hd := inAtt_dec hw hx
    rcases hxk with rfl | rfl <;> cases Option.some.inj hl <;> rcases hw with rfl | rfl <;>
      first
        | exact ⟨Or.inl rfl, by decide⟩
        | exact absurd hd (by decide)
  entryOff := fun v => v / wordLimit * 8
  entryOff_aligned := fun v => Nat.mul_mod_left _ _
  zero_off := rfl
  idx_writes := fun {x l} hW hl hi => by
    rcases hi with rfl | rfl <;> cases x <;> first
      | exact Or.inl ⟨Or.inl rfl, rfl⟩
      | exact Or.inl ⟨Or.inr rfl, rfl⟩
      | exact Or.inr ⟨Or.inl rfl, (), aW, Or.inl rfl, inAtt_mk (by decide) (by decide)⟩
      | exact Or.inr ⟨Or.inr rfl, (), bW, Or.inr rfl, inAtt_mk (by decide) (by decide)⟩
      | exact absurd hW (by decide)
      | exact absurd hl (by decide)
  pub_off := fun {x i w} hxk hw hx => by
    cases i
    have hd := inAtt_dec hw hx
    rcases hxk with rfl | rfl <;> rcases hw with rfl | rfl <;>
      first
        | exact absurd hd (by decide)
        | exact Or.inl ⟨aB, inAtt_mk (by decide) (by decide), Or.inl rfl,
            ⟨by decide, by decide⟩, by decide⟩
        | exact Or.inl ⟨bB, inAtt_mk (by decide) (by decide), Or.inr rfl,
            ⟨by decide, by decide⟩, by decide⟩
  pub_stamp := fun {x i w} hxk hw hx => by
    have hd := inAtt_dec hw hx
    rcases hxk with rfl | rfl <;> rcases hw with rfl | rfl <;>
      first | decide | exact absurd hd (by decide)
  pub_record := fun {x i w} hxk hw hx o h₁ h₂ h₃ => by
    cases i
    have hd := inAtt_dec hw hx
    rcases hxk with rfl | rfl <;> rcases hw with rfl | rfl <;>
      first | exact absurd hd (by decide) | skip
    · -- The setup's record, at offset 0.
      rcases cover4 (off := 0) h₁ h₂ h₃ rfl with rfl | rfl | rfl | rfl
      · exact ⟨aR, Word.r0, inAtt_mk (by decide) (by decide), rfl, rfl,
          Or.inl rfl, rfl, rfl, by decide, by decide⟩
      · exact ⟨aRl, Word.r8, inAtt_mk (by decide) (by decide), rfl, rfl,
          Or.inr (Or.inl rfl), rfl, rfl, by decide, by decide⟩
      · exact ⟨aRk, Word.r16, inAtt_mk (by decide) (by decide), rfl, rfl,
          Or.inr (Or.inr (Or.inl rfl)), rfl, rfl, by decide, by decide⟩
      · exact ⟨aRv, Word.r24, inAtt_mk (by decide) (by decide), rfl, rfl,
          Or.inr (Or.inr (Or.inr (Or.inl rfl))), rfl, rfl, by decide, by decide⟩
    · -- `W`'s record, at offset 32.
      rcases cover4 (off := 32) h₁ h₂ h₃ rfl with rfl | rfl | rfl | rfl
      · exact ⟨bR, Word.r32, inAtt_mk (by decide) (by decide), rfl, rfl,
          Or.inr (Or.inr (Or.inr (Or.inr (Or.inl rfl)))), rfl, rfl, by decide, by decide⟩
      · exact ⟨bRl, Word.r40, inAtt_mk (by decide) (by decide), rfl, rfl,
          Or.inr (Or.inr (Or.inr (Or.inr (Or.inr (Or.inl rfl))))), rfl, rfl, by decide,
          by decide⟩
      · exact ⟨bRk, Word.r48, inAtt_mk (by decide) (by decide), rfl, rfl,
          Or.inr (Or.inr (Or.inr (Or.inr (Or.inr (Or.inr (Or.inl rfl)))))), rfl, rfl,
          by decide, by decide⟩
      · exact ⟨bRv, Word.r56, inAtt_mk (by decide) (by decide), rfl, rfl,
          Or.inr (Or.inr (Or.inr (Or.inr (Or.inr (Or.inr (Or.inr rfl)))))), rfl, rfl,
          by decide, by decide⟩
  cap_ge := by decide
  cap_aligned := by decide
  entry_life := fun {s v Y x l} _ hv _ _ _ _ => by
    have := ep_small v hv
    exact ⟨fun _ => by unfold wordLimit; omega, fun _ _ _ _ => by unfold wordLimit; omega⟩
  cas_stamp := fun {x i w} hx hw hxw => by
    have hd := inAtt_dec hw hxw
    subst hx
    rcases hw with rfl | rfl
    · decide
    · exact absurd hd (by decide)
  cas_reads := fun {x} h => by subst h; exact ⟨iIdx, rfl⟩
  store_src := fun {x i w l} hx hw hxw hl => by
    subst hx
    have hd := inAtt_dec hw hxw
    cases Option.some.inj hl
    rcases hw with rfl | rfl
    · exact absurd hd (by decide)
    · refine ⟨bJ, aI, rfl, rfl, by decide, inAtt_mk (by decide) (by decide), rfl, by decide,
        fun Y => Y = bK ∨ Y = bKl, fun Y hY => ?_, fun Y hY => ?_, fun _ _ _ _ _ => rfl⟩
      · rcases hY with rfl | rfl
        · exact ⟨Word.r0, rfl, Or.inl rfl, rfl, Or.inl (by decide)⟩
        · exact ⟨Word.r8, rfl, Or.inr (Or.inl rfl), rfl, Or.inr (Or.inl (by decide))⟩
      · rcases hY with rfl | rfl
        · exact ⟨by decide, inAtt_mk (by decide) (by decide)⟩
        · exact ⟨by decide, inAtt_mk (by decide) (by decide)⟩
  dist := fun _ _ => 0
  dist_inj := fun {k l l'} h h' ha _ => by
    rcases h with rfl | rfl <;> rcases h' with rfl | rfl <;>
      first | rfl | exact absurd ha (by decide)
  pub_skip := fun {x i w l l'} _ _ _ _ _ _ hd => absurd hd (Nat.lt_irrefl 0)

/-! ## The lookups' hits -/

/-- `L`'s stale hit: V1 read 3, and the probe of the previous arena read the
setup's entry and its header, lengths, key and value (`V1`) words. -/
def hitL : Hit index where
  v1 := lV1
  s1 := tP
  v1_rf := rfl
  v1_loc := rfl
  e := 2
  e_def := Or.inr rfl
  i1 := lJ
  slot := Word.idx
  x2 := aI
  i1_loc := rfl
  slot_index := Or.inl rfl
  i1_rf := rfl
  v1_i1 := by decide
  i1_stamp := by decide
  i1f := lJF
  d6 := lD
  v2 := lV2
  s2 := tP
  i1_i1f := by decide
  i1f_acq := rfl
  i1_d6 := by decide
  d6_acq := rfl
  d6_v2 := by decide
  v2_rf := rfl
  v2_loc := rfl
  v2_pass := by decide
  loads := fun Y => Y = lY ∨ Y = lYl ∨ Y = lYk ∨ Y = lYv
  loads_rec := fun Y hY => by
    rcases hY with rfl | rfl | rfl | rfl
    · exact ⟨Word.r0, rfl, Or.inl rfl, rfl, Or.inl (by decide)⟩
    · exact ⟨Word.r8, rfl, Or.inr (Or.inl rfl), rfl, Or.inr (Or.inl (by decide))⟩
    · exact ⟨Word.r16, rfl, Or.inr (Or.inr (Or.inl rfl)), rfl, Or.inr (Or.inr
        ⟨lYl, Word.r8, Or.inr (Or.inl rfl), ⟨aRl, rfl⟩, rfl, Or.inr (Or.inl rfl), rfl,
          by decide, Or.inl ⟨0, rfl, by decide, by decide⟩⟩)⟩
    · exact ⟨Word.r24, rfl, Or.inr (Or.inr (Or.inr (Or.inl rfl))), rfl, Or.inr (Or.inr
        ⟨lYl, Word.r8, Or.inr (Or.inl rfl), ⟨aRl, rfl⟩, rfl, Or.inr (Or.inl rfl), rfl,
          by decide, Or.inr ⟨0, rfl, by decide, by decide⟩⟩)⟩
  loads_sb := fun Y hY => by
    rcases hY with rfl | rfl | rfl | rfl <;> exact ⟨by decide, by decide⟩
  life := by decide

/-- The final lookup's hit: V1 read 3, and the probe of the previous arena
read `W`'s entry and its header, lengths, key and value (`V2`) words. -/
def hitF : Hit index where
  v1 := fV1
  s1 := tP
  v1_rf := rfl
  v1_loc := rfl
  e := 2
  e_def := Or.inr rfl
  i1 := fJ
  slot := Word.idx
  x2 := bS
  i1_loc := rfl
  slot_index := Or.inl rfl
  i1_rf := rfl
  v1_i1 := by decide
  i1_stamp := by decide
  i1f := fJF
  d6 := fD
  v2 := fV2
  s2 := tP
  i1_i1f := by decide
  i1f_acq := rfl
  i1_d6 := by decide
  d6_acq := rfl
  d6_v2 := by decide
  v2_rf := rfl
  v2_loc := rfl
  v2_pass := by decide
  loads := fun Y => Y = fY ∨ Y = fYl ∨ Y = fYk ∨ Y = fYv
  loads_rec := fun Y hY => by
    rcases hY with rfl | rfl | rfl | rfl
    · exact ⟨Word.r32, rfl, Or.inr (Or.inr (Or.inr (Or.inr (Or.inl rfl)))), rfl,
        Or.inl (by decide)⟩
    · exact ⟨Word.r40, rfl, Or.inr (Or.inr (Or.inr (Or.inr (Or.inr (Or.inl rfl))))), rfl,
        Or.inr (Or.inl (by decide))⟩
    · exact ⟨Word.r48, rfl, Or.inr (Or.inr (Or.inr (Or.inr (Or.inr (Or.inr (Or.inl rfl)))))),
        rfl, Or.inr (Or.inr
        ⟨fYl, Word.r40, Or.inr (Or.inl rfl), ⟨bRl, rfl⟩, rfl,
          Or.inr (Or.inr (Or.inr (Or.inr (Or.inr (Or.inl rfl))))), rfl,
          by decide, Or.inl ⟨0, rfl, by decide, by decide⟩⟩)⟩
    · exact ⟨Word.r56, rfl, Or.inr (Or.inr (Or.inr (Or.inr (Or.inr (Or.inr (Or.inr rfl)))))),
        rfl, Or.inr (Or.inr
        ⟨fYl, Word.r40, Or.inr (Or.inl rfl), ⟨bRl, rfl⟩, rfl,
          Or.inr (Or.inr (Or.inr (Or.inr (Or.inr (Or.inl rfl))))), rfl,
          by decide, Or.inr ⟨0, rfl, by decide, by decide⟩⟩)⟩
  loads_sb := fun Y hY => by
    rcases hY with rfl | rfl | rfl | rfl <;> exact ⟨by decide, by decide⟩
  life := by decide

/-! ## Values, readiness and promotion: no promotion is made -/

/-- Both inserts store key 1: the setup `V1` (value 1), `W` `V2` (value 2). -/
def value : ValueProtocol X index where
  attKey := fun _ => 1
  attVal := fun w => if w = aW then 1 else 2
  inserted := fun k v => k = 1 ∧ (v = 1 ∨ v = 2)
  isPromo := fun _ => False
  src := fun _ => lJ
  ins_val := fun {i w} hw _ => by rcases hw with rfl | rfl <;> decide
  pub_key := fun _ _ _ => rfl
  promo_src := fun _ hp => hp.elim

/-- No readiness word is written: `R`'s scan saw `W`'s pin of 2, so it does
not certify 2, and no lookup rechecks. The readiness word `rdy` has no
event. -/
def readiness : ReadinessProtocol X cache where
  rdyLoc := fun _ => Word.rdy
  isRdyInit := fun _ => False
  isCert := fun _ => False
  certArg := fun _ => 0
  rdyInit_val := fun h => h.elim
  rdy_writes := fun {e n} _ hl => by cases e <;> exact absurd hl (by decide)
  cert_w := fun h => h.elim
  cert_loc := fun h => h.elim
  cert_rel := fun h => h.elim
  cert_rmw := fun h => h.elim
  cert_src := fun h => h.elim

theorem promotion : PromotionProtocol X value readiness where
  promo_ready := fun _ hp => hp.elim
  promo_cas := fun _ hp => hp.elim

/-! ## Insert operations and the final lookup -/

/-- The `insert()` calls: the setup's and `W`'s. -/
inductive InsOp
  | js | jw
  deriving DecidableEq

open InsOp

def insCallF : InsOp → E
  | js => aQ | jw => bQ

def insRetF : InsOp → E
  | js => aU | jw => bU

def insAttF : InsOp → E
  | js => aW | jw => bW

/-- Each insert makes one attempt, is called at its W1 and returns `Ok` at
its unpin: the setup after its CAS `aI`, `W` after its store `bS`. -/
def ops : InsertOps X value where
  Op := InsOp
  insKey := fun _ => 1
  insCall := insCallF
  insRet := fun J r => r = insRetF J
  insOk := fun J r => r = insRetF J
  ok_ret := id
  insOf := fun w J => w = insAttF J
  insOf_np := fun _ hp => hp
  ins_of_attempt := fun {i w} hw _ => by
    rcases hw with rfl | rfl
    · exact ⟨js, rfl⟩
    · exact ⟨jw, rfl⟩
  insOf_unique := fun {w J J'} h h' => by
    subst h
    cases J <;> cases J' <;> first | rfl | exact absurd h' (by decide)
  ins_key := fun _ => rfl
  att_w1 := fun {i w J} _ h => by
    subst h
    cases J
    · exact ⟨aQ, iEp, rfl, rfl, rfl, Or.inl rfl, by decide, fun r hr => hr ▸ by decide⟩
    · exact ⟨bQ, iEp, rfl, rfl, rfl, Or.inl rfl, by decide, fun r hr => hr ▸ by decide⟩
  att_ret := fun {i w J a r} hw h ha hr => by
    subst h
    subst hr
    cases J
    · exact (inAtt_dec hw ha).2
    · exact (inAtt_dec hw ha).2
  ok_pub := fun {J r} _ => by
    cases J
    · exact ⟨(), aW, aI, Or.inl rfl, rfl, inAtt_mk (by decide) (by decide), Or.inl rfl, rfl,
        Word.idx, rfl⟩
    · exact ⟨(), bW, bS, Or.inr rfl, rfl, inAtt_mk (by decide) (by decide), Or.inr rfl, rfl,
        Word.idx, rfl⟩
  insErr := fun _ _ => False
  err_ret := fun h => h.elim
  insCorrupt := fun _ _ => False
  corrupt_err := fun h => h.elim
  err_nopub := fun h => h.elim

/-- The final lookup's probe of `c0`'s arena for key 1 in epoch 3: its home
I1 reads the zero fill of `idx3`, whose stamp is not 3, so it misses. -/
def curP : ProbeRun index where
  e := 3
  key := 1
  i1 := fun Y => Y = fI1c
  i1_slot := fun {Y} h => by subst h; exact ⟨Word.idx3, rfl, Or.inr rfl, rfl⟩
  i1_reads := fun {Y} h => by subst h; exact ⟨iIdx3, rfl⟩
  recs := fun _ _ => False
  recs_addr := fun {Y} _ _ hZ => hZ.elim
  recs_fence := fun h => h.elim
  missed := True
  miss_check := fun _ s hs har hch => by
    rcases hs with rfl | rfl
    · exact absurd har (by decide)
    · obtain ⟨x, j, w, hrf, -, -, hxk, -⟩ := (hch.2 fI1c rfl rfl).1
      cases Option.some.inj hrf
      rcases hxk with h | h
      · exact absurd (show iIdx3 = aI from h) (by decide)
      · exact absurd (show iIdx3 = bS from h) (by decide)

/-- The probe's record loads, as `hitF`'s. -/
def prevRecs (Y Z : E) : Prop := Y = fJ ∧ hitF.loads Z

/-- The final lookup's probe of `c2`'s arena for key 1 in epoch 2 (`hitF`). -/
def prevP : ProbeRun index where
  e := 2
  key := 1
  i1 := fun Y => Y = fJ
  i1_slot := fun {Y} h => by subst h; exact ⟨Word.idx, rfl, Or.inl rfl, rfl⟩
  i1_reads := fun {Y} h => by subst h; exact ⟨bS, rfl⟩
  recs := prevRecs
  recs_addr := fun {Y} h Z hZ => by
    subst h
    obtain ⟨-, hZ⟩ := hZ
    rcases hZ with rfl | rfl | rfl | rfl
    · exact ⟨Word.r32, rfl, Or.inr (Or.inr (Or.inr (Or.inr (Or.inl rfl)))), rfl,
        Or.inl (by decide)⟩
    · exact ⟨Word.r40, rfl, Or.inr (Or.inr (Or.inr (Or.inr (Or.inr (Or.inl rfl))))), rfl,
        Or.inr (Or.inl (by decide))⟩
    · exact ⟨Word.r48, rfl, Or.inr (Or.inr (Or.inr (Or.inr (Or.inr (Or.inr (Or.inl rfl)))))),
        rfl, Or.inr (Or.inr
        ⟨fYl, Word.r40, ⟨rfl, Or.inr (Or.inl rfl)⟩, ⟨bRl, rfl⟩, rfl,
          Or.inr (Or.inr (Or.inr (Or.inr (Or.inr (Or.inl rfl))))), rfl,
          by decide, Or.inl ⟨0, rfl, by decide, by decide⟩⟩)⟩
    · exact ⟨Word.r56, rfl, Or.inr (Or.inr (Or.inr (Or.inr (Or.inr (Or.inr (Or.inr rfl)))))),
        rfl, Or.inr (Or.inr
        ⟨fYl, Word.r40, ⟨rfl, Or.inr (Or.inl rfl)⟩, ⟨bRl, rfl⟩, rfl,
          Or.inr (Or.inr (Or.inr (Or.inr (Or.inr (Or.inl rfl))))), rfl,
          by decide, Or.inr ⟨0, rfl, by decide, by decide⟩⟩)⟩
  recs_fence := fun {Y Z} h => by
    obtain ⟨rfl, hZ⟩ := h
    rcases hZ with rfl | rfl | rfl | rfl <;> exact ⟨fJF, by decide, rfl, by decide⟩
  missed := False
  miss_check := fun h => h.elim

/-- The final lookup of key 1, after the joins: it loads epoch 3, misses in
`c0`'s arena and returns `W`'s record from the previous one (`hitF`). -/
def lookF : LookupRun value where
  key := 1
  v1 := fV1
  s1 := tP
  v1_rf := rfl
  v1_loc := rfl
  probe := fun P => P = curP ∨ P = prevP
  probe_key := fun {P} h => by rcases h with rfl | rfl <;> rfl
  probe_after := fun {P Y} h hY => by
    rcases h with rfl | rfl
    · subst hY; decide
    · subst hY; decide
  cur := curP
  cur_probe := Or.inl rfl
  cur_e := rfl
  hit := fun H => H = hitF
  hit_v1 := fun {H} h => by subst h; rfl
  hit_key := fun _ _ _ _ _ => rfl
  hit_prev := fun {H} h _ => by
    subst h
    refine ⟨trivial, fun Z hZ => ?_⟩
    rcases hZ with rfl | ⟨_, _, h⟩
    · decide
    · exact h.elim
  miss := False
  insufficientCapacity := False
  corrupt := False
  result_cases := Or.inr (Or.inl ⟨hitF, rfl⟩)
  miss_probes := fun h => h.elim

/-! ## The theorems on this execution -/

/-- `regression`'s epoch hypothesis: no epoch above 3 is published. -/
theorem ep_le_three : ∀ e, X.isW e → X.loc e = some cache.R.ep → X.wval e ≤ 3 := by
  intro e; cases e <;> decide

/-- `regression`'s other hypothesis: every other insert of the key, here the
setup's, returned before `W`'s was called. -/
theorem others_precede :
    ∀ J, ops.insKey J = ops.insKey jw → J ≠ jw → ops.precedes J jw := by
  intro J _ hne
  cases J
  · exact ⟨aU, rfl, hb_of_sb (by decide)⟩
  · exact absurd rfl hne

/-- **The regression scenario, witnessed.** Every hypothesis of
`regression` holds on this execution, with `W`'s insert as `I` and the final
lookup as `L`: the lookup does not miss, and every hit it returns has root
`W`'s insert only. -/
theorem witness_regression :
    ¬ lookF.miss ∧ ∀ H, lookF.hit H → ∃ j w0, cache.isAttempt j w0 ∧
      (cache.pins j).inAttempt w0 H.x2 ∧
      (∀ Y, H.loads Y → ∀ z, X.rf z Y → (cache.pins j).inAttempt w0 z) ∧
      ∀ J, Desc readiness ops w0 J → J = jw :=
  regression consistent promotion ops lookF (Iop := jw) (r := bU) rfl rfl
    (hb_of_sb (by decide)) ep_le_three others_precede

/-- The final lookup's hit `hitF` returns `V2`: the record it read is that
of an attempt whose only root is `W`'s insert, and whose value is 2. -/
theorem witness_final_v2 :
    ∃ j w0, cache.isAttempt j w0 ∧ (cache.pins j).inAttempt w0 hitF.x2 ∧
      (∀ Y, hitF.loads Y → ∀ z, X.rf z Y → (cache.pins j).inAttempt w0 z) ∧
      (∀ J, Desc readiness ops w0 J → J = jw) ∧ value.attVal w0 = 2 := by
  obtain ⟨j, w0, hw0, hx2, hread, hroot⟩ := witness_regression.2 hitF rfl
  refine ⟨j, w0, hw0, hx2, hread, hroot, ?_⟩
  have hd := inAtt_dec hw0 hx2
  rcases hw0 with rfl | rfl
  · exact absurd hd (by decide)
  · rfl

/-- `L`'s stale hit read the setup's record, `V1`, whose only root is the
setup insert: the scenario's concurrent lookup may return the older value. -/
theorem witness_stale_hit :
    (∀ Y, hitL.loads Y → ∀ z, X.rf z Y → (cache.pins ()).inAttempt aW z) ∧
      (∀ J, Desc readiness ops aW J → J = js) ∧ value.attVal aW = 1 := by
  refine ⟨fun Y hY z hz => ?_, fun J hD => desc_of_ins ops (rfl : ops.insOf aW js) hD, rfl⟩
  rcases hY with rfl | rfl | rfl | rfl <;> cases Option.some.inj hz <;>
    exact inAtt_mk (by decide) (by decide)

/-- FR3 in its successful-insert form, on the final lookup. -/
theorem witness_no_resurrection :
    ∃ j w0, cache.isAttempt j w0 ∧ (cache.pins j).inAttempt w0 hitF.x2 ∧
      (∀ Y, hitF.loads Y → ∀ z, X.rf z Y → (cache.pins j).inAttempt w0 z) ∧
      ∀ J, Desc readiness ops w0 J → J = jw ∨ ¬ ops.precedes J jw :=
  successful_insert_no_resurrection consistent promotion ops lookF (Iop := jw) (r := bU)
    rfl rfl (hb_of_sb (by decide)) rfl

/-- F1 on this execution: after `W`'s pin, the registrant's next write to
its pin is `W`'s unpin. -/
theorem witness_pin_next : ∃ u, pinsP.unpinOf bW u ∧ (bU = u ∨ X.sb u bU) :=
  pinsP.pin_next (c := aC) ⟨Or.inr rfl, rfl⟩ ⟨rfl, rfl⟩
    (Or.inr (Or.inl ⟨bW, Or.inr rfl, rfl⟩)) (by decide)

end RegressionWitness
end Sgc
