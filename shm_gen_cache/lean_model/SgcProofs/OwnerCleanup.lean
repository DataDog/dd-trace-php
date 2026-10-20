import SgcProofs.OwnerClassification
import SgcProofs.Recovery
import SgcProofs.ReaperPinFlow

/-!
# RB2: the actual owner registration and its rotation cleanup

`rotate` installs ReleaseRotation immediately after successful acquisition,
before rotate_owned. A normal return and an arbitrary caught unwind both
run its destructor, whose first shared action stores NONE (O4). A panic in
the subsequent wake is therefore after O4. Aborting execution cannot reach
that thread's P3. The registration's P3 is outside every call to rotate.

The finite source projection below records exited RAII scopes in a
registration that reaches P3. Only labels and program order are premises;
O4-before-P3 is derived by list membership. It is not supplied as a field.
The slot/id of the old holder is derived from OwnerHandoff's actual
registration load and OwnerClassification's packed value definitions.
`OwnerRecoveryFacts.holds` and `takeover_owner` are not inputs here.
-/
namespace Sgc.Execution
variable {Ev Loc : Type} {X : Execution Ev Loc} {Lv : Liveness X}

inductive RotationScopeExit where
  | returned | unwound

/-- Projection of an exited ReleaseRotation scope. Helpers may unwind at
any point; both exits drop the same guard before the caller resumes. -/
structure RotationScope (Ev : Type) where
  acquire : Ev
  body : List Ev
  release : Ev
  exit : RotationScopeExit

def RotationScope.trace (scope : RotationScope Ev) : List Ev :=
  match scope.exit with
  | .returned => scope.acquire :: (scope.body ++ [scope.release])
  | .unwound => scope.acquire :: (scope.body ++ [scope.release])

/-- An actual P3 after the registration's exited rotation scopes. The
source covers every acquisition performed for this registration, labels
the guard's store, and orders projected events by program order. It does
not supply a release-before-free relation. -/
structure OwnerRegistrationEnd (O : OwnerEvents X) (S : SlotProtocol X Lv)
    (c x : Ev) where
  scopes : List (RotationScope Ev)
  covered : ∀ {A}, O.isAcqCas A → S.act c A →
    ∃ scope ∈ scopes, scope.acquire = A
  release_label : ∀ scope ∈ scopes, O.relOf scope.acquire scope.release
  tenure_covered : ∀ scope ∈ scopes, ∀ e, O.under scope.acquire e → e ∈ scope.trace
  ordered : List.Pairwise X.sb (scopes.flatMap RotationScope.trace ++ [x])

/-- Source boundary for P3. A live caller can only release its lock after
rotate has returned or unwound through ReleaseRotation. Infinite waits,
process death and abort have no P3 and require no invented O4. -/
def OwnerCleanupSource (O : OwnerEvents X) (S : SlotProtocol X Lv) : Type :=
  ∀ {c x}, S.freeOf c x → OwnerRegistrationEnd O S c x

theorem release_before_free_from_guards {O : OwnerEvents X} {S : SlotProtocol X Lv}
    (G : OwnerCleanupSource O S) {c A x : Ev}
    (hA : O.isAcqCas A) (hca : S.act c A) (hx : S.freeOf c x) :
    ∃ r, O.relOf A r ∧ X.sb r x := by
  let endTrace := G hx
  obtain ⟨scope, hmem, hscope⟩ := endTrace.covered hA hca
  have hrmem : scope.release ∈ endTrace.scopes.flatMap RotationScope.trace := by
    apply List.mem_flatMap.mpr
    refine ⟨scope, hmem, ?_⟩
    cases he : scope.exit <;> simp [RotationScope.trace, he]
  exact ⟨scope.release, hscope ▸ endTrace.release_label scope hmem,
    (List.pairwise_append.mp endTrace.ordered).2.2 _ hrmem x (by simp)⟩

/-- O4-before-P3 supplies the owner handoff's tenure-lifetime premise,
including work carried out for a second stolen registration. -/
theorem tenure_before_free_from_guards {O : OwnerEvents X} {S : SlotProtocol X Lv}
    (G : OwnerCleanupSource O S) {c A e x : Ev}
    (hA : O.isAcqCas A) (hca : S.act c A) (he : O.under A e) (hx : S.freeOf c x) :
    e = x ∨ X.sb e x := by
  let endTrace := G hx
  obtain ⟨scope, hmem, hscope⟩ := endTrace.covered hA hca
  have hemem : e ∈ endTrace.scopes.flatMap RotationScope.trace :=
    List.mem_flatMap.mpr ⟨scope, hmem, endTrace.tenure_covered scope hmem e (hscope ▸ he)⟩
  exact Or.inr ((List.pairwise_append.mp endTrace.ordered).2.2 _ hemem x (by simp))

