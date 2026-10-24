import SgcProofs.Promotion

/-!
# RD1: rotation scan branches and the certificate guard

rotation.rs:133-185 updates a single Boolean after *every* R3, including
loads followed by pause/reap/retry. A pin of the current epoch marks it
false and exits that slot's wait; an older pin may pause, be reaped and
reload, or cause an error that bypasses R3C. The flag is never reset within
the outer loop. This file derives CertScan from that source control flow;
RawReadinessProtocol therefore does not assume the scan's nonmatching-pin
conclusion. A7 supplies one completed, nonempty scan per configured slot.
-/
namespace Sgc.Execution

/-- A4 keeps the waiting flag out of the epoch, so wrapping_add(2) is an
ordinary addition for decoded pins. -/
def scanPasses (epoch pin : Nat) : Bool := pin == 0 || decide (epoch < pin + 2)

def updateReady (epoch pin : Nat) (ready : Bool) : Bool := ready && !(pin == epoch)

/-- What the inner loop does after a load that cannot exit immediately. -/
inductive WaitOutcome where
  | pauseContinue | reapSucceeded | timeout | backendError
  deriving DecidableEq

inductive ScanDecision where
  | nextSlot | reload | abort
  deriving DecidableEq

def scanDecision (epoch pin : Nat) (wait : WaitOutcome) : ScanDecision :=
  if scanPasses epoch pin then .nextSlot
  else match wait with
    | .pauseContinue | .reapSucceeded => .reload
    | .timeout | .backendError => .abort

theorem current_pin_disables (epoch : Nat) (ready : Bool) (wait : WaitOutcome) :
    updateReady epoch epoch ready = false ∧ scanDecision epoch epoch wait = .nextSlot := by
  simp [updateReady, scanDecision, scanPasses]

/-- Older pins can reload after either wait progress or successful reaping;
the timeout/backend-error branches return before R3C. -/
theorem older_pin_branches {epoch pin : Nat} (hpin : pin ≠ 0) (hold : pin + 2 ≤ epoch) :
    scanDecision epoch pin .pauseContinue = .reload ∧
    scanDecision epoch pin .reapSucceeded = .reload ∧
    scanDecision epoch pin .timeout = .abort ∧
    scanDecision epoch pin .backendError = .abort := by
  have hn : ¬ epoch < pin + 2 := by omega
  simp [scanDecision, scanPasses, hpin, hn]

/-- A completed per-slot scan. `again` is precisely either Continue edge;
there is deliberately no constructor continuing after the abort edge. -/
inductive SlotScan (epoch : Nat) where
  | done (pin : Nat) (passed : scanPasses epoch pin = true)
  | again (pin : Nat) (wait : WaitOutcome)
      (reload : scanDecision epoch pin wait = .reload) (rest : SlotScan epoch)

def SlotScan.values {epoch : Nat} : SlotScan epoch → List Nat
  | .done pin _ => [pin]
  | .again pin _ _ rest => pin :: rest.values

def scanReady (epoch : Nat) : Bool → List Nat → Bool
  | ready, [] => ready
  | ready, pin :: rest => scanReady epoch (updateReady epoch pin ready) rest

theorem scanReady_true (epoch : Nat) (pins : List Nat) (ready : Bool)
    (h : scanReady epoch ready pins = true) :
    ready = true ∧ ∀ pin ∈ pins, pin ≠ epoch := by
  induction pins generalizing ready with
  | nil => exact ⟨h, by simp⟩
  | cons pin rest ih =>
    obtain ⟨hhead, hrest⟩ := ih _ h
    have hh : ready = true ∧ pin ≠ epoch := by simpa [updateReady] using hhead
    exact ⟨hh.1, by intro p hp; rcases List.mem_cons.mp hp with rfl | hp; exact hh.2; exact hrest p hp⟩

variable {Ev Loc : Type} {X : Execution Ev Loc} {C : CacheProtocol X}

