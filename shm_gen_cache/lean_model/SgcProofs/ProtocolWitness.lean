import SgcProofs.Rotation

/-!
# A witness for the protocols

`OwnerProtocol` and `RotationProtocol` are long lists of hypotheses. This
file shows they can all hold at once, together with every hypothesis of
`pin_visibility_closed`, by building one consistent execution of twenty
events:

* a writer pins epoch 2, fences and loads the control word unsealed;
* tenure `A1` rechecks epoch 2, seals, publishes 3 and releases;
* tenure `A2` rechecks epoch 3, seals, publishes 4 and releases;
* tenure `B` rechecks epoch 4, makes a `SeqCst` fence `Fo` under
  ownership and scans the writer's pin. This is the code's shape for the
  reuse scan: `Fo` stands for R1F, the fence `rotate_owned()` makes under
  ownership right after its seal loop (`rotation.rs`, line 131). `B`'s own
  seal is not modelled; `pin_visibility_closed` needs only that the fence
  is under ownership. `CacheWitness.lean` witnesses the same shape inside
  the whole cache.

Every relation agrees with one total order of the events (`rank`): it is a
sequentially consistent run. Initialisation is thread 0, sequenced before
everything else.
-/

namespace Sgc
namespace ProtocolWitness

inductive E
  | Io | A1 | r1 | A2 | r2 | B
  | Ie | q1 | p1 | q2 | p2 | qB
  | Ci | Z1 | Z2
  | W2 | W3 | B0 | Fo | S
  deriving DecidableEq

inductive Word
  | own | ep | c2 | c3 | pin
  deriving DecidableEq

open E

def rank : E → Nat
  | Io => 0 | Ie => 1 | Ci => 2 | W2 => 3 | W3 => 4 | B0 => 5
  | A1 => 6 | q1 => 7 | Z1 => 8 | p1 => 9 | r1 => 10
  | A2 => 11 | q2 => 12 | Z2 => 13 | p2 => 14 | r2 => 15
  | B => 16 | qB => 17 | Fo => 18 | S => 19

/-- Thread of an event; 0 is initialisation. -/
def thr : E → Nat
  | Io | Ie | Ci => 0
  | W2 | W3 | B0 => 1
  | A1 | q1 | Z1 | p1 | r1 => 2
  | A2 | q2 | Z2 | p2 | r2 => 3
  | B | qB | Fo | S => 4

def locOf : E → Option Word
  | Io | A1 | r1 | A2 | r2 | B => some Word.own
  | Ie | q1 | p1 | q2 | p2 | qB => some Word.ep
  | Ci | Z1 | B0 => some Word.c2
  | Z2 => some Word.c3
  | W2 | S => some Word.pin
  | W3 | Fo => none

def isWB : E → Bool
  | Io | A1 | r1 | A2 | r2 | B | Ie | p1 | p2 | Ci | Z1 | Z2 | W2 => true
  | _ => false

def isRB : E → Bool
  | A1 | A2 | B | q1 | q2 | qB | B0 | Z1 | S => true
  | _ => false

/-- The write each read takes its value from. -/
def src : E → Option E
  | A1 => some Io | A2 => some r1 | B => some r2
  | q1 => some Ie | q2 => some p1 | qB => some p2
  | B0 => some Ci | Z1 => some Ci | S => some W2
  | _ => none

/-- Values written. A control word is `2 * stamp + sealed`. -/
def wv : E → Nat
  | Ie => 2 | p1 => 3 | p2 => 4
  | Ci => 4 | Z1 => 5 | Z2 => 7
  | _ => 0

def rv (e : E) : Nat :=
  match src e with
  | some w => wv w
  | none => 0

def relB : E → Bool
  | A1 | A2 | B | r1 | r2 | p1 | p2 | W2 => true
  | _ => false

def acqB : E → Bool
  | A1 | A2 | B => true
  | _ => false

def fenceB : E → Bool
  | W3 | Fo => true
  | _ => false

