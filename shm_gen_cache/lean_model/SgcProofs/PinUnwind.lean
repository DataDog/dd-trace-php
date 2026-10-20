import SgcProofs.PinEventFlow
import SgcProofs.PinnedArithmetic
import SgcProofs.ReservationAlignment

/-!
# Optional arithmetic analysis of production unwind edges

RB5's unconditional unwind boundary is now proved by PinCleanup.lean:
UnpinOnExit clears outstanding pins before a public return/unwind or
release_claim. The no-fault results here remain useful arithmetic facts,
but are not required to cover every Rust helper or every possible panic.
The releaseClaim action below is the release after cleanup; it is not the
whole ParticipantLock::drop implementation.

An unwind prefix may end before a pass, after any completed pass, or inside
W2--W7. Outside that interval callbacks and assertions may panic freely.
Inside it, the closed source call graph contains the operations enumerated
by PinnedSite below. Their fault conditions are the checked integer,
shift, indexing and assertion conditions, not an assumed no-panic field.
The arithmetic lemmas discharge each condition from configuration, loop
state and record provenance. The releaseClaim action preserves the pin.

Rust correspondence remains manual: every production helper operation must
be classified by the concrete PinnedSite source-site inventory, with its
actual operands and provenance. In
particular the source has no external callback inside W2--W7; the verify
hooks are excluded by the HTML scope. The constructors do not claim a Rust
frontend or turn an unclassified callback into an infallible operation.
-/
namespace Sgc.PinFlow
open Sgc.PinnedArithmetic

/-- Primitive operations with no Rust panic edge under the HTML's valid
mapping contract: atomic/wrapping arithmetic, floating operations and raw
pointer reads/writes. This does not include assertions or checked arithmetic. -/
inductive PrimitiveSite where
  | atomic | wrapping | floating | rawPointer

/-- Actual potentially trapping sites in pinned helpers. Premises express
source configuration, loop guards and IX/SP/BD record provenance. None is
a no-panic premise, and none is the successful assertion being proved. -/
inductive PinnedSite where
  | primitive (site : PrimitiveSite)
  | constants
  | tableProbe (b mask start probe : Nat) (hb : b ≤ 2147483648)
      (hm : mask + 1 = b) (hs : start ≤ mask) (hp : probe ≤ mask)
  | codecAlignment (wordOffset : Nat)
  | codecShift (bits : Nat) (hb : bits ≤ 28)
  | codecOffset (masked : Nat) (hm : masked < 268435456)
  | codecTag (hash bits : Nat) (hh : hash < 18446744073709551616)
  | arena (epoch arenas stride mapping : Nat) (he : arenas + 3 * stride ≤ mapping)
      (hm : mapping < 2 ^ 64)
  | reservation (bump n chunk : Nat) (hb : bump < 2147483648)
      (hn : n ≤ 4294967312) (hc : chunk < 2147483648)
  | lengths (key value : Nat) (hk : key < 4294967296) (hv : value < 4294967296)
  | recordPrefix (off ras : Nat) (ho : off + 16 ≤ ras) (hr : ras < 2147483648)
  | keyAddress (off pos ras : Nat) (ho : off + pos + 8 ≤ ras) (hr : ras < 2147483648)
  | emit (i n w : Nat) (hi : i ≤ n) (hn : n < 2147483648) (hw : w < 268435456)
  | paddedEmit (size i count : Nat) (hs : size < 2147483648)
      (hc : count ≤ (size + 7) / 8) (hi : i ≤ count)
  | keyTail (key : Nat) (h : key % 8 ≠ 0)
  | tail (i n : Nat) (hi : i < n) (he : n < i + 8)
  | smallTail (pos full : Nat) (hp : pos ≤ 6) (hf : full < 2147483648)
  | estimateSquare (t : Nat) (ht : t ≤ 2097152)
  | estimateSample (n sum delta : Nat) (h : SumBound n sum)
      (hn : n ≠ 2097152) (hd : delta ≤ 4398046511104)
  | estimateDecision (n decided : Nat) (h : decided ≤ n)
  | estimateTable (saturatingCast : Nat)