/-- One R3 event with its source and ordering. This carries no assertion
about the value relative to the epoch being certified. -/
structure ScanLoad (C : CacheProtocol X) (f c : Ev) where
  slot : C.ι
  read : Ev
  source : Ev
  rf : X.rf source read
  loc : X.loc read = some (C.pins slot).pin
  acquire : X.isAcq read
  afterFence : X.sb f read
  beforeCert : X.sb read c

def ScanLoad.value {f c : Ev} (l : ScanLoad C f c) : Nat :=
  (C.pins l.slot).pinVal (X.rval l.read)

/-- Source scan trace for a successful R3C. The list records all R3s in
program order, including reloads after waits/reaping. `covered` comes from
the completed for-loop over participant_capacity (A7), and `guard` is the
actual `if promotion_ready` branch. `slotRuns` records the wait branches,
so current pins cannot be silently removed by reaping in this model. -/
structure RotationScanTrace (C : CacheProtocol X) (f c : Ev) (epoch : Nat) where
  loads : List (ScanLoad C f c)
  covered : ∀ i, ∃ l ∈ loads, l.slot = i
  slotRuns : ∀ i, ∃ s : SlotScan epoch,
    ∃ selected : List (ScanLoad C f c),
      (∀ l, l ∈ selected ↔ l ∈ loads ∧ l.slot = i) ∧
      s.values = selected.map ScanLoad.value
  guard : scanReady epoch true (loads.map ScanLoad.value) = true

theorem RotationScanTrace.certScan {f c : Ev} {epoch : Nat}
    (t : RotationScanTrace C f c epoch) : CertScan C f c epoch := by
  have h := (scanReady_true epoch _ true t.guard).2
  intro i
  obtain ⟨l, hl, he⟩ := t.covered i
  obtain ⟨slot, read, source, rf, loc, acq, afterF, beforeC⟩ := l
  try dsimp at he
  subst slot
  exact ⟨read, source, rf, loc, acq, afterF, beforeC,
    h _ (List.mem_map.mpr ⟨_, hl, rfl⟩)⟩

/-- The actual R3 branch tests, including failed scans that never certify.
`waitAfter` and `reapAfter` identify events reached from a particular load.
Both are inside the loop after the `if ... { break; }` at rotation.rs:149.
The premises preserve that guard in its Rust u64 form. They do not assume
that the pin differs from the rotation epoch or that readiness is true. -/
structure ScanBranches {Lv : Liveness X} {S : SlotProtocol X Lv}
    (P : PinProtocol X S) where
  epoch : Ev → Nat
  isR3 : Ev → Prop
  waitAfter : Ev → Ev → Prop
  reapAfter : Ev → Ev → Prop
  /-- Masking ROTATION_WAITING makes the decoded pin less than 2^63. -/
  decoded_bound : ∀ {r}, isR3 r → P.pinVal (X.rval r) < 9223372036854775808
  wait_guard : ∀ {r a}, waitAfter r a → isR3 r ∧ X.sb r a ∧
    P.pinVal (X.rval r) ≠ 0 ∧
    ¬ epoch r < (P.pinVal (X.rval r) + 2) % 18446744073709551616
  reap_guard : ∀ {r a}, reapAfter r a → isR3 r ∧ X.sb r a ∧
    P.pinVal (X.rval r) ≠ 0 ∧
    ¬ epoch r < (P.pinVal (X.rval r) + 2) % 18446744073709551616

/-- Every execution-level wait/reap edge is taken only on a sufficiently
old pin. The wrapping addition is proved exact from the decoded mask. -/
theorem scan_action_requires_old_pin {Lv : Liveness X} {S : SlotProtocol X Lv}
    {P : PinProtocol X S} (B : ScanBranches P) {r a : Ev}
    (h : B.waitAfter r a ∨ B.reapAfter r a) :
    P.pinVal (X.rval r) ≠ 0 ∧ P.pinVal (X.rval r) + 2 ≤ B.epoch r := by
  have hg := h.elim B.wait_guard B.reap_guard
  have hb := B.decoded_bound hg.1
  have hm : (P.pinVal (X.rval r) + 2) % 18446744073709551616 =
      P.pinVal (X.rval r) + 2 := Nat.mod_eq_of_lt (by omega)
  rw [hm] at hg
  exact ⟨hg.2.2.1, by omega⟩