abbrev sbP (a b : E) : Prop := rank a < rank b ∧ (thr a = thr b ∨ thr a = 0)
abbrev rfP (w r : E) : Prop := src r = some w
abbrev moP (a b : E) : Prop :=
  isWB a = true ∧ isWB b = true ∧ locOf a = locOf b ∧ locOf a ≠ none ∧ rank a < rank b

def X : Execution E Word where
  loc := locOf
  isW := fun e => isWB e = true
  isR := fun e => isRB e = true
  isRel := fun e => relB e = true
  isAcq := fun e => acqB e = true
  isRelFence := fun e => fenceB e = true
  isAcqFence := fun e => fenceB e = true
  isScFence := fun e => fenceB e = true
  sb := sbP
  rf := rfP
  mo := moP
  asw := fun _ _ => False
  wval := wv
  rval := rv

/-! ## Raw facts, by enumeration -/

theorem rank_inj : ∀ a b : E, rank a = rank b → a = b := by
  intro a b; cases a <;> cases b <;> decide

theorem rf_rank : ∀ w r : E, rfP w r → rank w < rank r := by
  intro w r; cases r <;> cases w <;> decide

theorem rf_wf_raw : ∀ w r : E, rfP w r →
    isWB w = true ∧ isRB r = true ∧ locOf w = locOf r ∧ locOf w ≠ none := by
  intro w r; cases r <;> cases w <;> decide

theorem rmw_mo_raw : ∀ w u : E, rfP w u → isWB u = true → moP w u := by
  intro w u; cases u <;> cases w <;> decide

/-- A read takes the last write before it: nothing later in `mo` than its
source comes before the read. -/
theorem rb_rank : ∀ r w w₀ : E, rfP w₀ r → moP w₀ w → rank r ≤ rank w := by
  intro r w w₀ h
  cases r <;> simp [rfP, src] at h <;> (subst h; cases w <;> decide)

theorem rf_val_raw : ∀ w r : E, rfP w r → rv r = wv w := by
  intro w r h
  simp [rv, show src r = some w from h]

/-! ## Consistency -/

theorem rs_rank {w w' : E} (h : X.rs w w') : rank w ≤ rank w' := by
  induction h with
  | refl => exact Nat.le_refl _
  | rmw _ hrf _ ih => exact Nat.le_trans ih (Nat.le_of_lt (rf_rank _ _ hrf))

