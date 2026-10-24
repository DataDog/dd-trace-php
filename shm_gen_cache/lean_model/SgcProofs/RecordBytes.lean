import SgcProofs.Provenance

/-!
Concrete byte contents for HT2, HT3 and RB3. A byte is its eight bits;
`Bytes.byte` decodes the little-endian u64 words used by `load_u64`,
`load_tail`, `emit_bytes_relaxed` and `emit_words_relaxed` in `util.rs`.
The logical length excludes padding, whereas `word` retains all padding.
Source fields below describe individual W4/W5 stores and D4/D5 addresses.
They do not assume the result of a comparison or the provenance theorem.
-/

namespace Sgc.Execution
namespace RecordBytes

abbrev Byte := Fin 8 → Bool

structure Bytes where
  size : Nat
  word : Nat → Nat
  bounded : ∀ p, word p < 2 ^ 64

def Bytes.byte (a : Bytes) (j : Nat) : Byte :=
  fun b => (a.word (j / 8)).testBit (8 * (j % 8) + b.val)

def SameBytes (a b : Bytes) : Prop :=
  a.size = b.size ∧ ∀ j, j < a.size → a.byte j = b.byte j

def mask (size p : Nat) : Nat := 2 ^ (8 * min 8 (size - 8 * p)) - 1

theorem masked_bits {a b size p : Nat} :
    a &&& mask size p = b &&& mask size p ↔
      ∀ k, k < 8 * min 8 (size - 8 * p) → a.testBit k = b.testBit k := by
  constructor
  · intro h k hk
    have := congrArg (fun n : Nat => n.testBit k) h
    simpa [mask, Nat.testBit_and, hk] using this
  · intro h
    apply Nat.eq_of_testBit_eq
    intro k
    by_cases hk : k < 8 * min 8 (size - 8 * p)
    · simp [mask, hk, h k hk]
    · simp [mask, hk]

/-- Full words use all 64 bits; the final partial word uses exactly the
low `8 * rem` bits, as `!0u64 >> ((8 - rem) * 8)` in `key_matches`. -/
theorem mask_full_or_tail {size p : Nat} (hp : p < (size + 7) / 8) :
    (8 * p + 8 ≤ size ∧ mask size p = 2 ^ 64 - 1) ∨
    (8 * p < size ∧ size < 8 * p + 8 ∧
      mask size p = 2 ^ (8 * (size - 8 * p)) - 1) := by
  by_cases h : 8 * p + 8 ≤ size
  · left
    exact ⟨h, by simp [mask, Nat.min_eq_left (by omega : 8 ≤ size - 8 * p)]⟩
  · right
    exact ⟨by omega, by omega,
      by simp [mask, Nat.min_eq_right (by omega : size - 8 * p ≤ 8)]⟩

/-- Equality of the actual key masks is exactly equality of the logical
bytes. Padding outside the logical length is intentionally irrelevant. -/
theorem sameBytes_iff_words (a b : Bytes) (hlen : a.size = b.size) :
    SameBytes a b ↔ ∀ p, p < (a.size + 7) / 8 →
      a.word p &&& mask a.size p = b.word p &&& mask a.size p := by
  constructor
  · intro h p hp
    apply masked_bits.mpr
    intro k hk
    have hj : 8 * p + k / 8 < a.size := by omega
    have hb := congrFun (h.2 _ hj) ⟨k % 8, by omega⟩
    have hd : (8 * p + k / 8) / 8 = p := by omega
    have hm : (8 * p + k / 8) % 8 = k / 8 := by omega
    have he : 8 * (k / 8) + k % 8 = k := by omega
    simpa [Bytes.byte, hd, hm, he] using hb
  · intro h
    refine ⟨hlen, ?_⟩
    intro j hj
    funext bit
    apply masked_bits.mp (h (j / 8) (by omega))
    have hb := bit.isLt
    omega

/-- The recursive word loop returns immediately on its first mismatch.
For full words the mask is all ones; for the tail it is the Rust mask. -/
def compareWords (size : Nat) (stored input : Nat → Nat) : Nat → Nat → Bool
  | _, 0 => true
  | p, n + 1 =>
      if stored p &&& mask size p = input p &&& mask size p then
        compareWords size stored input (p + 1) n
      else false

