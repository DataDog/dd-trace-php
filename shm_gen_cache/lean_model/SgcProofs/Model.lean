/-!
# An axiomatic weak-memory model

The fragment of RC11 / C++20 that the `shm_gen_cache` invariant proofs use.
An execution is a set of events with the relations `sb` (sequenced before),
`rf` (reads from) and `mo` (modification order). Everything else is derived:
release sequences, `sw`, `hb`, and the coherence order `cob` (RC11's `eco`).

Every axiom of `Consistent` except `mo_wellFounded` follows from RC11
consistency of the execution whose happens-before also includes the `asw`
edges (additional synchronises-with, as for thread creation and join; here
also assumption A3). We additionally require well-founded modification
order, which is automatic for finite executions. A theorem proved here
holds for every such execution. The axioms are deliberately no stronger
than what the proofs need:

* only the fence part of RC11's `psc` is required to be acyclic
  (`sc_fences`), since the algorithm's one `SeqCst` access is outside the
  proofs;
* a read-modify-write is a single event that both reads and writes, so
  RC11's `rmw` relation becomes the two axioms `rmw_mo` and `atomicity`.

The readiness, freshness and read-after-insert proofs (`Promotion.lean`,
`Recency.lean`) add no axiom: they use REL-ACQ with release sequences of
RMWs, FENCE-FENCE, the fence part of `psc` (SC-4.4), and the coherence rules
derived below.

No Mathlib: the file depends on core Lean only. Transitive closures are core's
`Relation.TransGen`, the definition Mathlib builds on, so its lemmas apply
under Mathlib too.
-/

namespace Sgc

universe u

/-- A relation that increases a measure has a transitive closure that does. -/
theorem transGen_lt_of_rank {α : Type u} {r : α → α → Prop} {f : α → Nat}
    (h : ∀ a b, r a b → f a < f b) {a b : α} (hab : Relation.TransGen r a b) :
    f a < f b := by
  induction hab with
  | single hr => exact h _ _ hr
  | tail _ hr ih => exact Nat.lt_trans ih (h _ _ hr)

/-- The same, for a relation that does not decrease the measure. -/
theorem transGen_le_of_rank {α : Type u} {r : α → α → Prop} {f : α → Nat}
    (h : ∀ a b, r a b → f a ≤ f b) {a b : α} (hab : Relation.TransGen r a b) :
    f a ≤ f b := by
  induction hab with
  | single hr => exact h _ _ hr
  | tail _ hr ih => exact Nat.le_trans ih (h _ _ hr)

/-- A candidate execution over events `Ev` and locations `Loc`. -/
structure Execution (Ev : Type) (Loc : Type) where
  /-- The atomic word an access acts on; `none` for fences. -/
  loc : Ev → Option Loc
  /-- The event writes: a store or a read-modify-write. -/
  isW : Ev → Prop
  /-- The event reads: a load or a read-modify-write. No proof uses it; it
  is kept so that `rf_wf` states the RC11 well-formedness condition in full
  and the witnesses check it. -/
  isR : Ev → Prop
  /-- A write with release ordering or stronger. -/
  isRel : Ev → Prop
  /-- A read with acquire ordering or stronger. -/
  isAcq : Ev → Prop
  /-- A release fence (a `SeqCst` fence is one). -/
  isRelFence : Ev → Prop
  /-- An acquire fence (a `SeqCst` fence is one). -/
  isAcqFence : Ev → Prop
  /-- A `SeqCst` fence. -/
  isScFence : Ev → Prop
  /-- Sequenced before: the full, strict, transitive program order within
  a thread. -/
  sb : Ev → Ev → Prop
  /-- `rf w r`: the read `r` takes its value from the write `w`. -/
  rf : Ev → Ev → Prop
  /-- Modification order, per location. -/
  mo : Ev → Ev → Prop
  /-- Additional synchronisation, outside the atomics: thread creation and
  join in C++, and here also what the liveness backend provides when it
  reports a thread dead (assumption A3). -/
  asw : Ev → Ev → Prop
  /-- The value a write stores (a read-modify-write's new value). -/
  wval : Ev → Nat
  /-- The value a read returns (a read-modify-write's old value). -/
  rval : Ev → Nat

namespace Execution

variable {Ev Loc : Type}

/-- Reads-before: `r ≠ w` and `r` reads a write that precedes `w` in `mo`. -/
def rb (X : Execution Ev Loc) (r w : Ev) : Prop :=
  r ≠ w ∧ ∃ w₀, X.rf w₀ r ∧ X.mo w₀ w

/-- One step of the coherence order. -/
def cobStep (X : Execution Ev Loc) (a b : Ev) : Prop :=
  X.rf a b ∨ X.mo a b ∨ X.rb a b

/-- Coherence-ordered before ([atomics.order]/3; RC11's `eco`). -/
def cob (X : Execution Ev Loc) : Ev → Ev → Prop :=
  Relation.TransGen X.cobStep

/-- `rs w w'`: `w'` lies in the release sequence headed by `w`, that is,
`w` followed only by read-modify-writes, each reading its predecessor.
This is the C++20 definition; no same-thread plain store continues it. -/
inductive rs (X : Execution Ev Loc) : Ev → Ev → Prop
  | refl (w : Ev) : rs X w w
  | rmw {w w' u : Ev} : rs X w w' → X.rf w' u → X.isW u → rs X w u

/-- Synchronises-with, four rules. Three are named in `docs/invariants.html`:
REL-ACQ is `relAcq`, FENCE-ACQ is `relFence` (a release write and an acquire
fence) and FENCE-FENCE is `fenceFence`. The fourth, `fenceAcq` (a release
fence and an acquire load), is the remaining rule of [atomics.fences]; no
proof builds an edge with it. -/
inductive sw (X : Execution Ev Loc) : Ev → Ev → Prop
  /-- A release write and an acquire read of its release sequence. -/
  | relAcq {w w' r : Ev} :
      X.isRel w → X.rs w w' → X.rf w' r → X.isAcq r → sw X w r
  /-- A release write and an acquire fence after a read of its release
  sequence. -/
  | relFence {w w' r f : Ev} :
      X.isRel w → X.rs w w' → X.rf w' r → X.sb r f → X.isAcqFence f → sw X w f
  /-- A release fence before a write, and an acquire read of that write. -/
  | fenceAcq {f w w' r : Ev} :
      X.isRelFence f → X.sb f w → X.isW w → X.rs w w' → X.rf w' r →
      X.isAcq r → sw X f r
  /-- A release fence before a write, and an acquire fence after a read of
  that write. -/
  | fenceFence {f w w' r g : Ev} :
      X.isRelFence f → X.sb f w → X.isW w → X.rs w w' → X.rf w' r →
      X.sb r g → X.isAcqFence g → sw X f g

/-- One step of happens-before. -/
def hbStep (X : Execution Ev Loc) (a b : Ev) : Prop :=
  X.sb a b ∨ X.sw a b ∨ X.asw a b

/-- Happens before: the transitive closure of `sb`, `sw` and `asw`. -/
def hb (X : Execution Ev Loc) : Ev → Ev → Prop :=
  Relation.TransGen X.hbStep

/-- The fence part of RC11's `psc`: `[F] ; (hb ∪ hb ; eco ; hb) ; [F]`.
Rule SC-4.4 of `docs/invariants.html` is its second disjunct. -/
def pscF (X : Execution Ev Loc) (x y : Ev) : Prop :=
  X.isScFence x ∧ X.isScFence y ∧
    (X.hb x y ∨ ∃ a b, X.hb x a ∧ X.cob a b ∧ X.hb b y)

/-- Well-formedness and the consistency axioms the proofs rely on. -/
structure Consistent (X : Execution Ev Loc) : Prop where
  /-- `rf` relates a write and a read of one location. -/
  rf_wf : ∀ {w r}, X.rf w r → X.isW w ∧ X.isR r ∧ X.loc w = X.loc r ∧ X.loc w ≠ none
  /-- `mo` relates two writes of one location. -/
  mo_wf : ∀ {a b}, X.mo a b → X.isW a ∧ X.isW b ∧ X.loc a = X.loc b ∧ X.loc a ≠ none
  mo_irrefl : ∀ a, ¬ X.mo a a
  mo_trans : ∀ {a b c}, X.mo a b → X.mo b c → X.mo a c
  /-- `mo` has no infinite descending chain. -/
  mo_wellFounded : WellFounded X.mo
  /-- A read takes its value from one write. -/
  rf_unique : ∀ {w w' r}, X.rf w r → X.rf w' r → w = w'
  /-- A read returns the value of the write it reads from. -/
  rf_val : ∀ {w r}, X.rf w r → X.rval r = X.wval w
  /-- `mo` is total on the writes of a location. -/
  mo_total : ∀ {a b}, X.isW a → X.isW b → X.loc a = X.loc b → X.loc a ≠ none →
    a = b ∨ X.mo a b ∨ X.mo b a
  /-- A `SeqCst` fence is both a release and an acquire fence. -/
  scFence_relAcq : ∀ {f}, X.isScFence f → X.isRelFence f ∧ X.isAcqFence f
  /-- `hb` is irreflexive. -/
  hb_irrefl : ∀ a, ¬ X.hb a a
  /-- Coherence: `hb ; eco` is irreflexive. -/
  coherence : ∀ {a b}, X.hb a b → ¬ X.cob b a
  /-- A read-modify-write follows, in `mo`, the write it reads. -/
  rmw_mo : ∀ {w u}, X.rf w u → X.isW u → X.mo w u
  /-- Atomicity: no write falls between a read-modify-write and the write it
  reads. -/
  atomicity : ∀ {u w}, X.rb u w → ¬ X.mo w u
  /-- The `SeqCst` fences can be totally ordered consistently with `pscF`. -/
  sc_fences : ∀ f, ¬ Relation.TransGen X.pscF f f

/-! ## Basic facts -/

theorem hb_of_sb {X : Execution Ev Loc} {a b : Ev} (h : X.sb a b) : X.hb a b :=
  Relation.TransGen.single (Or.inl h)

theorem hb_of_sw {X : Execution Ev Loc} {a b : Ev} (h : X.sw a b) : X.hb a b :=
  Relation.TransGen.single (Or.inr (Or.inl h))

theorem hb_of_asw {X : Execution Ev Loc} {a b : Ev} (h : X.asw a b) : X.hb a b :=
  Relation.TransGen.single (Or.inr (Or.inr h))

theorem hb_trans {X : Execution Ev Loc} {a b c : Ev}
    (h₁ : X.hb a b) (h₂ : X.hb b c) : X.hb a c :=
  Relation.TransGen.trans h₁ h₂

theorem cob_of_rf {X : Execution Ev Loc} {a b : Ev} (h : X.rf a b) : X.cob a b :=
  Relation.TransGen.single (Or.inl h)

theorem cob_of_mo {X : Execution Ev Loc} {a b : Ev} (h : X.mo a b) : X.cob a b :=
  Relation.TransGen.single (Or.inr (Or.inl h))

theorem cob_of_rb {X : Execution Ev Loc} {a b : Ev} (h : X.rb a b) : X.cob a b :=
  Relation.TransGen.single (Or.inr (Or.inr h))

theorem cob_trans {X : Execution Ev Loc} {a b c : Ev}
    (h₁ : X.cob a b) (h₂ : X.cob b c) : X.cob a c :=
  Relation.TransGen.trans h₁ h₂

/-! ## The coherence rules of `docs/invariants.html`, derived -/

/-- COH-WW: writes to one word ordered by `hb` are ordered the same way by
`mo`. -/
theorem coh_ww {X : Execution Ev Loc} (hc : X.Consistent) {a b : Ev}
    (ha : X.isW a) (hb' : X.isW b) (hloc : X.loc a = X.loc b)
    (hsome : X.loc a ≠ none) (h : X.hb a b) : X.mo a b := by
  rcases hc.mo_total ha hb' hloc hsome with heq | hmo | hmo
  · subst heq; exact absurd h (hc.hb_irrefl a)
  · exact hmo
  · exact absurd (cob_of_mo hmo) (hc.coherence h)

/-- COH-WR: a read that happens after a write reads that write or a later
one. -/
theorem coh_wr {X : Execution Ev Loc} (hc : X.Consistent) {w w' r : Ev}
    (hw : X.isW w) (hloc : X.loc w = X.loc r) (h : X.hb w r)
    (hrf : X.rf w' r) : w' = w ∨ X.mo w w' := by
  have ⟨hw', _, hloc', hsome'⟩ := hc.rf_wf hrf
  rcases hc.mo_total hw' hw (hloc'.trans hloc.symm) hsome' with heq | hmo | hmo
  · exact Or.inl heq
  · have hne : r ≠ w := by
      intro heq
      subst heq
      exact hc.hb_irrefl _ h
    exact absurd (cob_of_rb ⟨hne, w', hrf, hmo⟩) (hc.coherence h)
  · exact Or.inr hmo

/-- COH-RW: a read that happens before a write reads an earlier write. -/
theorem coh_rw {X : Execution Ev Loc} (hc : X.Consistent) {w w' r : Ev}
    (hw : X.isW w) (hloc : X.loc w = X.loc r) (h : X.hb r w)
    (hrf : X.rf w' r) : X.mo w' w := by
  have ⟨hw', _, hloc', hsome'⟩ := hc.rf_wf hrf
  rcases hc.mo_total hw' hw (hloc'.trans hloc.symm) hsome' with heq | hmo | hmo
  · subst heq; exact absurd (cob_of_rf hrf) (hc.coherence h)
  · exact hmo
  · exact absurd (cob_trans (cob_of_mo hmo) (cob_of_rf hrf)) (hc.coherence h)

/-- COH-RR: of two reads of one word ordered by `hb`, the later one reads the
same write or a later one. -/
theorem coh_rr {X : Execution Ev Loc} (hc : X.Consistent) {w w' r r' : Ev}
    (hloc : X.loc r = X.loc r') (h : X.hb r r')
    (hrf : X.rf w r) (hrf' : X.rf w' r') : w = w' ∨ X.mo w w' := by
  have ⟨hw, _, hl, hsome⟩ := hc.rf_wf hrf
  have ⟨hw', _, hl', _⟩ := hc.rf_wf hrf'
  rcases hc.mo_total hw hw' (hl.trans (hloc.trans hl'.symm)) hsome with heq | hmo | hmo
  · exact Or.inl heq
  · exact Or.inr hmo
  · have hne : r' ≠ w := by
      intro heq
      subst heq
      exact hc.coherence h (cob_of_rf hrf)
    exact absurd (cob_trans (cob_of_rb ⟨hne, w', hrf', hmo⟩) (cob_of_rf hrf))
      (hc.coherence h)

/-- RMW: nothing lies, in `mo`, between a read-modify-write and the write it
read. -/
theorem rmw_adjacent {X : Execution Ev Loc} (hc : X.Consistent) {w u v : Ev}
    (hrf : X.rf w u) (h₁ : X.mo w v) (h₂ : X.mo v u) : False := by
  have hne : u ≠ v := by
    intro heq
    subst heq
    exact hc.mo_irrefl _ h₂
  exact hc.atomicity ⟨hne, w, hrf, h₁⟩ h₂

/-- A write that precedes a read-modify-write in `mo` is the write it read or
precedes that write. -/
theorem rmw_pred_ge {X : Execution Ev Loc} (hc : X.Consistent) {w u R : Ev}
    (hrf : X.rf w u) (hR : X.isW R) (hloc : X.loc R = X.loc w) (hRu : X.mo R u) :
    R = w ∨ X.mo R w := by
  have ⟨hwW, _, _, _⟩ := hc.rf_wf hrf
  have ⟨_, _, _, hsome⟩ := hc.mo_wf hRu
  rcases hc.mo_total hR hwW hloc hsome with heq | h | h
  · exact Or.inl heq
  · exact Or.inr h
  · exact (rmw_adjacent hc hrf h hRu).elim

/-- Assumption A1 in general: a write that happens before every other write
to its word is preceded by nothing in `mo`. -/
theorem not_mo_first {X : Execution Ev Loc} (hc : X.Consistent) {i e : Ev} (hiW : X.isW i)
    (first : ∀ {e}, X.isW e → X.loc e = X.loc i → e ≠ i → X.hb i e) (h : X.mo e i) : False := by
  have ⟨he, _, hl, hs⟩ := hc.mo_wf h
  by_cases heq : e = i
  · subst heq; exact hc.mo_irrefl _ h
  · exact hc.mo_irrefl _ (hc.mo_trans (coh_ww hc hiW he hl.symm (hl ▸ hs) (first he hl heq)) h)

theorem some_ne_none {l : Loc} {o : Option Loc} (h : o = some l) : o ≠ none := by
  rw [h]; exact fun h => nomatch h

/-- COH-WW on a named word. -/
theorem coh_ww_at {X : Execution Ev Loc} (hc : X.Consistent) {a b : Ev} {l : Loc}
    (ha : X.isW a) (hla : X.loc a = some l) (hb' : X.isW b) (hlb : X.loc b = some l)
    (h : X.hb a b) : X.mo a b :=
  coh_ww hc ha hb' (hla.trans hlb.symm) (some_ne_none hla) h

/-- `hb` followed by `sb` or nothing. -/
theorem hb_sbr {X : Execution Ev Loc} {a b c : Ev} (h : X.hb a b) (h' : c = b ∨ X.sb b c) :
    X.hb a c := by
  rcases h' with rfl | h'
  · exact h
  · exact hb_trans h (hb_of_sb h')

/-- `sb` or nothing, followed by `hb`. -/
theorem sbr_hb {X : Execution Ev Loc} {a b c : Ev} (h' : a = b ∨ X.sb a b) (h : X.hb b c) :
    X.hb a c := by
  rcases h' with rfl | h'
  · exact h
  · exact hb_trans (hb_of_sb h') h

end Execution
end Sgc