theorem sw_rank {a b : E} (h : X.sw a b) : rank a < rank b := by
  cases h with
  | relAcq _ hrs hrf _ =>
      exact Nat.lt_of_le_of_lt (rs_rank hrs) (rf_rank _ _ hrf)
  | relFence _ hrs hrf hsb _ =>
      exact Nat.lt_trans (Nat.lt_of_le_of_lt (rs_rank hrs) (rf_rank _ _ hrf)) hsb.1
  | fenceAcq _ hsb _ hrs hrf _ =>
      exact Nat.lt_trans hsb.1 (Nat.lt_of_le_of_lt (rs_rank hrs) (rf_rank _ _ hrf))
  | fenceFence _ hsb _ hrs hrf hsb' _ =>
      exact Nat.lt_trans hsb.1
        (Nat.lt_trans (Nat.lt_of_le_of_lt (rs_rank hrs) (rf_rank _ _ hrf)) hsb'.1)

theorem hb_rank {a b : E} (h : X.hb a b) : rank a < rank b :=
  transGen_lt_of_rank (fun _ _ hs => hs.elim (fun h => h.1) (fun h => h.elim sw_rank False.elim)) h

theorem cob_rank {a b : E} (h : X.cob a b) : rank a ≤ rank b :=
  transGen_le_of_rank
    (fun a b hs => hs.elim (fun h => Nat.le_of_lt (rf_rank a b h))
      (fun h => h.elim (fun h => Nat.le_of_lt h.2.2.2.2)
        (fun ⟨_, w₀, hrf, hmo⟩ => rb_rank a b w₀ hrf hmo))) h

theorem pscF_rank (a b : E) (h : X.pscF a b) : rank a < rank b := by
  obtain ⟨_, _, h | ⟨c, d, h₁, h₂, h₃⟩⟩ := h
  · exact hb_rank h
  · exact Nat.lt_of_lt_of_le (hb_rank h₁) (Nat.le_trans (cob_rank h₂) (Nat.le_of_lt (hb_rank h₃)))

theorem consistent : X.Consistent where
  rf_wf := fun h => rf_wf_raw _ _ h
  mo_wf := fun h => ⟨h.1, h.2.1, h.2.2.1, h.2.2.2.1⟩
  mo_irrefl := fun _ h => Nat.lt_irrefl _ h.2.2.2.2
  mo_trans := fun h₁ h₂ =>
    ⟨h₁.1, h₂.2.1, h₁.2.2.1.trans h₂.2.2.1, h₁.2.2.2.1, Nat.lt_trans h₁.2.2.2.2 h₂.2.2.2.2⟩
  mo_wellFounded :=
    Subrelation.wf (fun {a b} (h : moP a b) => h.2.2.2.2) (measure rank).wf
  rf_val := fun h => rf_val_raw _ _ h
  rf_unique := fun (h : src _ = some _) h' => Option.some.inj (h.symm.trans h')
  mo_total := by
    intro a b ha hb hloc hsome
    rcases Nat.lt_trichotomy (rank a) (rank b) with h | h | h
    · exact Or.inr (Or.inl ⟨ha, hb, hloc, hsome, h⟩)
    · exact Or.inl (rank_inj a b h)
    · exact Or.inr (Or.inr ⟨hb, ha, hloc.symm, fun h' => hsome (hloc.trans h'), h⟩)
  scFence_relAcq := fun h => ⟨h, h⟩
  hb_irrefl := fun _ h => Nat.lt_irrefl _ (hb_rank h)
  coherence := fun h₁ h₂ => Nat.not_le_of_gt (hb_rank h₁) (cob_rank h₂)
  rmw_mo := fun h hu => rmw_mo_raw _ _ h hu
  atomicity := fun ⟨_, w₀, hrf, hmo⟩ h₂ => Nat.not_le_of_gt h₂.2.2.2.2 (rb_rank _ _ w₀ hrf hmo)
  sc_fences := fun _ h => Nat.lt_irrefl _ (transGen_lt_of_rank pscF_rank h)

/-! ## The owner protocol -/

open Execution

abbrev isAcqCasP (e : E) : Prop := e = A1 ∨ e = A2 ∨ e = B
abbrev relOfP (A r : E) : Prop := (A = A1 ∧ r = r1) ∨ (A = A2 ∧ r = r2)

theorem init_first_own : ∀ e : E, isWB e = true → locOf e = some Word.own → e ≠ Io →
    sbP Io e := by
  intro e; cases e <;> decide

theorem writes_own : ∀ e : E, isWB e = true → locOf e = some Word.own →
    e = Io ∨ isAcqCasP e ∨ ∃ A, relOfP A e := by
  intro e hw hl
  cases e <;> first
    | exact Or.inl rfl
    | exact Or.inr (Or.inl (by decide))
    | exact Or.inr (Or.inr ⟨A1, by decide⟩)
    | exact Or.inr (Or.inr ⟨A2, by decide⟩)
    | exact absurd hl (by decide)

theorem acq_facts : ∀ A : E, isAcqCasP A →
    isWB A = true ∧ locOf A = some Word.own ∧ acqB A = true ∧ (src A).isSome = true ∧
    relB A = true := by
  intro A; cases A <;> decide

theorem rel_facts : ∀ A r : E, relOfP A r →
    isAcqCasP A ∧ isWB r = true ∧ locOf r = some Word.own ∧ relB r = true ∧
    ¬ isAcqCasP r ∧ sbP A r := by
  intro A r; cases A <;> cases r <;> decide

