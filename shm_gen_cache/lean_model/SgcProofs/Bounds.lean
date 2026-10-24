/-!
# BD1 and BD2, bounded access: the arithmetic

The two readers of a record (`probe()` and `entry_has_key()`) take the
header's length from shared memory and must not trust it. These are the
facts their checks rely on, with `ras` the record area size, `off` the
record's offset, `len` the header's length, and `kl`, `vl` the lengths word.
Index slots are addressed through the bucket mask, except in the group scan
(`group_scan_in_bounds`).
-/

namespace Sgc
namespace Bounds

/-- Both writer and reader index probes use `(start + probe) & bucket_mask`.
The mask is `bucket_count - 1`; the upper bound needs no trust in either
operand. -/
theorem masked_index_in_bounds {b mask start probe : Nat} (hmask : mask + 1 = b) :
    (start + probe) &&& mask < b := by
  have := Nat.and_le_right (n := start + probe) (m := mask)
  omega

/-- The bound the readers check, `8 ≤ len ≤ ras - off - 8`, also bounds the
padded record, because the area size and the offset are multiples of 8. -/
theorem padded_in_bounds {ras off len : Nat} (hras : ras % 8 = 0) (hoff : off % 8 = 0)
    (hhi : len + off + 8 ≤ ras) : off + 8 + (len + 7) / 8 * 8 ≤ ras := by
  omega

/-- The lengths word is inside the record once `len ≥ 8`. -/
theorem lengths_in_bounds {ras off len : Nat} (hlo : 8 ≤ len) (hhi : len + off + 8 ≤ ras) :
    off + 16 ≤ ras := by
  omega

/-- After the consistency check `8 + align8(kl) + vl = len`, the padded key
ends where the value begins, inside the record. -/
theorem key_in_bounds {ras off len kl vl : Nat} (hhi : len + off + 8 ≤ ras)
    (hcons : 8 + (kl + 7) / 8 * 8 + vl = len) : off + 16 + (kl + 7) / 8 * 8 ≤ ras := by
  omega

/-- The last value word a lookup copies ends within the padded record. -/
theorem value_in_bounds {ras off len kl vl pos : Nat} (hras : ras % 8 = 0)
    (hoff : off % 8 = 0) (hhi : len + off + 8 ≤ ras)
    (hcons : 8 + (kl + 7) / 8 * 8 + vl = len) (hpos : pos < vl) (hpos8 : pos % 8 = 0) :
    off + 16 + (kl + 7) / 8 * 8 + pos + 8 ≤ ras := by
  omega

/-- A lookup writes whole words up to the padded capacity of the output. -/
theorem output_in_bounds {cap vl pos : Nat} (hfit : vl ≤ cap) (hpos : pos < vl)
    (hpos8 : pos % 8 = 0) : pos + 8 ≤ (cap + 7) / 8 * 8 := by
  omega

/-- The non-wrapping group scan of `probe()` indexes `base + i` for `i < 8`
without the bucket mask, and only when `base ≤ mask + 1 - 8`. With a bucket
count `b` that is a multiple of 8 (assumption A5, `Config::resolve`),
`b - 8` does not wrap, and every slot it touches is in range. -/
theorem group_scan_in_bounds {b base i : Nat} (hb : b % 8 = 0) (hpos : 8 ≤ b)
    (hbase : base ≤ b - 8) (hi : i < 8) : base + i < b := by
  omega

end Bounds
end Sgc
