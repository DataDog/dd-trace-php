//! Unpinned lookup in one arena.

use core::sync::atomic::Ordering::{Acquire, Relaxed};
use core::sync::atomic::{AtomicU64, fence};

use super::ArenaView;
use super::index::{EPOCH_BITS, IndexEntry, RefCodec};
use super::records::{RecordHeader, RecordLengths, key_matches};
use crate::arena::index::mix_hash;
use crate::config::HotParams;
use crate::util::{age_of, align8};

/// Why a probe found no usable value.
#[derive(Clone, Copy, PartialEq, Eq, Debug)]
pub enum ProbeError {
    /// The key is not in this arena incarnation.
    Miss,
    /// A read crossed into a recycled incarnation or saw an inconsistent
    /// record; the whole lookup must restart.
    Retry,
    /// The value does not fit the output capacity.
    InsufficientCapacity,
}

/// Slots scanned before testing whether the probe chain has ended.
const GROUP_SIZE: u32 = 8;

/// Looks `key` up in the incarnation `epoch` of `arena`, copying a match
/// directly into `output`, then validates that none of the reads came from
/// a recycled incarnation.
///
/// `output` is word-aligned and padded: `output_capacity` is its logical
/// byte capacity, and up to `align8(output_capacity)` bytes may be written.
/// It must not overlap `key`. A failed validation can leave output modified;
/// only success is usable.
///
/// Always inlined into its two call sites in lookup. Out of line, each call
/// would pass arguments on the stack, save callee-saved registers and return
/// the result through memory, and a miss in the current arena would pay that
/// twice and redo the hash mix and the key tail setup. Inlined, the second
/// probe reuses them.
///
/// # Safety
/// `output` must be valid for writes of `align8(output_capacity)` bytes.
#[inline(always)]
#[allow(clippy::too_many_arguments)]
pub(crate) unsafe fn probe(
    arena: ArenaView<'_>,
    hp: &HotParams,
    hash: u64,
    key: &[u8],
    epoch: u64,
    output: *mut u64,
    output_capacity: usize,
    global_epoch: &AtomicU64,
) -> Result<RecordLengths, ProbeError> {
    let codec = RefCodec::new(hp);
    let mask = hp.bucket_mask;
    let ras = hp.record_area_size;
    let stamp = epoch as u32;
    let mixed_hash = mix_hash(hash);
    let start = mixed_hash as u32 & mask;
    let tag = codec.hash_tag(mixed_hash);
    let candidate_bits = codec.candidate_bits();

    // Probe home on its own because most hits are there, then scan groups
    // of eight. Each entry contributes to a branchless chain-end check:
    //   difference = entry XOR {wanted epoch, wanted tag, zero offset}
    //   (difference AND candidate_bits) == 0 -> validate this candidate
    //   differences OR= difference
    // A candidate-free group needs one chain-end test:
    //   (differences AND epoch_bits) != 0 -> an empty/stale slot -> miss
    // The terminating group may load seven entries past the first empty or
    // stale slot. These contiguous loads cost less than loading and checking
    // slots one by one, because of the branch mispredictions they would
    // cause.
    let wanted = IndexEntry::new(stamp, tag << codec.offset_bits()).0;
    let mut distance: u32;
    let mut r#ref: u32;
    'found: {
        let home = arena.slot(start).load(Relaxed); // I1
        let home_difference = home ^ wanted;
        if home_difference & candidate_bits == 0 {
            distance = 0;
            r#ref = IndexEntry(home).r#ref();
            break 'found;
        }
        if home_difference & EPOCH_BITS != 0 {
            return Err(ProbeError::Miss);
        }
        let mut first: u32 = 1;
        while first <= mask {
            let base = (start + first) & mask;
            let mut differences: u64 = 0;
            let mut candidate_ref: u32 = 0;
            let i = if base <= mask + 1 - GROUP_SIZE {
                find_candidate::<false>(
                    arena,
                    mask,
                    base,
                    wanted,
                    candidate_bits,
                    &mut candidate_ref,
                    &mut differences,
                )
            } else {
                find_candidate::<true>(
                    arena,
                    mask,
                    base,
                    wanted,
                    candidate_bits,
                    &mut candidate_ref,
                    &mut differences,
                )
            };
            if i < GROUP_SIZE {
                // The group may contain an earlier chain terminator, but a
                // racing insert can publish this candidate while we scan.
                // Full record validation below decides whether it is a hit.
                distance = first + i;
                r#ref = candidate_ref;
                break 'found;
            }
            if differences & EPOCH_BITS != 0 {
                return Err(ProbeError::Miss); // empty/stale slot
            }
            first += GROUP_SIZE;
        }
        return Err(ProbeError::Miss);
    }

    // Empty and stale slots both end the current probe chain through an
    // epoch mismatch. Initialisation uses the same bounded-stamp lifetime
    // assumption as entries retained across arena reuse. The candidate
    // selected above is validated first; if its full record validation
    // fails, the scan continues slot by slot from the next distance.
    loop {
        // check_record:
        //
        // Publication ordering for a candidate selected by I1:
        //
        //   writer: record stores --sb--> I2 (release)
        //   reader: I1 (relaxed) --sb--> I1F (acquire) --sb--> record loads
        //   across: I2 --rf--> I1, therefore I2 --sw--> I1F
        //
        // The sb/sw chain makes the record stores happen-before D4 and the
        // following record loads. If the epoch or tag does not match, lookup
        // never dereferences this entry's ref: it either returns a miss or
        // examines the next slot. Only a matching entry needs I1F to acquire
        // its record publication.
        'next: {
            fence(Acquire); // I1F
            let record_offset = codec.offset(r#ref);
            production_assert!(record_offset <= ras && ras - record_offset >= 8);

            // I1 already acquired publication of a current record. During
            // reuse, D4 only validates the stamp; D6/V2 reject payload reads
            // that cross into the replacement incarnation.
            let rh = RecordHeader(arena.word(record_offset).load(Relaxed)); // D4
            if rh.epoch() != stamp {
                return Err(ProbeError::Miss);
            }
            // The bound is RAS - offset in 32-bit arithmetic, widened to 64
            // bits before subtracting the 8-byte header.
            if rh.len() < 8
                || rh.len() as u64 > (ras.wrapping_sub(record_offset) as u64).wrapping_sub(8)
            {
                return Err(ProbeError::Retry);
            }
            let payload_offset = record_offset + 8;
            let lengths = RecordLengths(arena.word(payload_offset).load(Relaxed));
            let padded_key_size = align8(lengths.key_len() as u64);
            if 8 + padded_key_size + lengths.value_len() as u64 != rh.len() as u64 {
                return Err(ProbeError::Retry);
            }
            if lengths.key_len() as usize != key.len() {
                break 'next;
            }

            // The record is consistent so far.
            if !key_matches(arena, payload_offset + 8, key) {
                break 'next;
            }

            let value_len = lengths.value_len();
            let fits = value_len as usize <= output_capacity;
            if fits {
                let value_offset = payload_offset + 8 + padded_key_size as u32;
                let mut pos: u32 = 0;
                while pos < value_len {
                    let word = arena.word(value_offset + pos).load(Relaxed); // D5
                    // SAFETY: pos < value_len <= output_capacity, and output
                    // holds align8(output_capacity) bytes (caller).
                    unsafe { output.add((pos / 8) as usize).write(word) };
                    pos += 8;
                }
            }

            fence(Acquire); // D6
            if age_of(global_epoch.load(Relaxed), stamp) > 1 {
                return Err(ProbeError::Retry); // V2
            }
            if !fits {
                return Err(ProbeError::InsufficientCapacity);
            }
            return Ok(lengths);
        }

        // Sequential continuation after a rejected candidate.
        loop {
            distance += 1;
            if distance > mask {
                return Err(ProbeError::Miss);
            }
            let entry = IndexEntry(arena.slot((start + distance) & mask).load(Relaxed)); // I1
            if entry.epoch() != stamp {
                return Err(ProbeError::Miss);
            }
            // Like a key mismatch, this skips only this slot. Any later hit
            // still passes the arena-reuse validation at V2.
            if codec.tag(entry.r#ref()) == tag {
                r#ref = entry.r#ref();
                break;
            }
        }
    }
}

