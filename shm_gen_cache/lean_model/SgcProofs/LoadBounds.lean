import SgcProofs.Access
import Init.Data.Nat.Power2.Bitwise

/-!
# BD2: source load addresses

LoadAddress classifies the operands used by probe, entry_has_key, the
participant/owner/rotation paths, and readiness C0. Its record constructors
carry the checks executed before the particular load, rather than a proof
that its address is in the mapping. The index entry offset is derived from
its reads-from source using IX1/SP4. ConfiguredLayout is Config::resolve's
layout, shared by attachers under A7. Pointer arithmetic is natural-number
arithmetic under A5's no-overflow target/layout assumptions.
-/
namespace Sgc.Execution

variable {Ev Loc : Type} {X : Execution Ev Loc} {C : CacheProtocol X}
  {A : ArenaProtocol X C} {Rv : ReserveProtocol X C} {I : IndexProtocol X A Rv}

/-- Exactness, not merely soundness, of both source header bounds:
put.rs subtracts twice in u32; probe.rs widens to u64 for the second
subtraction. An index entry's stronger off+16 bound supplies hoff. -/
theorem wrapped_header_bounds_exact {ras off : Nat} (hoff : off + 8 ≤ ras)
    (hras : ras < wordLimit) :
    ((ras + wordLimit - off) % wordLimit + wordLimit - 8) % wordLimit = ras - off - 8 ∧
    ((ras + wordLimit - off) % wordLimit + 18446744073709551616 - 8) %
      18446744073709551616 = ras - off - 8 := by
  unfold wordLimit at *
  have h : (ras + 4294967296 - off) % 4294967296 = ras - off := by omega
  rw [h]
  constructor <;> omega

theorem header_guard_iff {ras off len : Nat} (hoff : off + 8 ≤ ras)
    (hras : ras < wordLimit) :
    (len ≤ ((ras + wordLimit - off) % wordLimit + wordLimit - 8) % wordLimit ↔
      len + off + 8 ≤ ras) ∧
    (len ≤ ((ras + wordLimit - off) % wordLimit + 18446744073709551616 - 8) %
      18446744073709551616 ↔ len + off + 8 ≤ ras) := by
  obtain ⟨h32, h64⟩ := wrapped_header_bounds_exact hoff hras
  rw [h32, h64]
  omega

/-- The two index-address expressions in find_candidate, plus home and
scalar probes. The false branch carries its actual branch guard. -/
inductive IndexAddress (b : Nat) where
  | masked (start distance : Nat)
  | group (base i : Nat) (guard : base ≤ b - 8) (loop : i < 8)

def IndexAddress.slot {b : Nat} : IndexAddress b → Nat
  | .masked start distance => (start + distance) &&& (b - 1)
  | .group base i _ _ => base + i

theorem IndexAddress.bound {b : Nat} (hb : 8 ≤ b) (hgroup : b % 8 = 0)
    (a : IndexAddress b) : a.slot < b := by
  cases a with
  | masked start distance => exact Bounds.masked_index_in_bounds (by omega)
  | group base i hg hi => exact Bounds.group_scan_in_bounds hgroup hb hg hi

/-- A source index load and its reads-from witness. There is no epoch or
freshness premise: stale entries have the same offset bound. -/
structure ReadEntry (I : IndexProtocol X A Rv) where
  read : Ev
  source : Ev
  loc : Loc
  rf : X.rf source read
  at_index : X.loc read = some loc
  index : I.isIndex loc

def ReadEntry.off (e : ReadEntry I) : Nat := I.entryOff (X.rval e.read)

theorem ReadEntry.bound (hc : X.Consistent) (e : ReadEntry I) : e.off + 16 ≤ Rv.cap :=
  I.index_load_in_area hc e.rf e.at_index e.index

/-- D4 and D5 precede the lengths consistency check; key and value loads
follow it. The key loop uses ceil(kl/8) words including its masked tail. -/
inductive RecordRead (I : IndexProtocol X A Rv) where
  | header (e : ReadEntry I)
  | lengths (e : ReadEntry I)
  | key (e : ReadEntry I) (len kl vl pos : Nat)
      (headerCheck : len + e.off + 8 ≤ Rv.cap)
      (lengthsCheck : 8 + (kl + 7) / 8 * 8 + vl = len)
      (loop : pos < (kl + 7) / 8)
  | value (e : ReadEntry I) (len kl vl pos : Nat)
      (headerCheck : len + e.off + 8 ≤ Rv.cap)
      (lengthsCheck : 8 + (kl + 7) / 8 * 8 + vl = len)
      (loop : pos < vl) (step8 : pos % 8 = 0)

