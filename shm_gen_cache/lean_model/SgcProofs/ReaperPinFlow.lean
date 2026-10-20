import SgcProofs.Cache
import SgcProofs.Recovery

/-!
# Reaper release entries on actual atomic executions

The release in reap_pinner/reap_owner is on the stolen registration. Its
P6 store is followed by wake_rotation and reset_chunk, neither of which
writes that pin, then release_claim's relaxed assertion load. Other
registrants' pin writes are excluded using SL1/PN1, not by assuming the
assertion succeeds. An R3A cannot overwrite P6: its compare_exchange
expects the nonzero pin read before its wait guard. This file proves that
such a release reads the actual zero word, including the waiting flag.

Registration boundaries are proved from atomic initialization, normal
P3/P1 release/acquire handoff, or the returned steal's P6. Empty normal
registrations inherit the prior boundary without inventing a W7. These
proofs do not rely on `production_assert!`: `src/assert.rs` omits both the
check and its load in release/verify builds. The read theorems describe a
read when present; the zero-write prefix certificates do not require one.
The source boundary is `ParticipantLock::new` after claim/steal returns,
then the first API operation (or Drop). `src/cache/store.rs` supplies W7
on every completed pinned pass; an operation that never reaches W2 leaves
the inherited boundary intact. Source interval facts, A1 publication,
and the existing SlotProtocol/PinProtocol model assumptions remain explicit.
-/
namespace Sgc.Execution
variable {Ev Loc : Type} {X : Execution Ev Loc} {Lv : Liveness X}
  {S : SlotProtocol X Lv} (P : PinProtocol X S)

private theorem release_sequence_order (hc : X.Consistent) {a b : Ev}
    (h : X.rs a b) : a = b ∨ X.mo a b := by
  induction h with
  | refl => exact Or.inl rfl
  | rmw h rf hw ih =>
    rcases ih with rfl | ih
    · exact Or.inr (hc.rmw_mo rf hw)
    · exact Or.inr (hc.mo_trans ih (hc.rmw_mo rf hw))

/-- A zero non-announce write followed by a local interval containing no
registrant pin write. These are event facts, not a premise about the value
of the later read. The interval may follow W7 or the reaper's P6. -/
structure ZeroWriteReadFacts where
  releaseRead : Ev → Ev → Prop
  origin : Ev → Ev → Ev
  source : ∀ {c r}, releaseRead c r → ∃ k p,
    k = origin c r ∧ P.nonAnn k ∧ S.act c k ∧ X.wval k = 0 ∧ P.pinVal 0 = 0 ∧ X.sb k r ∧
    S.act c r ∧ ¬ X.isW r ∧ X.loc r = some P.pin ∧ X.rf p r
  quiet : ∀ {c k r N}, releaseRead c r → k = origin c r →
    P.nonAnn N → S.act c N → X.sb k N → X.sb N r → False
  announce_guard : ∀ {a}, P.isAnnounce a → P.pinVal (X.rval a) ≠ 0

