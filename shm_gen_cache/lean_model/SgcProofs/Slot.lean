import SgcProofs.Model

/-!
# SL1, slot serialisation

One participant slot. Its `state` word is written only by compare-exchanges:
a *claim* (P1 from FREE, or the steal P4 of a dead claim), the *register*
that completes it (P2 or P5) and the *free* that releases it (P3). A
*registration* is identified with its claim.

`Liveness` is assumption A3, the contract of the liveness backend: a "dead"
verdict is ordered after everything the matching thread incarnation did,
provided that incarnation was observed before the verdict. Later reuse
of its backend id is outside the verdict's scope.

SL1 in `docs/invariants.html` has three parts, proved here as:

* (a), the shape of `mo(state)`: `claim_total`, `handoff_free`,
  `handoff_steal`, `no_claim_after_reg`, `mo_of_stealOf`;
* (b) `serial`: everything done on behalf of a registration happens before
  the claim of every later registration of the slot (`hb_later_claim`,
  `acts_ordered`);
* (c) `ender_before`: every state written after a registration's register
  is at or after the write that ended it, its P3 or a P4 stealing it.

That a live registrant is never displaced is RB1, which needs A9 and is
not modelled. The delicate part is a steal from a registered claim
(`handoff_steal`): the stealer reads the start time with a relaxed load, and the proof shows that a
successful P4 nonetheless paired the state with that claim's own start time,
so the verdict was about the claim it replaced.
-/

namespace Sgc
namespace Execution

variable {Ev Loc : Type}

/-- **Assumption A3**, the liveness backend (dead-verdict soundness). Threads
are numbered. Its converse, that a running thread is never reported dead
(A9), is not modelled: only RB1 and RB2 need it. -/
structure Liveness (X : Execution Ev Loc) where
  /-- The thread incarnation that performs an event. -/
  thrOf : Ev → Nat
  /-- A thread's id as the backend sees it (it can be reused). -/
  pidOf : Nat → Nat
  /-- A thread's start time. -/
  startOf : Nat → Nat
  /-- `dead vd pid st`: at `vd` the backend answered "dead" for the identity
  `(pid, st)`; `st = 0` asks about the id alone. -/
  dead : Ev → Nat → Nat → Prop
  /-- Every action of a matching incarnation observed before the verdict
  happens before it. The observation is a reads-from chain from that
  incarnation to a read sequenced before the verdict: directly from an
  INITIALIZING claim, or through its register CAS. It establishes that the
  incarnation already existed without assuming the `hb` edge the verdict
  provides. Later incarnations reusing the id are outside this scope. -/
  sound : ∀ {vd pid st}, dead vd pid st → ∀ θ, pidOf θ = pid →
    (st = 0 ∨ startOf θ = st) →
    (∃ w r, thrOf w = θ ∧ Relation.TransGen X.rf w r ∧ X.sb r vd) →
    ∀ e, thrOf e = θ → X.hb e vd

