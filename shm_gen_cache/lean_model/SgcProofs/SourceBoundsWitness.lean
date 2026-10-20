import SgcProofs.CacheWitness
import SgcProofs.LoadBounds
import SgcProofs.RotationScan

/-! Concrete configured-address and readiness-protocol witnesses. The
atomic execution is CacheWitness's consistent, nonempty cache execution;
the new protocol is populated from its actual R3 and C3 events. -/
namespace Sgc.SourceBoundsWitness
open Execution CacheWitness CacheWitness.E

def layout : MappingLayout where
  lineBytes := 64
  participants := 1
  buckets := 8
  recordBytes := 64
  arenaHeader := 64
  recordOffset := 128
  arenaStride := 192
  arenasOffset := 128
  mappingBytes := 704
  index_end := rfl
  record_end := by decide
  arenas_start := rfl
  mapping_end := rfl

theorem configured : ConfiguredLayout layout := ⟨Or.inl rfl, Or.inl rfl⟩

theorem fixed_addresses_exist :
    (FixedAddress.participant (.registered 0 (by decide)) ⟨9, by decide⟩ :
      FixedAddress layout).address + 8 ≤ layout.mappingBytes ∧
    (FixedAddress.readiness (.epoch 7) : FixedAddress layout).address + 8 ≤ layout.mappingBytes :=
  ⟨FixedAddress.in_mapping configured _, FixedAddress.in_mapping configured _⟩

/-- Fixed locations of the concrete execution use the Rust field offsets. -/
def fixedLocation : Word → Option (FixedAddress layout)
  | .own => some (.header ⟨14, by decide⟩)
  | .ep => some (.header ⟨10, by decide⟩)
  | .pin => some (.participant (.registered 0 (by decide)) ⟨0, by decide⟩)
  | .st => some (.participant (.registered 0 (by decide)) ⟨2, by decide⟩)
  | .tdis => some (.participant (.registered 0 (by decide)) ⟨4, by decide⟩)
  | .ce => some (.participant (.registered 0 (by decide)) ⟨6, by decide⟩)
  | .cc => some (.participant (.registered 0 (by decide)) ⟨8, by decide⟩)
  | .cn => some (.participant (.registered 0 (by decide)) ⟨9, by decide⟩)
  | .c0 => some (.control (.epoch 0))
  | .c1 => some (.control (.epoch 1))
  | .c2 => some (.control (.epoch 2))
  | .rdy0 => some (.readiness (.epoch 0))
  | .rdy1 => some (.readiness (.epoch 1))
  | .rdy2 => some (.readiness (.epoch 2))
  | _ => none

def arenaNumber : Word → Nat
  | .idx3 | .rc3 | .rc3b | .rc3c | .rc3d => 0
  | _ => 2

def physicalAddress (l : Word) : Nat :=
  match fixedLocation l with
  | some a => a.address
  | none => layout.arenasOffset + arenaNumber l * layout.arenaStride +
      (if l = .idx ∨ l = .idx3 then layout.arenaHeader else layout.recordOffset + recOffF l)

def storeLayout : StoreLayout X arena reserve index where
  layout := layout
  address := physicalAddress
  configured := configured
  record_bytes := rfl
  bucketMask := 7
  bucket_count := rfl
  record_addr := by
    intro l hl
    rcases hl with rfl | rfl | rfl | rfl | rfl | rfl | rfl | rfl <;>
      exact ⟨_, by decide, rfl⟩
  init_record_bound := by
    intro x l _ _ hl
    rcases hl with rfl | rfl | rfl | rfl | rfl | rfl | rfl | rfl <;> decide
  pub_addr := by
    intro x l _ _ hl
    rcases hl with rfl | rfl <;> exact ⟨_, 0, 0, by decide, rfl⟩
  init_idx_addr := by
    intro x l _ _ hl
    rcases hl with rfl | rfl <;> exact ⟨_, 0, by decide, by decide, rfl⟩
  fixed_addr := by
    intro x l _ _ hr hi
    change ¬ isRecordP l at hr
    change ¬ isIndexP l at hi
    have hf : ∃ a, fixedLocation l = some a := by
      cases l <;> first
        | exact ⟨_, rfl⟩
        | exact False.elim (hr (by decide))
        | exact False.elim (hi (by decide))
    obtain ⟨a, ha⟩ := hf
    exact ⟨a, by simp [physicalAddress, ha]⟩

theorem all_witness_stores {x : E} {l : Word} (hw : X.isW x) (hl : X.loc x = some l) :
    physicalAddress l + 8 ≤ layout.mappingBytes :=
  storeLayout.shared_store_in_mapping consistent hw hl

def entry : ReadEntry index := ⟨kI, bI, Word.idx, rfl, rfl, Or.inl rfl⟩

def valueRead : RecordRead index :=
  .value entry 16 0 8 0 (by decide) rfl (by decide) rfl

theorem value_load_address :
    layout.arenasOffset + 2 * layout.arenaStride + layout.recordOffset + valueRead.offset + 8 ≤
      layout.mappingBytes :=
  layout.record_word (by decide) (valueRead.bound consistent)

