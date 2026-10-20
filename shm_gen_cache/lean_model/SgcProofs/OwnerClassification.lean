import SgcProofs.OwnerHandoff

/-!
# OW2: code O2/O3 sites classified by owner values

`ClaimClosure.isO2/isO3` classify RF sources. This file proves that these
classes match the actual CAS sites in rotation.rs. `OwnerCasCall` records
successful CAS equality and the branch's expected value. It distinguishes
SkipBusyOwner's literal NONE from the waiting O2's O1 value: the latter
checks only registration_id == 0, so its whole expected value is proved
zero rather than assumed zero. O3 expects O1's nonempty owner word.

`OwnerIssuedArguments` records the arguments to RotationOwner::new.
Nonzero registration ids come from participant.rs:next_registration_id:
`let id = counter.fetch_add(1, Relaxed); if id != 0 { return id; }`.
This guard holds even when the u32 counter wraps; A4 supplies uniqueness,
not nonzeroness. The source uses that return for every successful P1/P4
and the corresponding P2/P5. `owner_arguments_from_registrations` derives
the owner arguments from the registered lock's actual registration_id()
load via OW1's `acquire_origin`/`own_registration_read`, so the shared
Relaxed load is not simply assumed to return the caller's id.

No source assumption assigns a CAS an RF class. Init/O4 values come from
OwnerSource; acquiring CAS values come from the packed issued arguments;
RF-value agreement and the CAS expected-value check derive the class.
-/

namespace Sgc.Execution
variable {Ev Loc : Type} {X : Execution Ev Loc}

/-- Actual arguments of successful calls to RotationOwner::new. The
nonzero argument is a returned next_registration_id, whose explicit
return guard excludes zero, and is retained through P2/P5. -/
structure OwnerIssuedArguments (O : OwnerEvents X) : Prop where
  acquire : ∀ {A}, O.isAcqCas A → ∃ (index id : Fin wordLimit),
    id.val ≠ 0 ∧ X.wval A = OwnerWord.pack index.val id.val

/-- Source derivation of the owner arguments, using the actual registered
lock and its shared-state registration_id read for each acquiring CAS.
The only extra premise is the issuer's nonzero-return guard on claim ids. -/
theorem owner_arguments_from_registrations {O : OwnerEvents X} {Lv : Liveness X}
    (hc : X.Consistent)
    (calls : ∀ {A}, O.isAcqCas A →
      ∃ (S : SlotProtocol X Lv) (index : Fin wordLimit) (expected : Nat)
        (R : OwnerRegistrationSource O S index expected),
        OwnerWord.slot (X.wval A) = index.val ∧
        ∀ c, S.isClaim c → (R.id c).val ≠ 0) : OwnerIssuedArguments O := by
  refine ⟨?_⟩
  intro A hA
  obtain ⟨S, index, expected, R, hi, hids⟩ := calls hA
  obtain ⟨c, g, hg, _, _, hv⟩ := R.acquire_origin hc hA hi
  exact ⟨index, R.id c, hids c (S.reg_claim hg), hv⟩

/-- An O2/O3 write has a nonzero high u32, hence cannot be NONE. -/
theorem acquiring_owner_nonempty {O : OwnerEvents X} (I : OwnerIssuedArguments O)
    {A : Ev} (hA : O.isAcqCas A) :
    OwnerWord.registration (X.wval A) ≠ 0 ∧ X.wval A ≠ 0 := by
  obtain ⟨index, id, hn, hv⟩ := I.acquire hA
  have hr : OwnerWord.registration (X.wval A) = id.val := by
    rw [hv, OwnerWord.registration_pack index.isLt id.isLt]
  have hne : OwnerWord.registration (X.wval A) ≠ 0 := by
    rw [hr]
    exact hn
  exact ⟨hne, fun hz => hne (by rw [hz]; rfl)⟩

/-- An owner read with zero registration id reads init or O4. In
particular its *entire* value is zero: a stray nonzero low slot field
with high half zero cannot have been written by this source protocol. -/
theorem empty_owner_read_source {O : OwnerEvents X} {capacity : Nat}
    (W : OwnerSource O capacity) (I : OwnerIssuedArguments O) (hc : X.Consistent)
    {w r : Ev} (hrf : X.rf w r) (hl : X.loc r = some O.own)
    (hempty : OwnerWord.registration (X.rval r) = 0) :
    (w = O.init ∨ ∃ B, O.relOf B w) ∧ X.rval r = 0 := by
  obtain ⟨hw, _, hloc, _⟩ := hc.rf_wf hrf
  rcases O.writes hw (hloc.trans hl) with hinit | hacq | ⟨B, hrel⟩
  · subst w
    exact ⟨Or.inl rfl, (hc.rf_val hrf).trans W.init_none⟩
  · exact ((acquiring_owner_nonempty I hacq).1
      ((congrArg OwnerWord.registration (hc.rf_val hrf)).symm.trans hempty)).elim
  · exact ⟨Or.inr ⟨B, hrel⟩, (hc.rf_val hrf).trans (W.release_none hrel)⟩

/-- The exact three source sites: two ordinary acquisitions and takeover. -/
inductive OwnerCasSite
  | skipBusyO2 | waitingO2 | takeoverO3
  deriving DecidableEq

