import SgcProofs.Provenance

/-!
# RD1, RD2, FR1 and FR2: promotion readiness and promotion freshness

A lookup promotes a hit of the previous generation `n` only once `n` is
*certified*: every store attempt for `n` has finished. Each arena has a
readiness word, `promotion_ready_epoch` (`arena/mod.rs`, `ArenaCtlLine`).
It starts at 0 (`cache/mod.rs`, `initialize_storage()`, line 246), is
never reset (R4 does not write it), and is written otherwise only by
*certificates*: release `fetch_max` RMWs whose argument is the full 64-bit
epoch they certify (`promotion.rs`, `mark_promotion_ready()`, lines 74-78).
There are two kinds (`ReadinessProtocol.cert_src`):

* R3C, by a rotation: after sealing `n` it makes R1F (a `SeqCst` fence),
  then scans every slot's pin, and certifies `n` if no load returned a pin
  of `n` (`rotation.rs`, `rotate_owned()`, lines 131-186; `RotCert`);
* C4, by a lookup's readiness recheck: C1, an acquire load of the epoch,
  read `n + 1`; then C2, a `SeqCst` fence; then C3, an acquire load of
  every slot's pin, none pinned to `n` (`promotion.rs`,
  `recheck_promotion_ready()`, lines 44-68; `RecheckCert`).

A promotion copies either a hit whose probe acquired a certificate for `n`
at C0, before its first index load (`probe.rs`, `probe()`, line 74), or
the hit of a reprobe sequenced after a recheck's C4 (`lookup.rs`,
`lookup()` and `reprobe_for_promotion()`, lines 121-148 and 182-209;
`PromoHit`).

Proved here for every consistent execution:

* `cert_read` (RD2): an acquire load of a readiness word that returns
  `n ≠ 0` synchronises with a certificate for `n`. The mo-first write of
  value `n` is a certificate with argument `n`, and every later write up to
  the one read is an RMW, so it lies in that certificate's release
  sequence. A delayed certificate with a smaller argument neither lowers
  the value nor breaks the sequence; a certificate for an epoch that shares
  the arena (`n - 3k`) or the low 32 bits of `n` is never mistaken for `n`,
  since the value is the full epoch and never reset;
* `cert_sound` (RD1): every action of every store attempt for `n` happens
  before every certificate for `n`. The certifier's fence follows the seal
  of `n` (EP3(ii) for R1F, EP3(iv) for C2), so by PN3 its acquire load of
  the attempt's slot reads W2 or later; a pinned epoch other than `n`
  means a later non-announce write, which the attempt's actions precede
  (PN2) and which the load acquires (PN1). This is QU1's
  `quiescence_core` with "pinned epoch `≠ n`" in place of "passes";
* `promo_source_after` (FR1): every action of every store attempt for `n`
  happens before the index load I1 of the promoted hit, on both paths;
* `promo_fresh` (FR2): the promoted entry is a publication of the caller's
  key by an attempt for `n`, the promotion stores that attempt's value, and
  every publication of the key in incarnation `n` lies in the same slot and
  is that entry or `mo`-before it: the promoted record is that of the
  `mo`-last publication of the key in `n`;
* `promo_first` (F9 with RB4(d)): a promotion never replaces an entry of
  its own incarnation.

Nothing here assumes timing, fairness, or that readiness is ever reached:
`PromotionSampler::should_recheck` is outside the model, and every theorem
holds whatever it returns. No new axiom is used: only REL-ACQ, release
sequences of RMWs, `SeqCst` fences (SC-4.4) and the coherence rules.

`Recency.lean` lifts FR2 to operations: FR3 and FR4, read-after-insert.
-/

namespace Sgc
namespace Execution

variable {Ev Loc : Type}