/-- No wait or reaper event can follow an R3 that observed the current
rotation epoch, including scans that eventually abort or do not certify. -/
theorem current_scan_has_no_wait_or_reap {Lv : Liveness X} {S : SlotProtocol X Lv}
    {P : PinProtocol X S} (B : ScanBranches P) {r : Ev}
    (he : P.pinVal (X.rval r) = B.epoch r) :
    ∀ a, ¬ B.waitAfter r a ∧ ¬ B.reapAfter r a := by
  intro a
  constructor
  · intro h; have := (scan_action_requires_old_pin B (Or.inl h)).2; omega
  · intro h; have := (scan_action_requires_old_pin B (Or.inr h)).2; omega

/-- A successful scan-triggered reap takes the registration identified by
its fresh P0/state CAS, without equating that registration or pin with the
older R3 observation. `isSteal` distinguishes the Stolen path from live,
free, lost and error returns. P4's RMW adjacency makes that state current
at the successful CAS; the branch carries no expected registration id. -/
theorem scan_reap_current_registration {Lv : Liveness X} {S : SlotProtocol X Lv}
    {P : PinProtocol X S} (B : ScanBranches P) (hc : X.Consistent) {r a : Ev}
    (hscan : B.reapAfter r a) (hstolen : S.isSteal a) :
    ∃ previous word p0 verdict, S.stealOf previous a ∧
      X.sb r a ∧ X.rf word p0 ∧ X.isAcq p0 ∧ X.sb p0 verdict ∧ X.sb verdict a ∧
      X.rf word a ∧ (word = previous ∨ S.regOf previous word) ∧
      (∀ z, X.mo word z → X.mo z a → False) := by
  obtain ⟨previous, hsteal⟩ := S.steal_of hstolen
  obtain ⟨word, p0, verdict, hrf, hp0, hacq, hpv, hva, hstate⟩ := S.steal_ev hsteal
  refine ⟨previous, word, p0, verdict, hsteal, (B.reap_guard hscan).2.1,
    hp0, hacq, hpv, hva, hrf, ?_, fun _ h₁ h₂ => rmw_adjacent hc hrf h₁ h₂⟩
  exact hstate.elim (fun h => Or.inl h.1) (fun h => Or.inr h.1)

/-- A pass that stops before W3 cannot be an accepted attempt, because
att_b0 requires its W3 and B0. This is a consequence of the source attempt
definition, independent of a witness's choice of isAttempt predicate. -/
theorem stopped_before_w3_not_attempt (C : CacheProtocol X) {i : C.ι} {w : Ev}
    (hstopped : ∀ f, X.isScFence f → ¬ X.sb w f) : ¬ C.isAttempt i w := by
  intro hw
  obtain ⟨f, _, _, hf, hwf, _⟩ := C.att_b0 hw
  exact hstopped f hf hwf

/-- One tenure's complete R3 stream. The branch classifier is scoped to
this tenure, including every reload. Its epoch is the value this tenure
read at R0, and every classified R3 occurs in the readiness fold. These
are source trace facts from the single nested loop in rotate_owned:
there is one load site and one readiness update before either branch. -/
structure TenureScan (C : CacheProtocol X) (A q f c : Ev) where
  r0 : C.R.r0Of A q
  trace : RotationScanTrace C f c (X.rval q)
  branches : (i : C.ι) → ScanBranches (C.pins i)
  branch_epoch : ∀ i r, (branches i).isR3 r → (branches i).epoch r = X.rval q
  all_r3 : ∀ i r, (branches i).isR3 r →
    ∃ l ∈ trace.loads, l.slot = i ∧ l.read = r

