import SgcProofs.Reserve

/-!
# The participant index decoded from the owner word (BD1)

`production_assert!` in `reap_owner` and `Cache::participant` is omitted
in release and verification builds (`assert.rs`). It cannot justify an
address bound. Instead, O1 reads one of the writes classified by
`OwnerEvents.writes`: initialization and O4 store NONE, and O2/O3 store
`RotationOwner::new(slot_index, registration_id)` (`rotation.rs`).

The slot argument of O2/O3 comes from `ParticipantLock.slot_index`.
`register_participant` constructs that lock from its range loop, or from
`reap_dead_participant`, which returns an index from the same range
(`cache/mod.rs`). `lock.rs` retains that index; `store.rs` and `lookup.rs`
forward it to rotation without changing it. This is the source premise
`OwnerSource.acquire_arguments`. No read bound, successful assertion, or
current registration of the *old* owner is a premise: a stale owner word
still encodes an index that was valid when it was stored. A7 keeps the
mapping capacity fixed.
-/

namespace Sgc.Execution

namespace OwnerWord

/-- `RotationOwner::new`: low u32 slot, high u32 registration id. -/
def pack (slot registration : Nat) : Nat := slot ||| (registration <<< 32)

/-- `RotationOwner::slot`, a truncating cast to u32. -/
def slot (word : Nat) : Nat := word % wordLimit

/-- `RotationOwner::registration_id`, used by the real O1 branch. -/
def registration (word : Nat) : Nat := word / wordLimit % wordLimit

/-- The high half cannot alter the slot's low 32 bits. This is a
bitwise encoding theorem, independent of owner-word freshness or ordering. -/
theorem slot_pack {i : Nat} (hi : i < wordLimit) (reg : Nat) :
    slot (pack i reg) = i := by
  change (i ||| (reg <<< 32)) % 2 ^ 32 = i
  rw [Nat.or_mod_two_pow, Nat.shiftLeft_eq]
  have hmod : i % 2 ^ 32 = i := Nat.mod_eq_of_lt hi
  simp [hmod]

/-- The high u32 identifies the registration independently of the slot. -/
theorem registration_pack {i reg : Nat} (hi : i < wordLimit)
    (hr : reg < wordLimit) : registration (pack i reg) = reg := by
  change ((i ||| (reg <<< 32)) / 2 ^ 32) % 2 ^ 32 = reg
  rw [Nat.or_div_two_pow, Nat.shiftLeft_eq]
  have hdiv : i / 2 ^ 32 = 0 := Nat.div_eq_of_lt hi
  simp [hdiv]
  exact hr

end OwnerWord

variable {Ev Loc : Type} {X : Execution Ev Loc}

/-- Source-level values of the owner writes. The membership premise is
supplied by the successful registration loop, including registration by
reaping, before the lock forwards its index to any O2 or O3. Both arguments
are u32 in Rust. These fields describe writes, not the value O1 reads. -/
structure OwnerSource (O : OwnerEvents X) (capacity : Nat) where
  init_none : X.wval O.init = 0
  release_none : ∀ {A r}, O.relOf A r → X.wval r = 0
  acquire_arguments : ∀ {A}, O.isAcqCas A →
    ∃ (i reg : Fin wordLimit), i.val ∈ List.range capacity ∧
      X.wval A = OwnerWord.pack i.val reg.val

/-- Every nonempty O1 value originates at an O2/O3 that encoded a slot
from a successful registration loop. NONE from initialization or O4
cannot pass the actual `registration_id() != 0` branch in rotation.rs.
The source can be arbitrarily old: the capacity bound needs no acquire
ordering, freshness, or successful runtime assertion. -/
theorem owner_load_source {O : OwnerEvents X} {capacity : Nat}
    (S : OwnerSource O capacity) (hc : X.Consistent) {w r : Ev}
    (hrf : X.rf w r) (hloc : X.loc r = some O.own)
    (howner : OwnerWord.registration (X.rval r) ≠ 0) :
    O.isAcqCas w ∧ ∃ (i reg : Fin wordLimit), i.val ∈ List.range capacity ∧
      X.rval r = OwnerWord.pack i.val reg.val := by
  obtain ⟨hw, _, hwr, _⟩ := hc.rf_wf hrf
  rcases O.writes hw (hwr.trans hloc) with hinit | hacq | ⟨A, hrel⟩
  · subst w
    rw [hc.rf_val hrf, S.init_none] at howner
    exact False.elim (howner rfl)
  · obtain ⟨i, reg, hi, hval⟩ := S.acquire_arguments hacq
    exact ⟨hacq, i, reg, hi, (hc.rf_val hrf).trans hval⟩
  · rw [hc.rf_val hrf, S.release_none hrel] at howner
    exact False.elim (howner rfl)

/-- BD1's owner-derived participant index, proved from the writer and
its encoding rather than assumed from `production_assert!`. -/
theorem owner_load_bound {O : OwnerEvents X} {capacity : Nat}
    (S : OwnerSource O capacity) (hc : X.Consistent) {w r : Ev}
    (hrf : X.rf w r) (hloc : X.loc r = some O.own)
    (howner : OwnerWord.registration (X.rval r) ≠ 0) :
    OwnerWord.slot (X.rval r) < capacity := by
  obtain ⟨_, i, reg, hi, hval⟩ := owner_load_source S hc hrf hloc howner
  rw [hval, OwnerWord.slot_pack i.isLt]
  exact List.mem_range.mp hi

end Sgc.Execution