theorem compareWords_exact (size : Nat) (stored input : Nat → Nat) (p n : Nat) :
    compareWords size stored input p n = true ↔
      ∀ q, p ≤ q → q < p + n →
        stored q &&& mask size q = input q &&& mask size q := by
  induction n generalizing p with
  | zero =>
    constructor
    · intro _ q hq hlt; omega
    · intro _; rfl
  | succ n ih =>
    simp only [compareWords]
    split
    · rename_i h
      rw [ih]
      constructor
      · intro rest q hq hqn
        by_cases he : q = p
        · simpa [he] using h
        · exact rest q (by omega) (by omega)
      · intro all q hq hqn
        exact all q (by omega) (by omega)
    · rename_i h
      simp only [Bool.false_eq_true, false_iff]
      intro all
      exact h (all p (by omega) (by omega))

theorem key_matches_exact (a b : Bytes) (hlen : a.size = b.size) :
    compareWords a.size a.word b.word 0 ((a.size + 7) / 8) = true ↔ SameBytes a b := by
  rw [compareWords_exact, sameBytes_iff_words a b hlen]
  simp

variable {Ev Loc : Type} {X : Execution Ev Loc} {C : CacheProtocol X}
  {A : ArenaProtocol X C} {Rv : ReserveProtocol X C} {I : IndexProtocol X A Rv}

/-- Individual W4 store contents from `store.rs` and `util.rs`. The key
and value buffers are the actual arguments to this attempt's emission
loops, including padding. The address equations use the same u32
arithmetic as the loads. Bounds/SP4/SP5 justify that these are distinct
words of this reservation. They apply to every executed store, including
a prefix interrupted before I2, `FullRejected`, and a promotion that finds an existing key. No field
requires publication or completion. On B0/B1 exits there are no record
stores (`ReserveProtocol.rec_in_res`). These source facts assert no read
result or caller provenance. -/
structure Contents (I : IndexProtocol X A Rv) where
  key : Ev → Bytes
  value : Ev → Bytes
  key_length : ∀ {i w}, C.isAttempt i w → (key w).size = keyLen (Rv.lens w)
  value_length : ∀ {i w}, C.isAttempt i w → (value w).size = valLen (Rv.lens w)
  /-- Decoding the W5 `RecordHeader::new` store. -/
  header_reserved : ∀ {i w r z l}, C.isAttempt i w →
    Rv.reserves i w r →
    (C.pins i).inAttempt w z → X.isW z → X.loc z = some l → Rv.isRecord l →
    Rv.recOff l = r →
    entryStamp (X.wval z) = C.attEpoch w % wordLimit ∧
      valLen (X.wval z) = 8 + ((key w).size + 7) / 8 * 8 + (value w).size
  /-- W4 writes the lengths before the key/value emission loops. -/
  lengths_reserved : ∀ {i w r z l}, C.isAttempt i w →
    Rv.reserves i w r →
    (C.pins i).inAttempt w z → X.isW z → X.loc z = some l → Rv.isRecord l →
    Rv.recOff l = (r + 8) % wordLimit →
    X.wval z = Rv.lens w
  key_reserved : ∀ {i w r z l p}, C.isAttempt i w →
    Rv.reserves i w r →
    (C.pins i).inAttempt w z → X.isW z → X.loc z = some l → Rv.isRecord l →
    p < ((key w).size + 7) / 8 →
    Rv.recOff l = (r + 16 + 8 * p) % wordLimit →
    X.wval z = (key w).word p
  value_reserved : ∀ {i w r z l p}, C.isAttempt i w →
    Rv.reserves i w r →
    (C.pins i).inAttempt w z → X.isW z → X.loc z = some l → Rv.isRecord l →
    p < ((value w).size + 7) / 8 →
    Rv.recOff l = (r + 16 +
      ((key w).size + 7) / 8 * 8 + 8 * p) % wordLimit →
    X.wval z = (value w).word p

/-- Publication specializes the reservation-anchored store fact. -/
theorem Contents.header_store (D : Contents I) {i w x z l} (hw : C.isAttempt i w) :
    (I.isPubCas x ∨ I.isPubStore x) → (C.pins i).inAttempt w x →
    (C.pins i).inAttempt w z → X.isW z → X.loc z = some l → Rv.isRecord l →
    Rv.recOff l = I.entryOff (X.wval x) →
    entryStamp (X.wval z) = C.attEpoch w % wordLimit ∧
      valLen (X.wval z) = 8 + ((D.key w).size + 7) / 8 * 8 + (D.value w).size := by
  intro hk hx
  exact D.header_reserved hw (I.pub_off hk hw hx)

