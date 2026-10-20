import SgcProofs.PinEventFlow

/-!
# RB5: cleanup on public return/unwind and before release_claim

UnpinOnExit is installed before insert/lookup_raw invokes any callback.
Its Drop reads the pin and calls unpin on nonzero. ParticipantLock::drop
runs the same cleanup before release_claim. A caught panic therefore cannot
carry a pin into a later call. No helper no-panic theorem or exhaustive
list of potential panic sites is required: any finite before is permitted.
The physical proof uses COH-RR, SL1 and R3A's nonzero guard on the zero
branch, and the actual W7 on the nonzero branch.
-/
namespace Sgc.PinFlow

def cleanupTrace (pinned : Bool) : List Action := if pinned then [.w7] else []

theorem cleanup_checked (pinned : Bool) :
    run pinned (cleanupTrace pinned) = some false := by
  cases pinned <;> rfl

/-- Release entry is after cleanup; Drop itself may start pinned. -/
theorem cleanup_release_entry (pinned : Bool) :
    executeTrace pinned (cleanupTrace pinned) = false ∧
    run pinned (cleanupTrace pinned ++ [.releaseClaim]) = some false := by
  refine ⟨checked_equals_execution (cleanup_checked pinned), ?_⟩
  simp [run_append, cleanup_checked, run, step]

/-- Arbitrary finite interruptions, including inside pinned helpers. -/
theorem interrupted_call_cleanup (call : CallExecution) {before suffix : List Action}
    (h : call.trace = before ++ suffix) :
    ∃ pinned, run false before = some pinned ∧
      run false (before ++ cleanupTrace pinned) = some false := by
  have hc := source_call_returns call
  rw [h, run_append] at hc
  cases hp : run false before with
  | none => simp [hp] at hc
  | some pinned => exact ⟨pinned, rfl, by simp [run_append, hp, cleanup_checked]⟩

theorem reuse_after_interruption (call next : CallExecution)
    {before suffix : List Action} (h : call.trace = before ++ suffix) :
    ∃ pinned, run false (before ++ cleanupTrace pinned ++ next.trace) = some false := by
  obtain ⟨pinned, _, hc⟩ := interrupted_call_cleanup call h
  exact ⟨pinned, by rw [run_append, hc]; exact source_call_returns next⟩

/-- The public-call guard appended to any interrupted source prefix. -/
def guardedPrefix (before : List Action) : List Action :=
  before ++ cleanupTrace (executeTrace false before)

theorem guarded_prefix_checked (call : CallExecution) {before after : List Action}
    (h : call.trace = before ++ after) : run false (guardedPrefix before) = some false := by
  obtain ⟨pinned, hp, hc⟩ := interrupted_call_cleanup call h
  simpa [guardedPrefix, checked_equals_execution hp] using hc


/-- A registration may catch any number of panics and reuse its lock.
UnpinOnExit runs before each caught unwind reaches the caller
(`lock.rs:57`, `107`, `167-179`), including a prefix ending just after W2. -/
inductive GuardedHistory where
  | empty
  | returned (previous : GuardedHistory) (call : CallExecution)
  | caught (previous : GuardedHistory) (call : CallExecution)
      (before after : List Action) (hprefix : call.trace = before ++ after)

def GuardedHistory.trace : GuardedHistory → List Action
  | .empty => []
  | .returned previous call => previous.trace ++ call.trace
  | .caught previous _ before _ _ => previous.trace ++ guardedPrefix before

/-- Cleanup, rather than a no-panic assumption, restores the entry state
before the next call, even when the interrupted prefix was pinned. -/
theorem guarded_history_checked (history : GuardedHistory) :
    run false history.trace = some false := by
  induction history with
  | empty => rfl
  | returned previous call ih =>
      simp [GuardedHistory.trace, run_append, ih, source_call_returns]
  | caught previous call before after hprefix ih =>
      simp [GuardedHistory.trace, run_append, ih, guarded_prefix_checked call hprefix]

/-- A finite execution can stop inside a later call or inside its cleanup.
Every such prefix remains checked; it need not be unpinned yet. -/
theorem guarded_history_prefix_checked (history : GuardedHistory)
    {before after : List Action} (h : history.trace = before ++ after) :
    ∃ state, run false before = some state := by
  apply checked_prefix (ys := after)
  rw [← h]
  exact guarded_history_checked history

