import SgcProofs.Reserve

/-!
# HT1 and HT2, hit integrity; IX1, RB3 and RB4, the index

The arena words (record words and index slots) are written only by the zero
fill and by store attempts, from W2 to W7, after the attempt's W1 and its
fence W3 (code fact F12, `ArenaProtocol`). IX1, that a publication I2
follows its whole record and encodes its attempt's offset and stamp, is not
a theorem: it is the code-level fields `idx_writes`, `pub_slot`, `pub_off`,
`pub_stamp`, `pub_record`, `entryOff_aligned` and `zero_off` of
`IndexProtocol` (code facts F3, F8).

Record loads, in `probe()` and in `table_put()`'s `entry_has_key()`, are the
header load D4 and the payload loads D5 (the lengths word, the key words
and, in `probe()`, the value words). `table_put()`'s acquire load of an
index slot, which its CAS or store I2 is predicated on, is I0.

* `recycled_caught` (HT1): if a lookup load made before the acquire
  fence D6 reads a store of an attempt for epoch `e`, the epoch reload V2
  after D6 returns at least `e`. This is the second fence argument, with
  the fence-to-fence rule.
* `hit_load` (HT2, one load): a record load of a lookup that
  passed V2, of a word that the followed entry's attempt stored before
  publishing it, reads that store. The same-incarnation case is SP5,
  `record_write_once` in `Reserve.lean`.
* `current_entry` (RB3, the entry): an index load I0 of a store attempt
  that finds its own stamp read a publication of its own epoch. Older
  entries with that stamp are excluded by A6, newer ones by QU2(b).
* `record_loads_read` (the addressing of HT2 and RB3): the
  record loads of `probe()` or `entry_has_key()` address words of the
  followed entry's record, so if each such load reads the store to its
  word, all of them read the record's stores. The lengths load comes first;
  the key and value positions follow from what it returns.
* `hit_integrity` (HT2): every record load of a lookup hit (`Hit`) reads a
  store of the attempt, of the probed epoch, whose publication the hit
  followed.
* `pinned_read`, `keyCheck_holds` (RB3, the key check): a record load that a pinned
  writer makes after acquiring an entry of its own epoch reads the store of
  the entry's attempt. So `entry_has_key()` compares against the key that
  attempt stored; what is left assumed (`KeyCheck`) is sequential: what the
  check concludes from the values it loaded.
* `key_of_earlier`, `slot_key_fixed` (RB4, one key per slot): the entries
  that attempts of one epoch publish in an index slot all carry the same
  key.
* `skipped`, `not_further`, `key_one_slot`, `chain_no_gap` (RB4, one slot
  per key and no gap): the entries of one epoch for one key lie in one
  slot, and every slot the writer passed on its way there held an entry of
  that epoch for another key.

RB3 and RB4 are recency properties: safety does not need them (`probe()`
re-checks the key). They are the only users of I0's acquire.

Neither proof uses the epoch stamp of a record header (`probe.rs:142`,
`put.rs:129`): the entry's stamp, A6 and V2 already pin the record's
incarnation, so A6 is needed for index entries and lookups in progress
only, not for headers.
-/

namespace Sgc
namespace Execution

variable {Ev Loc : Type}