def RecordRead.offset : RecordRead I → Nat
  | .header e => e.off
  | .lengths e => e.off + 8
  | .key e _ _ _ pos _ _ _ => e.off + 16 + pos * 8
  | .value e _ kl _ pos _ _ _ _ => e.off + 16 + (kl + 7) / 8 * 8 + pos

theorem RecordRead.bound (hc : X.Consistent) (r : RecordRead I) :
    r.offset + 8 ≤ Rv.cap := by
  cases r with
  | header e => have := e.bound hc; dsimp [offset]; omega
  | lengths e => have := e.bound hc; dsimp [offset]; omega
  | key e len kl vl pos hh hl hp => dsimp [offset]; omega
  | value e len kl vl pos hh hl hp hs =>
    exact Bounds.value_in_bounds I.cap_aligned (I.entryOff_aligned _) hh hl hp hs

/-- The reader site selects the actual source arithmetic: entry_has_key
(put.rs:131) subtracts twice in u32; probe (probe.rs:166) widens the first
u32 subtraction to u64 before subtracting the header's eight bytes. -/
inductive PayloadReader where
  | entryHasKey
  | probe

def PayloadReader.headerBound (reader : PayloadReader) (ras off : Nat) : Nat :=
  match reader with
  | .entryHasKey => ((ras + wordLimit - off) % wordLimit + wordLimit - 8) % wordLimit
  | .probe => ((ras + wordLimit - off) % wordLimit + 18446744073709551616 - 8) %
      18446744073709551616

/-- Convert the guard at the selected source site to the mathematical
bound. In particular the probe branch uses the u64 half of header_guard_iff. -/
theorem PayloadReader.guard_iff (reader : PayloadReader) {ras off len : Nat}
    (hoff : off + 8 ≤ ras) (hras : ras < wordLimit) :
    (len ≤ reader.headerBound ras off ↔ len + off + 8 ≤ ras) := by
  obtain ⟨h32, h64⟩ := header_guard_iff (len := len) hoff hras
  cases reader with
  | entryHasKey => exact h32
  | probe => exact h64

/-- Successful local checks are attached to the loads that supply their
operands. The header length is the high u32 of the actual D4 value, and key/value
lengths are the low/high u32 of the actual D5 value (records.rs). The two
successful checks occur before the payload load in both source readers.
No trustworthy length or bounded address is assumed. -/
structure CheckedPayloadRead (I : IndexProtocol X A Rv) where
  reader : PayloadReader
  entry : ReadEntry I
  header : Ev
  lengths : Ev
  read : Ev
  header_read : X.isR header
  lengths_read : X.isR lengths
  payload_read : X.isR read
  header_pass : 8 ≤ valLen (X.rval header) ∧
    valLen (X.rval header) ≤ reader.headerBound Rv.cap entry.off
  before_lengths : X.sb header lengths
  lengths_pass : 8 + (keyLen (X.rval lengths) + 7) / 8 * 8 + valLen (X.rval lengths) =
    valLen (X.rval header)
  before_payload : X.sb lengths read
  pos : Nat
  /-- key_matches scans full words then an optional masked tail; the value
  loop is pos<value_len with pos increasing by eight. -/
  shape : (pos < (keyLen (X.rval lengths) + 7) / 8) ∨
    (pos < valLen (X.rval lengths) ∧ pos % 8 = 0)

/-- The source event checks precede payload loads and imply the exact
arithmetic predicates, with lengths taken from those actual loads. -/
theorem CheckedPayloadRead.checked_before_load (R : CheckedPayloadRead I)
    (hc : X.Consistent) :
    X.hb R.header R.read ∧ X.sb R.lengths R.read ∧
    valLen (X.rval R.header) + R.entry.off + 8 ≤ Rv.cap ∧
    8 + (keyLen (X.rval R.lengths) + 7) / 8 * 8 + valLen (X.rval R.lengths) =
      valLen (X.rval R.header) := by
  have ho := R.entry.bound hc
  have hcap : Rv.cap < wordLimit := by
    have := Rv.a5; unfold bumpLimit at this; unfold wordLimit; omega
  exact ⟨hb_trans (hb_of_sb R.before_lengths) (hb_of_sb R.before_payload),
    R.before_payload, (R.reader.guard_iff (by omega) hcap).mp R.header_pass.2,
    R.lengths_pass⟩