/-- Publication specializes the reservation-anchored store fact. -/
theorem Contents.lengths_store (D : Contents I) {i w x z l} (hw : C.isAttempt i w) :
    (I.isPubCas x ∨ I.isPubStore x) → (C.pins i).inAttempt w x →
    (C.pins i).inAttempt w z → X.isW z → X.loc z = some l → Rv.isRecord l →
    Rv.recOff l = (I.entryOff (X.wval x) + 8) % wordLimit →
    X.wval z = Rv.lens w := by
  intro hk hx
  exact D.lengths_reserved hw (I.pub_off hk hw hx)

/-- Publication specializes the reservation-anchored store fact. -/
theorem Contents.key_store (D : Contents I) {i w x z l p} (hw : C.isAttempt i w) :
    (I.isPubCas x ∨ I.isPubStore x) → (C.pins i).inAttempt w x →
    (C.pins i).inAttempt w z → X.isW z → X.loc z = some l → Rv.isRecord l →
    p < ((D.key w).size + 7) / 8 →
    Rv.recOff l = (I.entryOff (X.wval x) + 16 + 8 * p) % wordLimit →
    X.wval z = (D.key w).word p := by
  intro hk hx
  exact D.key_reserved hw (I.pub_off hk hw hx)

/-- Publication specializes the reservation-anchored store fact. -/
theorem Contents.value_store (D : Contents I) {i w x z l p} (hw : C.isAttempt i w) :
    (I.isPubCas x ∨ I.isPubStore x) → (C.pins i).inAttempt w x →
    (C.pins i).inAttempt w z → X.isW z → X.loc z = some l → Rv.isRecord l →
    p < ((D.value w).size + 7) / 8 →
    Rv.recOff l = (I.entryOff (X.wval x) + 16 +
      ((D.key w).size + 7) / 8 * 8 + 8 * p) % wordLimit →
    X.wval z = (D.value w).word p := by
  intro hk hx
  exact D.value_reserved hw (I.pub_off hk hw hx)

/-- Concrete caller/output buffers for the successful Rust probe path.
`input_word` is the private `load_u64`/`load_tail`; the tail's irrelevant
high bits may differ. `output_word` is the whole u64 output write. -/
structure HitBytes (H : Hit I) (io : IndexProtocol.HitIO I H) where
  input : Bytes
  output : Bytes
  input_size : input.size = io.inputLength
  output_size : output.size = io.returnedLength
  input_word : ∀ p, io.inputWord p &&& mask input.size p.val =
    input.word p.val &&& mask input.size p.val
  masks : ∀ p, io.keyMask p = mask input.size p.val
  output_word : ∀ p, output.word p.val = io.outputWord p

/-- **HT2 at byte level.** A successful probe rechecks precisely the
stored key bytes and returns precisely the stored value words, padding
included, of its single publishing attempt. -/
theorem hit_bytes_exact (D : Contents I) (hc : X.Consistent)
    (H : Hit I) (io : IndexProtocol.HitIO I H) (B : HitBytes H io) :
    ∃ i w, C.isAttempt i w ∧ (C.pins i).inAttempt w H.x2 ∧ C.attEpoch w = H.e ∧
      SameBytes B.input (D.key w) ∧ SameBytes B.output (D.value w) ∧
      (∀ p, p < (B.output.size + 7) / 8 → B.output.word p = (D.value w).word p) := by
  obtain ⟨i, w, hw, hx, hk, he, hsrc⟩ := I.hit_integrity hc H
  obtain ⟨j, w', hw', hx', _, hlens⟩ := I.hit_lengths_exact hc H io
  have ⟨_, heq⟩ := Rv.att_unique hw hw' hx hx'
  subst w'
  have hkey : B.input.size = (D.key w).size := by
    rw [B.input_size, io.key_length_checked, hlens, D.key_length hw]
  have hval : B.output.size = (D.value w).size := by
    rw [B.output_size, io.returned_length, hlens, D.value_length hw]
  have hkeys : io.keyWords = (B.input.size + 7) / 8 := by
    rw [io.key_words, ← io.key_length_checked, ← B.input_size]
  have hvals : io.valueWords = (B.output.size + 7) / 8 := by
    rw [io.value_words, ← io.returned_length, ← B.output_size]
  have kval : ∀ p : Fin io.keyWords, X.rval (io.keyLoad p) = (D.key w).word p.val := by
    intro p
    obtain ⟨z, hz⟩ := io.key_rf p
    obtain ⟨l, hl, hr, hoff⟩ := io.key_address p
    obtain ⟨hzW, _, hzl, _⟩ := hc.rf_wf hz
    rw [hc.rf_val hz]
    apply D.key_store hw hk hx (hsrc _ (io.all_loads _ (io.key_load p)) z hz)
      hzW (hzl.trans hl) hr
    · rw [← hkey, ← hkeys]; exact p.isLt
    · rwa [hc.rf_val H.i1_rf] at hoff
  have vval : ∀ p : Fin io.valueWords, X.rval (io.valueLoad p) = (D.value w).word p.val := by
    intro p
    obtain ⟨z, hz⟩ := io.value_rf p
    obtain ⟨l, hl, hr, hoff⟩ := io.value_address p
    obtain ⟨hzW, _, hzl, _⟩ := hc.rf_wf hz
    rw [hc.rf_val hz]
    apply D.value_store hw hk hx (hsrc _ (io.all_loads _ (io.value_load p)) z hz)
      hzW (hzl.trans hl) hr
    · rw [← hval, ← hvals]; exact p.isLt
    · rwa [hc.rf_val H.i1_rf, hlens, ← D.key_length hw] at hoff
  have hout : ∀ p, p < (B.output.size + 7) / 8 →
      B.output.word p = (D.value w).word p := by
    intro p hp
    let q : Fin io.valueWords := ⟨p, by omega⟩
    exact (B.output_word q).trans ((io.value_copy q).trans (vval q))
  refine ⟨i, w, hw, hx, he, ?_, ?_, hout⟩
  · apply (sameBytes_iff_words _ _ hkey).mpr
    intro p hp
    let q : Fin io.keyWords := ⟨p, by omega⟩
    have hm := io.key_match q
    rw [kval q, B.masks q, B.input_word q] at hm
    exact hm.symm
  · apply (sameBytes_iff_words _ _ hval).mpr
    intro p hp
    rw [hout p hp]

