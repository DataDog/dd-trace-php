import SgcProofs.CacheWitness

/-!
# Nonvacuity of the B0 and H1 read interfaces

Use CacheWitness's complete consistent execution: the second attempt of
writer b loads B0, then reads its chunk's epoch and returns a chunk cut,
without executing B1 (`store.rs:303-308`). This checks the new interfaces
against a path that ends before B1, including ordinary relaxed H1 loads.
-/

namespace Sgc
namespace ReserveReadWitness

open Execution CacheWitness CacheWitness.E

/-- All three reads of the matching chunk probe, before its cursor store. -/
def probe : ReserveProtocol.H1Probe reserve where
  slot := ()
  attempt := bW2
  le := bLe
  lc := bLc
  ln := bLn
  ze := bKe
  zc := bKc
  zn := bKn
  isAttempt := Or.inr (Or.inr (Or.inl rfl))
  le_in := inAtt_mk (by decide) (by decide)
  lc_in := inAtt_mk (by decide) (by decide)
  ln_in := inAtt_mk (by decide) (by decide)
  after_pin_e := by decide
  after_pin_c := by decide
  after_pin_n := by decide
  e_before_c := by decide
  c_before_n := by decide
  e_loc := rfl
  c_loc := rfl
  n_loc := rfl
  e_rf := rfl
  c_rf := rfl
  n_rf := rfl
  epoch_match := by decide
  before_b1 := by
    intro b hb hbB
    exact (reserve.use_no_b1
      (show reserve.useOf () bW2 bLe bLc bLn bMc from ⟨rfl, rfl, rfl, rfl, rfl⟩)
      hb hbB).elim
  one_cursor := by
    intro le' lc' ln' s h
    obtain ⟨-, -, rfl, -, -⟩ := h
    rfl

/-- An ordinary epoch load of the second attempt, with no assumed seriality. -/
def epochLoad : ReserveProtocol.H1Load reserve where
  read := bLe
  source := bKe
  rf := rfl
  slot := ()
  attempt := bW2
  isAttempt := Or.inr (Or.inr (Or.inl rfl))
  inAttempt := inAtt_mk (by decide) (by decide)
  chunkWord := Or.inl rfl
  epochRead := bLe
  path := .epoch rfl (by decide) rfl
  epoch_ne_cursor := by decide
  epoch_ne_end := by decide
  not_write := by decide
  init_before := by
    intro z hz
    rcases hz with rfl | rfl | rfl <;> exact hb_of_sb (by decide)
  before_b1 := by
    intro b hb hbB
    exact (reserve.use_no_b1
      (show reserve.useOf () bW2 bLe bLc bLn bMc from ⟨rfl, rfl, rfl, rfl, rfl⟩)
      hb hbB).elim

/-- B0 of a path that returns its chunk before B1. -/
def precheck : ReserveProtocol.B0Load reserve where
  slot := ()
  attempt := bW2
  read := bL2
  source := bB
  epoch := 5
  recordSize := 16
  pin := cache.att_w2 (Or.inr (Or.inr (Or.inl rfl)))
  after_pin := by decide
  inAttempt := inAtt_mk (by decide) (by decide)
  rf := rfl
  loc := by decide
  occupied := False
  chunkRoom := True
  outcome := .cut
  path := .chunk (by decide) (by decide) id trivial
  b1_outcome := by
    intro y hy hyB
    exact (reserve.use_no_b1
      (show reserve.useOf () bW2 bLe bLc bLn bMc from ⟨rfl, rfl, rfl, rfl, rfl⟩)
      hy hyB).elim

/-- The cursor and end loads belong to that same guarded invocation. -/
def cursorLoad : ReserveProtocol.H1Load reserve where
  slot := ()
  attempt := bW2
  isAttempt := Or.inr (Or.inr (Or.inl rfl))
  epochRead := bLe
  epoch_ne_cursor := by decide
  epoch_ne_end := by decide
  read := bLc
  source := bKc
  rf := rfl
  inAttempt := inAtt_mk (by decide) (by decide)
  chunkWord := Or.inr (Or.inl rfl)
  path := .tail (ReserveProtocol.H1Probe.gate reserve probe) (by decide) (by decide) (by decide)
  not_write := by decide
  init_before := by
    intro z hz
    rcases hz with rfl | rfl | rfl <;> exact hb_of_sb (by decide)
  before_b1 := by
    intro b hb hbB
    exact (reserve.use_no_b1
      (show reserve.useOf () bW2 bLe bLc bLn bMc from ⟨rfl, rfl, rfl, rfl, rfl⟩)
      hb hbB).elim

