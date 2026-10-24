import SgcProofs.OwnerHandoff
import SgcProofs.TakeoverWitness
import SgcProofs.TakeoverFreeWitness

/-!
Nonempty concrete source witnesses. The existing schedules now encode
actual u32 registration ids in state and owner words. The first execution
uses Stolen and a release-only O3. The second witnesses Free in the
abstract protocol (as previously documented, Rust's O4-before-P3 order
excludes this P3 path before a successful O3; the main proof permits it).
-/

namespace Sgc.OwnerHandoffWitness
open Execution
namespace Stolen
open TakeoverWitness TakeoverWitness.E

def ids : E → Fin wordLimit
  | c0 => ⟨11, by decide⟩
  | cs => ⟨22, by decide⟩
  | _ => ⟨33, by decide⟩

theorem words : OwnerSource ownerEvents 2 where
  init_none := rfl
  release_none := fun h => by obtain ⟨_, rfl⟩ := h; rfl
  acquire_arguments := fun h => by
    rcases h with rfl | rfl
    · exact ⟨⟨0, by decide⟩, ⟨11, by decide⟩, by decide, by decide⟩
    · exact ⟨⟨1, by decide⟩, ⟨44, by decide⟩, by decide, by decide⟩

def registrations : OwnerRegistrationSource ownerEvents slot ⟨0, by decide⟩
    (OwnerWord.registration (X.rval l)) where
  id := ids
  acquire_read := by
    intro a ha hi
    rcases ha with rfl | rfl
    · exact ⟨c0, g0, d0, g0, Or.inl ⟨rfl, rfl⟩, (by decide : actP c0 A1),
        (by decide : actP c0 d0), (by decide : sbP g0 d0),
        (by decide : sbP g0 A1), rfl, rfl, (by change ¬ (false = true); decide),
        by decide⟩
    · exact (by decide : OwnerWord.slot (X.wval T) ≠ 0) hi |>.elim
  registered_id := by
    intro c registered h
    rcases h with ⟨rfl, rfl⟩ | ⟨rfl, rfl⟩ | ⟨rfl, rfl⟩ <;> decide
  init_zero := rfl
  free_zero := fun h => by obtain ⟨_, rfl⟩ := h; rfl
  claim_initializing := by
    intro c hc
    rcases hc with (rfl | rfl) | rfl <;> decide
  register_even := by
    intro c registered h
    rcases h with ⟨rfl, rfl⟩ | ⟨rfl, rfl⟩ | ⟨rfl, rfl⟩ <;> decide
  id_unique := by
    intro c c' hc hc' hi hi'
    rcases hc with (rfl | rfl) | rfl <;>
      rcases hc' with (rfl | rfl) | rfl <;>
      first | rfl | exact (by decide : ¬ (_ = OwnerWord.registration (X.rval l))) hi |>.elim
            | exact (by decide : ¬ (_ = OwnerWord.registration (X.rval l))) hi' |>.elim
  tenure_thread := by
    intro a e ha he
    have h := he.1
    rcases ha with rfl | rfl <;> cases e <;>
      simp_all [X, sbP, rank, thr, liveness]
  before_free := by
    intro c a e y ha hact he hy
    obtain ⟨rfl, rfl⟩ := hy
    rcases ha with rfl | rfl <;> exact (by decide : ¬ actP cs _) hact |>.elim

theorem reads : OwnerTakeoverRead ownerEvents A1 T A1 l where
  replaced := Or.inl rfl
  takeover := Or.inr rfl
  predecessor := rfl
  observed := rfl
  load_location := rfl
  load_acquire := rfl
  nonempty := by decide
  expected := rfl

theorem branch : ReapOwnerReturn slot l T :=
  .stolen o g0 c0 cs g0 rfl rfl rfl (by decide : sbP l o)
    (by decide : sbP o T) (by decide) ⟨rfl, rfl⟩ rfl rfl (by decide : sbP cs T)

