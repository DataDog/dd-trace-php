import SgcProofs.RecordBytes
import SgcProofs.CacheWitness

namespace Sgc.CacheWitness
open Execution Execution.RecordBytes E

/-- A real eight-byte key/value; all eight bytes are represented, even
though only the first byte is nonzero in this witness. -/
def bytes8 (n : Nat) (h : n < 2 ^ 64) : Bytes where
  size := 8
  word := fun _ => n
  bounded := fun _ => h

def emptyBytes : Bytes where
  size := 0
  word := fun _ => 0
  bounded := fun _ => by decide

def concreteKey : E → Bytes
  | aW | aW3 => bytes8 7 (by decide)
  | _ => emptyBytes

def concreteValue : E → Bytes
  | aW | aW3 => bytes8 42 (by decide)
  | _ => emptyBytes

/-- The offset returned by each B1 or H1 in this schedule. This proof
uses only reservation events; it does not appeal to any publication. -/
theorem concrete_reservation {i w} (hw : cache.isAttempt i w) :
    reserve.reserves i w (if w = bW2 then 16 else 0) := by
  rcases hw with rfl | rfl | rfl | rfl
  · exact Or.inl ⟨aB, inAtt_mk (by decide) (by decide), Or.inl rfl,
      by unfold ReserveProtocol.wins; decide, rfl⟩
  · exact Or.inl ⟨bB, inAtt_mk (by decide) (by decide), Or.inr (Or.inl rfl),
      by unfold ReserveProtocol.wins; decide, rfl⟩
  · exact Or.inr ⟨bLe, bLc, bLn, bMc, ⟨rfl, rfl, rfl, rfl, rfl⟩, rfl⟩
  · exact Or.inl ⟨aB3, inAtt_mk (by decide) (by decide), Or.inr (Or.inr rfl),
      by unfold ReserveProtocol.wins; decide, rfl⟩

/-- The concrete W4/W5 contents hold in the existing concurrent witness,
including the promoted record and the later chunk-cut replacement. -/
def contents : Contents index where
  key := concreteKey
  value := concreteValue
  key_length := fun {i w} hw => by rcases hw with rfl | rfl | rfl | rfl <;> decide
  value_length := fun {i w} hw => by rcases hw with rfl | rfl | rfl | rfl <;> decide
  header_reserved := fun {i w r z l} hw hs hz hzW hl hr hoff => by
    change isRecordP l at hr
    have hdz := inAtt_dec hw hz
    have hrsv := reserve.reserve_unique hw hs (concrete_reservation hw)
    rw [hrsv] at hoff
    rcases hw with rfl | rfl | rfl | rfl
    all_goals cases z <;> first | exact absurd hdz (by decide) |
      exact absurd hzW (by decide) | skip
    all_goals cases l <;> first | exact absurd hl (by decide) |
      exact absurd hr (by decide) | exact absurd hoff (by decide) | decide
  lengths_reserved := fun {i w r z l} hw hs hz hzW hl hr hoff => by
    change isRecordP l at hr
    have hdz := inAtt_dec hw hz
    have hrsv := reserve.reserve_unique hw hs (concrete_reservation hw)
    rw [hrsv] at hoff
    rcases hw with rfl | rfl | rfl | rfl
    all_goals cases z <;> first | exact absurd hdz (by decide) |
      exact absurd hzW (by decide) | skip
    all_goals cases l <;> first | exact absurd hl (by decide) |
      exact absurd hr (by decide) | exact absurd hoff (by decide) | decide
  key_reserved := fun {i w r z l p} hw hs hz hzW hl hr hp hoff => by
    change isRecordP l at hr
    have hdz := inAtt_dec hw hz
    have hrsv := reserve.reserve_unique hw hs (concrete_reservation hw)
    rw [hrsv] at hoff
    rcases hw with rfl | rfl | rfl | rfl
    all_goals simp only [concreteKey, bytes8, emptyBytes] at hp
    all_goals first | omega | skip
    all_goals have hp0 : p = 0 := by omega
    all_goals subst p
    all_goals cases z <;> first | exact absurd hdz (by decide) |
      exact absurd hzW (by decide) | skip
    all_goals cases l <;> first | exact absurd hl (by decide) |
      exact absurd hr (by decide) | exact absurd hoff (by decide) | decide
  value_reserved := fun {i w r z l p} hw hs hz hzW hl hr hp hoff => by
    change isRecordP l at hr
    have hdz := inAtt_dec hw hz
    have hrsv := reserve.reserve_unique hw hs (concrete_reservation hw)
    rw [hrsv] at hoff
    rcases hw with rfl | rfl | rfl | rfl
    all_goals simp only [concreteValue, bytes8, emptyBytes] at hp
    all_goals first | omega | skip
    all_goals have hp0 : p = 0 := by omega
    all_goals subst p
    all_goals cases z <;> first | exact absurd hdz (by decide) |
      exact absurd hzW (by decide) | skip
    all_goals cases l <;> first | exact absurd hl (by decide) |
      exact absurd hr (by decide) | exact absurd hoff (by decide) | decide