/-- The actual key loop can stop after any mismatching word. `seen` lists
only loads that occurred; neither `done` nor a mismatch requires later
loads. The tail uses the same mask as the successful-hit proof. -/
inductive KeyScan (size : Nat) (read input : Nat → Nat) (seen : Nat → Prop) :
    Nat → Nat → Bool → Prop
  | done (p) : KeyScan size read input seen p 0 true
  | mismatch {p n} : seen p → read p &&& mask size p ≠ input p &&& mask size p →
      KeyScan size read input seen p (n + 1) false
  | next {p n result} : seen p → read p &&& mask size p = input p &&& mask size p →
      KeyScan size read input seen (p + 1) n result →
      KeyScan size read input seen p (n + 1) result

theorem keyScan_exact {size p n result} {read input stored : Nat → Nat}
    {seen : Nat → Prop} (run : KeyScan size read input seen p n result)
    (sources : ∀ q, seen q → read q = stored q) :
    result = true ↔ ∀ q, p ≤ q → q < p + n →
      stored q &&& mask size q = input q &&& mask size q := by
  induction run with
  | done p =>
    constructor
    · intro _ q hq hlt; omega
    · intro _; rfl
  | @mismatch p n hs hm =>
    simp only [Bool.false_eq_true, false_iff]
    intro h
    exact hm (by rw [sources p hs]; exact h p (by omega) (by omega))
  | @next p n result hs hm rest ih =>
    rw [ih]
    have hp : stored p &&& mask size p = input p &&& mask size p := by
      rwa [sources p hs] at hm
    constructor
    · intro all q hq hlt
      by_cases he : q = p
      · simpa [he] using hp
      · exact all q (by omega) (by omega)
    · intro all q hq hlt
      exact all q (by omega) (by omega)

/-- Common memory context for every `entry_has_key` invocation, including
header-only, length-only, partial-key, and FullRejected paths. There is no
mandatory lengths/key event and no assumed comparison result. -/
structure TableReads (I : IndexProtocol X A Rv) where
  slot : Loc
  l0 : Ev
  z : Ev
  i : C.ι
  w : Ev
  attempt : C.isAttempt i w
  load_attempt : (C.pins i).inAttempt w l0
  read : X.rf z l0
  acquire : X.isAcq l0
  load_loc : X.loc l0 = some slot
  index_slot : I.isIndex slot
  own_arena : I.arenaOf slot = C.R.ctlLoc (C.attEpoch w)
  own_stamp : entryStamp (X.rval l0) = C.attEpoch w % wordLimit
  loads : Ev → Prop
  addresses : Rv.RecLoads (I.arenaOf slot) (I.entryOff (X.rval l0)) loads
  after_load : ∀ Y, loads Y → X.sb l0 Y ∧ (C.pins i).inAttempt w Y

