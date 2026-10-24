import SgcProofs.LoadBounds
import SgcProofs.TakeoverWitness

/-!
A nonempty owner load in the existing takeover schedule, with concrete
owner-word values replacing that schedule's abstract zero placeholders.
O2 encodes slot 2 and registration 11; O3 encodes slot 1 and registration
22. The schedule's accesses and ordering are unchanged. Consistency is
reproved for the concrete values, including the O1 reads-from edge.
-/

namespace Sgc.OwnerBoundsWitness
open Execution TakeoverWitness TakeoverWitness.E

/-- Values use the actual `RotationOwner::new` bit layout. -/
def concreteWrite : E → Nat
  | A1 => OwnerWord.pack 2 11
  | T => OwnerWord.pack 1 22
  | e => wv e

def concreteRead (e : E) : Nat :=
  match src e with
  | some w => concreteWrite w
  | none => 0

def execution : Execution E Word :=
  { X with wval := concreteWrite, rval := concreteRead }

def order : Ranked execution where
  rank := rank
  sb_lt := fun h => h.1
  asw_lt := fun h => asw_rank _ _ h
  rf_lt := fun h => rf_rank _ _ h
  mo_lt := fun h => h.2.2.2.2
  rb_le := fun ⟨_, w₀, hrf, hmo⟩ => rb_rank _ _ w₀ hrf hmo

theorem consistent : execution.Consistent where
  rf_wf := TakeoverWitness.consistent.rf_wf
  mo_wf := TakeoverWitness.consistent.mo_wf
  mo_irrefl := order.mo_irrefl
  mo_trans := TakeoverWitness.consistent.mo_trans
  mo_wellFounded := order.mo_wellFounded
  rf_unique := TakeoverWitness.consistent.rf_unique
  rf_val := fun {w r} (h : src r = some w) => by simp [execution, concreteRead, h]
  mo_total := TakeoverWitness.consistent.mo_total
  scFence_relAcq := fun h => h.elim
  hb_irrefl := order.hb_irrefl
  coherence := order.coherence
  rmw_mo := TakeoverWitness.consistent.rmw_mo
  atomicity := order.atomicity
  sc_fences := order.sc_fences

def owner : OwnerEvents execution where
  own := Word.own
  init := Io
  isAcqCas := isAcqCasP
  relOf := fun a r => a = T ∧ r = r2
  init_w := rfl
  init_loc := rfl
  init_first := fun hw hl hne => hb_of_sb (own_first _ hw hl hne)
  writes := fun hw hl => own_writes _ hw hl
  acq_w := fun h => (acq_facts _ h).1
  acq_loc := fun h => (acq_facts _ h).2.1
  acq_isAcq := fun {a w} h hrf hn => acq_of_noncas a w h hrf hn
  acq_isRel := fun h => (acq_facts _ h).2.2.1
  acq_reads := ownerEvents.acq_reads
  rel_acq := ownerEvents.rel_acq
  rel_w := ownerEvents.rel_w
  rel_loc := ownerEvents.rel_loc
  rel_isRel := ownerEvents.rel_isRel
  rel_not_acq := ownerEvents.rel_not_acq
  rel_sb := ownerEvents.rel_sb
  rel_unique := ownerEvents.rel_unique

theorem source : OwnerSource owner 4 where
  init_none := rfl
  release_none := fun h => by obtain ⟨_, rfl⟩ := h; rfl
  acquire_arguments := fun h => by
    rcases h with rfl | rfl
    · exact ⟨⟨2, by decide⟩, ⟨11, by decide⟩, by decide, rfl⟩
    · exact ⟨⟨1, by decide⟩, ⟨22, by decide⟩, by decide, rfl⟩

/-- O1 actually takes the nonempty branch and decodes a nonzero slot. -/
theorem nonempty_owner_load :
    execution.rf A1 l ∧ OwnerWord.registration (execution.rval l) = 11 ∧
      OwnerWord.slot (execution.rval l) = 2 ∧
      OwnerWord.slot (execution.rval l) < 4 :=
  ⟨rfl, by decide, by decide, owner_load_bound source consistent
    (w := A1) (r := l) rfl rfl (by decide)⟩