/-- Exhaustive source address forms for shared loads, including C0 and
fixed metadata. Reading earlier shared values changes operands, not forms. -/
inductive LoadAddress (L : MappingLayout) (I : IndexProtocol X A Rv) where
  | fixed (a : FixedAddress L)
  | index (a : ArenaIndex) (slot : IndexAddress L.buckets)
  | record (a : ArenaIndex) (word : RecordRead I)

def LoadAddress.address {L : MappingLayout} : LoadAddress L I → Nat
  | .fixed a => a.address
  | .index a slot => L.arenasOffset + a.val * L.arenaStride + L.arenaHeader + 8 * slot.slot
  | .record a word => L.arenasOffset + a.val * L.arenaStride + L.recordOffset + word.offset

/-- A payload load with its actual address expression. The same entry,
arena and loaded lengths determine header, lengths and payload addresses;
these equalities are pointer-expression facts, not bounds. -/
structure LocatedPayloadRead (L : MappingLayout) (I : IndexProtocol X A Rv)
    (address : Loc → Nat) extends CheckedPayloadRead I where
  arena : ArenaIndex
  headerLoc : Loc
  lengthsLoc : Loc
  payloadLoc : Loc
  at_header : X.loc header = some headerLoc
  at_lengths : X.loc lengths = some lengthsLoc
  at_payload : X.loc read = some payloadLoc
  header_address : address headerLoc =
    L.arenasOffset + arena.val * L.arenaStride + L.recordOffset + entry.off
  lengths_address : address lengthsLoc =
    L.arenasOffset + arena.val * L.arenaStride + L.recordOffset + entry.off + 8
  /-- Left is key_matches' word index, right probe's byte position. -/
  payload_address :
    (pos < (keyLen (X.rval lengths) + 7) / 8 ∧ address payloadLoc =
      L.arenasOffset + arena.val * L.arenaStride + L.recordOffset + entry.off + 16 + pos * 8) ∨
    (pos < valLen (X.rval lengths) ∧ pos % 8 = 0 ∧ address payloadLoc =
      L.arenasOffset + arena.val * L.arenaStride + L.recordOffset + entry.off + 16 +
        (keyLen (X.rval lengths) + 7) / 8 * 8 + pos)

theorem LocatedPayloadRead.in_mapping {L : MappingLayout} {address : Loc → Nat}
    (P : LocatedPayloadRead L I address) (hc : X.Consistent)
    (hbytes : L.recordBytes = Rv.cap) : address P.payloadLoc + 8 ≤ L.mappingBytes := by
  obtain ⟨_, _, hh, hl⟩ := P.checked_before_load hc
  rcases P.payload_address with ⟨hp, ha⟩ | ⟨hp, hs, ha⟩
  · have hb := (RecordRead.key P.entry (valLen (X.rval P.header))
        (keyLen (X.rval P.lengths)) (valLen (X.rval P.lengths)) P.pos hh hl hp).bound hc
    have hm := L.record_word P.arena.bound (by rw [hbytes]; exact hb)
    simpa only [RecordRead.offset, ha, Nat.add_assoc] using hm
  · have hb := (RecordRead.value P.entry (valLen (X.rval P.header))
        (keyLen (X.rval P.lengths)) (valLen (X.rval P.lengths)) P.pos hh hl hp hs).bound hc
    have hm := L.record_word P.arena.bound (by rw [hbytes]; exact hb)
    simpa only [RecordRead.offset, ha, Nat.add_assoc] using hm

/-- Event-indexed source forms. The header/length constructors retain the
index read followed by this load. Payload constructors use actual loaded
values and link all three addresses to that same record. -/
inductive SourceLoad (L : MappingLayout) (I : IndexProtocol X A Rv)
    (address : Loc → Nat) (x : Ev) (l : Loc) where
  | fixed (a : FixedAddress L) (addr : address l = a.address)
  | index (a : ArenaIndex) (slot : IndexAddress L.buckets)
      (addr : address l = L.arenasOffset + a.val * L.arenaStride + L.arenaHeader + 8 * slot.slot)
  | header (a : ArenaIndex) (e : ReadEntry I) (before : X.sb e.read x)
      (addr : address l = L.arenasOffset + a.val * L.arenaStride + L.recordOffset + e.off)
  | lengths (a : ArenaIndex) (e : ReadEntry I) (before : X.sb e.read x)
      (addr : address l = L.arenasOffset + a.val * L.arenaStride + L.recordOffset + e.off + 8)
  | payload (P : LocatedPayloadRead L I address) (event : P.read = x) (location : P.payloadLoc = l)