/-- The source path and atomic protocols establish that the reaper's
release_claim assertion load reads zero; no pin balance is a premise. -/
theorem zero_write_reads_zero (F : ZeroWriteReadFacts P) (hc : X.Consistent)
    {c r : Ev} (hr : F.releaseRead c r) : X.rval r = 0 := by
  obtain ⟨k, p, hkorigin, hk, hkact, hk0, hdecode, hkr, hrc, hrnw, hrl, hrf⟩ := F.source hr
  have hkna : P.nonAnn k := hk
  obtain ⟨_, _, _, hkw, hkl, _⟩ := P.nonAnn_facts hk
  have hcc := S.act_claim hkact
  have hkp : k = p ∨ X.mo k p :=
    (coh_wr hc hkw (hkl.trans hrl.symm) (hb_of_sb hkr) hrf).imp Eq.symm id
  have ⟨hpw, _, hpl, _⟩ := hc.rf_wf hrf
  obtain ⟨H, hH, hrs, hval, hmax⟩ := P.head hc p hpw (hpl.trans hrl)
  have hkH := hmax k hkna hkp
  have hHk : H = k := by
    rcases hkH with heq | hkH
    · exact heq.symm
    rcases hH with rfl | hH
    · exact (P.not_mo_pinInit hc hkH).elim
    obtain ⟨d, hdc, hHd, hHw, hHl, _⟩ := P.nonAnn_facts hH
    have not_before_k : ¬ X.hb H k := by
      intro h
      have hm := coh_ww hc hHw hkw (hHl.trans hkl.symm) (some_ne_none hHl) h
      exact hc.mo_irrefl _ (hc.mo_trans hkH hm)
    have not_after_r : ¬ X.hb r H := by
      intro h
      have hm := coh_rw hc hHw (hHl.trans hrl.symm) h hrf
      rcases release_sequence_order hc hrs with rfl | hh
      · exact hc.mo_irrefl _ hm
      · exact hc.mo_irrefl _ (hc.mo_trans hh hm)
    rcases S.claim_total hc hcc hdc with heq | hcd | hdc
    · subst d
      have hkHs : X.sb k H := by
        rcases S.act_total hkact hHd with heq | h | h
        · exact (hc.mo_irrefl _ (heq ▸ hkH)).elim
        · exact h
        · exact (not_before_k (hb_of_sb h)).elim
      rcases S.act_total hHd hrc with heq | h | h
      · exact (hrnw (heq ▸ hHw)).elim
      · exact (F.quiet hr hkorigin hH hHd hkHs h).elim
      · exact (not_after_r (hb_of_sb h)).elim
    · exact (not_after_r (S.acts_ordered hc hcc (S.act_claim hHd) hcd hrc hHd)).elim
    · exact (not_before_k (S.acts_ordered hc (S.act_claim hHd) hcc hdc hHd hkact)).elim
  subst H
  have hp0 : P.pinVal (X.wval p) = 0 := by rw [← hval, hk0, hdecode]
  have hpk : p = k := by
    rcases P.pin_writes hpw (hpl.trans hrl) with hpinit | hpann | hpna
    · subst p
      rcases hkp with heq | hmo
      · exact heq.symm
      · exact (P.not_mo_pinInit hc hmo).elim
    · obtain ⟨w, hw, hv⟩ := P.ann_rmw hpann
      apply False.elim
      apply F.announce_guard hpann
      rw [hc.rf_val hw, ← hv]
      exact hp0
    · rcases hmax p hpna (Or.inl rfl) with hpk | hpk
      · exact hpk
      · rcases hkp with rfl | hkp
        · exact (hc.mo_irrefl _ hpk).elim
        · exact (hc.mo_irrefl _ (hc.mo_trans hkp hpk)).elim
  rw [hc.rf_val hrf, hpk, hk0]

/-- Local source facts for the straight-line successful-steal/release
path. The quiet window excludes only this registration's writes. Foreign
writes are handled by slot serialization. The R3A guard is the source
branch pin != UNPINNED, before attempting its compare_exchange. -/
structure ReaperReleaseFacts where
  releaseRead : Ev → Ev → Prop
  source : ∀ {c r}, releaseRead c r → ∃ k p,
    P.clearOf c k ∧ X.wval k = 0 ∧ P.pinVal 0 = 0 ∧ X.sb k r ∧
    S.act c r ∧ ¬ X.isW r ∧ X.loc r = some P.pin ∧ X.rf p r
  quiet : ∀ {c k r N}, releaseRead c r → P.clearOf c k →
    P.nonAnn N → S.act c N → X.sb k N → X.sb N r → False
  announce_guard : ∀ {a}, P.isAnnounce a → P.pinVal (X.rval a) ≠ 0