/-- **RB3 for all executed prefixes.** I0 identifies the publishing
attempt and every D4/D5 that actually occurs reads one of its stores.
This theorem also covers a header rejection without any lengths load. -/
theorem table_prefix_sources (hc : X.Consistent) (T : TableReads I) :
    ∃ j w', C.isAttempt j w' ∧ (C.pins j).inAttempt w' T.z ∧
      C.attEpoch w' = C.attEpoch T.w ∧ (I.isPubCas T.z ∨ I.isPubStore T.z) ∧
      ∀ Y, T.loads Y → ∀ v, X.rf v Y → (C.pins j).inAttempt w' v := by
  obtain ⟨j, w', hw', hz, hE, hzk⟩ :=
    I.current_entry hc T.attempt T.load_attempt T.read T.load_loc
      T.index_slot T.own_arena T.own_stamp
  exact ⟨j, w', hw', hz, hE, hzk, I.keyCheck_sources hc T.attempt hw' hz hzk hE
    T.read T.acquire T.load_loc T.addresses T.after_load⟩

/-- The three guards in `put.rs:entry_has_key`, after the production
assertion on the published offset. Subtraction is exact on this path. -/
def HeaderOK (epoch off cap header : Nat) : Prop :=
  entryStamp header = epoch % wordLimit ∧ 8 ≤ valLen header ∧
    off + 8 + valLen header ≤ cap

def LayoutOK (header lengths : Nat) : Prop :=
  8 + (keyLen lengths + 7) / 8 * 8 + valLen lengths = valLen header

/-- Rust's branching order, with optional lengths and key loads. The
header rejection needs only D4; the length rejection needs D4 and lengths;
the recursive `KeyScan` includes only its visited prefix. -/
structure EntryRun (T : TableReads I) where
  input : Bytes
  result : Bool
  header : Ev
  lengths : Ev
  header_load : T.loads header
  header_rf : ∃ z, X.rf z header
  header_address : ∃ l, X.loc header = some l ∧ Rv.isRecord l ∧
    Rv.recOff l = I.entryOff (X.rval T.l0)
  lengths_load : HeaderOK (C.attEpoch T.w) (I.entryOff (X.rval T.l0)) Rv.cap
    (X.rval header) → T.loads lengths
  lengths_rf : T.loads lengths → ∃ z, X.rf z lengths
  lengths_address : T.loads lengths → ∃ l, X.loc lengths = some l ∧ Rv.isRecord l ∧
    Rv.recOff l = (I.entryOff (X.rval T.l0) + 8) % wordLimit
  seen : Nat → Prop
  keyLoad : Nat → Ev
  key_load : ∀ p, seen p → T.loads (keyLoad p)
  key_rf : ∀ p, seen p → ∃ z, X.rf z (keyLoad p)
  key_bounds : ∀ p, seen p → p < (input.size + 7) / 8
  key_address : ∀ p, seen p → ∃ l, X.loc (keyLoad p) = some l ∧ Rv.isRecord l ∧
    Rv.recOff l = (I.entryOff (X.rval T.l0) + 16 + 8 * p) % wordLimit
  flow :
    (¬ HeaderOK (C.attEpoch T.w) (I.entryOff (X.rval T.l0)) Rv.cap (X.rval header) ∧
      result = false) ∨
    (HeaderOK (C.attEpoch T.w) (I.entryOff (X.rval T.l0)) Rv.cap (X.rval header) ∧
      ((keyLen (X.rval lengths) ≠ input.size ∧ result = false) ∨
      (keyLen (X.rval lengths) = input.size ∧
        ((¬ LayoutOK (X.rval header) (X.rval lengths) ∧ result = false) ∨
        (LayoutOK (X.rval header) (X.rval lengths) ∧
          KeyScan input.size (fun p => X.rval (keyLoad p)) input.word seen
            0 ((input.size + 7) / 8) result)))))