/-- Reads through reap_owner's participant(owner.slot()) reference. steal
reads state at byte 8 (P0, P4 and P5, including CAS failure), and the start
time at byte 16 (T0). Its Stolen branch calls release_claim on that same
reference: the plain state load, pin assertion and P3 CAS also read it. -/
inductive OwnerStealSite where
  | state | startTime | compareExchange | register | releaseState | releasePin | release

def OwnerStealSite.field : OwnerStealSite → Fin 10
  | .state | .compareExchange | .register | .releaseState | .release => ⟨2, by decide⟩
  | .releasePin => ⟨0, by decide⟩
  | .startTime => ⟨4, by decide⟩

/-- Source operands for a modeled reap_owner load. rotation.rs passes
participant(hp, owner.slot()) to steal. Retain O1's actual reads-from source,
its nonempty branch and the pointer expression, without a bounded-slot or
holder-identity premise. This applies even when no steal succeeds. -/
structure OwnerStealRead (L : MappingLayout) (O : OwnerEvents X)
    (address : Loc → Nat) (x : Ev) (l : Loc) where
  ownerRead : Ev
  ownerWrite : Ev
  rf : X.rf ownerWrite ownerRead
  at_owner : X.loc ownerRead = some O.own
  nonempty : OwnerWord.registration (X.rval ownerRead) ≠ 0
  before : X.sb ownerRead x
  site : OwnerStealSite
  at_slot : address l = L.lineBytes * (1 + OwnerWord.slot (X.rval ownerRead)) +
    4 * site.field.val

/-- Decode the actual O2/O3 value observed by O1, then use its writer's
registration range. Neither a successful P4 nor the assertion is needed. -/
theorem OwnerStealRead.in_mapping {L : MappingLayout} {O : OwnerEvents X}
    {address : Loc → Nat} {x : Ev} {l : Loc}
    (R : OwnerStealRead L O address x l) (G : ConfiguredLayout L)
    (S : OwnerSource O L.participants) (hc : X.Consistent) :
    address l + 8 ≤ L.mappingBytes := by
  rw [R.at_slot]
  exact owner_participant_in_mapping G S hc R.rf R.at_owner R.nonempty R.site.field

structure LoadLayout (X : Execution Ev Loc) {C : CacheProtocol X}
    {A : ArenaProtocol X C} {Rv : ReserveProtocol X C}
    (I : IndexProtocol X A Rv) (O : OwnerEvents X) where
  layout : MappingLayout
  configured : ConfiguredLayout layout
  record_bytes : layout.recordBytes = Rv.cap
  buckets_ge : 8 ≤ layout.buckets
  buckets_group : layout.buckets % 8 = 0
  /-- A5, config.rs:81: successful resolve excludes b & (b - 1) != 0.
  The natural subtraction is exact because buckets_ge excludes zero. -/
  buckets_bit_test : layout.buckets &&& (layout.buckets - 1) = 0
  address : Loc → Nat
  /-- Every modeled read through reap_owner's owner-derived participant
  reference: P0/T0/P4/P5 in steal and P3 in release_claim on Stolen, plus
  any modeled plain state/pin reads of release_claim. This source call-path
  classification includes read halves of both successful and failed CASes. -/
  isOwnerStealRead : Ev → Prop
  owner_reads : ∀ {x l}, isOwnerStealRead x → X.isR x → X.loc x = some l →
    Nonempty (OwnerStealRead layout O address x l)
  /-- Other source sites use cache/mod.rs view constructors, rotation and
  promotion loops, and probe.rs / put.rs validation. The exact-mode W6
  read in occupancy.rs:82 is a modeled fixed occupancy-word load here;
  FixedAddress.occupancy requires the exact-mode arena header layout.
  Owner-derived reads cannot use this fixed-address case. -/
  loads : ∀ {x l}, ¬ isOwnerStealRead x → X.isR x → X.loc x = some l →
    Nonempty (SourceLoad layout I address x l)
  /-- Exactly the shared loads in key_matches and the probe value loop;
  D4 and the lengths-word load are excluded. Source F11 supplies both
  successful branch tests and their program order, including failed reads. -/
  isPayloadRead : Ev → Prop
  payload_checks : ∀ {x}, isPayloadRead x → ∃ R : CheckedPayloadRead I, R.read = x

