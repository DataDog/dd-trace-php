import SgcProofs.Lookup
import SgcProofs.Bounds
import SgcProofs.OwnerBounds

/-!
# BD1 and BD2, bounded access, on the events

`Bounds.lean` has the arithmetic of the readers' checks. This file ties the
two quantities that come from shared memory and are not checked to the
events that wrote them:

* `record_store_in_area` (BD1, record stores W4 and W5): every record store of an attempt
  addresses a word inside the record area, because it lies in the
  attempt's reservation (`rec_in_res`) and the reservation lies in the
  area (SP4, `reserve_in_area`);
* `entry_in_area`, `index_load_in_area` (BD2): every value an index
  slot ever holds, the zero fill or a publication of any incarnation,
  encodes an offset whose first 16 bytes (the header and the lengths word)
  lie in the area; so does every value an index load returns, whichever
  write it reads;
* `check_exact`: with that, the readers' bound `len >
  ras.wrapping_sub(off).wrapping_sub(8)` does not wrap, and is the bound
  `Bounds.lean` assumes. It is modelled in `u32` as at `put.rs:131`;
  `probe.rs:166` subtracts the 8 in `u64`, which agrees once
  nothing wraps;
* `reader_in_bounds` (BD2): whatever the header and lengths
  loads (D4, D5) return, a reader that passed its checks addresses only
  words of the record area.

`MappingLayout` and `StoreLayout` below model the mapping offsets and classify
every shared store, including fixed slot, arena and index words. The code
mapping for that classification is `Config::resolve`, `Cache::participant`,
`Cache::arena`, `ArenaView::slot`, and `ArenaView::word`.
-/

namespace Sgc
namespace Execution

variable {Ev Loc : Type}

/-- A7's mapping shape, in bytes. `Config::resolve` computes these exact
offsets; the stride is rounded up to a cache line. -/
structure MappingLayout where
  lineBytes : Nat
  participants : Nat
  buckets : Nat
  recordBytes : Nat
  arenaHeader : Nat
  recordOffset : Nat
  arenaStride : Nat
  arenasOffset : Nat
  mappingBytes : Nat
  index_end : recordOffset = arenaHeader + 8 * buckets
  record_end : recordOffset + recordBytes ≤ arenaStride
  arenas_start : arenasOffset = lineBytes * (1 + participants)
  mapping_end : mappingBytes = arenasOffset + 3 * arenaStride

namespace MappingLayout

/-- A word in one of the three arena blocks stays inside the mapping. -/
theorem arena_word (L : MappingLayout) {a off : Nat} (ha : a < 3)
    (hoff : off + 8 ≤ L.arenaStride) :
    L.arenasOffset + a * L.arenaStride + off + 8 ≤ L.mappingBytes := by
  have hmul := Nat.mul_le_mul_right L.arenaStride (show a + 1 ≤ 3 by omega)
  rw [Nat.succ_mul] at hmul
  rw [L.mapping_end]
  omega

/-- Participant lines start after the header and before the arenas. -/
theorem participant_word (L : MappingLayout) {i off : Nat}
    (hi : i < L.participants) (hoff : off + 8 ≤ L.lineBytes) :
    L.lineBytes * (1 + i) + off + 8 ≤ L.mappingBytes := by
  have hstart : L.lineBytes * (1 + i) + off + 8 ≤ L.arenasOffset := by
    rw [L.arenas_start]
    have hmul := Nat.mul_le_mul_left L.lineBytes (show 1 + i + 1 ≤ 1 + L.participants by omega)
    simp only [Nat.mul_add, Nat.mul_one] at hmul ⊢
    omega
  rw [L.mapping_end]
  omega

/-- Index words use the masked slot number. -/
theorem index_word (L : MappingLayout) {a slot : Nat} (ha : a < 3)
    (hslot : slot < L.buckets) :
    L.arenasOffset + a * L.arenaStride + L.arenaHeader + 8 * slot + 8 ≤
      L.mappingBytes := by
  have hrecord := L.record_end
  rw [L.index_end] at hrecord
  have hoff : L.arenaHeader + 8 * slot + 8 ≤ L.arenaStride := by omega
  simpa only [Nat.add_assoc] using L.arena_word ha hoff

/-- Record stores use the offset proved by SP4. -/
theorem record_word (L : MappingLayout) {a off : Nat} (ha : a < 3)
    (hoff : off + 8 ≤ L.recordBytes) :
    L.arenasOffset + a * L.arenaStride + L.recordOffset + off + 8 ≤
      L.mappingBytes := by
  have hoff' : L.recordOffset + off + 8 ≤ L.arenaStride := by
    have := L.record_end
    omega
  simpa only [Nat.add_assoc] using L.arena_word ha hoff'

end MappingLayout

/-- The five disjoint address forms of a shared store. The bounds on the
indices come from the loops or guards that construct the corresponding
view, and the record offset comes from SP4. -/
inductive StoreRegion (L : MappingLayout) where
  | header (off : Nat) (h : off + 8 ≤ L.lineBytes)
  | participant (i off : Nat) (hi : i < L.participants) (h : off + 8 ≤ L.lineBytes)
  | arenaHeader (a off : Nat) (ha : a < 3) (h : off + 8 ≤ L.arenaHeader)
  | index (a slot : Nat) (ha : a < 3) (h : slot < L.buckets)
  | record (a off : Nat) (ha : a < 3) (h : off + 8 ≤ L.recordBytes)

namespace StoreRegion

def address {L : MappingLayout} : StoreRegion L → Nat
  | .header off _ => off
  | .participant i off _ _ => L.lineBytes * (1 + i) + off
  | .arenaHeader a off _ _ => L.arenasOffset + a * L.arenaStride + off
  | .index a slot _ _ => L.arenasOffset + a * L.arenaStride + L.arenaHeader + 8 * slot
  | .record a off _ _ => L.arenasOffset + a * L.arenaStride + L.recordOffset + off

theorem in_mapping {L : MappingLayout} (r : StoreRegion L) :
    r.address + 8 ≤ L.mappingBytes := by
  cases r with
  | header off h =>
    dsimp [address]
    rw [L.mapping_end, L.arenas_start]
    simp only [Nat.mul_add, Nat.mul_one]
    omega
  | participant i off hi h => exact L.participant_word hi h
  | arenaHeader a off ha h =>
    have hoff : off + 8 ≤ L.arenaStride := by
      have := L.record_end
      have := L.index_end
      omega
    exact L.arena_word ha hoff
  | index a slot ha h => exact L.index_word ha h
  | record a off ha h => exact L.record_word ha h

end StoreRegion

/-- Fixed layout facts produced by `Config::resolve`. The line is 64 or
128 bytes; exact occupancy allocates a second line. -/
structure ConfiguredLayout (L : MappingLayout) where
  line : L.lineBytes = 64 ∨ L.lineBytes = 128
  arena_header : L.arenaHeader = L.lineBytes ∨ L.arenaHeader = 2 * L.lineBytes

/-- Sources of participant indices. Registration preserves the range-loop
index in ParticipantLock. An owner value comes from an O2/O3 that encoded
such an index (`owner_load_source`); `reap_owner` has no runtime bounds
check in release/verification builds. The owner constructor exposes the
writer's range membership and actual packed value, not a presumed guard. -/
inductive SlotIndex (capacity : Nat) where
  | loop (i : Nat) (member : i ∈ List.range capacity)
  | registered (i : Nat) (registration : i ∈ List.range capacity)
  | owner (word : Nat) (i reg : Fin wordLimit)
      (registration : i.val ∈ List.range capacity)
      (encoded : word = OwnerWord.pack i.val reg.val)

def SlotIndex.val {capacity : Nat} : SlotIndex capacity → Nat
  | .loop i _ | .registered i _ => i
  | .owner word _ _ _ _ => OwnerWord.slot word

theorem SlotIndex.bound {capacity : Nat} (i : SlotIndex capacity) : i.val < capacity := by
  cases i with
  | loop i h => exact List.mem_range.mp h
  | registered i h => exact List.mem_range.mp h
  | owner word i reg h encoded =>
    dsimp [val]
    rw [encoded, OwnerWord.slot_pack i.isLt]
    exact List.mem_range.mp h

/-- Build the participant-index source used by `reap_owner` directly from
O1's reads-from edge. The constructor's range premise is extracted from
the acquiring write, never from the assertion that guards debug builds. -/
theorem owner_load_index {X : Execution Ev Loc} {O : OwnerEvents X} {capacity : Nat}
    (S : OwnerSource O capacity) (hc : X.Consistent) {w r : Ev}
    (hrf : X.rf w r) (hloc : X.loc r = some O.own)
    (howner : OwnerWord.registration (X.rval r) ≠ 0) :
    ∃ i : SlotIndex capacity, i.val = OwnerWord.slot (X.rval r) := by
  obtain ⟨_, i, reg, hi, hval⟩ := owner_load_source S hc hrf hloc howner
  exact ⟨.owner (X.rval r) i reg hi hval, rfl⟩

/-- Every arena comes from the initialization loop or arena_of(epoch).
No bound on the loaded epoch is needed for modulo three. -/
inductive ArenaIndex where
  | initialLoop (i : Nat) (member : i ∈ List.range 3)
  | epoch (e : Nat)
  | previous (e : Nat)

def ArenaIndex.val : ArenaIndex → Nat
  | .initialLoop i _ => i
  | .epoch e => e % 3
  | .previous e => if e % 3 = 0 then 2 else e % 3 - 1

theorem ArenaIndex.bound (a : ArenaIndex) : a.val < 3 := by
  cases a with
  | initialLoop i h => exact List.mem_range.mp h
  | epoch e => exact Nat.mod_lt _ (by decide)
  | previous e => dsimp [val]; split <;> have := Nat.mod_lt e (by decide : 0 < 3) <;> omega

/-- Source fields, expressed as their ABI byte offsets. U32 fields are
conservatively covered by eight bytes. CacheHeader's last field is at 56;
ParticipantSlot's last field is chunk_end at 36. -/
inductive FixedAddress (L : MappingLayout) where
  | header (field : Fin 15)
  | ownerHighHalf
  | participant (slot : SlotIndex L.participants) (field : Fin 10)
  | control (arena : ArenaIndex)
  | readiness (arena : ArenaIndex)
  | occupancy (arena : ArenaIndex) (exactMode : L.arenaHeader = 2 * L.lineBytes)

def FixedAddress.address {L : MappingLayout} : FixedAddress L → Nat
  | .header field => 4 * field.val
  | .ownerHighHalf => 60
  | .participant slot field => L.lineBytes * (1 + slot.val) + 4 * field.val
  | .control a => L.arenasOffset + a.val * L.arenaStride
  | .readiness a => L.arenasOffset + a.val * L.arenaStride + 8
  | .occupancy a _ => L.arenasOffset + a.val * L.arenaStride + L.lineBytes

theorem FixedAddress.in_mapping {L : MappingLayout} (G : ConfiguredLayout L)
    (a : FixedAddress L) : a.address + 8 ≤ L.mappingBytes := by
  have hline : 64 ≤ L.lineBytes := by rcases G.line with h | h <;> omega
  have hhead : L.lineBytes ≤ L.arenaHeader := by
    rcases G.arena_header with h | h <;> omega
  have hstride : L.arenaHeader ≤ L.arenaStride := by
    have := L.index_end; have := L.record_end; omega
  cases a with
  | header field =>
    dsimp [address]
    have := field.isLt
    rw [L.mapping_end, L.arenas_start]
    simp only [Nat.mul_add, Nat.mul_one]
    omega
  | ownerHighHalf =>
    dsimp [address]
    have ha : L.lineBytes ≤ L.arenasOffset := by
      rw [L.arenas_start]
      simpa only [Nat.mul_one] using
        (Nat.mul_le_mul_left L.lineBytes (show 1 ≤ 1 + L.participants by omega))
    rw [L.mapping_end]
    omega
  | participant slot field =>
    exact L.participant_word slot.bound (by have := field.isLt; omega)
  | control a => simpa [address] using L.arena_word a.bound (show 0 + 8 ≤ L.arenaStride by omega)
  | readiness a => exact L.arena_word a.bound (by omega)
  | occupancy a h => exact L.arena_word a.bound (by omega)

/-- The fixed-word address obtained by following a nonempty owner value
is inside the mapping. This connects the O1 provenance proof to BD1's
actual participant address, including a reaper's writes in that slot. -/
theorem owner_participant_in_mapping {X : Execution Ev Loc} {O : OwnerEvents X}
    {L : MappingLayout} (G : ConfiguredLayout L) (S : OwnerSource O L.participants)
    (hc : X.Consistent) {w r : Ev} (hrf : X.rf w r)
    (hloc : X.loc r = some O.own)
    (howner : OwnerWord.registration (X.rval r) ≠ 0) (field : Fin 10) :
    L.lineBytes * (1 + OwnerWord.slot (X.rval r)) + 4 * field.val + 8 ≤
      L.mappingBytes := by
  obtain ⟨i, hi⟩ := owner_load_index S hc hrf hloc howner
  have h := FixedAddress.in_mapping G (.participant i field)
  simpa only [FixedAddress.address, hi] using h

/-- The implementation-to-model address bridge. The record and publication
cases expose their source indices and offsets so BD1 derives their bounds
from SP4 and the bucket mask, rather than assuming they are in the mapping.
`fixed_addr` covers the remaining header, pin, chunk, control and counter
words, whose indices come from bounded loops and view constructors.
For `reap_owner`, `owner_load_index` supplies the owner constructor from
O1 provenance and `owner_participant_in_mapping` proves its address bound. -/
structure StoreLayout (X : Execution Ev Loc) {C : CacheProtocol X}
    (A : ArenaProtocol X C) (Rv : ReserveProtocol X C) (I : IndexProtocol X A Rv) where
  layout : MappingLayout
  address : Loc → Nat
  configured : ConfiguredLayout layout
  record_bytes : layout.recordBytes = Rv.cap
  bucketMask : Nat
  bucket_count : bucketMask + 1 = layout.buckets
  record_addr : ∀ l, Rv.isRecord l → ∃ a, a < 3 ∧
    address l = layout.arenasOffset + a * layout.arenaStride +
      layout.recordOffset + Rv.recOff l
  init_record_bound : ∀ {x l}, A.isArenaInit x → X.loc x = some l →
    Rv.isRecord l → Rv.recOff l + 8 ≤ Rv.cap
  pub_addr : ∀ {x l}, X.loc x = some l → (I.isPubCas x ∨ I.isPubStore x) →
    I.isIndex l → ∃ a start probe, a < 3 ∧
      address l = layout.arenasOffset + a * layout.arenaStride +
        layout.arenaHeader + 8 * ((start + probe) &&& bucketMask)
  init_idx_addr : ∀ {x l}, A.isArenaInit x → X.loc x = some l →
    I.isIndex l → ∃ a slot, a < 3 ∧ slot < layout.buckets ∧
      address l = layout.arenasOffset + a * layout.arenaStride +
        layout.arenaHeader + 8 * slot
  fixed_addr : ∀ {x l}, X.isW x → X.loc x = some l →
    ¬ Rv.isRecord l → ¬ I.isIndex l →
    ∃ r : FixedAddress layout, address l = r.address

namespace ReserveProtocol

variable {X : Execution Ev Loc} {C : CacheProtocol X} (Rv : ReserveProtocol X C)

/-- **BD1.** A record store of a store attempt addresses a word of
the record area. -/
theorem record_store_in_area (hc : X.Consistent) {i : C.ι} {w x : Ev} {l : Loc}
    (hw : C.isAttempt i w) (hx : (C.pins i).inAttempt w x) (hxW : X.isW x)
    (hxl : X.loc x = some l) (hrec : Rv.isRecord l) : Rv.recOff l + 8 ≤ Rv.cap := by
  obtain ⟨r, hr, h₁, h₂⟩ := Rv.rec_in_res hw hx hxW hxl hrec
  have hres : Rv.reserves i w r := by
    rcases hr with ⟨b, hb, hbB, hwin, rfl⟩ | ⟨le, lc, ln, s, hu, rfl⟩
    · exact Or.inl ⟨b, hb, hbB, hwin, rfl⟩
    · exact Or.inr ⟨le, lc, ln, s, hu, rfl⟩
  have := Rv.reserve_in_area hc hres
  omega

end ReserveProtocol

/-- **BD1, all shared stores.** Record bounds come from SP4, publication
indices from masking, and fixed words from their bounded view forms. -/
theorem StoreLayout.shared_store_in_mapping {X : Execution Ev Loc}
    {C : CacheProtocol X} {A : ArenaProtocol X C} {Rv : ReserveProtocol X C}
    {I : IndexProtocol X A Rv} (M : StoreLayout X A Rv I) (hc : X.Consistent)
    {x : Ev} {l : Loc} (hx : X.isW x) (hl : X.loc x = some l) :
    M.address l + 8 ≤ M.layout.mappingBytes := by
  by_cases hrec : Rv.isRecord l
  · obtain ⟨a, ha, haddr⟩ := M.record_addr l hrec
    have hbound : Rv.recOff l + 8 ≤ Rv.cap := by
      rcases A.aw_owner hx hl (I.record_arena hrec) with hinit | ⟨i, w, hw, hact⟩
      · exact M.init_record_bound hinit hl hrec
      · exact Rv.record_store_in_area hc hw hact hx hl hrec
    rw [haddr]
    exact M.layout.record_word ha (by rw [M.record_bytes]; exact hbound)
  · by_cases hidx : I.isIndex l
    · rcases I.idx_writes hx hl hidx with ⟨hinit, _⟩ | ⟨hk, _, _, _, _⟩
      · obtain ⟨a, slot, ha, hs, haddr⟩ := M.init_idx_addr hinit hl hidx
        rw [haddr]
        exact M.layout.index_word ha hs
      · obtain ⟨a, start, probe, ha, haddr⟩ := M.pub_addr hl hk hidx
        rw [haddr]
        exact M.layout.index_word ha
          (Bounds.masked_index_in_bounds M.bucket_count)
    · obtain ⟨r, haddr⟩ := M.fixed_addr hx hl hrec hidx
      rw [haddr]
      exact r.in_mapping M.configured

namespace IndexProtocol

variable {X : Execution Ev Loc} {C : CacheProtocol X} {A : ArenaProtocol X C}
  {Rv : ReserveProtocol X C} (I : IndexProtocol X A Rv)

/-- **BD2, the index values.** Every value an index slot holds encodes an
offset whose first 16 bytes lie in the record area. -/
theorem entry_in_area (hc : X.Consistent) {x : Ev} {l : Loc} (hxW : X.isW x)
    (hxl : X.loc x = some l) (hl : I.isIndex l) : I.entryOff (X.wval x) + 16 ≤ Rv.cap := by
  have hcap := I.cap_ge
  rcases I.idx_writes hxW hxl hl with ⟨-, hz⟩ | ⟨hxk, i, w, hw, hx⟩
  · rw [hz, I.zero_off]; omega
  · have := Rv.reserve_in_area hc (I.pub_off hxk hw hx)
    have := Rv.need_pos hw
    omega

/-- **BD2, the index loads.** Every value an index load returns, current
or stale, encodes an offset whose first 16 bytes lie in the record area. -/
theorem index_load_in_area (hc : X.Consistent) {x r : Ev} {l : Loc} (hrf : X.rf x r)
    (hrl : X.loc r = some l) (hl : I.isIndex l) : I.entryOff (X.rval r) + 16 ≤ Rv.cap := by
  have ⟨hxW, _, hlx, _⟩ := hc.rf_wf hrf
  rw [hc.rf_val hrf]
  exact I.entry_in_area hc hxW (hlx.trans hrl) hl

/-- The readers' bound `len <= ras.wrapping_sub(off).wrapping_sub(8)`, in
`u32` arithmetic, is exact once the header word is in the area. A5 keeps
the area below `2^31`. -/
theorem check_exact {ras off len : Nat} (hoff : off + 8 ≤ ras) (hras : ras < wordLimit)
    (hpass : len ≤ ((ras + wordLimit - off) % wordLimit + wordLimit - 8) % wordLimit) :
    len + off + 8 ≤ ras := by
  unfold wordLimit at *
  have h₁ : (ras + 4294967296 - off) % 4294967296 = ras - off := by omega
  rw [h₁] at hpass
  have h₂ : (ras - off + 4294967296 - 8) % 4294967296 = ras - off - 8 := by omega
  rw [h₂] at hpass
  omega

/-- **BD2.** A reader (`probe()` or `entry_has_key()`) loads
index slot `l` at `r` and decodes the offset `off`. Whatever its header
load returns for `len` and its lengths load for `kl` and `vl`, once its
checks pass (`8 <= len <= ras - off - 8` in `u32`, and
`8 + align8(kl) + vl = len`), the header, the lengths word, the padded key
and every value word it copies lie in the record area. -/
theorem reader_in_bounds (hc : X.Consistent) {x r : Ev} {l : Loc} {len kl vl pos : Nat}
    (hrf : X.rf x r) (hrl : X.loc r = some l) (hl : I.isIndex l)
    (hpass : len ≤
      ((Rv.cap + wordLimit - I.entryOff (X.rval r)) % wordLimit + wordLimit - 8) % wordLimit)
    (hlo : 8 ≤ len) (hcons : 8 + (kl + 7) / 8 * 8 + vl = len) (hpos : pos < vl)
    (hpos8 : pos % 8 = 0) :
    I.entryOff (X.rval r) + 8 ≤ Rv.cap ∧ I.entryOff (X.rval r) + 16 ≤ Rv.cap ∧
      I.entryOff (X.rval r) + 16 + (kl + 7) / 8 * 8 ≤ Rv.cap ∧
      I.entryOff (X.rval r) + 16 + (kl + 7) / 8 * 8 + pos + 8 ≤ Rv.cap := by
  have hoff := I.index_load_in_area hc hrf hrl hl
  have hras : Rv.cap < wordLimit := by
    have := Rv.a5; unfold bumpLimit at this; unfold wordLimit; omega
  have hhi := check_exact (by omega) hras hpass
  exact ⟨by omega, Bounds.lengths_in_bounds hlo hhi, Bounds.key_in_bounds hhi hcons,
    Bounds.value_in_bounds I.cap_aligned (I.entryOff_aligned _) hhi hcons hpos hpos8⟩

end IndexProtocol
end Execution
end Sgc