/-- A checked operation faults exactly when its success condition fails.
The record condition includes both pinned publication/header assertions;
no debug assertion is used as a premise. -/
def PinnedSite.fault : PinnedSite → Prop
  | .primitive _ => False
  | .constants => ¬ ((32 : Nat) < 64 ∧ (33 : Nat) < 64 ∧
      (0 : Nat) < 3 ∧ (0 : Nat) < 8 ∧ (0 : Nat) < 32 ∧ (4 : Nat) ≤ 32)
  | .tableProbe b mask start probe _ _ _ _ =>
      ¬ (checkedAdd 32 start probe = some (start + probe) ∧
        checkedAdd 32 probe 1 = some (probe + 1) ∧ (start + probe) &&& mask < b)
  | .codecAlignment wordOffset => ¬ ((wordOffset * 8) % 8 = 0)
  | .codecShift bits _ => ¬ (shiftValid 32 bits ∧ shiftValid 64 (32 + bits))
  | .codecOffset masked _ => checkedMul 32 masked 8 ≠ some (masked * 8)
  | .codecTag hash bits _ => ¬ (hash / 2 ^ (32 + bits) ≤ 4294967295 / 2 ^ bits)
  | .arena epoch arenas stride _ _ _ =>
      ¬ (epoch % 3 < 3 ∧
        checkedMul 64 (epoch % 3) stride = some (epoch % 3 * stride) ∧
        checkedAdd 64 arenas (epoch % 3 * stride) = some (arenas + epoch % 3 * stride))
  | .reservation bump n chunk _ _ _ =>
      ¬ (checkedAdd 64 bump n = some (bump + n) ∧
        checkedAdd 64 bump chunk = some (bump + chunk))
  | .lengths key value _ _ =>
      ¬ (checkedAdd 64 key 7 = some (key + 7) ∧
        checkedAdd 64 8 ((key + 7) / 8 * 8) = some (8 + (key + 7) / 8 * 8) ∧
        checkedAdd 64 (8 + (key + 7) / 8 * 8) value = some (8 + (key + 7) / 8 * 8 + value))
  | .recordPrefix off ras _ _ =>
      ¬ (off < ras ∧ off ≤ ras ∧ 8 ≤ ras - off ∧
        checkedAdd 32 off 8 = some (off + 8) ∧ checkedAdd 32 off 16 = some (off + 16))
  | .keyAddress off pos _ _ _ => checkedAdd 32 off pos ≠ some (off + pos)
  | .emit i n w _ _ _ =>
      ¬ (checkedAdd 64 i 8 = some (i + 8) ∧ checkedAdd 32 w 1 = some (w + 1) ∧
        checkedSub n i = some (n - i))
  | .paddedEmit size i _ _ _ _ =>
      ¬ (checkedAdd 64 (size / 8) 1 = some (size / 8 + 1) ∧
        checkedAdd 64 i 1 = some (i + 1))
  | .keyTail key _ =>
      ¬ (checkedSub key (key / 8 * 8) = some (key - key / 8 * 8) ∧
        0 < key % 8 ∧ key % 8 < 8 ∧ shiftValid 64 ((8 - key % 8) * 8))
  | .tail i n _ _ =>
      ¬ (0 < n - i ∧ n - i < 8 ∧ shiftValid 64 ((8 - (n - i)) * 8) ∧
        checkedSub 8 (n - i) = some (8 - (n - i)))
  | .smallTail pos full _ _ =>
      ¬ (shiftValid 64 (pos * 8) ∧ checkedAdd 32 full 8 = some (full + 8))
  | .estimateSquare t _ => checkedMul 64 t t ≠ some (t * t)
  | .estimateSample n sum delta _ _ _ =>
      ¬ (checkedAdd 64 n 1 = some (n + 1) ∧
        checkedAdd 64 sum delta = some (sum + delta))
  | .estimateDecision n decided _ => checkedSub n decided ≠ some (n - decided)
  | .estimateTable cast =>
      ¬ (min cast 9 < 11 ∧ min cast 9 + 1 < 11 ∧
        checkedAdd 64 (min cast 9) 1 = some (min cast 9 + 1))