/-- Complete the existing registration adapter with the proved lifetime
relation; no before_free premise is accepted from the caller. -/
def OwnerRegistrationData.withCleanup {O : OwnerEvents X} {S : SlotProtocol X Lv}
    {index : Fin wordLimit} {expected : Nat}
    (R : OwnerRegistrationData O S index expected) (G : OwnerCleanupSource O S) :
    OwnerRegistrationSource O S index expected where
  toOwnerRegistrationData := R
  before_free := fun hA hca he hx => tenure_before_free_from_guards G hA hca he hx

/-- **RB2 from source.** The returned claim is exactly the registration
encoded by the replaced acquiring CAS, even if O1 observed another tenure
of that same registration. Every free path is covered by RAII cleanup. -/
theorem no_live_owner_takeover_from_source {O : OwnerEvents X} {S : SlotProtocol X Lv}
    {capacity : Nat} (W : OwnerSource O capacity) (hc : X.Consistent)
    (A9 : NoFalseDeath Lv) {index : Fin wordLimit} {A T A' l : Ev}
    (R : OwnerRegistrationData O S index (OwnerWord.registration (X.rval l)))
    (G : OwnerCleanupSource O S) (D : OwnerTakeoverRead O A T A' l)
    (hi : OwnerWord.slot (X.rval l) = index.val) (B : ReapOwnerReturn S l T) :
    ∃ c, S.act c A ∧
      X.wval A = OwnerWord.pack index.val (R.id c).val ∧
      ¬ A9.toThreadLifetime.running (Lv.thrOf c) T := by
  let source := R.withCleanup G
  obtain ⟨c, hca, hid, he⟩ := takeover_evidence_of_source W hc source D hi B
  have hvA : X.wval A = X.rval l := (hc.rf_val D.predecessor).symm.trans D.expected
  obtain ⟨c', g, hg, hca', _, hv⟩ := source.acquire_origin hc D.replaced (hvA ▸ hi)
  have hsame := same_slot_action_unique hc hca hca'
  subst c'
  exact ⟨c, hca, hv,
    O.no_takeover_of_running S A9 hc he D.replaced D.takeover D.predecessor
      (fun x hx => release_before_free_from_guards G D.replaced hca hx)⟩

/-- Every successful source O3 supplies its own predecessor. Nonempty
expected-value equality derives that predecessor's acquiring-CAS class;
no abstract takeover_owner or holder predicate is supplied. -/
theorem no_live_code_takeover {O : OwnerEvents X} {S : SlotProtocol X Lv}
    {capacity : Nat} (W : OwnerSource O capacity) (hc : X.Consistent)
    (A9 : NoFalseDeath Lv) {index : Fin wordLimit} {T A' l : Ev}
    (R : OwnerRegistrationData O S index (OwnerWord.registration (X.rval l)))
    (G : OwnerCleanupSource O S) (hT : O.isAcqCas T)
    (observed : X.rf A' l) (location : X.loc l = some O.own)
    (acquire : X.isAcq l) (nonempty : OwnerWord.registration (X.rval l) ≠ 0)
    (success : X.rval T = X.rval l) (hi : OwnerWord.slot (X.rval l) = index.val)
    (B : ReapOwnerReturn S l T) :
    ∃ A c, X.rf A T ∧ O.isAcqCas A ∧ S.act c A ∧
      X.wval A = OwnerWord.pack index.val (R.id c).val ∧
      ¬ A9.toThreadLifetime.running (Lv.thrOf c) T := by
  obtain ⟨A, hrf⟩ := O.acq_reads hT
  have hA := (owner_load_source W hc hrf (O.acq_loc hT) (success ▸ nonempty)).1
  let D : OwnerTakeoverRead O A T A' l :=
    ⟨hA, hT, hrf, observed, location, acquire, nonempty, success⟩
  obtain ⟨c, hca, hv, hdead⟩ := no_live_owner_takeover_from_source W hc A9 R G D hi B
  exact ⟨A, c, hrf, hA, hca, hv, hdead⟩

end Sgc.Execution
