//! Index publication and index-entry key matching.

use core::sync::atomic::Ordering::{Acquire, Relaxed, Release};

use super::ArenaView;
use super::index::{IndexEntry, RefCodec, mix_hash};
use super::records::{RecordHeader, RecordLengths, key_matches};
use crate::config::HotParams;
use crate::util::align8;

/// Outcome of publishing an index entry.
#[derive(Clone, Copy, PartialEq, Eq, Debug)]
pub(crate) enum PutStatus {
    /// Succeeded; return after unpinning.
    Ok,
    /// Accepted at/above the occupancy target; unpin, attempt maintenance.
    Full,
    /// No bucket for this key; unpin, rotate, then retry.
    FullRejected,
}

/// Indexes the already-published record at `record_offset` for `key` in
/// the incarnation `epoch` of `arena`.
///
/// The caller stays pinned to `epoch` through this call, and handles
/// unpinning and any rotation after the table and occupancy are updated.
/// Occupancy is a rotation target: it never cancels an existing
/// reservation. `target_reached(new_entry, home_occupied)` reports whether
/// the occupancy target has been reached as far as the caller knows it;
/// `new_entry` says whether this call claimed a bucket rather than found the
/// key already present, `home_occupied` whether the first slot probed held
/// an entry of this incarnation when read. A promotion never replaces an
/// existing entry for its key (someone already inserted or promoted it).
#[inline(always)]
#[allow(clippy::too_many_arguments)]
pub(crate) fn table_put(
    arena: ArenaView<'_>,
    hp: &HotParams,
    hash: u64,
    key: &[u8],
    record_offset: u32,
    epoch: u64,
    promotion: bool,
    mut target_reached: impl FnMut(bool, bool) -> bool,
) -> PutStatus {
    let codec = RefCodec::new(hp);
    let mask = hp.bucket_mask;
    let stamp = epoch as u32;
    let mixed_hash = mix_hash(hash);
    let start = mixed_hash as u32 & mask;
    let tag = codec.hash_tag(mixed_hash);
    production_assert!(record_offset < hp.record_area_size);
    let entry_word = IndexEntry::new(stamp, codec.encode(record_offset, tag)).0;

    let mut home_occupied = false;
    let mut probe: u32 = 0;
    let new_entry = 'put: {
        while probe <= mask {
            let slot = arena.slot((start + probe) & mask);
            let current = slot.load(Acquire);
            let occupied = IndexEntry(current).epoch() == stamp;
            if probe == 0 {
                home_occupied = occupied;
            }
            if occupied {
                if codec.tag(IndexEntry(current).r#ref()) == tag
                    && entry_has_key(
                        arena,
                        codec,
                        hp.record_area_size,
                        stamp,
                        IndexEntry(current),
                        key,
                    )
                {
                    if !promotion {
                        // Unconditionally update the slot.
                        slot.store(entry_word, Release); // I2
                    }
                    break 'put false;
                }
                probe += 1;
                continue;
            }

            if slot
                .compare_exchange(current, entry_word, Release, Relaxed)
                .is_ok()
            {
                // I2
                break 'put true;
            }
            // The relaxed value of the failed CAS is discarded on purpose:
            // acquire-load and examine the winner at the same slot before
            // advancing, since it may have inserted this very key (and the
            // acquire licenses entry_has_key's relaxed record loads).
        }
        return PutStatus::FullRejected;
    };
    if target_reached(new_entry, home_occupied) {
        PutStatus::Full
    } else {
        PutStatus::Ok
    }
}

/// Whether `entry` (of incarnation `epoch`) points at a consistent record
/// for `key`. Out of line to keep table_put lean.
#[inline(never)]
fn entry_has_key(
    arena: ArenaView<'_>,
    codec: RefCodec,
    record_area_size: u32,
    epoch: u32,
    entry: IndexEntry,
    key: &[u8],
) -> bool {
    let record_offset = codec.offset(entry.r#ref());
    production_assert!(record_offset <= record_area_size && record_area_size - record_offset >= 8);

    // table_put acquired the release-published slot. Its pin prevents
    // reuse, so header, lengths and key loads can all be relaxed.
    let rh = RecordHeader(arena.word(record_offset).load(Relaxed));
    // The remaining payload capacity is word-aligned, so bounding the raw
    // length also bounds the padded record. (32-bit arithmetic: the record
    // area size and offsets are u32.)
    if rh.epoch() != epoch
        || rh.len() < 8
        || rh.len() > record_area_size.wrapping_sub(record_offset).wrapping_sub(8)
    {
        return false;
    }
    let lengths = RecordLengths(arena.word(record_offset + 8).load(Relaxed));
    if lengths.key_len() as usize != key.len() {
        return false;
    }

    // Round/add lengths read from shared storage in 64-bit arithmetic.
    let padded_key_size = align8(lengths.key_len() as u64);
    if 8 + padded_key_size + lengths.value_len() as u64 != rh.len() as u64 {
        return false;
    }

    key_matches(arena, record_offset + 16, key)
}