/-- Exhaustion of the actual operation classes, using the checked
arithmetic proofs rather than a no-unwind axiom. -/
theorem pinned_site_cannot_fault (site : PinnedSite) : ¬ site.fault := by
  cases site with
  | primitive _ => exact id
  | constants => exact fun h => h (by decide)
  | tableProbe b mask start probe hb hm hs hp => exact fun h => h (table_probe hb hm hs hp)
  | codecAlignment wordOffset => exact fun h => h (by omega)
  | codecShift bits hb => exact fun h => h (codec_shifts hb)
  | codecOffset masked hm => exact fun h => h (codec_offset hm)
  | codecTag hash bits hh => exact fun h => h (codec_tag_assertion hh)
  | arena epoch arenas stride mapping he hm => exact fun h => h (arena_address he hm)
  | reservation bump n chunk hb hn hc => exact fun h => h (reservation_adds hb hn hc)
  | lengths key value hk hv => exact fun h => h (lengths_arithmetic hk hv)
  | recordPrefix off ras ho hr =>
      exact fun h => h ⟨by omega, record_prefix ho hr⟩
  | keyAddress off pos ras ho hr => exact fun h => h (key_word_add ho hr)
  | emit i n w hi hn hw => exact fun h => h (emit_counters hi hn hw)
  | paddedEmit size i count hs hc hi => exact fun h => h (padded_emit_counters hs hc hi)
  | keyTail key hkey => exact fun h => h (key_tail hkey)
  | tail i n hi he => exact fun h => h (tail_guard hi he)
  | smallTail pos full hp hf => exact fun h => h (small_tail_and_key_counter hp hf)
  | estimateSquare t ht => exact fun h => h (estimator_increment ht).2.2
  | estimateSample n sum delta hs hn hd =>
      exact fun h => h ⟨(estimator_sample hs hn hd).1, (estimator_sample hs hn hd).2.1⟩
  | estimateDecision n decided hd => exact fun h => h (estimator_decision hd).1
  | estimateTable cast => exact fun h => h (estimator_table cast)

/-- Failure policy changes a failed helper check into abort or unwind.
Both outcomes remain in the semantics; neither is assumed away. -/
inductive HelperOutcome where
  | continues | unwinds | aborts
  deriving DecidableEq

def PinnedSite.permits (site : PinnedSite) : HelperOutcome → Prop
  | .continues => ¬ site.fault
  | .unwinds | .aborts => site.fault

theorem pinned_helper_continues (site : PinnedSite) (outcome : HelperOutcome)
    (h : site.permits outcome) : outcome = .continues := by
  cases outcome with
  | continues => rfl
  | unwinds => exact (pinned_site_cannot_fault site h).elim
  | aborts => exact (pinned_site_cannot_fault site h).elim

/-- Operations that may unwind outside passes. This includes callbacks,
lookup helpers and rotation/identity/wake helpers, whose safety is not
needed for RB5 once W7 has occurred. -/
inductive OutsideSite where
  | apiEntry | lookupProbe | paramsBeforeW2 | rotationAfterW7 | wakeAfterW7

/-- Source prefixes at pass boundaries. A reservation attempt ends with
W7 either in reserve's common rejection path or in store after publication.
A successful reserve-to-store return is inside the pass, not a boundary.
Any number of completed calls, attempts, rotations and retries is allowed. -/
inductive BoundaryPrefix where
  | entry
  | call (before : BoundaryPrefix) (api : CallExecution)
  | pass (before : BoundaryPrefix) (body : List PinnedSite)
  | rotate (before : BoundaryPrefix)

def BoundaryPrefix.trace : BoundaryPrefix → List Action
  | .entry => []
  | .call before api => before.trace ++ api.trace
  | .pass before _ => before.trace ++ [.w2, .w7]
  | .rotate before => before.trace ++ [.rotate]

theorem boundary_prefix_unpinned (boundary : BoundaryPrefix) :
    run false boundary.trace = some false := by
  induction boundary with
  | entry => rfl
  | call before api ih => simp [BoundaryPrefix.trace, run_append, ih, source_call_returns]
  | pass before body ih => simp [BoundaryPrefix.trace, run_append, ih, run, step]
  | rotate before ih => simp [BoundaryPrefix.trace, run_append, ih, run, step]

/-- Potential unwind points include arbitrary prefixes of the pinned
helper sequence. The pinned case is not removed from the syntax. -/
inductive UnwindPoint where
  | outside (before : BoundaryPrefix) (site : OutsideSite)
  | inside (before : BoundaryPrefix) (completed : List PinnedSite) (site : PinnedSite)

