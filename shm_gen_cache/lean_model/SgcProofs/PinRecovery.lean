import SgcProofs.Pins

/-!
# PN2: provenance of the first clear after an interrupted reaper

A successful steal has source order P4, T2, P5, P6, wake, H0, return
(`participant.rs:315-347`). A stealing registration cannot pin or release
its slot before that return, hence before its P6. This module derives the
conditional clearing provenance from those local facts and the slot RMW
protocol. It permits any finite chain of reapers stopping before P6.
It does not assert that a later clear eventually exists.
-/
namespace Sgc.Execution
variable {Ev Loc : Type} {X : Execution Ev Loc} {Lv : Liveness X}

namespace SlotProtocol
variable (S : SlotProtocol X Lv)

/-- The immediately preceding claim is replaced by P4, or released by P3
before the new P1. This is derived from the successful state-word RMWs. -/
theorem claim_predecessor (hc : X.Consistent) {a c : Ev}
    (ha : S.isClaim a) (hcC : S.isClaim c) (hac : X.mo a c) :
    ∃ p, S.isClaim p ∧ X.mo p c ∧ (a = p ∨ X.mo a p) ∧
      (S.stealOf p c ∨ ∃ x, S.freeOf p x ∧ X.rf x c) := by
  rcases hcC with hfc | hst
  · obtain ⟨x, hrf, hx⟩ := S.fc_reads hfc
    have hax := rmw_pred_ge hc hrf (S.claim_w ha)
      ((S.claim_loc ha).trans ((hc.rf_wf hrf).2.2.1.trans (S.claim_loc (Or.inl hfc))).symm) hac
    rcases hx with hinit | ⟨p, hf⟩
    · subst x
      rcases hax with rfl | h
      · exact (S.init_not_claim hc ha).elim
      · exact (S.not_mo_init hc h).elim
    · have hp := S.free_claim hf
      obtain ⟨g, hreg, hgx⟩ := S.free_reads hf
      have m1 := hc.rmw_mo (S.reg_reads hreg) (S.reg_w hreg)
      have m2 := hc.rmw_mo hgx (S.free_w hf)
      have m3 := hc.rmw_mo hrf (S.claim_w (Or.inl hfc))
      refine ⟨p, hp, hc.mo_trans m1 (hc.mo_trans m2 m3), ?_, Or.inr ⟨x, hf, hrf⟩⟩
      rcases S.claim_total hc ha hp with heq | h | h
      · exact Or.inl heq
      · exact Or.inr h
      · rcases hax with rfl | hx
        · exact (S.free_not_claim hf ha).elim
        · exact (S.no_claim_after_reg hc hreg hgx ha h hx).elim
  · obtain ⟨p, hso⟩ := S.steal_of hst
    have hp := (S.stealOf_claims hso).1
    obtain ⟨x, _, _, hrf, _, _, _, _, hcase⟩ := S.steal_ev hso
    refine ⟨p, hp, S.mo_of_stealOf hc hso, ?_, Or.inl hso⟩
    rcases S.claim_total hc ha hp with heq | h | h
    · exact Or.inl heq
    · exact Or.inr h
    · rcases hcase with ⟨rfl, _⟩ | ⟨hreg, _⟩
      · exact (rmw_adjacent hc hrf h hac).elim
      · exact (S.no_claim_after_reg hc hreg hrf ha h hac).elim

/-- No third claim lies between the replaced claim and its successful P4. -/
theorem no_claim_between_steal (hc : X.Consistent) {a c p : Ev}
    (hs : S.stealOf a c) (hp : S.isClaim p) (hap : X.mo a p)
    (hpc : X.mo p c) : False := by
  obtain ⟨x, _, _, hrf, _, _, _, _, hcase⟩ := S.steal_ev hs
  rcases hcase with ⟨rfl, _⟩ | ⟨hreg, _⟩
  · exact rmw_adjacent hc hrf hap hpc
  · exact S.no_claim_after_reg hc hreg hrf hp hap hpc

/-- A slot action has one owning claim, by SL1 and hb irreflexivity. -/
theorem action_owner (hc : X.Consistent) {c d e : Ev}
    (hcact : S.act c e) (hdact : S.act d e) : c = d := by
  have hcC := S.act_claim hcact
  have hdC := S.act_claim hdact
  rcases S.claim_total hc hcC hdC with heq | h | h
  · exact heq
  · exact (hc.hb_irrefl e (S.acts_ordered hc hcC hdC h hcact hdact)).elim
  · exact (hc.hb_irrefl e (S.acts_ordered hc hdC hcC h hdact hcact)).elim