end Sgc.PinFlow
namespace Sgc.Execution
open Sgc.PinFlow
variable {Ev Loc : Type} {X : Execution Ev Loc} {Lv : Liveness X}
  {S : SlotProtocol X Lv} (P : PinProtocol X S)

/-- Every W2 of an arbitrarily interrupted call has a real W7 after its
public guard runs. Source labels pair the cleanup swap with the unfinished
pass; the checked prefix theorem supplies balance without helper premises. -/
theorem interrupted_call_w7_events (L : PinSourceLabels X S)
    (call : CallExecution) {before after : List Action}
    (h : call.trace = before ++ after) {c w : Ev}
    (T : IndexedPinTrace L c (guardedPrefix before)) (hw : L.w2Of c w)
    (unpin_zero : ∀ {w u}, L.unpinOf w u → X.wval u = 0) :
    ∃ u, L.unpinOf w u ∧ S.act c u ∧ X.sb w u ∧ X.wval u = 0 := by
  have checked := guarded_prefix_checked call h
  have hnext := completed_w2_has_successor checked (T.w2_label hw)
  have hu := T.w7_pair hw rfl (next_after_w2 checked (T.w2_label hw) hnext)
  exact ⟨_, hu, T.owned _ hnext, by
    simpa only [T.w2_event hw] using T.event_order (T.position w) (T.position w + 1)
      (by omega) hnext, unpin_zero hu⟩


/-- **F1 across caught unwinds.** The next own pin write after any W2 has
its W7, including cleanup W7 and later calls after a catch. This derives
pass_exit from source traces and indexed event labels, without assuming
PinProtocol.pass_exit or excluding unwinds in pinned helpers. -/
theorem caught_history_pass_exit (L : PinSourceLabels X S)
    (history : GuardedHistory) {before after : List Action}
    (h : history.trace = before ++ after) {c w N : Ev}
    (T : IndexedPinTrace L c before) (hc : X.Consistent)
    (hw : L.w2Of c w) (hNact : S.act c N) (hN : L.nonAnn N)
    (hwN : X.sb w N) : ∃ u, L.unpinOf w u ∧ S.act c u := by
  obtain ⟨state, checked⟩ := guarded_history_prefix_checked history h
  exact IndexedPinTrace.derives_pass_exit L T hc checked hw hNact hN hwN

private theorem rs_order (hc : X.Consistent) {a b : Ev}
    (h : X.rs a b) : a = b ∨ X.mo a b := by
  induction h with
  | refl => exact Or.inl rfl
  | rmw h rf hw ih =>
    rcases ih with rfl | ih
    · exact Or.inr (hc.rmw_mo rf hw)
    · exact Or.inr (hc.mo_trans ih (hc.rmw_mo rf hw))

