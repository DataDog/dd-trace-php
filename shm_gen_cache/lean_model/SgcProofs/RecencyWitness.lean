import SgcProofs.CacheWitness
import SgcProofs.Recency

/-!
# A witness for insert operations, probe runs and a lookup's final pass

`InsertOps`, `ProbeRun` and `LookupRun` (`Recency.lean`) hold on the
execution of `CacheWitness.lean`, together with every protocol already
instantiated there, and the hypotheses of FR3 (`no_older_value`) and FR4
(`retained_hit`) are met by it:

* the inserts are writer `a`'s (`ja`, key 1, its attempt `aW`), writer `b`'s
  first (`jb`, key 2, `bW`) and second (`jb2`, key 2, `bW2`); each is called
  at its W1 and returns, `Ok`, at its unpin W7. Writer `a`'s promotion
  `aW3` belongs to no insert. Writer `a` also makes a failing insert (`jf`,
  key 3), rejected by `insert()`'s size check (`KeyTooLarge`) before
  `store()`: it makes no attempt and no memory event, so its call and its
  error return are both marked at `aU`;
* thread 1, after writer `a`'s insert returned (`aU`), looks key 1 up: its
  final pass loads epoch 3 (`aV1`), misses in `c0`'s arena (the home I1
  `aI1c` reads the zero fill of `idx3`), and returns the hit of its reprobe
  of the previous arena (`hitA`, writer `a`'s record). Its first probe of
  the previous arena, which hit unpromotably, is not modelled: `LookupRun`
  needs only the probes it lists to be probes of the pass;
* FR3 holds with `I = ja`: the hit's record is `aW`'s, whose only root is
  `ja`. FR4's hypotheses hold too (V1 read `f + 1 = 3`, and no load of the
  pass reads a store of epoch 5 or later). The promotion `aW3` descends from
  `ja` through `hitA` (`Desc.promo`);
* `failed_lookup_invisible` holds with `J = jf`: the hit's record is
  `aW`'s, which does not descend from `jf`.

The scenario of `regression` (no epoch above 3) is not this execution's:
epoch 5 is published here. `RegressionWitness.lean` witnesses it.
-/

namespace Sgc
namespace CacheWitness

open Execution E

/-- The `insert()` calls. -/
inductive InsOp
  | ja | jb | jb2 | jf
  deriving DecidableEq

open InsOp

def insKeyF : InsOp → Nat
  | ja => 1 | jb => 2 | jb2 => 2 | jf => 3

def insCallF : InsOp → E
  | ja => aQ | jb => bQ | jb2 => bQ2 | jf => aU

def insRetF : InsOp → E
  | ja => aU | jb => bU | jb2 => bU2 | jf => aU

/-- The attempt of each insert. The value for `jf` is unused: `insOf`
gives `jf` no attempt. -/
def insAttF : InsOp → E
  | ja => aW | jb => bW | jb2 => bW2 | jf => aW