end SlotProtocol

variable {S : SlotProtocol X Lv} (P : PinProtocol X S)

/-- Source order in `ParticipantSlot::steal` and its callers. These fields
assert no next-writer or chain-provenance conclusion. The clear is after
successful P5; callers cannot use/release a stolen registration until
`steal()` returns, after P6 (`participant.rs:324-347`, `cache/rotation.rs`
reap_owner/reap_pinner and `cache/mod.rs` reap_dead_participant). -/
structure ReaperPinFacts where
  clear_registered : ∀ {c k}, P.clearOf c k → ∃ g, S.regOf c g ∧ X.sb g k
  clear_before_pin : ∀ {c k w}, P.clearOf c k → P.w2Of c w → X.sb k w
  steal_pin_clear : ∀ {c w}, S.isSteal c → P.w2Of c w →
    ∃ k, P.clearOf c k ∧ X.sb k w
  steal_free_clear : ∀ {c x}, S.isSteal c → S.freeOf c x →
    ∃ k, P.clearOf c k ∧ X.sb k x
  /-- W7, including UnpinOnExit cleanup, follows the W2 it closes. -/
  unpin_after : ∀ {w u}, P.unpinOf w u → X.sb w u

namespace PinProtocol
variable (F : ReaperPinFacts P)
include F

/-- A reaper stopped before successful P5 cannot execute P6. Unlike a
witness's clearOf definition, this follows for every source realization. -/
theorem no_clear_before_registration {c : Ev}
    (hstop : ∀ g, ¬ S.regOf c g) : ∀ k, ¬ P.clearOf c k := by
  intro k hk
  obtain ⟨g, hg, _⟩ := F.clear_registered hk
  exact hstop g hg

/-- Any non-announce write by a stealing registration is its P6 or follows
its P6: W2 follows the successful steal return, and W7 follows W2. -/
theorem steal_write_has_clear (hc : X.Consistent) {c N : Ev}
    (hs : S.isSteal c) (ha : S.act c N) (hN : P.nonAnn N) :
    ∃ k, P.clearOf c k ∧ (k = N ∨ X.hb k N) := by
  rcases hN with ⟨d, hw⟩ | ⟨w, hu⟩ | ⟨d, hk⟩
  · have heq := S.action_owner hc (P.w2_act hw) ha
    subst d
    obtain ⟨k, hk, hkw⟩ := F.steal_pin_clear hs hw
    exact ⟨k, hk, Or.inr (hb_of_sb hkw)⟩
  · obtain ⟨d, hw, hau⟩ := P.unpin_w2 hu
    have heq := S.action_owner hc hau ha
    subst d
    obtain ⟨k, hk, hkw⟩ := F.steal_pin_clear hs hw
    exact ⟨k, hk, Or.inr (hb_trans (hb_of_sb hkw) (hb_of_sb (F.unpin_after hu)))⟩
  · have heq := S.action_owner hc (P.clear_act hk) ha
    exact ⟨N, heq ▸ hk, Or.inl rfl⟩

/-- Without an earlier clear, later claims descend only by steals. A P1
would need a preceding P3, and the stealing predecessor had to clear
before releasing. Well-founded induction follows actual RMW predecessors. -/
theorem steal_chain_without_clear (hc : X.Consistent) {c : Ev}
    (hcst : S.isSteal c) (d : Ev) (hd : S.isClaim d) (hcd : X.mo c d)
    (quiet : ∀ a k, (a = c ∨ X.mo c a) → X.mo a d → ¬ P.clearOf a k) :
    Relation.TransGen S.stealOf c d := by
  induction d using hc.mo_wellFounded.induction with
  | h d ih =>
    obtain ⟨a, ha, had, hca, hstep⟩ := S.claim_predecessor hc (Or.inr hcst) hd hcd
    have earlier : a = c ∨ Relation.TransGen S.stealOf c a := by
      rcases hca with heq | hca
      · exact Or.inl heq.symm
      · exact Or.inr (ih a had ha hca (fun p k hcp hpa => quiet p k hcp (hc.mo_trans hpa had)))
    rcases hstep with hsteal | ⟨x, hfree, _⟩
    · rcases earlier with rfl | earlier
      · exact .single hsteal
      · exact .tail earlier hsteal
    · have hsta : S.isSteal a := by
        rcases earlier with rfl | earlier
        · exact hcst
        · cases earlier with
          | single h => exact (S.stealOf_claims h).2
          | tail _ h => exact (S.stealOf_claims h).2
      obtain ⟨k, hk, _⟩ := F.steal_free_clear hsta hfree
      exact (quiet a k (hca.imp Eq.symm id) had hk).elim