/-- The no-wait/no-reap result now uses the actual tenure R0 value, not an
unrelated epoch parameter. The branch source may include aborting scans;
no memory freshness or timing premise is involved. -/
theorem TenureScan.current_has_no_wait_or_reap {A q f c r : Ev} {i : C.ι}
    (T : TenureScan C A q f c) (hr : (T.branches i).isR3 r)
    (hcurrent : (C.pins i).pinVal (X.rval r) = X.rval q) :
    ∀ a, ¬ (T.branches i).waitAfter r a ∧ ¬ (T.branches i).reapAfter r a := by
  apply current_scan_has_no_wait_or_reap (T.branches i)
  exact hcurrent.trans (T.branch_epoch i r hr).symm

/-- Every R3 in a certifying tenure, reloads included, is nonmatching.
Unlike coverage of one load per slot, all_r3 connects each actual load to
the sticky readiness flag. Observing the current pin rules out R3C. -/
theorem TenureScan.all_reads_nonmatching {A q f c r : Ev} {i : C.ι}
    (T : TenureScan C A q f c) (hr : (T.branches i).isR3 r) :
    (C.pins i).pinVal (X.rval r) ≠ X.rval q := by
  obtain ⟨l, hl, hs, hread⟩ := T.all_r3 i r hr
  have hn := (scanReady_true (X.rval q) _ true T.trace.guard).2 l.value
    (List.mem_map.mpr ⟨l, hl, rfl⟩)
  intro he
  apply hn
  unfold ScanLoad.value
  rw [hs, hread]
  exact he

/-- Raw R3C source shape, with the seal/tenure facts used by EP3 and the
actual scan Boolean instead of CertScan as a premise. -/
def RawRotCert (C : CacheProtocol X) (c : Ev) (n : Nat) : Prop :=
  ∃ A q f, C.R.r0Of A q ∧ X.rval q = n ∧ X.isScFence f ∧ C.R.toOwnerProtocol.under A f ∧
    X.sb f c ∧ (∀ {Z}, C.R.sealOf A Z → X.sb Z f) ∧
    ((∃ Z, C.R.sealOf A Z) ∨
      ∃ l u, C.R.toOwnerProtocol.under A l ∧ X.rf u l ∧ X.loc l = some (C.R.ctlLoc n) ∧
        C.R.stamp (X.wval u) = n ∧ C.R.isSealed (X.wval u)) ∧
    Nonempty (TenureScan C A q f c)

theorem raw_rotation_certificate {c : Ev} {n : Nat} (h : RawRotCert C c n) :
    RotCert C c n := by
  obtain ⟨a, q, f, hq, hv, hf, hu, hfc, hz, hs, ⟨trace⟩⟩ := h
  exact ⟨a, q, f, hq, hv, hf, hu, hfc, hz, hs, by simpa [hv] using trace.trace.certScan⟩

/-- The seal/ownership facts and complete scan for an actual R3C source.
The argument is the tenure's R0 value by construction. -/
structure CertifiedTenure (C : CacheProtocol X) (c : Ev) where
  owner : Ev
  r0 : Ev
  fence : Ev
  scan : TenureScan C owner r0 fence c
  sc : X.isScFence fence
  under : C.R.toOwnerProtocol.under owner fence
  beforeCert : X.sb fence c
  ownSeal : ∀ {Z}, C.R.sealOf owner Z → X.sb Z fence
  sealed : (∃ Z, C.R.sealOf owner Z) ∨
    ∃ l u, C.R.toOwnerProtocol.under owner l ∧ X.rf u l ∧
      X.loc l = some (C.R.ctlLoc (X.rval r0)) ∧
      C.R.stamp (X.wval u) = X.rval r0 ∧ C.R.isSealed (X.wval u)