/-- `ja`, `jb` and `jb2` make one attempt each, return `Ok` at its unpin,
and publish: `aI` and `bI` are CASes, `bS` a store. `jf` makes no attempt
and returns `KeyTooLarge`, not `Corrupt`, so `err_nopub` holds vacuously in
its attempts. -/
def ops : InsertOps X value where
  Op := InsOp
  insKey := insKeyF
  insCall := insCallF
  insRet := fun J r => r = insRetF J
  insOk := fun J r => J ≠ jf ∧ r = insRetF J
  ok_ret := fun h => h.2
  insOf := fun w J => J ≠ jf ∧ w = insAttF J
  insOf_np := fun {w J} h hp => by
    obtain ⟨-, rfl⟩ := h
    cases J <;> exact absurd (show insAttF _ = aW3 from hp) (by decide)
  ins_of_attempt := fun {i w} hw hp => by
    rcases hw with rfl | rfl | rfl | rfl
    · exact ⟨ja, by decide, rfl⟩
    · exact ⟨jb, by decide, rfl⟩
    · exact ⟨jb2, by decide, rfl⟩
    · exact absurd rfl hp
  insOf_unique := fun {w J J'} h h' => by
    obtain ⟨hJ, rfl⟩ := h
    obtain ⟨hJ', h'⟩ := h'
    cases J <;> cases J' <;>
      first | rfl | exact absurd rfl hJ | exact absurd rfl hJ' | exact absurd h' (by decide)
  ins_key := fun {w J} h => by
    obtain ⟨hJ, rfl⟩ := h
    cases J <;> first | rfl | exact absurd rfl hJ
  att_w1 := fun {i w J} _ h => by
    obtain ⟨hJ, rfl⟩ := h
    cases J
    · exact ⟨aQ, iEp, rfl, rfl, rfl, Or.inl rfl, by decide, fun r hr => hr ▸ by decide⟩
    · exact ⟨bQ, t3P, rfl, rfl, rfl, Or.inl rfl, by decide, fun r hr => hr ▸ by decide⟩
    · exact ⟨bQ2, t3P, rfl, rfl, rfl, Or.inl rfl, by decide, fun r hr => hr ▸ by decide⟩
    · exact absurd rfl hJ
  att_ret := fun {i w J a r} hw h ha hr => by
    obtain ⟨hJ, rfl⟩ := h
    subst hr
    have := (inAtt_dec hw ha).2
    cases J
    · exact this
    · exact this
    · exact this
    · exact absurd rfl hJ
  ok_pub := fun {J r} h => by
    cases J
    · exact ⟨(), aW, aI, Or.inl rfl, ⟨by decide, rfl⟩, inAtt_mk (by decide) (by decide),
        Or.inl (Or.inl rfl), rfl, Word.idx, rfl⟩
    · exact ⟨(), bW, bI, Or.inr (Or.inl rfl), ⟨by decide, rfl⟩,
        inAtt_mk (by decide) (by decide), Or.inl (Or.inr (Or.inl rfl)), rfl, Word.idx, rfl⟩
    · exact ⟨(), bW2, bS, Or.inr (Or.inr (Or.inl rfl)), ⟨by decide, rfl⟩,
        inAtt_mk (by decide) (by decide), Or.inr rfl, rfl, Word.idx, rfl⟩
    · exact absurd rfl h.1
  insErr := fun J r => J = jf ∧ r = insRetF J
  err_ret := fun h => h.2
  insCorrupt := fun _ _ => False
  corrupt_err := fun h => h.elim
  err_nopub := fun {J r} h _ {i w x} _ hins _ => absurd h.1 hins.1

/-- Thread 1's probe of `c0`'s arena for key 1 in epoch 3: its home I1 reads
the zero fill, whose stamp is not 3, so it misses. -/
def curP : ProbeRun index where
  e := 3
  key := 1
  i1 := fun Y => Y = aI1c
  i1_slot := fun {Y} h => by subst h; exact ⟨Word.idx3, rfl, Or.inr rfl, rfl⟩
  i1_reads := fun {Y} h => by subst h; exact ⟨iIdx3, rfl⟩
  recs := fun _ _ => False
  recs_addr := fun {Y} _ _ hZ => hZ.elim
  recs_fence := fun h => h.elim
  missed := True
  miss_check := fun _ s hs har hch => by
    rcases hs with rfl | rfl
    · exact absurd har (by decide)
    · obtain ⟨x, j, w, hrf, -, -, hxk, -⟩ := (hch.2 aI1c rfl rfl).1
      cases Option.some.inj hrf
      rcases hxk with (h | h | h) | h <;> exact absurd (show iIdx3 = _ from h) (by decide)

/-- The reprobe's record loads, as `hitA`'s. -/
def reRecs (Y Z : E) : Prop := Y = aJ ∧ hitA.loads Z

/-- Thread 1's reprobe of `c2`'s arena for key 1 in epoch 2 (`hitA`). -/
def reP : ProbeRun index where
  e := 2
  key := 1
  i1 := fun Y => Y = aJ
  i1_slot := fun {Y} h => by subst h; exact ⟨Word.idx, rfl, Or.inl rfl, rfl⟩
  i1_reads := fun {Y} h => by subst h; exact ⟨aI, rfl⟩
  recs := reRecs
  recs_addr := fun {Y} h Z hZ => by
    subst h
    obtain ⟨-, hZ⟩ := hZ
    rcases hZ with rfl | rfl | rfl | rfl
    · exact ⟨Word.rcd, rfl, Or.inl rfl, rfl, Or.inl (by decide)⟩
    · exact ⟨Word.rc2, rfl, Or.inr (Or.inl rfl), rfl, Or.inr (Or.inl (by decide))⟩
    · exact ⟨Word.rcA, rfl, Or.inr (Or.inr (Or.inl rfl)), rfl, Or.inr (Or.inr
        ⟨aYl, Word.rc2, ⟨rfl, Or.inr (Or.inl rfl)⟩, ⟨aRl, rfl⟩, rfl, Or.inr (Or.inl rfl), rfl,
          by decide, Or.inl ⟨0, rfl, by decide, by decide⟩⟩)⟩
    · exact ⟨Word.rcB, rfl, Or.inr (Or.inr (Or.inr (Or.inl rfl))), rfl, Or.inr (Or.inr
        ⟨aYl, Word.rc2, ⟨rfl, Or.inr (Or.inl rfl)⟩, ⟨aRl, rfl⟩, rfl, Or.inr (Or.inl rfl), rfl,
          by decide, Or.inr ⟨0, rfl, by decide, by decide⟩⟩)⟩
  recs_fence := fun {Y Z} h => by
    obtain ⟨rfl, hZ⟩ := h
    rcases hZ with rfl | rfl | rfl | rfl <;> exact ⟨aJF, by decide, rfl, by decide⟩
  missed := False
  miss_check := fun h => h.elim

/-- Thread 1's lookup of key 1: the final pass loads epoch 3, misses in
`c0`'s arena and returns the reprobe's hit `hitA`. -/
def lookupA : LookupRun value where
  key := 1
  v1 := aV1
  s1 := t1P
  v1_rf := rfl
  v1_loc := rfl
  probe := fun P => P = curP ∨ P = reP
  probe_key := fun {P} h => by rcases h with rfl | rfl <;> rfl
  probe_after := fun {P Y} h hY => by
    rcases h with rfl | rfl
    · subst hY; decide
    · subst hY; decide
  cur := curP
  cur_probe := Or.inl rfl
  cur_e := rfl
  hit := fun H => H = hitA
  hit_v1 := fun {H} h => by subst h; rfl
  hit_key := fun {H} h j w0 hw0 hread => by
    subst h
    have hd := inAtt_dec hw0 (hread aY (Or.inl rfl) aR rfl)
    rcases hw0 with rfl | rfl | rfl | rfl
    · rfl
    all_goals exact absurd hd (by decide)
  hit_prev := fun {H} h _ => by
    subst h
    refine ⟨trivial, fun Z hZ => ?_⟩
    rcases hZ with rfl | ⟨_, _, h⟩
    · decide
    · exact h.elim
  miss := False
  miss_probes := fun h => h.elim

/-! ## The theorems on this execution -/

/-- An event of `aW`'s record or entry is no store of an attempt for epoch 5
or later, nor is the zero fill. -/
theorem not_recycled {v : E} (hv : v = iIdx3 ∨ v = aI ∨ v = aR ∨ v = aRl ∨ v = aRk ∨ v = aRv) :
    ¬ Recycled cache 2 v := by
  rintro ⟨j, w, hw, hvw, hge⟩
  have hd := inAtt_dec hw hvw
  rcases hw with rfl | rfl | rfl | rfl <;>
    rcases hv with rfl | rfl | rfl | rfl | rfl | rfl <;>
    first | exact absurd hd (by decide) | exact absurd hge (by decide)

/-- Every probe of thread 1's final pass is retained for epoch 2. -/
theorem lookupA_retained : ∀ P, lookupA.probe P → P.Retained 2 := by
  rintro P (rfl | rfl) Z v hZ hv
  · rcases hZ with rfl | ⟨_, _, h⟩
    · cases Option.some.inj hv
      exact not_recycled (Or.inl rfl)
    · exact h.elim
  · rcases hZ with rfl | ⟨Y, rfl, -, hZ⟩
    · cases Option.some.inj hv
      exact not_recycled (Or.inr (Or.inl rfl))
    · rcases hZ with rfl | rfl | rfl | rfl <;> cases Option.some.inj hv
      · exact not_recycled (Or.inr (Or.inr (Or.inl rfl)))
      · exact not_recycled (Or.inr (Or.inr (Or.inr (Or.inl rfl))))
      · exact not_recycled (Or.inr (Or.inr (Or.inr (Or.inr (Or.inl rfl)))))
      · exact not_recycled (Or.inr (Or.inr (Or.inr (Or.inr (Or.inr rfl)))))

/-- FR3: thread 1's lookup, after writer `a`'s insert returned successfully,
returns a record whose every root is that insert or one that does not
precede it. -/
theorem witness_no_older_value :
    ∃ j w0, cache.isAttempt j w0 ∧ (cache.pins j).inAttempt w0 hitA.x2 ∧
      (∀ Y, hitA.loads Y → ∀ z, X.rf z Y → (cache.pins j).inAttempt w0 z) ∧
      ∀ J, Desc readiness ops w0 J → J = ja ∨ ¬ ops.precedes J ja :=
  successful_insert_no_resurrection consistent promotion ops lookupA (Iop := ja) (r := aU)
    ⟨by decide, rfl⟩ rfl (hb_of_sb (by decide)) rfl

/-- FR4: its hypotheses hold for thread 1's lookup, which does not miss. -/
theorem witness_retained_hit : ¬ lookupA.miss :=
  retained_hit consistent ops lookupA (Iop := ja) (iI := ()) (wI := aW) (xI := aI) (r := aU)
    (Or.inl rfl) ⟨by decide, rfl⟩ (inAtt_mk (by decide) (by decide)) (Or.inl (Or.inl rfl)) rfl rfl rfl rfl
    (hb_of_sb (by decide)) (by decide) lookupA_retained

/-- `Desc.promo`: the promotion `aW3` descends from writer `a`'s insert,
through the hit `hitA` of writer `a`'s entry. -/
theorem witness_desc_promo : Desc readiness ops aW3 ja :=
  Desc.promo (i := ()) (j := ()) hitA (Or.inr (Or.inr (Or.inr rfl))) rfl hitA_promo
    (Or.inl rfl) (inAtt_mk (by decide) (by decide)) (Desc.ins ⟨by decide, rfl⟩)

/-- `desc_exists` and `desc_key` on writer `b`'s second attempt. -/
theorem witness_desc_key : ∃ J, Desc readiness ops bW2 J ∧ ops.insKey J = 2 := by
  obtain ⟨J, hJ⟩ := desc_exists consistent promotion ops (i := ()) (w := bW2)
    (Or.inr (Or.inr (Or.inl rfl)))
  exact ⟨J, hJ, (desc_key consistent ops hJ).symm⟩

/-- A failed insert is invisible: thread 1's lookup returns `aW`'s record,
which does not descend from writer `a`'s failing insert `jf`. -/
theorem witness_failed_invisible :
    ∃ j w0, cache.isAttempt j w0 ∧ (cache.pins j).inAttempt w0 hitA.x2 ∧
      (∀ Y, hitA.loads Y → ∀ z, X.rf z Y → (cache.pins j).inAttempt w0 z) ∧
      ¬ Desc readiness ops w0 jf :=
  failed_lookup_invisible consistent ops lookupA (J := jf) (r := aU) ⟨rfl, rfl⟩ id rfl

end CacheWitness
end Sgc