def UnwindPoint.trace : UnwindPoint → List Action
  | .outside before _ => before.trace
  | .inside before _ _ => before.trace ++ [.w2]

/-- Outside operations may panic arbitrarily. Inside a pass a panic is an
actual failed helper check. Aborts terminate without entering Drop; allowing
all failed checks to unwind is conservative for std and panic=abort. -/
def UnwindPoint.raises : UnwindPoint → Prop
  | .outside _ _ => True
  | .inside _ _ site => site.fault

theorem unwind_prefix_unpinned (point : UnwindPoint) (h : point.raises) :
    run false point.trace = some false := by
  cases point with
  | outside before site => exact boundary_prefix_unpinned before
  | inside before completed site => exact (pinned_site_cannot_fault site h).elim

/-- Under the optional no-fault analysis, the pin is already zero before
cleanup, so the guard performs no W7. General unwind safety is proved by
PinCleanup without this raises premise or a helper-site classification. -/
theorem unwind_drop_entry (point : UnwindPoint) (h : point.raises) :
    executeTrace false point.trace = false ∧
    executeTrace false (point.trace ++ [.releaseClaim]) = false := by
  have hr := unwind_prefix_unpinned point h
  refine ⟨checked_equals_execution hr, ?_⟩
  apply checked_equals_execution
  simp [run_append, hr, run, step]

/-- When this optional fault analysis applies, cleanup's zero branch adds
no W7: the pin was already zero at the faulting source operation. -/
theorem unwind_drop_checked (point : UnwindPoint) (h : point.raises) :
    run false (point.trace ++ [.releaseClaim]) = some false := by
  simp [run_append, unwind_prefix_unpinned point h, run, step]

/-- The unwind entry selects the same physical zero origin as normal
returns, so the existing indexed-event and atomic-read adapters apply. -/
theorem unwind_entry_zero_origin (point : UnwindPoint) (h : point.raises) :
    PinFree point.trace ∨
      ∃ start tail, point.trace = start ++ .w7 :: tail ∧ PinFree tail := by
  exact (checked_zero_origin (unwind_prefix_unpinned point h)).elim
    (fun h => Or.inl h.2) Or.inr

end Sgc.PinFlow

namespace Sgc.Execution
open Sgc.PinFlow
variable {Ev Loc : Type} {X : Execution Ev Loc} {Lv : Liveness X}
  {S : SlotProtocol X Lv}

/-- The unwinding prefix also discharges the event-level F1 exit
obligation; its run premise is proved by the helper fault analysis. -/
theorem unwind_source_pass_exit (L : PinSourceLabels X S)
    (point : UnwindPoint) (h : point.raises) {c w N : Ev}
    (T : IndexedPinTrace L c point.trace) (hc : X.Consistent)
    (hw : L.w2Of c w) (hNact : S.act c N) (hN : L.nonAnn N) (hwN : X.sb w N) :
    ∃ u, L.unpinOf w u ∧ S.act c u :=
  IndexedPinTrace.derives_pass_exit L T hc (unwind_prefix_unpinned point h) hw hNact hN hwN

end Sgc.Execution

namespace Sgc.Execution
open Sgc.PinFlow Sgc.PinnedArithmetic
variable {Ev Loc : Type} {X : Execution Ev Loc} {C : CacheProtocol X}
  {A : ArenaProtocol X C} {Rv : ReserveProtocol X C} {I : IndexProtocol X A Rv}

/-- A5 gives the machine bound used by every record helper. -/
theorem pin_helper_capacity_bound (Rv : ReserveProtocol X C) : Rv.cap < 2147483648 := by
  have h := Rv.a5
  unfold bumpLimit at h
  omega

/-- An actual I0 read obtains the header offset from its reads-from source.
IX1/SP4, not the production assertion, supplies recordPrefix's premise. -/
def ReadEntry.pinnedPrefix (e : ReadEntry I) (hc : X.Consistent) : PinnedSite :=
  .recordPrefix e.off Rv.cap (e.bound hc) (pin_helper_capacity_bound Rv)

theorem ReadEntry.pinned_prefix_no_fault (e : ReadEntry I) (hc : X.Consistent) :
    ¬ (e.pinnedPrefix hc).fault := pinned_site_cannot_fault _