/-- The source path and atomic protocols establish that the reaper's
release_claim assertion load reads zero; no pin balance is a premise. -/
theorem reaper_release_reads_zero (F : ReaperReleaseFacts P) (hc : X.Consistent)
    {c r : Ev} (hr : F.releaseRead c r) : X.rval r = 0 := by
  obtain ⟨k, p, hk, hk0, hdecode, hkr, hrc, hrnw, hrl, hrf⟩ := F.source hr
  have hkna : P.nonAnn k := Or.inr (Or.inr ⟨c, hk⟩)
  have hkact := P.clear_act hk
  have hcc := S.act_claim hkact
  have hkp : k = p ∨ X.mo k p :=
    (coh_wr hc (P.clear_w hk) ((P.clear_loc hk).trans hrl.symm) (hb_of_sb hkr) hrf).imp Eq.symm id
  have ⟨hpw, _, hpl, _⟩ := hc.rf_wf hrf
  obtain ⟨H, hH, hrs, hval, hmax⟩ := P.head hc p hpw (hpl.trans hrl)
  have hkH := hmax k hkna hkp
  have hHk : H = k := by
    rcases hkH with heq | hkH
    · exact heq.symm
    rcases hH with rfl | hH
    · exact (P.not_mo_pinInit hc hkH).elim
    obtain ⟨d, hdc, hHd, hHw, hHl, _⟩ := P.nonAnn_facts hH
    have not_before_k : ¬ X.hb H k := by
      intro h
      have hm := coh_ww hc hHw (P.clear_w hk) (hHl.trans (P.clear_loc hk).symm) (some_ne_none hHl) h
      exact hc.mo_irrefl _ (hc.mo_trans hkH hm)
    have not_after_r : ¬ X.hb r H := by
      intro h
      have hm := coh_rw hc hHw (hHl.trans hrl.symm) h hrf
      rcases release_sequence_order hc hrs with rfl | hh
      · exact hc.mo_irrefl _ hm
      · exact hc.mo_irrefl _ (hc.mo_trans hh hm)
    rcases S.claim_total hc hcc hdc with heq | hcd | hdc
    · subst d
      have hkHs : X.sb k H := by
        rcases S.act_total hkact hHd with heq | h | h
        · exact (hc.mo_irrefl _ (heq ▸ hkH)).elim
        · exact h
        · exact (not_before_k (hb_of_sb h)).elim
      rcases S.act_total hHd hrc with heq | h | h
      · exact (hrnw (heq ▸ hHw)).elim
      · exact (F.quiet hr hk hH hHd hkHs h).elim
      · exact (not_after_r (hb_of_sb h)).elim
    · exact (not_after_r (S.acts_ordered hc hcc (S.act_claim hHd) hcd hrc hHd)).elim
    · exact (not_before_k (S.acts_ordered hc (S.act_claim hHd) hcc hdc hHd hkact)).elim
  subst H
  have hp0 : P.pinVal (X.wval p) = 0 := by rw [← hval, hk0, hdecode]
  have hpk : p = k := by
    rcases P.pin_writes hpw (hpl.trans hrl) with hpinit | hpann | hpna
    · subst p
      rcases hkp with heq | hmo
      · exact heq.symm
      · exact (P.not_mo_pinInit hc hmo).elim
    · obtain ⟨w, hw, hv⟩ := P.ann_rmw hpann
      apply False.elim
      apply F.announce_guard hpann
      rw [hc.rf_val hw, ← hv]
      exact hp0
    · rcases hmax p hpna (Or.inl rfl) with hpk | hpk
      · exact hpk
      · rcases hkp with rfl | hkp
        · exact (hc.mo_irrefl _ hpk).elim
        · exact (hc.mo_irrefl _ (hc.mo_trans hkp hpk)).elim
  rw [hc.rf_val hrf, hpk, hk0]