/-- The events on one slot's `state` and start-time words. -/
structure SlotProtocol (X : Execution Ev Loc) (Lv : Liveness X) where
  /-- The `state` word. -/
  st : Loc
  /-- The start-time word (`thread_disambiguation`). -/
  tdis : Loc
  init : Ev
  tInit : Ev
  /-- P1: a claim of a FREE slot. -/
  isFreeClaim : Ev → Prop
  /-- P4: a steal of a dead claim. -/
  isSteal : Ev → Prop
  /-- `stealOf c₀ c`: the steal `c` replaces the claim `c₀`. -/
  stealOf : Ev → Ev → Prop
  /-- `regOf c g`: `g` is the register (P2 or P5) of claim `c`. -/
  regOf : Ev → Ev → Prop
  /-- `freeOf c x`: `x` is the release (P3) of claim `c`. -/
  freeOf : Ev → Ev → Prop
  /-- `act c e`: `e` is performed on behalf of the registration `c`. -/
  act : Ev → Ev → Prop
  /-- `tOf c s`: `s` is the start-time store (T1 or T2) of claim `c`. -/
  tOf : Ev → Ev → Prop
  -- The state word.
  init_w : X.isW init
  init_loc : X.loc init = some st
  /-- Assumption A1. -/
  init_first : ∀ {e}, X.isW e → X.loc e = some st → e ≠ init → X.hb init e
  writes : ∀ {e}, X.isW e → X.loc e = some st →
    e = init ∨ (isFreeClaim e ∨ isSteal e) ∨ (∃ c, regOf c e) ∨ (∃ c, freeOf c e)
  claim_w : ∀ {c}, isFreeClaim c ∨ isSteal c → X.isW c
  claim_loc : ∀ {c}, isFreeClaim c ∨ isSteal c → X.loc c = some st
  /-- P1 succeeds with acquire. -/
  fc_isAcq : ∀ {c}, isFreeClaim c → X.isAcq c
  /-- P1 reads FREE: the initial state or a P3. -/
  fc_reads : ∀ {c}, isFreeClaim c → ∃ w, X.rf w c ∧ (w = init ∨ ∃ c₀, freeOf c₀ w)
  /-- P4 succeeds with release. -/
  steal_isRel : ∀ {c}, isSteal c → X.isRel c
  steal_of : ∀ {c}, isSteal c → ∃ c₀, stealOf c₀ c
  stealOf_claims : ∀ {c₀ c}, stealOf c₀ c → (isFreeClaim c₀ ∨ isSteal c₀) ∧ isSteal c
  /-- What `steal()` did before a successful P4 `c` on the word `w`: the
  acquire load P0 `o` of the same word (state words are unique, assumption
  A4), then a verdict `vd` (`is_live`, `is_live_since` returning
  `Ok(false)`). If the word was `INITIALIZING(c₀)` the verdict is about the
  id alone; if it was `REGISTERED(c₀)`, about the id and the start time the
  relaxed load T0 `t` returned (code fact F7). -/
  steal_ev : ∀ {c₀ c}, stealOf c₀ c → ∃ w o vd,
    X.rf w c ∧ X.rf w o ∧ X.isAcq o ∧ X.sb o vd ∧ X.sb vd c ∧
    ((w = c₀ ∧ Lv.dead vd (Lv.pidOf (Lv.thrOf c₀)) 0) ∨
     (regOf c₀ w ∧ ∃ t wt, X.loc t = some tdis ∧ X.rf wt t ∧ X.sb o t ∧ X.sb t c ∧
        Lv.dead vd (Lv.pidOf (Lv.thrOf c₀)) (X.rval t)))
  reg_claim : ∀ {c g}, regOf c g → isFreeClaim c ∨ isSteal c
  reg_w : ∀ {c g}, regOf c g → X.isW g
  reg_loc : ∀ {c g}, regOf c g → X.loc g = some st
  /-- P2 and P5 succeed with release. -/
  reg_isRel : ∀ {c g}, regOf c g → X.isRel g
  /-- The register replaces the claim's own word. -/
  reg_reads : ∀ {c g}, regOf c g → X.rf c g
  reg_not_claim : ∀ {c g}, regOf c g → ¬ (isFreeClaim g ∨ isSteal g)
  reg_owner : ∀ {c c' g}, regOf c g → regOf c' g → c = c'
  free_w : ∀ {c x}, freeOf c x → X.isW x
  free_loc : ∀ {c x}, freeOf c x → X.loc x = some st
  /-- P3 succeeds with release. -/
  free_isRel : ∀ {c x}, freeOf c x → X.isRel x
  /-- P3 replaces the registered word. -/
  free_reads : ∀ {c x}, freeOf c x → ∃ g, regOf c g ∧ X.rf g x
  free_not_claim : ∀ {c x}, freeOf c x → ¬ (isFreeClaim x ∨ isSteal x)
  -- Assumption A2: a registration acts on one thread, and not after P3.
  act_self : ∀ {c}, isFreeClaim c ∨ isSteal c → act c c
  act_sb : ∀ {c e}, act c e → e = c ∨ X.sb c e
  act_claim : ∀ {c e}, act c e → isFreeClaim c ∨ isSteal c
  act_thr : ∀ {c e}, act c e → Lv.thrOf e = Lv.thrOf c
  act_total : ∀ {c e f}, act c e → act c f → e = f ∨ X.sb e f ∨ X.sb f e
  act_before_free : ∀ {c x e}, freeOf c x → act c e → e = x ∨ X.sb e x
  -- The start-time word.
  tInit_w : X.isW tInit
  tInit_loc : X.loc tInit = some tdis
  tInit_first : ∀ {e}, X.isW e → X.loc e = some tdis → e ≠ tInit → X.hb tInit e
  t_writes : ∀ {e}, X.isW e → X.loc e = some tdis → e = tInit ∨ ∃ c, tOf c e
  t_w : ∀ {c s}, tOf c s → X.isW s
  t_loc : ∀ {c s}, tOf c s → X.loc s = some tdis
  t_act : ∀ {c s}, tOf c s → act c s
  /-- The start time is stored before the claim is registered. -/
  t_before_reg : ∀ {c s g}, tOf c s → regOf c g → X.sb s g
  t_unique : ∀ {c s s'}, tOf c s → tOf c s' → s = s'
  t_of_reg : ∀ {c g}, regOf c g → ∃ s, tOf c s
  /-- The claimant stores its own start time, or zero if unknown. -/
  t_val : ∀ {c s}, tOf c s → X.wval s = 0 ∨ X.wval s = Lv.startOf (Lv.thrOf c)

namespace SlotProtocol

variable {X : Execution Ev Loc} {Lv : Liveness X} (S : SlotProtocol X Lv)

/-- A claim: P1 or P4. -/
abbrev isClaim (c : Ev) : Prop := S.isFreeClaim c ∨ S.isSteal c

theorem not_mo_init (hc : X.Consistent) {e : Ev} (h : X.mo e S.init) : False :=
  not_mo_first hc S.init_w (fun he hl => S.init_first he (hl.trans S.init_loc)) h

/-- A P3 releases a claim. -/
theorem free_claim {c x : Ev} (hf : S.freeOf c x) : S.isClaim c :=
  let ⟨_, hreg, _⟩ := S.free_reads hf; S.reg_claim hreg

/-- The initialisation is not a claim: claims read an earlier write. -/
theorem init_not_claim (hc : X.Consistent) : ¬ S.isClaim S.init := by
  intro h
  obtain ⟨w, hrf⟩ : ∃ w, X.rf w S.init := by
    rcases h with hfc | hst
    · obtain ⟨w, hrf, _⟩ := S.fc_reads hfc; exact ⟨w, hrf⟩
    · obtain ⟨c₀, hso⟩ := S.steal_of hst
      obtain ⟨w, _, _, hrf, _⟩ := S.steal_ev hso; exact ⟨w, hrf⟩
  exact S.not_mo_init hc (hc.rmw_mo hrf S.init_w)

theorem not_mo_tInit (hc : X.Consistent) {e : Ev} (h : X.mo e S.tInit) : False :=
  not_mo_first hc S.tInit_w (fun he hl => S.tInit_first he (hl.trans S.tInit_loc)) h

theorem claim_total (hc : X.Consistent) {a b : Ev} (ha : S.isClaim a) (hb' : S.isClaim b) :
    a = b ∨ X.mo a b ∨ X.mo b a :=
  hc.mo_total (S.claim_w ha) (S.claim_w hb') ((S.claim_loc ha).trans (S.claim_loc hb').symm)
    (some_ne_none (S.claim_loc ha))

/-- Every state write but the first is a compare-exchange: it reads. -/
theorem st_reads {e : Ev} (hW : X.isW e) (hl : X.loc e = some S.st) (hne : e ≠ S.init) :
    ∃ w, X.rf w e := by
  rcases S.writes hW hl with h | (h | h) | ⟨c, h⟩ | ⟨c, h⟩
  · exact absurd h hne
  · obtain ⟨w, hrf, _⟩ := S.fc_reads h; exact ⟨w, hrf⟩
  · obtain ⟨c₀, hso⟩ := S.steal_of h
    obtain ⟨w, _, _, hrf, _⟩ := S.steal_ev hso
    exact ⟨w, hrf⟩
  · exact ⟨c, S.reg_reads h⟩
  · obtain ⟨g, _, hrf⟩ := S.free_reads h; exact ⟨g, hrf⟩

/-- Every state write at or after a write `R` lies in `R`'s release
sequence, because all of them are read-modify-writes. -/
theorem rs_of_ge (hc : X.Consistent) {R : Ev} (hRW : X.isW R) (hRl : X.loc R = some S.st)
    (w : Ev) : (R = w ∨ X.mo R w) → X.rs R w := by
  refine hc.mo_wellFounded.induction (C := fun w => (R = w ∨ X.mo R w) → X.rs R w) w ?_
  intro w ih h
  rcases h with heq | hRw
  · subst heq; exact rs.refl _
  · have ⟨_, hwW, hl, _⟩ := hc.mo_wf hRw
    have hwl : X.loc w = some S.st := hl.symm.trans hRl
    have hne : w ≠ S.init := fun h => by subst h; exact S.not_mo_init hc hRw
    obtain ⟨w', hrf⟩ := S.st_reads hwW hwl hne
    have ⟨_, _, hlw', _⟩ := hc.rf_wf hrf
    rcases rmw_pred_ge hc hrf hRW (hRl.trans (hlw'.trans hwl).symm) hRw with heq | h
    · subst heq; exact rs.rmw (rs.refl _) hrf hwW
    · exact rs.rmw (ih w' (hc.rmw_mo hrf hwW) (Or.inr h)) hrf hwW

/-- REL-ACQ on the state word: an acquire read of any state at or after a
release write synchronises with it. -/
theorem hb_release_acq (hc : X.Consistent) {R w q : Ev}
    (hRW : X.isW R) (hRl : X.loc R = some S.st) (hRrel : X.isRel R)
    (hrf : X.rf w q) (hq : X.isAcq q) (h : R = w ∨ X.mo R w) : X.hb R q :=
  hb_of_sw (sw.relAcq hRrel (S.rs_of_ge hc hRW hRl w h) hrf hq)

/-- A release write on the state word happens before every later claim. -/
theorem hb_later_claim (hc : X.Consistent) {R c : Ev}
    (hRW : X.isW R) (hRl : X.loc R = some S.st) (hRrel : X.isRel R)
    (hcC : S.isClaim c) (hRc : X.mo R c) : X.hb R c := by
  rcases hcC with hfc | hst
  · obtain ⟨w, hrf, _⟩ := S.fc_reads hfc
    have ⟨_, _, hlw, _⟩ := hc.rf_wf hrf
    exact S.hb_release_acq hc hRW hRl hRrel hrf (S.fc_isAcq hfc)
      (rmw_pred_ge hc hrf hRW (hRl.trans (hlw.trans (S.claim_loc (Or.inl hfc))).symm) hRc)
  · obtain ⟨c₀, hso⟩ := S.steal_of hst
    obtain ⟨w, o, vd, hrf, hrfo, hoacq, hov, hvc, _⟩ := S.steal_ev hso
    have ⟨_, _, hlw, _⟩ := hc.rf_wf hrf
    exact hb_trans
      (S.hb_release_acq hc hRW hRl hRrel hrfo hoacq
        (rmw_pred_ge hc hrf hRW (hRl.trans (hlw.trans (S.claim_loc (Or.inr hst))).symm) hRc))
      (hb_trans (hb_of_sb hov) (hb_of_sb hvc))

/-- **SL1(b), P3 case.** A registration that ends in P3 hands over to the P1
that reads it. -/
theorem handoff_free {c₀ x c e : Ev} (hf : S.freeOf c₀ x) (hrf : X.rf x c)
    (hfc : S.isFreeClaim c) (he : S.act c₀ e) : X.hb e c := by
  have hsw : X.hb x c := hb_of_sw (sw.relAcq (S.free_isRel hf) (rs.refl x) hrf (S.fc_isAcq hfc))
  exact sbr_hb (S.act_before_free hf he) hsw

/-- The steal of a claim follows it in `mo`. -/
theorem mo_of_stealOf (hc : X.Consistent) {c₀ c : Ev} (hso : S.stealOf c₀ c) : X.mo c₀ c := by
  obtain ⟨w, _, _, hwc, _, _, _, _, hcase⟩ := S.steal_ev hso
  have mwc : X.mo w c := hc.rmw_mo hwc (S.claim_w (Or.inr (S.stealOf_claims hso).2))
  rcases hcase with ⟨heq, _⟩ | ⟨hreg, _⟩
  · subst heq; exact mwc
  · exact hc.mo_trans (hc.rmw_mo (S.reg_reads hreg) (S.reg_w hreg)) mwc

/-- No claim lies, in `mo`, strictly after a claim `c₀` and before a write
that reads `c₀`'s register: the register and that write are adjacent to
what they read. -/
theorem no_claim_after_reg (hc : X.Consistent) {c₀ g x a : Ev} (hreg : S.regOf c₀ g)
    (hgx : X.rf g x) (ha : S.isClaim a) (h₀ : X.mo c₀ a) (hax : X.mo a x) : False := by
  rcases rmw_pred_ge hc hgx (S.claim_w ha) ((S.claim_loc ha).trans (S.reg_loc hreg).symm) hax
    with rfl | h
  · exact S.reg_not_claim hreg ha
  · exact rmw_adjacent hc (S.reg_reads hreg) h₀ h

/-- **SL1(b), steal case.** A registration that is stolen hands over to the
steal, given SL1(b) for the claims before it. -/
theorem handoff_steal (hc : X.Consistent) {c₀ c : Ev} (hso : S.stealOf c₀ c)
    (ih : ∀ a, S.isClaim a → X.mo a c₀ → ∀ e, S.act a e → X.hb e c₀)
    {e : Ev} (he : S.act c₀ e) : X.hb e c := by
  obtain ⟨hc₀, hcst⟩ := S.stealOf_claims hso
  obtain ⟨w, o, vd, hwc, hwo, hoacq, hov, hVc, hcase⟩ := S.steal_ev hso
  rcases hcase with ⟨heq, hdead⟩ | ⟨hreg, t, wt, htl, hwt, hot, htc, hdead⟩
  · -- From `INITIALIZING(c₀)`: the verdict is about the id alone.
    subst w
    exact hb_trans (Lv.sound hdead _ rfl (Or.inl rfl)
      ⟨c₀, o, rfl, .single hwo, hov⟩ e (S.act_thr he)) (hb_of_sb hVc)
  · -- From `REGISTERED(c₀)`: show the start time `t` read is `c₀`'s own.
    obtain ⟨s₀, hs₀⟩ := S.t_of_reg hreg
    have hwt_eq : wt = s₀ := by
      -- Not an earlier one: the opening load acquired the register.
      have hs₀t : X.hb s₀ t :=
        hb_trans (hb_of_sb (S.t_before_reg hs₀ hreg))
          (hb_trans (hb_of_sw (sw.relAcq (S.reg_isRel hreg) (rs.refl w) hwo hoacq))
            (hb_of_sb hot))
      rcases coh_wr hc (S.t_w hs₀) ((S.t_loc hs₀).trans htl.symm) hs₀t hwt with heq | hmo
      · exact heq
      · exfalso
        have ⟨_, hwtW, hl, _⟩ := hc.mo_wf hmo
        rcases S.t_writes hwtW (hl.symm.trans (S.t_loc hs₀)) with hinit | ⟨cs, hcs⟩
        · subst hinit; exact S.not_mo_tInit hc hmo
        · have hcsC := S.act_claim (S.t_act hcs)
          -- Not the steal's own or a later claim's: `t` happens before it.
          have late : (cs = c ∨ X.mo c cs) → False := by
            intro h
            have htcs : X.hb t cs := by
              rcases h with heq | hm
              · subst heq; exact hb_of_sb htc
              · exact hb_trans (hb_of_sb htc)
                  (S.hb_later_claim hc (S.claim_w (Or.inr hcst)) (S.claim_loc (Or.inr hcst))
                    (S.steal_isRel hcst) hcsC hm)
            have htwt : X.hb t wt := hb_sbr htcs (S.act_sb (S.t_act hcs))
            exact hc.coherence htwt (cob_of_rf hwt)
          rcases S.claim_total hc hcsC hc₀ with heq | h | h
          · -- The same claim: the same store.
            subst heq
            have := S.t_unique hcs hs₀
            subst this
            exact hc.mo_irrefl _ hmo
          · -- An earlier claim: its store precedes `s₀` (SL1 for earlier claims).
            have h₁ : X.hb wt c₀ := ih cs hcsC h wt (S.t_act hcs)
            have h₂ : X.hb wt s₀ := hb_sbr h₁ (S.act_sb (S.t_act hs₀))
            exact hc.mo_irrefl _
              (hc.mo_trans hmo
                (coh_ww_at hc hwtW (hl.symm.trans (S.t_loc hs₀)) (S.t_w hs₀) (S.t_loc hs₀) h₂))
          · rcases S.claim_total hc hcsC (Or.inr hcst) with heq | h' | h'
            · exact late (Or.inl heq)
            · -- A claim strictly between `c₀` and `c`: impossible, the
              -- register and the steal are adjacent to what they read.
              exact S.no_claim_after_reg hc hreg hwc hcsC h h'
            · exact late (Or.inr h')
    have hst : X.rval t = 0 ∨ Lv.startOf (Lv.thrOf c₀) = X.rval t := by
      rw [hc.rf_val hwt, hwt_eq]
      rcases S.t_val hs₀ with h | h
      · exact Or.inl h
      · exact Or.inr h.symm
    exact hb_trans (Lv.sound hdead _ rfl hst
      ⟨c₀, o, rfl, .tail (.single (S.reg_reads hreg)) hwo, hov⟩ e (S.act_thr he))
      (hb_of_sb hVc)

/-- **SL1(b).** Everything done on behalf of a registration happens before
the claim of every later registration of the slot. -/
theorem serial (hc : X.Consistent) (c : Ev) :
    S.isClaim c → ∀ a, S.isClaim a → X.mo a c → ∀ e, S.act a e → X.hb e c := by
  refine hc.mo_wellFounded.induction
    (C := fun c => S.isClaim c → ∀ a, S.isClaim a → X.mo a c → ∀ e, S.act a e → X.hb e c) c ?_
  intro c ih hcC a ha hac e he
  -- The claim `c₀` that `c` follows directly.
  have key : ∃ c₀, S.isClaim c₀ ∧ X.mo c₀ c ∧ (a = c₀ ∨ X.mo a c₀) ∧
      (∀ e, S.act c₀ e → X.hb e c) := by
    rcases hcC with hfc | hst
    · obtain ⟨w, hrf, hw⟩ := S.fc_reads hfc
      have haw := rmw_pred_ge hc hrf (S.claim_w ha)
        ((S.claim_loc ha).trans ((hc.rf_wf hrf).2.2.1.trans (S.claim_loc (Or.inl hfc))).symm) hac
      rcases hw with hinit | ⟨c₀, hf⟩
      · -- `c` read the initial state: no claim precedes it.
        subst hinit
        rcases haw with rfl | h
        · exact (S.init_not_claim hc ha).elim
        · exact (S.not_mo_init hc h).elim
      · have hc₀ := S.free_claim hf
        obtain ⟨g, hreg, hgx⟩ := S.free_reads hf
        have m₁ : X.mo c₀ g := hc.rmw_mo (S.reg_reads hreg) (S.reg_w hreg)
        have m₂ : X.mo g w := hc.rmw_mo hgx (S.free_w hf)
        have m₃ : X.mo w c := hc.rmw_mo hrf (S.claim_w (Or.inl hfc))
        refine ⟨c₀, hc₀, hc.mo_trans m₁ (hc.mo_trans m₂ m₃), ?_,
          fun e he => S.handoff_free hf hrf hfc he⟩
        rcases S.claim_total hc ha hc₀ with heq | h | h
        · exact Or.inl heq
        · exact Or.inr h
        · exfalso
          rcases haw with rfl | h₃
          · exact S.free_not_claim hf ha
          · exact S.no_claim_after_reg hc hreg hgx ha h h₃
    · obtain ⟨c₀, hso⟩ := S.steal_of hst
      have hc₀ := (S.stealOf_claims hso).1
      obtain ⟨w, o, vd, hwc, _, _, _, _, hcase⟩ := S.steal_ev hso
      have hmo : X.mo c₀ c ∧ (a = c₀ ∨ X.mo a c₀) := by
        refine ⟨S.mo_of_stealOf hc hso, ?_⟩
        rcases S.claim_total hc ha hc₀ with heq | h | h
        · exact Or.inl heq
        · exact Or.inr h
        · exfalso
          rcases hcase with ⟨rfl, _⟩ | ⟨hreg, _⟩
          · exact rmw_adjacent hc hwc h hac
          · exact S.no_claim_after_reg hc hreg hwc ha h hac
      exact ⟨c₀, hc₀, hmo.1, hmo.2,
        fun e he => S.handoff_steal hc hso (ih c₀ hmo.1 hc₀) he⟩
  obtain ⟨c₀, hc₀, hc₀c, hac₀, hand⟩ := key
  rcases hac₀ with heq | h
  · subst heq; exact hand e he
  · exact hb_trans (ih c₀ hc₀c hc₀ a ha h e he) (hand c₀ (S.act_self hc₀))

/-- Actions of an earlier registration happen before actions of a later
one. -/
theorem acts_ordered (hc : X.Consistent) {a c e f : Ev}
    (ha : S.isClaim a) (hcC : S.isClaim c) (hac : X.mo a c)
    (he : S.act a e) (hf : S.act c f) : X.hb e f :=
  hb_sbr (S.serial hc c hcC a ha hac e he) (S.act_sb hf)

/-- **SL1(c).** Every state after a registered word is at or after what
ended that registration: its P3 or the steal that replaced it. -/
theorem ender_before (hc : X.Consistent) {c g : Ev} (hreg : S.regOf c g) (w : Ev) :
    X.mo g w → ∃ E, (S.freeOf c E ∨ S.stealOf c E) ∧ (E = w ∨ X.mo E w) := by
  refine hc.mo_wellFounded.induction
    (C := fun w => X.mo g w → ∃ E, (S.freeOf c E ∨ S.stealOf c E) ∧ (E = w ∨ X.mo E w)) w ?_
  intro w ih hgw
  have ⟨hgW, hwW, hl, _⟩ := hc.mo_wf hgw
  have hwl : X.loc w = some S.st := hl.symm.trans (S.reg_loc hreg)
  have hne : w ≠ S.init := fun h => by subst h; exact S.not_mo_init hc hgw
  obtain ⟨w', hrf⟩ := S.st_reads hwW hwl hne
  have ⟨_, _, hlw', _⟩ := hc.rf_wf hrf
  rcases rmw_pred_ge hc hrf hgW (hl.trans hlw'.symm) hgw with heq | h
  · -- `w` reads `g` itself: it is the ender.
    subst heq
    rcases S.writes hwW hwl with hinit | (hfc | hst) | ⟨c₁, hr₁⟩ | ⟨c₁, hf₁⟩
    · exact absurd hinit hne
    · -- A P1 reads FREE, not a registered word.
      exfalso
      obtain ⟨w₀, hrf₀, h₀⟩ := S.fc_reads hfc
      have hgw₀ := hc.rf_unique hrf hrf₀
      rcases h₀ with hinit | ⟨c₂, hf₂⟩
      · rw [hinit] at hgw₀
        rw [hgw₀] at hreg
        exact S.not_mo_init hc (hc.rmw_mo (S.reg_reads hreg) (S.reg_w hreg))
      · rw [← hgw₀] at hf₂
        obtain ⟨g₂, hreg₂, hrf₂⟩ := S.free_reads hf₂
        have hcg := hc.rf_unique (S.reg_reads hreg) hrf₂
        rw [← hcg] at hreg₂
        exact S.reg_not_claim hreg₂ (S.reg_claim hreg)
    · obtain ⟨c₁, hso⟩ := S.steal_of hst
      obtain ⟨w₀, _, _, hw₀, _, _, _, _, hcase⟩ := S.steal_ev hso
      have hgw₀ := hc.rf_unique hrf hw₀
      rcases hcase with ⟨heq, _⟩ | ⟨hreg₁, _⟩
      · exfalso
        rw [← hgw₀] at heq
        rw [heq] at hreg
        exact S.reg_not_claim hreg (S.stealOf_claims hso).1
      · rw [← hgw₀] at hreg₁
        have := S.reg_owner hreg hreg₁
        subst this
        exact ⟨w, Or.inr hso, Or.inl rfl⟩
    · exfalso
      have hgc := hc.rf_unique hrf (S.reg_reads hr₁)
      rw [hgc] at hreg
      exact S.reg_not_claim hreg (S.reg_claim hr₁)
    · obtain ⟨g₁, hreg₁, hrf₁⟩ := S.free_reads hf₁
      have hgg := hc.rf_unique hrf hrf₁
      rw [← hgg] at hreg₁
      have := S.reg_owner hreg hreg₁
      subst this
      exact ⟨w, Or.inl hf₁, Or.inl rfl⟩
  · obtain ⟨E, hE, hle⟩ := ih w' (hc.rmw_mo hrf hwW) h
    refine ⟨E, hE, Or.inr ?_⟩
    rcases hle with heq | h'
    · rw [heq]; exact hc.rmw_mo hrf hwW
    · exact hc.mo_trans h' (hc.rmw_mo hrf hwW)

end SlotProtocol
end Execution
end Sgc