/-- The readiness words: who writes them and how. -/
structure CertWord (X : Execution Ev Loc) where
  /-- The readiness word `arena_of(n).promotion_ready_epoch` of the arena
  that serves epoch `n`. -/
  rdyLoc : Nat → Loc
  /-- The initialisation of a readiness word. -/
  isRdyInit : Ev → Prop
  /-- A certificate: R3C or C4. -/
  isCert : Ev → Prop
  /-- The epoch a certificate certifies, its `fetch_max` argument. -/
  certArg : Ev → Nat
  /-- `initialize_storage()` stores 0 (`cache/mod.rs:246`). Nothing asks the initialisation to be `mo`-first: its value suffices. -/
  rdyInit_val : ∀ {i}, isRdyInit i → X.wval i = 0
  /-- Code fact F13: only the initialisation and certificates write a
  readiness word; R4 does not (`rotation.rs`, `rotate_owned()`, lines
  190-195). -/
  rdy_writes : ∀ {e n}, X.isW e → X.loc e = some (rdyLoc n) → isRdyInit e ∨ isCert e
  cert_w : ∀ {c}, isCert c → X.isW c
  /-- `mark_promotion_ready()` writes the word of `arena_of(epoch)`
  (`promotion.rs:75`). No proof needs it: `cert_read` follows values,
  not locations. -/
  cert_loc : ∀ {c}, isCert c → X.loc c = some (rdyLoc (certArg c))
  /-- `fetch_max(epoch, Release)` (`promotion.rs:77`). -/
  cert_rel : ∀ {c}, isCert c → X.isRel c
  /-- `fetch_max` is an RMW that stores the larger of the value it read and
  its argument, in full 64 bits (`promotion.rs:77`). A plain store in its
  place would fail both this value step and the release sequence of
  `cert_read`. -/
  cert_rmw : ∀ {c}, isCert c → ∃ y, X.rf y c ∧ X.wval c = max (X.wval y) (certArg c)

namespace CertWord

variable {X : Execution Ev Loc} (W : CertWord X)

/-- **RD2, certificate visibility.** An acquire load `r` of the readiness
word of epoch `n`'s arena that returns `n ≠ 0` synchronises with a
certificate for `n`. -/
theorem cert_read (hc : X.Consistent) {r z : Ev} {n : Nat}
    (hrf : X.rf z r) (hacq : X.isAcq r) (hl : X.loc r = some (W.rdyLoc n))
    (hv : X.rval r = n) (hn : n ≠ 0) :
    ∃ c, W.isCert c ∧ W.certArg c = n ∧ X.sw c r := by
  -- Every write of value `n` to the word lies in the release sequence of a
  -- certificate for `n`.
  have key : ∀ z, X.isW z → X.loc z = some (W.rdyLoc n) → X.wval z = n →
      ∃ c, W.isCert c ∧ W.certArg c = n ∧ X.rs c z := by
    intro z
    refine hc.mo_wellFounded.induction
      (C := fun z => X.isW z → X.loc z = some (W.rdyLoc n) → X.wval z = n →
        ∃ c, W.isCert c ∧ W.certArg c = n ∧ X.rs c z) z ?_
    intro z ih hzW hzl hzv
    rcases W.rdy_writes hzW hzl with hinit | hcert
    · exact absurd ((W.rdyInit_val hinit).symm.trans hzv) (Ne.symm hn)
    · by_cases harg : W.certArg z = n
      · exact ⟨z, hcert, harg, rs.refl z⟩
      · -- A smaller argument: the value came from the write it read.
        obtain ⟨y, hyz, hmax⟩ := W.cert_rmw hcert
        have ⟨hyW, _, hly, _⟩ := hc.rf_wf hyz
        have hyv : X.wval y = n := by
          rw [hzv, Nat.max_def] at hmax
          split at hmax <;> omega
        obtain ⟨c, hc', harg', hrs⟩ := ih y (hc.rmw_mo hyz hzW) hyW (hly.trans hzl) hyv
        exact ⟨c, hc', harg', rs.rmw hrs hyz hzW⟩
  have ⟨hzW, _, hlz, _⟩ := hc.rf_wf hrf
  obtain ⟨c, hcert, harg, hrs⟩ :=
    key z hzW (hlz.trans hl) (by rw [← hc.rf_val hrf]; exact hv)
  exact ⟨c, hcert, harg, sw.relAcq (W.cert_rel hcert) hrs hrf hacq⟩

end CertWord

/-- The scan of a certifier: after its fence `f` and before the certificate
`c`, one acquire load of every slot's pin that returned a pinned epoch other
than `n`. The `∀ i` is assumption A7: the scan covers every slot. -/
def CertScan {X : Execution Ev Loc} (C : CacheProtocol X) (f c : Ev) (n : Nat) : Prop :=
  ∀ i, ∃ Sc p, X.rf p Sc ∧ X.loc Sc = some (C.pins i).pin ∧ X.isAcq Sc ∧
    X.sb f Sc ∧ X.sb Sc c ∧ (C.pins i).pinVal (X.rval Sc) ≠ n