variable {O : OwnerEvents X}

/-- A5's power-of-two multiple of eight follows from the exact accepted
Config::resolve guard, rather than an extra assumed power-of-two fact. -/
theorem LoadLayout.bucket_configuration (M : LoadLayout X I O) :
    M.layout.buckets.isPowerOfTwo ∧ M.layout.buckets % 8 = 0 := by
  have hn : M.layout.buckets ≠ 0 := by have := M.buckets_ge; omega
  exact ⟨(Nat.and_sub_one_eq_zero_iff_isPowerOfTwo hn).mp M.buckets_bit_test,
    M.buckets_group⟩

/-- All source payload loads use the values returned by preceding header
and lengths loads, whose successful guards are represented in R. -/
theorem LoadLayout.payload_checks_before_load (M : LoadLayout X I O) (hc : X.Consistent)
    {x : Ev} (hx : M.isPayloadRead x) : ∃ R : CheckedPayloadRead I, R.read = x ∧
      X.hb R.header x ∧ X.sb R.lengths x ∧
      valLen (X.rval R.header) + R.entry.off + 8 ≤ Rv.cap ∧
      8 + (keyLen (X.rval R.lengths) + 7) / 8 * 8 + valLen (X.rval R.lengths) =
        valLen (X.rval R.header) := by
  obtain ⟨R, rfl⟩ := M.payload_checks hx
  exact ⟨R, rfl, R.checked_before_load hc⟩

theorem LoadLayout.shared_load_in_mapping (M : LoadLayout X I O)
    (S : OwnerSource O M.layout.participants) (hc : X.Consistent)
    {x : Ev} {l : Loc} (hr : X.isR x) (hl : X.loc x = some l) :
    M.address l + 8 ≤ M.layout.mappingBytes := by
  classical
  by_cases ho : M.isOwnerStealRead x
  · obtain ⟨R⟩ := M.owner_reads ho hr hl
    exact R.in_mapping M.configured S hc
  obtain ⟨a⟩ := M.loads ho hr hl
  cases a with
  | fixed a h => rw [h]; exact a.in_mapping M.configured
  | index a slot h =>
    rw [h]; exact M.layout.index_word a.bound (slot.bound M.buckets_ge M.buckets_group)
  | header a e _ h =>
    rw [h]
    exact M.layout.record_word a.bound (by rw [M.record_bytes]; have := e.bound hc; omega)
  | lengths a e _ h =>
    rw [h]
    have hm := M.layout.record_word a.bound (show e.off + 8 + 8 ≤ M.layout.recordBytes by
      rw [M.record_bytes]; exact e.bound hc)
    simpa only [Nat.add_assoc] using hm
  | payload P _ he => rw [← he]; exact P.in_mapping hc M.record_bytes

/-- Source provenance for a slot reference, including the second registration
created by reap_owner. Loop origins cover registration, reap_dead_participant,
and rotation's pin scan. Retained origins are the index saved in ParticipantLock.
Owner origins retain O1's actual loaded word and branch, not an assumed bound.
The release_claim called at rotation.rs:241 uses that same owner-derived slot. -/
inductive LoadSlotSource (X : Execution Ev Loc) (O : OwnerEvents X) (capacity : Nat) where
  | scan (i : Nat) (iteration : i ∈ List.range capacity)
  | retained (i : Nat) (registration : i ∈ List.range capacity)
  | owner (w r : Ev) (rf : X.rf w r) (location : X.loc r = some O.own)
      (nonempty : OwnerWord.registration (X.rval r) ≠ 0)

def LoadSlotSource.index {O : OwnerEvents X} {capacity : Nat} :
    LoadSlotSource X O capacity → Nat
  | .scan i _ | .retained i _ => i
  | .owner _ r _ _ _ => OwnerWord.slot (X.rval r)

/-- Derive the slot index from the source. In particular the owner case
uses the O2/O3 writer's registration range via owner_load_index; it does
not assume the old owner is alive, that O1 is fresh, or that an assertion ran. -/
theorem LoadSlotSource.classified {O : OwnerEvents X} {capacity : Nat}
    (S : OwnerSource O capacity) (hc : X.Consistent) (r : LoadSlotSource X O capacity) :
    ∃ i : SlotIndex capacity, i.val = r.index := by
  cases r with
  | scan i hi => exact ⟨.loop i hi, rfl⟩
  | retained i hi => exact ⟨.registered i hi, rfl⟩
  | owner w r hrf hl hn => exact owner_load_index S hc hrf hl hn

