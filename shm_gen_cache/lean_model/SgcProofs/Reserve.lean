import SgcProofs.Cache

/-!
# SP2 to SP5: the bump, the chunks, write-once records

`ReserveProtocol` adds to the cache what `reserve()` and `store()` do with
bytes (`store.rs`, `reserve()` and the loop of `store()`):

* the bump field of the control word, which each B1 advances by its claim,
  modulo `2^31` (the carry out of bit 63 is discarded, `ctl.rs:6-8`);
* the three chunk words of each slot, `chunk_epoch`, `chunk_cursor` and
  `chunk_end`, written by the registrant only: reset by every claim (H0),
  set by a B1 that claims a chunk (H2), and advanced by a reservation cut
  from it (H1);
* the record words, which an attempt stores once each, inside the byte
  range it reserved.

Proved here:

* `att_order` (with PN2): the store attempts of one slot are serial;
* `b1_loc`, `base_le`, `base_mo_b1` (SP1(b)): a B1 lies in `mo` after the
  base of the incarnation its precheck B0 read;
* `Inv`, `inv`, `no_wrap` (SP2(b)): the bump is what the B1s claimed, and no
  B1 carries out of the bump field;
* `fail_blocks`, `fail_last`, `fail_once` (SP2(a)): at most one B1 per slot
  fails in an incarnation;
* `b1_disjoint` (SP4): the byte ranges claimed by two B1s of one
  incarnation are disjoint;
* `chunk_source`, `epoch_source` (SP3): the three loads H1 makes read the
  stores of the chunk's own claim H2, or, for the cursor, of the last cut
  from it;
* `reserve_in_area`, `reserve_disjoint` (SP4): every reservation lies in
  the record area, and reservations of distinct attempts of one incarnation
  are disjoint;
* `record_write_once` (SP5): two stores to one record word by attempts of
  one incarnation are the same store. `hit_integrity` (`Lookup.lean`) uses it
  in place of a hypothesis.

The chunk words are not ordered by any synchronisation of their own: every
access is relaxed and by the registrant, so SL1 and A2 order them (`not_early`,
`C.att_between`). A chunk-epoch load that returns the attempt's epoch reads a
chunk claim's store (`epoch_source`), made by the same registration, because
every registration resets `chunk_epoch` (H0) before it first pins (code fact
F4; only the `chunk_epoch` reset is needed). No other chunk
claim lies between, so the end load reads that claim's end, and the cursor
load reads its cursor or the cursor of the last cut; by induction over `mo`
the cursor stays in `[bump + n, end]`, which also makes the `u32`
`wrapping_sub` room check exact (`cut_arith`, with A5 bounding the area below
`2^31`).

The bound of SP2 is the argument of `reservation_adds_fit_bump()`
(`config.rs:228-256`): after the last success, by a slot `X`, only
failures follow, one per slot; they add at most one claim each, and if `X`
fails too, its own precheck saw room for that claim after its success.
-/

namespace Sgc
namespace Execution

variable {Ev Loc : Type}

/-- The bump field is 31 bits wide: bits 33 to 63 of the control word
(`ctl.rs:6`). -/
def bumpLimit : Nat := 2147483648

/-- The cursor and end of a chunk are `u32`s (`participant.rs:140-141`). -/
def wordLimit : Nat := 4294967296

/-- The key length of a record's lengths word, its low 32 bits
(`records.rs:62-64`). -/
def keyLen (v : Nat) : Nat := v % wordLimit

/-- The value length of a record's lengths word, its high 32 bits
(`records.rs:68-70`). -/
def valLen (v : Nat) : Nat := v / wordLimit % wordLimit

