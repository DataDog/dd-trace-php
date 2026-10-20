import SgcProofs.Bounds

/-!
Machine-width obligations for the production W2--W7 interval. `checkedAdd`
and `checkedMul` specify Rust's checked (debug) integer operations: success
returns the mathematical value, not its reduction modulo the word size.
Wrapping/atomic operations do not have these panic conditions. The source
call-graph correspondence and validity of shared record provenance remain
manual; these lemmas do not claim to translate Rust or model unwinding.
-/
namespace Sgc.PinnedArithmetic

def checkedAdd (bits a b : Nat) : Option Nat :=
  if a + b < 2 ^ bits then some (a + b) else none

def checkedMul (bits a b : Nat) : Option Nat :=
  if a * b < 2 ^ bits then some (a * b) else none

def checkedSub (a b : Nat) : Option Nat :=
  if b ≤ a then some (a - b) else none

def shiftValid (bits amount : Nat) : Prop := amount < bits

theorem add_ok {bits a b : Nat} (h : a + b < 2 ^ bits) :
    checkedAdd bits a b = some (a + b) := by simp [checkedAdd, h]

theorem mul_ok {bits a b : Nat} (h : a * b < 2 ^ bits) :
    checkedMul bits a b = some (a * b) := by simp [checkedMul, h]

/-- Config::resolve accepts power-of-two u32 bucket counts. -/
theorem configured_bucket_bound {k : Nat} (h : 2 ^ k < 4294967296) :
    2 ^ k ≤ 2147483648 := by
  by_cases hk : k ≤ 31
  · exact Nat.pow_le_pow_right (by decide : 0 < 2) hk
  · have hh := Nat.pow_le_pow_right (by decide : 0 < 2) (show 32 ≤ k by omega)
    omega

/-- put.rs: start is masked, and the loop body has probe ≤ mask.
The final increment can reach b, then the loop exits. -/
theorem table_probe {b mask start probe : Nat}
    (hb : b ≤ 2147483648) (hm : mask + 1 = b)
    (hs : start ≤ mask) (hp : probe ≤ mask) :
    checkedAdd 32 start probe = some (start + probe) ∧
    checkedAdd 32 probe 1 = some (probe + 1) ∧
    (start + probe) &&& mask < b := by
  refine ⟨add_ok (by omega), add_ok (by omega), ?_⟩
  exact Bounds.masked_index_in_bounds hm

/-- RefCodec's configured split: ras < 2^31 implies at most 28
word-offset bits. tag_shift = 32 + offset_bits. -/
theorem codec_shifts {offsetBits : Nat} (h : offsetBits ≤ 28) :
    shiftValid 32 offsetBits ∧ shiftValid 64 (32 + offsetBits) := by
  unfold shiftValid
  omega

/-- Decoding masks to at most 28 bits before multiplying by eight. -/
theorem codec_offset {masked : Nat} (h : masked < 268435456) :
    checkedMul 32 masked 8 = some (masked * 8) := mul_ok (by omega)

/-- The hash tag is obtained from a 64-bit hash shifted by 32+bits.
Its bound is exactly the debug assertion in RefCodec::encode. -/
theorem codec_tag {hash bits : Nat} (hh : hash < 2 ^ 64) (hb : bits ≤ 28) :
    hash / 2 ^ (32 + bits) < 2 ^ (32 - bits) := by
  have he : 32 + bits + (32 - bits) = 64 := by omega
  have hd : 0 < 2 ^ (32 + bits) := Nat.two_pow_pos _
  apply (Nat.div_lt_iff_lt_mul hd).2
  simpa [← Nat.pow_add, he, Nat.mul_comm] using hh

/-- The exact assertion is tag <= u32::MAX >> offset_bits. Division
by powers of two is the unsigned right-shift interpretation. -/
theorem codec_tag_assertion {hash bits : Nat} (hh : hash < 18446744073709551616) :
    hash / 2 ^ (32 + bits) ≤ 4294967295 / 2 ^ bits := by
  have hhi : hash / 4294967296 ≤ 4294967295 := by omega
  have h := Nat.div_le_div_right (c := 2 ^ bits) hhi
  simpa [Nat.div_div_eq_div_mul, Nat.pow_add] using h

/-- bit_width(max_word) is zero or its highest set bit has this bound.
Config::resolve computes it as 32-leading_zeros(max_word). -/
theorem codec_width {maxWord bits : Nat} (hm : maxWord < 268435456)
    (hwidth : bits = 0 ∨ 2 ^ (bits - 1) ≤ maxWord) : bits ≤ 28 := by
  rcases hwidth with hzero | hhigh
  · omega
  · by_cases hb : bits ≤ 28
    · exact hb
    · have hp := Nat.pow_le_pow_right (by decide : 0 < 2)
        (show 28 ≤ bits - 1 by omega)
      omega

theorem codec_mask {bits masked : Nat} (hb : bits ≤ 28)
    (hm : masked ≤ 2 ^ bits - 1) : masked < 268435456 := by
  have hp := Nat.pow_le_pow_right (by decide : 0 < 2) hb
  have hpos := Nat.two_pow_pos bits
  omega