/-- Source control flow and success of compare_exchange. The expected
value is constant NONE in SkipBusyOwner and the value loaded at O1 in
the waiting path. This does not impose a reads-from source. -/
inductive OwnerCasCall (O : OwnerEvents X) (A : Ev) : OwnerCasSite → Prop where
  | skipBusy (cas : O.isAcqCas A) (success : X.rval A = 0) :
      OwnerCasCall O A .skipBusyO2
  | waiting (l : Ev) (cas : O.isAcqCas A)
      (load_location : X.loc l = some O.own) (load_acquire : X.isAcq l)
      (empty : OwnerWord.registration (X.rval l) = 0)
      (success : X.rval A = X.rval l) : OwnerCasCall O A .waitingO2
  | takeover (l : Ev) (cas : O.isAcqCas A)
      (load_location : X.loc l = some O.own) (load_acquire : X.isAcq l)
      (nonempty : OwnerWord.registration (X.rval l) ≠ 0)
      (success : X.rval A = X.rval l) : OwnerCasCall O A .takeoverO3

/-- The successful event at each source site is an acquiring CAS. -/
theorem OwnerCasCall.acquiring {O : OwnerEvents X} {A : Ev} {site : OwnerCasSite}
    (C : OwnerCasCall O A site) : O.isAcqCas A := by
  cases C with
  | skipBusy h _ => exact h
  | waiting _ h _ _ _ _ => exact h
  | takeover _ h _ _ _ _ => exact h

/-- The CAS reads its checked expected value. Waiting O2 inherits the
zero high-half guard; takeover O3 inherits the opposite guard. -/
theorem OwnerCasCall.expected_kind {O : OwnerEvents X} {A : Ev} {site : OwnerCasSite}
    (C : OwnerCasCall O A site) :
    match site with
    | .skipBusyO2 | .waitingO2 => OwnerWord.registration (X.rval A) = 0
    | .takeoverO3 => OwnerWord.registration (X.rval A) ≠ 0 := by
  cases C with
  | skipBusy _ h => rw [h]; rfl
  | waiting _ _ _ _ h hs => simpa only [hs] using h
  | takeover _ _ _ _ h hs => simpa only [hs] using h

/-- **OW2(a), actual source sites.** The immediate predecessor of a
code O2 is init or O4 and the expected/read word is exactly zero. The
immediate predecessor of code O3 is an acquiring CAS with nonzero id.
This is derived from values, not the definitions of isO2/isO3. -/
theorem code_owner_cas_predecessor {O : OwnerEvents X} {capacity : Nat}
    (W : OwnerSource O capacity) (I : OwnerIssuedArguments O) (hc : X.Consistent)
    {A : Ev} {site : OwnerCasSite} (C : OwnerCasCall O A site) :
    ∃ w, X.rf w A ∧ X.mo w A ∧ (∀ v, X.mo w v → X.mo v A → False) ∧
      match site with
      | .skipBusyO2 | .waitingO2 =>
          (w = O.init ∨ ∃ B, O.relOf B w) ∧ X.rval A = 0
      | .takeoverO3 => O.isAcqCas w ∧ OwnerWord.registration (X.rval A) ≠ 0 := by
  have hA := C.acquiring
  obtain ⟨w, hrf⟩ := O.acq_reads hA
  refine ⟨w, hrf, hc.rmw_mo hrf (O.acq_w hA), ?_, ?_⟩
  · intro v hwv hvA
    exact rmw_adjacent hc hrf hwv hvA
  · have hk := C.expected_kind
    cases site with
    | skipBusyO2 => exact empty_owner_read_source W I hc hrf (O.acq_loc hA) hk
    | waitingO2 => exact empty_owner_read_source W I hc hrf (O.acq_loc hA) hk
    | takeoverO3 => exact ⟨(owner_load_source W hc hrf (O.acq_loc hA) hk).1, hk⟩

/-- The source's two O2 sites and its O3 site agree with the previously
abstract RF classes, so the original OW2 block lemmas apply to the code. -/
theorem code_owner_cas_kind {O : OwnerEvents X} {capacity : Nat}
    (W : OwnerSource O capacity) (I : OwnerIssuedArguments O) (hc : X.Consistent)
    {A : Ev} {site : OwnerCasSite} (C : OwnerCasCall O A site) :
    (match site with
      | .skipBusyO2 | .waitingO2 => O.isO2 A ∧ ¬ O.isO3 A
      | .takeoverO3 => O.isO3 A ∧ ¬ O.isO2 A) := by
  obtain ⟨w, hrf, _, _, hkind⟩ := code_owner_cas_predecessor W I hc C
  have hA := C.acquiring
  cases site with
  | skipBusyO2 | waitingO2 =>
    refine ⟨⟨hA, w, hrf, hkind.1⟩, ?_⟩
    rintro ⟨_, v, hv, hva⟩
    have hnonzero := (acquiring_owner_nonempty I hva).2
    exact hnonzero ((hc.rf_val hv).symm.trans hkind.2)
  | takeoverO3 =>
    refine ⟨⟨hA, w, hrf, hkind.1⟩, ?_⟩
    rintro ⟨_, v, hv, hzero⟩
    have hz : X.wval v = 0 := by
      rcases hzero with rfl | ⟨B, hrel⟩
      · exact W.init_none
      · exact W.release_none hrel
    have hrv := (hc.rf_val hv).trans hz
    exact hkind.2 (by rw [hrv]; rfl)

end Sgc.Execution