/-- COH-WR prevents cleanup from skipping an outstanding own W2, even
when R3A has added its waiting flag. The quiet interval is the source's
unfinished attempt; it does not assume that the helper cannot unwind. -/
theorem cleanup_load_sees_pin (hc : X.Consistent) {c w q p : Ev}
    (hw : P.w2Of c w) (hpin : P.pinVal (X.wval w) ≠ 0)
    (hdecode : P.pinVal 0 = 0) (hwq : X.sb w q) (hqc : S.act c q)
    (hqnw : ¬ X.isW q) (hql : X.loc q = some P.pin) (hrf : X.rf p q)
    (quiet : ∀ {N}, P.nonAnn N → S.act c N → X.sb w N → X.sb N q → False) :
    X.rval q ≠ 0 := by
  have hwp := coh_wr hc (P.w2_w hw) ((P.w2_loc hw).trans hql.symm) (hb_of_sb hwq) hrf
  have ⟨hpw, _, hpl, _⟩ := hc.rf_wf hrf
  obtain ⟨H, hH, hrs, hval, hmax⟩ := P.head hc p hpw (hpl.trans hql)
  have hwH := hmax w (Or.inl ⟨c, hw⟩) (hwp.imp Eq.symm id)
  have hH_eq : H = w := by
    rcases hwH with heq | hwH
    · exact heq.symm
    rcases hH with rfl | hH
    · exact (P.not_mo_pinInit hc hwH).elim
    obtain ⟨d, hdClaim, hHd, hHw, hHl, _⟩ := P.nonAnn_facts hH
    have not_before : ¬ X.hb H w := by
      intro h
      have hm := coh_ww hc hHw (P.w2_w hw) (hHl.trans (P.w2_loc hw).symm)
        (some_ne_none hHl) h
      exact hc.mo_irrefl w (hc.mo_trans hwH hm)
    have not_after : ¬ X.hb q H := by
      intro h
      have hm := coh_rw hc hHw (hHl.trans hql.symm) h hrf
      rcases rs_order hc hrs with rfl | hHp
      · exact hc.mo_irrefl _ hm
      · exact hc.mo_irrefl _ (hc.mo_trans hHp hm)
    have hwc := P.w2_act hw
    have hcc := S.act_claim hwc
    rcases S.claim_total hc hcc hdClaim with heq | hcd | hdc
    · subst d
      have hwHs : X.sb w H := by
        rcases S.act_total hwc hHd with heq | h | h
        · exact (hc.mo_irrefl _ (heq ▸ hwH)).elim
        · exact h
        · exact (not_before (hb_of_sb h)).elim
      rcases S.act_total hHd hqc with heq | h | h
      · exact (hqnw (heq ▸ hHw)).elim
      · exact (quiet hH hHd hwHs h).elim
      · exact (not_after (hb_of_sb h)).elim
    · exact (not_after (S.acts_ordered hc hcc hdClaim hcd hqc hHd)).elim
    · exact (not_before (S.acts_ordered hc hdClaim hcc hdc hHd hwc)).elim
  intro hz
  apply hpin
  rw [← hH_eq, hval, ← hc.rf_val hrf, hz, hdecode]

/-- A cleanup load of raw zero remains zero at the later boundary.
Only own writes are excluded by the straight-line source interval; foreign
registrants and R3A are excluded by the atomic protocols. -/
theorem cleanup_zero_read_stays_zero (hc : X.Consistent) {c q r k p : Ev}
    (hqc : S.act c q) (hrc : S.act c r) (hqr : X.sb q r)
    (hqnw : ¬ X.isW q) (hrnw : ¬ X.isW r)
    (hql : X.loc q = some P.pin) (hrl : X.loc r = some P.pin)
    (hqrf : X.rf k q) (hrf : X.rf p r) (hq0 : X.rval q = 0)
    (hdecode : P.pinVal 0 = 0)
    (quiet : ∀ {N}, P.nonAnn N → S.act c N → X.sb q N → X.sb N r → False)
    (guard : ∀ {a}, P.isAnnounce a → P.pinVal (X.rval a) ≠ 0) : X.rval r = 0 := by
  have hk0 : X.wval k = 0 := (hc.rf_val hqrf).symm.trans hq0
  have ⟨hkw, _, hkl, _⟩ := hc.rf_wf hqrf
  have ⟨hpw, _, hpl, _⟩ := hc.rf_wf hrf
  have hkp := coh_rr hc (hql.trans hrl.symm) (hb_of_sb hqr) hqrf hrf
  have hcc := S.act_claim hqc
  have no_later : ∀ {N}, P.nonAnn N → X.mo k N → (N = p ∨ X.mo N p) → False := by
    intro N hN hkN hNp
    obtain ⟨d, hdClaim, hNd, hNw, hNl, _⟩ := P.nonAnn_facts hN
    have not_before : ¬ X.hb N q := by
      intro h
      rcases coh_wr hc hNw (hNl.trans hql.symm) h hqrf with heq | hmo
      · exact hc.mo_irrefl N (heq ▸ hkN)
      · exact hc.mo_irrefl k (hc.mo_trans hkN hmo)
    have not_after : ¬ X.hb r N := by
      intro h
      have hpN := coh_rw hc hNw (hNl.trans hrl.symm) h hrf
      rcases hNp with rfl | hNp
      · exact hc.mo_irrefl _ hpN
      · exact hc.mo_irrefl p (hc.mo_trans hpN hNp)
    rcases S.claim_total hc hcc hdClaim with heq | hcd | hdc
    · subst d
      have hqN : X.sb q N := by
        rcases S.act_total hqc hNd with heq | h | h
        · exact (hqnw (heq.symm ▸ hNw)).elim
        · exact h
        · exact (not_before (hb_of_sb h)).elim
      rcases S.act_total hNd hrc with heq | h | h
      · exact hrnw (heq ▸ hNw)
      · exact quiet hN hNd hqN h
      · exact not_after (hb_of_sb h)
    · exact not_after (S.acts_ordered hc hcc hdClaim hcd hrc hNd)
    · exact not_before (S.acts_ordered hc hdClaim hcc hdc hNd hqc)
  have hkind : k = P.pinInit ∨ P.nonAnn k := by
    rcases P.pin_writes hkw (hkl.trans hql) with hi | ha | hn
    · exact Or.inl hi
    · obtain ⟨w, hw, hv⟩ := P.ann_rmw ha
      exact (guard ha (by rw [hc.rf_val hw, ← hv, hk0, hdecode])).elim
    · exact Or.inr hn
  obtain ⟨H, hH, hrs, hval, hmax⟩ := P.head hc p hpw (hpl.trans hrl)
  have hHp := rs_order hc hrs
  have hHk : H = k := by
    rcases hkind with rfl | hkn
    · rcases hH with hHi | hHn
      · exact hHi
      · by_cases hne : H = P.pinInit
        · exact hne
        apply False.elim
        obtain ⟨_, _, _, hHw, hHl, _⟩ := P.nonAnn_facts hHn
        have hiH := P.pinInit_first hHw hHl hne
        exact no_later hHn (coh_ww hc P.pinInit_w hHw (P.pinInit_loc.trans hHl.symm)
          (some_ne_none P.pinInit_loc) hiH) hHp
    · rcases hmax k hkn hkp with heq | hkH
      · exact heq.symm
      · rcases hH with rfl | hHn
        · exact (P.not_mo_pinInit hc hkH).elim
        · exact (no_later hHn hkH hHp).elim
  subst H
  have hp0 : P.pinVal (X.wval p) = 0 := by rw [← hval, hk0, hdecode]
  have hpk : p = k := by
    rcases P.pin_writes hpw (hpl.trans hrl) with hi | ha | hn
    · subst p
      rcases hkp with heq | hmo
      · exact heq.symm
      · exact (P.not_mo_pinInit hc hmo).elim
    · obtain ⟨w, hw, hv⟩ := P.ann_rmw ha
      exact (guard ha (by rw [hc.rf_val hw, ← hv]; exact hp0)).elim
    · rcases hkp with heq | hmo
      · exact heq.symm
      · exact (no_later hn hmo (Or.inl rfl)).elim
  rw [hc.rf_val hrf, hpk, hk0]