/-- R3C, a rotation's certificate `c` for `n` (`rotation.rs`,
`rotate_owned()`). Tenure `A` rechecked `n` at R0
(90). It left the seal loop (97-124) through its own R1 (115-121) or through
a load under ownership (the initial load, 95, or a failed CAS, 115-122) that
returned `(stamp n, sealed)` (98, 101); its own seal, if any, precedes R1F
`f` (131), which it makes under ownership. After `f` it loaded every slot's
pin with acquire (142), testing each load for a pin of `n` (143-148), and
it certifies only if none had one (184-185), before R4 and R5. The field
asks for one such load per slot, which is weaker than the code's "every
load". -/
def RotCert {X : Execution Ev Loc} (C : CacheProtocol X) (c : Ev) (n : Nat) : Prop :=
  ∃ A q f, C.R.r0Of A q ∧ X.rval q = n ∧ X.isScFence f ∧ C.R.toOwnerProtocol.under A f ∧
    X.sb f c ∧ (∀ {Z}, C.R.sealOf A Z → X.sb Z f) ∧
    ((∃ Z, C.R.sealOf A Z) ∨
      ∃ l u, C.R.toOwnerProtocol.under A l ∧ X.rf u l ∧ X.loc l = some (C.R.ctlLoc n) ∧
        C.R.stamp (X.wval u) = n ∧ C.R.isSealed (X.wval u)) ∧
    CertScan C f c n

/-- C4, a readiness recheck's certificate `c` for `n` (`promotion.rs`,
`recheck_promotion_ready()`): C1, an acquire load of the
epoch (56), returned `n + 1`; C2, a `SeqCst` fence (59), follows it; C3
acquire-loads every slot's pin (60-65), and C4 (66) runs only if none was
pinned to `n`. -/
def RecheckCert {X : Execution Ev Loc} (C : CacheProtocol X) (c : Ev) (n : Nat) : Prop :=
  ∃ q s f, X.rf s q ∧ X.isAcq q ∧ X.loc q = some C.R.ep ∧ X.rval q = n + 1 ∧ X.sb q f ∧
    X.isScFence f ∧ X.sb f c ∧ CertScan C f c n

/-- The readiness words of a cache, and where each certificate comes from. -/
structure ReadinessProtocol (X : Execution Ev Loc) (C : CacheProtocol X) extends CertWord X where
  /-- Code fact F13: `mark_promotion_ready()` is called only from
  `rotate_owned()` (R3C, `rotation.rs:185`) and from
  `recheck_promotion_ready()` (C4, `promotion.rs:66`). -/
  cert_src : ∀ {c}, isCert c → RotCert C c (certArg c) ∨ RecheckCert C c (certArg c)

namespace ReadinessProtocol

variable {X : Execution Ev Loc} {C : CacheProtocol X} (RP : ReadinessProtocol X C)

/-- **RD1, certificate soundness.** Every action, from W2 to W7, of every
store attempt for `n` happens before every certificate for `n`. A *store
attempt* is a pass whose B0 saw its incarnation unsealed (`isAttempt`); a
pass whose W3 follows the certifier's fence in the `SeqCst` order sees the
seal, so it is not one. An R3 observing `n` takes neither the wait nor the
reap edge. A reap triggered by an older observation can clear a changed
pin; the death-verdict/P6 handoff still supplies the required ordering. -/
theorem cert_sound (hc : X.Consistent) {c a : Ev} {i : C.ι} {w : Ev}
    (hcert : RP.isCert c) (hw : C.isAttempt i w) (hE : C.attEpoch w = RP.certArg c)
    (ha : (C.pins i).inAttempt w a) : X.hb a c := by
  have hge := C.att_ge hc hw
  -- The certifier's fence follows the seal of `n` (EP3(ii) or EP3(iv)).
  obtain ⟨f, hf, hseal, hscan⟩ : ∃ f, X.isScFence f ∧
      (∃ B Z, C.R.sealOf B Z ∧ C.R.stamp (X.wval Z) = C.attEpoch w ∧ X.hb Z f) ∧
      CertScan C f c (RP.certArg c) := by
    rcases RP.cert_src hcert with
      ⟨A, q, f, hq, hqv, hf, hfA, -, hself, hexit, hscan⟩ |
      ⟨q, s, f, hsq, hacq, hql, hqv, hqf, hf, -, hscan⟩
    · refine ⟨f, hf, ?_, hscan⟩
      rw [hE, ← hqv]
      rw [← hqv] at hexit
      exact C.R.seal_before_tenure_fence hc hq hfA hself hexit
    · -- For `n < 2` there is no attempt for `n` (EP1).
      refine ⟨f, hf, ?_, hscan⟩
      rw [hE]
      exact C.seal_before_fence1 hc hsq hacq hql hqv (by omega) hqf
  -- PN3 and QU1's core on the load of the attempt's slot.
  obtain ⟨Sc, p, hpS, hSl, hSacq, hfS, hSc, hne⟩ := hscan i
  obtain ⟨cl, hw2⟩ := C.att_w2 hw
  obtain ⟨fw, l, u, hfw, hwf, hfl, hul, hll, hst, huns⟩ := C.att_b0 hw
  exact hb_trans
    ((C.pins i).quiescence hc C.R hfw hf hwf hfl hul hll hst huns hseal hfS hpS hw2
      (C.att_pin hw) hSl hSacq (by rw [hE]; exact hne) ha)
    (hb_of_sb hSc)