/-- Own-slot rotate/release assertion loads after the last W7 read the
raw UNPINNED word. The interval premise excludes only own writes; SL1
excludes foreign writes and R3A's nonzero guard excludes a waiting flag.
The first registration, before any W7, needs its own initialization proof. -/
theorem own_entry_after_w7_reads_zero (hc : X.Consistent) {c w u r p : Ev}
    (hu : P.unpinOf w u) (huc : S.act c u)
    (hu0 : X.wval u = 0) (hdecode : P.pinVal 0 = 0)
    (hur : X.sb u r) (hrc : S.act c r) (hrnw : ¬ X.isW r)
    (hrl : X.loc r = some P.pin) (hrf : X.rf p r)
    (quiet : ∀ {N}, P.nonAnn N → S.act c N → X.sb u N → X.sb N r → False)
    (guard : ∀ {a}, P.isAnnounce a → P.pinVal (X.rval a) ≠ 0) : X.rval r = 0 := by
  let F : ZeroWriteReadFacts P := {
    releaseRead := fun d e => d = c ∧ e = r
    origin := fun _ _ => u
    source := by
      intro d e h
      obtain ⟨rfl, rfl⟩ := h
      exact ⟨u, p, rfl, Or.inr (Or.inl ⟨w, hu⟩), huc, hu0, hdecode, hur,
        hrc, hrnw, hrl, hrf⟩
    quiet := by
      intro d k e N h hk hN hNc hkN hNr
      obtain ⟨rfl, rfl⟩ := h
      subst k
      exact quiet hN hNc hkN hNr
    announce_guard := guard }
  exact zero_write_reads_zero P F hc ⟨rfl, rfl⟩

/-- One event cannot be performed for two distinct registrations of the
same slot. This follows from SL1's ordering of their actions. -/
theorem same_slot_action_unique (hc : X.Consistent) {c d e : Ev}
    (hcAct : S.act c e) (hdAct : S.act d e) : c = d := by
  have hcC := S.act_claim hcAct
  have hdC := S.act_claim hdAct
  rcases S.claim_total hc hcC hdC with heq | hcd | hdc
  · exact heq
  · exact (hc.hb_irrefl e (S.acts_ordered hc hcC hdC hcd hcAct hdAct)).elim
  · exact (hc.hb_irrefl e (S.acts_ordered hc hdC hcC hdc hdAct hcAct)).elim

/-- A running rotator cannot steal its own still-held registration.
P4 acts for the target registration, so no caller-act premise is imposed
on it. The caller registered before this function call and releases only
after it (ParticipantLock keeps its claim across rotate/reap). A9 excludes
a steal ending that running registration; source order excludes its P3. -/
theorem reaper_target_distinct (C : CacheProtocol X) (hc : X.Consistent)
    (A9 : NoFalseDeath C.Lv) {i j : C.ι} {caller registered steal : Ev}
    (hreg : (C.slot i).regOf caller registered) (hprior : X.sb registered steal)
    (hheld : ∀ {f}, (C.slot i).freeOf caller f → X.sb steal f)
    (hrunning : A9.toThreadLifetime.running (C.Lv.thrOf caller) steal)
    (hsteal : (C.slot j).isSteal steal) : i ≠ j := by
  intro heq
  subst j
  have hmo := coh_ww_at hc ((C.slot i).reg_w hreg) ((C.slot i).reg_loc hreg)
    ((C.slot i).claim_w (Or.inr hsteal)) ((C.slot i).claim_loc (Or.inr hsteal))
    (hb_of_sb hprior)
  obtain ⟨ending, hkind, hle⟩ := (C.slot i).ender_before hc hreg steal hmo
  rcases hkind with hf | hs
  · rcases hle with rfl | hm
    · exact (C.slot i).free_not_claim hf (Or.inr hsteal)
    · have hb := (C.slot i).hb_later_claim hc ((C.slot i).free_w hf)
        ((C.slot i).free_loc hf) ((C.slot i).free_isRel hf) (Or.inr hsteal) hm
      exact hc.hb_irrefl steal (hb_trans (hb_of_sb (hheld hf)) hb)
  · rcases hle with rfl | hm
    · exact (C.slot i).no_steal_of_running A9 hc hs hrunning
    · have he := (C.slot i).stealOf_claims hs
      have hb := (C.slot i).hb_later_claim hc ((C.slot i).claim_w (Or.inr he.2))
        ((C.slot i).claim_loc (Or.inr he.2)) ((C.slot i).steal_isRel he.2)
        (Or.inr hsteal) hm
      exact (C.slot i).no_steal_before_running A9 hc hs (Or.inr hb) hrunning