/-- Config::resolve's actual layout expression fits usize on the only
supported target width. The stride is next_multiple_of(line); its input
is positive. This discharges arena_address's machine-width premise. -/
theorem configured_mapping_size {line header participants buckets ras : Nat}
    (hline : line = 64 ∨ line = 128) (hheader : header ≤ 2 * line)
    (hp : participants < 4294967296) (hb : buckets ≤ 2147483648)
    (hr : ras < 2147483648) :
    line * (1 + participants) +
      3 * ((header + 8 * buckets + ras + line - 1) / line * line) <
        18446744073709551616 := by
  rcases hline with rfl | rfl <;> omega

/-- arena_of uses modulo three; the layout's final mapping endpoint
bounds both intermediate arithmetic operations on the 64-bit host. -/
theorem arena_address {epoch arenas stride mapping : Nat}
    (hend : arenas + 3 * stride ≤ mapping) (hmap : mapping < 2 ^ 64) :
    epoch % 3 < 3 ∧
    checkedMul 64 (epoch % 3) stride = some (epoch % 3 * stride) ∧
    checkedAdd 64 arenas (epoch % 3 * stride) =
      some (arenas + epoch % 3 * stride) := by
  have hi : epoch % 3 < 3 := Nat.mod_lt _ (by decide)
  have hm := Nat.mul_le_mul_right stride (show epoch % 3 ≤ 3 by omega)
  exact ⟨hi, mul_ok (by omega), add_ok (by omega)⟩

/-- reserve adds a decoded 31-bit bump to bounded record/chunk sizes.
Even the larger u32 header-derived record_size fits these u64 sums. -/
theorem reservation_adds {bump n chunk : Nat} (hb : bump < 2147483648)
    (hn : n ≤ 4294967312) (hc : chunk < 2147483648) :
    checkedAdd 64 bump n = some (bump + n) ∧
    checkedAdd 64 bump chunk = some (bump + chunk) :=
  ⟨add_ok (by omega), add_ok (by omega)⟩

/-- align8 receives u32 lengths, including lengths read from storage;
header/length consistency is evaluated in u64 before trusting the data. -/
theorem lengths_arithmetic {kl vl : Nat} (hk : kl < 4294967296)
    (hv : vl < 4294967296) :
    checkedAdd 64 kl 7 = some (kl + 7) ∧
    checkedAdd 64 8 ((kl + 7) / 8 * 8) = some (8 + (kl + 7) / 8 * 8) ∧
    checkedAdd 64 (8 + (kl + 7) / 8 * 8) vl =
      some (8 + (kl + 7) / 8 * 8 + vl) := by
  have hr : (kl + 7) / 8 * 8 ≤ kl + 7 := Nat.div_mul_le_self _ _
  exact ⟨add_ok (by omega), add_ok (by omega), add_ok (by omega)⟩

/-- IX/SP provenance bounds an entry before the header load; the
subsequent guards strengthen this for the key loop. These implications
also prove the pinned production_assert offset condition. -/
theorem record_prefix {off ras : Nat} (h : off + 16 ≤ ras)
    (hr : ras < 2147483648) :
    off ≤ ras ∧ 8 ≤ ras - off ∧
    checkedAdd 32 off 8 = some (off + 8) ∧
    checkedAdd 32 off 16 = some (off + 16) := by
  exact ⟨by omega, by omega, add_ok (by omega), add_ok (by omega)⟩

theorem key_word_add {off pos ras : Nat} (h : off + pos + 8 ≤ ras)
    (hr : ras < 2147483648) :
    checkedAdd 32 off pos = some (off + pos) := add_ok (by omega)

/-- Accepted key/value lengths and the reservation bound imply the
bounds used for every emitter counter, including the final free index.
ceil division here is the implementation of usize::div_ceil(8). -/
theorem configured_emit_bounds {key value ras : Nat}
    (hrecord : 16 + (key + 7) / 8 * 8 + (value + 7) / 8 * 8 ≤ ras)
    (hras : ras < 2147483648) :
    key < 2147483648 ∧ value < 2147483648 ∧
    2 + (key + 7) / 8 + (value + 7) / 8 < 268435456 := by omega

theorem emit_word_position {keyWords valueWords w : Nat}
    (hrecord : 2 + keyWords + valueWords < 268435456)
    (hw : w ≤ 2 + keyWords + valueWords) : w < 268435456 := by omega

theorem byte_loop_exit {i n : Nat} (hi : i ≤ n) (hguard : ¬ i + 8 ≤ n) :
    n - i < 8 := by omega

/-- emit_bytes uses i <= n <= max configured length and advances a
word index bounded by the complete reserved record. -/
theorem emit_counters {i n w : Nat} (hi : i ≤ n) (hn : n < 2147483648)
    (hw : w < 268435456) :
    checkedAdd 64 i 8 = some (i + 8) ∧
    checkedAdd 32 w 1 = some (w + 1) ∧
    checkedSub n i = some (n - i) := by
  exact ⟨add_ok (by omega), add_ok (by omega), by simp [checkedSub, hi]⟩