/-- What `reserve()` and `store()` do with bytes, with the lines of
`store.rs`, `participant.rs`, `rotation.rs` and `config.rs` that do it. -/
structure ReserveProtocol (X : Execution Ev Loc) (C : CacheProtocol X) where
  /-- `record_area_size`, the bytes of an arena's record area. -/
  cap : Nat
  /-- `chunk_size`. -/
  chunk : Nat
  /-- The largest claim, the larger of a chunk and a maximal record
  (`config.rs:249-253`). -/
  maxClaim : Nat
  /-- The bump field of a control-word value. -/
  bump : Nat → Nat
  /-- The bytes a store attempt reserves, `record_size(len)`
  (`store.rs:262`). -/
  need : Ev → Nat
  /-- The bytes a B1 adds to the bump (`store.rs:321-325`). -/
  claim : Ev → Nat
  /-- The slots, each listed once: `participant_capacity` of them. -/
  slots : List C.ι
  slots_nodup : slots.Nodup
  slots_all : ∀ i, i ∈ slots
  /-- The lengths word an attempt stores, `RecordLengths::new(key.len(),
  value.len())` (`store.rs:137`). -/
  lens : Ev → Nat
  /-- The bytes reserved are `record_size(len)`, `8 + align8(len)`, for the
  header's `len = payload_size()`, `8 + align8(key_len) + value_len`
  (`store.rs:138`, `262`; `records.rs:45-47`, `75-78`). The `u32`
  arithmetic of `payload_size()` does not wrap: keys and values are at most
  `max_key_size` and `max_value_size` (`store.rs:90-95`; a promotion's key
  `lookup.rs:56`, its value `lookup.rs:154`), and the
  largest record is at most the record area, below `2^31` (`config.rs:116`,
  `123-133`). -/
  need_lens : ∀ {i w}, C.isAttempt i w →
    need w = 8 + (8 + (keyLen (lens w) + 7) / 8 * 8 + valLen (lens w) + 7) / 8 * 8
  /-- `insert()` rejects larger keys and values (`store.rs:90-95`), and a
  promotion bounds its key (`lookup.rs:56`) and its value (`lookup.rs:154`):
  every record is at most `max_record_size`. -/
  need_le : ∀ {i w}, C.isAttempt i w → need w ≤ maxClaim
  chunk_le : chunk ≤ maxClaim
  /-- A5: `reservation_adds_fit_bump()` (`config.rs:241-256`). -/
  a5 : cap + slots.length * maxClaim < bumpLimit
  /-- An event belongs to at most one store attempt: an attempt is one pass
  of the loop of one `store()` call (`store.rs:139-204`, `263-360`), calls
  do not nest, and a registration acts on one thread (A2). -/
  att_unique : ∀ {i j w w' x}, C.isAttempt i w → C.isAttempt j w' →
    (C.pins i).inAttempt w x → (C.pins j).inAttempt w' x → i = j ∧ w = w'
  /-- A pin store W2 is a store to its own slot's `pinned_epoch`
  (`store.rs:272`), and each slot has its own (`participant.rs:134`). -/
  att_slot : ∀ {i j w}, C.isAttempt i w → C.isAttempt j w → i = j
  -- The bump.
  /-- `initialize()` stores a zero bump (`cache/mod.rs:245`). -/
  ctlInit_bump : ∀ {x}, C.R.isCtlInit x → bump (X.wval x) = 0
  /-- R4 stores a zero bump (`rotation.rs:193-195`). -/
  r4_bump : ∀ {A x}, C.R.r4Of A x → bump (X.wval x) = 0
  /-- R1 sets the sealed bit and keeps the bump (`rotation.rs:115-120`). -/
  seal_bump : ∀ {A Z}, C.R.sealOf A Z → ∃ w, X.rf w Z ∧ bump (X.wval Z) = bump (X.wval w)
  /-- B1 adds its claim to the bump; the carry out of bit 63 is discarded
  (`store.rs:327-331`, `ctl.rs:6-8`). -/
  b1_bump : ∀ {b}, C.R.isB1 b → bump (X.wval b) = (bump (X.rval b) + claim b) % bumpLimit
  /-- B1 is made by a store attempt: after a precheck that saw its
  incarnation unsealed (`store.rs:286-331`). -/
  b1_att : ∀ {b}, C.R.isB1 b → ∃ i w, C.isAttempt i w ∧ (C.pins i).inAttempt w b
  /-- An attempt makes at most one B1: it returns or unpins after it
  (`store.rs:332-352`). -/
  b1_unique : ∀ {i w b b'}, C.isAttempt i w → (C.pins i).inAttempt w b →
    (C.pins i).inAttempt w b' → C.R.isB1 b → C.R.isB1 b' → b = b'
  /-- The precheck B0 before B1, after W2: a load of the epoch's control word
  that returned an unsealed value with room for the record; the claim is a
  chunk when the record is smaller and a chunk fits, the record otherwise
  (`store.rs:272`, `286-313`, `321-325`). -/
  b1_b0 : ∀ {i w b}, C.isAttempt i w → (C.pins i).inAttempt w b → C.R.isB1 b →
    ∃ l u, X.sb w l ∧ X.sb l b ∧ X.rf u l ∧ X.loc l = some (C.R.ctlLoc (C.attEpoch w)) ∧
      ¬ C.R.isSealed (X.wval u) ∧ bump (X.wval u) + need w ≤ cap ∧
      claim b = if need w < chunk ∧ bump (X.wval u) + chunk ≤ cap then chunk else need w
  -- The chunk words of each slot.
  ceLoc : C.ι → Loc
  ccLoc : C.ι → Loc
  cnLoc : C.ι → Loc
  /-- The initialisation of a chunk word (`participant.rs:182`). -/
  isChunkInit : Ev → Prop
  chunkInit_w : ∀ {x}, isChunkInit x → X.isW x
  /-- `initialize()` zeroes the chunk words (`participant.rs:182`,
  `190-192`). -/
  chunkInit_val : ∀ {x}, isChunkInit x → X.wval x = 0
  /-- Assumption A1. -/
  chunkInit_first : ∀ {x e}, isChunkInit x → X.isW e → X.loc e = X.loc x → e ≠ x → X.hb x e
  /-- `resetOf i c x`: `x` is a store of `reset_chunk()` (H0) by registration
  `c` of slot `i`, storing zero (`participant.rs:185-193`). -/
  resetOf : C.ι → Ev → Ev → Prop
  reset_act : ∀ {i c x}, resetOf i c x → (C.slot i).act c x
  reset_val : ∀ {i c x}, resetOf i c x → X.wval x = 0
  /-- `claim()` resets before P2 (`participant.rs:213`), so before every
  W2 of the registration; a steal resets after P6 and before returning
  (`participant.rs:346`), so before any W2 of a registrar that keeps the
  stolen slot (`reap_dead_participant()`). -/
  reset_before : ∀ {i c x w}, resetOf i c x → (C.pins i).w2Of c w → X.sb x w
  /-- A registration that pins has reset `chunk_epoch`. -/
  reset_ce : ∀ {i c w}, (C.pins i).w2Of c w →
    ∃ x, resetOf i c x ∧ X.isW x ∧ X.loc x = some (ceLoc i)
  /-- `chunkOf i w b xe xc xn`: attempt `w`, whose B1 `b` succeeded with a
  claim larger than its record, stores the chunk (H2): epoch, cursor past the
  record, and end clipped to the area (`store.rs:332-340`). -/
  chunkOf : C.ι → Ev → Ev → Ev → Ev → Ev → Prop
  chunk_b1 : ∀ {i w b xe xc xn}, chunkOf i w b xe xc xn →
    C.isAttempt i w ∧ (C.pins i).inAttempt w b ∧ C.R.isB1 b ∧
      (¬ C.R.isSealed (X.rval b) ∧ bump (X.rval b) + need w ≤ cap) ∧ need w < claim b
  chunk_ev : ∀ {i w b xe xc xn}, chunkOf i w b xe xc xn →
    X.sb w xe ∧ X.sb w xc ∧ X.sb w xn ∧ (C.pins i).inAttempt w xe ∧
      (C.pins i).inAttempt w xc ∧ (C.pins i).inAttempt w xn ∧
      X.isW xe ∧ X.isW xc ∧ X.isW xn ∧
      X.loc xe = some (ceLoc i) ∧ X.loc xc = some (ccLoc i) ∧ X.loc xn = some (cnLoc i) ∧
      X.wval xe = C.attEpoch w ∧ X.wval xc = (bump (X.rval b) + need w) % wordLimit ∧
      X.wval xn = min (bump (X.rval b) + claim b) cap
  /-- An attempt stores at most one chunk. -/
  chunk_unique : ∀ {i w b xe xc xn b' xe' xc' xn'}, chunkOf i w b xe xc xn →
    chunkOf i w b' xe' xc' xn' → xe = xe' ∧ xc = xc' ∧ xn = xn'
  /-- `useOf i w le lc ln s`: attempt `w` reserves from its slot's chunk (H1). It
  loads the epoch, the cursor and the end, finds the epoch its own and
  `end - cursor`, in wrapping `u32` arithmetic, at least its record, and
  stores the cursor advanced by the record (`store.rs:303-308`). -/
  useOf : C.ι → Ev → Ev → Ev → Ev → Ev → Prop
  use_ev : ∀ {i w le lc ln s}, useOf i w le lc ln s →
    C.isAttempt i w ∧ X.sb w le ∧ X.sb w lc ∧ X.sb w ln ∧ X.sb w s ∧ X.sb lc s ∧
      (C.pins i).inAttempt w le ∧ (C.pins i).inAttempt w lc ∧ (C.pins i).inAttempt w ln ∧
      (C.pins i).inAttempt w s ∧ X.isW s ∧
      X.loc le = some (ceLoc i) ∧ X.loc lc = some (ccLoc i) ∧ X.loc ln = some (cnLoc i) ∧
      X.loc s = some (ccLoc i) ∧
      (∃ x, X.rf x le) ∧ (∃ x, X.rf x lc) ∧ (∃ x, X.rf x ln) ∧
      X.rval le = C.attEpoch w ∧ need w ≤ (X.rval ln + wordLimit - X.rval lc) % wordLimit ∧
      X.wval s = (X.rval lc + need w) % wordLimit
  /-- A chunk reservation returns before B1 (`store.rs:308`). -/
  use_no_b1 : ∀ {i w le lc ln s b}, useOf i w le lc ln s → (C.pins i).inAttempt w b →
    ¬ C.R.isB1 b
  /-- An attempt reserves from its chunk at most once: the chunk branch
  returns (`store.rs:303-308`). -/
  use_unique : ∀ {i w le lc ln s le' lc' ln' s'}, useOf i w le lc ln s →
    useOf i w le' lc' ln' s' → le = le' ∧ lc = lc' ∧ ln = ln' ∧ s = s'
  /-- Only these write `chunk_epoch`, ... -/
  ce_writes : ∀ {i x}, X.isW x → X.loc x = some (ceLoc i) →
    isChunkInit x ∨ (∃ c, resetOf i c x) ∨ ∃ w b xc xn, chunkOf i w b x xc xn
  /-- ... `chunk_cursor` ... -/
  cc_writes : ∀ {i x}, X.isW x → X.loc x = some (ccLoc i) →
    isChunkInit x ∨ (∃ c, resetOf i c x) ∨ (∃ w b xe xn, chunkOf i w b xe x xn) ∨
      ∃ w le lc ln, useOf i w le lc ln x
  /-- ... and `chunk_end`. -/
  cn_writes : ∀ {i x}, X.isW x → X.loc x = some (cnLoc i) →
    isChunkInit x ∨ (∃ c, resetOf i c x) ∨ ∃ w b xe xc, chunkOf i w b xe xc x
  -- The record words.
  /-- A record word of some arena. -/
  isRecord : Loc → Prop
  /-- The byte offset of a record word in its arena's record area. -/
  recOff : Loc → Nat
  /-- The arena of a record word, named by its control word. -/
  recArena : Loc → Loc
  /-- A record word is the word at its byte offset of its arena's record
  area (`ArenaView::word`, `arena/mod.rs:125-134`; `words_at`, `138-146`). -/
  rec_ext : ∀ {l l'}, isRecord l → isRecord l' → recArena l = recArena l' →
    recOff l = recOff l' → l = l'
  /-- An attempt stores record words only inside the bytes it reserved: at
  the offset B1 returned when it won, or at the cursor it loaded
  (`store.rs:142-158`, `303-308`, `332-342`). -/
  rec_in_res : ∀ {i w x l}, C.isAttempt i w → (C.pins i).inAttempt w x → X.isW x →
    X.loc x = some l → isRecord l →
    ∃ r, ((∃ b, (C.pins i).inAttempt w b ∧ C.R.isB1 b ∧
            (¬ C.R.isSealed (X.rval b) ∧ bump (X.rval b) + need w ≤ cap) ∧
            r = bump (X.rval b)) ∨
          (∃ le lc ln s, useOf i w le lc ln s ∧ r = X.rval lc)) ∧
      r ≤ recOff l ∧ recOff l + 8 ≤ r + need w
  /-- An attempt stores each word of its record once: the lengths, the key
  and value words, then the header (`store.rs:152-158`). -/
  rec_once : ∀ {i w x x' l}, C.isAttempt i w → (C.pins i).inAttempt w x →
    (C.pins i).inAttempt w x' → X.isW x → X.isW x' → X.loc x = some l → X.loc x' = some l →
    isRecord l → x = x'

namespace ReserveProtocol

variable {X : Execution Ev Loc} {C : CacheProtocol X} (Rv : ReserveProtocol X C)

/-- A record has a header and a lengths word: `record_size(len)` is at
least 16 bytes. -/
theorem need_pos {i : C.ι} {w : Ev} (hw : C.isAttempt i w) : 16 ≤ Rv.need w := by
  rw [Rv.need_lens hw]; omega

end ReserveProtocol

/-! ## The attempts of one slot are serial -/

namespace CacheProtocol

variable {X : Execution Ev Loc} (C : CacheProtocol X)

/-- Everything attempt `w` does happens before everything attempt `w'` does
after its pin. -/
def attBefore (i : C.ι) (w w' : Ev) : Prop :=
  ∀ a a', (C.pins i).inAttempt w a → X.hb w' a' → X.hb a a'

/-- Two store attempts of one slot are ordered (SL1 and PN2). -/
theorem att_order (hc : X.Consistent) {i : C.ι} {w w' : Ev}
    (hw : C.isAttempt i w) (hw' : C.isAttempt i w') (hne : w ≠ w') :
    C.attBefore i w w' ∨ C.attBefore i w' w := by
  obtain ⟨c, h2⟩ := C.att_w2 hw
  obtain ⟨c', h2'⟩ := C.att_w2 hw'
  have hn : (C.pins i).nonAnn w := Or.inl ⟨c, h2⟩
  have hn' : (C.pins i).nonAnn w' := Or.inl ⟨c', h2'⟩
  have key : ∀ {v v' d d'}, (C.pins i).w2Of d v → (C.pins i).w2Of d' v' → X.hb v v' →
      C.attBefore i v v' := by
    intro v v' d d' hv hv' h a a' ha ha'
    have hmo := coh_ww_at hc ((C.pins i).w2_w hv) ((C.pins i).w2_loc hv)
      ((C.pins i).w2_w hv') ((C.pins i).w2_loc hv') h
    rcases (C.pins i).attempt_before hc hv (Or.inl ⟨d', hv'⟩) hmo ha with heq | h'
    · rw [heq]; exact ha'
    · exact hb_trans h' ha'
  rcases (C.pins i).nonAnn_serial hc hn hn' with heq | h | h
  · exact absurd heq hne
  · exact Or.inl (key h2 h2' h)
  · exact Or.inr (key h2' h2 h)

/-- A B1 is on its epoch's control word. -/
theorem b1_loc {i : C.ι} {w b : Ev} (hw : C.isAttempt i w)
    (hb : (C.pins i).inAttempt w b) (hb1 : C.R.isB1 b) :
    X.isW b ∧ X.loc b = some (C.R.ctlLoc (C.attEpoch w)) :=
  let ⟨h₁, h₂, _⟩ := C.b1_after_b0 hw hb hb1; ⟨h₁, h₂⟩

end CacheProtocol

namespace ReserveProtocol

variable {X : Execution Ev Loc} {C : CacheProtocol X} (Rv : ReserveProtocol X C)

/-- The success test of B1 (`store.rs:332`); the stamp half holds by
`b1_lands`. -/
def wins (w b : Ev) : Prop :=
  ¬ C.R.isSealed (X.rval b) ∧ Rv.bump (X.rval b) + Rv.need w ≤ Rv.cap

/-- `reserves i w r`: attempt `w` of slot `i` holds the bytes
`[r, r + need w)`, won at B1 or cut from its slot's chunk. -/
def reserves (i : C.ι) (w : Ev) (r : Nat) : Prop :=
  (∃ b, (C.pins i).inAttempt w b ∧ C.R.isB1 b ∧ Rv.wins w b ∧ r = Rv.bump (X.rval b)) ∨
  (∃ le lc ln s, Rv.useOf i w le lc ln s ∧ r = X.rval lc)

/-- What the claim of a B1 satisfies, and the precheck it follows. -/
theorem claim_facts {i : C.ι} {w b : Ev} (hw : C.isAttempt i w)
    (hb : (C.pins i).inAttempt w b) (hb1 : C.R.isB1 b) :
    Rv.need w ≤ Rv.claim b ∧ Rv.claim b ≤ Rv.maxClaim ∧
    ∃ l u, X.sb w l ∧ X.sb l b ∧ X.rf u l ∧ X.loc l = some (C.R.ctlLoc (C.attEpoch w)) ∧
      ¬ C.R.isSealed (X.wval u) ∧ Rv.bump (X.wval u) + Rv.need w ≤ Rv.cap ∧
      Rv.bump (X.wval u) + Rv.claim b ≤ Rv.cap := by
  obtain ⟨l, u, hwl, hlb, hul, hll, hus, hroom, hcl⟩ := Rv.b1_b0 hw hb hb1
  have hn := Rv.need_le hw
  have hc := Rv.chunk_le
  split at hcl <;>
    exact ⟨by omega, by omega, l, u, hwl, hlb, hul, hll, hus, hroom, by omega⟩

include Rv in
/-- A B1 happens after its attempt's pin. -/
theorem b1_after_w2 {i : C.ι} {w b : Ev} (hw : C.isAttempt i w)
    (hb : (C.pins i).inAttempt w b) (hb1 : C.R.isB1 b) : X.hb w b := by
  obtain ⟨l, _, hwl, hlb, _⟩ := Rv.b1_b0 hw hb hb1
  exact hb_trans (hb_of_sb hwl) (hb_of_sb hlb)

include Rv in
/-- Of two B1s of one slot, the one earlier in `mo` happens before
everything the other's attempt does after its pin. -/
theorem b1_before (hc : X.Consistent) {σ : C.ι} {w w' z y a : Ev}
    (hw : C.isAttempt σ w) (hw' : C.isAttempt σ w') (hz : (C.pins σ).inAttempt w z)
    (hzB : C.R.isB1 z) (hy : (C.pins σ).inAttempt w' y) (hyB : C.R.isB1 y)
    (hzy : X.mo z y) (ha : X.hb w' a) : X.hb z a := by
  have hne : w ≠ w' := by
    intro heq
    subst heq
    have := Rv.b1_unique hw hz hy hzB hyB
    subst this
    exact hc.mo_irrefl _ hzy
  rcases C.att_order hc hw hw' hne with h | h
  · exact h z a hz ha
  · exfalso
    have hyz : X.hb y z := h y z hy (Rv.b1_after_w2 hw hz hzB)
    have ⟨hzW, hyW, hl, hs⟩ := hc.mo_wf hzy
    exact hc.mo_irrefl _ (hc.mo_trans hzy (coh_ww hc hyW hzW hl.symm (hl ▸ hs) hyz))

end ReserveProtocol

/-! ## The incarnations of a control word -/

/-- At or before, in `mo`, then before. -/
theorem moLe_mo {X : Execution Ev Loc} (hc : X.Consistent) {a b c : Ev}
    (h₁ : a = b ∨ X.mo a b) (h₂ : X.mo b c) : X.mo a c := by
  rcases h₁ with rfl | h
  · exact h₂
  · exact hc.mo_trans h h₂

/-- At or before, twice. -/
theorem moLe_trans {X : Execution Ev Loc} (hc : X.Consistent) {a b c : Ev}
    (h₁ : a = b ∨ X.mo a b) (h₂ : b = c ∨ X.mo b c) : a = c ∨ X.mo a c := by
  rcases h₂ with rfl | h
  · exact h₁
  · exact Or.inr (moLe_mo hc h₁ h)

/-- What precedes a read-modify-write precedes, or is, the write it read. -/
theorem le_pred {X : Execution Ev Loc} (hc : X.Consistent) {z y y' : Ev}
    (hrf : X.rf y' y) (h : X.mo z y) : z = y' ∨ X.mo z y' := by
  have ⟨hzW, _, hl, _⟩ := hc.mo_wf h
  have ⟨_, _, hl', _⟩ := hc.rf_wf hrf
  exact rmw_pred_ge hc hrf hzW (hl.trans hl'.symm) h

namespace CacheProtocol

variable {X : Execution Ev Loc} (C : CacheProtocol X)

/-- A control-word value that starts an incarnation: the initialisation or a
reset. -/
def isBase (b : Ev) : Prop := C.R.isCtlInit b ∨ ∃ A, C.R.r4Of A b

/-- `seg b y`: `y` is `b`, or follows it in `mo` with only `fetch_add`s and
seals from `b` to `y`. So `b` starts the incarnation `y` belongs to. -/
def seg (b y : Ev) : Prop :=
  (b = y ∨ X.mo b y) ∧
    ∀ z, X.mo b z → (z = y ∨ X.mo z y) → C.R.isB1 z ∨ ∃ A, C.R.sealOf A z

theorem seg_rf (hc : X.Consistent) {b y y' : Ev} (hs : C.seg b y) (hby : X.mo b y)
    (hrf : X.rf y' y) : C.seg b y' ∧ X.mo y' y := by
  have ⟨_, hyW, _, _⟩ := hc.mo_wf hby
  have hy'y := hc.rmw_mo hrf hyW
  exact ⟨⟨le_pred hc hrf hby, fun z hbz hzy' => hs.2 z hbz (Or.inr (moLe_mo hc hzy' hy'y))⟩,
    hy'y⟩

theorem seg_mono (hc : X.Consistent) {b y u : Ev} (hs : C.seg b y) (hbu : b = u ∨ X.mo b u)
    (huy : u = y ∨ X.mo u y) : C.seg b u :=
  ⟨hbu, fun z hbz hzu => hs.2 z hbz (moLe_trans hc hzu huy)⟩

/-- Every control-word write belongs to an incarnation; a `fetch_add`
strictly follows the start of its own. -/
theorem seg_exists (hc : X.Consistent) (y : Ev) :
    X.isW y → (∃ n, X.loc y = some (C.R.ctlLoc n)) →
    ∃ b, C.isBase b ∧ C.seg b y ∧ (C.R.isB1 y → X.mo b y) := by
  refine hc.mo_wellFounded.induction
    (C := fun y => X.isW y → (∃ n, X.loc y = some (C.R.ctlLoc n)) →
      ∃ b, C.isBase b ∧ C.seg b y ∧ (C.R.isB1 y → X.mo b y)) y ?_
  intro y ih hW ⟨n, hl⟩
  have self : C.seg y y :=
    ⟨Or.inl rfl, fun z h₁ h₂ => by
      rcases h₂ with rfl | h
      · exact (hc.mo_irrefl _ h₁).elim
      · exact (hc.mo_irrefl _ (hc.mo_trans h₁ h)).elim⟩
  -- A read-modify-write belongs to the incarnation of the write it read.
  have step : ∀ y', X.rf y' y → (C.R.isB1 y ∨ ∃ A, C.R.sealOf A y) →
      ∃ b, C.isBase b ∧ C.seg b y ∧ X.mo b y := by
    intro y' hrf hk
    have ⟨hy'W, _, hly', _⟩ := hc.rf_wf hrf
    have hy'y := hc.rmw_mo hrf hW
    obtain ⟨b, hb, hs, _⟩ := ih y' hy'y hy'W ⟨n, hly'.trans hl⟩
    have hby : X.mo b y := moLe_mo hc hs.1 hy'y
    refine ⟨b, hb, ⟨Or.inr hby, fun z hbz hzy => ?_⟩, hby⟩
    rcases hzy with rfl | h
    · exact hk
    · exact hs.2 z hbz (le_pred hc hrf h)
  by_cases hb1 : C.R.isB1 y
  · obtain ⟨y', hrf, _⟩ := C.R.b1_rmw hb1
    obtain ⟨b, hb, hs, hby⟩ := step y' hrf (Or.inl hb1)
    exact ⟨b, hb, hs, fun _ => hby⟩
  · rcases C.R.ctl_writes hW hl with hinit | hb1' | ⟨A, hZ⟩ | ⟨A, h4⟩
    · exact ⟨y, Or.inl hinit, self, fun h => absurd h hb1⟩
    · exact absurd hb1' hb1
    · obtain ⟨y', hrf, _⟩ := C.seal_rmw hZ
      obtain ⟨b, hb, hs, _⟩ := step y' hrf (Or.inr ⟨A, hZ⟩)
      exact ⟨b, hb, hs, fun h => absurd h hb1⟩
    · exact ⟨y, Or.inr ⟨A, h4⟩, self, fun h => absurd h hb1⟩

/-- The start of an incarnation precedes every value an attempt for that
incarnation loads after its pin (SP1). -/
theorem base_le (hc : X.Consistent) {i : C.ι} {w b l u : Ev} (hb : C.isBase b)
    (hbW : X.isW b) (hE : C.R.stamp (X.wval b) = C.attEpoch w) (hw : C.isAttempt i w)
    (hwl : X.sb w l) (hul : X.rf u l) (hloc : X.loc b = X.loc l) : b = u ∨ X.mo b u := by
  rcases hb with hinit | ⟨B, h4⟩
  · have ⟨huW, _, hlu, hsu⟩ := hc.rf_wf hul
    have hsome : X.loc b ≠ none := by rw [hloc, ← hlu]; exact hsu
    rcases hc.mo_total hbW huW (hloc.trans hlu.symm) hsome with h | h | h
    · exact Or.inl h
    · exact Or.inr h
    · exact (C.not_mo_ctlInit hc hinit h).elim
  · obtain ⟨q, hq, hst⟩ := C.R.r4_r0 h4
    have h₁ : X.hb b l := C.r4_before_att hc hw (Or.inr hwl) h4 hq (hst.symm.trans hE)
    rcases coh_wr hc hbW hloc h₁ hul with h | h
    · exact Or.inl h.symm
    · exact Or.inr h

end CacheProtocol

namespace ReserveProtocol

variable {X : Execution Ev Loc} {C : CacheProtocol X} (Rv : ReserveProtocol X C)

/-- `f` is a B1 of slot `i` that failed. -/
def Fails (i : C.ι) (f : Ev) : Prop :=
  ∃ w, C.isAttempt i w ∧ (C.pins i).inAttempt w f ∧ C.R.isB1 f ∧ ¬ Rv.wins w f

/-- `s` is a B1 of slot `i` that succeeded. -/
def Wins (i : C.ι) (s : Ev) : Prop :=
  ∃ w, C.isAttempt i w ∧ (C.pins i).inAttempt w s ∧ C.R.isB1 s ∧ Rv.wins w s

/-- The bytes a list of B1s claimed. -/
def claims (F : List (C.ι × Ev)) : Nat := (F.map fun p => Rv.claim p.2).sum

theorem claims_cons (p : C.ι × Ev) (F : List (C.ι × Ev)) :
    Rv.claims (p :: F) = Rv.claim p.2 + Rv.claims F := by
  simp [claims]

theorem claims_le {F : List (C.ι × Ev)} (h : ∀ p ∈ F, Rv.claim p.2 ≤ Rv.maxClaim) :
    Rv.claims F ≤ F.length * Rv.maxClaim := by
  induction F with
  | nil => simp [claims]
  | cons q F ih =>
    rw [Rv.claims_cons, List.length_cons, Nat.succ_mul]
    have h₁ := h q (List.mem_cons.mpr (Or.inl rfl))
    have h₂ := ih (fun p hp => h p (List.mem_cons.mpr (Or.inr hp)))
    omega

theorem claims_le_mem {F : List (C.ι × Ev)} {p : C.ι × Ev} (hp : p ∈ F)
    (h : ∀ p ∈ F, Rv.claim p.2 ≤ Rv.maxClaim) :
    Rv.claims F + Rv.maxClaim ≤ Rv.claim p.2 + F.length * Rv.maxClaim := by
  induction F with
  | nil => cases hp
  | cons q F ih =>
    rw [Rv.claims_cons, List.length_cons, Nat.succ_mul]
    have hF : ∀ p ∈ F, Rv.claim p.2 ≤ Rv.maxClaim :=
      fun p hp => h p (List.mem_cons.mpr (Or.inr hp))
    rcases List.mem_cons.mp hp with rfl | hp'
    · have := Rv.claims_le hF
      omega
    · have h₁ := ih hp' hF
      have h₂ := h q (List.mem_cons.mpr (Or.inl rfl))
      omega

/-- A list of distinct slots is no longer than the list of all slots. -/
theorem slots_le {l : List C.ι} (h : l.Nodup) : l.length ≤ Rv.slots.length :=
  h.length_le_of_subset (fun i _ => Rv.slots_all i)

/-- What holds of every value `y` of the incarnation that `b` starts, up to
`y` in `mo`:

* it keeps `b`'s stamp; the bump never decreases and the sealed bit is never
  cleared;
* no B1 has carried out of the bump field;
* the bump is what the B1s claimed. `F` lists the B1s that failed after the
  last success `s`, by slot `i` (or after `b` if there is none yet), one per
  slot; and if `i` failed again, its failure claimed no more than room
  left after `s`. -/
structure Inv (b y : Ev) : Prop where
  stamp : C.R.stamp (X.wval y) = C.R.stamp (X.wval b)
  mono : ∀ z, (b = z ∨ X.mo b z) → (z = y ∨ X.mo z y) →
    Rv.bump (X.wval z) ≤ Rv.bump (X.wval y)
  sealed : ∀ z, (b = z ∨ X.mo b z) → (z = y ∨ X.mo z y) →
    C.R.isSealed (X.wval z) → C.R.isSealed (X.wval y)
  exact : ∀ z, X.mo b z → (z = y ∨ X.mo z y) → C.R.isB1 z →
    Rv.bump (X.wval z) = Rv.bump (X.rval z) + Rv.claim z
  count : ∃ F : List (C.ι × Ev), (F.map Prod.fst).Nodup ∧
    (∀ p ∈ F, Rv.Fails p.1 p.2 ∧ X.mo b p.2 ∧ (p.2 = y ∨ X.mo p.2 y)) ∧
    (Rv.bump (X.wval y) = Rv.claims F ∨
     ∃ i s, Rv.Wins i s ∧ X.mo b s ∧ (s = y ∨ X.mo s y) ∧
       Rv.bump (X.wval s) + 1 ≤ Rv.cap + Rv.maxClaim ∧
       (∀ p ∈ F, p.1 = i → Rv.bump (X.wval s) + Rv.claim p.2 ≤ Rv.cap) ∧
       Rv.bump (X.wval y) = Rv.bump (X.wval s) + Rv.claims F)

theorem inv_base (hc : X.Consistent) {b : Ev} (hb : C.isBase b) : Rv.Inv b b where
  stamp := rfl
  mono := fun z h₁ h₂ => by rw [CacheProtocol.between_self hc h₁ h₂]; exact Nat.le_refl _
  sealed := fun z h₁ h₂ hs => by rw [CacheProtocol.between_self hc h₁ h₂] at hs; exact hs
  exact := fun z h₁ h₂ _ => (hc.mo_irrefl _ (moLe_mo hc (Or.inr h₁) (by
    rcases h₂ with rfl | h
    · exact h₁
    · exact h))).elim
  count := by
    refine ⟨[], List.nodup_nil, (fun p hp => by cases hp), Or.inl ?_⟩
    show Rv.bump (X.wval b) = 0
    rcases hb with hi | ⟨A, h4⟩
    · exact Rv.ctlInit_bump hi
    · exact Rv.r4_bump h4

theorem inv_seal (hc : X.Consistent) {b y y' A : Ev} (hZ : C.R.sealOf A y)
    (hnb : ¬ C.R.isB1 y) (hrf : X.rf y' y) (hy'y : X.mo y' y) (I' : Rv.Inv b y') :
    Rv.Inv b y := by
  obtain ⟨y₁, hrf₁, hst⟩ := C.seal_rmw hZ
  obtain ⟨y₂, hrf₂, hbm⟩ := Rv.seal_bump hZ
  cases hc.rf_unique hrf₁ hrf
  cases hc.rf_unique hrf₂ hrf
  obtain ⟨F, hnd, hF, hstate⟩ := I'.count
  refine ⟨hst.trans I'.stamp, fun z h₁ h₂ => ?_, fun _ _ _ _ => C.R.seal_sealed hZ,
    fun z h₁ h₂ hz => ?_, F, hnd, fun p hp => ?_, ?_⟩
  · rcases h₂ with rfl | h
    · exact Nat.le_refl _
    · rw [hbm]; exact I'.mono z h₁ (le_pred hc hrf h)
  · rcases h₂ with rfl | h
    · exact absurd hz hnb
    · exact I'.exact z h₁ (le_pred hc hrf h) hz
  · obtain ⟨h₁, h₂, h₃⟩ := hF p hp
    exact ⟨h₁, h₂, Or.inr (moLe_mo hc h₃ hy'y)⟩
  · rcases hstate with h | ⟨i, s, hs, hbs, hsy, h₁, h₂, h₃⟩
    · exact Or.inl (hbm.trans h)
    · exact Or.inr ⟨i, s, hs, hbs, Or.inr (moLe_mo hc hsy hy'y), h₁, h₂, hbm.trans h₃⟩

include Rv in
/-- A B1 at or before an attempt's precheck, by an earlier attempt of the
same slot, is at or before the value the precheck read. -/
theorem b1_le_l (hc : X.Consistent) {σ : C.ι} {wz z w y l u : Ev}
    (hwz : C.isAttempt σ wz) (hz : (C.pins σ).inAttempt wz z) (hzB : C.R.isB1 z)
    (hw : C.isAttempt σ w) (hy : (C.pins σ).inAttempt w y) (hyB : C.R.isB1 y)
    (hzy : X.mo z y) (hwl : X.sb w l) (hul : X.rf u l) (hll : X.loc l = X.loc y) :
    z = u ∨ X.mo z u := by
  have h₁ : X.hb z l := Rv.b1_before hc hwz hw hz hzB hy hyB hzy (hb_of_sb hwl)
  have ⟨hzW, _, hlz, _⟩ := hc.mo_wf hzy
  rcases coh_wr hc hzW (hlz.trans hll.symm) h₁ hul with h | h
  · exact Or.inl h.symm
  · exact Or.inr h

/-- **The heart of SP2.** A slot's failed B1 stops every later B1 of the
slot in the incarnation: the later precheck sees the failure, whose word
stays sealed or full. -/
theorem fail_blocks (hc : X.Consistent) {σ : C.ι} {wf f w y l u b : Ev}
    (hwf : C.isAttempt σ wf) (hf : (C.pins σ).inAttempt wf f) (hfB : C.R.isB1 f)
    (hfail : ¬ Rv.wins wf f)
    (hw : C.isAttempt σ w) (hy : (C.pins σ).inAttempt w y) (hyB : C.R.isB1 y)
    (hfy : X.mo f y) (hwl : X.sb w l) (hul : X.rf u l) (hll : X.loc l = X.loc y)
    (hus : ¬ C.R.isSealed (X.wval u)) (hroom : Rv.bump (X.wval u) + Rv.need w ≤ Rv.cap)
    (Iu : Rv.Inv b u) (hbf : X.mo b f) : False := by
  have hfu := Rv.b1_le_l hc hwf hf hfB hw hy hyB hfy hwl hul hll
  obtain ⟨f₀, hrf0, _, hse⟩ := C.R.b1_rmw hfB
  by_cases hs : C.R.isSealed (X.rval f)
  · rw [hc.rf_val hrf0] at hs
    exact hus (Iu.sealed f (Or.inr hbf) hfu (hse.mpr hs))
  · have hgt : ¬ (Rv.bump (X.rval f) + Rv.need wf ≤ Rv.cap) := fun h => hfail ⟨hs, h⟩
    have hex := Iu.exact f hbf hfu hfB
    have hmono := Iu.mono f (Or.inr hbf) hfu
    have ⟨hn, _⟩ := Rv.claim_facts hwf hf hfB
    omega

theorem inv_b1 (hc : X.Consistent) {b y y' : Ev} (hbase : C.isBase b) (hseg : C.seg b y)
    (hby : X.mo b y) (hb1 : C.R.isB1 y) (hrf : X.rf y' y)
    (ih : ∀ u, X.mo u y → C.seg b u → Rv.Inv b u) : Rv.Inv b y := by
  have ⟨hbW, hyW, hlby, _⟩ := hc.mo_wf hby
  obtain ⟨hseg', hy'y⟩ := C.seg_rf hc hseg hby hrf
  have I' := ih y' hy'y hseg'
  obtain ⟨y'', hrf'', hst'', hse⟩ := C.R.b1_rmw hb1
  cases hc.rf_unique hrf'' hrf
  obtain ⟨σ, w, hw, hyw⟩ := Rv.b1_att hb1
  obtain ⟨_, hcM, l, u, hwl, hly, hul, hll, hus, hroom, hroomc⟩ := Rv.claim_facts hw hyw hb1
  have hyl : X.loc y = some (C.R.ctlLoc (C.attEpoch w)) := (C.b1_loc hw hyw hb1).2
  have hll' : X.loc l = X.loc y := hll.trans hyl.symm
  -- The B1 lands in the incarnation of `b` (SP1).
  have hE : C.R.stamp (X.wval b) = C.attEpoch w := by
    rw [← I'.stamp, ← hc.rf_val hrf]; exact C.b1_lands hc hw hyw hb1
  -- Its precheck read a value of this incarnation, at or before `y'`.
  have ⟨huW, _, _, _⟩ := hc.rf_wf hul
  have huy : X.mo u y := coh_rw hc hyW hll'.symm (hb_of_sb hly) hul
  have huy' := le_pred hc hrf huy
  have hbu := C.base_le hc hbase hbW hE hw hwl hul (hlby.trans hll'.symm)
  have Iu := ih u huy (C.seg_mono hc hseg' hbu huy')
  -- What holds of `y` once the add is known not to carry.
  have hex_of : Rv.bump (X.wval y') + Rv.claim y < bumpLimit →
      Rv.bump (X.wval y) = Rv.bump (X.wval y') + Rv.claim y := by
    intro h; rw [Rv.b1_bump hb1, hc.rf_val hrf, Nat.mod_eq_of_lt h]
  have hmono : Rv.bump (X.wval y) = Rv.bump (X.wval y') + Rv.claim y →
      ∀ z, (b = z ∨ X.mo b z) → (z = y ∨ X.mo z y) →
        Rv.bump (X.wval z) ≤ Rv.bump (X.wval y) := by
    intro hex z h₁ h₂
    rcases h₂ with rfl | h
    · exact Nat.le_refl _
    · have := I'.mono z h₁ (le_pred hc hrf h); omega
  have hsealed : ∀ z, (b = z ∨ X.mo b z) → (z = y ∨ X.mo z y) →
      C.R.isSealed (X.wval z) → C.R.isSealed (X.wval y) := by
    intro z h₁ h₂ hz
    rcases h₂ with rfl | h
    · exact hz
    · exact hse.mpr (I'.sealed z h₁ (le_pred hc hrf h) hz)
  have hexact : Rv.bump (X.wval y) = Rv.bump (X.wval y') + Rv.claim y →
      ∀ z, X.mo b z → (z = y ∨ X.mo z y) → C.R.isB1 z →
        Rv.bump (X.wval z) = Rv.bump (X.rval z) + Rv.claim z := by
    intro hex z h₁ h₂ hz
    rcases h₂ with rfl | h
    · rw [hc.rf_val hrf]; exact hex
    · exact I'.exact z h₁ (le_pred hc hrf h) hz
  have hstamp : C.R.stamp (X.wval y) = C.R.stamp (X.wval b) := hst''.trans I'.stamp
  have hP : 0 < Rv.slots.length := List.length_pos_of_mem (Rv.slots_all σ)
  have hPM : Rv.maxClaim ≤ Rv.slots.length * Rv.maxClaim := Nat.le_mul_of_pos_left _ hP
  have ha5 := Rv.a5
  have hnpos := Rv.need_pos hw
  obtain ⟨F, hnd, hF, hstate⟩ := I'.count
  by_cases hwin : Rv.wins w y
  · -- A success: the count restarts from it.
    have hwin' := hwin.2
    rw [hc.rf_val hrf] at hwin'
    have hex := hex_of (by omega)
    refine ⟨hstamp, hmono hex, hsealed, hexact hex, [], List.nodup_nil,
      (fun p hp => by cases hp),
      Or.inr ⟨σ, y, ⟨w, hw, hyw, hb1, hwin⟩, hby, Or.inl rfl, by omega,
        (fun p hp => by cases hp), ?_⟩⟩
    show _ = _ + 0
    omega
  · -- A failure: the slot has not failed before (`fail_blocks`).
    have hσ : σ ∉ F.map Prod.fst := by
      intro hm
      obtain ⟨p, hpF, hpσ⟩ := List.mem_map.mp hm
      obtain ⟨⟨wf, hwf, hf, hfB, hfail⟩, hbf, hfy'⟩ := hF p hpF
      rw [hpσ] at hwf hf
      exact Rv.fail_blocks hc hwf hf hfB hfail hw hyw hb1 (moLe_mo hc hfy' hy'y) hwl hul hll'
        hus hroom Iu hbf
    have hnd' : (((σ, y) :: F).map Prod.fst).Nodup := List.nodup_cons.mpr ⟨hσ, hnd⟩
    have hlen : F.length + 1 ≤ Rv.slots.length := by
      have := Rv.slots_le hnd'
      simpa using this
    have hFM : ∀ p ∈ F, Rv.claim p.2 ≤ Rv.maxClaim := by
      intro p hp
      obtain ⟨⟨wf, hwf, hf, hfB, _⟩, _⟩ := hF p hp
      exact (Rv.claim_facts hwf hf hfB).2.1
    have hcl := Rv.claims_le hFM
    have hlenM : F.length * Rv.maxClaim + Rv.maxClaim ≤ Rv.slots.length * Rv.maxClaim := by
      have := Nat.mul_le_mul_right Rv.maxClaim hlen
      rw [Nat.succ_mul] at this
      exact this
    have hF' : ∀ p ∈ (σ, y) :: F, Rv.Fails p.1 p.2 ∧ X.mo b p.2 ∧ (p.2 = y ∨ X.mo p.2 y) := by
      intro p hp
      rcases List.mem_cons.mp hp with rfl | hp'
      · exact ⟨⟨w, hw, hyw, hb1, hwin⟩, hby, Or.inl rfl⟩
      · obtain ⟨h₁, h₂, h₃⟩ := hF p hp'
        exact ⟨h₁, h₂, Or.inr (moLe_mo hc h₃ hy'y)⟩
    have hcons : Rv.claims ((σ, y) :: F) = Rv.claim y + Rv.claims F := Rv.claims_cons (σ, y) F
    rcases hstate with hA | ⟨i, s, hWs, hbs, hsy', hs1, hsF, hbs'⟩
    · have hex := hex_of (by omega)
      exact ⟨hstamp, hmono hex, hsealed, hexact hex, (σ, y) :: F, hnd', hF', Or.inl (by omega)⟩
    · -- If the last winner fails now, its precheck saw room after its success.
      have hXσ : i = σ → Rv.bump (X.wval s) + Rv.claim y ≤ Rv.cap := by
        intro hiσ
        subst hiσ
        obtain ⟨ws, hws, hs, hsB, _⟩ := hWs
        have hsy : X.mo s y := moLe_mo hc hsy' hy'y
        have hsu := Rv.b1_le_l hc hws hs hsB hw hyw hb1 hsy hwl hul hll'
        have := Iu.mono s (Or.inr hbs) hsu
        omega
      have hnw : Rv.bump (X.wval y') + Rv.claim y < bumpLimit := by
        by_cases hiσ : i = σ
        · have := hXσ hiσ
          omega
        · by_cases hiF : i ∈ F.map Prod.fst
          · obtain ⟨p, hpF, hpi⟩ := List.mem_map.mp hiF
            have h₁ := hsF p hpF hpi
            have h₂ := Rv.claims_le_mem hpF hFM
            omega
          · have hnd'' : (i :: σ :: F.map Prod.fst).Nodup :=
              List.nodup_cons.mpr ⟨fun h => by
                rcases List.mem_cons.mp h with h | h
                · exact hiσ h
                · exact hiF h, hnd'⟩
            have hlen2 : F.length + 2 ≤ Rv.slots.length := by
              have := Rv.slots_le hnd''
              simpa using this
            have := Nat.mul_le_mul_right Rv.maxClaim hlen2
            rw [Nat.add_mul] at this
            omega
      have hex := hex_of hnw
      refine ⟨hstamp, hmono hex, hsealed, hexact hex, (σ, y) :: F, hnd', hF',
        Or.inr ⟨i, s, hWs, hbs, Or.inr (moLe_mo hc hsy' hy'y), hs1, fun p hp hpi => ?_,
          by omega⟩⟩
      rcases List.mem_cons.mp hp with rfl | hp'
      · exact hXσ hpi.symm
      · exact hsF p hp' hpi

/-- Every value of an incarnation satisfies `Inv`. -/
theorem inv (hc : X.Consistent) (y : Ev) :
    ∀ b, C.isBase b → C.seg b y → Rv.Inv b y := by
  refine hc.mo_wellFounded.induction
    (C := fun y => ∀ b, C.isBase b → C.seg b y → Rv.Inv b y) y ?_
  intro y ih b hbase hseg
  rcases hseg.1 with rfl | hby
  · exact Rv.inv_base hc hbase
  · have ih' : ∀ u, X.mo u y → C.seg b u → Rv.Inv b u := fun u hu hs => ih u hu b hbase hs
    by_cases hb1 : C.R.isB1 y
    · obtain ⟨y', hrf, _⟩ := C.R.b1_rmw hb1
      exact Rv.inv_b1 hc hbase hseg hby hb1 hrf ih'
    · obtain ⟨A, hZ⟩ := (hseg.2 y hby (Or.inl rfl)).resolve_left hb1
      obtain ⟨y', hrf, _⟩ := C.seal_rmw hZ
      have ⟨_, hyW, _, _⟩ := hc.mo_wf hby
      have hy'y := hc.rmw_mo hrf hyW
      exact Rv.inv_seal hc hZ hb1 hrf hy'y (ih y' hy'y b hbase (C.seg_rf hc hseg hby hrf).1)

/-! ## SP2 and the claimed ranges -/

include Rv in
/-- Every B1 belongs to an incarnation that starts strictly before it and
carries its attempt's epoch. -/
theorem b1_inc (hc : X.Consistent) {i : C.ι} {w y : Ev} (hw : C.isAttempt i w)
    (hy : (C.pins i).inAttempt w y) (hyB : C.R.isB1 y) :
    ∃ b, C.isBase b ∧ C.seg b y ∧ X.mo b y ∧ C.R.stamp (X.wval b) = C.attEpoch w := by
  have ⟨hyW, hyl⟩ := C.b1_loc hw hy hyB
  obtain ⟨b, hb, hs, hby⟩ := C.seg_exists hc y hyW ⟨_, hyl⟩
  have hby := hby hyB
  obtain ⟨y', hrf, _⟩ := C.R.b1_rmw hyB
  have I' := Rv.inv hc y' b hb (C.seg_rf hc hs hby hrf).1
  refine ⟨b, hb, hs, hby, ?_⟩
  rw [← I'.stamp, ← hc.rf_val hrf]; exact C.b1_lands hc hw hy hyB

include Rv in
/-- The start of an incarnation precedes every B1 of an attempt for it. -/
theorem base_mo_b1 (hc : X.Consistent) {i : C.ι} {w b z : Ev} (hb : C.isBase b)
    (hbW : X.isW b) (hE : C.R.stamp (X.wval b) = C.attEpoch w) (hw : C.isAttempt i w)
    (hz : (C.pins i).inAttempt w z) (hzB : C.R.isB1 z) (hloc : X.loc b = X.loc z) :
    X.mo b z := by
  obtain ⟨_, _, l, u, hwl, hlz, hul, hll, _⟩ := Rv.claim_facts hw hz hzB
  have ⟨hzW, hzl⟩ := C.b1_loc hw hz hzB
  have hll' : X.loc l = X.loc z := hll.trans hzl.symm
  have hbu := C.base_le hc hb hbW hE hw hwl hul (hloc.trans hll'.symm)
  exact moLe_mo hc hbu (coh_rw hc hzW hll'.symm (hb_of_sb hlz) hul)

/-- **SP2, the bound.** No B1 carries out of the 31-bit bump field, so
the bump of every incarnation stays below `2^31`. -/
theorem no_wrap (hc : X.Consistent) {i : C.ι} {w y : Ev} (hw : C.isAttempt i w)
    (hy : (C.pins i).inAttempt w y) (hyB : C.R.isB1 y) :
    Rv.bump (X.rval y) + Rv.claim y < bumpLimit := by
  obtain ⟨b, hb, hs, hby, _⟩ := Rv.b1_inc hc hw hy hyB
  have h₁ := (Rv.inv hc y b hb hs).exact y hby (Or.inl rfl) hyB
  have h₂ := Rv.b1_bump hyB
  have h₃ : (Rv.bump (X.rval y) + Rv.claim y) % bumpLimit < bumpLimit :=
    Nat.mod_lt _ (by decide)
  omega

/-- **SP2, one failure per slot.** A failed B1 is the last B1 its slot
makes in the incarnation. -/
theorem fail_last (hc : X.Consistent) {σ : C.ι} {w w' f y : Ev}
    (hw : C.isAttempt σ w) (hf : (C.pins σ).inAttempt w f) (hfB : C.R.isB1 f)
    (hfail : ¬ Rv.wins w f) (hw' : C.isAttempt σ w') (hy : (C.pins σ).inAttempt w' y)
    (hyB : C.R.isB1 y) (hE : C.attEpoch w = C.attEpoch w') : ¬ X.mo f y := by
  intro hfy
  obtain ⟨b, hb, hs, hby, hstb⟩ := Rv.b1_inc hc hw' hy hyB
  have ⟨hbW, hyW, hlby, _⟩ := hc.mo_wf hby
  have hyl := (C.b1_loc hw' hy hyB).2
  obtain ⟨_, _, l, u, hwl, hly, hul, hll, hus, hroom, _⟩ := Rv.claim_facts hw' hy hyB
  have hll' : X.loc l = X.loc y := hll.trans hyl.symm
  obtain ⟨y', hrf, _⟩ := C.R.b1_rmw hyB
  have hbu := C.base_le hc hb hbW hstb hw' hwl hul (hlby.trans hll'.symm)
  have huy := coh_rw hc hyW hll'.symm (hb_of_sb hly) hul
  have Iu := Rv.inv hc u b hb (C.seg_mono hc (C.seg_rf hc hs hby hrf).1 hbu (le_pred hc hrf huy))
  have hfl := (C.b1_loc hw hf hfB).2
  have hbf := Rv.base_mo_b1 hc hb hbW (hstb.trans hE.symm) hw hf hfB
    (hlby.trans (hyl.trans (hE ▸ hfl).symm))
  exact Rv.fail_blocks hc hw hf hfB hfail hw' hy hyB hfy hwl hul hll' hus hroom Iu hbf

/-- **SP2, one failure per slot.** Two failed B1s of one slot in one
incarnation are the same. -/
theorem fail_once (hc : X.Consistent) {σ : C.ι} {w w' f f' : Ev}
    (hw : C.isAttempt σ w) (hf : (C.pins σ).inAttempt w f) (hfB : C.R.isB1 f)
    (hfail : ¬ Rv.wins w f) (hw' : C.isAttempt σ w') (hf' : (C.pins σ).inAttempt w' f')
    (hf'B : C.R.isB1 f') (hfail' : ¬ Rv.wins w' f') (hE : C.attEpoch w = C.attEpoch w') :
    f = f' := by
  have ⟨hfW, hfl⟩ := C.b1_loc hw hf hfB
  have ⟨hf'W, hf'l⟩ := C.b1_loc hw' hf' hf'B
  rw [hE] at hfl
  rcases hc.mo_total hfW hf'W (hfl.trans hf'l.symm) (some_ne_none hfl) with h | h | h
  · exact h
  · exact (Rv.fail_last hc hw hf hfB hfail hw' hf' hf'B hE h).elim
  · exact (Rv.fail_last hc hw' hf' hf'B hfail' hw hf hfB hE.symm h).elim

/-- An earlier B1 of an incarnation claims its range below the bump a later
one reads. -/
theorem claimed_before (hc : X.Consistent) {i j : C.ι} {w w' z z' : Ev}
    (hw : C.isAttempt i w) (hz : (C.pins i).inAttempt w z) (hzB : C.R.isB1 z)
    (hw' : C.isAttempt j w') (hz' : (C.pins j).inAttempt w' z') (hz'B : C.R.isB1 z')
    (hE : C.attEpoch w = C.attEpoch w') (hzz : X.mo z z') :
    Rv.bump (X.rval z) + Rv.claim z ≤ Rv.bump (X.rval z') := by
  obtain ⟨b, hb, hs, hby, hstb⟩ := Rv.b1_inc hc hw' hz' hz'B
  have ⟨hbW, _, hlb, _⟩ := hc.mo_wf hby
  have ⟨_, _, hlz, _⟩ := hc.mo_wf hzz
  have hbz := Rv.base_mo_b1 hc hb hbW (hstb.trans hE.symm) hw hz hzB (hlb.trans hlz.symm)
  obtain ⟨y', hrf, _⟩ := C.R.b1_rmw hz'B
  have I' := Rv.inv hc y' b hb (C.seg_rf hc hs hby hrf).1
  have hzy' := le_pred hc hrf hzz
  have h₁ := I'.mono z (Or.inr hbz) hzy'
  have h₂ := I'.exact z hbz hzy' hzB
  rw [hc.rf_val hrf]
  omega

/-- **SP4, the claimed ranges.** Two B1s of one incarnation claim
disjoint byte ranges `[bump, bump + claim)`. -/
theorem b1_disjoint (hc : X.Consistent) {i j : C.ι} {w w' z z' : Ev}
    (hw : C.isAttempt i w) (hz : (C.pins i).inAttempt w z) (hzB : C.R.isB1 z)
    (hw' : C.isAttempt j w') (hz' : (C.pins j).inAttempt w' z') (hz'B : C.R.isB1 z')
    (hE : C.attEpoch w = C.attEpoch w') (hne : z ≠ z') :
    Rv.bump (X.rval z) + Rv.claim z ≤ Rv.bump (X.rval z') ∨
      Rv.bump (X.rval z') + Rv.claim z' ≤ Rv.bump (X.rval z) := by
  have ⟨hzW, hzl⟩ := C.b1_loc hw hz hzB
  have ⟨hz'W, hz'l⟩ := C.b1_loc hw' hz' hz'B
  rw [hE] at hzl
  rcases hc.mo_total hzW hz'W (hzl.trans hz'l.symm) (some_ne_none hzl) with h | h | h
  · exact absurd h hne
  · exact Or.inl (Rv.claimed_before hc hw hz hzB hw' hz' hz'B hE h)
  · exact Or.inr (Rv.claimed_before hc hw' hz' hz'B hw hz hzB hE.symm h)

end ReserveProtocol

/-! ## SP3: the chunk words -/

/-- Nothing comes between a write and a read of it: a write `x` to the read's
word with `z hb x hb r` contradicts `rf z r` (COH-WW, COH-WR). -/
theorem no_between {X : Execution Ev Loc} (hc : X.Consistent) {z x r : Ev} (hrf : X.rf z r)
    (hxW : X.isW x) (hxl : X.loc x = X.loc r) (hzx : X.hb z x) (hxr : X.hb x r) : False := by
  have ⟨hzW, _, hzl, hs⟩ := hc.rf_wf hrf
  have hmo := coh_ww hc hzW hxW (hzl.trans hxl.symm) hs hzx
  rcases coh_wr hc hxW hxl hxr hrf with heq | h
  · subst heq; exact hc.mo_irrefl _ hmo
  · exact hc.mo_irrefl _ (hc.mo_trans hmo h)

/-- A read does not read a write it happens before. -/
theorem rf_not_hb {X : Execution Ev Loc} (hc : X.Consistent) {z r : Ev} (hrf : X.rf z r)
    (h : X.hb r z) : False :=
  hc.coherence h (cob_of_rf hrf)

namespace CacheProtocol

variable {X : Execution Ev Loc} (C : CacheProtocol X)

/-- `attBefore` is transitive through an attempt that does something after
its pin. -/
theorem attBefore_trans {i : C.ι} {w₁ w₂ w₃ e : Ev} (h₁ : C.attBefore i w₁ w₂)
    (h₂ : C.attBefore i w₂ w₃) (he : (C.pins i).inAttempt w₂ e) (he' : X.hb w₂ e) :
    C.attBefore i w₁ w₃ :=
  fun a a' ha ha' => hb_trans (h₁ a e ha he') (h₂ e a' he ha')

/-- Let attempt `w0` store `x0` to a word and a later attempt `w` of the slot
load it at `ld`. If `ld` reads a store of an attempt `w1` of the slot, then
`w1` is `w0` or comes after it, and is `w` or comes before it. -/
theorem att_between (hc : X.Consistent) {i : C.ι} {w0 w w1 x0 ld z : Ev}
    (hw0 : C.isAttempt i w0) (hw : C.isAttempt i w) (hbef : C.attBefore i w0 w)
    (hx0 : (C.pins i).inAttempt w0 x0) (hx0s : X.sb w0 x0) (hx0W : X.isW x0)
    (hx0l : X.loc x0 = X.loc ld) (hld : (C.pins i).inAttempt w ld) (hlds : X.sb w ld)
    (hrf : X.rf z ld) (hw1 : C.isAttempt i w1) (hz : (C.pins i).inAttempt w1 z)
    (hzs : X.sb w1 z) :
    (w1 = w0 ∨ C.attBefore i w0 w1) ∧ (w1 = w ∨ C.attBefore i w1 w) := by
  have hx0ld : X.hb x0 ld := hbef x0 ld hx0 (hb_of_sb hlds)
  refine ⟨?_, ?_⟩
  · by_cases h01 : w1 = w0
    · exact Or.inl h01
    · rcases C.att_order hc hw0 hw1 (Ne.symm h01) with h | h
      · exact Or.inr h
      · exact (no_between hc hrf hx0W hx0l (h z x0 hz (hb_of_sb hx0s)) hx0ld).elim
  · by_cases h1 : w1 = w
    · exact Or.inl h1
    · rcases C.att_order hc hw1 hw h1 with h | h
      · exact Or.inr h
      · exact (rf_not_hb hc hrf (h ld z hld (hb_of_sb hzs))).elim

end CacheProtocol

namespace ReserveProtocol

variable {X : Execution Ev Loc} {C : CacheProtocol X} (Rv : ReserveProtocol X C)

/-- The chunk words are private to the registrant (SP3). Let
attempt `w0` store `x0` to a chunk word, and a later attempt `w` of the same
registration load it at `ld`. Then `ld` reads neither the initialisation nor a
reset, unless that is `x0`: the resets of this registration precede its pins,
those of earlier ones precede `x0`, and those of later ones follow `ld`. -/
theorem not_early (hc : X.Consistent) {i : C.ι} {c w0 w x0 ld z : Ev}
    (hc0 : (C.pins i).w2Of c w0) (hcw : (C.pins i).w2Of c w) (hbef : C.attBefore i w0 w)
    (hx0 : (C.pins i).inAttempt w0 x0) (hx0W : X.isW x0) (hx0l : X.loc x0 = X.loc ld)
    (hld : (C.pins i).inAttempt w ld) (hlds : X.sb w ld) (hrf : X.rf z ld)
    (hz : Rv.isChunkInit z ∨ ∃ c', Rv.resetOf i c' z) : z = x0 := by
  have hx0ld : X.hb x0 ld := hbef x0 ld hx0 (hb_of_sb hlds)
  have ⟨_, _, hzl, _⟩ := hc.rf_wf hrf
  refine Classical.byContradiction fun hne => ?_
  rcases hz with hinit | ⟨c', hr⟩
  · exact no_between hc hrf hx0W hx0l
      (Rv.chunkInit_first hinit hx0W (hx0l.trans hzl.symm) (fun h => hne h.symm)) hx0ld
  · have hact := Rv.reset_act hr
    have hcC' := (C.slot i).act_claim hact
    have hcC := (C.slot i).act_claim ((C.pins i).w2_act hc0)
    rcases (C.slot i).claim_total hc hcC' hcC with heq | h | h
    · subst heq
      exact no_between hc hrf hx0W hx0l (hb_sbr (hb_of_sb (Rv.reset_before hr hc0)) hx0.1)
        hx0ld
    · exact no_between hc hrf hx0W hx0l
        ((C.slot i).acts_ordered hc hcC' hcC h hact ((C.pins i).att_act hc0 hx0.1 hx0.2))
        hx0ld
    · exact rf_not_hb hc hrf
        ((C.slot i).acts_ordered hc hcC hcC' h ((C.pins i).att_act hcw hld.1 hld.2) hact)

/-- The epoch load of a chunk reservation reads the `chunk_epoch` store of a
chunk claim by an earlier attempt of the same registration and epoch: the load
returned the attempt's epoch, which no initialisation or reset stores, and a
later registration resets the word before it pins. -/
theorem epoch_source (hc : X.Consistent) {i : C.ι} {w le lc ln s : Ev}
    (hu : Rv.useOf i w le lc ln s) :
    ∃ c w0 b0 xe xc xn, Rv.chunkOf i w0 b0 xe xc xn ∧ (C.pins i).w2Of c w0 ∧
      (C.pins i).w2Of c w ∧ C.attEpoch w0 = C.attEpoch w ∧ C.attBefore i w0 w ∧
      X.rf xe le := by
  obtain ⟨hw, hwle, -, -, -, -, hle, -, -, -, -, hlel, -, -, -, ⟨ze, hze⟩, -, -, hlev, -⟩ :=
    Rv.use_ev hu
  have ⟨hzeW, _, hzel, _⟩ := hc.rf_wf hze
  have hge := C.att_ge hc hw
  have hval : X.wval ze = C.attEpoch w := by rw [← hc.rf_val hze]; exact hlev
  rcases Rv.ce_writes hzeW (hzel.trans hlel) with hinit | ⟨c', hr⟩ | ⟨w0, b0, xc, xn, hch⟩
  · rw [Rv.chunkInit_val hinit] at hval; omega
  · rw [Rv.reset_val hr] at hval; omega
  · obtain ⟨hw0, hb0, hb0B, -, -⟩ := Rv.chunk_b1 hch
    obtain ⟨hs0e, -, -, hze0, -, -, -, -, -, -, -, -, hzev, -, -⟩ := Rv.chunk_ev hch
    have hE : C.attEpoch w0 = C.attEpoch w := hzev.symm.trans hval
    have hne : w0 ≠ w := fun h => Rv.use_no_b1 hu (h ▸ hb0) hb0B
    have hbef : C.attBefore i w0 w := by
      rcases C.att_order hc hw0 hw hne with h | h
      · exact h
      · exact (rf_not_hb hc hze (h le ze hle (hb_of_sb hs0e))).elim
    obtain ⟨c0, hc0⟩ := C.att_w2 hw0
    obtain ⟨c, hcw⟩ := C.att_w2 hw
    have hcC0 := (C.slot i).act_claim ((C.pins i).w2_act hc0)
    have hcC := (C.slot i).act_claim ((C.pins i).w2_act hcw)
    have hze_act := (C.pins i).att_act hc0 hze0.1 hze0.2
    have hle_act := (C.pins i).att_act hcw hle.1 hle.2
    rcases (C.slot i).claim_total hc hcC0 hcC with heq | h | h
    · subst heq
      exact ⟨c0, w0, b0, ze, xc, xn, hch, hc0, hcw, hE, hbef, hze⟩
    · -- A later registration reset `chunk_epoch` before it pinned.
      obtain ⟨x, hr, hxW, hxl⟩ := Rv.reset_ce hcw
      exact (no_between hc hze hxW (hxl.trans hlel.symm)
        ((C.slot i).acts_ordered hc hcC0 hcC h hze_act (Rv.reset_act hr))
        (hb_trans (hb_of_sb (Rv.reset_before hr hcw)) (hb_of_sb hwle))).elim
    · exact (rf_not_hb hc hze ((C.slot i).acts_ordered hc hcC hcC0 h hle_act hze_act)).elim

/-- No chunk claim of the slot comes between the claim whose `chunk_epoch`
store a reservation read and the reservation: its own epoch store would. -/
theorem no_claim_between (hc : X.Consistent) {i : C.ι}
    {w0 w w1 b0 xe xc xn le b1 xe1 xc1 xn1 : Ev}
    (hch : Rv.chunkOf i w0 b0 xe xc xn) (hrf : X.rf xe le)
    (hlel : X.loc le = some (Rv.ceLoc i)) (hwle : X.sb w le)
    (hch1 : Rv.chunkOf i w1 b1 xe1 xc1 xn1) (h01 : C.attBefore i w0 w1)
    (h1 : C.attBefore i w1 w) : False := by
  obtain ⟨-, -, -, hxe, -, -, -, -, -, -, -, -, -, -, -⟩ := Rv.chunk_ev hch
  obtain ⟨hs1, -, -, hxe1, -, -, hxe1W, -, -, hxe1l, -, -, -, -, -⟩ := Rv.chunk_ev hch1
  exact no_between hc hrf hxe1W (hxe1l.trans hlel.symm) (h01 xe xe1 hxe (hb_of_sb hs1))
    (h1 xe1 le hxe1 (hb_of_sb hwle))

/-- The arithmetic of a cut: a cursor inside the chunk, an end load of the
chunk's end, and the `u32` room check give room for the record, and the cursor
store does not wrap. -/
theorem cut_arith {cur en need sv cap : Nat} (hcap : cap < wordLimit) (hen : en ≤ cap)
    (hcur : cur ≤ en) (hroom : need ≤ (en + wordLimit - cur) % wordLimit)
    (hsv : sv = (cur + need) % wordLimit) : cur + need ≤ en ∧ sv = cur + need := by
  unfold wordLimit at *
  omega

/-- **SP3 and the chunk cuts.** A chunk reservation `useOf i w le lc ln s`
cuts from the chunk of a claim `chunkOf i w0 b0 xe xc xn` by an earlier attempt
of the same registration and epoch:

* its epoch and end loads read the claim's stores, and its cursor load reads
  the claim's cursor store or the cursor store of an earlier cut between `w0`
  and `w`: each load reads the latest store to its word;
* the cursor it loads lies in the chunk `[bump + need w0, min (bump + claim)
  cap)` with room for its record, and its cursor store does not wrap;
* every cut between `w0` and `w` loaded a cursor at least one record lower. -/
theorem chunk_source (hc : X.Consistent) (s : Ev) :
    ∀ {i : C.ι} {w le lc ln : Ev}, Rv.useOf i w le lc ln s →
    ∃ c w0 b0 xe xc xn, Rv.chunkOf i w0 b0 xe xc xn ∧ (C.pins i).w2Of c w0 ∧
      (C.pins i).w2Of c w ∧ C.attEpoch w0 = C.attEpoch w ∧ C.attBefore i w0 w ∧
      X.rf xe le ∧ X.rf xn ln ∧
      (X.rf xc lc ∨ ∃ w1 le1 lc1 ln1 s1, Rv.useOf i w1 le1 lc1 ln1 s1 ∧ X.rf s1 lc ∧
        C.attBefore i w0 w1 ∧ C.attBefore i w1 w) ∧
      Rv.bump (X.rval b0) + Rv.need w0 ≤ X.rval lc ∧
      X.rval lc + Rv.need w ≤ min (Rv.bump (X.rval b0) + Rv.claim b0) Rv.cap ∧
      X.wval s = X.rval lc + Rv.need w ∧
      ∀ w1 le1 lc1 ln1 s1, Rv.useOf i w1 le1 lc1 ln1 s1 → C.attBefore i w0 w1 →
        C.attBefore i w1 w → X.rval lc1 + Rv.need w1 ≤ X.rval lc := by
  refine hc.mo_wellFounded.induction
    (C := fun s => ∀ {i : C.ι} {w le lc ln : Ev}, Rv.useOf i w le lc ln s →
      ∃ c w0 b0 xe xc xn, Rv.chunkOf i w0 b0 xe xc xn ∧ (C.pins i).w2Of c w0 ∧
        (C.pins i).w2Of c w ∧ C.attEpoch w0 = C.attEpoch w ∧ C.attBefore i w0 w ∧
        X.rf xe le ∧ X.rf xn ln ∧
        (X.rf xc lc ∨ ∃ w1 le1 lc1 ln1 s1, Rv.useOf i w1 le1 lc1 ln1 s1 ∧ X.rf s1 lc ∧
          C.attBefore i w0 w1 ∧ C.attBefore i w1 w) ∧
        Rv.bump (X.rval b0) + Rv.need w0 ≤ X.rval lc ∧
        X.rval lc + Rv.need w ≤ min (Rv.bump (X.rval b0) + Rv.claim b0) Rv.cap ∧
        X.wval s = X.rval lc + Rv.need w ∧
        ∀ w1 le1 lc1 ln1 s1, Rv.useOf i w1 le1 lc1 ln1 s1 → C.attBefore i w0 w1 →
          C.attBefore i w1 w → X.rval lc1 + Rv.need w1 ≤ X.rval lc) s ?_
  intro s ih i w le lc ln hu
  obtain ⟨c, w0, b0, xe, xc, xn, hch, hc0, hcw, hE, hbef, hxe⟩ := Rv.epoch_source hc hu
  obtain ⟨hw, hwle, hwlc, hwln, hws, hlcs, hle, hlc, hln, hs, hsW, hlel, hlcl, hlnl, hsl,
    -, ⟨zc, hzc⟩, ⟨zn, hzn⟩, -, hroom, hsv⟩ := Rv.use_ev hu
  obtain ⟨hw0, hb0, hb0B, ⟨-, hwin⟩, hnc⟩ := Rv.chunk_b1 hch
  obtain ⟨-, hs0c, hs0n, -, hxc0, hxn0, -, hxcW, hxnW, -, hxcl, hxnl, -, hxcv, hxnv⟩ :=
    Rv.chunk_ev hch
  have hcapw : Rv.cap < wordLimit := by
    have := Rv.a5; unfold bumpLimit at this; unfold wordLimit; omega
  -- The end load reads the claim's end store.
  have hzn_eq : zn = xn := by
    have ⟨hznW, _, hznl, _⟩ := hc.rf_wf hzn
    have hl0 : X.loc xn = X.loc ln := hxnl.trans hlnl.symm
    rcases Rv.cn_writes hznW (hznl.trans hlnl) with h | h | ⟨w1, b1, xe1, xc1, hch1⟩
    · exact Rv.not_early hc hc0 hcw hbef hxn0 hxnW hl0 hln hwln hzn (Or.inl h)
    · exact Rv.not_early hc hc0 hcw hbef hxn0 hxnW hl0 hln hwln hzn (Or.inr h)
    · obtain ⟨hw1, hb1, hb1B, -⟩ := Rv.chunk_b1 hch1
      obtain ⟨-, -, hs1n, -, -, hzn1, -⟩ := Rv.chunk_ev hch1
      rcases C.att_between hc hw0 hw hbef hxn0 hs0n hxnW hl0 hln hwln hzn hw1 hzn1 hs1n
        with ⟨h0 | h0, h1 | h1⟩
      · subst h0; exact (Rv.chunk_unique hch1 hch).2.2
      · subst h0; exact (Rv.chunk_unique hch1 hch).2.2
      · subst h1; exact (Rv.use_no_b1 hu hb1 hb1B).elim
      · exact (Rv.no_claim_between hc hch hxe hlel hwle hch1 h0 h1).elim
  have hen : X.rval ln = min (Rv.bump (X.rval b0) + Rv.claim b0) Rv.cap := by
    rw [hc.rf_val hzn, hzn_eq, hxnv]
  have henc : min (Rv.bump (X.rval b0) + Rv.claim b0) Rv.cap ≤ Rv.cap := Nat.min_le_right _ _
  rw [hen] at hroom
  -- A cut between `w0` and `w` stores the cursor, so it is not before the store read.
  have between_store : ∀ {z w2 le2 lc2 ln2 s2}, X.rf z lc → Rv.useOf i w2 le2 lc2 ln2 s2 →
      C.attBefore i w2 w → X.hb z s2 → False := by
    intro z w2 le2 lc2 ln2 s2 hz hu2 h2 hzs
    obtain ⟨-, -, -, -, hw2s, -, -, -, -, hs2, hs2W, -, -, -, hs2l, -⟩ := Rv.use_ev hu2
    exact no_between hc hz hs2W (hs2l.trans hlcl.symm) hzs (h2 s2 lc hs2 (hb_of_sb hwlc))
  -- The cursor load reads the claim's cursor store, or an earlier cut's.
  have ⟨hzcW, _, hzcl, _⟩ := hc.rf_wf hzc
  have hl0 : X.loc xc = X.loc lc := hxcl.trans hlcl.symm
  have hsrc : zc = xc ∨ ∃ w1 le1 lc1 ln1, Rv.useOf i w1 le1 lc1 ln1 zc ∧
      C.attBefore i w0 w1 ∧ C.attBefore i w1 w := by
    rcases Rv.cc_writes hzcW (hzcl.trans hlcl) with h | h | ⟨w1, b1, xe1, xn1, hch1⟩ |
        ⟨w1, le1, lc1, ln1, hu1⟩
    · exact Or.inl (Rv.not_early hc hc0 hcw hbef hxc0 hxcW hl0 hlc hwlc hzc (Or.inl h))
    · exact Or.inl (Rv.not_early hc hc0 hcw hbef hxc0 hxcW hl0 hlc hwlc hzc (Or.inr h))
    · obtain ⟨hw1, hb1, hb1B, -⟩ := Rv.chunk_b1 hch1
      obtain ⟨-, hs1c, -, -, hzc1, -⟩ := Rv.chunk_ev hch1
      rcases C.att_between hc hw0 hw hbef hxc0 hs0c hxcW hl0 hlc hwlc hzc hw1 hzc1 hs1c
        with ⟨h0 | h0, h1 | h1⟩
      · subst h0; exact Or.inl (Rv.chunk_unique hch1 hch).2.1
      · subst h0; exact Or.inl (Rv.chunk_unique hch1 hch).2.1
      · subst h1; exact (Rv.use_no_b1 hu hb1 hb1B).elim
      · exact (Rv.no_claim_between hc hch hxe hlel hwle hch1 h0 h1).elim
    · obtain ⟨hw1, -, -, -, hw1s1, -, -, -, -, hs1, -⟩ := Rv.use_ev hu1
      rcases C.att_between hc hw0 hw hbef hxc0 hs0c hxcW hl0 hlc hwlc hzc hw1 hs1 hw1s1
        with ⟨h0 | h0, h1 | h1⟩
      · subst h0; exact (Rv.use_no_b1 hu1 hb0 hb0B).elim
      · subst h0; exact (Rv.use_no_b1 hu1 hb0 hb0B).elim
      · subst h1
        obtain ⟨-, -, -, rfl⟩ := Rv.use_unique hu1 hu
        exact (rf_not_hb hc hzc (hb_of_sb hlcs)).elim
      · exact Or.inr ⟨w1, le1, lc1, ln1, hu1, h0, h1⟩
  rcases hsrc with rfl | ⟨w1, le1, lc1, ln1, hu1, h0, h1⟩
  · -- The claim's cursor store: the first cut.
    have hcur : X.rval lc = Rv.bump (X.rval b0) + Rv.need w0 := by
      rw [hc.rf_val hzc, hxcv, Nat.mod_eq_of_lt (by omega)]
    have hle' : X.rval lc ≤ min (Rv.bump (X.rval b0) + Rv.claim b0) Rv.cap := by
      rw [hcur]; exact Nat.le_min.mpr ⟨by omega, hwin⟩
    obtain ⟨h₁, h₂⟩ := cut_arith hcapw henc hle' hroom hsv
    refine ⟨c, w0, b0, xe, zc, xn, hch, hc0, hcw, hE, hbef, hxe, hzn_eq ▸ hzn, Or.inl hzc,
      by omega, h₁, h₂, fun w2 le2 lc2 ln2 s2 hu2 h02 h2 => ?_⟩
    obtain ⟨-, -, -, -, hw2s, -⟩ := Rv.use_ev hu2
    exact (between_store hzc hu2 h2 (h02 zc s2 hxc0 (hb_of_sb hw2s))).elim
  · -- An earlier cut's cursor store: induction.
    obtain ⟨hw1, hw1le1, -, -, hw1s1, -, hle1, -, -, hs1, -, hle1l, -⟩ := Rv.use_ev hu1
    have hmo : X.mo zc s :=
      coh_ww hc hzcW hsW (hzcl.trans (hlcl.trans hsl.symm)) (some_ne_none (hzcl.trans hlcl))
        (h1 zc s hs1 (hb_of_sb hws))
    obtain ⟨c', w0', b0', xe', xc', xn', hch', -, -, -, h0', hxe', -, -, hlo1, hhi1, hsv1,
      hall1⟩ := ih zc hmo hu1
    -- That cut is from the same chunk.
    have hw0' : w0' = w0 := by
      obtain ⟨hw0'a, -⟩ := Rv.chunk_b1 hch'
      refine Classical.byContradiction fun hne => ?_
      rcases C.att_order hc hw0 hw0'a (Ne.symm hne) with h | h
      · exact Rv.no_claim_between hc hch hxe hlel hwle hch' h
          (C.attBefore_trans h0' h1 hle1 (hb_of_sb hw1le1))
      · exact Rv.no_claim_between hc hch' hxe' hle1l hw1le1 hch h h0
    subst hw0'
    have hb0' : b0' = b0 := by
      obtain ⟨-, hb0'a, hb0'B, -⟩ := Rv.chunk_b1 hch'
      exact Rv.b1_unique hw0 hb0'a hb0 hb0'B hb0B
    subst hb0'
    have hcur : X.rval lc = X.rval lc1 + Rv.need w1 := by rw [hc.rf_val hzc, hsv1]
    obtain ⟨h₁, h₂⟩ := cut_arith hcapw henc (by omega) hroom hsv
    refine ⟨c, w0', b0', xe, xc, xn, hch, hc0, hcw, hE, hbef, hxe, hzn_eq ▸ hzn,
      Or.inr ⟨w1, le1, lc1, ln1, zc, hu1, hzc, h0, h1⟩, by omega, h₁, h₂,
      fun w2 le2 lc2 ln2 s2 hu2 h02 h2 => ?_⟩
    obtain ⟨hw2, -, -, -, hw2s2, -⟩ := Rv.use_ev hu2
    by_cases h21 : w2 = w1
    · subst h21
      obtain ⟨-, rfl, -, -⟩ := Rv.use_unique hu2 hu1
      omega
    · rcases C.att_order hc hw2 hw1 h21 with h | h
      · have := hall1 w2 le2 lc2 ln2 s2 hu2 h02 h
        omega
      · exact (between_store hzc hu2 h2 (h zc s2 hs1 (hb_of_sb hw2s2))).elim

/-! ## SP4 and SP5: the reservations, and write-once records -/

/-- **SP4, in the area.** Every reservation lies in the record area. -/
theorem reserve_in_area (hc : X.Consistent) {i : C.ι} {w : Ev} {r : Nat}
    (hr : Rv.reserves i w r) : r + Rv.need w ≤ Rv.cap := by
  rcases hr with ⟨b, -, -, ⟨-, hwin⟩, rfl⟩ | ⟨le, lc, ln, s, hu, rfl⟩
  · exact hwin
  · obtain ⟨_, _, _, _, _, _, -, -, -, -, -, -, -, -, -, hhi, -⟩ := Rv.chunk_source hc s hu
    exact Nat.le_trans hhi (Nat.min_le_right _ _)

/-- The record of a B1 and a chunk cut by another attempt of the same
incarnation are disjoint. -/
theorem b1_cut_disjoint (hc : X.Consistent) {i j : C.ι} {w w' b le lc ln s : Ev}
    (hw : C.isAttempt i w) (hb : (C.pins i).inAttempt w b) (hbB : C.R.isB1 b)
    (hu : Rv.useOf j w' le lc ln s)
    (hE : C.attEpoch w = C.attEpoch w') :
    Rv.bump (X.rval b) + Rv.need w ≤ X.rval lc ∨
      X.rval lc + Rv.need w' ≤ Rv.bump (X.rval b) := by
  obtain ⟨c, w0, b0, xe, xc, xn, hch, -, -, hE0, -, -, -, -, hlo, hhi, -⟩ :=
    Rv.chunk_source hc s hu
  obtain ⟨hw0, hb0, hb0B, -⟩ := Rv.chunk_b1 hch
  have hmin := Nat.min_le_left (Rv.bump (X.rval b0) + Rv.claim b0) Rv.cap
  have hn := (Rv.claim_facts hw hb hbB).1
  by_cases hbb : b = b0
  · subst hbb
    obtain ⟨-, rfl⟩ := Rv.att_unique hw hw0 hb hb0
    exact Or.inl hlo
  · rcases Rv.b1_disjoint hc hw hb hbB hw0 hb0 hb0B (hE.trans hE0.symm) hbb with h | h
    · exact Or.inl (by have := (Rv.claim_facts hw0 hb0 hb0B).1; omega)
    · exact Or.inr (by omega)

/-- Two chunk cuts by distinct attempts of the same incarnation are
disjoint. -/
theorem cut_cut_disjoint (hc : X.Consistent) {i j : C.ι}
    {w w' le lc ln s le' lc' ln' s' : Ev} (hu : Rv.useOf i w le lc ln s)
    (hu' : Rv.useOf j w' le' lc' ln' s') (hE : C.attEpoch w = C.attEpoch w') (hne : w ≠ w') :
    X.rval lc + Rv.need w ≤ X.rval lc' ∨ X.rval lc' + Rv.need w' ≤ X.rval lc := by
  obtain ⟨c, w0, b0, xe, xc, xn, hch, -, -, hE0, h0, -, -, -, hlo, hhi, -, hall⟩ :=
    Rv.chunk_source hc s hu
  obtain ⟨c', w0', b0', xe', xc', xn', hch', -, -, hE0', h0', -, -, -, hlo', hhi', -, hall'⟩ :=
    Rv.chunk_source hc s' hu'
  obtain ⟨hw0, hb0, hb0B, -⟩ := Rv.chunk_b1 hch
  obtain ⟨hw0', hb0', hb0'B, -⟩ := Rv.chunk_b1 hch'
  have hmin := Nat.min_le_left (Rv.bump (X.rval b0) + Rv.claim b0) Rv.cap
  have hmin' := Nat.min_le_left (Rv.bump (X.rval b0') + Rv.claim b0') Rv.cap
  by_cases hbb : b0 = b0'
  · -- One chunk: the cuts are ordered, and so are their cursors.
    subst hbb
    obtain ⟨rfl, rfl⟩ := Rv.att_unique hw0 hw0' hb0 hb0'
    have hw := (Rv.use_ev hu).1
    have hw' := (Rv.use_ev hu').1
    rcases C.att_order hc hw hw' hne with h | h
    · exact Or.inl (hall' w le lc ln s hu h0 h)
    · exact Or.inr (hall w' le' lc' ln' s' hu' h0' h)
  · rcases Rv.b1_disjoint hc hw0 hb0 hb0B hw0' hb0' hb0'B
      (hE0.trans (hE.trans hE0'.symm)) hbb with h | h
    · have := (Rv.claim_facts hw0' hb0' hb0'B).1
      exact Or.inl (by omega)
    · have := (Rv.claim_facts hw0 hb0 hb0B).1
      exact Or.inr (by omega)

/-- **SP4, disjoint.** The reservations of two distinct attempts of one
incarnation, won at B1 or cut from a chunk, are disjoint. -/
theorem reserve_disjoint (hc : X.Consistent) {i j : C.ι} {w w' : Ev} {r r' : Nat}
    (hw : C.isAttempt i w) (hw' : C.isAttempt j w') (hE : C.attEpoch w = C.attEpoch w')
    (hne : w ≠ w') (hr : Rv.reserves i w r) (hr' : Rv.reserves j w' r') :
    r + Rv.need w ≤ r' ∨ r' + Rv.need w' ≤ r := by
  rcases hr with ⟨b, hb, hbB, -, rfl⟩ | ⟨le, lc, ln, s, hu, rfl⟩ <;>
    rcases hr' with ⟨b', hb', hb'B, -, rfl⟩ | ⟨le', lc', ln', s', hu', rfl⟩
  · have hbb : b ≠ b' := fun h => hne (Rv.att_unique hw hw' hb (h ▸ hb')).2
    rcases Rv.b1_disjoint hc hw hb hbB hw' hb' hb'B hE hbb with h | h
    · have := (Rv.claim_facts hw hb hbB).1
      exact Or.inl (by omega)
    · have := (Rv.claim_facts hw' hb' hb'B).1
      exact Or.inr (by omega)
  · exact Rv.b1_cut_disjoint hc hw hb hbB hu' hE
  · exact (Rv.b1_cut_disjoint hc hw' hb' hb'B hu hE.symm).symm
  · exact Rv.cut_cut_disjoint hc hu hu' hE hne

/-- **SP5, write-once.** Two stores to one record word by attempts of one
incarnation are the same store: an attempt stores each word of its record once,
inside its reservation, and the reservations of distinct attempts are
disjoint. -/
theorem record_write_once (hc : X.Consistent) {i j : C.ι} {w w' x x' : Ev} {l : Loc}
    (hw : C.isAttempt i w) (hx : (C.pins i).inAttempt w x)
    (hw' : C.isAttempt j w') (hx' : (C.pins j).inAttempt w' x')
    (hxW : X.isW x) (hx'W : X.isW x') (hxl : X.loc x = some l) (hx'l : X.loc x' = some l)
    (hl : Rv.isRecord l) (hE : C.attEpoch w = C.attEpoch w') : x = x' := by
  by_cases hww : w = w'
  · subst hww
    have := Rv.att_slot hw hw'
    subst this
    exact Rv.rec_once hw hx hx' hxW hx'W hxl hx'l hl
  · obtain ⟨r, hr, h₁, h₂⟩ := Rv.rec_in_res hw hx hxW hxl hl
    obtain ⟨r', hr', h₁', h₂'⟩ := Rv.rec_in_res hw' hx' hx'W hx'l hl
    rcases Rv.reserve_disjoint hc hw hw' hE hww hr hr' with h | h <;> omega

end ReserveProtocol
end Execution
end Sgc