/-- All locations in the execution have a load-address form. The record
shapes have offset zero, one padded key word and one value word; the
smaller empty-key records share the same safe prefix. -/
def readAddress (l : Word) : LoadAddress layout index :=
  match fixedLocation l with
  | some a => .fixed a
  | none => match l with
    | .idx => .index (.epoch 2) (.masked 0 0)
    | .idx3 => .index (.epoch 0) (.masked 0 0)
    | .rcd => .record (.epoch 2) (.header entry)
    | .rc2 => .record (.epoch 2) (.lengths entry)
    | .rcA => .record (.epoch 2) (.key entry 24 1 8 0 (by decide) rfl (by decide))
    | .rcB => .record (.epoch 2) (.value entry 24 1 8 0 (by decide) rfl (by decide) rfl)
    | .rc3 => .record (.epoch 0) (.header entry)
    | .rc3b => .record (.epoch 0) (.lengths entry)
    | .rc3c => .record (.epoch 0) (.key entry 24 1 8 0 (by decide) rfl (by decide))
    | .rc3d => .record (.epoch 0) (.value entry 24 1 8 0 (by decide) rfl (by decide) rfl)
    | _ => .fixed (.header ⟨0, by decide⟩)

def payloadChecks (read : E) (h : read = aYk ∨ read = aYv) : CheckedPayloadRead index where
  reader := .probe
  entry := ⟨aJ, aI, Word.idx, rfl, rfl, Or.inl rfl⟩
  header := aY
  lengths := aYl
  read := read
  header_read := rfl
  lengths_read := rfl
  payload_read := by rcases h with rfl | rfl <;> rfl
  header_pass := by decide
  before_lengths := by decide
  lengths_pass := rfl
  before_payload := by rcases h with rfl | rfl <;> decide
  pos := 0
  shape := Or.inl (by decide)

def locatedPayload (read : E) (h : read = aYk ∨ read = aYv) :
    LocatedPayloadRead layout index physicalAddress :=
  { payloadChecks read h with
    arena := .epoch 2
    headerLoc := Word.rcd
    lengthsLoc := Word.rc2
    payloadLoc := if read = aYk then Word.rcA else Word.rcB
    at_header := rfl
    at_lengths := rfl
    at_payload := by rcases h with rfl | rfl <;> rfl
    header_address := by rcases h with rfl | rfl <;> decide
    lengths_address := by rcases h with rfl | rfl <;> decide
    payload_address := by
      rcases h with rfl | rfl
      · exact Or.inl ⟨by decide, rfl⟩
      · exact Or.inr ⟨by decide, rfl, rfl⟩ }

def aEntry : ReadEntry index := ⟨aJ, aI, Word.idx, rfl, rfl, Or.inl rfl⟩
def bEntry : ReadEntry index := ⟨bJ, bI, Word.idx, rfl, rfl, Or.inl rfl⟩

set_option maxHeartbeats 2000000 in
def loadLayout : LoadLayout X index owner.toOwnerEvents where
  layout := layout
  configured := configured
  record_bytes := rfl
  buckets_ge := by decide
  buckets_group := rfl
  buckets_bit_test := rfl
  address := physicalAddress
  isOwnerStealRead := fun _ => False
  owner_reads := fun h => h.elim
  loads := by
    intro x l _ hr hl
    change isRB x = true at hr
    change locOf x = some l at hl
    by_cases hf : ∃ a, fixedLocation l = some a
    · obtain ⟨a, ha⟩ := hf
      exact ⟨.fixed a (by simp [physicalAddress, ha])⟩
    · cases x <;> simp only [isRB] at hr <;> try contradiction
      all_goals simp only [locOf, Option.some.injEq] at hl
      all_goals subst l
      all_goals first
        | exact ⟨.index (.epoch 2) (.masked 0 0) rfl⟩
        | exact ⟨.index (.epoch 0) (.masked 0 0) rfl⟩
        | exact ⟨.header (.epoch 2) aEntry (by decide) rfl⟩
        | exact ⟨.header (.epoch 2) entry (by decide) rfl⟩
        | exact ⟨.header (.epoch 2) bEntry (by decide) rfl⟩
        | exact ⟨.lengths (.epoch 2) aEntry (by decide) rfl⟩
        | exact ⟨.lengths (.epoch 2) entry (by decide) rfl⟩
        | exact ⟨.lengths (.epoch 2) bEntry (by decide) rfl⟩
        | exact ⟨.payload (locatedPayload aYk (Or.inl rfl)) rfl rfl⟩
        | exact ⟨.payload (locatedPayload aYv (Or.inr rfl)) rfl rfl⟩
        | exact False.elim (hf ⟨_, rfl⟩)
  isPayloadRead := fun x => x = aYk ∨ x = aYv
  payload_checks := fun {x} h => ⟨payloadChecks x h, rfl⟩

theorem ownerSource : OwnerSource owner.toOwnerEvents layout.participants where
  init_none := rfl
  release_none := by
    rintro A r ⟨hA, rfl⟩
    rcases hA with rfl | rfl | rfl <;> rfl
  acquire_arguments := by
    rintro A (rfl | rfl | rfl) <;>
      exact ⟨⟨0, by decide⟩, ⟨0, by decide⟩, by decide, rfl⟩

