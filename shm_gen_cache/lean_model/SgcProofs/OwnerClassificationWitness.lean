import SgcProofs.OwnerClassification
import SgcProofs.Ranked

/-!
Seven owner-word events exercise every CAS site: SkipBusyOwner O2 from
init, a release-only O3 expecting a nonempty O1 value, its O4, then waiting
O2 expecting an empty O1 value. Values use the actual slot/id encoding.
This is the owner-word projection; registration-slot events are covered
by the source bridge and the separate OwnerHandoff witnesses.
-/

namespace Sgc.OwnerClassificationWitness
open Execution

inductive E
  | I | A | q1 | T | r | q2 | B
  deriving DecidableEq
open E

def rank : E → Nat
  | I => 0 | A => 1 | q1 => 2 | T => 3 | r => 4 | q2 => 5 | B => 6

def thr : E → Nat
  | I => 0 | A => 1 | q1 | T | r => 2 | q2 | B => 3

def writeB : E → Bool
  | I | A | T | r | B => true
  | _ => false

def readB : E → Bool
  | A | q1 | T | q2 | B => true
  | _ => false

def src : E → Option E
  | A => some I | q1 | T => some A | q2 | B => some r
  | _ => none

def value : E → Nat
  | A => OwnerWord.pack 0 11 | T => OwnerWord.pack 1 22 | B => OwnerWord.pack 2 33
  | _ => 0

def readValue (e : E) : Nat := match src e with
  | some w => value w
  | none => 0

def releaseB : E → Bool
  | A | T | r | B => true
  | _ => false

def acquireB : E → Bool
  | A | q1 | q2 | B => true
  | _ => false

abbrev sbP (a b : E) : Prop := rank a < rank b ∧ (thr a = thr b ∨ thr a = 0)
abbrev rfP (w q : E) : Prop := src q = some w
abbrev moP (a b : E) : Prop := writeB a = true ∧ writeB b = true ∧ rank a < rank b

def X : Execution E Unit where
  loc := fun _ => some ()
  isW := fun e => writeB e = true
  isR := fun e => readB e = true
  isRel := fun e => releaseB e = true
  isAcq := fun e => acquireB e = true
  isRelFence := fun _ => False
  isAcqFence := fun _ => False
  isScFence := fun _ => False
  sb := sbP
  rf := rfP
  mo := moP
  asw := fun _ _ => False
  wval := value
  rval := readValue

theorem rank_injective : ∀ a b : E, rank a = rank b → a = b := by
  intro a b; cases a <;> cases b <;> decide

theorem rf_rank : ∀ w q : E, rfP w q → rank w < rank q := by
  intro w q; cases w <;> cases q <;> decide

theorem rb_rank : ∀ q w p : E, rfP p q → moP p w → rank q ≤ rank w := by
  intro q w p h
  cases q <;> simp [rfP, src] at h <;> (subst h; cases w <;> decide)

def ranked : Ranked X where
  rank := rank
  sb_lt := fun h => h.1
  asw_lt := fun h => h.elim
  rf_lt := fun h => rf_rank _ _ h
  mo_lt := fun h => h.2.2
  rb_le := fun ⟨_, p, hrf, hmo⟩ => rb_rank _ _ p hrf hmo

theorem consistent : X.Consistent where
  rf_wf := by
    intro w q h
    refine ⟨?_, ?_, rfl, by change (some () : Option Unit) ≠ none; decide⟩ <;>
      cases w <;> cases q <;> simp_all [X, rfP, src, writeB, readB]
  mo_wf := fun h => ⟨h.1, h.2.1, rfl, by change (some () : Option Unit) ≠ none; decide⟩
  mo_irrefl := ranked.mo_irrefl
  mo_trans := fun h h' => ⟨h.1, h'.2.1, Nat.lt_trans h.2.2 h'.2.2⟩
  mo_wellFounded := ranked.mo_wellFounded
  rf_unique := fun (h : src _ = some _) h' => Option.some.inj (h.symm.trans h')
  rf_val := fun {w q} (h : src q = some w) => by simp [X, readValue, h]
  mo_total := by
    intro a b ha hb _ _
    rcases Nat.lt_trichotomy (rank a) (rank b) with h | h | h
    · exact Or.inr (Or.inl ⟨ha, hb, h⟩)
    · exact Or.inl (rank_injective a b h)
    · exact Or.inr (Or.inr ⟨hb, ha, h⟩)
  scFence_relAcq := fun h => h.elim
  hb_irrefl := ranked.hb_irrefl
  coherence := ranked.coherence
  rmw_mo := by
    intro w q h hw
    cases w <;> cases q <;> simp_all [X, rfP, src, moP, writeB, rank]
  atomicity := ranked.atomicity
  sc_fences := ranked.sc_fences