def hitAIO : IndexProtocol.HitIO index hitA where
  recordLoad := hitA.loads
  all_loads := fun _ h => h
  no_omitted_loads := fun _ h => h
  header := aY
  lengths := aYl
  header_load := Or.inl rfl
  lengths_load := Or.inr (Or.inl rfl)
  header_rf := ⟨aR, rfl⟩
  lengths_rf := ⟨aRl, rfl⟩
  lengths_loc := ⟨Word.rc2, rfl, Or.inr (Or.inl rfl), rfl, rfl⟩
  keyWords := 1
  valueWords := 1
  inputLength := 8
  returnedLength := 8
  key_length_checked := rfl
  returned_length := rfl
  key_words := rfl
  value_words := rfl
  keyLoad := fun _ => aYk
  valueLoad := fun _ => aYv
  key_load := fun _ => Or.inr (Or.inr (Or.inl rfl))
  value_load := fun _ => Or.inr (Or.inr (Or.inr rfl))
  key_address := fun p => by
    have hp : p.val = 0 := by omega
    exact ⟨Word.rcA, rfl, Or.inr (Or.inr (Or.inl rfl)), by simp; rfl⟩
  value_address := fun p => by
    have hp : p.val = 0 := by omega
    exact ⟨Word.rcB, rfl, Or.inr (Or.inr (Or.inr (Or.inl rfl))), by simp; rfl⟩
  load_shapes := fun Y h => by
    rcases h with rfl | rfl | rfl | rfl
    · exact Or.inl rfl
    · exact Or.inr (Or.inl rfl)
    · exact Or.inr (Or.inr (Or.inl ⟨0, rfl⟩))
    · exact Or.inr (Or.inr (Or.inr ⟨0, rfl⟩))
  key_rf := fun _ => ⟨aRk, rfl⟩
  value_rf := fun _ => ⟨aRv, rfl⟩
  inputWord := fun _ => 7
  keyMask := fun _ => 2 ^ 64 - 1
  outputWord := fun _ => 42
  key_match := fun _ => rfl
  value_copy := fun _ => rfl

def hitABuffers : HitBytes hitA hitAIO where
  input := bytes8 7 (by decide)
  output := bytes8 42 (by decide)
  input_size := rfl
  output_size := rfl
  input_word := fun _ => rfl
  masks := fun p => by
    have hp : p.val = 0 := by have := p.isLt; change p.val < 1 at this; omega
    simp only [hitAIO, mask, bytes8, hp]
    rfl
  output_word := fun _ => rfl

def sources : Sources contents where
  Call := Bool
  occurred := fun _ => True
  callKey := fun a => if a then bytes8 7 (by decide) else emptyBytes
  callValue := fun a => if a then bytes8 42 (by decide) else emptyBytes
  promo := fun w => w = aW3
  insert_src := fun {i w} hw hp => by
    rcases hw with rfl | rfl | rfl | rfl
    · exact ⟨true, trivial, sameBytes_refl _, ⟨rfl, fun _ _ => rfl⟩⟩
    · exact ⟨false, trivial, sameBytes_refl _, ⟨rfl, fun _ _ => rfl⟩⟩
    · exact ⟨false, trivial, sameBytes_refl _, ⟨rfl, fun _ _ => rfl⟩⟩
    · exact False.elim (hp rfl)
  promo_src := fun {i w} _ hp => by
    subst w
    exact ⟨hitA, hitAIO, hitABuffers, rfl, rfl, sameBytes_refl _,
      ⟨rfl, fun _ _ => rfl⟩⟩

theorem concrete_hit :
    ∃ i w, cache.isAttempt i w ∧ (cache.pins i).inAttempt w hitA.x2 ∧
      cache.attEpoch w = hitA.e ∧ SameBytes hitABuffers.input (contents.key w) ∧
      SameBytes hitABuffers.output (contents.value w) ∧
      (∀ p, p < (hitABuffers.output.size + 7) / 8 →
        hitABuffers.output.word p = (contents.value w).word p) :=
  hit_bytes_exact contents consistent hitA hitAIO hitABuffers

theorem concrete_promotion :
    ∃ call, sources.occurred call ∧ SameBytes (contents.key aW3) (sources.callKey call) ∧
      SameWords (contents.value aW3) (sources.callValue call) :=
  bytes_provenance contents sources consistent (i := ())
    (Or.inr (Or.inr (Or.inr rfl)))