def endLoad : ReserveProtocol.H1Load reserve where
  slot := ()
  attempt := bW2
  isAttempt := Or.inr (Or.inr (Or.inl rfl))
  epochRead := bLe
  epoch_ne_cursor := by decide
  epoch_ne_end := by decide
  read := bLn
  source := bKn
  rf := rfl
  inAttempt := inAtt_mk (by decide) (by decide)
  chunkWord := Or.inr (Or.inr rfl)
  path := .tail (ReserveProtocol.H1Probe.gate reserve probe) (by decide) (by decide) (by decide)
  not_write := by decide
  init_before := by
    intro z hz
    rcases hz with rfl | rfl | rfl <;> exact hb_of_sb (by decide)
  before_b1 := by
    intro b hb hbB
    exact (reserve.use_no_b1
      (show reserve.useOf () bW2 bLe bLc bLn bMc from ⟨rfl, rfl, rfl, rfl, rfl⟩)
      hb hbB).elim

/-- All three concrete H1Load values instantiate the universal ownership
result, so the cursor/end guard is exercised rather than left uninhabited. -/
theorem all_loads_owned :
    (∃ c, (cache.pins ()).w2Of c bW2 ∧ (cache.slot ()).act c bKe) ∧
    (∃ c, (cache.pins ()).w2Of c bW2 ∧ (cache.slot ()).act c bKc) ∧
    (∃ c, (cache.pins ()).w2Of c bW2 ∧ (cache.slot ()).act c bKn) :=
  ⟨reserve.h1_own consistent epochLoad, reserve.h1_own consistent cursorLoad,
    reserve.h1_own consistent endLoad⟩

/-- The full execution validates the matching-probe ownership interface. -/
theorem probe_supported : ∃ c, (cache.pins ()).w2Of c bW2 ∧
    (cache.slot ()).act c bKc ∧ (cache.slot ()).act c bKn :=
  reserve.chunk_probe_own consistent probe

/-- The complete execution instantiates derived serial/latest-read order. -/
theorem reads_supported : X.Consistent ∧ X.hb bKe bLe ∧
    (∀ y, (cache.pins ()).inAttempt bW2 y → ¬ cache.R.isB1 y) := by
  exact ⟨consistent, (reserve.h1_latest consistent epochLoad).1,
    fun _ hy => reserve.use_no_b1
      (show reserve.useOf () bW2 bLe bLc bLn bMc from ⟨rfl, rfl, rfl, rfl, rfl⟩) hy⟩

open ReserveProtocol

/-- Branch regression: a B1 reading a sealed word at bump 32 fails,
adds 16 to reach 48, and the next B0 still sees it sealed. Even with a
usable chunk and room, B0 exits before H1/B1. This is an arithmetic and
control-flow trace, not an additional concurrent execution witness. -/
theorem first_b0_sealed :
    ¬ (¬ True ∧ (32 : Nat) + 16 ≤ 64) ∧
    (32 + 16) % bumpLimit = 48 ∧
    B0Path ((2 : Nat) = 2) True False True (48 + 16 ≤ 64) .retry ∧
    ¬ B0Path ((2 : Nat) = 2) True False True (48 + 16 ≤ 64) .add := by
  refine ⟨by simp, by decide, .seal rfl trivial, ?_⟩
  intro h
  exact (b0_add_guard h).2.1 trivial

/-- Branch regression: an unsealed B1 at bump 64 fails a 16-byte claim
and reaches 80. An intervening arena reset changes stamp 2 to stamp 5
and clears the bump. The next B0 of a pass pinned for 2 exits on the
stamp check, even though the reset restored room. As above, this tests
the branch/arithmetic path and does not assert concurrency reachability. -/
theorem first_b0_other_stamp :
    ¬ (¬ False ∧ (64 : Nat) + 16 ≤ 64) ∧
    (64 + 16) % bumpLimit = 80 ∧
    B0Path ((5 : Nat) = 2) False False True (0 + 16 ≤ 64) .retry ∧
    ¬ B0Path ((5 : Nat) = 2) False False True (0 + 16 ≤ 64) .add := by
  refine ⟨by decide, by decide, .stamp (by decide), ?_⟩
  intro h
  exact (by decide : (5 : Nat) ≠ 2) (b0_add_guard h).1

end ReserveReadWitness
end Sgc