end ReadinessProtocol

/-- A promotion `w` copies the hit `H`, and `H`'s probe is ordered after a
certificate for its epoch: HT3's three clauses (`ValueProtocol.promo_src`)
for this `H`, and one of two readiness paths. Either the C0 path: an acquire
load `r` of the readiness word of `H.e`'s arena returned exactly `H.e` and
is sequenced before the probe's I1 (`probe.rs:74`, before the home I1 at
`99`); or the reprobe path: a certificate for `H.e`, the C4 of the lookup's
recheck, is sequenced before the reprobe's I1 (`promotion.rs:66`, then
`lookup.rs:198`). And `H` is the one hit `w` copies: its I1 is
`V.src w`. -/
def PromoHit {X : Execution Ev Loc} {C : CacheProtocol X} {A : ArenaProtocol X C}
    {Rv : ReserveProtocol X C} {I : IndexProtocol X A Rv}
    (V : ValueProtocol X I) (RP : ReadinessProtocol X C) (w : Ev) (H : Hit I) : Prop :=
  (X.rval H.v1 = H.e + 1 ∧ C.attEpoch w = X.rval H.v1 ∧
    ∀ j w0, C.isAttempt j w0 → (∀ Y, H.loads Y → ∀ z, X.rf z Y → (C.pins j).inAttempt w0 z) →
      V.attKey w0 = V.attKey w ∧ V.attVal w = V.attVal w0) ∧
  ((∃ r z, X.rf z r ∧ X.isAcq r ∧ X.loc r = some (RP.rdyLoc H.e) ∧ X.rval r = H.e ∧
      X.sb r H.i1) ∨
    ∃ c, RP.isCert c ∧ RP.certArg c = H.e ∧ X.sb c H.i1) ∧
  H.i1 = V.src w

/-- Which hits a promotion may copy, and how it publishes. A separate
structure from `ValueProtocol`, so that HT3 stays independent of
readiness. -/
structure PromotionProtocol (X : Execution Ev Loc) {C : CacheProtocol X} {A : ArenaProtocol X C}
    {Rv : ReserveProtocol X C} {I : IndexProtocol X A Rv}
    (V : ValueProtocol X I) (RP : ReadinessProtocol X C) where
  /-- Code fact F10: `promote` is set only from the `promotion_ready` of a
  `probe::<true>` hit, which only C0 sets (`lookup.rs:121`, `probe.rs:74`
  and `208-211`), or after `reprobe_for_promotion()` returned a hit, which
  it does only after `recheck_promotion_ready()` returned true, that is,
  after C4 (`lookup.rs:124-148`, `191-208`); and the promotion stores the
  copy of that hit (`lookup.rs:137-140`, `157-168`). A hit of V1's own
  arena is never promoted: `probe::<false>` returns `promotion_ready`
  false. The hit is the single one `lookup()` holds, the one whose I1 is
  `V.src w` (`PromoHit`). -/
  promo_ready : ∀ {i w}, C.isAttempt i w → V.isPromo w → ∃ H : Hit I, PromoHit V RP w H
  /-- Code fact F9: a promotion never stores; its I2 is always the CAS
  (`put.rs`, `table_put()`: `if !promotion` guards the store at
  `put.rs:76-79`; the CAS at `put.rs:86-88` is shared by inserts and
  promotions). -/
  promo_cas : ∀ {i w x}, C.isAttempt i w → V.isPromo w → (C.pins i).inAttempt w x →
    (I.isPubCas x ∨ I.isPubStore x) → I.isPubCas x

