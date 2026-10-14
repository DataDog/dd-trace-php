import SgcProofs.Promotion

/-!
# FR3 and FR4: read-after-insert

`Promotion.lean` proves that a promotion copies the `mo`-last publication of
its key in the source incarnation (FR2). This file lifts that to whole
operations: an insert `I` that returned, and a lookup `L` whose final pass
starts (V1) after `I`'s return in `hb`.

The modelling the operation-level statement needs, and nothing more:

* `InsertOps` (code fact F15): insert operations, their calls and returns,
  which store attempts belong to which insert, that an insert that
  returns `Ok` published, and that one that returns an error other than
  `Corrupt` did not (`store.rs`, `insert()` and `store()`);
* `Desc` (*descends from*): an attempt of an insert descends from it; a
  promotion descends from whatever the attempt whose entry its hit followed
  descends from. FR3 is stated over this *root*, not over values, because
  two inserts may store equal values; and it quantifies over every root, so
  it does not need the model to make the root unique (`desc_exists` shows
  that every attempt has one);
* `ProbeRun` and its `miss_check` (code fact F14): a sequential fact about
  `probe()`, patterned on `KeyCheck`. If, along the key's probe sequence up
  to some slot `s`, every index load read an entry of the probed
  incarnation, every candidate's record loads read the record of that
  entry's publisher, and the loads of `s` read an entry for the key, then
  the probe does not return Miss;
* `LookupRun`: the final pass of `lookup()`, its probes and its outcome.

Proved for every consistent execution:

* `chain_read`, `not_missed`: a probe of the inserted incarnation `f`,
  made after the publication `P_I` in `hb`, whose loads read no store of an
  attempt for `f + 3` or later (*retention*), does not miss. The index loads
  of the slots before `P_I`'s slot read entries of `f` (RB4(c),
  `IndexProtocol.skipped`), the loads of `P_I`'s slot read `P_I` or a later
  entry for the key (COH-WR, RB4(a)), and the record loads read their
  publishers' records (`record_load_or_recycled`, SP5, QU2(a));