/-- PaddedValue's div_ceil(8) and emit_words loop use bounded usize
counts. The byte loop's successful guard also licenses its i increment. -/
theorem padded_emit_counters {size i count : Nat} (hs : size < 2147483648)
    (hc : count ≤ (size + 7) / 8) (hi : i ≤ count) :
    checkedAdd 64 (size / 8) 1 = some (size / 8 + 1) ∧
    checkedAdd 64 i 1 = some (i + 1) := by
  exact ⟨add_ok (by omega), add_ok (by omega)⟩

/-- key_matches derives full and rem from the private caller length.
Its tail mask shift is guarded by rem != 0. -/
theorem key_tail {key : Nat} (h : key % 8 ≠ 0) :
    checkedSub key (key / 8 * 8) = some (key - key / 8 * 8) ∧
    0 < key % 8 ∧ key % 8 < 8 ∧ shiftValid 64 ((8 - key % 8) * 8) := by
  have hm := Nat.mod_lt key (by decide : 0 < 8)
  have hd := Nat.div_mul_le_self key 8
  refine ⟨by simp [checkedSub, hd], by omega, hm, ?_⟩
  unfold shiftValid
  omega

/-- The terminating full-word loop has i <= n < i+8. The tail branch
requires i<n, proving load_tail's assertion and both tail shift guards. -/
theorem tail_guard {i n : Nat} (hi : i < n) (he : n < i + 8) :
    0 < n - i ∧ n - i < 8 ∧
    shiftValid 64 ((8 - (n - i)) * 8) ∧
    checkedSub 8 (n - i) = some (8 - (n - i)) := by
  refine ⟨by omega, by omega, ?_, ?_⟩
  · unfold shiftValid; omega
  · simp [checkedSub, show n - i ≤ 8 by omega]

/-- Non-prefix load_tail uses positions 0, 4, or 6; its shift is at
most 48. The key comparator's full-word loop increments by eight. -/
theorem small_tail_and_key_counter {pos full : Nat} (hp : pos ≤ 6)
    (hf : full < 2147483648) :
    shiftValid 64 (pos * 8) ∧ checkedAdd 32 full 8 = some (full + 8) := by
  exact ⟨by unfold shiftValid; omega, add_ok (by omega)⟩

/-- A deliberately coarse inductive invariant covers all four estimator
sums. Each of n samples contributes at most MAX_SAMPLES squared. -/
def SumBound (n sum : Nat) : Prop := n ≤ 2097152 ∧ sum ≤ n * 4398046511104

theorem estimator_zero : SumBound 0 0 := by constructor <;> decide

/-- Every actual increment (t, t*t, 0/1, or 0/t) is below this bound. -/
theorem estimator_increment {t : Nat} (ht : t ≤ 2097152) :
    t ≤ 4398046511104 ∧ t * t ≤ 4398046511104 ∧
    checkedMul 64 t t = some (t * t) := by
  have hm := Nat.mul_le_mul ht ht
  exact ⟨by omega, by omega, mul_ok (by omega)⟩

theorem estimator_sample {n sum delta : Nat} (h : SumBound n sum)
    (hn : n ≠ 2097152) (hd : delta ≤ 4398046511104) :
    checkedAdd 64 n 1 = some (n + 1) ∧
    checkedAdd 64 sum delta = some (sum + delta) ∧ SumBound (n + 1) (sum + delta) := by
  rcases h with ⟨hnmax, hs⟩
  refine ⟨add_ok (by omega), add_ok (by omega), ?_⟩
  constructor <;> omega

/-- All source choices of sample increment satisfy the transition's
bound. This includes the Bernoulli branches, not just the t*t sum. -/
theorem estimator_source_deltas {n : Nat} (hn : n < 2097152) (occupied : Bool) :
    n + 1 ≤ 4398046511104 ∧
    (n + 1) * (n + 1) ≤ 4398046511104 ∧
    (if occupied then 1 else 0) ≤ 4398046511104 ∧
    (if occupied then n + 1 else 0) ≤ 4398046511104 := by
  have h := estimator_increment (show n + 1 ≤ 2097152 by omega)
  cases occupied <;> simp_all

/-- decided_at starts at zero, resets with n, and decide assigns n;
sampling only increases n. Integer clamp has fixed ordered bounds. -/
theorem estimator_decision {n decided : Nat} (h : decided ≤ n) :
    checkedSub n decided = some (n - decided) ∧ (4 : Nat) ≤ 32 := by
  simp [checkedSub, h]

/-- expected_max_of_normals clamps the saturating float-to-usize cast
before every array access. This covers NaN and infinities too. -/
theorem estimator_table (cast : Nat) :
    min cast 9 < 11 ∧ min cast 9 + 1 < 11 ∧
    checkedAdd 64 (min cast 9) 1 = some (min cast 9 + 1) := by
  have h := Nat.min_le_right cast 9
  exact ⟨by omega, by omega, add_ok (by omega)⟩

end Sgc.PinnedArithmetic