theorem rel_unique_raw : ∀ A r r' : E, relOfP A r → relOfP A r' → r = r' := by
  intro A r r' h h'
  rcases h with ⟨rfl, rfl⟩ | ⟨rfl, rfl⟩ <;> rcases h' with ⟨h₁, rfl⟩ | ⟨h₁, rfl⟩ <;>
    first | rfl | exact absurd h₁ (by decide)

/-- No tenure is taken over in this execution: every acquiring CAS reads a
`NONE`. -/
theorem no_takeover : ∀ A T : E, isAcqCasP A → isAcqCasP T → ¬ rfP A T := by
  intro A T; cases A <;> cases T <;> decide

def ownerProtocol : OwnerProtocol X where
  own := Word.own
  init := Io
  isAcqCas := isAcqCasP
  relOf := relOfP
  init_w := rfl
  init_loc := rfl
  init_first := fun hw hl hne => hb_of_sb (init_first_own _ hw hl hne)
  writes := fun hw hl => writes_own _ hw hl
  acq_w := fun h => (acq_facts _ h).1
  acq_loc := fun h => (acq_facts _ h).2.1
  acq_isAcq := fun h _ _ => (acq_facts _ h).2.2.1
  acq_isRel := fun h => (acq_facts _ h).2.2.2.2
  acq_reads := fun {A} h => by
    have := (acq_facts A h).2.2.2.1
    cases hs : src A with
    | none => rw [hs] at this; exact absurd this (by decide)
    | some w => exact ⟨w, hs⟩
  rel_acq := fun h => (rel_facts _ _ h).1
  rel_w := fun h => (rel_facts _ _ h).2.1
  rel_loc := fun h => (rel_facts _ _ h).2.2.1
  rel_isRel := fun h => (rel_facts _ _ h).2.2.2.1
  rel_not_acq := fun h => (rel_facts _ _ h).2.2.2.2.1
  rel_sb := fun h => (rel_facts _ _ h).2.2.2.2.2
  rel_unique := fun h h' => rel_unique_raw _ _ _ h h'
  takeover := fun hA hT hrf => absurd hrf (no_takeover _ _ hA hT)

/-! ## The rotation protocol -/

abbrev r0OfP (A q : E) : Prop := (A = A1 ∧ q = q1) ∨ (A = A2 ∧ q = q2) ∨ (A = B ∧ q = qB)
abbrev r5OfP (A p : E) : Prop := (A = A1 ∧ p = p1) ∨ (A = A2 ∧ p = p2)
abbrev sealOfP (A Z : E) : Prop := (A = A1 ∧ Z = Z1) ∨ (A = A2 ∧ Z = Z2)

/-- Epoch 2 is served by the arena whose control word is `c2`; every other
epoch this execution reaches, by `c3`. -/
def ctlLocF (n : Nat) : Word := if n = 2 then Word.c2 else Word.c3

theorem ctlLoc_cases (n : Nat) : ctlLocF n = Word.c2 ∨ ctlLocF n = Word.c3 := by
  unfold ctlLocF; split <;> simp

/-- The two halves of `under`, in a form that can be decided. -/
abbrev underP (A e : E) : Prop :=
  (e = A ∨ sbP A e) ∧ (A = A1 → (e = r1 ∨ sbP e r1)) ∧ (A = A2 → (e = r2 ∨ sbP e r2))

theorem under_of {A e : E} (h : underP A e) : ownerProtocol.under A e :=
  ⟨h.1, fun r hr => by
    rcases hr with ⟨hA, rfl⟩ | ⟨hA, rfl⟩
    · exact h.2.1 hA
    · exact h.2.2 hA⟩

theorem ep_first : ∀ e : E, isWB e = true → locOf e = some Word.ep → e ≠ Ie → sbP Ie e := by
  intro e; cases e <;> decide

theorem ep_writes_raw : ∀ e : E, isWB e = true → locOf e = some Word.ep →
    e = Ie ∨ ∃ A, r5OfP A e := by
  intro e hw hl
  cases e <;> first
    | exact Or.inl rfl
    | exact Or.inr ⟨A1, by decide⟩
    | exact Or.inr ⟨A2, by decide⟩
    | exact absurd hw (by decide)
    | exact absurd hl (by decide)