/-- Enumeration of omitted participant read sites, with exact ABI field
indices (four-byte units). release_claim's state read is also P3 in the event
model; including it here makes the bounds independent of that choice.
registrationId includes lock construction/assert_usable and acquire_rotation.
releasePin/releaseState cover lock drop, reap_participant and reap_owner.
The latter two may own a second registration simultaneously with their lock. -/
inductive AuxiliarySlotSite where
  | claimPin | releasePin | releaseState | registrationId | isFree
  | rotatePin | assertUsablePin | unwindPin | waitPin

def AuxiliarySlotSite.field : AuxiliarySlotSite → Fin 10
  | .releaseState | .registrationId | .isFree => ⟨2, by decide⟩
  | .claimPin | .releasePin | .rotatePin | .assertUsablePin | .unwindPin | .waitPin =>
      ⟨0, by decide⟩

/-- Source enumeration for reads omitted from the concurrency graph:
the initialization magic CAS at cache/mod.rs:191; participant.rs:212,231,
238,353,360; rotation.rs:76; lock.rs:150,175; and wait.rs:81,1054.
WordHalf::read reads the full u64 at a scan pin or rotation_owner (56).
On AArch64, arm_wfet also reads the watched low pin half at its base or
the high owner half at byte 60. Each slot reference carries its origin. -/
inductive AuxiliaryLoad (L : MappingLayout) (X : Execution Ev Loc) (O : OwnerEvents X) where
  | slot (site : AuxiliarySlotSite) (source : LoadSlotSource X O L.participants)
  | initMagic
  | waitOwner
  | waitOwnerHighHalf

def AuxiliaryLoad.address {L : MappingLayout} {O : OwnerEvents X} :
    AuxiliaryLoad L X O → Nat
  | .slot site source => L.lineBytes * (1 + source.index) + 4 * site.field.val
  | .initMagic => 0
  | .waitOwner => 56
  | .waitOwnerHighHalf => 60

/-- Exhaustive source classification: every enumerated omitted Rust load
maps to a fixed address. The owner case is derived from O1's reads-from
source, so even release_claim's second registration needs no range premise.
No source-form disjunction or bounded-address hypothesis is supplied. -/
theorem AuxiliaryLoad.classified {L : MappingLayout} {O : OwnerEvents X}
    (S : OwnerSource O L.participants) (hc : X.Consistent) (r : AuxiliaryLoad L X O) :
    ∃ a : FixedAddress L, r.address = a.address := by
  cases r with
  | slot site source =>
    obtain ⟨i, hi⟩ := source.classified S hc
    exact ⟨.participant i site.field, by simp only [address, FixedAddress.address, hi]⟩
  | initMagic => exact ⟨.header ⟨0, by decide⟩, rfl⟩
  | waitOwner => exact ⟨.header ⟨14, by decide⟩, rfl⟩
  | waitOwnerHighHalf => exact ⟨.ownerHighHalf, rfl⟩

theorem AuxiliaryLoad.in_mapping {L : MappingLayout} {O : OwnerEvents X}
    (G : ConfiguredLayout L) (S : OwnerSource O L.participants) (hc : X.Consistent)
    (r : AuxiliaryLoad L X O) : r.address + 8 ≤ L.mappingBytes := by
  obtain ⟨a, ha⟩ := r.classified S hc
  rw [ha]
  exact a.in_mapping G

/-- Address forms for extracted and omitted reads. Modeled reads retain
their event and location; omitted read sites use AuxiliarySlotSite or
waitOwner. ImplementationReadSource below supplies the separate exhaustive
coverage obligation over all dynamic Rust load occurrences. -/
inductive ImplementationLoad (M : LoadLayout X I O) where
  | modeled (x : Ev) (l : Loc) (read : X.isR x) (location : X.loc x = some l)
  | auxiliary (r : AuxiliaryLoad M.layout X O)

def ImplementationLoad.address {M : LoadLayout X I O} :
    ImplementationLoad M → Nat
  | .modeled _ l _ _ => M.address l
  | .auxiliary r => r.address

theorem ImplementationLoad.in_mapping {M : LoadLayout X I O}
    (r : ImplementationLoad M) (S : OwnerSource O M.layout.participants)
    (hc : X.Consistent) :
    r.address + 8 ≤ M.layout.mappingBytes := by
  cases r with
  | modeled x l hr hl => exact M.shared_load_in_mapping S hc hr hl
  | auxiliary r => exact r.in_mapping M.configured S hc