/-- The publication assertion uses the actual offset encoded by I2.
Its reservation and need>=16 give the same header-prefix bound. -/
def IndexProtocol.pinnedPublication (I : IndexProtocol X A Rv) (hc : X.Consistent)
    {i : C.ι} {w x : Ev} (hk : I.isPubCas x ∨ I.isPubStore x)
    (hw : C.isAttempt i w) (hx : (C.pins i).inAttempt w x) : PinnedSite :=
  .recordPrefix (I.entryOff (X.wval x)) Rv.cap (by
    have ha := Rv.reserve_in_area hc (I.pub_off hk hw hx)
    have hn := Rv.need_pos hw
    omega) (pin_helper_capacity_bound Rv)

theorem IndexProtocol.pinned_publication_no_fault (I : IndexProtocol X A Rv)
    (hc : X.Consistent) {i : C.ι} {w x : Ev} (hk : I.isPubCas x ∨ I.isPubStore x)
    (hw : C.isAttempt i w) (hx : (C.pins i).inAttempt w x) :
    ¬ (I.pinnedPublication hc hk hw hx).fault := pinned_site_cannot_fault _

/-- The encoded offset's multiplication form also discharges encode's
alignment assertion. No successful assertion is assumed. -/
theorem IndexProtocol.pinned_offset_alignment (I : IndexProtocol X A Rv) (value : Nat) :
    I.entryOff value = I.entryOff value / 8 * 8 ∧
    ¬ (PinnedSite.codecAlignment (I.entryOff value / 8)).fault := by
  have h := I.entryOff_aligned value
  exact ⟨by omega, pinned_site_cannot_fault _⟩

/-- D4/D5 guards and the actual key-loop index instantiate the arithmetic
site, with the same read events and lengths as BD2's checked payload. -/
def CheckedPayloadRead.pinnedKey (R : CheckedPayloadRead I) (hc : X.Consistent)
    (hk : R.pos < (keyLen (X.rval R.lengths) + 7) / 8) : PinnedSite :=
  .keyAddress R.entry.off (16 + R.pos * 8) Rv.cap (by
    obtain ⟨_, _, hh, hl⟩ := R.checked_before_load hc
    omega) (pin_helper_capacity_bound Rv)

theorem CheckedPayloadRead.pinned_key_no_fault (R : CheckedPayloadRead I)
    (hc : X.Consistent) (hk : R.pos < (keyLen (X.rval R.lengths) + 7) / 8) :
    ¬ (R.pinnedKey hc hk).fault := pinned_site_cannot_fault _

/-- The successful reservation's actual lengths word bounds the complete
emitter, before or after either key/value byte loop. -/
theorem ReserveProtocol.pinned_emit_bounds (Rv : ReserveProtocol X C)
    (hc : X.Consistent) {i : C.ι} {w : Ev} {off : Nat}
    (hw : C.isAttempt i w) (hr : Rv.reserves i w off) :
    keyLen (Rv.lens w) < 2147483648 ∧ valLen (Rv.lens w) < 2147483648 ∧
    2 + (keyLen (Rv.lens w) + 7) / 8 + (valLen (Rv.lens w) + 7) / 8 < 268435456 := by
  have hb := Rv.reserve_in_area hc hr
  have hn := Rv.need_lens hw
  apply configured_emit_bounds (ras := Rv.cap)
  · omega
  · exact pin_helper_capacity_bound Rv

/-- The emitter's actual counter guards supply only loop positions; its
machine-width premises are derived from SP4 and the stored lengths. -/
def ReserveProtocol.pinnedEmit (Rv : ReserveProtocol X C) (hc : X.Consistent)
    {slot : C.ι} {w : Ev} {off : Nat} (hw : C.isAttempt slot w)
    (hr : Rv.reserves slot w off) (bytePos n wordPos : Nat)
    (which : n = keyLen (Rv.lens w) ∨ n = valLen (Rv.lens w)) (hi : bytePos ≤ n)
    (hp : wordPos ≤ 2 + (keyLen (Rv.lens w) + 7) / 8 + (valLen (Rv.lens w) + 7) / 8) :
    PinnedSite :=
  .emit bytePos n wordPos hi (by
    have h := Rv.pinned_emit_bounds hc hw hr
    rcases which with rfl | rfl <;> omega) (by
    have h := Rv.pinned_emit_bounds hc hw hr
    omega)