theorem r0_facts : ∀ A q : E, r0OfP A q →
    isAcqCasP A ∧ underP A q ∧ locOf q = some Word.ep ∧ (src q).isSome = true := by
  intro A q; cases A <;> cases q <;> decide

theorem r0_unique_raw : ∀ A q q' : E, r0OfP A q → r0OfP A q' → q = q' := by
  intro A q q' h h'
  rcases h with ⟨rfl, rfl⟩ | ⟨rfl, rfl⟩ | ⟨rfl, rfl⟩ <;>
    rcases h' with ⟨h₁, rfl⟩ | ⟨h₁, rfl⟩ | ⟨h₁, rfl⟩ <;>
    first | rfl | exact absurd h₁ (by decide)

theorem r5_facts : ∀ A p : E, r5OfP A p →
    isAcqCasP A ∧ underP A p ∧ isWB p = true ∧ locOf p = some Word.ep := by
  intro A p; cases A <;> cases p <;> decide

theorem r5_unique_raw : ∀ A p p' : E, r5OfP A p → r5OfP A p' → p = p' := by
  intro A p p' h h'
  rcases h with ⟨rfl, rfl⟩ | ⟨rfl, rfl⟩ <;> rcases h' with ⟨h₁, rfl⟩ | ⟨h₁, rfl⟩ <;>
    first | rfl | exact absurd h₁ (by decide)

theorem r5_r0_raw : ∀ A p : E, r5OfP A p →
    ∃ q, r0OfP A q ∧ sbP q p ∧ wv p = rv q + 1 := by
  intro A p h
  rcases h with ⟨rfl, rfl⟩ | ⟨rfl, rfl⟩
  · exact ⟨q1, by decide, by decide, by decide⟩
  · exact ⟨q2, by decide, by decide, by decide⟩

theorem ctl_writes_raw : ∀ (e : E) (n : Nat), isWB e = true → locOf e = some (ctlLocF n) →
    e = Ci ∨ False ∨ (∃ A, sealOfP A e) ∨ (∃ _A : E, False) := by
  intro e n hw hl
  cases e <;> first
    | exact Or.inl rfl
    | exact Or.inr (Or.inr (Or.inl ⟨A1, by decide⟩))
    | exact Or.inr (Or.inr (Or.inl ⟨A2, by decide⟩))
    | exact absurd hw (by decide)
    | (rcases ctlLoc_cases n with h | h <;> rw [h] at hl <;> exact absurd hl (by decide))

theorem ctlInit_first_raw : ∀ e : E, isWB e = true → locOf e = locOf Ci → e ≠ Ci → sbP Ci e := by
  intro e; cases e <;> decide

theorem seal_facts : ∀ A Z : E, sealOfP A Z →
    isAcqCasP A ∧ underP A Z ∧ isWB Z = true ∧ wv Z % 2 = 1 := by
  intro A Z; cases A <;> cases Z <;> decide

theorem seal_r0_raw : ∀ A Z : E, sealOfP A Z →
    ∃ q, r0OfP A q ∧ wv Z / 2 = rv q ∧ locOf Z = some (ctlLocF (rv q)) := by
  intro A Z h
  rcases h with ⟨rfl, rfl⟩ | ⟨rfl, rfl⟩
  · exact ⟨q1, by decide, by decide, by decide⟩
  · exact ⟨q2, by decide, by decide, by decide⟩

theorem seal_before_r5_raw : ∀ A Z p : E, sealOfP A Z → r5OfP A p → sbP Z p := by
  intro A Z p h h'
  rcases h with ⟨rfl, rfl⟩ | ⟨rfl, rfl⟩ <;> rcases h' with ⟨h₁, rfl⟩ | ⟨h₁, rfl⟩ <;>
    first | decide | exact absurd h₁ (by decide)