theorem CertifiedTenure.raw_certificate {c : Ev} (T : CertifiedTenure C c) :
    RawRotCert C c (X.rval T.r0) :=
  ⟨T.owner, T.r0, T.fence, T.scan.r0, rfl, T.sc, T.under, T.beforeCert,
    T.ownSeal, T.sealed, ⟨T.scan⟩⟩

/-- C3's `return false` guard is the same Boolean test, with one load per
slot. There are no wait/reap branches in this source function. -/
structure RecheckScanTrace (C : CacheProtocol X) (f c : Ev) (epoch : Nat) where
  load : C.ι → ScanLoad C f c
  slot : ∀ i, (load i).slot = i
  passed : ∀ i, updateReady epoch (load i).value true = true

theorem RecheckScanTrace.certScan {f c : Ev} {epoch : Nat}
    (t : RecheckScanTrace C f c epoch) : CertScan C f c epoch := by
  intro i
  refine ⟨(t.load i).read, (t.load i).source, (t.load i).rf, ?_,
    (t.load i).acquire, (t.load i).afterFence, (t.load i).beforeCert, ?_⟩
  · exact (t.load i).loc.trans (congrArg (fun j => some (C.pins j).pin) (t.slot i))
  · have hp := t.passed i
    have hn : (t.load i).value ≠ epoch := by simpa [updateReady] using hp
    intro he
    apply hn
    exact (congrArg (fun j => (C.pins j).pinVal (X.rval (t.load i).read)) (t.slot i)).trans he

def RawRecheckCert (C : CacheProtocol X) (c : Ev) (n : Nat) : Prop :=
  ∃ q s f, X.rf s q ∧ X.isAcq q ∧ X.loc q = some C.R.ep ∧ X.rval q = n + 1 ∧ X.sb q f ∧
    X.isScFence f ∧ X.sb f c ∧ Nonempty (RecheckScanTrace C f c n)

theorem raw_recheck_certificate {c : Ev} {n : Nat} (h : RawRecheckCert C c n) :
    RecheckCert C c n := by
  obtain ⟨q, s, f, hr, ha, hl, hv, hqf, hf, hfc, ⟨trace⟩⟩ := h
  exact ⟨q, s, f, hr, ha, hl, hv, hqf, hf, hfc, trace.certScan⟩

structure RawReadinessProtocol (X : Execution Ev Loc) (C : CacheProtocol X)
    extends CertWord X where
  cert_source : ∀ {c}, isCert c → RawRotCert C c (certArg c) ∨ RawRecheckCert C c (certArg c)

def RawReadinessProtocol.toReadiness (R : RawReadinessProtocol X C) : ReadinessProtocol X C :=
  { R.toCertWord with
    cert_src := by
      intro c h
      rcases R.cert_source h with hr | hr
      · exact Or.inl (raw_rotation_certificate hr)
      · exact Or.inr (raw_recheck_certificate hr) }

/-- RD1 from the actual source scan tests, including rotation's older-pin
wait/reap/reload paths. The remaining premises are the documented atomic
protocol and A7's coverage of the configured slots. -/
theorem source_certificate_sound (R : RawReadinessProtocol X C) (hc : X.Consistent)
    {c a : Ev} {i : C.ι} {w : Ev} (hcert : R.isCert c) (hw : C.isAttempt i w)
    (he : C.attEpoch w = R.certArg c) (ha : (C.pins i).inAttempt w a) : X.hb a c :=
  R.toReadiness.cert_sound hc hcert hw he ha

/-- Feasible branch witnesses: older pin, successful reap, acquire reload
of zero; and a current pin that exits immediately but disables readiness.
These witness the source scan paths, not an entire atomic execution. -/
def reapScanWitness : SlotScan 5 :=
  .again 2 .reapSucceeded rfl (.done 0 rfl)

theorem reap_scan_can_certify : scanReady 5 true reapScanWitness.values = true := by decide

theorem current_scan_cannot_certify :
    scanReady 5 true (SlotScan.done (epoch := 5) 5 (by decide)).values = false := by decide

end Sgc.Execution