* `root_at_f`, `root_after`: every publication of the key in incarnation
  `f` at or after `P_I` in `mo` has root `I` or an insert that does not
  precede `I` (RB4(d): it is an insert's store, F9); and so has every
  attempt of the key for a later epoch (inserts there do not precede `I`,
  by COH-RR on the epoch; promotions copy the `mo`-last publication of
  the epoch before, FR2), by induction on the epoch;
* `no_older_value` (FR3): a hit returned by `L` has root `I` or an insert
  that does not precede `I`. A hit of `f` or later is covered by the last
  item; a hit of `f - 1` would need the probe of `f` to miss, which needs a
  load of a later incarnation, which the hit's V2 rejects (HT1);
* `successful_insert_no_resurrection`: FR3 for an insert that returned
  `Ok`, with its publication supplied by `ok_pub`. Promotions cannot make
  a later lookup return a superseded insert's record. No retention or
  bound on rotations or promotions is needed; misses remain possible;
* `retained_hit` (FR4): if V1 read `f` or `f + 1` and the probes of the
  final pass are retained, `L` does not return a miss.
  `retained_of_before` and `epoch_le_of_before` are the two sufficient
  conditions: the pass's loads happen before the R5 that publishes `f + 3`
  (retention), or before the one that publishes `f + 2` (both
  hypotheses); `retained_hit_of_before` combines them;
* `regression`: the scenario of the GenMC program
  `promotion_keeps_newer_stale_epoch_insert`. With one rotation at most
  (no epoch above 3) and every other insert of the key preceding `I`, `L`
  does not miss, and a hit it returns has root `I`. `RegressionWitness.lean`
  shows its hypotheses jointly satisfiable;
* `failed_invisible`, `failed_lookup_invisible`: no hit, and so no lookup,
  returns a record that descends from an insert that returned an error
  other than `Corrupt`, which published nothing (`err_nopub`), since every
  hit follows a publication (HT2) and so does every promotion in the chain
  (`pub_not_desc`). `unpublished_invisible` states it for any insert that
  published nothing. No claim is made about `Corrupt` returns.

Nothing here assumes timing, fairness, sampling decisions or that readiness
is ever reached; no new axiom is used. *Precedes* is `hb`, not real time:
inserts concurrent with `I` may win, and lookups not `hb`-after `I`'s return
may return an older value. FR3 and FR4 say nothing about a failed insert;
one whose error is not `Corrupt` is seen by no lookup
(`failed_invisible`).
-/

namespace Sgc
namespace Execution

variable {Ev Loc : Type}

/-! ## Insert operations -/

/-- Insert operations (code fact F15): the store attempts of each
`insert()` call, its call and its returns, with the lines of `store.rs` that
make them. -/
structure InsertOps (X : Execution Ev Loc) {C : CacheProtocol X} {A : ArenaProtocol X C}
    {Rv : ReserveProtocol X C} {I : IndexProtocol X A Rv} (V : ValueProtocol X I) where
  /-- The `insert()` calls. -/
  Op : Type
  /-- The key an insert stores. -/
  insKey : Op → Nat
  /-- The first event of an insert, its call. -/
  insCall : Op → Ev
  /-- `insRet J r`: `r` marks `J`'s return, whatever its outcome. -/
  insRet : Op → Ev → Prop
  /-- `insOk J r`: `J` returned `Ok` at `r`. -/
  insOk : Op → Ev → Prop
  ok_ret : ∀ {J r}, insOk J r → insRet J r
  /-- `insOf w J`: the store attempt pinned by `w` is a pass of `J`'s
  `store()` loop (`store.rs:96`, `139-204`). -/
  insOf : Ev → Op → Prop
  /-- A promotion's attempts belong to a lookup, not to an insert: `insert()`
  passes no `promotion` epoch (`store.rs:102`, `lookup.rs:168`). -/
  insOf_np : ∀ {w J}, insOf w J → ¬ V.isPromo w
  /-- `store()` is called only by `insert()` and by a promoting `lookup()`
  (`store.rs:96`, `lookup.rs:168`), so every other attempt is an insert's. -/
  ins_of_attempt : ∀ {i w}, C.isAttempt i w → ¬ V.isPromo w → ∃ J, insOf w J
  /-- An attempt is a pass of one call (code fact F2). -/
  insOf_unique : ∀ {w J J'}, insOf w J → insOf w J' → J = J'
  /-- An insert stores its caller's key (`store.rs:96-104`). -/
  ins_key : ∀ {w J}, insOf w J → V.attKey w = insKey J
  /-- The attempt's W1 (`store.rs:264`) comes after the call and before the
  pin W2 (`store.rs:272`) and every return of the insert: `reserve()` runs
  inside `store()`, inside `insert()`. -/
  att_w1 : ∀ {i w J}, C.isAttempt i w → insOf w J →
    ∃ q1 s, X.rf s q1 ∧ X.loc q1 = some C.R.ep ∧ X.rval q1 = C.attEpoch w ∧
      (insCall J = q1 ∨ X.sb (insCall J) q1) ∧ X.sb q1 w ∧ ∀ r, insRet J r → X.sb q1 r
  /-- Everything an attempt does, from W2 to the unpin W7 (`store.rs:175`),
  is done before the insert returns. -/
  att_ret : ∀ {i w J a r}, C.isAttempt i w → insOf w J → (C.pins i).inAttempt w a →
    insRet J r → a = r ∨ X.sb a r
  /-- `insert()` returns `Ok` only after one of its attempts published: the
  pass that returns has `PutStatus::Ok` or `Full` (`store.rs:176-189`), which
  `table_put()` returns only after an I2 by an insert (`put.rs:76-92`,
  `101-105`), a write to an index slot. After a publication, only `Corrupt`
  from the `Full` arm's rotation becomes an error (`store.rs:185-188`).
  `reserve()` returns `None` only to a
  promotion (`store.rs:269-271` and `353-355`). -/
  ok_pub : ∀ {J r}, insOk J r → ∃ i w x, C.isAttempt i w ∧ insOf w J ∧
    (C.pins i).inAttempt w x ∧ (I.isPubCas x ∨ I.isPubStore x) ∧ X.isW x ∧
    ∃ l, X.loc x = some l
  /-- `insErr J r`: `J` returned an `Err` at `r`. -/
  insErr : Op → Ev → Prop
  err_ret : ∀ {J r}, insErr J r → insRet J r
  /-- `insCorrupt J r`: the error `J` returned at `r` is `Error::Corrupt`. -/
  insCorrupt : Op → Ev → Prop
  corrupt_err : ∀ {J r}, insCorrupt J r → insErr J r
  /-- An insert that returns an error other than `Error::Corrupt` published
  nothing: no event of any of its attempts is an I2. `insert()`
  (`cache/store.rs`) fails by itself only before calling `store()`
  (`KeyTooLarge`, `ValueTooLarge`), with no attempt. `store()` returns an
  error only

  1. from `reserve()`, whose only errors are those of its `rotate()`, made
     after the pass's unpin W7 and before any record store or publication
     of that pass;
  2. after `PutStatus::FullRejected`, from `rotate(.., WaitForOwner)`.
     `table_put()` (`arena/put.rs`) returns `FullRejected` only once its
     probe loop runs out of slots, and each of its I2s, the store over an
     entry for the key and the successful compare-exchange, leaves that
     loop by `break 'put`; a failed compare-exchange writes nothing. So a
     pass that ends in `FullRejected` made no I2;
  3. `Err(Corrupt)` after `PutStatus::Full`, after the pass published:
     excluded here by the hypothesis that the error is not `Corrupt`.

  A pass of the `store()` loop that does not return got `FullRejected`, so
  no earlier pass of a failing insert published either. -/
  err_nopub : ∀ {J r}, insErr J r → ¬ insCorrupt J r → ∀ {i w x}, C.isAttempt i w →
    insOf w J → (C.pins i).inAttempt w x → ¬ (I.isPubCas x ∨ I.isPubStore x)

/-- `Ops.precedes J K`: insert `J` returned before insert `K` was called, in
`hb`. -/
def InsertOps.precedes {X : Execution Ev Loc} {C : CacheProtocol X} {A : ArenaProtocol X C}
    {Rv : ReserveProtocol X C} {I : IndexProtocol X A Rv} {V : ValueProtocol X I}
    (Ops : InsertOps X V) (J K : Ops.Op) : Prop :=
  ∃ r, Ops.insRet J r ∧ X.hb r (Ops.insCall K)

/-- `Desc RP Ops w J`: the attempt pinned by `w` descends from insert `J`,
its *root*: it is an attempt of `J`, or a promotion whose promoted hit
followed the publication of an attempt that descends from `J`. -/
inductive Desc {X : Execution Ev Loc} {C : CacheProtocol X} {A : ArenaProtocol X C}
    {Rv : ReserveProtocol X C} {I : IndexProtocol X A Rv} {V : ValueProtocol X I}
    (RP : ReadinessProtocol X C) (Ops : InsertOps X V) : Ev → Ops.Op → Prop
  | ins {w J} : Ops.insOf w J → Desc RP Ops w J
  | promo {i j w w0 J} (H : Hit I) : C.isAttempt i w → V.isPromo w → PromoHit V RP w H →
      C.isAttempt j w0 → (C.pins j).inAttempt w0 H.x2 → Desc RP Ops w0 J → Desc RP Ops w J

/-! ## Probes and lookups -/

/-- `Recycled C n v`: `v` is a store of an attempt for epoch `n + 3` or later,
so of a later incarnation of epoch `n`'s arena. -/
def Recycled {X : Execution Ev Loc} (C : CacheProtocol X) (n : Nat) (v : Ev) : Prop :=
  ∃ j w, C.isAttempt j w ∧ (C.pins j).inAttempt w v ∧ n + 3 ≤ C.attEpoch w

/-- `EntryRead I n recs Y`: the index load `Y` read a publication of an
attempt for epoch `n`, and every record load `recs Y` makes of the candidate
read a store of that attempt. -/
def EntryRead {X : Execution Ev Loc} {C : CacheProtocol X} {A : ArenaProtocol X C}
    {Rv : ReserveProtocol X C} (I : IndexProtocol X A Rv) (n : Nat)
    (recs : Ev → Ev → Prop) (Y : Ev) : Prop :=
  ∃ x j w, X.rf x Y ∧ C.isAttempt j w ∧ (C.pins j).inAttempt w x ∧
    (I.isPubCas x ∨ I.isPubStore x) ∧ C.attEpoch w = n ∧
    ∀ Z v, recs Y Z → X.rf v Z → (C.pins j).inAttempt w v

/-- `ChainRead I n k i1 recs s`: the loads of a probe of epoch `n` for key `k`
read what they would with nothing recycled, up to slot `s`: the loads of
every slot before `s` along `k`'s probe sequence read entries of `n` and
their records (`EntryRead`), and the loads of `s` read an entry of `n` for
`k` and its record. -/
def ChainRead {X : Execution Ev Loc} {C : CacheProtocol X} {A : ArenaProtocol X C}
    {Rv : ReserveProtocol X C} (I : IndexProtocol X A Rv) (n k : Nat)
    (i1 : Ev → Prop) (recs : Ev → Ev → Prop) (s : Loc) : Prop :=
  (∀ Y l, i1 Y → X.loc Y = some l → I.dist k l < I.dist k s → EntryRead I n recs Y) ∧
  ∀ Y, i1 Y → X.loc Y = some s → EntryRead I n recs Y ∧ ∀ x, X.rf x Y → I.pubKey x = k

/-- One `probe()` of epoch `e` for key `key` (`probe.rs`, `probe()`), with
its loads and whether it returned Miss. -/
structure ProbeRun {X : Execution Ev Loc} {C : CacheProtocol X} {A : ArenaProtocol X C}
    {Rv : ReserveProtocol X C} (I : IndexProtocol X A Rv) where
  e : Nat
  key : Nat
  /-- The index loads I1: the home slot (`probe.rs:99`), the group scans
  (`find_candidate()`, `probe.rs:269`) and the sequential continuation
  (`probe.rs:220`), all of index slots of the arena that serves `e`: the
  `ArenaView` `lookup()` passes (`lookup.rs:86`, `98`, `199`). -/
  i1 : Ev → Prop
  i1_slot : ∀ {Y}, i1 Y → ∃ l, X.loc Y = some l ∧ I.isIndex l ∧ I.arenaOf l = C.R.ctlLoc e
  /-- Every load reads some write. -/
  i1_reads : ∀ {Y}, i1 Y → ∃ x, X.rf x Y
  /-- `recs Y Z`: `Z` is a record load (D4, or a D5 load of the lengths, the
  key or the value) of the candidate whose entry the I1 `Y` returned
  (`probe.rs:159-197`). -/
  recs : Ev → Ev → Prop
  /-- They address the record at the offset the entry encodes
  (`probe.rs:152`), in the same arena (`RecLoads`, as `Hit.loads_rec`). -/
  recs_addr : ∀ {Y}, i1 Y → Rv.RecLoads (C.R.ctlLoc e) (I.entryOff (X.rval Y)) (recs Y)
  /-- I1F (`probe.rs:151`), an acquire fence, comes between the I1 that
  selected a candidate and its record loads. -/
  recs_fence : ∀ {Y Z}, recs Y Z → ∃ f, X.sb Y f ∧ X.isAcqFence f ∧ X.sb f Z
  /-- The probe returned `ProbeError::Miss`. -/
  missed : Prop
  /-- **Code fact F14**, sequential, like `KeyCheck`: a probe that missed did
  not read, up to any slot `s` of its arena, what `ChainRead` describes.
  Checked by reading `probe()`. Miss is returned only by a stamp mismatch of
  an I1 when no candidate was found (home, `probe.rs:106-108`; a group,
  `128-129`; the sequential scan, `221-222`), by a stamp mismatch of D4
  (`160-162`), or past the last slot (`135`, `217-218`). An entry of `e`
  carries the stamp `e as u32`, and a publisher's header carries it too
  (`store.rs:158`), with consistent lengths (`store.rs:137-138`, `152`). A
  candidate whose record loads read its publisher's record is validated
  without Miss: its key is skipped if it differs (`probe.rs:176-183`), and
  otherwise the probe returns a hit, Retry or InsufficientCapacity
  (`199-211`). So up to `s` nothing returns Miss, a group scan stops at the
  first candidate (`find_candidate()`, `probe.rs:271-273`, even past a
  terminator in the group), and the entry of `s` is a candidate:
  a publication for `key` carries `key`'s tag (`put.rs:51`, `53`; equal
  keys have equal hashes, A8, and `probe.rs:81`, `95`, `226`), and its
  record holds `key` (`store.rs:154`), so the probe returns at `s` at the
  latest, not with Miss. -/
  miss_check : missed → ∀ s, I.isIndex s → I.arenaOf s = C.R.ctlLoc e →
    ¬ ChainRead I e key i1 recs s

/-- The loads of a probe: its index loads and their candidates' record
loads. -/
def ProbeRun.loads {X : Execution Ev Loc} {C : CacheProtocol X} {A : ArenaProtocol X C}
    {Rv : ReserveProtocol X C} {I : IndexProtocol X A Rv} (P : ProbeRun I) (Z : Ev) : Prop :=
  P.i1 Z ∨ ∃ Y, P.i1 Y ∧ P.recs Y Z

/-- *Retention* for epoch `n`: no load of the probe reads a store of an
attempt for `n + 3` or later. -/
def ProbeRun.Retained {X : Execution Ev Loc} {C : CacheProtocol X} {A : ArenaProtocol X C}
    {Rv : ReserveProtocol X C} {I : IndexProtocol X A Rv} (P : ProbeRun I) (n : Nat) : Prop :=
  ∀ Z v, P.loads Z → X.rf v Z → ¬ Recycled C n v

/-- The final pass of a `lookup()` of `key` (`lookup.rs`, `lookup()`, lines 76-171):
the pass that returns rather than retries. -/
structure LookupRun {X : Execution Ev Loc} {C : CacheProtocol X} {A : ArenaProtocol X C}
    {Rv : ReserveProtocol X C} {I : IndexProtocol X A Rv} (V : ValueProtocol X I) where
  key : Nat
  /-- V1, the pass's epoch load (`lookup.rs:77`), and the write it read. -/
  v1 : Ev
  s1 : Ev
  v1_rf : X.rf s1 v1
  v1_loc : X.loc v1 = some C.R.ep
  /-- The probes of the pass: of V1's arena (`lookup.rs:84-95`), of the
  previous one (`102-113`), and the reprobe of the previous one
  (`reprobe_for_promotion()`, `197-208`), all for the caller's key and all
  sequenced after V1. -/
  probe : ProbeRun I → Prop
  probe_key : ∀ {P}, probe P → P.key = key
  probe_after : ∀ {P Y}, probe P → P.i1 Y → X.sb v1 Y
  /-- The probe of V1's arena, made first. -/
  cur : ProbeRun I
  cur_probe : probe cur
  cur_e : cur.e = X.rval v1
  /-- The hit the pass returns: `lookup()` returns `Ok(Some(_))` with the
  value this probe copied (`lookup.rs:115-116`, `137-140`, `170`). -/
  hit : Hit I → Prop
  hit_v1 : ∀ {H}, hit H → H.v1 = v1
  /-- A probe returns a hit only after `key_matches()` compared the caller's
  key with the key words it loaded (`probe.rs:176-183`); if those loads read
  the stores of an attempt `w0`, `w0` stored that key. Sequential. -/
  hit_key : ∀ {H}, hit H → ∀ j w0, C.isAttempt j w0 →
    (∀ Y, H.loads Y → ∀ z, X.rf z Y → (C.pins j).inAttempt w0 z) → V.attKey w0 = key
  /-- A hit of the previous epoch comes after the probe of V1's arena missed
  (`lookup.rs:96-97`, `124`), and every load of that probe is sequenced
  before the hit's D6 (`lookup.rs:84-113`, `127-136`). -/
  hit_prev : ∀ {H}, hit H → H.e + 1 = X.rval v1 →
    cur.missed ∧ ∀ Z, cur.loads Z → X.sb Z H.d6
  /-- The pass returns `Ok(None)`. -/
  miss : Prop
  /-- Only after the probe of V1's arena missed, and then a probe of the
  previous epoch missed: the first one (`lookup.rs:119`) or the reprobe
  (`145`). -/
  miss_probes : miss → cur.missed ∧ ∃ P, probe P ∧ P.e + 1 = X.rval v1 ∧ P.missed

/-! ## Loads of a probe of the inserted incarnation -/

namespace ArenaProtocol

variable {X : Execution Ev Loc} {C : CacheProtocol X} (A : ArenaProtocol X C)

/-- A record load ordered after the store `sY` of attempt `w` reads `sY`, or
a store of a later incarnation (`Recycled`). `hit_load` without V2: SP5
excludes a second store of `w`'s incarnation, and QU2(a) one of an earlier
incarnation. -/
theorem record_load_or_recycled (hc : X.Consistent) (Rv : ReserveProtocol X C)
    {sY Y z : Ev} {l : Loc} {i : C.ι} {w : Ev}
    (hw : C.isAttempt i w) (hsY : (C.pins i).inAttempt w sY) (hsYW : X.isW sY)
    (hsYl : X.loc sY = some l) (hYl : X.loc Y = some l) (hl : A.isArena l)
    (hrec : Rv.isRecord l) (h₁ : X.hb sY Y) (hz : X.rf z Y) :
    z = sY ∨ Recycled C (C.attEpoch w) z := by
  rcases coh_wr hc hsYW (hsYl.trans hYl.symm) h₁ hz with heq | hmo
  · exact Or.inl heq
  · right
    have ⟨_, hzW, hlz, _⟩ := hc.mo_wf hmo
    have hzl : X.loc z = some l := hlz.symm.trans hsYl
    rcases A.aw_owner hzW hzl hl with hinit | ⟨j, w', hw', hz'⟩
    · exact (not_mo_first hc hzW (A.arenaInit_first hinit) hmo).elim
    · rcases A.same_arena hzl hsYl hl hzW hsYW hw' hz' hw hsY with heq | h | h
      · have := Rv.record_write_once hc hw' hz' hw hsY hzW hsYW hzl hsYl hrec heq
        subst this
        exact (hc.mo_irrefl _ hmo).elim
      · exact (hc.mo_irrefl _
          (hc.mo_trans hmo
            (C.arena_writes_ordered hc hw' hw h hz' hsY hzW hsYW (hzl.trans hsYl.symm)
              (some_ne_none hzl)))).elim
      · exact ⟨j, w', hw', hz', h⟩

end ArenaProtocol

namespace IndexProtocol

variable {X : Execution Ev Loc} {C : CacheProtocol X} {A : ArenaProtocol X C}
  {Rv : ReserveProtocol X C} (I : IndexProtocol X A Rv)

/-- An index load `Y` ordered after the publication `z` of an attempt for
epoch `n`, in the same slot, that reads no store of a later incarnation,
reads a publication of an attempt for `n`: `z` or one after it in `mo`. The
zero fill and earlier incarnations precede `z` (A1, QU2(a)). -/
theorem entry_after (hc : X.Consistent) {z Y x : Ev} {l : Loc} {i : C.ι} {w : Ev}
    (hzk : I.isPubCas z ∨ I.isPubStore z) (hzW : X.isW z) (hw : C.isAttempt i w)
    (hz : (C.pins i).inAttempt w z) (hzl : X.loc z = some l) (hYl : X.loc Y = some l)
    (hzY : X.hb z Y) (hx : X.rf x Y) (hret : ¬ Recycled C (C.attEpoch w) x) :
    ∃ j w', C.isAttempt j w' ∧ (C.pins j).inAttempt w' x ∧ (I.isPubCas x ∨ I.isPubStore x) ∧
      C.attEpoch w' = C.attEpoch w ∧ (x = z ∨ X.mo z x) := by
  have hli := (I.pub_slot hzk hw hz hzl).1
  rcases coh_wr hc hzW (hzl.trans hYl.symm) hzY hx with heq | hmo
  · subst heq
    exact ⟨i, w, hw, hz, hzk, rfl, Or.inl rfl⟩
  · have ⟨_, hxW, hlx, _⟩ := hc.mo_wf hmo
    have hxl : X.loc x = some l := hlx.symm.trans hzl
    rcases I.idx_writes hxW hxl hli with ⟨hinit, -⟩ | ⟨hxk, j, w', hw', hx'⟩
    · exact (not_mo_first hc hxW (A.arenaInit_first hinit) hmo).elim
    · rcases A.same_arena hxl hzl (I.index_arena hli) hxW hzW hw' hx' hw hz with heq | h | h
      · exact ⟨j, w', hw', hx', hxk, heq, Or.inr hmo⟩
      · exact (hc.mo_irrefl _
          (hc.mo_trans hmo
            (C.arena_writes_ordered hc hw' hw h hx' hz hxW hzW (hxl.trans hzl.symm)
              (some_ne_none hxl)))).elim
      · exact (hret ⟨j, w', hw', hx', h⟩).elim

/-- The record loads `R` of a candidate whose I1 `Y` read the publication `x`
of attempt `w`, made after an acquire fence, read stores of `w` unless one
reads a store of a later incarnation. `record_loads_read` with
`record_load_or_recycled` as its core (I2 release, I1F acquire). -/
theorem entry_records (hc : X.Consistent) {x Y : Ev} {i : C.ι} {w : Ev} {R : Ev → Prop}
    (hxk : I.isPubCas x ∨ I.isPubStore x) (hw : C.isAttempt i w)
    (hx : (C.pins i).inAttempt w x) (hxY : X.rf x Y)
    (hR : Rv.RecLoads (C.R.ctlLoc (C.attEpoch w)) (I.entryOff (X.rval Y)) R)
    (hfence : ∀ Z, R Z → ∃ f, X.sb Y f ∧ X.isAcqFence f ∧ X.sb f Z)
    (hret : ∀ Z v, R Z → X.rf v Z → ¬ Recycled C (C.attEpoch w) v) :
    ∀ Z v, R Z → X.rf v Z → (C.pins i).inAttempt w v := by
  rw [hc.rf_val hxY] at hR
  have key := I.record_loads_read hc hxk hw hx hR
    fun Z sZ l hZ hsZ hsZW hsZl hZl hrec hsb v hv => by
      obtain ⟨f, hYf, hf, hfZ⟩ := hfence Z hZ
      have h₁ : X.hb sZ Z :=
        hb_trans (hb_of_sb hsb)
          (hb_trans (hb_of_sw (sw.relFence (I.pub_rel hxk) (rs.refl x) hxY hYf hf))
            (hb_of_sb hfZ))
      rcases A.record_load_or_recycled hc Rv hw hsZ hsZW hsZl hZl (I.record_arena hrec) hrec h₁
        hv with h | h
      · exact h
      · exact (hret Z v hZ hv h).elim
  exact fun Z v hZ hv => key Z hZ v hv

end IndexProtocol

namespace ProbeRun

variable {X : Execution Ev Loc} {C : CacheProtocol X} {A : ArenaProtocol X C}
  {Rv : ReserveProtocol X C} {I : IndexProtocol X A Rv}

/-- **F14's premise, discharged.** A retained probe of epoch `f` for key `k`,
whose index loads all follow, in `hb`, a publication `xI` of `k` by an
attempt for `f` in slot `s`, reads what `ChainRead` describes up to `s`.
Before `s`, RB4(c) (`skipped`) gives an entry of `f`, ordered before `xI`,
in each slot; at `s` there is `xI`; each load reads that entry or a later
one of `f` (`entry_after`), for the same key at `s` (RB4(a)), and the
candidates' record loads read its publisher's record (`entry_records`). -/
theorem chain_read (hc : X.Consistent) (P : ProbeRun I) {xI : Ev} {s : Loc} {i : C.ι} {wI : Ev}
    (hxk : I.isPubCas xI ∨ I.isPubStore xI) (hxW : X.isW xI) (hwI : C.isAttempt i wI)
    (hxI : (C.pins i).inAttempt wI xI) (hxl : X.loc xI = some s)
    (hE : P.e = C.attEpoch wI) (hkey : I.pubKey xI = P.key)
    (hafter : ∀ Y, P.i1 Y → X.hb xI Y) (hret : P.Retained (C.attEpoch wI)) :
    ChainRead I P.e P.key P.i1 P.recs s := by
  -- An index load that read a publication of `f` reads its record too.
  have entry : ∀ Y x j w', P.i1 Y → X.rf x Y → C.isAttempt j w' →
      (C.pins j).inAttempt w' x → (I.isPubCas x ∨ I.isPubStore x) →
      C.attEpoch w' = C.attEpoch wI → EntryRead I P.e P.recs Y := by
    intro Y x j w' hY hx hw' hx' hxk' hE'
    refine ⟨x, j, w', hx, hw', hx', hxk', hE'.trans hE.symm, ?_⟩
    have hR := P.recs_addr hY
    rw [hE, ← hE'] at hR
    exact I.entry_records hc hxk' hw' hx' hx hR (fun Z hZ => P.recs_fence hZ)
      (fun Z v hZ hv => by rw [hE']; exact hret Z v (Or.inr ⟨Y, hY, hZ⟩) hv)
  have hsI := I.pub_slot hxk hwI hxI hxl
  refine ⟨fun Y l hY hYl hd => ?_, fun Y hY hYl => ?_⟩
  · -- A slot before `s`: it held an entry of `f` for another key before `xI`.
    obtain ⟨l', hYl', hli, har⟩ := P.i1_slot hY
    rw [hYl] at hYl'
    cases hYl'
    obtain ⟨z, j, w', hzk, hzW, hzl, hw', hz, hEz, hzx, -⟩ :=
      I.skipped hc hxk hwI hxI hxl hli (by rw [har, hE, hsI.2]) (by rw [hkey]; exact hd)
    obtain ⟨x, hx⟩ := P.i1_reads hY
    have hret' : ¬ Recycled C (C.attEpoch w') x := by
      rw [hEz]; exact hret Y x (Or.inl hY) hx
    obtain ⟨j', w'', hw'', hx', hxk', hE'', -⟩ :=
      I.entry_after hc hzk hzW hw' hz hzl hYl (hb_trans hzx (hafter Y hY)) hx hret'
    exact entry Y x j' w'' hY hx hw'' hx' hxk' (hE''.trans hEz)
  · -- The slot `s` itself: `xI` or a later entry of `f`, for the same key.
    obtain ⟨x, hx⟩ := P.i1_reads hY
    obtain ⟨j', w'', hw'', hx', hxk', hE'', -⟩ :=
      I.entry_after hc hxk hxW hwI hxI hxl hYl (hafter Y hY) hx (hret Y x (Or.inl hY) hx)
    refine ⟨entry Y x j' w'' hY hx hw'' hx' hxk' hE'', fun x' hx'' => ?_⟩
    have := hc.rf_unique hx'' hx
    subst this
    have ⟨hxW', _, hlx, _⟩ := hc.rf_wf hx''
    rw [← hkey]
    exact I.slot_key_fixed hc (I.index_arena hsI.1) hxk hxW hxl hwI hxI hxk' hxW'
      (hlx.trans hYl) hw'' hx' hE''

/-- **No miss without recycling.** A retained probe of epoch `f` for key `k`
whose index loads all follow a publication of `k` by an attempt for `f`
does not return Miss (F14 with `chain_read`, at the publication's slot). -/
theorem not_missed (hc : X.Consistent) (P : ProbeRun I) {xI : Ev} {s : Loc} {i : C.ι}
    {wI : Ev} (hxk : I.isPubCas xI ∨ I.isPubStore xI) (hxW : X.isW xI)
    (hwI : C.isAttempt i wI) (hxI : (C.pins i).inAttempt wI xI) (hxl : X.loc xI = some s)
    (hE : P.e = C.attEpoch wI) (hkey : I.pubKey xI = P.key)
    (hafter : ∀ Y, P.i1 Y → X.hb xI Y) (hret : P.Retained (C.attEpoch wI)) : ¬ P.missed :=
  fun hm =>
    have hsI := I.pub_slot hxk hwI hxI hxl
    P.miss_check hm s hsI.1 (by rw [hsI.2, hE])
      (P.chain_read hc hxk hxW hwI hxI hxl hE hkey hafter hret)

end ProbeRun

/-! ## Roots -/

namespace InsertOps

variable {X : Execution Ev Loc} {C : CacheProtocol X} {A : ArenaProtocol X C}
  {Rv : ReserveProtocol X C} {I : IndexProtocol X A Rv} {V : ValueProtocol X I}
  (Ops : InsertOps X V)

/-- An insert's call happens before everything its attempts do. -/
theorem call_before {i : C.ι} {w a : Ev} {J : Ops.Op} (hw : C.isAttempt i w)
    (hins : Ops.insOf w J) (ha : (C.pins i).inAttempt w a) : X.hb (Ops.insCall J) a := by
  obtain ⟨q1, s, -, -, -, hcq, hqw, -⟩ := Ops.att_w1 hw hins
  exact sbr_hb hcq (hb_sbr (hb_of_sb hqw) ha.1)

/-- **FR3, step 0.** A lookup whose V1 follows an insert's return reads at
least the epoch of each of the insert's attempts (COH-RR, EP1). -/
theorem epoch_ge (hc : X.Consistent) {i : C.ι} {w r v1 s1 : Ev} {J : Ops.Op}
    (hw : C.isAttempt i w) (hins : Ops.insOf w J) (hr : Ops.insRet J r) (hrv : X.hb r v1)
    (hv1 : X.rf s1 v1) (hv1l : X.loc v1 = some C.R.ep) : C.attEpoch w ≤ X.rval v1 := by
  obtain ⟨q1, s, hsq, hql, hqv, -, -, hqr⟩ := Ops.att_w1 hw hins
  have h₁ : X.hb q1 v1 := hb_trans (hb_of_sb (hqr r hr)) hrv
  have ⟨hs1W, _, hls1, _⟩ := hc.rf_wf hv1
  rw [← hqv, hc.rf_val hsq, hc.rf_val hv1]
  rcases coh_rr hc (hql.trans hv1l.symm) h₁ hsq hv1 with heq | hm
  · rw [heq]; exact Nat.le_refl _
  · exact Nat.le_of_lt (C.R.epoch_mono hc s1 hs1W (hls1.trans hv1l) s hm)

end InsertOps

section Roots

variable {X : Execution Ev Loc} {C : CacheProtocol X} {A : ArenaProtocol X C}
  {Rv : ReserveProtocol X C} {I : IndexProtocol X A Rv} {V : ValueProtocol X I}
  {RP : ReadinessProtocol X C}

/-- An attempt of an insert descends from that insert only. -/
theorem desc_of_ins (Ops : InsertOps X V) {w : Ev} {J J' : Ops.Op} (hins : Ops.insOf w J)
    (hD : Desc RP Ops w J') : J' = J := by
  cases hD with
  | ins h => exact Ops.insOf_unique h hins
  | promo H _ hp _ _ _ _ => exact absurd hp (Ops.insOf_np hins)

/-- Every store attempt has a root: by induction on the epoch, since a
promotion copies a hit of the epoch before its own (`promo_ready`, HT2). -/
theorem desc_exists (hc : X.Consistent) (PP : PromotionProtocol X V RP) (Ops : InsertOps X V)
    {i : C.ι} {w : Ev} (hw : C.isAttempt i w) : ∃ J, Desc RP Ops w J := by
  suffices h : ∀ n, ∀ {i : C.ι} {w : Ev}, C.isAttempt i w → C.attEpoch w = n →
      ∃ J, Desc RP Ops w J from h _ hw rfl
  intro n
  induction n using Nat.strongRecOn with
  | ind n ih =>
    intro i w hw hn
    by_cases hp : V.isPromo w
    · obtain ⟨H, hPH⟩ := PP.promo_ready hw hp
      obtain ⟨j, w0, hw0, hx2, -, hE, -⟩ := I.hit_integrity hc H
      have h₁ := hPH.1.1
      have h₂ := hPH.1.2.1
      obtain ⟨J, hJ⟩ := ih (C.attEpoch w0) (by omega) hw0 rfl
      exact ⟨J, Desc.promo H hw hp hPH hw0 hx2 hJ⟩
    · obtain ⟨J, hJ⟩ := Ops.ins_of_attempt hw hp
      exact ⟨J, Desc.ins hJ⟩

/-- Every store attempt has at most one root, so exactly one with
`desc_exists`: by induction on the epoch. Two inserts of one attempt are
one (`insOf_unique`), and an insert's attempt is not a promotion
(`insOf_np`). A promotion copies one hit, whose I1 is `V.src w`
(`PromoHit`); that load read one write (rf is functional), which belongs
to one attempt (`att_unique`), for the epoch before the promotion's (HT2). -/
theorem desc_unique (hc : X.Consistent) (Ops : InsertOps X V) {w : Ev} {J J' : Ops.Op}
    (hD : Desc RP Ops w J) (hD' : Desc RP Ops w J') : J = J' := by
  suffices h : ∀ n, ∀ {w : Ev} {J J' : Ops.Op}, C.attEpoch w = n →
      Desc RP Ops w J → Desc RP Ops w J' → J = J' from h _ rfl hD hD'
  intro n
  induction n using Nat.strongRecOn with
  | ind n ih =>
    intro w J J' hn hD hD'
    cases hD with
    | ins h => exact (desc_of_ins Ops h hD').symm
    | promo H hw hp hPH hw0 hx2 hD0 =>
      cases hD' with
      | ins h' => exact desc_of_ins Ops h' (Desc.promo H hw hp hPH hw0 hx2 hD0)
      | promo H' _ _ hPH' hw0' hx2' hD0' =>
        have hi1 : H'.i1 = H.i1 := hPH'.2.2.trans hPH.2.2.symm
        have hx : H'.x2 = H.x2 := hc.rf_unique (hi1 ▸ H'.i1_rf) H.i1_rf
        rw [hx] at hx2'
        obtain ⟨-, rfl⟩ := Rv.att_unique hw0' hw0 hx2' hx2
        obtain ⟨j1, w1, hw1, hx21, -, hE, -⟩ := I.hit_integrity hc H
        obtain ⟨-, rfl⟩ := Rv.att_unique hw1 hw0 hx21 hx2
        have h₁ := hPH.1.1
        have h₂ := hPH.1.2.1
        exact ih (C.attEpoch w1) (by omega) rfl hD0 hD0'

/-- An attempt stores the key of each of its roots. -/
theorem desc_key (hc : X.Consistent) (Ops : InsertOps X V) {w : Ev} {J : Ops.Op}
    (hD : Desc RP Ops w J) : V.attKey w = Ops.insKey J := by
  induction hD with
  | ins h => exact Ops.ins_key h
  | promo H _ _ hPH hw0 hx2 _ ih =>
    obtain ⟨j1, w1, hw1, hx21, -, -, hread⟩ := I.hit_integrity hc H
    obtain ⟨-, rfl⟩ := Rv.att_unique hw1 hw0 hx21 hx2
    exact (hPH.1.2.2 _ _ hw1 hread).1.symm.trans ih

/-- **FR3, Lemma A.** A publication `x` of the key in incarnation `f`, in the
slot of the insert's publication `xI`, that is `xI` or follows it in `mo`,
has root `I` or an insert that does not precede `I`. If it is not `xI`, it
is not a CAS (RB4(d), `cas_first`), so not a promotion's (F9, `promo_cas`),
so an insert's; that insert did not return before `I`'s call, or its I2
would precede `xI` in `mo` (COH-WW). -/
theorem root_at_f (hc : X.Consistent) (PP : PromotionProtocol X V RP) (Ops : InsertOps X V)
    {Iop : Ops.Op} {iI : C.ι} {wI xI : Ev} {s : Loc}
    (hwI : C.isAttempt iI wI) (hins : Ops.insOf wI Iop) (hxI : (C.pins iI).inAttempt wI xI)
    (hxk : I.isPubCas xI ∨ I.isPubStore xI) (hxW : X.isW xI) (hxl : X.loc xI = some s)
    {j : C.ι} {w0 x : Ev} (hw0 : C.isAttempt j w0) (hx : (C.pins j).inAttempt w0 x)
    (hxk' : I.isPubCas x ∨ I.isPubStore x) (hxW' : X.isW x) (hxl' : X.loc x = some s)
    (hE : C.attEpoch w0 = C.attEpoch wI) (hord : x = xI ∨ X.mo xI x) :
    ∀ J, Desc RP Ops w0 J → J = Iop ∨ ¬ Ops.precedes J Iop := by
  intro J hD
  rcases hord with heq | hmo
  · subst heq
    obtain ⟨-, rfl⟩ := Rv.att_unique hw0 hwI hx hxI
    exact Or.inl (desc_of_ins Ops hins hD)
  · have hl := I.index_arena (I.pub_slot hxk hwI hxI hxl).1
    have hnp : ¬ V.isPromo w0 := fun hp =>
      I.cas_first hc hl (PP.promo_cas hw0 hp hx hxk') hxW' hxl' hw0 hx hxW hxl hwI hxI hE.symm
        hmo
    obtain ⟨J', hJ'⟩ := Ops.ins_of_attempt hw0 hnp
    have := desc_of_ins Ops hJ' hD
    subst this
    right
    rintro ⟨r, hr, hrc⟩
    have h₁ : X.hb x xI :=
      sbr_hb (Ops.att_ret hw0 hJ' hx hr) (hb_trans hrc (Ops.call_before hwI hins hxI))
    exact hc.mo_irrefl _ (hc.mo_trans hmo (coh_ww_at hc hxW' hxl' hxW hxl h₁))

/-- **FR3, `OK(h)`.** Every attempt for an epoch `h > f` that stores the key
has root `I` or an insert that does not precede `I`. An insert's attempt
for `h` belongs to an insert that did not return before `I`'s call: its W1
would precede `I`'s W1, which read `f < h` (Lemma B, COH-RR and EP1). A
promotion for `h` copies the `mo`-last publication of the key in `h - 1`
(FR2, `promo_fresh`): for `h - 1 = f` that is `xI` or a later one
(`root_at_f`); otherwise induction. -/
theorem root_after (hc : X.Consistent) (PP : PromotionProtocol X V RP) (Ops : InsertOps X V)
    {Iop : Ops.Op} {iI : C.ι} {wI xI : Ev} {s : Loc}
    (hwI : C.isAttempt iI wI) (hins : Ops.insOf wI Iop) (hxI : (C.pins iI).inAttempt wI xI)
    (hxk : I.isPubCas xI ∨ I.isPubStore xI) (hxW : X.isW xI) (hxl : X.loc xI = some s) :
    ∀ h, C.attEpoch wI + 1 ≤ h → ∀ {j : C.ι} {w' : Ev}, C.isAttempt j w' →
      C.attEpoch w' = h → V.attKey w' = I.pubKey xI →
      ∀ J, Desc RP Ops w' J → J = Iop ∨ ¬ Ops.precedes J Iop := by
  intro h
  induction h using Nat.strongRecOn with
  | ind h ih =>
    intro hh j w' hw' hE hkey J hD
    cases hD with
    | ins hJ =>
      -- Lemma B.
      right
      rintro ⟨r, hr, hrc⟩
      obtain ⟨q1, s1, hsq, hql, hqv, -, -, hqr⟩ := Ops.att_w1 hw' hJ
      obtain ⟨q1I, sI, hsqI, hqlI, hqvI, hcqI, -, -⟩ := Ops.att_w1 hwI hins
      have h₁ : X.hb q1 q1I :=
        hb_trans (hb_of_sb (hqr r hr)) (hb_sbr hrc (hcqI.elim (fun h => Or.inl h.symm) Or.inr))
      have ⟨hsIW, _, hlsI, _⟩ := hc.rf_wf hsqI
      have hle : X.rval q1 ≤ X.rval q1I := by
        rw [hc.rf_val hsq, hc.rf_val hsqI]
        rcases coh_rr hc (hql.trans hqlI.symm) h₁ hsq hsqI with heq | hm
        · rw [heq]; exact Nat.le_refl _
        · exact Nat.le_of_lt (C.R.epoch_mono hc sI hsIW (hlsI.trans hqlI) s1 hm)
      omega
    | promo H _ _ hPH hw0 hx2 hD0 =>
      obtain ⟨j1, w1, hw1, hx21, hx2k, hE1, hkey1, -, hall⟩ :=
        PromotionProtocol.promo_fresh hc hPH
      obtain ⟨-, rfl⟩ := Rv.att_unique hw1 hw0 hx21 hx2
      have hv1 := hPH.1.1
      have hpin := hPH.1.2.1
      have ⟨hx2W, _, hlx2, _⟩ := hc.rf_wf H.i1_rf
      have hx2l : X.loc H.x2 = some H.slot := hlx2.trans H.i1_loc
      have hk2 : I.pubKey H.x2 = I.pubKey xI := hkey1.trans hkey
      by_cases hHe : H.e = C.attEpoch wI
      · obtain ⟨hs, hord⟩ := hall hxk hxW hxl hwI hxI hHe.symm hk2.symm
        subst hs
        exact root_at_f hc PP Ops hwI hins hxI hxk hxW hxl hw1 hx21 hx2k hx2W hx2l
          (hE1.trans hHe) (hord.elim (fun h => Or.inl h.symm) Or.inr) J hD0
      · exact ih H.e (by omega) (by omega) hw1 hE1
          ((V.pub_key hx2k hw1 hx21).symm.trans hk2) J hD0

end Roots

/-! ## FR3 and FR4 -/

section Main

variable {X : Execution Ev Loc} {C : CacheProtocol X} {A : ArenaProtocol X C}
  {Rv : ReserveProtocol X C} {I : IndexProtocol X A Rv} {V : ValueProtocol X I}
  {RP : ReadinessProtocol X C}

/-- **FR3, read-after-insert: no older value.** Let an attempt `wI` of insert
`I` publish `xI` (as every insert that returns `Ok` does, `ok_pub`), and let
the final pass of lookup `L` of `I`'s key start (V1) after a return of `I`
in `hb`. If `L` returns a hit, the record it returns is that of an attempt
`w0` whose every root is `I` or an insert that does not precede `I`. So `L`
never returns a record that originates in an insert that returned before
`I` was called, directly or as a promoted copy, in any generation.

Let `f` be `wI`'s epoch and `E` the hit's. V1 read at least `f`
(`epoch_ge`). For `E > f`, `root_after`. For `E = f`, the hit's entry is in
`xI`'s slot (RB4(b)) and is `xI` or later (COH-WR, as `xI` happens before
its I1), so `root_at_f`. For `E < f`, V1 read `f` and the probe of `f`
missed; its loads are sequenced before the hit's D6, so none reads a store
of an attempt for `f + 3` or later (HT1 against V2's bound, HT2), and a
retained probe of `f` does not miss (`not_missed`). -/
theorem no_older_value (hc : X.Consistent) (PP : PromotionProtocol X V RP)
    (Ops : InsertOps X V) (L : LookupRun V) {Iop : Ops.Op} {iI : C.ι} {wI xI r : Ev}
    {s : Loc} (hwI : C.isAttempt iI wI) (hins : Ops.insOf wI Iop)
    (hxI : (C.pins iI).inAttempt wI xI) (hxk : I.isPubCas xI ∨ I.isPubStore xI)
    (hxW : X.isW xI) (hxl : X.loc xI = some s) (hr : Ops.insRet Iop r)
    (hkey : L.key = Ops.insKey Iop) (hafter : X.hb r L.v1) {H : Hit I} (hH : L.hit H) :
    ∃ j w0, C.isAttempt j w0 ∧ (C.pins j).inAttempt w0 H.x2 ∧
      (∀ Y, H.loads Y → ∀ z, X.rf z Y → (C.pins j).inAttempt w0 z) ∧
      ∀ J, Desc RP Ops w0 J → J = Iop ∨ ¬ Ops.precedes J Iop := by
  obtain ⟨j, w0, hw0, hx2, hx2k, hE, hread⟩ := I.hit_integrity hc H
  refine ⟨j, w0, hw0, hx2, hread, ?_⟩
  have hkI : I.pubKey xI = L.key := by
    rw [V.pub_key hxk hwI hxI, Ops.ins_key hins, hkey]
  have hk0 : V.attKey w0 = I.pubKey xI := (L.hit_key hH j w0 hw0 hread).trans hkI.symm
  have hf := Ops.epoch_ge hc hwI hins hr hafter L.v1_rf L.v1_loc
  have hed := H.e_def
  rw [L.hit_v1 hH] at hed
  have hvi : X.sb L.v1 H.i1 := by rw [← L.hit_v1 hH]; exact H.v1_i1
  have hxIi1 : X.hb xI H.i1 :=
    sbr_hb (Ops.att_ret hwI hins hxI hr) (hb_trans hafter (hb_of_sb hvi))
  rcases Nat.lt_trichotomy H.e (C.attEpoch wI) with hlt | heq | hgt
  · -- `E = f - 1`: the probe of `f` missed, yet nothing it read was recycled.
    exfalso
    have hg : H.e + 1 = X.rval L.v1 := by omega
    obtain ⟨hmiss, hsb⟩ := L.hit_prev hH hg
    have hret : L.cur.Retained (C.attEpoch wI) := by
      rintro Z v hZ hv ⟨j', w', hw', hv', hge⟩
      obtain ⟨l, hZl, hla⟩ : ∃ l, X.loc Z = some l ∧ A.isArena l := by
        rcases hZ with hZ | ⟨Y, hY, hYZ⟩
        · obtain ⟨l, hl, hli, -⟩ := L.cur.i1_slot hZ
          exact ⟨l, hl, I.index_arena hli⟩
        · obtain ⟨l, hl, hrec, -⟩ := L.cur.recs_addr hY Z hYZ
          exact ⟨l, hl, I.record_arena hrec⟩
      have ⟨hvW, _, hlv, _⟩ := hc.rf_wf hv
      have h₁ := A.recycled_caught hc hvW (hlv.trans hZl) hla hw' hv' hv (hsb Z hZ) H.d6_acq
        H.d6_v2 H.v2_rf H.v2_loc
      have h₂ := I.hit_v2_bound hc H
      omega
    exact L.cur.not_missed hc hxk hxW hwI hxI hxl (by rw [L.cur_e]; omega)
      (hkI.trans (L.probe_key L.cur_probe).symm)
      (fun Y hY => sbr_hb (Ops.att_ret hwI hins hxI hr)
        (hb_trans hafter (hb_of_sb (L.probe_after L.cur_probe hY))))
      hret hmiss
  · -- `E = f`: the hit read `xI` or a later entry of the key, in `xI`'s slot.
    have ⟨hx2W, _, hlx2, _⟩ := hc.rf_wf H.i1_rf
    have hx2l : X.loc H.x2 = some H.slot := hlx2.trans H.i1_loc
    have hslot : s = H.slot :=
      I.key_one_slot hc hxk hxW hxl hwI hxI hx2k hx2W hx2l hw0 hx2 (hE.trans heq)
        (by rw [V.pub_key hx2k hw0 hx2, hk0])
    subst hslot
    have hord : H.x2 = xI ∨ X.mo xI H.x2 :=
      coh_wr hc hxW (hxl.trans H.i1_loc.symm) hxIi1 H.i1_rf
    exact root_at_f hc PP Ops hwI hins hxI hxk hxW hxl hw0 hx2 hx2k hx2W hx2l (hE.trans heq)
      hord
  · -- `E > f`.
    exact root_after hc PP Ops hwI hins hxI hxk hxW hxl H.e (by omega) hw0 hE hk0

/-- **FR3, successful-insert form: promotions cannot resurrect a superseded
insert.** If `Iop` returned `Ok` and the final pass of a lookup of its key
starts after that return in `hb`, every root of a returned hit is `Iop` or
an insert that does not precede it. This permits concurrent inserts to win
and the lookup to miss, but never to return a record of an insert that
completed before `Iop` began, however often it was promoted. No retention
or bound on rotations is needed. The claim is about provenance, not
inequality of value bytes. -/
theorem successful_insert_no_resurrection (hc : X.Consistent)
    (PP : PromotionProtocol X V RP) (Ops : InsertOps X V) (L : LookupRun V)
    {Iop : Ops.Op} {r : Ev} (hok : Ops.insOk Iop r)
    (hkey : L.key = Ops.insKey Iop) (hafter : X.hb r L.v1) {H : Hit I} (hH : L.hit H) :
    ∃ j w0, C.isAttempt j w0 ∧ (C.pins j).inAttempt w0 H.x2 ∧
      (∀ Y, H.loads Y → ∀ z, X.rf z Y → (C.pins j).inAttempt w0 z) ∧
      ∀ J, Desc RP Ops w0 J → J = Iop ∨ ¬ Ops.precedes J Iop := by
  obtain ⟨iI, wI, xI, hwI, hins, hxI, hxk, hxW, s, hxl⟩ := Ops.ok_pub hok
  exact no_older_value hc PP Ops L hwI hins hxI hxk hxW hxl
    (Ops.ok_ret hok) hkey hafter hH

/-- **FR4, retention: the lookup hits.** In FR3's setting, if V1 of `L`'s
final pass read `f` or `f + 1` and no load of the pass's probes reads a
store of an attempt for `f + 3` or later, `L` does not return a miss: it
returns a hit (to which FR3 applies), InsufficientCapacity or Corrupt. A
miss needs the probe of V1's arena to miss and then a probe of the epoch
before; one of them probes `f`, after `xI` in `hb`, and a retained probe of
`f` does not miss (`not_missed`). Without retention the claim is false:
misses are not validated by V2. -/
theorem retained_hit (hc : X.Consistent) (Ops : InsertOps X V) (L : LookupRun V)
    {Iop : Ops.Op} {iI : C.ι} {wI xI r : Ev} {s : Loc}
    (hwI : C.isAttempt iI wI) (hins : Ops.insOf wI Iop) (hxI : (C.pins iI).inAttempt wI xI)
    (hxk : I.isPubCas xI ∨ I.isPubStore xI) (hxW : X.isW xI) (hxl : X.loc xI = some s)
    (hr : Ops.insRet Iop r) (hkey : L.key = Ops.insKey Iop) (hafter : X.hb r L.v1)
    (hg : X.rval L.v1 ≤ C.attEpoch wI + 1)
    (hret : ∀ P, L.probe P → P.Retained (C.attEpoch wI)) : ¬ L.miss := by
  intro hm
  obtain ⟨hcur, P, hP, hPe, hPm⟩ := L.miss_probes hm
  have hf := Ops.epoch_ge hc hwI hins hr hafter L.v1_rf L.v1_loc
  have hkI : I.pubKey xI = L.key := by
    rw [V.pub_key hxk hwI hxI, Ops.ins_key hins, hkey]
  have after : ∀ P, L.probe P → ∀ Y, P.i1 Y → X.hb xI Y := fun P hP Y hY =>
    sbr_hb (Ops.att_ret hwI hins hxI hr) (hb_trans hafter (hb_of_sb (L.probe_after hP hY)))
  by_cases hgf : X.rval L.v1 = C.attEpoch wI
  · exact L.cur.not_missed hc hxk hxW hwI hxI hxl (L.cur_e.trans hgf)
      (hkI.trans (L.probe_key L.cur_probe).symm) (after _ L.cur_probe) (hret _ L.cur_probe)
      hcur
  · exact P.not_missed hc hxk hxW hwI hxI hxl (by omega) (hkI.trans (L.probe_key hP).symm)
      (after P hP) (hret P hP) hPm

/-- A load that happens before the R5 `p` reads no store of an attempt for
`wval p` or later: such an attempt's acquire W1 read `p` or a later R5
(EP1), which `p` happens before (OW2), so the store happens after the load
(COH-RW). -/
theorem not_read_after (hc : X.Consistent) {Y p Bp v : Ev} {j : C.ι} {w' : Ev}
    (h5 : C.R.r5Of Bp p) (hYp : X.hb Y p) (hw' : C.isAttempt j w')
    (hv : (C.pins j).inAttempt w' v) (hn : X.wval p ≤ C.attEpoch w') : ¬ X.rf v Y := by
  intro hrf
  obtain ⟨q1, s, hsq, hacq, hql, hqw, hqv⟩ := C.att_w1 hw'
  have ⟨hsW, _, hls, _⟩ := hc.rf_wf hsq
  have hsl : X.loc s = some C.R.ep := hls.trans hql
  have hpl := C.R.r5_loc h5
  have hsv : X.wval s = C.attEpoch w' := by rw [← hc.rf_val hsq, hqv]
  have hps : X.hb p q1 := by
    rcases hc.mo_total (C.R.r5_w h5) hsW (hpl.trans hsl.symm) (some_ne_none hpl)
      with heq | hm | hm
    · rw [heq]; exact hb_of_sw (sw.relAcq (C.r5_isRel (heq ▸ h5)) (rs.refl _) hsq hacq)
    · rcases C.R.ep_writes hsW hsl with hinit | ⟨B, h5'⟩
      · rw [hinit] at hm; exact (C.R.not_mo_epInit hc hm).elim
      · rcases C.R.toOwnerProtocol.tenure_total hc (C.R.r5_acq h5) (C.R.r5_acq h5')
          with heq | hAB | hBA
        · subst heq
          have := C.R.r5_unique h5 h5'
          subst this
          exact (hc.mo_irrefl _ hm).elim
        · exact hb_trans
            (C.R.toOwnerProtocol.tenures_ordered hc (C.R.r5_acq h5) (C.R.r5_acq h5') hAB
              (C.R.r5_under h5) (C.R.r5_under h5'))
            (hb_of_sw (sw.relAcq (C.r5_isRel h5') (rs.refl _) hsq hacq))
        · exact (hc.mo_irrefl _ (hc.mo_trans hm (coh_ww_at hc (C.R.r5_w h5') hsl
            (C.R.r5_w h5) hpl
            (C.R.toOwnerProtocol.tenures_ordered hc (C.R.r5_acq h5') (C.R.r5_acq h5) hBA
              (C.R.r5_under h5') (C.R.r5_under h5))))).elim
    · have := C.R.epoch_mono hc p (C.R.r5_w h5) hpl s hm
      omega
  exact hc.coherence (hb_trans hYp (hb_trans hps (hb_sbr (hb_of_sb hqw) hv.1)))
    (cob_of_rf hrf)

/-- **FR4, the retention condition.** A probe whose loads all happen before
an R5 that publishes `f + 3` or less is retained for `f`. -/
theorem ProbeRun.retained_of_before (hc : X.Consistent) (P : ProbeRun I) {p Bp : Ev}
    {n : Nat} (h5 : C.R.r5Of Bp p) (hpn : X.wval p ≤ n + 3)
    (hbefore : ∀ Z, P.loads Z → X.hb Z p) : P.Retained n :=
  fun Z v hZ hv ⟨_, _, hw', hv', hge⟩ =>
    not_read_after hc h5 (hbefore Z hZ) hw' hv' (by omega) hv

/-- **FR4, the epoch condition.** A V1 that happens before an R5 read a
smaller epoch (COH-RW, EP1). -/
theorem LookupRun.epoch_le_of_before (hc : X.Consistent) (L : LookupRun V) {p Bp : Ev}
    (h5 : C.R.r5Of Bp p) (hv1p : X.hb L.v1 p) : X.rval L.v1 < X.wval p := by
  have hm := coh_rw hc (C.R.r5_w h5) ((C.R.r5_loc h5).trans L.v1_loc.symm) hv1p L.v1_rf
  rw [hc.rf_val L.v1_rf]
  exact C.R.epoch_mono hc p (C.R.r5_w h5) (C.R.r5_loc h5) L.s1 hm

/-- **FR4, sufficient condition.** If V1 and every load of `L`'s final pass
happen before the R5 that publishes `f + 2` (for instance because `L`
returns before it), `L` does not return a miss. -/
theorem retained_hit_of_before (hc : X.Consistent) (Ops : InsertOps X V) (L : LookupRun V)
    {Iop : Ops.Op} {iI : C.ι} {wI xI r p Bp : Ev} {s : Loc}
    (hwI : C.isAttempt iI wI) (hins : Ops.insOf wI Iop) (hxI : (C.pins iI).inAttempt wI xI)
    (hxk : I.isPubCas xI ∨ I.isPubStore xI) (hxW : X.isW xI) (hxl : X.loc xI = some s)
    (hr : Ops.insRet Iop r) (hkey : L.key = Ops.insKey Iop) (hafter : X.hb r L.v1)
    (h5 : C.R.r5Of Bp p) (hp : X.wval p = C.attEpoch wI + 2) (hv1p : X.hb L.v1 p)
    (hbefore : ∀ P, L.probe P → ∀ Z, P.loads Z → X.hb Z p) : ¬ L.miss := by
  have := L.epoch_le_of_before hc h5 hv1p
  exact retained_hit hc Ops L hwI hins hxI hxk hxW hxl hr hkey hafter (by omega)
    (fun P hP => P.retained_of_before hc h5 (by omega) (hbefore P hP))

/-- **The regression scenario** (`promotion_keeps_newer_stale_epoch_insert`):
the epoch is never published above 3 (one rotation at most), insert `I`
returned `Ok`, every other insert of its key returned before `I` was called,
and the final pass of lookup `L` of the key starts after `I`'s return. Then
`L` does not miss, and a hit it returns has root `I`: the newer value, never
the older one, whether `L` hits the previous generation directly or after a
promotion. -/
theorem regression (hc : X.Consistent) (PP : PromotionProtocol X V RP) (Ops : InsertOps X V)
    (L : LookupRun V) {Iop : Ops.Op} {r : Ev} (hok : Ops.insOk Iop r)
    (hkey : L.key = Ops.insKey Iop) (hafter : X.hb r L.v1)
    (hep : ∀ e, X.isW e → X.loc e = some C.R.ep → X.wval e ≤ 3)
    (honly : ∀ J, Ops.insKey J = Ops.insKey Iop → J ≠ Iop → Ops.precedes J Iop) :
    ¬ L.miss ∧ ∀ H, L.hit H → ∃ j w0, C.isAttempt j w0 ∧ (C.pins j).inAttempt w0 H.x2 ∧
      (∀ Y, H.loads Y → ∀ z, X.rf z Y → (C.pins j).inAttempt w0 z) ∧
      ∀ J, Desc RP Ops w0 J → J = Iop := by
  obtain ⟨iI, wI, xI, hwI, hins, hxI, hxk, hxW, s, hxl⟩ := Ops.ok_pub hok
  have hr := Ops.ok_ret hok
  have hge := C.att_ge hc hwI
  have hg : X.rval L.v1 ≤ C.attEpoch wI + 1 := by
    have ⟨hs1W, _, hls1, _⟩ := hc.rf_wf L.v1_rf
    have := hep _ hs1W (hls1.trans L.v1_loc)
    rw [hc.rf_val L.v1_rf]
    omega
  refine ⟨retained_hit hc Ops L hwI hins hxI hxk hxW hxl hr hkey hafter hg ?_, fun H hH => ?_⟩
  · -- No attempt is for an epoch above 3, so nothing is recycled.
    intro P _ Z v _ _ ⟨j, w', hw', _, hge'⟩
    obtain ⟨q1, s', hsq, -, hql, -, hqv⟩ := C.att_w1 hw'
    have ⟨hsW, _, hls, _⟩ := hc.rf_wf hsq
    have := hep _ hsW (hls.trans hql)
    rw [← hc.rf_val hsq, hqv] at this
    omega
  · obtain ⟨j, w0, hw0, hx2, hread, hroot⟩ :=
      no_older_value hc PP Ops L hwI hins hxI hxk hxW hxl hr hkey hafter hH
    refine ⟨j, w0, hw0, hx2, hread, fun J hD => ?_⟩
    rcases hroot J hD with h | h
    · exact h
    · by_cases hne : J = Iop
      · exact hne
      · exact (h (honly J (by rw [← desc_key hc Ops hD, L.hit_key hH j w0 hw0 hread, hkey])
          hne)).elim

end Main

/-! ## A failed insert is invisible -/

section Failed

variable {X : Execution Ev Loc} {C : CacheProtocol X} {A : ArenaProtocol X C}
  {Rv : ReserveProtocol X C} {I : IndexProtocol X A Rv} {V : ValueProtocol X I}
  {RP : ReadinessProtocol X C}

/-- **No published descendant.** If no attempt of insert `J` made an I2,
then no attempt that made an I2 descends from `J`. By induction on `Desc`:
an attempt of `J` made no I2 by hypothesis; a promotion descends through
the attempt whose publication `H.x2` its promoted hit followed, and `H.x2`
is an I2 (`hit_integrity`), so that attempt does not descend from `J`. -/
theorem pub_not_desc (hc : X.Consistent) (Ops : InsertOps X V) {J : Ops.Op}
    (hnp : ∀ {i w x}, C.isAttempt i w → Ops.insOf w J → (C.pins i).inAttempt w x →
      ¬ (I.isPubCas x ∨ I.isPubStore x))
    {w : Ev} (hD : Desc RP Ops w J) :
    ∀ {i : C.ι} {x : Ev}, C.isAttempt i w → (C.pins i).inAttempt w x →
      ¬ (I.isPubCas x ∨ I.isPubStore x) := by
  induction hD with
  | ins h => exact fun hw hx => hnp hw h hx
  | promo H _ _ _ hw0 hx2 _ ih =>
    intro _ _ _ _ _
    obtain ⟨-, -, -, -, hx2k, -, -⟩ := I.hit_integrity hc H
    exact ih hnp hw0 hx2 hx2k

/-- **An unpublished insert is invisible.** If no attempt of insert `J` made
an I2, then every hit `H` followed the publication `H.x2` of an attempt
`w0`, whose stores every record load of `H` read (`hit_integrity`, HT2),
and `w0` does not descend from `J` (`pub_not_desc`): no hit returns a
record of `J`, directly or as a promoted copy, in any generation.

The hypothesis holds for every insert that returns an error other than
`Error::Corrupt` (`InsertOps.err_nopub`, used by `failed_invisible`).
`Corrupt` returns are outside the failed-insert guarantee. -/
theorem unpublished_invisible (hc : X.Consistent) (Ops : InsertOps X V) {J : Ops.Op}
    (hnp : ∀ {i w x}, C.isAttempt i w → Ops.insOf w J → (C.pins i).inAttempt w x →
      ¬ (I.isPubCas x ∨ I.isPubStore x))
    (H : Hit I) :
    ∃ j w0, C.isAttempt j w0 ∧ (C.pins j).inAttempt w0 H.x2 ∧
      (∀ Y, H.loads Y → ∀ z, X.rf z Y → (C.pins j).inAttempt w0 z) ∧
      ¬ Desc RP Ops w0 J := by
  obtain ⟨j, w0, hw0, hx2, hx2k, -, hread⟩ := I.hit_integrity hc H
  exact ⟨j, w0, hw0, hx2, hread, fun hD => pub_not_desc hc Ops hnp hD hw0 hx2 hx2k⟩

/-- **A failed insert is invisible.** Let insert `J` return an error other
than `Error::Corrupt`. Every hit `H` followed the publication of an attempt
`w0` whose stores its record loads read, and `w0` does not descend from
`J`: `J` made no I2 (`InsertOps.err_nopub`), so `unpublished_invisible`
applies. `Corrupt` returns are outside this guarantee. -/
theorem failed_invisible (hc : X.Consistent) (Ops : InsertOps X V) {J : Ops.Op} {r : Ev}
    (herr : Ops.insErr J r) (hnc : ¬ Ops.insCorrupt J r) (H : Hit I) :
    ∃ j w0, C.isAttempt j w0 ∧ (C.pins j).inAttempt w0 H.x2 ∧
      (∀ Y, H.loads Y → ∀ z, X.rf z Y → (C.pins j).inAttempt w0 z) ∧
      ¬ Desc RP Ops w0 J :=
  unpublished_invisible hc Ops (Ops.err_nopub herr hnc) H

/-- **A lookup never returns a failed insert's record.** The hit that the
final pass of lookup `L` returns read the record of an attempt that does not
descend from insert `J`, if `J` returned an error other than
`Error::Corrupt` (`failed_invisible`). Unlike FR3 (`no_older_value`), this
needs no order between `J` and `L`. -/
theorem failed_lookup_invisible (hc : X.Consistent) (Ops : InsertOps X V) (L : LookupRun V)
    {J : Ops.Op} {r : Ev} (herr : Ops.insErr J r) (hnc : ¬ Ops.insCorrupt J r)
    {H : Hit I} (_ : L.hit H) :
    ∃ j w0, C.isAttempt j w0 ∧ (C.pins j).inAttempt w0 H.x2 ∧
      (∀ Y, H.loads Y → ∀ z, X.rf z Y → (C.pins j).inAttempt w0 z) ∧
      ¬ Desc RP Ops w0 J :=
  failed_invisible hc Ops herr hnc H

end Failed

end Execution
end Sgc
