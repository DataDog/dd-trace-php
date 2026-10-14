import SgcProofs.Lookup
import SgcProofs.Bounds

/-!
# BD1 and BD2, bounded access, on the events

`Bounds.lean` has the arithmetic of the readers' checks. This file ties the
two quantities that come from shared memory and are not checked to the
events that wrote them:

* `record_store_in_area` (BD1, record stores W4 and W5): every record store of an attempt
  addresses a word inside the record area, because it lies in the
  attempt's reservation (`rec_in_res`) and the reservation lies in the
  area (SP4, `reserve_in_area`);
* `entry_in_area`, `index_load_in_area` (BD2): every value an index
  slot ever holds, the zero fill or a publication of any incarnation,
  encodes an offset whose first 16 bytes (the header and the lengths word)
  lie in the area; so does every value an index load returns, whichever
  write it reads;
* `check_exact`: with that, the readers' bound `len >
  ras.wrapping_sub(off).wrapping_sub(8)` does not wrap, and is the bound
  `Bounds.lean` assumes. It is modelled in `u32` as at `put.rs:131`;
  `probe.rs:166` subtracts the 8 in `u64`, which agrees once
  nothing wraps;
* `reader_in_bounds` (BD2): whatever the header and lengths
  loads (D4, D5) return, a reader that passed its checks addresses only
  words of the record area.

BD1's slot and arena indices (below `participant_capacity` and 3, A7) are
fixed array indexing and are not formalised.
-/

namespace Sgc
namespace Execution

variable {Ev Loc : Type}

namespace ReserveProtocol

variable {X : Execution Ev Loc} {C : CacheProtocol X} (Rv : ReserveProtocol X C)

/-- **BD1.** A record store of a store attempt addresses a word of
the record area. -/
theorem record_store_in_area (hc : X.Consistent) {i : C.ι} {w x : Ev} {l : Loc}
    (hw : C.isAttempt i w) (hx : (C.pins i).inAttempt w x) (hxW : X.isW x)
    (hxl : X.loc x = some l) (hrec : Rv.isRecord l) : Rv.recOff l + 8 ≤ Rv.cap := by
  obtain ⟨r, hr, h₁, h₂⟩ := Rv.rec_in_res hw hx hxW hxl hrec
  have hres : Rv.reserves i w r := by
    rcases hr with ⟨b, hb, hbB, hwin, rfl⟩ | ⟨le, lc, ln, s, hu, rfl⟩
    · exact Or.inl ⟨b, hb, hbB, hwin, rfl⟩
    · exact Or.inr ⟨le, lc, ln, s, hu, rfl⟩
  have := Rv.reserve_in_area hc hres
  omega

end ReserveProtocol

namespace IndexProtocol

variable {X : Execution Ev Loc} {C : CacheProtocol X} {A : ArenaProtocol X C}
  {Rv : ReserveProtocol X C} (I : IndexProtocol X A Rv)

/-- **BD2, the index values.** Every value an index slot holds encodes an
offset whose first 16 bytes lie in the record area. -/
theorem entry_in_area (hc : X.Consistent) {x : Ev} {l : Loc} (hxW : X.isW x)
    (hxl : X.loc x = some l) (hl : I.isIndex l) : I.entryOff (X.wval x) + 16 ≤ Rv.cap := by
  have hcap := I.cap_ge
  rcases I.idx_writes hxW hxl hl with ⟨-, hz⟩ | ⟨hxk, i, w, hw, hx⟩
  · rw [hz, I.zero_off]; omega
  · have := Rv.reserve_in_area hc (I.pub_off hxk hw hx)
    have := Rv.need_pos hw
    omega

/-- **BD2, the index loads.** Every value an index load returns, current
or stale, encodes an offset whose first 16 bytes lie in the record area. -/
theorem index_load_in_area (hc : X.Consistent) {x r : Ev} {l : Loc} (hrf : X.rf x r)
    (hrl : X.loc r = some l) (hl : I.isIndex l) : I.entryOff (X.rval r) + 16 ≤ Rv.cap := by
  have ⟨hxW, _, hlx, _⟩ := hc.rf_wf hrf
  rw [hc.rf_val hrf]
  exact I.entry_in_area hc hxW (hlx.trans hrl) hl

/-- The readers' bound `len <= ras.wrapping_sub(off).wrapping_sub(8)`, in
`u32` arithmetic, is exact once the header word is in the area. A5 keeps
the area below `2^31`. -/
theorem check_exact {ras off len : Nat} (hoff : off + 8 ≤ ras) (hras : ras < wordLimit)
    (hpass : len ≤ ((ras + wordLimit - off) % wordLimit + wordLimit - 8) % wordLimit) :
    len + off + 8 ≤ ras := by
  unfold wordLimit at *
  have h₁ : (ras + 4294967296 - off) % 4294967296 = ras - off := by omega
  rw [h₁] at hpass
  have h₂ : (ras - off + 4294967296 - 8) % 4294967296 = ras - off - 8 := by omega
  rw [h₂] at hpass
  omega

/-- **BD2.** A reader (`probe()` or `entry_has_key()`) loads
index slot `l` at `r` and decodes the offset `off`. Whatever its header
load returns for `len` and its lengths load for `kl` and `vl`, once its
checks pass (`8 <= len <= ras - off - 8` in `u32`, and
`8 + align8(kl) + vl = len`), the header, the lengths word, the padded key
and every value word it copies lie in the record area. -/
theorem reader_in_bounds (hc : X.Consistent) {x r : Ev} {l : Loc} {len kl vl pos : Nat}
    (hrf : X.rf x r) (hrl : X.loc r = some l) (hl : I.isIndex l)
    (hpass : len ≤
      ((Rv.cap + wordLimit - I.entryOff (X.rval r)) % wordLimit + wordLimit - 8) % wordLimit)
    (hlo : 8 ≤ len) (hcons : 8 + (kl + 7) / 8 * 8 + vl = len) (hpos : pos < vl)
    (hpos8 : pos % 8 = 0) :
    I.entryOff (X.rval r) + 8 ≤ Rv.cap ∧ I.entryOff (X.rval r) + 16 ≤ Rv.cap ∧
      I.entryOff (X.rval r) + 16 + (kl + 7) / 8 * 8 ≤ Rv.cap ∧
      I.entryOff (X.rval r) + 16 + (kl + 7) / 8 * 8 + pos + 8 ≤ Rv.cap := by
  have hoff := I.index_load_in_area hc hrf hrl hl
  have hras : Rv.cap < wordLimit := by
    have := Rv.a5; unfold bumpLimit at this; unfold wordLimit; omega
  have hhi := check_exact (by omega) hras hpass
  exact ⟨by omega, Bounds.lengths_in_bounds hlo hhi, Bounds.key_in_bounds hhi hcons,
    Bounds.value_in_bounds I.cap_aligned (I.entryOff_aligned _) hhi hcons hpos hpos8⟩

end IndexProtocol
end Execution
end Sgc