/-- A source boundary records writes, never the result of an assertion.
`past` covers registrations strictly before this one. A successful P1
inherits this boundary through its FREE predecessor; a successful steal
creates it with P6. -/
structure RegistrationZeroPrefix (c k : Ev) : Prop where
  claim : S.isClaim c
  write : X.isW k
  location : X.loc k = some P.pin
  zero : X.wval k = 0
  past : ∀ {d N}, S.isClaim d → X.mo d c → P.nonAnn N → S.act d N →
    N = k ∨ X.hb N k

/-- P1 reading the initial FREE has no preceding registration. The
initial pin store is zero in ParticipantSlot::initialize. Publication of
the mapping must separately order this store before the entry read. -/
theorem initial_registration_zero_prefix (hc : X.Consistent) {c : Ev}
    (hfc : S.isFreeClaim c) (hrf : X.rf S.init c)
    (hzero : X.wval P.pinInit = 0) : RegistrationZeroPrefix P c P.pinInit := by
  refine ⟨Or.inl hfc, P.pinInit_w, P.pinInit_loc, hzero, ?_⟩
  intro d N hd hdc _ _
  rcases rmw_pred_ge hc hrf (S.claim_w hd)
      ((S.claim_loc hd).trans S.init_loc.symm) hdc with heq | h
  · subst d
    exact (S.init_not_claim hc hd).elim
  · exact (S.not_mo_init hc h).elim

private theorem claim_before_free_predecessor (hc : X.Consistent)
    {d f c a : Ev} (hf : S.freeOf d f) (hrf : X.rf f c)
    (ha : S.isClaim a) (hac : X.mo a c) : a = d ∨ X.mo a d := by
  have haf := rmw_pred_ge hc hrf (S.claim_w ha)
    ((S.claim_loc ha).trans (S.free_loc hf).symm) hac
  rcases S.claim_total hc ha (S.free_claim hf) with heq | h | h
  · exact Or.inl heq
  · exact Or.inr h
  · obtain ⟨g, hg, hgf⟩ := S.free_reads hf
    rcases haf with rfl | haf
    · exact (S.free_not_claim hf ha).elim
    · exact (S.no_claim_after_reg hc hg hgf ha h haf).elim

/-- P3 with a final W7 (or P6) hands that zero to P1. The terminal-write
premise is source control flow, not the omitted release assertion. P2 is
not needed for this prefix: its successful rf edge is checked separately
by `registration_entry_reads_zero`. -/
theorem free_registration_zero_prefix (hc : X.Consistent) {d f c k : Ev}
    (hf : S.freeOf d f) (hfc : S.isFreeClaim c) (hrf : X.rf f c)
    (hk : P.nonAnn k) (hkd : S.act d k) (hk0 : X.wval k = 0)
    (last : ∀ {N}, P.nonAnn N → S.act d N → N = k ∨ X.sb N k) :
    RegistrationZeroPrefix P c k ∧ X.hb k c := by
  obtain ⟨_, _, _, hkw, hkl, _⟩ := P.nonAnn_facts hk
  refine ⟨⟨Or.inl hfc, hkw, hkl, hk0, ?_⟩, S.handoff_free hf hrf hfc hkd⟩
  intro a N ha hac hN hNa
  rcases claim_before_free_predecessor hc hf hrf ha hac with rfl | had
  · exact (last hN hNa).imp id hb_of_sb
  · exact Or.inr (S.acts_ordered hc ha (S.free_claim hf) had hNa hkd)