theorem all_witness_loads {x : E} {l : Word} (hr : X.isR x) (hl : X.loc x = some l) :
    physicalAddress l + 8 ≤ layout.mappingBytes :=
  loadLayout.shared_load_in_mapping ownerSource consistent hr hl

/-- A nonempty source adapter covers both an extracted pin-scan event
and a wait-helper read omitted from the concurrency execution. -/
def sourceReads : ImplementationReadSource loadLayout Bool where
  event := fun r => if r then some t2S else none
  address := fun r => if r then physicalAddress Word.pin else 56
  modeled := by
    intro r x he
    cases r with
    | false => cases he
    | true =>
      change some t2S = some x at he
      cases Option.some.inj he
      exact ⟨Word.pin, rfl, rfl, rfl⟩
  omitted := by
    intro r he
    cases r with
    | false => exact ⟨.waitOwner, rfl⟩
    | true => cases he

theorem source_reads_in_mapping (r : Bool) :
    sourceReads.address r + 8 ≤ layout.mappingBytes :=
  loadLayout.all_source_loads_in_mapping ownerSource consistent sourceReads r

def rotationLoad : ScanLoad cache t2F t2C where
  slot := ()
  read := t2S
  source := aU3
  rf := rfl
  loc := rfl
  acquire := rfl
  afterFence := by decide
  beforeCert := by decide

def rotationTrace : RotationScanTrace cache t2F t2C 3 where
  loads := [rotationLoad]
  covered := fun i => by cases i; exact ⟨rotationLoad, by simp, rfl⟩
  slotRuns := fun i => by
    cases i
    refine ⟨.done 0 rfl, [rotationLoad], ?_, rfl⟩
    intro l
    simp only [List.mem_singleton]
    constructor
    · intro h; subst l; exact ⟨rfl, rfl⟩
    · exact fun h => h.1
  guard := rfl

def rotationBranches : ScanBranches (cache.pins ()) where
  epoch := fun _ => 3
  isR3 := fun r => r = t2S
  waitAfter := fun _ _ => False
  reapAfter := fun _ _ => False
  decoded_bound := by intro r h; subst r; decide
  wait_guard := fun h => h.elim
  reap_guard := fun h => h.elim

def tenureScan : TenureScan cache t2A t2q t2F t2C where
  r0 := ⟨Or.inr (Or.inl rfl), rfl⟩
  trace := rotationTrace
  branches := fun _ => rotationBranches
  branch_epoch := fun _ _ _ => rfl
  all_r3 := by
    intro i r hr
    cases i
    change r = t2S at hr
    subst r
    exact ⟨rotationLoad, by simp [rotationTrace], rfl, rfl⟩

def certifiedTenure : CertifiedTenure cache t2C where
  owner := t2A
  r0 := t2q
  fence := t2F
  scan := tenureScan
  sc := rfl
  under := under_of (by decide)
  beforeCert := by decide
  ownSeal := fun {Z} hZ => by obtain ⟨_, rfl⟩ := hZ; decide
  sealed := Or.inl ⟨t2Z, ⟨Or.inr (Or.inl rfl), rfl⟩⟩

theorem complete_tenure_certificate : RawRotCert cache t2C 3 :=
  certifiedTenure.raw_certificate

def recheckLoad : ScanLoad cache aC2 aC4 where
  slot := ()
  read := aC3
  source := aU
  rf := rfl
  loc := rfl
  acquire := rfl
  afterFence := by decide
  beforeCert := by decide

def recheckTrace : RecheckScanTrace cache aC2 aC4 2 where
  load := fun _ => recheckLoad
  slot := fun i => by cases i; rfl
  passed := fun _ => rfl

def rawReadiness : RawReadinessProtocol X cache :=
  { readiness.toCertWord with
    cert_source := by
      intro c h
      rcases h with rfl | rfl
      · exact Or.inl ⟨t2A, t2q, t2F, ⟨Or.inr (Or.inl rfl), rfl⟩, rfl, rfl,
          under_of (by decide), by decide,
          fun {Z} hZ => by obtain ⟨_, rfl⟩ := hZ; decide,
          Or.inl ⟨t2Z, ⟨Or.inr (Or.inl rfl), rfl⟩⟩, ⟨tenureScan⟩⟩
      · exact Or.inr ⟨aC1, t1P, aC2, rfl, rfl, rfl, rfl, by decide,
          rfl, by decide, ⟨recheckTrace⟩⟩ }

theorem raw_rotation_sound : X.hb aI3 t2C :=
  source_certificate_sound rawReadiness consistent (i := ()) (w := aW3) (Or.inl rfl)
    (Or.inr (Or.inr (Or.inr rfl))) rfl (inAtt_mk (by decide) (by decide))

theorem raw_recheck_sound : X.hb aI aC4 :=
  source_certificate_sound rawReadiness consistent (i := ()) (w := aW) (Or.inr rfl)
    (Or.inl rfl) rfl (inAtt_mk (by decide) (by decide))

end Sgc.SourceBoundsWitness