/-- The actual conditional cleanup establishes a later return/release read
of zero. Source branches and quiet intervals replace helper panic freedom. -/
theorem conditional_cleanup_reads_zero (hc : X.Consistent) {c q r k p : Ev}
    (hqc : S.act c q) (hrc : S.act c r) (hqr : X.sb q r)
    (hqnw : ¬ X.isW q) (hrnw : ¬ X.isW r)
    (hql : X.loc q = some P.pin) (hrl : X.loc r = some P.pin)
    (hqrf : X.rf k q) (hrf : X.rf p r) (hdecode : P.pinVal 0 = 0)
    (zeroQuiet : X.rval q = 0 → ∀ {N}, P.nonAnn N → S.act c N →
      X.sb q N → X.sb N r → False)
    (nonzeroBranch : X.rval q ≠ 0 → ∃ w u, P.unpinOf w u ∧ S.act c u ∧
      X.wval u = 0 ∧ X.sb u r ∧
      ∀ {N}, P.nonAnn N → S.act c N → X.sb u N → X.sb N r → False)
    (guard : ∀ {a}, P.isAnnounce a → P.pinVal (X.rval a) ≠ 0) : X.rval r = 0 := by
  by_cases hz : X.rval q = 0
  · exact cleanup_zero_read_stays_zero P hc hqc hrc hqr hqnw hrnw hql hrl hqrf hrf hz
      hdecode (zeroQuiet hz) guard
  · obtain ⟨w, u, hu, huc, hu0, hur, quiet⟩ := nonzeroBranch hz
    exact own_entry_after_w7_reads_zero P hc hu huc hu0 hdecode hur hrc hrnw hrl hrf quiet guard

end Sgc.Execution
