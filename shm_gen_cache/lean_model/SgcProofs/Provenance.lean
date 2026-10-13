import SgcProofs.Lookup

/-!
# HT3, value provenance

A store attempt is either an insert, which stores the caller's key and
value, or a promotion: `lookup()` hit in the previous generation and calls
`store()` with the caller's key and the words the hit copied into the
output buffer (`lookup.rs:85-125`). `ValueProtocol` says this of the
abstract key and value an attempt stores, `attKey` and `attVal`.

* `promo_earlier`: a promotion stores the key and value of an attempt of a
  strictly earlier epoch. The hit's record loads read the stores of an
  attempt of the epoch the hit probed (`hit_integrity`), one less than V1's,
  and the promotion pins V1's epoch.
* `provenance` (HT3): every store attempt stores a key and value that some
  caller passed to `insert()`, by induction on the epoch.
* `published_provenance` (HT3, as `docs/invariants.html` states it): every
  publication pairs its key with such a value.

The model has no well-foundedness of `hb` (only of `mo`), so the induction
is not along `hb` but on the epoch, which the code's
"promote only from the previous generation" makes strictly decreasing.
-/

namespace Sgc
namespace Execution

variable {Ev Loc : Type}

/-- The key and value of each store attempt, and where a promotion's come
from, with the lines of `lookup.rs` and `store.rs` that do it. -/
structure ValueProtocol (X : Execution Ev Loc) {C : CacheProtocol X} {A : ArenaProtocol X C}
    {Rv : ReserveProtocol X C} (I : IndexProtocol X A Rv) where
  /-- The key an attempt stores. -/
  attKey : Ev → Nat
  /-- The value an attempt stores, with its padding. -/
  attVal : Ev → Nat
  /-- `inserted k v`: some caller passed `k` and `v` to `insert()`. -/
  inserted : Nat → Nat → Prop
  /-- The attempt is a promotion (`lookup.rs:114-124`). -/
  isPromo : Ev → Prop
  /-- An insert stores the caller's key and value (`store.rs:96-104`,
  `152-155`). -/
  ins_val : ∀ {i w}, C.isAttempt i w → ¬ isPromo w → inserted (attKey w) (attVal w)
  /-- A publication is for its attempt's key (`store.rs:160-169`, the call
  of `table_put()`). -/
  pub_key : ∀ {x i w}, (I.isPubCas x ∨ I.isPubStore x) → C.isAttempt i w →
    (C.pins i).inAttempt w x → I.pubKey x = attKey w
  /-- Code fact F10. A promotion follows a lookup hit `H` in the previous generation: it
  probed one epoch less than V1 read, after missing in V1's arena
  (`lookup.rs:85-99`, `114`); the promotion passes V1's epoch as `only_epoch`
  (`lookup.rs:123`), so it pins nothing else (`store.rs:269-272`). If the
  hit's loads read the stores of an attempt `w0`, then `w0` was for the
  caller's key (`key_matches()`) and the promotion stores `w0`'s value: the
  value words the hit copied (`probe.rs:167-179`, `lookup.rs:119-123`,
  `store.rs:69-73`). That last item is sequential. -/
  promo_src : ∀ {i w}, C.isAttempt i w → isPromo w →
    ∃ H : Hit I, X.rval H.v1 = H.e + 1 ∧ C.attEpoch w = X.rval H.v1 ∧
      ∀ j w0, C.isAttempt j w0 → (∀ Y, H.loads Y → ∀ z, X.rf z Y → (C.pins j).inAttempt w0 z) →
        attKey w0 = attKey w ∧ attVal w = attVal w0

namespace ValueProtocol

variable {X : Execution Ev Loc} {C : CacheProtocol X} {A : ArenaProtocol X C}
  {Rv : ReserveProtocol X C} {I : IndexProtocol X A Rv} (V : ValueProtocol X I)

/-- A promotion stores the key and value of an attempt of an earlier
epoch. -/
theorem promo_earlier (hc : X.Consistent) {i : C.ι} {w : Ev} (hw : C.isAttempt i w)
    (hp : V.isPromo w) :
    ∃ j w0, C.isAttempt j w0 ∧ C.attEpoch w0 < C.attEpoch w ∧
      V.attKey w0 = V.attKey w ∧ V.attVal w = V.attVal w0 := by
  obtain ⟨H, hv1, hpin, hval⟩ := V.promo_src hw hp
  -- The hit read the record of an attempt of the epoch it probed (HT2).
  obtain ⟨j, w0, hw0, -, -, hE, hread⟩ := I.hit_integrity hc H
  obtain ⟨hkey, hv⟩ := hval j w0 hw0 hread
  exact ⟨j, w0, hw0, by omega, hkey, hv⟩

/-- **HT3.** Every store attempt stores a key and a value that some caller
passed together to `insert()`. -/
theorem provenance (hc : X.Consistent) {i : C.ι} {w : Ev} (hw : C.isAttempt i w) :
    V.inserted (V.attKey w) (V.attVal w) := by
  suffices h : ∀ n, ∀ {i : C.ι} {w : Ev}, C.isAttempt i w → C.attEpoch w = n →
      V.inserted (V.attKey w) (V.attVal w) from h _ hw rfl
  intro n
  induction n using Nat.strongRecOn with
  | ind n ih =>
    intro i w hw hn
    by_cases hp : V.isPromo w
    · obtain ⟨j, w0, hw0, hlt, hkey, hv⟩ := V.promo_earlier hc hw hp
      have := ih (C.attEpoch w0) (hn ▸ hlt) hw0 rfl
      rw [← hkey, hv]
      exact this
    · exact V.ins_val hw hp

/-- **HT3**, as `docs/invariants.html` states it: every publication pairs its key
with a value that some caller passed to `insert()` for that key. -/
theorem published_provenance (hc : X.Consistent) {x : Ev} {i : C.ι} {w : Ev}
    (hxk : I.isPubCas x ∨ I.isPubStore x) (hw : C.isAttempt i w)
    (hx : (C.pins i).inAttempt w x) : V.inserted (I.pubKey x) (V.attVal w) := by
  rw [V.pub_key hxk hw hx]
  exact V.provenance hc hw

end ValueProtocol
end Execution
end Sgc