def rotationProtocol : RotationProtocol X where
  toOwnerProtocol := ownerProtocol
  ep := Word.ep
  epInit := Ie
  r0Of := r0OfP
  r5Of := r5OfP
  ctlLoc := ctlLocF
  stamp := fun v => v / 2
  isSealed := fun v => v % 2 = 1
  isCtlInit := fun e => e = Ci
  isB1 := fun _ => False
  sealOf := sealOfP
  r4Of := fun _ _ => False
  -- This witness isolates pin visibility; it does not model B's seal loop.
  r1fOf := fun _ _ _ => False
  epInit_w := rfl
  epInit_loc := rfl
  epInit_val := rfl
  epInit_first := fun hw hl hne => hb_of_sb (ep_first _ hw hl hne)
  ep_writes := fun hw hl => ep_writes_raw _ hw hl
  r0_acq := fun h => (r0_facts _ _ h).1
  r0_under := fun h => under_of (r0_facts _ _ h).2.1
  r0_loc := fun h => (r0_facts _ _ h).2.2.1
  r0_reads := fun {A q} h => by
    have := (r0_facts A q h).2.2.2
    cases hs : src q with
    | none => rw [hs] at this; exact absurd this (by decide)
    | some w => exact ⟨w, hs⟩
  r0_unique := fun h h' => r0_unique_raw _ _ _ h h'
  r5_acq := fun h => (r5_facts _ _ h).1
  r5_under := fun h => under_of (r5_facts _ _ h).2.1
  r5_w := fun h => (r5_facts _ _ h).2.2.1
  r5_loc := fun h => (r5_facts _ _ h).2.2.2
  r5_unique := fun h h' => r5_unique_raw _ _ _ h h'
  r5_r0 := fun h => r5_r0_raw _ _ h
  ctl_writes := fun hw hl => ctl_writes_raw _ _ hw hl
  ctlInit_first := fun {i e} hi hw hl hne => by
    subst hi; exact hb_of_sb (ctlInit_first_raw e hw hl hne)
  ctlInit_val := fun {i} hi hs => by
    subst hi; exact absurd hs (by decide)
  b1_rmw := fun h => h.elim
  seal_acq := fun h => (seal_facts _ _ h).1
  seal_under := fun h => under_of (seal_facts _ _ h).2.1
  seal_w := fun h => (seal_facts _ _ h).2.2.1
  seal_sealed := fun h => (seal_facts _ _ h).2.2.2
  seal_r0 := fun h => seal_r0_raw _ _ h
  seal_before_r5 := fun h h' => seal_before_r5_raw _ _ _ h h'
  seal_before_r4 := fun _ h => h.elim
  r4_acq := fun h => h.elim
  r4_under := fun h => h.elim
  r4_w := fun h => h.elim
  r4_r0 := fun h => h.elim
  r4_unsealed := fun h => h.elim
  loop_exit := fun {A p q} h5 _ => by
    rcases h5 with ⟨rfl, _⟩ | ⟨rfl, _⟩
    · exact Or.inl ⟨Z1, by decide⟩
    · exact Or.inl ⟨Z2, by decide⟩
  r1f_facts := fun h => h.elim

/-- Every hypothesis of `pin_visibility_closed` holds in this execution, for
the writer pinned to epoch 2 and the tenure `B` that rechecks epoch 4. -/
theorem closed_instance : ¬ X.mo W2 W2 :=
  rotationProtocol.pin_visibility_closed consistent
    (W2 := W2) (W3 := W3) (B0 := B0) (f := Fo) (Sc := S) (u := Ci) (p := W2)
    (B := B) (qB := qB) (e := 2)
    rfl rfl
    (by decide : sbP W2 W3) (by decide : sbP W3 B0)
    (rfl : src B0 = some Ci)
    (by decide : locOf B0 = some (ctlLocF 2))
    (by decide : wv Ci / 2 = 2) (by decide : ¬ wv Ci % 2 = 1)
    (Nat.le_refl 2)
    (by decide : r0OfP B qB) (by decide : rv qB = 2 + 2)
    (under_of (by decide : underP B Fo))
    (by decide : sbP Fo S) (rfl : src S = some W2)

end ProtocolWitness
end Sgc