/// Scans one group of eight slots starting at `first_slot` for an entry
/// with the wanted epoch and hash tag. If one is found, returns its
/// position and copies its ref for full validation. Otherwise returns
/// [`GROUP_SIZE`] and leaves in `differences` the combined XOR of every
/// entry against `wanted`. If every slot belongs to this arena incarnation,
/// the key may still appear in a later group and probing must continue. An
/// empty or stale slot instead ends the linear-probe chain, so the caller
/// detects its epoch difference and returns a miss.
///
/// * `WRAPS`: use modulo indexing for the final group, which crosses the end
///   of the table;
/// * `wanted`: the lookup epoch and hash tag packed like an index entry,
///   with the offset bits cleared.
///
/// The bucket count is a multiple of eight, so groups starting one slot
/// after home end with distances `B - 7` through `B`. The last distance
/// wraps back to home. Keeping that full group avoids a separate seven-slot
/// tail path; the duplicate load comes after every other slot. If home
/// changed into a candidate concurrently, normal record validation handles
/// it.
#[inline(always)]
fn find_candidate<const WRAPS: bool>(
    arena: ArenaView<'_>,
    mask: u32,
    first_slot: u32,
    wanted: u64,
    candidate_bits: u64,
    candidate_ref: &mut u32,
    differences: &mut u64,
) -> u32 {
    let mut i: u32 = 0;
    while i < GROUP_SIZE {
        let slot = if WRAPS {
            (first_slot + i) & mask
        } else {
            first_slot + i
        };
        let entry = arena.slot(slot).load(Relaxed); // I1
        let difference = entry ^ wanted;
        if difference & candidate_bits == 0 {
            *candidate_ref = IndexEntry(entry).r#ref();
            return i;
        }
        *differences |= difference;
        i += 1;
    }
    GROUP_SIZE
}