/-- The arena words and who writes them. -/
structure ArenaProtocol (X : Execution Ev Loc) (C : CacheProtocol X) where
  /-- A record word or an index slot. -/
  isArena : Loc → Prop
  /-- The zero fill of an arena word. -/
  isArenaInit : Ev → Prop
  /-- Assumption A1. -/
  arenaInit_first : ∀ {i x}, isArenaInit i → X.isW x → X.loc x = X.loc i → x ≠ i → X.hb i x
  arenaInit_not_att : ∀ {x i w}, isArenaInit x → C.isAttempt i w →
    ¬ (C.pins i).inAttempt w x
  /-- Only store attempts write arena words. -/
  aw_owner : ∀ {x l}, X.isW x → X.loc x = some l → isArena l →
    isArenaInit x ∨ ∃ i w, C.isAttempt i w ∧ (C.pins i).inAttempt w x
  /-- An event belongs to attempts of one epoch. -/
  owner_epoch : ∀ {x i w j w'}, C.isAttempt i w → (C.pins i).inAttempt w x →
    C.isAttempt j w' → (C.pins j).inAttempt w' x → C.attEpoch w = C.attEpoch w'
  /-- The attempts that write one word use the same arena: their epochs are
  equal or at least three apart. -/
  same_arena : ∀ {x x' l i w j w'}, X.loc x = some l → X.loc x' = some l → isArena l →
    X.isW x → X.isW x' →
    C.isAttempt i w → (C.pins i).inAttempt w x →
    C.isAttempt j w' → (C.pins j).inAttempt w' x' →
    C.attEpoch w = C.attEpoch w' ∨ C.attEpoch w + 3 ≤ C.attEpoch w' ∨
      C.attEpoch w' + 3 ≤ C.attEpoch w
  /-- An attempt writes arena words after its W1 and its fence W3 (F12). -/
  aw_after_fence : ∀ {x l i w}, X.isW x → X.loc x = some l → isArena l →
    C.isAttempt i w → (C.pins i).inAttempt w x →
    ∃ q1 s f, X.rf s q1 ∧ X.loc q1 = some C.R.ep ∧ X.rval q1 = C.attEpoch w ∧
      X.sb q1 f ∧ X.isScFence f ∧ X.sb f x

namespace ArenaProtocol

variable {X : Execution Ev Loc} {C : CacheProtocol X} (A : ArenaProtocol X C)

/-- **HT1, recycled reads are caught.** A load `Y` before the acquire
fence `d6` reads an arena store of an attempt for epoch `e`; then the epoch
load `v2` after the fence returns at least `e`. -/
theorem recycled_caught (hc : X.Consistent) {x Y d6 v2 s2 : Ev} {l : Loc} {i : C.ι} {w : Ev}
    (hxW : X.isW x) (hxl : X.loc x = some l) (hl : A.isArena l)
    (hw : C.isAttempt i w) (hx : (C.pins i).inAttempt w x)
    (hrf : X.rf x Y) (hYd : X.sb Y d6) (hd6 : X.isAcqFence d6) (hdv : X.sb d6 v2)
    (hv2 : X.rf s2 v2) (hv2l : X.loc v2 = some C.R.ep) :
    C.attEpoch w ≤ X.rval v2 := by
  obtain ⟨q1, s, f, hsq, hql, hqv, hqf, hf, hfx⟩ := A.aw_after_fence hxW hxl hl hw hx
  -- FENCE-FENCE: the writer's `SeqCst` fence synchronises with `d6`.
  have hsw : X.hb f d6 :=
    hb_of_sw (sw.fenceFence (hc.scFence_relAcq hf).1 hfx hxW (rs.refl x) hrf hYd hd6)
  have h₁ : X.hb q1 v2 := hb_trans (hb_of_sb hqf) (hb_trans hsw (hb_of_sb hdv))
  have ⟨hs2W, _, hls2, _⟩ := hc.rf_wf hv2
  rw [← hqv, hc.rf_val hsq, hc.rf_val hv2]
  rcases coh_rr hc (hql.trans hv2l.symm) h₁ hsq hv2 with heq | hm
  · rw [heq]; exact Nat.le_refl _
  · exact Nat.le_of_lt (C.R.epoch_mono hc s2 hs2W (hls2.trans hv2l) s hm)

/-- **HT2, one load.** A lookup followed the index entry published by `x2`,
of a store attempt `w`, and its epoch reload `v2` passed. Then a load `Y` it
made, between the fences, of a record word that `w` stored at `sY` before
`x2`, read `sY`. A later store of the same incarnation would contradict SP5
(`record_write_once`), one of an earlier incarnation precedes `sY` in `mo`
(QU2(a)), and V2 catches one of a later incarnation (`recycled_caught`).

`hpass` is V2's check as a bound on `w`'s epoch, and `hsYx` says the entry
is `w`'s publication, made after the store `Y` addresses. `hit_integrity`
below discharges both, and the addressing, for every record load of a hit,
from the code's structures. -/
theorem hit_load (hc : X.Consistent) (Rv : ReserveProtocol X C)
    {x2 i1 i1f Y d6 v2 s2 sY z : Ev} {l : Loc} {i : C.ι} {w : Ev}
    (hw : C.isAttempt i w) (hx2rel : X.isRel x2) (hi1 : X.rf x2 i1)
    (hi1f : X.sb i1 i1f) (hfa : X.isAcqFence i1f) (hfY : X.sb i1f Y)
    (hYd : X.sb Y d6) (hd6 : X.isAcqFence d6) (hdv : X.sb d6 v2)
    (hv2 : X.rf s2 v2) (hv2l : X.loc v2 = some C.R.ep)
    (hpass : X.rval v2 ≤ C.attEpoch w + 1)
    (hsY : (C.pins i).inAttempt w sY) (hsYW : X.isW sY) (hsYl : X.loc sY = some l)
    (hYl : X.loc Y = some l) (hl : A.isArena l) (hrec : Rv.isRecord l)
    (hsYx : X.sb sY x2) (hz : X.rf z Y) : z = sY := by
  -- The record store happens before the load: I2 is a release, I1F an acquire fence.
  have h₁ : X.hb sY Y :=
    hb_trans (hb_of_sb hsYx)
      (hb_trans (hb_of_sw (sw.relFence hx2rel (rs.refl x2) hi1 hi1f hfa)) (hb_of_sb hfY))
  rcases coh_wr hc hsYW (hsYl.trans hYl.symm) h₁ hz with heq | hmo
  · exact heq
  · exfalso
    have ⟨_, hzW, hlz, _⟩ := hc.mo_wf hmo
    have hzl : X.loc z = some l := hlz.symm.trans hsYl
    have hsome : X.loc z ≠ none := some_ne_none hzl
    rcases A.aw_owner hzW hzl hl with hinit | ⟨j, w', hw', hz'⟩
    · exact not_mo_first hc hzW (A.arenaInit_first hinit) hmo
    · rcases A.same_arena hzl hsYl hl hzW hsYW hw' hz' hw hsY with heq | h | h
      · -- The same incarnation: the word is stored once (SP5).
        have := Rv.record_write_once hc hw' hz' hw hsY hzW hsYW hzl hsYl hrec heq
        subst this
        exact hc.mo_irrefl _ hmo
      · -- An earlier incarnation: its store precedes `sY`.
        exact hc.mo_irrefl _
          (hc.mo_trans hmo
            (C.arena_writes_ordered hc hw' hw h hz' hsY hzW hsYW (hzl.trans hsYl.symm) hsome))
      · -- A later incarnation: V2 would have caught it.
        have := A.recycled_caught hc hzW hzl hl hw' hz' hz hYd hd6 hdv hv2 hv2l
        omega

/-- **RB3, the key check, per load.** A load `Y` that store attempt `w` makes while
pinned, of a record word that an attempt `w'` of the same epoch stored at
`sY`, reads `sY` once `sY` happens before it. A later store of the same
incarnation would contradict SP5 (`record_write_once`), one of an earlier
incarnation precedes `sY` in `mo` (QU2(a)), and one of a later incarnation
cannot be read by a pinned writer (QU2(b)). -/
theorem pinned_read (hc : X.Consistent) (Rv : ReserveProtocol X C)
    {sY Y z : Ev} {l : Loc} {i j : C.ι} {w w' : Ev}
    (hw : C.isAttempt i w) (hY : (C.pins i).inAttempt w Y)
    (hw' : C.isAttempt j w') (hsY : (C.pins j).inAttempt w' sY) (hsYW : X.isW sY)
    (hsYl : X.loc sY = some l) (hYl : X.loc Y = some l) (hl : A.isArena l)
    (hrec : Rv.isRecord l) (hE : C.attEpoch w' = C.attEpoch w)
    (h₁ : X.hb sY Y) (hz : X.rf z Y) : z = sY := by
  rcases coh_wr hc hsYW (hsYl.trans hYl.symm) h₁ hz with heq | hmo
  · exact heq
  · exfalso
    have ⟨_, hzW, hlz, _⟩ := hc.mo_wf hmo
    have hzl : X.loc z = some l := hlz.symm.trans hsYl
    rcases A.aw_owner hzW hzl hl with hinit | ⟨k, w'', hw'', hz'⟩
    · exact not_mo_first hc hzW (A.arenaInit_first hinit) hmo
    · rcases A.same_arena hzl hsYl hl hzW hsYW hw'' hz' hw' hsY with heq | h | h
      · -- The same incarnation: the word is stored once (SP5).
        have := Rv.record_write_once hc hw'' hz' hw' hsY hzW hsYW hzl hsYl hrec heq
        subst this
        exact hc.mo_irrefl _ hmo
      · -- An earlier incarnation: its store precedes `sY`.
        exact hc.mo_irrefl _
          (hc.mo_trans hmo
            (C.arena_writes_ordered hc hw'' hw' h hz' hsY hzW hsYW (hzl.trans hsYl.symm)
              (some_ne_none hzl)))
      · -- A later incarnation: a pinned writer never reads it (QU2(b)).
        exact C.no_future_read hc hw hw'' (by omega) hY hz' hz

end ArenaProtocol

namespace ReserveProtocol

variable {X : Execution Ev Loc} {C : CacheProtocol X} (Rv : ReserveProtocol X C)

/-- `RecLoads a off L`: each load in `L` addresses, in `u32` arithmetic, a
word of the record at byte offset `off` of the record area of arena `a`:
the header (`off`: `probe.rs:141`, `put.rs:125`), the lengths word
(`off + 8`: `probe.rs:152-153`, `put.rs:135`), or a word whose position
depends on a load `Ln` of that lengths word in `L`: a key word below the key
length `Ln` returned (`off + 16 + p`: `key_matches()`, `records.rs:92-114`,
called at `probe.rs:163` and `put.rs:146` once that length is the caller's,
`probe.rs:158`, `put.rs:136`), or a value word below its value length
(`off + 16 + align8(key_len) + p`: D5, `probe.rs:169-178`). This is what
`probe()` and `entry_has_key()` load from a record, whatever the values. -/
def RecLoads (a : Loc) (off : Nat) (L : Ev → Prop) : Prop :=
  ∀ Y, L Y → ∃ l, X.loc Y = some l ∧ Rv.isRecord l ∧ Rv.recArena l = a ∧
    (Rv.recOff l = off ∨ Rv.recOff l = (off + 8) % wordLimit ∨
      ∃ Ln ln, L Ln ∧ (∃ u, X.rf u Ln) ∧ X.loc Ln = some ln ∧ Rv.isRecord ln ∧
        Rv.recArena ln = a ∧ Rv.recOff ln = (off + 8) % wordLimit ∧
        ((∃ p, p % 8 = 0 ∧ p < keyLen (X.rval Ln) ∧
            Rv.recOff l = (off + 16 + p) % wordLimit) ∨
          (∃ p, p % 8 = 0 ∧ p < valLen (X.rval Ln) ∧
            Rv.recOff l = (off + 16 + (keyLen (X.rval Ln) + 7) / 8 * 8 + p) % wordLimit)))

/-- `KeyCheck i w l0 a off z P`: what `entry_has_key()` (`put.rs:111-147`),
called by attempt `w` on the entry `z` that its acquire load `l0` returned,
does with record words. Its loads (the header, the lengths word, the key
words, D4 and D5; none when the tag differs, `put.rs:66`) are made by `w`
after `l0` (I0) and address the record at the entry's offset `off` in the
slot's arena `a` (`RecLoads`, `put.rs:119`, `125-146`);
and if each of them reads a store of an attempt `w'` that made `z`, `P`
holds. The last half is sequential: `w'` stored its own key, header and
lengths, and the check compares the caller's key against the values it
loaded. -/
def KeyCheck (i : C.ι) (w l0 : Ev) (a : Loc) (off : Nat) (z : Ev) (P : Prop) : Prop :=
  ∃ K : Ev → Prop, Rv.RecLoads a off K ∧ (∀ Y, K Y → X.sb l0 Y ∧ (C.pins i).inAttempt w Y) ∧
    ∀ j w', C.isAttempt j w' → (C.pins j).inAttempt w' z →
      (∀ Y, K Y → ∀ v, X.rf v Y → (C.pins j).inAttempt w' v) → P

end ReserveProtocol

/-- The stamp of an index entry word, its low 32 bits (`index.rs:51-53`).
The zero fill's is 0 (`index.rs:18-19`). -/
def entryStamp (v : Nat) : Nat := v % wordLimit

/-- The publications (I2) in the index slots, with the lines of `put.rs`,
`probe.rs`, `store.rs` and `lookup.rs` that make them. -/
structure IndexProtocol (X : Execution Ev Loc) {C : CacheProtocol X}
    (A : ArenaProtocol X C) (Rv : ReserveProtocol X C) where
  /-- An I2 that claims a slot: a compare-exchange over a non-current entry. -/
  isPubCas : Ev → Prop
  /-- An I2 that replaces an entry for the same key: a store. -/
  isPubStore : Ev → Prop
  /-- The key a publication is for. -/
  pubKey : Ev → Nat
  /-- I2 is a release (`put.rs:78`, `put.rs:87`). -/
  pub_rel : ∀ {x}, isPubCas x ∨ isPubStore x → X.isRel x
  /-- An index slot of some arena. -/
  isIndex : Loc → Prop
  index_arena : ∀ {l}, isIndex l → A.isArena l
  /-- Record words are arena words. -/
  record_arena : ∀ {l}, Rv.isRecord l → A.isArena l
  /-- The arena an index slot belongs to, named by its control word. -/
  arenaOf : Loc → Loc
  /-- A publication is made in an index slot of its attempt's arena
  (`store.rs:147`, `160-169`: `table_put()` on `arena_of(e)`). -/
  pub_slot : ∀ {x i w l}, (isPubCas x ∨ isPubStore x) → C.isAttempt i w →
    (C.pins i).inAttempt w x → X.loc x = some l →
    isIndex l ∧ arenaOf l = C.R.ctlLoc (C.attEpoch w)
  -- The entry words.
  /-- The record offset an index word encodes, `(ref & offset_mask) * 8`
  (`index.rs:96-98`). -/
  entryOff : Nat → Nat
  entryOff_aligned : ∀ v, entryOff v % 8 = 0
  /-- The zero fill encodes offset 0. -/
  zero_off : entryOff 0 = 0
  /-- Only the zero fill and publications write index slots. -/
  idx_writes : ∀ {x l}, X.isW x → X.loc x = some l → isIndex l →
    (A.isArenaInit x ∧ X.wval x = 0) ∨
      ((isPubCas x ∨ isPubStore x) ∧ ∃ i w, C.isAttempt i w ∧ (C.pins i).inAttempt w x)
  /-- A publication encodes the offset of its attempt's own reservation
  (`store.rs:160-169` passes the offset `reserve()` returned; `put.rs:53`). -/
  pub_off : ∀ {x i w}, (isPubCas x ∨ isPubStore x) → C.isAttempt i w →
    (C.pins i).inAttempt w x → Rv.reserves i w (entryOff (X.wval x))
  /-- A publication stamps its entry with its attempt's epoch, `epoch as
  u32` (`put.rs:48`, `53`; `store()` passes the epoch it pinned,
  `store.rs:160-169`). -/
  pub_stamp : ∀ {x i w}, (isPubCas x ∨ isPubStore x) → C.isAttempt i w →
    (C.pins i).inAttempt w x → entryStamp (X.wval x) = C.attEpoch w % wordLimit
  /-- `store()` writes the whole record before it publishes it
  (`store.rs:148-158`, then `publish()` at `store.rs:160`, whose I2 is
  `put.rs:78` or `put.rs:87`): every word of the reservation, in the
  record area of the attempt's arena (`store.rs:147-148`), the lengths word
  second (`store.rs:152`), then the key and value words
  (`util.rs:119-143`, `151-166`) and the header (`store.rs:158`). -/
  pub_record : ∀ {x i w}, (isPubCas x ∨ isPubStore x) → C.isAttempt i w →
    (C.pins i).inAttempt w x → ∀ o, entryOff (X.wval x) ≤ o →
    o + 8 ≤ entryOff (X.wval x) + Rv.need w → o % 8 = 0 →
    ∃ sY l, (C.pins i).inAttempt w sY ∧ X.isW sY ∧ X.loc sY = some l ∧ Rv.isRecord l ∧
      Rv.recArena l = C.R.ctlLoc (C.attEpoch w) ∧ Rv.recOff l = o ∧ X.sb sY x ∧
      (o = entryOff (X.wval x) + 8 → X.wval sY = Rv.lens w)
  /-- A5: `record_area_size` holds a largest record, so at least 16 bytes,
  and is a multiple of 8 (`config.rs:116`, `130`). -/
  cap_ge : 16 ≤ Rv.cap
  cap_aligned : Rv.cap % 8 = 0
  /-- **A6, lifetime** (environmental; the code does not check it): no index
  entry, the zero fill included, survives in its slot until its 32-bit stamp
  recurs (crate README, "Limits"; `probe.rs:120-123`). As a reader observes
  it: an index load that happens after an epoch load returning `n` reads an
  entry stamped by an epoch above `n - 2^32`, the zero fill counting as
  epoch 0. -/
  entry_life : ∀ {s v Y x l}, X.rf s v → X.loc v = some C.R.ep → X.hb v Y → X.rf x Y →
    X.loc Y = some l → isIndex l →
    (A.isArenaInit x → X.rval v < wordLimit) ∧
      ∀ i w, C.isAttempt i w → (C.pins i).inAttempt w x → X.rval v < C.attEpoch w + wordLimit
  -- Publication.
  /-- The CAS expects the value its acquire load returned, which it found
  not current (`put.rs:60-61`, `65`, `86-88`): what it replaces does not
  carry its stamp. -/
  cas_stamp : ∀ {x i w}, isPubCas x → C.isAttempt i w → (C.pins i).inAttempt w x →
    entryStamp (X.rval x) ≠ C.attEpoch w % wordLimit
  cas_reads : ∀ {x}, isPubCas x → ∃ r, X.rf r x
  /-- The store follows an acquire load I0 of the slot by the same attempt
  (`put.rs:60`, while pinned: `store.rs:160`, `175`) that returned an entry
  with its own stamp (`put.rs:61`, `65`), whose tag matched and for which
  `entry_has_key()` held (`put.rs:66-78`); given that the check's loads read
  the record of the attempt that made the entry, that entry is for the key
  being stored (`KeyCheck`). -/
  store_src : ∀ {x i w l}, isPubStore x → C.isAttempt i w → (C.pins i).inAttempt w x →
    X.loc x = some l →
    ∃ l0 z, X.rf z l0 ∧ X.isAcq l0 ∧ X.sb l0 x ∧ (C.pins i).inAttempt w l0 ∧
      X.loc l0 = some l ∧ entryStamp (X.rval l0) = C.attEpoch w % wordLimit ∧
      Rv.KeyCheck i w l0 (arenaOf l) (entryOff (X.rval l0)) z (pubKey z = pubKey x)
  -- The probe chain (RB4, code fact F9).
  /-- `dist k l`: how far slot `l` lies along key `k`'s probe sequence,
  `(pos l - home k) & bucket_mask` (`put.rs:50`, `put.rs:59`). -/
  dist : Nat → Loc → Nat
  /-- The probe sequence visits each slot of a table once. -/
  dist_inj : ∀ {k l l'}, isIndex l → isIndex l' → arenaOf l = arenaOf l' →
    dist k l = dist k l' → l = l'
  /-- `table_put()` advances past a slot only after an acquire load I0 of it by
  the same attempt returned an entry with its own stamp that is not for its
  key: the tag differs, or `entry_has_key()` failed (`put.rs:58-84`); a
  failed CAS reloads the same slot (`put.rs:86-98`). That the tags of equal
  keys agree is the API contract that equal keys have equal hashes (A8). -/
  pub_skip : ∀ {x i w l l'}, (isPubCas x ∨ isPubStore x) → C.isAttempt i w →
    (C.pins i).inAttempt w x → X.loc x = some l → isIndex l' → arenaOf l' = arenaOf l →
    dist (pubKey x) l' < dist (pubKey x) l →
    ∃ l0 z, X.rf z l0 ∧ X.isAcq l0 ∧ X.sb l0 x ∧ (C.pins i).inAttempt w l0 ∧
      X.loc l0 = some l' ∧ entryStamp (X.rval l0) = C.attEpoch w % wordLimit ∧
      Rv.KeyCheck i w l0 (arenaOf l') (entryOff (X.rval l0)) z (pubKey z ≠ pubKey x)

namespace IndexProtocol

variable {X : Execution Ev Loc} {C : CacheProtocol X} {A : ArenaProtocol X C}
  {Rv : ReserveProtocol X C} (I : IndexProtocol X A Rv)

/-- A CAS replaces a value that no attempt of its own epoch wrote. -/
theorem cas_noncurrent (hc : X.Consistent) {x r : Ev} {i j : C.ι} {w w' : Ev}
    (hx : I.isPubCas x) (hw : C.isAttempt i w) (hxw : (C.pins i).inAttempt w x)
    (hrf : X.rf r x) (hw' : C.isAttempt j w') (hr : (C.pins j).inAttempt w' r) :
    C.attEpoch w' ≠ C.attEpoch w := by
  intro hE
  have ⟨hrW, hxR, hlr, _⟩ := hc.rf_wf hrf
  obtain ⟨l, hxl⟩ : ∃ l, X.loc x = some l := by
    cases h : X.loc x with
    | none => exact absurd (hlr.trans h) (hc.rf_wf hrf).2.2.2
    | some l => exact ⟨l, rfl⟩
  have hli := (I.pub_slot (Or.inl hx) hw hxw hxl).1
  rcases I.idx_writes hrW (hlr.trans hxl) hli with ⟨hinit, -⟩ | ⟨hrk, k, wr, hwr, hr'⟩
  · exact A.arenaInit_not_att hinit hw' hr
  · obtain ⟨rfl, rfl⟩ := Rv.att_unique hwr hw' hr' hr
    have h₁ := I.pub_stamp hrk hwr hr'
    have h₂ := I.cas_stamp hx hw hxw
    rw [hc.rf_val hrf, h₁, hE] at h₂
    exact h₂ rfl

/-- **RB3, the entry.** An index load `l0` (I0) that attempt `w` makes in a slot of
its own arena, and that returns an entry with `w`'s stamp (`put.rs:61`),
read a publication by an attempt of `w`'s epoch. The zero fill, and entries
of earlier incarnations, are excluded by A6 (`entry_life`); entries of later
ones by QU2(b) (`no_future_read`). -/
theorem current_entry (hc : X.Consistent) {l0 z : Ev} {l : Loc} {i : C.ι} {w : Ev}
    (hw : C.isAttempt i w) (hl0 : (C.pins i).inAttempt w l0) (hrf : X.rf z l0)
    (hl0l : X.loc l0 = some l) (hl : I.isIndex l)
    (har : I.arenaOf l = C.R.ctlLoc (C.attEpoch w))
    (hst : entryStamp (X.rval l0) = C.attEpoch w % wordLimit) :
    ∃ j w', C.isAttempt j w' ∧ (C.pins j).inAttempt w' z ∧ C.attEpoch w' = C.attEpoch w ∧
      (I.isPubCas z ∨ I.isPubStore z) := by
  obtain ⟨q1, s, hsq, -, hql, hqw, hqv⟩ := C.att_w1 hw
  have hq : X.hb q1 l0 := hb_sbr (hb_of_sb hqw) hl0.1
  have ⟨hzW, _, hlz, _⟩ := hc.rf_wf hrf
  have hzl : X.loc z = some l := hlz.trans hl0l
  have hlife := I.entry_life hsq hql hq hrf hl0l hl
  have he := C.att_ge hc hw
  rw [hc.rf_val hrf] at hst
  rcases I.idx_writes hzW hzl hl with ⟨hinit, hz0⟩ | ⟨hzk, j, w', hw', hz⟩
  · exfalso
    have := hlife.1 hinit
    rw [hz0] at hst
    unfold entryStamp wordLimit at *
    omega
  · have h₁ := hlife.2 j w' hw' hz
    rw [I.pub_stamp hzk hw' hz] at hst
    rcases C.ctl_eq ((I.pub_slot hzk hw' hz hzl).2.symm.trans har) with heq | h | h
    · exact ⟨j, w', hw', hz, heq, hzk⟩
    · -- An earlier incarnation with the same stamp: 2^32 epochs old.
      exfalso
      unfold wordLimit at *
      omega
    · exact absurd hrf (C.no_future_read hc hw hw' h hl0 hz)

/-- **The addressing of HT2 and RB3.** A reader follows the
publication `x` of attempt `w`: its loads `L` address `w`'s record, at the
offset `x` encodes, in `w`'s arena (`RecLoads`). If each load that
addresses a word `w` stored before `x` reads that store (`core`), then every
load in `L` reads a store of `w`. The lengths load comes first: it
addresses `w`'s lengths word, so it returns `w`'s lengths, and the key and
value words it bounds lie in `w`'s reservation, which `w` filled before
`x` (`pub_record`). -/
theorem record_loads_read (hc : X.Consistent) {x : Ev} {i : C.ι} {w : Ev} {L : Ev → Prop}
    (hxk : I.isPubCas x ∨ I.isPubStore x) (hw : C.isAttempt i w)
    (hx : (C.pins i).inAttempt w x)
    (hL : Rv.RecLoads (C.R.ctlLoc (C.attEpoch w)) (I.entryOff (X.wval x)) L)
    (core : ∀ Y sY l, L Y → (C.pins i).inAttempt w sY → X.isW sY → X.loc sY = some l →
      X.loc Y = some l → Rv.isRecord l → X.sb sY x → ∀ v, X.rf v Y → v = sY) :
    ∀ Y, L Y → ∀ v, X.rf v Y → (C.pins i).inAttempt w v := by
  have harea := Rv.reserve_in_area hc (I.pub_off hxk hw hx)
  have hcap : Rv.cap < wordLimit := by
    have := Rv.a5; unfold bumpLimit at this; unfold wordLimit; omega
  have hneed := Rv.need_lens hw
  have hal := I.entryOff_aligned (X.wval x)
  -- A load of a word of the reservation reads `w`'s store to it.
  have word : ∀ Y l, L Y → X.loc Y = some l → Rv.isRecord l →
      Rv.recArena l = C.R.ctlLoc (C.attEpoch w) → I.entryOff (X.wval x) ≤ Rv.recOff l →
      Rv.recOff l + 8 ≤ I.entryOff (X.wval x) + Rv.need w → Rv.recOff l % 8 = 0 →
      ∃ sY, (C.pins i).inAttempt w sY ∧ (∀ v, X.rf v Y → v = sY) ∧
        (Rv.recOff l = I.entryOff (X.wval x) + 8 → X.wval sY = Rv.lens w) := by
    intro Y l hY hYl hrec har h₁ h₂ h₃
    obtain ⟨sY, l', hsY, hsYW, hsYl, hrec', har', hoff', hsb, hlen⟩ :=
      I.pub_record hxk hw hx _ h₁ h₂ h₃
    have : l' = l := Rv.rec_ext hrec' hrec (har'.trans har.symm) hoff'
    subst this
    exact ⟨sY, hsY, core Y sY l' hY hsY hsYW hsYl hYl hrec hsb, fun h => hlen (hoff'.symm.trans h)⟩
  intro Y hY v hv
  obtain ⟨l, hYl, hrec, har, hpos⟩ := hL Y hY
  have done : ∀ sY, (C.pins i).inAttempt w sY → (∀ v, X.rf v Y → v = sY) →
      (C.pins i).inAttempt w v := fun sY hsY hread => by rw [hread v hv]; exact hsY
  rcases hpos with h | h | ⟨Ln, ln, hLn, ⟨u, hu⟩, hlnl, hrecn, harn, hoffn, hkv⟩
  · obtain ⟨sY, hsY, hread, -⟩ := word Y l hY hYl hrec har (by omega) (by omega) (by omega)
    exact done sY hsY hread
  · unfold wordLimit at h hcap
    obtain ⟨sY, hsY, hread, -⟩ := word Y l hY hYl hrec har (by omega) (by omega) (by omega)
    exact done sY hsY hread
  · -- The lengths load returned `w`'s lengths.
    have hoffn' : Rv.recOff ln = I.entryOff (X.wval x) + 8 := by
      unfold wordLimit at hoffn hcap; omega
    obtain ⟨sL, -, hreadL, hlenL⟩ :=
      word Ln ln hLn hlnl hrecn harn (by omega) (by omega) (by omega)
    have hrv : X.rval Ln = Rv.lens w := by rw [hc.rf_val hu, hreadL u hu]; exact hlenL hoffn'
    rw [hrv] at hkv
    generalize keyLen (Rv.lens w) = kl at hkv hneed
    generalize valLen (Rv.lens w) = vl at hkv hneed
    unfold wordLimit at hkv hcap
    rcases hkv with ⟨p, hp8, hp, h⟩ | ⟨p, hp8, hp, h⟩
    · obtain ⟨sY, hsY, hread, -⟩ := word Y l hY hYl hrec har (by omega) (by omega) (by omega)
      exact done sY hsY hread
    · obtain ⟨sY, hsY, hread, -⟩ := word Y l hY hYl hrec har (by omega) (by omega) (by omega)
      exact done sY hsY hread

/-- **RB3, the key check.** `entry_has_key()` is exact: a writer that acquired a
release-published entry of its own epoch concludes what the entry's own
record says. Its record loads address that record (`record_loads_read`)
and follow its stores in `hb` (REL-ACQ), so they read them
(`pinned_read`). -/
theorem keyCheck_holds (hc : X.Consistent) {i j : C.ι} {w w' l0 z : Ev} {l : Loc} {P : Prop}
    (hw : C.isAttempt i w) (hw' : C.isAttempt j w') (hz : (C.pins j).inAttempt w' z)
    (hzk : I.isPubCas z ∨ I.isPubStore z) (hE : C.attEpoch w' = C.attEpoch w)
    (hrf : X.rf z l0) (hacq : X.isAcq l0) (hl0l : X.loc l0 = some l)
    (hK : Rv.KeyCheck i w l0 (I.arenaOf l) (I.entryOff (X.rval l0)) z P) : P := by
  obtain ⟨K, hrec, hload, hP⟩ := hK
  refine hP j w' hw' hz ?_
  have ⟨_, _, hlz, _⟩ := hc.rf_wf hrf
  rw [(I.pub_slot hzk hw' hz (hlz.trans hl0l)).2, hc.rf_val hrf] at hrec
  refine I.record_loads_read hc hzk hw' hz hrec
    fun Y sY l' hY hsY hsYW hsYl hYl hrec' hsb v hv => ?_
  have h₁ : X.hb sY Y :=
    hb_trans (hb_of_sb hsb)
      (hb_trans (hb_of_sw (sw.relAcq (I.pub_rel hzk) (rs.refl z) hrf hacq))
        (hb_of_sb (hload Y hY).1))
  exact A.pinned_read hc Rv hw (hload Y hY).2 hw' hsY hsYW hsYl hYl (I.record_arena hrec') hrec'
    hE h₁ hv

/-- **RB4, one key per slot.** In one index slot, a publication by an attempt carries the
same key as every earlier publication by an attempt of the same epoch. -/
theorem key_of_earlier (hc : X.Consistent) {l : Loc} (hl : A.isArena l) (x : Ev) :
    (I.isPubCas x ∨ I.isPubStore x) → X.isW x → X.loc x = some l →
    ∀ {i : C.ι} {w : Ev}, C.isAttempt i w → (C.pins i).inAttempt w x →
    ∀ y, (I.isPubCas y ∨ I.isPubStore y) → X.mo y x →
    ∀ {j : C.ι} {w' : Ev}, C.isAttempt j w' → (C.pins j).inAttempt w' y →
    C.attEpoch w' = C.attEpoch w → I.pubKey y = I.pubKey x := by
  refine hc.mo_wellFounded.induction
    (C := fun x => (I.isPubCas x ∨ I.isPubStore x) → X.isW x → X.loc x = some l →
      ∀ {i : C.ι} {w : Ev}, C.isAttempt i w → (C.pins i).inAttempt w x →
      ∀ y, (I.isPubCas y ∨ I.isPubStore y) → X.mo y x →
      ∀ {j : C.ι} {w' : Ev}, C.isAttempt j w' → (C.pins j).inAttempt w' y →
      C.attEpoch w' = C.attEpoch w → I.pubKey y = I.pubKey x) x ?_
  intro x ih hxk hxW hxl i w hw hx y hyk hyx j w' hw' hy hE
  have ⟨hyW, _, hly, hsy⟩ := hc.mo_wf hyx
  have hyl : X.loc y = some l := hly.trans hxl
  rcases hxk with hcas | hstore
  · -- A CAS over a non-current entry: no earlier publication of this epoch exists.
    exfalso
    obtain ⟨r, hrx⟩ := I.cas_reads hcas
    have ⟨hrW, _, hlr, hsr⟩ := hc.rf_wf hrx
    have hrl : X.loc r = some l := hlr.trans hxl
    have hyr : y = r ∨ X.mo y r := rmw_pred_ge hc hrx hyW (hyl.trans hrl.symm) hyx
    rcases A.aw_owner hrW hrl hl with hinit | ⟨k, wr, hwr, hr⟩
    · rcases hyr with heq | hm
      · exact A.arenaInit_not_att (heq ▸ hinit) hw' hy
      · exact not_mo_first hc hrW (A.arenaInit_first hinit) hm
    · rcases A.same_arena hrl hxl hl hrW hxW hwr hr hw hx with heq | h | h
      · exact I.cas_noncurrent hc hcas hw hx hrx hwr hr heq
      · -- `r` is older than this epoch, yet at or after `y`.
        rcases hyr with heq | hm
        · have := A.owner_epoch hw' hy hwr (heq ▸ hr)
          omega
        · exact hc.mo_irrefl _
            (hc.mo_trans hm
              (C.arena_writes_ordered hc hwr hw' (by omega) hr hy hrW hyW
                (hrl.trans hyl.symm) hsr))
      · -- `r` is from a later incarnation, yet before `x`.
        exact hc.mo_irrefl _
          (hc.mo_trans (hc.rmw_mo hrx hxW)
            (C.arena_writes_ordered hc hw hwr h hx hr hxW hrW (hxl.trans hrl.symm)
              (some_ne_none hxl)))
  · -- A store over an entry `z` it loaded, with the same key (RB3).
    obtain ⟨l0, z, hzl0, hacq, hl0x, hl0w, hl0l, hst, hchk⟩ := I.store_src hstore hw hx hxl
    have hli := I.pub_slot (Or.inr hstore) hw hx hxl
    obtain ⟨k, wz, hwz, hz, hEz, hzk⟩ := I.current_entry hc hw hl0w hzl0 hl0l hli.1 hli.2 hst
    have hkey := I.keyCheck_holds hc hw hwz hz hzk hEz hzl0 hacq hl0l hchk
    have ⟨hzW, _, hlz, hsz⟩ := hc.rf_wf hzl0
    have hzl : X.loc z = some l := hlz.trans hl0l
    have hzx : X.mo z x := coh_rw hc hxW (hxl.trans hl0l.symm) (hb_of_sb hl0x) hzl0
    rcases hc.mo_total hyW hzW (hyl.trans hzl.symm) hsy with heq | h | h
    · rw [heq]; exact hkey
    · exact (ih z hzx hzk hzW hzl hwz hz y hyk h hw' hy (hE.trans hEz.symm)).trans hkey
    · exact ((ih y hyx hyk hyW hyl hw' hy z hzk h hwz hz (hEz.trans hE.symm)).symm).trans hkey

/-- **RB4, one key per slot.** Two publications in one index slot by attempts of one epoch
carry the same key. -/
theorem slot_key_fixed (hc : X.Consistent) {l : Loc} (hl : A.isArena l) {x y : Ev}
    {i j : C.ι} {w w' : Ev}
    (hxk : I.isPubCas x ∨ I.isPubStore x) (hxW : X.isW x) (hxl : X.loc x = some l)
    (hw : C.isAttempt i w) (hx : (C.pins i).inAttempt w x)
    (hyk : I.isPubCas y ∨ I.isPubStore y) (hyW : X.isW y) (hyl : X.loc y = some l)
    (hw' : C.isAttempt j w') (hy : (C.pins j).inAttempt w' y)
    (hE : C.attEpoch w' = C.attEpoch w) : I.pubKey y = I.pubKey x := by
  rcases hc.mo_total hyW hxW (hyl.trans hxl.symm) (some_ne_none hyl)
    with heq | h | h
  · rw [heq]
  · exact I.key_of_earlier hc hl x hxk hxW hxl hw hx y hyk h hw' hy hE
  · exact (I.key_of_earlier hc hl y hyk hyW hyl hw' hy x hxk h hw hx hE.symm).symm

/-- A writer that passed slot `l'` on its way to `l` left in `l'` an entry
of its epoch for another key. -/
theorem skipped (hc : X.Consistent) {x : Ev} {l l' : Loc} {i : C.ι} {w : Ev}
    (hxk : I.isPubCas x ∨ I.isPubStore x) (hw : C.isAttempt i w)
    (hx : (C.pins i).inAttempt w x) (hxl : X.loc x = some l) (hl' : I.isIndex l')
    (har : I.arenaOf l' = I.arenaOf l) (hd : I.dist (I.pubKey x) l' < I.dist (I.pubKey x) l) :
    ∃ z j w', (I.isPubCas z ∨ I.isPubStore z) ∧ X.isW z ∧ X.loc z = some l' ∧
      C.isAttempt j w' ∧ (C.pins j).inAttempt w' z ∧ C.attEpoch w' = C.attEpoch w ∧
      X.hb z x ∧ I.pubKey z ≠ I.pubKey x := by
  obtain ⟨l0, z, hrf, hacq, hsb, hl0w, hl0l, hst, hchk⟩ := I.pub_skip hxk hw hx hxl hl' har hd
  obtain ⟨j, w', hw', hz, hE, hzk⟩ :=
    I.current_entry hc hw hl0w hrf hl0l hl' (har.trans (I.pub_slot hxk hw hx hxl).2) hst
  have ⟨hzW, _, hlz, _⟩ := hc.rf_wf hrf
  exact ⟨z, j, w', hzk, hzW, hlz.trans hl0l, hw', hz, hE,
    hb_trans (hb_of_sw (sw.relAcq (I.pub_rel hzk) (rs.refl z) hrf hacq)) (hb_of_sb hsb),
    I.keyCheck_holds hc hw hw' hz hzk hE hrf hacq hl0l hchk⟩

/-- Of two publications of one epoch for one key, the second is not further
along the probe sequence than the first. -/
theorem not_further (hc : X.Consistent) {x x' : Ev} {l l' : Loc} {i j : C.ι} {w w' : Ev}
    (hxk : I.isPubCas x ∨ I.isPubStore x) (hxW : X.isW x) (hxl : X.loc x = some l)
    (hw : C.isAttempt i w) (hx : (C.pins i).inAttempt w x)
    (hx'k : I.isPubCas x' ∨ I.isPubStore x') (hx'l : X.loc x' = some l')
    (hw' : C.isAttempt j w') (hx' : (C.pins j).inAttempt w' x')
    (hE : C.attEpoch w' = C.attEpoch w) (hkey : I.pubKey x' = I.pubKey x)
    (har : I.arenaOf l = I.arenaOf l') : ¬ I.dist (I.pubKey x) l < I.dist (I.pubKey x) l' := by
  intro hd
  have hli := (I.pub_slot hxk hw hx hxl).1
  obtain ⟨z, k, wz, hzk, hzW, hzl, hwz, hz, hEz, -, hne⟩ :=
    I.skipped hc hx'k hw' hx' hx'l hli har (by rw [hkey]; exact hd)
  -- `l` holds `x`, for the key, and `z`, of the same epoch for another.
  have := I.slot_key_fixed hc (I.index_arena hli) hxk hxW hxl hw hx hzk hzW hzl hwz hz
    (hEz.trans hE)
  exact hne (this.trans hkey.symm)

/-- **RB4, one slot per key.** Two publications by attempts of one epoch for one
key are in the same index slot. -/
theorem key_one_slot (hc : X.Consistent) {x x' : Ev} {l l' : Loc} {i j : C.ι} {w w' : Ev}
    (hxk : I.isPubCas x ∨ I.isPubStore x) (hxW : X.isW x) (hxl : X.loc x = some l)
    (hw : C.isAttempt i w) (hx : (C.pins i).inAttempt w x)
    (hx'k : I.isPubCas x' ∨ I.isPubStore x') (hx'W : X.isW x') (hx'l : X.loc x' = some l')
    (hw' : C.isAttempt j w') (hx' : (C.pins j).inAttempt w' x')
    (hE : C.attEpoch w' = C.attEpoch w) (hkey : I.pubKey x' = I.pubKey x) : l = l' := by
  have ⟨hli, hla⟩ := I.pub_slot hxk hw hx hxl
  have ⟨hli', hla'⟩ := I.pub_slot hx'k hw' hx' hx'l
  have har : I.arenaOf l = I.arenaOf l' := by rw [hla, hla', hE]
  rcases Nat.lt_trichotomy (I.dist (I.pubKey x) l) (I.dist (I.pubKey x) l') with h | h | h
  · exact absurd h (I.not_further hc hxk hxW hxl hw hx hx'k hx'l hw' hx' hE hkey har)
  · exact I.dist_inj hli hli' har h
  · rw [← hkey] at h
    exact absurd h
      (I.not_further hc hx'k hx'W hx'l hw' hx' hxk hxl hw hx hE.symm hkey.symm har.symm)

/-- **RB4, no gap.** Every slot of the arena that lies before a
publication's slot along its key's probe sequence holds an entry of the
same epoch, published before it, for another key; by RB4's one key per slot every later
entry of that epoch there is for that other key too. -/
theorem chain_no_gap (hc : X.Consistent) {x : Ev} {l l' : Loc} {i : C.ι} {w : Ev}
    (hxk : I.isPubCas x ∨ I.isPubStore x) (hw : C.isAttempt i w)
    (hx : (C.pins i).inAttempt w x) (hxl : X.loc x = some l) (hl' : I.isIndex l')
    (har : I.arenaOf l' = I.arenaOf l) (hd : I.dist (I.pubKey x) l' < I.dist (I.pubKey x) l) :
    ∃ z j w', (I.isPubCas z ∨ I.isPubStore z) ∧ X.loc z = some l' ∧
      C.isAttempt j w' ∧ (C.pins j).inAttempt w' z ∧ C.attEpoch w' = C.attEpoch w ∧
      X.hb z x ∧ I.pubKey z ≠ I.pubKey x ∧
      ∀ {y k w''}, (I.isPubCas y ∨ I.isPubStore y) → X.isW y → X.loc y = some l' →
        C.isAttempt k w'' → (C.pins k).inAttempt w'' y → C.attEpoch w'' = C.attEpoch w →
        I.pubKey y = I.pubKey z := by
  obtain ⟨z, j, w', hzk, hzW, hzl, hw', hz, hE, hzx, hne⟩ :=
    I.skipped hc hxk hw hx hxl hl' har hd
  refine ⟨z, j, w', hzk, hzl, hw', hz, hE, hzx, hne, fun hyk hyW hyl hw'' hy hE'' => ?_⟩
  exact I.slot_key_fixed hc (I.index_arena hl') hzk hzW hzl hw' hz hyk hyW hyl hw'' hy
    (hE''.trans hE.symm)

end IndexProtocol

/-- A lookup probe that returned a hit: the events of `lookup()` and
`probe()` that HT2 rests on, each with the code that makes it (`lookup.rs`
and `probe.rs`). -/
structure Hit {X : Execution Ev Loc} {C : CacheProtocol X} {A : ArenaProtocol X C}
    {Rv : ReserveProtocol X C} (I : IndexProtocol X A Rv) where
  /-- V1, the lookup's epoch load (`lookup.rs:66`), and the write it read. -/
  v1 : Ev
  s1 : Ev
  v1_rf : X.rf s1 v1
  v1_loc : X.loc v1 = some C.R.ep
  /-- The epoch probed: V1's, in its arena, or one less, in the previous
  arena (`lookup.rs:69-99`). -/
  e : Nat
  e_def : X.rval v1 = e ∨ X.rval v1 = e + 1
  /-- I1, the index load whose entry the probe validated (`probe.rs:81`,
  `199` or `248`), the slot it loaded and the write it read. -/
  i1 : Ev
  slot : Loc
  x2 : Ev
  i1_loc : X.loc i1 = some slot
  slot_index : I.isIndex slot
  i1_rf : X.rf x2 i1
  /-- `probe()` is inlined into `lookup()` after V1 (`lookup.rs:66-99`). -/
  v1_i1 : X.sb v1 i1
  /-- The entry has the probe's stamp, `epoch as u32` (`probe.rs:60`): a
  candidate matches `wanted` in the stamp bits (`probe.rs:77`, `83`, `250`),
  and the sequential scan checks the stamp (`probe.rs:200`). -/
  i1_stamp : entryStamp (X.rval i1) = e % wordLimit
  /-- I1F (`probe.rs:133`) and D6 (`probe.rs:181`), acquire fences, and V2,
  the epoch reload after D6 (`probe.rs:182`). -/
  i1f : Ev
  d6 : Ev
  v2 : Ev
  s2 : Ev
  i1_i1f : X.sb i1 i1f
  i1f_acq : X.isAcqFence i1f
  i1_d6 : X.sb i1 d6
  d6_acq : X.isAcqFence d6
  d6_v2 : X.sb d6 v2
  v2_rf : X.rf s2 v2
  v2_loc : X.loc v2 = some C.R.ep
  /-- V2 passed: `age_of(v2, stamp) <= 1`, in wrapping `u32` arithmetic
  (`probe.rs:182-186`, `util.rs:42-44`). -/
  v2_pass : (X.rval v2 % wordLimit + wordLimit - e % wordLimit) % wordLimit ≤ 1
  /-- The record loads between I1F and D6: the header D4, and the payload
  loads D5, the lengths, the key words and the value words
  (`probe.rs:141-178`), at the offset the
  entry encodes (`probe.rs:134`), in the slot's arena (one `ArenaView`
  serves both, `probe.rs:81`, `141`). -/
  loads : Ev → Prop
  loads_rec : Rv.RecLoads (I.arenaOf slot) (I.entryOff (X.rval i1)) loads
  loads_sb : ∀ Y, loads Y → X.sb i1f Y ∧ X.sb Y d6
  /-- **A6, lifetime, for a lookup** (environmental): a lookup in progress
  does not survive until its stamp recurs (crate README, "Limits"): V2 reads
  an epoch below `e + 2^32`. -/
  life : X.rval v2 < e + wordLimit

namespace IndexProtocol

variable {X : Execution Ev Loc} {C : CacheProtocol X} {A : ArenaProtocol X C}
  {Rv : ReserveProtocol X C} (I : IndexProtocol X A Rv)

/-- **HT2, hit integrity.** A hit followed the publication of an attempt
`w` of the epoch it probed, and every record load of the hit read a store
of `w`.

Step 1: the entry is a publication (the zero fill's stamp 0 recurs only at
epoch `2^32`); its epoch has the probe's stamp, is not `2^32` or more
older (A6, `entry_life`), and is at most V2's (HT1, `recycled_caught`),
which is less than `2^32` past the probe's (A6, `life`); so it is the
probe's, and
V2's `u32` check bounds V2 by that epoch plus one. Step 4: the loads address
`w`'s record (`record_loads_read`, F11). Steps 2 and 3: each reads `w`'s
store (`hit_load`: I2 release and I1F acquire, then SP5, QU2(a) and HT1).
Among index facts it rests on IX1 only. -/
theorem hit_integrity (hc : X.Consistent) (H : Hit I) :
    ∃ i w, C.isAttempt i w ∧ (C.pins i).inAttempt w H.x2 ∧
      (I.isPubCas H.x2 ∨ I.isPubStore H.x2) ∧ C.attEpoch w = H.e ∧
      ∀ Y, H.loads Y → ∀ z, X.rf z Y → (C.pins i).inAttempt w z := by
  have ⟨hx2W, _, hlx2, _⟩ := hc.rf_wf H.i1_rf
  have hx2l : X.loc H.x2 = some H.slot := hlx2.trans H.i1_loc
  have hlife := I.entry_life H.v1_rf H.v1_loc (hb_of_sb H.v1_i1) H.i1_rf H.i1_loc H.slot_index
  have ⟨hs1W, _, hls1, _⟩ := hc.rf_wf H.v1_rf
  have hv1 : 2 ≤ X.rval H.v1 := by
    rw [hc.rf_val H.v1_rf]; exact C.R.ep_val_ge hc hs1W (hls1.trans H.v1_loc)
  have hed := H.e_def
  have hst := H.i1_stamp
  rw [hc.rf_val H.i1_rf] at hst
  rcases I.idx_writes hx2W hx2l H.slot_index with ⟨hinit, hz⟩ | ⟨hxk, i, w, hw, hx⟩
  · exfalso
    have := hlife.1 hinit
    rw [hz] at hst
    unfold entryStamp wordLimit at *
    omega
  · have h₁ := hlife.2 i w hw hx
    rw [I.pub_stamp hxk hw hx] at hst
    have h₂ : C.attEpoch w ≤ X.rval H.v2 :=
      A.recycled_caught hc hx2W hx2l (I.index_arena H.slot_index) hw hx H.i1_rf H.i1_d6
        H.d6_acq H.d6_v2 H.v2_rf H.v2_loc
    have h₃ := H.life
    have hE : C.attEpoch w = H.e := by unfold wordLimit at *; omega
    have hpass : X.rval H.v2 ≤ C.attEpoch w + 1 := by
      have := H.v2_pass; unfold wordLimit at *; omega
    refine ⟨i, w, hw, hx, hxk, hE, ?_⟩
    have hrec := H.loads_rec
    rw [(I.pub_slot hxk hw hx hx2l).2, hc.rf_val H.i1_rf] at hrec
    refine I.record_loads_read hc hxk hw hx hrec fun Y sY l hY hsY hsYW hsYl hYl hr hsb v hv => ?_
    have ⟨hfY, hYd⟩ := H.loads_sb Y hY
    exact A.hit_load hc Rv hw (I.pub_rel hxk) H.i1_rf H.i1_i1f H.i1f_acq hfY hYd H.d6_acq
      H.d6_v2 H.v2_rf H.v2_loc hpass hsY hsYW hsYl hYl (I.record_arena hr) hr hsb hv

end IndexProtocol
end Execution
end Sgc