/-- The witness identifies the original claim c0 from the actual owner
word and obtains both action handoffs and no O4, without O3 acquire. -/
theorem concrete_stolen_handoff :
    ∃ c, slot.act c A1 ∧ (registrations.id c).val = 11 ∧
      (∀ e, slot.act c e → X.hb e T) ∧
      (∀ e, ownerEvents.under A1 e → X.hb e T) ∧
      (∀ r, ¬ ownerEvents.relOf A1 r) :=
  owner_handoff_from_source words consistent registrations reads (by decide) branch

end Stolen
namespace Free
open TakeoverFreeWitness TakeoverFreeWitness.E

theorem words : OwnerSource ownerEvents 2 where
  init_none := rfl
  release_none := fun h => by obtain ⟨_, rfl⟩ := h; rfl
  acquire_arguments := fun h => by
    rcases h with rfl | rfl
    · exact ⟨⟨0, by decide⟩, ⟨11, by decide⟩, by decide, by decide⟩
    · exact ⟨⟨1, by decide⟩, ⟨44, by decide⟩, by decide, by decide⟩

def registrations : OwnerRegistrationSource ownerEvents slot ⟨0, by decide⟩
    (OwnerWord.registration (X.rval l)) where
  id := fun _ => ⟨11, by decide⟩
  acquire_read := by
    intro a ha hi
    rcases ha with rfl | rfl
    · exact ⟨c0, g0, d0, g0, ⟨rfl, rfl⟩, (by decide : actP c0 A1),
        (by decide : actP c0 d0), (by decide : sbP g0 d0),
        (by decide : sbP g0 A1), rfl, rfl, (by change ¬ (false = true); decide),
        by decide⟩
    · exact (by decide : OwnerWord.slot (X.wval T) ≠ 0) hi |>.elim
  registered_id := by
    intro c registered h
    obtain ⟨rfl, rfl⟩ := h
    decide
  init_zero := rfl
  free_zero := fun h => by obtain ⟨_, rfl⟩ := h; rfl
  claim_initializing := by
    intro c hc
    rcases hc with rfl | h
    · decide
    · exact h.elim
  register_even := by
    intro c registered h
    obtain ⟨rfl, rfl⟩ := h
    decide
  id_unique := by
    intro c c' hc hc' _ _
    rcases hc with rfl | h
    · rcases hc' with rfl | h
      · rfl
      · exact h.elim
    · exact h.elim
  tenure_thread := by
    intro a e ha he
    have h := he.1
    rcases ha with rfl | rfl <;> cases e <;>
      simp_all [X, sbP, rank, thr, liveness]
  before_free := by
    intro c a e y ha hact he hy
    obtain ⟨rfl, rfl⟩ := hy
    rcases ha with rfl | rfl
    · exact slot.act_before_free ⟨rfl, rfl⟩ (under_A1 e he.1)
    · exact (by decide : ¬ actP c0 T) hact |>.elim

theorem reads : OwnerTakeoverRead ownerEvents A1 T A1 l where
  replaced := Or.inl rfl
  takeover := Or.inr rfl
  predecessor := rfl
  observed := rfl
  load_location := rfl
  load_acquire := rfl
  nonempty := by decide
  expected := rfl

theorem branch : ReapOwnerReturn slot l T :=
  .free q x0 rfl rfl rfl (by decide : sbP l q) (by decide : sbP q T) rfl

/-- P0's zero return differs from the nonzero register imported by O1. -/
theorem concrete_free_handoff :
    ∃ c, slot.act c A1 ∧ (registrations.id c).val = 11 ∧
      (∀ e, slot.act c e → X.hb e T) ∧
      (∀ e, ownerEvents.under A1 e → X.hb e T) ∧
      (∀ r, ¬ ownerEvents.relOf A1 r) :=
  owner_handoff_from_source words consistent registrations reads (by decide) branch

end Free
end Sgc.OwnerHandoffWitness
