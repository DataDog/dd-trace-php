import SgcProofs.LoadBounds

/-!
Reservation offsets remain eight-byte aligned through control-word and
chunk-cursor writes. The only extra configuration fact is chunk alignment,
checked by Config::resolve. No assertion outcome or aligned reservation is
assumed: initialization/reset, B1 and the H1/H2 cursor transitions establish
it by modification-order induction, including wrapped updates.
-/
namespace Sgc.Execution.ReserveProtocol
variable {Ev Loc : Type} {X : Execution Ev Loc} {C : CacheProtocol X}
  (Rv : ReserveProtocol X C)

theorem need_aligned {i : C.ι} {w : Ev} (hw : C.isAttempt i w) : Rv.need w % 8 = 0 := by
  rw [Rv.need_lens hw]
  omega

theorem claim_aligned (hchunk : Rv.chunk % 8 = 0) {b : Ev} (hb : C.R.isB1 b) :
    Rv.claim b % 8 = 0 := by
  obtain ⟨i, w, hw, hwb⟩ := Rv.b1_att hb
  obtain ⟨l, u, _, _, _, _, _, _, hclaim⟩ := Rv.b1_b0 hw hwb hb
  have hn := Rv.need_aligned hw
  split at hclaim <;> omega

theorem control_bump_aligned (hc : X.Consistent) (hchunk : Rv.chunk % 8 = 0)
    (y : Ev) : ∀ n, X.isW y → X.loc y = some (C.R.ctlLoc n) →
      Rv.bump (X.wval y) % 8 = 0 := by
  induction y using hc.mo_wellFounded.induction with
  | h y ih =>
    intro n hy hl
    rcases C.R.ctl_writes hy hl with hinit | hb | ⟨a, hs⟩ | ⟨a, hr⟩
    · rw [Rv.ctlInit_bump hinit]
    · obtain ⟨u, hrf, _⟩ := C.R.b1_rmw hb
      have hu := ih u (hc.rmw_mo hrf hy) n (hc.rf_wf hrf).1 ((hc.rf_wf hrf).2.2.1.trans hl)
      have hadd := Rv.claim_aligned hchunk hb
      rw [Rv.b1_bump hb, hc.rf_val hrf]
      unfold bumpLimit
      omega
    · obtain ⟨u, hrf, heq⟩ := Rv.seal_bump hs
      rw [heq]
      exact ih u (hc.rmw_mo hrf hy) n (hc.rf_wf hrf).1 ((hc.rf_wf hrf).2.2.1.trans hl)
    · rw [Rv.r4_bump hr]

theorem b1_read_bump_aligned (hc : X.Consistent) (hchunk : Rv.chunk % 8 = 0)
    {i : C.ι} {w b : Ev} (hw : C.isAttempt i w)
    (hb : (C.pins i).inAttempt w b) (hbB : C.R.isB1 b) : Rv.bump (X.rval b) % 8 = 0 := by
  obtain ⟨u, hrf, _⟩ := C.R.b1_rmw hbB
  rw [hc.rf_val hrf]
  exact Rv.control_bump_aligned hc hchunk u (C.attEpoch w) (hc.rf_wf hrf).1
    ((hc.rf_wf hrf).2.2.1.trans (C.b1_loc hw hb hbB).2)

theorem cursor_aligned (hc : X.Consistent) (hchunk : Rv.chunk % 8 = 0)
    (y : Ev) : ∀ i, X.isW y → X.loc y = some (Rv.ccLoc i) → X.wval y % 8 = 0 := by
  induction y using hc.mo_wellFounded.induction with
  | h y ih =>
    intro i hy hl
    rcases Rv.cc_writes hy hl with hinit | ⟨c, hr⟩ | ⟨w, b, xe, xn, hch⟩ | ⟨w, le, lc, ln, hu⟩
    · rw [Rv.chunkInit_val hinit]
    · rw [Rv.reset_val hr]
    · obtain ⟨hw, hb, hbB, _⟩ := Rv.chunk_b1 hch
      have ha := Rv.b1_read_bump_aligned hc hchunk hw hb hbB
      have hn := Rv.need_aligned hw
      obtain ⟨_, _, _, _, _, _, _, _, _, _, _, _, _, hval, _⟩ := Rv.chunk_ev hch
      rw [hval]
      unfold wordLimit
      omega
    · obtain ⟨hw, _, _, _, _, hls, _, _, _, _, _, _, hcl, _, hyl,
          _, ⟨u, hrf⟩, _, _, _, hval⟩ := Rv.use_ev hu
      have hum : X.mo u y := coh_rw hc hy (hyl.trans hcl.symm) (hb_of_sb hls) hrf
      have ha := ih u hum i (hc.rf_wf hrf).1 ((hc.rf_wf hrf).2.2.1.trans hcl)
      have hn := Rv.need_aligned hw
      rw [hval, hc.rf_val hrf]
      unfold wordLimit
      omega

/-- Both successful reservation forms return aligned offsets. This also
covers a table_put that never publishes because its table is full. -/
theorem reservation_aligned (hc : X.Consistent) (hchunk : Rv.chunk % 8 = 0)
    {i : C.ι} {w : Ev} {off : Nat} (hw : C.isAttempt i w)
    (hr : Rv.reserves i w off) : off % 8 = 0 := by
  rcases hr with ⟨b, hb, hbB, _, rfl⟩ | ⟨le, lc, ln, s, hu, rfl⟩
  · exact Rv.b1_read_bump_aligned hc hchunk hw hb hbB
  · obtain ⟨_, _, _, _, _, _, _, _, _, _, _, _, hcl, _, _,
        _, ⟨u, hrf⟩, _⟩ := Rv.use_ev hu
    rw [hc.rf_val hrf]
    exact Rv.cursor_aligned hc hchunk u i (hc.rf_wf hrf).1 ((hc.rf_wf hrf).2.2.1.trans hcl)

end Sgc.Execution.ReserveProtocol