/-- **RB3, concrete comparison including all early returns.** The
publication's W5 and lengths stores rule out invalid-header/layout exits.
A different length or first unequal word returns false; exhausting the
full words and optional masked tail returns true exactly for equal bytes.
No equality with `pubKey`, and no correctness of the comparison, is a
premise. This applies even when the containing attempt later rejects. -/
theorem table_bytes_exact (D : Contents I) (hc : X.Consistent)
    (T : TableReads I) (R : EntryRun T) :
    ∃ j w', C.isAttempt j w' ∧ (C.pins j).inAttempt w' T.z ∧
      C.attEpoch w' = C.attEpoch T.w ∧
      (∀ Y, T.loads Y → ∀ v, X.rf v Y → (C.pins j).inAttempt w' v) ∧
      (R.result = true ↔ SameBytes (D.key w') R.input) := by
  obtain ⟨j, w', hw, hx, he, hk, hsrc⟩ := table_prefix_sources hc T
  obtain ⟨z, hz⟩ := R.header_rf
  obtain ⟨l, hl, hr, hoff⟩ := R.header_address
  obtain ⟨hzW, _, hzl, _⟩ := hc.rf_wf hz
  rw [hc.rf_val T.read] at hoff
  have hhead := D.header_store hw hk hx (hsrc _ R.header_load z hz)
    hzW (hzl.trans hl) hr hoff
  rw [← hc.rf_val hz] at hhead
  have hroom := Rv.reserve_in_area hc (I.pub_off hk hw hx)
  have hneed := Rv.need_lens hw
  rw [← D.key_length hw, ← D.value_length hw] at hneed
  have hvalid : HeaderOK (C.attEpoch T.w) (I.entryOff (X.rval T.l0)) Rv.cap
      (X.rval R.header) := by
    refine ⟨he ▸ hhead.1, ?_, ?_⟩
    · rw [hhead.2]; omega
    · rw [hc.rf_val T.read, hhead.2]; omega
  obtain ⟨zl, hzlrf⟩ := R.lengths_rf (R.lengths_load hvalid)
  obtain ⟨ll, hll, hrl, hoffl⟩ := R.lengths_address (R.lengths_load hvalid)
  obtain ⟨hzlW, _, hzll, _⟩ := hc.rf_wf hzlrf
  rw [hc.rf_val T.read] at hoffl
  have hlens := D.lengths_store hw hk hx (hsrc _ (R.lengths_load hvalid) zl hzlrf)
    hzlW (hzll.trans hll) hrl hoffl
  rw [← hc.rf_val hzlrf] at hlens
  have hlayout : LayoutOK (X.rval R.header) (X.rval R.lengths) := by
    unfold LayoutOK
    rw [hlens, ← D.key_length hw, ← D.value_length hw, hhead.2]
  refine ⟨j, w', hw, hx, he, hsrc, ?_⟩
  rcases R.flow with hbad | ⟨_, hlen | ⟨hlen, hbad | ⟨_, scan⟩⟩⟩
  · exact False.elim (hbad.1 hvalid)
  · have hsize : (D.key w').size ≠ R.input.size := by
      rw [D.key_length hw, ← hlens]; exact hlen.1
    constructor
    · intro ht; rw [hlen.2] at ht; contradiction
    · intro hs; exact False.elim (hsize hs.1)
  · exact False.elim (hbad.1 hlayout)
  · have hsize : (D.key w').size = R.input.size := by
      rw [D.key_length hw, ← hlens]; exact hlen
    have hread : ∀ p, R.seen p → X.rval (R.keyLoad p) = (D.key w').word p := by
      intro p hp
      obtain ⟨zk, hzk⟩ := R.key_rf p hp
      obtain ⟨lk, hlk, hrk, hoffk⟩ := R.key_address p hp
      obtain ⟨hzkW, _, hzkl, _⟩ := hc.rf_wf hzk
      rw [hc.rf_val hzk]
      apply D.key_store hw hk hx (hsrc _ (R.key_load p hp) zk hzk)
        hzkW (hzkl.trans hlk) hrk
      · rw [hsize]; exact R.key_bounds p hp
      · rwa [hc.rf_val T.read] at hoffk
    rw [keyScan_exact scan hread, sameBytes_iff_words _ _ hsize, hsize]
    simp

/-- Whole copied words, including the final padding. -/
def SameWords (a b : Bytes) : Prop :=
  a.size = b.size ∧ ∀ p, p < (a.size + 7) / 8 → a.word p = b.word p

theorem sameBytes_refl (a : Bytes) : SameBytes a a := ⟨rfl, fun _ _ => rfl⟩

theorem sameBytes_symm {a b : Bytes} (h : SameBytes a b) : SameBytes b a :=
  ⟨h.1.symm, fun j hj => (h.2 j (by simpa [h.1] using hj)).symm⟩

theorem sameBytes_trans {a b c : Bytes} (h : SameBytes a b) (h' : SameBytes b c) :
    SameBytes a c := ⟨h.1.trans h'.1, fun j hj => (h.2 j hj).trans (h'.2 j (by simpa [h.1] using hj))⟩

theorem sameWords_trans {a b c : Bytes} (h : SameWords a b) (h' : SameWords b c) :
    SameWords a c := ⟨h.1.trans h'.1, fun p hp => (h.2 p hp).trans (h'.2 p (by simpa [h.1] using hp))⟩

theorem sameWords_bytes {a b : Bytes} (h : SameWords a b) : SameBytes a b := by
  apply (sameBytes_iff_words a b h.1).mpr
  intro p hp
  rw [h.2 p hp]

/-- F10 at the actual source-buffer level. `insert_src` describes the two
emission loops fed by one insert call. `promo_src` names the concrete
validated probe (including a possible reprobe), caller key, output buffer,
and the whole-word emission loop. It assumes no equality to the old
attempt: that equality is derived by `hit_bytes_exact`.

`callValue` is the caller value zero-padded by `emit_bytes_relaxed`;
`SameWords` records the padding that promotions preserve verbatim. -/
structure Sources (D : Contents I) where
  Call : Type
  occurred : Call → Prop
  callKey : Call → Bytes
  callValue : Call → Bytes
  promo : Ev → Prop
  insert_src : ∀ {i w}, C.isAttempt i w → ¬ promo w →
    ∃ call, occurred call ∧ SameBytes (D.key w) (callKey call) ∧
      SameWords (D.value w) (callValue call)
  promo_src : ∀ {i w}, C.isAttempt i w → promo w →
    ∃ (H : Hit I) (io : IndexProtocol.HitIO I H) (B : HitBytes H io),
      X.rval H.v1 = H.e + 1 ∧ C.attEpoch w = X.rval H.v1 ∧
      SameBytes (D.key w) B.input ∧ SameWords (D.value w) B.output

/-- **HT3 without abstract key/value names.** Every attempt's actual
record bytes come from one insert call; the value equality includes every
padded word. The promotion induction uses HT2's concrete key comparison
and copy theorem, including the reprobe path. -/
theorem bytes_provenance (D : Contents I) (S : Sources D) (hc : X.Consistent)
    {i w} (hw : C.isAttempt i w) :
    ∃ call, S.occurred call ∧ SameBytes (D.key w) (S.callKey call) ∧
      SameWords (D.value w) (S.callValue call) := by
  suffices all : ∀ n, ∀ {i w}, C.isAttempt i w → C.attEpoch w = n →
      ∃ call, S.occurred call ∧ SameBytes (D.key w) (S.callKey call) ∧
        SameWords (D.value w) (S.callValue call) from all _ hw rfl
  intro n
  induction n using Nat.strongRecOn with
  | ind n ih =>
    intro i w hw hn
    by_cases hp : S.promo w
    · obtain ⟨H, io, B, he, hwE, hkey, hvalue⟩ := S.promo_src hw hp
      obtain ⟨j, w0, hw0, _, hE, hk, hv, hwords⟩ := hit_bytes_exact D hc H io B
      obtain ⟨call, hcall, hk0, hv0⟩ := ih (C.attEpoch w0) (by omega) hw0 rfl
      exact ⟨call, hcall, sameBytes_trans (sameBytes_trans hkey hk) hk0,
        sameWords_trans (sameWords_trans hvalue ⟨hv.1, hwords⟩) hv0⟩
    · exact S.insert_src hw hp

/-- The executed W4 stores of one reservation encode this pair. The key
clause compares its logical bytes, masking only the last word's padding;
the value clause preserves every padded word. Quantifying over executed
events permits any interruption, including before the first W4. -/
def StoresPair (Rv : ReserveProtocol X C) (i : C.ι) (w : Ev) (r : Nat) (key value : Bytes) : Prop :=
  ∀ z l, (C.pins i).inAttempt w z → X.isW z → X.loc z = some l → Rv.isRecord l →
    (Rv.recOff l = (r + 8) % wordLimit →
      keyLen (X.wval z) = key.size ∧ valLen (X.wval z) = value.size) ∧
    (∀ p, p < (key.size + 7) / 8 →
      Rv.recOff l = (r + 16 + 8 * p) % wordLimit →
      X.wval z &&& mask key.size p = key.word p &&& mask key.size p) ∧
    (∀ p, p < (value.size + 7) / 8 →
      Rv.recOff l = (r + 16 + (key.size + 7) / 8 * 8 + 8 * p) % wordLimit →
      X.wval z = value.word p)

/-- **HT3, every attempt, including nonpublishers.** One actual insert
call supplies the pair for all W4 stores this attempt executes. There is
no I2, acceptance, completed emission, or future-event premise. For a
promotion, `bytes_provenance` derives the paired caller from the validated
hit and strictly earlier attempt, rather than assuming its provenance. -/
theorem attempt_stored_bytes_provenance (D : Contents I) (S : Sources D)
    (hc : X.Consistent) {i w} (hw : C.isAttempt i w) :
    ∃ call, S.occurred call ∧ SameBytes (D.key w) (S.callKey call) ∧
      SameWords (D.value w) (S.callValue call) ∧
      ∀ r, Rv.reserves i w r →
        StoresPair Rv i w r (S.callKey call) (S.callValue call) := by
  obtain ⟨call, hcall, hkey, hval⟩ := bytes_provenance D S hc hw
  refine ⟨call, hcall, hkey, hval, ?_⟩
  intro r hr z l hz hW hl hrec
  refine ⟨?_, ?_, ?_⟩
  · intro hoff
    rw [D.lengths_reserved hw hr hz hW hl hrec hoff]
    exact ⟨(D.key_length hw).symm.trans hkey.1,
      (D.value_length hw).symm.trans hval.1⟩
  · intro p hp hoff
    have hp' : p < ((D.key w).size + 7) / 8 := by simpa [hkey.1] using hp
    rw [D.key_reserved hw hr hz hW hl hrec hp' hoff, ← hkey.1]
    exact (sameBytes_iff_words _ _ hkey.1).mp hkey p hp'
  · intro p hp hoff
    have hp' : p < ((D.value w).size + 7) / 8 := by simpa [hval.1] using hp
    have hoff' : Rv.recOff l =
        (r + 16 + ((D.key w).size + 7) / 8 * 8 + 8 * p) % wordLimit := by
      simpa [hkey.1] using hoff
    exact (D.value_reserved hw hr hz hW hl hrec hp' hoff').trans (hval.2 p hp')

/-- Every actual record write has a successful reservation and the same
caller pair as all other writes in its attempt. The reservation is a
conclusion, not a premise restricting the theorem to successful inserts. -/
theorem every_record_write_provenance (D : Contents I) (S : Sources D)
    (hc : X.Consistent) {i w} (hw : C.isAttempt i w) :
    ∃ call, S.occurred call ∧
      ∀ z l, (C.pins i).inAttempt w z → X.isW z → X.loc z = some l →
        Rv.isRecord l → ∃ r, Rv.reserves i w r ∧
          StoresPair Rv i w r (S.callKey call) (S.callValue call) := by
  obtain ⟨call, hcall, _, _, hall⟩ := attempt_stored_bytes_provenance D S hc hw
  refine ⟨call, hcall, ?_⟩
  intro z l hz hW hl hrec
  obtain ⟨r, hr, _, _⟩ := Rv.rec_in_res hw hz hW hl hrec
  exact ⟨r, hr, hall r hr⟩

/-- B0 exits and failed B1 passes never reach a W4: `store()` only
emits after `reserve()` returns `Some((offset, epoch))`. No complete
record or successful reservation is asserted for these attempts. -/
theorem no_reservation_no_record_stores {i w} (hw : C.isAttempt i w)
    (hno : ∀ r, ¬ Rv.reserves i w r) {z l}
    (hz : (C.pins i).inAttempt w z) (hW : X.isW z)
    (hl : X.loc z = some l) (hrec : Rv.isRecord l) : False := by
  obtain ⟨r, hr, _, _⟩ := Rv.rec_in_res hw hz hW hl hrec
  exact hno r hr

/-- Published records carry the actual words of that same inserted pair.
The value-store conclusion ties provenance to an individual W4, rather
than only to an unconstrained abstract `attVal` name. -/
theorem published_bytes_provenance (D : Contents I) (S : Sources D) (hc : X.Consistent)
    {i w x} (hw : C.isAttempt i w) (hk : I.isPubCas x ∨ I.isPubStore x)
    (hx : (C.pins i).inAttempt w x) :
    ∃ call, S.occurred call ∧ SameBytes (D.key w) (S.callKey call) ∧
      SameWords (D.value w) (S.callValue call) ∧
      ∀ z l p, (C.pins i).inAttempt w z → X.isW z → X.loc z = some l →
        Rv.isRecord l → p < ((D.value w).size + 7) / 8 →
        Rv.recOff l = (I.entryOff (X.wval x) + 16 +
          ((D.key w).size + 7) / 8 * 8 + 8 * p) % wordLimit →
        X.wval z = (S.callValue call).word p := by
  obtain ⟨call, hcall, hkey, hval⟩ := bytes_provenance D S hc hw
  refine ⟨call, hcall, hkey, hval, ?_⟩
  intro z l p hz hzW hzl hr hp hoff
  exact (D.value_store hw hk hx hz hzW hzl hr hp hoff).trans (hval.2 p hp)

end RecordBytes
end Sgc.Execution