/-- A registration may be released without ever storing W2. Such a P3
inherits the existing prefix; it does not require a fictitious W7. This
constructor can be iterated through arbitrarily many empty registrations.
The hb premise is the previous boundary's ordering before P3. -/
theorem empty_free_registration_zero_prefix (hc : X.Consistent) {d f c k : Ev}
    (B : RegistrationZeroPrefix P d k) (hf : S.freeOf d f)
    (hfc : S.isFreeClaim c) (hrf : X.rf f c) (hkf : X.hb k f)
    (empty : ∀ {N}, P.nonAnn N → S.act d N → False) :
    RegistrationZeroPrefix P c k ∧ X.hb k c := by
  refine ⟨⟨Or.inl hfc, B.write, B.location, B.zero, ?_⟩, ?_⟩
  · intro a N ha hac hN hNa
    rcases claim_before_free_predecessor hc hf hrf ha hac with rfl | had
    · exact (empty hN hNa).elim
    · exact B.past ha had hN hNa
  · exact hb_trans hkf (hb_of_sw (sw.relAcq (S.free_isRel hf)
      (rs.refl f) hrf (S.fc_isAcq hfc)))

/-- A returned successful steal has executed P6 after P5. SL1 orders all
older registrations before that clear, even when the stolen claim died
in INITIALIZING or during an earlier reaper's handoff. -/
theorem stolen_registration_zero_prefix (hc : X.Consistent) {c k : Ev}
    (hk : P.clearOf c k) (hk0 : X.wval k = 0) : RegistrationZeroPrefix P c k := by
  have hkc := P.clear_act hk
  refine ⟨S.act_claim hkc, P.clear_w hk, P.clear_loc hk, hk0, ?_⟩
  intro d N hd hdc _ hNd
  exact Or.inr (S.acts_ordered hc hd (S.act_claim hkc) hdc hNd hkc)