/-- The O3 write also carries the new owner's registered slot, even
though this CAS is release-only. -/
theorem takeover_encoding :
    ¬ execution.isAcq T ∧ execution.wval T = OwnerWord.pack 1 22 ∧
      OwnerWord.slot (execution.wval T) = 1 := ⟨by change ¬ (false = true); decide, rfl, by decide⟩

def layout : MappingLayout where
  lineBytes := 64
  participants := 4
  buckets := 8
  recordBytes := 64
  arenaHeader := 64
  recordOffset := 128
  arenaStride := 192
  arenasOffset := 320
  mappingBytes := 896
  index_end := rfl
  record_end := by decide
  arenas_start := rfl
  mapping_end := rfl

/-- A reaper's write to the final participant field has the configured
mapping bound, using the actual nonempty O1 and its source, with no
assertion premise. -/
theorem owner_address_bound :
    layout.lineBytes * (1 + OwnerWord.slot (execution.rval l)) + 4 * 9 + 8 ≤
      layout.mappingBytes :=
  owner_participant_in_mapping ⟨Or.inl rfl, Or.inl rfl⟩ source consistent
    (w := A1) (r := l) rfl rfl (by decide) ⟨9, by decide⟩

/-- This takeover reads slot 2, decoded from A1's actual O2 word. -/
def readAddress : Word → Nat
  | .st => 64 * (1 + 2) + 8
  | .tdis => 64 * (1 + 2) + 16
  | .pin => 64 * (1 + 2)
  | .own => 56

def stealState : OwnerStealRead layout owner readAddress o Word.st where
  ownerRead := l
  ownerWrite := A1
  rf := rfl
  at_owner := rfl
  nonempty := by decide
  before := by change sbP _ _; decide
  site := .state
  at_slot := by decide

def stealTime : OwnerStealRead layout owner readAddress t Word.tdis where
  ownerRead := l
  ownerWrite := A1
  rf := rfl
  at_owner := rfl
  nonempty := by decide
  before := by change sbP _ _; decide
  site := .startTime
  at_slot := by decide

def stealCas : OwnerStealRead layout owner readAddress cs Word.st where
  ownerRead := l
  ownerWrite := A1
  rf := rfl
  at_owner := rfl
  nonempty := by decide
  before := by change sbP _ _; decide
  site := .compareExchange
  at_slot := by decide

def registerCas : OwnerStealRead layout owner readAddress g Word.st where
  ownerRead := l
  ownerWrite := A1
  rf := rfl
  at_owner := rfl
  nonempty := by decide
  before := by change sbP _ _; decide
  site := .register
  at_slot := by decide

def releaseCas : OwnerStealRead layout owner readAddress x Word.st where
  ownerRead := l
  ownerWrite := A1
  rf := rfl
  at_owner := rfl
  nonempty := by decide
  before := by change sbP _ _; decide
  site := .release
  at_slot := by decide

/-- P5 registers and P3 releases the temporary registration on the very
same O1-derived slot, without assuming any new registration's index bound. -/
theorem registration_release_loads_in_mapping :
    readAddress Word.st + 8 ≤ layout.mappingBytes ∧
    readAddress Word.st + 8 ≤ layout.mappingBytes :=
  ⟨registerCas.in_mapping ⟨Or.inl rfl, Or.inl rfl⟩ source consistent,
    releaseCas.in_mapping ⟨Or.inl rfl, Or.inl rfl⟩ source consistent⟩

/-- The concrete P0, T0 and P4 all use the source-derived nonzero slot.
The proof of the CAS address requires no write or success premise. -/
theorem modeled_steal_loads_in_mapping :
    readAddress Word.st + 8 ≤ layout.mappingBytes ∧
    readAddress Word.tdis + 8 ≤ layout.mappingBytes ∧
    readAddress Word.st + 8 ≤ layout.mappingBytes :=
  ⟨stealState.in_mapping ⟨Or.inl rfl, Or.inl rfl⟩ source consistent,
    stealTime.in_mapping ⟨Or.inl rfl, Or.inl rfl⟩ source consistent,
    stealCas.in_mapping ⟨Or.inl rfl, Or.inl rfl⟩ source consistent⟩

end Sgc.OwnerBoundsWitness