/-- Observe the promotion after its value W4, before W5 or I2. This is
a prefix of the concrete consistent execution, not a claim that the
complete witness never later publishes. The byte theorem applies at this
point without using a future publication of this attempt. -/
theorem promotion_prefix_before_publication :
    (∀ x, (cache.pins ()).inAttempt aW3 x → rank x ≤ rank aR3v →
      ¬ (index.isPubCas x ∨ index.isPubStore x)) ∧
    ∃ call, sources.occurred call ∧
      (sources.callKey call).size = 8 ∧ (sources.callValue call).size = 8 ∧
      keyLen (X.wval aR3l) = (sources.callKey call).size ∧
      valLen (X.wval aR3l) = (sources.callValue call).size ∧
      X.wval aR3k &&& mask 8 0 = (sources.callKey call).word 0 &&& mask 8 0 ∧
      X.wval aR3v = (sources.callValue call).word 0 := by
  have hw : cache.isAttempt () aW3 := Or.inr (Or.inr (Or.inr rfl))
  constructor
  · intro x hx htime hk
    have hdx := inAtt_dec hw hx
    rcases hk with (rfl | rfl | rfl) | rfl <;>
      first | exact absurd hdx (by decide) | exact absurd htime (by decide)
  · obtain ⟨call, hcall, hk, hv, hstores⟩ :=
      attempt_stored_bytes_provenance contents sources consistent hw
    have hks : (sources.callKey call).size = 8 := hk.1.symm
    have hvs : (sources.callValue call).size = 8 := hv.1.symm
    have hs := hstores 0 (concrete_reservation hw)
    have hl := (hs aR3l Word.rc3b (inAtt_mk (by decide) (by decide)) rfl rfl
      (Or.inr (Or.inr (Or.inr (Or.inr (Or.inr (Or.inl rfl))))))).1 rfl
    have hkey := (hs aR3k Word.rc3c (inAtt_mk (by decide) (by decide)) rfl rfl
      (Or.inr (Or.inr (Or.inr (Or.inr (Or.inr (Or.inr (Or.inl rfl)))))))).2.1
      0 (by rw [hks]; decide) rfl
    have hvalue := (hs aR3v Word.rc3d (inAtt_mk (by decide) (by decide)) rfl rfl
      (Or.inr (Or.inr (Or.inr (Or.inr (Or.inr (Or.inr (Or.inr rfl)))))))).2.2
      0 (by rw [hvs]; decide) (by rw [hks]; rfl)
    exact ⟨call, hcall, hks, hvs, hl.1, hl.2, by simpa [hks] using hkey, hvalue⟩

def tableReads : TableReads index where
  slot := Word.idx
  l0 := bJ
  z := bI
  i := ()
  w := bW2
  attempt := Or.inr (Or.inr (Or.inl rfl))
  load_attempt := inAtt_mk (by decide) (by decide)
  read := rfl
  acquire := rfl
  load_loc := rfl
  index_slot := Or.inl rfl
  own_arena := rfl
  own_stamp := rfl
  loads := fun Y => Y = bK ∨ Y = bKl
  addresses := fun Y h => by
    rcases h with rfl | rfl
    · exact ⟨Word.rcd, rfl, Or.inl rfl, rfl, Or.inl (by decide)⟩
    · exact ⟨Word.rc2, rfl, Or.inr (Or.inl rfl), rfl, Or.inr (Or.inl (by decide))⟩
  after_load := fun Y h => by
    rcases h with rfl | rfl <;> exact ⟨by decide, inAtt_mk (by decide) (by decide)⟩

def entryRun : EntryRun tableReads where
  input := emptyBytes
  result := true
  header := bK
  lengths := bKl
  header_load := Or.inl rfl
  header_rf := ⟨bR, rfl⟩
  header_address := ⟨Word.rcd, rfl, Or.inl rfl, rfl⟩
  lengths_load := fun _ => Or.inr rfl
  lengths_rf := fun _ => ⟨bRl, rfl⟩
  lengths_address := fun _ => ⟨Word.rc2, rfl, Or.inr (Or.inl rfl), rfl⟩
  seen := fun _ => False
  keyLoad := fun _ => bK
  key_load := fun _ h => h.elim
  key_rf := fun _ h => h.elim
  key_bounds := fun _ h => h.elim
  key_address := fun _ h => h.elim
  flow := Or.inr ⟨⟨rfl, by decide, by decide⟩,
    Or.inr ⟨rfl, Or.inr ⟨rfl, KeyScan.done 0⟩⟩⟩

theorem concrete_table_check :
    ∃ j w', cache.isAttempt j w' ∧ (cache.pins j).inAttempt w' tableReads.z ∧
      cache.attEpoch w' = cache.attEpoch tableReads.w ∧
      (∀ Y, tableReads.loads Y → ∀ v, X.rf v Y → (cache.pins j).inAttempt w' v) ∧
      (entryRun.result = true ↔ SameBytes (contents.key w') entryRun.input) :=
  table_bytes_exact contents consistent tableReads entryRun

/-- The loop stops at the first unequal full word and needs no later
load, even with two words remaining. -/
theorem mismatch_prefix_witness :
    KeyScan 24 (fun _ => 7) (fun _ => 8) (fun p => p = 0) 0 3 false :=
  KeyScan.mismatch rfl (by decide)

/-- A full word then a partial tail: the tail's high padding differs but
the low byte matches. The visited set contains exactly these two loads. -/
theorem partial_tail_witness :
    KeyScan 9 (fun p => if p = 0 then 7 else 513)
      (fun p => if p = 0 then 7 else 1) (fun p => p < 2) 0 2 true :=
  KeyScan.next (by decide) (by decide)
    (KeyScan.next (by decide) (by decide) (KeyScan.done 2))

end Sgc.CacheWitness