/-- An entry before this registration's first W2 reads the raw zero word.
The baseline is constructed from initialization, normal FREE handoff, or
P6 above. `hprefix` describes the source prefix: every own non-announce
write is the baseline, precedes it, or follows the entry. In particular,
this permits the initial P6 on the stolen-registration path. Neither
claim/release/assert_usable assertions nor their loads are assumed to run.
If the read is present, coherence forces its result. -/
theorem registration_entry_reads_zero (hc : X.Consistent) {c g k r p : Ev}
    (B : RegistrationZeroPrefix P c k) (hreg : S.regOf c g)
    (hgr : X.sb g r) (hrc : S.act c r)
    (hrl : X.loc r = some P.pin) (hrf : X.rf p r) (hkr : X.hb k r)
    (hdecode : P.pinVal 0 = 0)
    (hprefix : ∀ {N}, P.nonAnn N → S.act c N →
      N = k ∨ X.hb N k ∨ X.sb r N)
    (guard : ∀ {a}, P.isAnnounce a → P.pinVal (X.rval a) ≠ 0) :
    X.rval r = 0 ∧ X.mo c g ∧ X.hb g r := by
  have hcg := hc.rmw_mo (S.reg_reads hreg) (S.reg_w hreg)
  refine ⟨?_, hcg, hb_of_sb hgr⟩
  have ⟨hpw, _, hpl, _⟩ := hc.rf_wf hrf
  obtain ⟨H, hH, hrs, hval, hmax⟩ := P.head hc p hpw (hpl.trans hrl)
  have hkp : k = p ∨ X.mo k p :=
    (coh_wr hc B.write (B.location.trans hrl.symm) hkr hrf).imp Eq.symm id
  have after_impossible : ¬ X.hb r H := by
    intro h
    have hHw : X.isW H := by
      rcases hH with rfl | hH
      · exact P.pinInit_w
      · exact (P.nonAnn_facts hH).choose_spec.2.2.1
    have hHl : X.loc H = some P.pin := by
      rcases hH with rfl | hH
      · exact P.pinInit_loc
      · obtain ⟨_, _, _, _, hl, _⟩ := P.nonAnn_facts hH
        exact hl
    have hm := coh_rw hc hHw (hHl.trans hrl.symm) h hrf
    rcases release_sequence_order hc hrs with rfl | hh
    · exact hc.mo_irrefl _ hm
    · exact hc.mo_irrefl _ (hc.mo_trans hh hm)
  have hHk : H = k := by
    rcases hH with hinit | hH
    · subst H
      rcases P.pin_writes B.write B.location with hkinit | hkann | hkna
      · exact hkinit.symm
      · -- A zero baseline may never be a guarded announce.
        obtain ⟨w, hw, hv⟩ := P.ann_rmw hkann
        exact (guard hkann (by rw [hc.rf_val hw, ← hv, B.zero, hdecode])).elim
      · rcases hmax k hkna hkp with heq | hmo
        · exact heq.symm
        · exact (P.not_mo_pinInit hc hmo).elim
    · obtain ⟨d, hd, hHd, hHw, hHl, _⟩ := P.nonAnn_facts hH
      have split : H = k ∨ X.hb H k ∨ X.hb r H := by
        rcases S.claim_total hc hd B.claim with rfl | hdc | hcd
        · exact (hprefix hH hHd).imp id (fun h => h.imp id hb_of_sb)
        · exact (B.past hd hdc hH hHd).imp id Or.inl
        · exact Or.inr (Or.inr (S.acts_ordered hc B.claim hd hcd hrc hHd))
      rcases split with heq | hbefore | hafter
      · exact heq
      · have hHkm := coh_ww hc hHw B.write (hHl.trans B.location.symm)
          (some_ne_none hHl) hbefore
        rcases P.pin_writes B.write B.location with hkinit | hkann | hkna
        · subst k
          exact (P.not_mo_pinInit hc hHkm).elim
        · obtain ⟨w, hw, hv⟩ := P.ann_rmw hkann
          exact (guard hkann (by rw [hc.rf_val hw, ← hv, B.zero, hdecode])).elim
        · rcases hmax k hkna hkp with heq | hkH
          · exact heq.symm
          · exact (hc.mo_irrefl _ (hc.mo_trans hHkm hkH)).elim
      · exact (after_impossible hafter).elim
  have hp0 : P.pinVal (X.wval p) = 0 := by rw [← hval, hHk, B.zero, hdecode]
  have hpk : p = k := by
    rcases P.pin_writes hpw (hpl.trans hrl) with hpinit | hpann | hpna
    · subst p
      rcases hkp with heq | hm
      · exact heq.symm
      · exact (P.not_mo_pinInit hc hm).elim
    · obtain ⟨w, hw, hv⟩ := P.ann_rmw hpann
      exact (guard hpann (by rw [hc.rf_val hw, ← hv]; exact hp0)).elim
    · rcases hmax p hpna (Or.inl rfl) with heq | hpH
      · exact heq.trans hHk
      · rw [hHk] at hpH
        rcases hkp with rfl | hkp
        · exact (hc.mo_irrefl _ hpH).elim
        · exact (hc.mo_irrefl _ (hc.mo_trans hpH hkp)).elim
  rw [hc.rf_val hrf, hpk, B.zero]

/-- Convert the source's quiet initial interval into the ordering premise
of `registration_entry_reads_zero`. For a fresh P1, there are no own pin
writes before the first entry. After a steal, P6 is the one allowed write;
a later operation boundary uses its final W7 instead. -/
theorem registration_entry_prefix {c k r : Ev}
    (hrc : S.act c r) (hrnw : ¬ X.isW r)
    (quiet : ∀ {N}, P.nonAnn N → S.act c N → X.sb N r →
      N = k ∨ X.sb N k) :
    ∀ {N}, P.nonAnn N → S.act c N → N = k ∨ X.hb N k ∨ X.sb r N := by
  intro N hN hNc
  rcases S.act_total hNc hrc with heq | hNr | hrN
  · obtain ⟨_, _, _, hNw, _⟩ := P.nonAnn_facts hN
    exact (hrnw (heq ▸ hNw)).elim
  · exact (quiet hN hNc hNr).imp id (fun h => Or.inl (hb_of_sb h))
  · exact Or.inr (Or.inr hrN)

end Sgc.Execution