/-- **PN2, conditional provenance.** The original pass has no W7, its
first reaper c stops before P6, and N is the next non-announce pin write
(if any). Then N is P6 of a later steal-chain descendant of c. Further
reapers may also stop: there is no direct-successor or progress premise. -/
theorem next_clear_descends (hc : X.Consistent) {G w c N : Ev}
    (hw : P.w2Of G w) (hdead : ∀ u, ¬ P.unpinOf w u)
    (hsteal : S.stealOf G c) (hstop : ∀ k, ¬ P.clearOf c k)
    (hN : P.nonAnn N) (hwN : X.mo w N)
    (hnext : ∀ k, P.nonAnn k → X.mo w k → X.mo k N → False) :
    ∃ d, Relation.TransGen S.stealOf c d ∧ P.clearOf d N := by
  obtain ⟨d, hd, hdN, hNW, hNl, _⟩ := P.nonAnn_facts hN
  have hG := S.act_claim (P.w2_act hw)
  have hGc := S.mo_of_stealOf hc hsteal
  have hcst := (S.stealOf_claims hsteal).2
  have hGd : X.mo G d := by
    rcases S.claim_total hc hG hd with heq | h | h
    · subst d
      rcases S.act_total (P.w2_act hw) hdN with heq | hs | hs
      · exact (hc.mo_irrefl w (heq ▸ hwN)).elim
      · obtain ⟨u, hu, _⟩ := P.pass_exit hw hdN hN hs
        exact (hdead u hu).elim
      · exact (hc.mo_irrefl w (hc.mo_trans hwN
          (coh_ww_at hc hNW hNl (P.w2_w hw) (P.w2_loc hw) (hb_of_sb hs)))).elim
    · exact h
    · exact (hc.mo_irrefl w (hc.mo_trans hwN
        (coh_ww_at hc hNW hNl (P.w2_w hw) (P.w2_loc hw)
          (S.acts_ordered hc hd hG h hdN (P.w2_act hw))))).elim
  have hcd : X.mo c d := by
    rcases S.claim_total hc (Or.inr hcst) hd with heq | h | h
    · subst d
      obtain ⟨k, hk, _⟩ := P.steal_write_has_clear F hc hcst hdN hN
      exact (hstop k hk).elim
    · exact h
    · exact (S.no_claim_between_steal hc hsteal hd hGd h).elim
  have quiet : ∀ a k, (a = c ∨ X.mo c a) → X.mo a d → ¬ P.clearOf a k := by
    intro a k hca had hk
    have hGa : X.mo G a := hca.elim (fun h => h ▸ hGc) (hc.mo_trans hGc)
    have ha := S.act_claim (P.clear_act hk)
    have hwk := S.acts_ordered hc hG ha hGa (P.w2_act hw) (P.clear_act hk)
    have hkN := S.acts_ordered hc ha hd had (P.clear_act hk) hdN
    exact hnext k (Or.inr (Or.inr ⟨a, hk⟩))
      (coh_ww_at hc (P.w2_w hw) (P.w2_loc hw) (P.clear_w hk) (P.clear_loc hk) hwk)
      (coh_ww_at hc (P.clear_w hk) (P.clear_loc hk) hNW hNl hkN)
  have chain := P.steal_chain_without_clear F hc hcst d hd hcd quiet
  have hdst : S.isSteal d := by
    cases chain with
    | single h => exact (S.stealOf_claims h).2
    | tail _ h => exact (S.stealOf_claims h).2
  obtain ⟨k, hk, hkN⟩ := P.steal_write_has_clear F hc hdst hdN hN
  refine ⟨d, chain, ?_⟩
  rcases hkN with rfl | hkN
  · exact hk
  · have hwk := S.acts_ordered hc hG hd hGd (P.w2_act hw) (P.clear_act hk)
    exact (hnext k (Or.inr (Or.inr ⟨d, hk⟩))
      (coh_ww_at hc (P.w2_w hw) (P.w2_loc hw) (P.clear_w hk) (P.clear_loc hk) hwk)
      (coh_ww_at hc (P.clear_w hk) (P.clear_loc hk) hNW hNl hkN)).elim
end PinProtocol
end Sgc.Execution