namespace PromotionProtocol

variable {X : Execution Ev Loc} {C : CacheProtocol X} {A : ArenaProtocol X C}
  {Rv : ReserveProtocol X C} {I : IndexProtocol X A Rv}
  {V : ValueProtocol X I} {RP : ReadinessProtocol X C} (PP : PromotionProtocol X V RP)

include PP in
/-- RB4/FR2: a store publication is made by an insertion, never by a
promotion. The two I2 instruction kinds are disjoint. -/
theorem pub_store_not_promo {i : C.ι} {w x : Ev}
    (hw : C.isAttempt i w) (hx : (C.pins i).inAttempt w x)
    (hstore : I.isPubStore x) : ¬ V.isPromo w := by
  intro hp
  exact I.pub_kinds_disjoint (PP.promo_cas hw hp hx (Or.inr hstore)) hstore

/-- **FR1, fresh source.** If a promotion copies the hit `H`, every action
of every store attempt for `H.e` happens before the I1 that read `H`'s
entry. On the C0 path the certificate synchronises with C0 (RD2), on the
reprobe path it is sequenced before the reprobe; either way it follows the
attempt's actions (RD1). A certificate learned after an I1 would not order
that I1: this is why C0 precedes the first I1, and why the reprobe exists. -/
theorem promo_source_after (hc : X.Consistent) {w : Ev} {H : Hit I}
    (hH : PromoHit V RP w H) {j : C.ι} {w' a : Ev} (hw' : C.isAttempt j w')
    (hE : C.attEpoch w' = H.e) (ha : (C.pins j).inAttempt w' a) : X.hb a H.i1 := by
  obtain ⟨⟨hv1, -, -⟩, hrd, -⟩ := hH
  have hge : 1 ≤ H.e := by
    have ⟨hs1W, _, hls1, _⟩ := hc.rf_wf H.v1_rf
    have := C.R.ep_val_ge hc hs1W (hls1.trans H.v1_loc)
    rw [← hc.rf_val H.v1_rf] at this
    omega
  rcases hrd with ⟨r, z, hrf, hacq, hl, hv, hri⟩ | ⟨c, hcert, harg, hci⟩
  · obtain ⟨c, hcert, harg, hsw⟩ := RP.toCertWord.cert_read hc hrf hacq hl hv (by omega)
    exact hb_trans (RP.cert_sound hc hcert hw' (hE.trans harg.symm) ha)
      (hb_trans (hb_of_sw hsw) (hb_of_sb hri))
  · exact hb_trans (RP.cert_sound hc hcert hw' (hE.trans harg.symm) ha) (hb_of_sb hci)

/-- **FR2, promotion freshness.** If a promotion `w` copies the hit `H`,
the entry `H` followed is a publication of `w`'s key by an attempt `w0` for
`H.e`, and `w` stores `w0`'s value (HT2, HT3). Every publication of that key
by an attempt for `H.e`, insert or promotion, is in `H`'s slot (RB4(b)) and
is the entry `H` read or precedes it in `mo` (FR1 and COH-WR). So the
promoted record is that of the `mo`-last publication of the key in
incarnation `H.e`. Nothing is assumed about timing, sampling or readiness
ever being reached. -/
theorem promo_fresh (hc : X.Consistent) {w : Ev} {H : Hit I} (hH : PromoHit V RP w H) :
    ∃ j w0, C.isAttempt j w0 ∧ (C.pins j).inAttempt w0 H.x2 ∧
      (I.isPubCas H.x2 ∨ I.isPubStore H.x2) ∧ C.attEpoch w0 = H.e ∧
      I.pubKey H.x2 = V.attKey w ∧ V.attVal w = V.attVal w0 ∧
      ∀ {y l k w'}, (I.isPubCas y ∨ I.isPubStore y) → X.isW y → X.loc y = some l →
        C.isAttempt k w' → (C.pins k).inAttempt w' y → C.attEpoch w' = H.e →
        I.pubKey y = I.pubKey H.x2 → l = H.slot ∧ (y = H.x2 ∨ X.mo y H.x2) := by
  obtain ⟨j, w0, hw0, hx2, hxk, hE, hread⟩ := I.hit_integrity hc H
  obtain ⟨hkey, hval⟩ := hH.1.2.2 j w0 hw0 hread
  refine ⟨j, w0, hw0, hx2, hxk, hE, (V.pub_key hxk hw0 hx2).trans hkey, hval,
    fun {y l k w'} hyk hyW hyl hw' hy hE' hkey' => ?_⟩
  have ⟨hx2W, _, hlx2, _⟩ := hc.rf_wf H.i1_rf
  have hx2l : X.loc H.x2 = some H.slot := hlx2.trans H.i1_loc
  have hslot : H.slot = l :=
    I.key_one_slot hc hxk hx2W hx2l hw0 hx2 hyk hyW hyl hw' hy (hE'.trans hE.symm) hkey'
  refine ⟨hslot.symm, ?_⟩
  have hhb := promo_source_after hc hH hw' hE' hy
  rcases coh_wr hc hyW (hyl.trans (hslot ▸ H.i1_loc).symm) hhb H.i1_rf with h | h
  · exact Or.inl h.symm
  · exact Or.inr h

include PP in
/-- **FR2, the publication kind of the latest source.** If the hit's
publication is a store, its attempt is an insert. If it is a CAS, no
earlier publication of the same key and incarnation exists in the slot:
`cas_first` forbids one before the CAS, and `promo_fresh` forbids one after
the publication read by the hit. -/
theorem promo_last_kind (hc : X.Consistent) {w : Ev} {H : Hit I}
    (hH : PromoHit V RP w H) :
    ∃ j w0, C.isAttempt j w0 ∧ (C.pins j).inAttempt w0 H.x2 ∧
      C.attEpoch w0 = H.e ∧
      ((I.isPubStore H.x2 ∧ ¬ V.isPromo w0) ∨
       (I.isPubCas H.x2 ∧
        ∀ {y l k w'}, (I.isPubCas y ∨ I.isPubStore y) → X.isW y →
          X.loc y = some l → C.isAttempt k w' →
          (C.pins k).inAttempt w' y → C.attEpoch w' = H.e →
          I.pubKey y = I.pubKey H.x2 → y = H.x2)) := by
  obtain ⟨j, w0, hw0, hx2, hxk, hE, _, _, hmax⟩ := promo_fresh hc hH
  refine ⟨j, w0, hw0, hx2, hE, ?_⟩
  rcases hxk with hcas | hstore
  · refine Or.inr ⟨hcas, ?_⟩
    intro y l k w' hyk hyW hyl hw' hy hE' hkey'
    obtain ⟨hslot, heq | hmo⟩ := hmax hyk hyW hyl hw' hy hE' hkey'
    · exact heq
    · have ⟨hxW, _, hlx, _⟩ := hc.rf_wf H.i1_rf
      have hxl : X.loc H.x2 = some H.slot := hlx.trans H.i1_loc
      exact (I.cas_first hc (I.index_arena H.slot_index) hcas hxW hxl hw0 hx2
        hyW (hslot ▸ hyl) hw' hy (hE'.trans hE.symm) hmo).elim
  · exact Or.inl ⟨hstore, pub_store_not_promo PP hw0 hx2 hstore⟩

include PP in
/-- **F9 with RB4(d).** A promotion never replaces an entry of its own
incarnation: its publication, a CAS (`promo_cas`), is preceded in its slot's
`mo` by no write of an attempt of the same epoch (`cas_first`). -/
theorem promo_first (hc : X.Consistent) {i j : C.ι} {w w' x y : Ev} {l : Loc}
    (hw : C.isAttempt i w) (hp : V.isPromo w) (hx : (C.pins i).inAttempt w x)
    (hxk : I.isPubCas x ∨ I.isPubStore x) (hxW : X.isW x) (hxl : X.loc x = some l)
    (hw' : C.isAttempt j w') (hy : (C.pins j).inAttempt w' y) (hyW : X.isW y)
    (hyl : X.loc y = some l) (hE : C.attEpoch w' = C.attEpoch w) : ¬ X.mo y x :=
  I.cas_first hc (I.index_arena (I.pub_slot hxk hw hx hxl).1) (PP.promo_cas hw hp hx hxk)
    hxW hxl hw hx hyW hyl hw' hy hE

end PromotionProtocol
end Execution
end Sgc