/-- Explicit source coverage obligation for the *whole* Rust load trace.
Read indexes dynamic source load occurrences, including loads omitted from
Execution. event is the extraction map: Some x for an event represented in
X, None for an omitted read. address is that occurrence's actual byte address.

The modeled and omitted fields require exhaustive source adapters, not just
a list of available constructors. Modeled includes W6's exact-occupancy
load on the existing-entry path. Omitted covers the initialization magic
CAS read, participant own/scan/owner loads, and wait-helper loads (including
the AArch64 low pin or high owner half) at every call occurrence. Each
adapter must supply the source operand equality; no mapping bound is assumed. -/
structure ImplementationReadSource (M : LoadLayout X I O) (Read : Type) where
  event : Read → Option Ev
  address : Read → Nat
  modeled : ∀ r x, event r = some x → ∃ l,
    X.isR x ∧ X.loc x = some l ∧ address r = M.address l
  omitted : ∀ r, event r = none → ∃ a : AuxiliaryLoad M.layout X O,
    address r = a.address

/-- Every Rust read occurrence, including one absent from the concurrency
execution, has a load form with its actual address. -/
theorem ImplementationReadSource.classified {M : LoadLayout X I O} {Read : Type}
    (B : ImplementationReadSource M Read) (r : Read) :
    ∃ a : ImplementationLoad M, B.address r = a.address := by
  cases he : B.event r with
  | some x =>
    obtain ⟨l, hr, hl, ha⟩ := B.modeled r x he
    exact ⟨.modeled x l hr hl, ha⟩
  | none =>
    obtain ⟨a, ha⟩ := B.omitted r he
    exact ⟨.auxiliary a, ha⟩

/-- The complete source trace is bounded only after discharging its
exhaustive adapter, including the omitted-load coverage field. -/
theorem LoadLayout.all_source_loads_in_mapping (M : LoadLayout X I O)
    (S : OwnerSource O M.layout.participants) (hc : X.Consistent)
    {Read : Type} (B : ImplementationReadSource M Read) (r : Read) :
    B.address r + 8 ≤ M.layout.mappingBytes := by
  obtain ⟨a, ha⟩ := B.classified r
  rw [ha]
  exact a.in_mapping S hc

/-- C0 specifically addresses readiness at offset 8 in arena_of(epoch). -/
theorem readiness_load_in_mapping {L : MappingLayout} (G : ConfiguredLayout L) (epoch : Nat) :
    L.arenasOffset + epoch % 3 * L.arenaStride + 8 + 8 ≤ L.mappingBytes :=
  (FixedAddress.readiness (.epoch epoch)).in_mapping G

/-- Probe's output guard and 8-byte loop bound every copy, including the
padded final word; callers supply align8(output_capacity) writable bytes. -/
theorem output_copy_in_bounds {cap vl pos : Nat} (hfit : vl ≤ cap)
    (hpos : pos < vl) (hstep : pos % 8 = 0) :
    pos + 8 ≤ (vl + 7) / 8 * 8 ∧ (vl + 7) / 8 * 8 ≤ (cap + 7) / 8 * 8 := by
  have h := Bounds.output_in_bounds (Nat.le_refl vl) hpos hstep
  constructor
  · exact h
  · omega

/-- Safe OutputBuffer values originate at output.rs::new's unconditional
assert. Private fields prevent later capacity/word-count mutation; Clone
copies both fields, and words_mut only lends the fixed-size backing array.
These constructors describe those source operations, including failed
assertions by the absence of a constructed value. -/
inductive SafeOutputBuffer where
  | new (words capacity : Nat) (assertion : capacity ≤ 8 * words)
  | clone (buffer : SafeOutputBuffer)
  | wordsMut (buffer : SafeOutputBuffer)

def SafeOutputBuffer.words : SafeOutputBuffer → Nat
  | .new words _ _ => words
  | .clone b | .wordsMut b => b.words

def SafeOutputBuffer.capacity : SafeOutputBuffer → Nat
  | .new _ capacity _ => capacity
  | .clone b | .wordsMut b => b.capacity

/-- The invariant checked by new survives every safe public operation
that can supply a lookup buffer; the mutable view cannot change capacity. -/
theorem SafeOutputBuffer.capacity_bound (b : SafeOutputBuffer) :
    b.capacity ≤ b.words * 8 := by
  induction b with
  | new words capacity assertion => simp only [SafeOutputBuffer.capacity, SafeOutputBuffer.words]; omega
  | clone b ih => exact ih
  | wordsMut b ih => exact ih