abbrev acqCas (a : E) : Prop := a = A ∨ a = T ∨ a = B

def owner : OwnerEvents X where
  own := ()
  init := I
  isAcqCas := acqCas
  relOf := fun a e => a = T ∧ e = r
  init_w := rfl
  init_loc := rfl
  init_first := by
    intro e _ _ he
    apply hb_of_sb
    cases e <;> simp_all [X, sbP, rank, thr]
  writes := by
    intro e hw _
    cases e <;> first
      | exact Or.inl rfl
      | exact Or.inr (Or.inl (by decide))
      | exact Or.inr (Or.inr ⟨T, rfl, rfl⟩)
      | exact (by decide : ¬ (false = true)) hw |>.elim
  acq_w := by intro a h; rcases h with rfl | rfl | rfl <;> rfl
  acq_loc := fun _ => rfl
  acq_isAcq := by
    intro a w h hrf hn
    rcases h with rfl | rfl | rfl
    · rfl
    · have hw : w = A := Option.some.inj hrf.symm
      exact (hn (Or.inl hw)).elim
    · rfl
  acq_isRel := by intro a h; rcases h with rfl | rfl | rfl <;> rfl
  acq_reads := by
    intro a h
    rcases h with rfl | rfl | rfl
    · exact ⟨I, rfl⟩
    · exact ⟨A, rfl⟩
    · exact ⟨r, rfl⟩
  rel_acq := fun h => Or.inr (Or.inl h.1)
  rel_w := fun h => by obtain ⟨_, rfl⟩ := h; rfl
  rel_loc := fun _ => rfl
  rel_isRel := fun h => by obtain ⟨_, rfl⟩ := h; rfl
  rel_not_acq := fun h => by obtain ⟨_, rfl⟩ := h; decide
  rel_sb := fun h => by obtain ⟨rfl, rfl⟩ := h; exact (by decide : sbP T r)
  rel_unique := fun h h' => h.2.trans h'.2.symm

theorem writes : OwnerSource owner 3 where
  init_none := rfl
  release_none := fun h => by obtain ⟨_, rfl⟩ := h; rfl
  acquire_arguments := by
    intro a h
    rcases h with rfl | rfl | rfl
    · exact ⟨⟨0, by decide⟩, ⟨11, by decide⟩, by decide, rfl⟩
    · exact ⟨⟨1, by decide⟩, ⟨22, by decide⟩, by decide, rfl⟩
    · exact ⟨⟨2, by decide⟩, ⟨33, by decide⟩, by decide, rfl⟩

theorem issued : OwnerIssuedArguments owner where
  acquire := by
    intro a h
    rcases h with rfl | rfl | rfl
    · exact ⟨⟨0, by decide⟩, ⟨11, by decide⟩, by decide, rfl⟩
    · exact ⟨⟨1, by decide⟩, ⟨22, by decide⟩, by decide, rfl⟩
    · exact ⟨⟨2, by decide⟩, ⟨33, by decide⟩, by decide, rfl⟩

theorem skip_call : OwnerCasCall owner A .skipBusyO2 := .skipBusy (Or.inl rfl) rfl

theorem waiting_call : OwnerCasCall owner B .waitingO2 :=
  .waiting q2 (Or.inr (Or.inr rfl)) rfl rfl rfl rfl

theorem takeover_call : OwnerCasCall owner T .takeoverO3 :=
  .takeover q1 (Or.inr (Or.inl rfl)) rfl rfl (by decide) rfl

/-- All source sites inhabit their derived RF classes on a consistent
execution, including the release-only takeover and O2 after its O4. -/
theorem three_sites_classified :
    owner.isO2 A ∧ ¬ owner.isO3 A ∧ owner.isO2 B ∧ ¬ owner.isO3 B ∧
      owner.isO3 T ∧ ¬ owner.isO2 T ∧ ¬ X.isAcq T := by
  have ha := code_owner_cas_kind writes issued consistent skip_call
  have hb := code_owner_cas_kind writes issued consistent waiting_call
  have ht := code_owner_cas_kind writes issued consistent takeover_call
  exact ⟨ha.1, ha.2, hb.1, hb.2, ht.1, ht.2, by change ¬ (false = true); decide⟩

end Sgc.OwnerClassificationWitness