theorem ReserveProtocol.pinned_emit_no_fault (Rv : ReserveProtocol X C)
    (hc : X.Consistent) {slot : C.ι} {w : Ev} {off : Nat}
    (hw : C.isAttempt slot w) (hr : Rv.reserves slot w off) (bytePos n wordPos : Nat)
    (which : n = keyLen (Rv.lens w) ∨ n = valLen (Rv.lens w)) (hi : bytePos ≤ n)
    (hp : wordPos ≤ 2 + (keyLen (Rv.lens w) + 7) / 8 + (valLen (Rv.lens w) + 7) / 8) :
    ¬ (Rv.pinnedEmit hc hw hr bytePos n wordPos which hi hp).fault := pinned_site_cannot_fault _

end Sgc.Execution

namespace Sgc.Execution
open Sgc.PinFlow Sgc.PinnedArithmetic
variable {Ev Loc : Type} {X : Execution Ev Loc} {C : CacheProtocol X}
  {Rv : ReserveProtocol X C}

/-- The actual successful reservation offset, including Full and
FullRejected paths that never publish, supplies table_put's assertion. -/
def ReserveProtocol.pinnedReservation (Rv : ReserveProtocol X C) (hc : X.Consistent)
    {i : C.ι} {w : Ev} {off : Nat} (hw : C.isAttempt i w)
    (hr : Rv.reserves i w off) : PinnedSite :=
  .recordPrefix off Rv.cap (by
    have ha := Rv.reserve_in_area hc hr
    have hn := Rv.need_pos hw
    omega) (pin_helper_capacity_bound Rv)

theorem ReserveProtocol.pinned_reservation_no_fault (Rv : ReserveProtocol X C)
    (hc : X.Consistent) {i : C.ι} {w : Ev} {off : Nat}
    (hw : C.isAttempt i w) (hr : Rv.reserves i w off) :
    ¬ (Rv.pinnedReservation hc hw hr).fault := pinned_site_cannot_fault _

/-- encode's alignment assertion follows from the actual B1/H1 returned
offset, with aligned control/cursor writes proved by mo induction. -/
theorem ReserveProtocol.pinned_reservation_alignment (Rv : ReserveProtocol X C)
    (hc : X.Consistent) (hchunk : Rv.chunk % 8 = 0)
    {i : C.ι} {w : Ev} {off : Nat} (hw : C.isAttempt i w)
    (hr : Rv.reserves i w off) :
    off = off / 8 * 8 ∧ ¬ (PinnedSite.codecAlignment (off / 8)).fault := by
  have ha := Rv.reservation_aligned hc hchunk hw hr
  exact ⟨by omega, pinned_site_cannot_fault _⟩

/-- Input size rejection and A5 bound need/chunk even before reservation
succeeds. Thus the failed-reservation paths get the same arithmetic guard. -/
theorem ReserveProtocol.pinned_claim_sizes (Rv : ReserveProtocol X C)
    {i : C.ι} {w : Ev} (hw : C.isAttempt i w) :
    Rv.need w < 2147483648 ∧ Rv.chunk < 2147483648 := by
  have hlen := List.length_pos_of_mem (Rv.slots_all i)
  have hm := Nat.le_mul_of_pos_left Rv.maxClaim hlen
  have hn := Rv.need_le hw
  have hc := Rv.chunk_le
  have ha := Rv.a5
  unfold bumpLimit at ha
  omega

def ReserveProtocol.pinnedReservationAdds (Rv : ReserveProtocol X C)
    {i : C.ι} {w : Ev} (hw : C.isAttempt i w) (ctlWord : Nat)
    (width : ctlWord < 18446744073709551616) : PinnedSite :=
  .reservation (ctlWord / 8589934592) (Rv.need w) Rv.chunk (by omega)
    (by have h := Rv.pinned_claim_sizes hw; omega) (Rv.pinned_claim_sizes hw).2

theorem ReserveProtocol.pinned_reservation_adds_no_fault (Rv : ReserveProtocol X C)
    {i : C.ι} {w : Ev} (hw : C.isAttempt i w) (ctlWord : Nat) (width : ctlWord < 18446744073709551616) :
    ¬ (Rv.pinnedReservationAdds hw ctlWord width).fault := pinned_site_cannot_fault _

end Sgc.Execution