/-- lock.rs:75-85 forwards W and output.capacity() for safe lookup;
lookup_raw forwards its explicit output_words and capacity arguments.
The unsafe case carries no assumed capacity invariant. -/
inductive LookupOutputCall where
  | safe (buffer : SafeOutputBuffer)
  | raw (outputWords outputCapacity : Nat)

def LookupOutputCall.words : LookupOutputCall → Nat
  | .safe b => b.words
  | .raw words _ => words

def LookupOutputCall.capacity : LookupOutputCall → Nat
  | .safe b => b.capacity
  | .raw _ capacity => capacity

/-- Actual probe output stores, tied to the lookup arguments forwarded
unchanged by cache/lookup.rs to probe.rs. fits is probe.rs:186's branch;
loop and step are the while loop at 190-195. at_write is the actual
output.add(pos / 8).write operand, measured in bytes from the output base.
The source facts constrain each actual store, including stores before a
failed final header check. The caller must supply writable words; the
release-disabled production_assert is not used as a guarantee. -/
structure OutputCopy (X : Execution Ev Loc) where
  call : LookupOutputCall
  lengths : Ev
  valueRead : Ev
  write : Ev
  before : X.sb valueRead write
  pos : Nat
  fits : valLen (X.rval lengths) ≤ call.capacity
  loop : pos < valLen (X.rval lengths)
  step : pos % 8 = 0
  outputOffset : Ev → Nat
  at_write : outputOffset write = pos / 8 * 8

theorem OutputCopy.in_bounds (O : OutputCopy X)
    (caller_capacity : O.call.capacity ≤ O.call.words * 8) :
    O.outputOffset O.write + 8 ≤ O.call.words * 8 := by
  obtain ⟨h, h'⟩ := output_copy_in_bounds O.fits O.loop O.step
  rw [O.at_write]
  omega

/-- Safe lookup establishes the capacity premise from the constructor
assertion and preserved private fields, rather than from a raw contract. -/
theorem OutputCopy.safe_in_bounds (O : OutputCopy X) (b : SafeOutputBuffer)
    (forwarded : O.call = .safe b) :
    O.outputOffset O.write + 8 ≤ b.words * 8 := by
  have hb : O.call.capacity ≤ O.call.words * 8 := by
    rw [forwarded]
    exact b.capacity_bound
  simpa only [forwarded, LookupOutputCall.words] using O.in_bounds hb

/-- Source adapter for all actual probe output writes. callOf records the
outer lookup's actual arguments, forwarded through each of its probes;
outputOffset is the actual destination relative to that call's output base.
The bridge supplies the branch/loop facts above for every write, not merely
one chosen successful lookup. It asserts no output address bound. -/
structure ProbeOutputSource (X : Execution Ev Loc) where
  isOutputWrite : Ev → Prop
  callOf : Ev → LookupOutputCall
  outputOffset : Ev → Nat
  copies : ∀ w, isOutputWrite w → ∃ O : OutputCopy X,
    O.write = w ∧ O.call = callOf w ∧ O.outputOffset w = outputOffset w

/-- Every actual copy is bounded under the explicit raw caller contract. -/
theorem ProbeOutputSource.write_in_bounds (P : ProbeOutputSource X)
    {w : Ev} (hw : P.isOutputWrite w)
    (caller_capacity : (P.callOf w).capacity ≤ (P.callOf w).words * 8) :
    P.outputOffset w + 8 ≤ (P.callOf w).words * 8 := by
  obtain ⟨O, he, hc, ha⟩ := P.copies w hw
  have h := O.in_bounds (by simpa only [hc] using caller_capacity)
  simpa only [he, hc, ha] using h

/-- For actual safe calls, the source adapter and the preserved constructor
invariant discharge the capacity premise for every copied word. -/
theorem ProbeOutputSource.safe_write_in_bounds (P : ProbeOutputSource X)
    {w : Ev} (hw : P.isOutputWrite w) (b : SafeOutputBuffer)
    (forwarded : P.callOf w = .safe b) :
    P.outputOffset w + 8 ≤ b.words * 8 := by
  have h := P.write_in_bounds hw (by rw [forwarded]; exact b.capacity_bound)
  simpa only [forwarded, LookupOutputCall.words] using h

end Sgc.Execution
