//! Lookup.

use core::sync::atomic::Ordering::Relaxed;

use super::store::PaddedValue;
use super::{ARENA_COUNT, Cache};
use crate::arena::{ProbeError, probe};
use crate::config::Params;
use crate::error::Error;
use crate::occupancy::OccupancyMode;
use crate::participant::ParticipantSlot;

impl<P: Params> Cache<'_, P> {
    /// Looks `key` up, copying a hit's value into `output` and returning its
    /// length; `Ok(None)` is a miss. A hit in the previous generation is
    /// promoted (inserted into the current one); promotion errors are
    /// returned although `output` already holds the value.
    ///
    /// `output` holds `output_words` words, of which the first
    /// `output_capacity` bytes are the logical capacity; whole words are
    /// copied. Retries and failed promotions can leave `output` modified
    /// without returning a hit. Lookups do not pin.
    ///
    /// # Safety
    /// `output` must be valid for writes of `output_words` words, and
    /// `output_capacity <= 8 * output_words`.
    #[inline(always)]
    #[allow(clippy::too_many_arguments)]
    pub(crate) unsafe fn lookup(
        self,
        slot: &ParticipantSlot,
        slot_index: u32,
        hash: u64,
        key: &[u8],
        output: *mut u64,
        output_words: usize,
        output_capacity: usize,
        estimator: &mut <P::Occupancy as OccupancyMode>::Estimator,
    ) -> Result<Option<usize>, Error> {
        let hp = self.params.hot();
        production_assert!(output_capacity <= output_words * 8);
        if key.len() > hp.max_key_size as usize {
            return Err(Error::KeyTooLarge);
        }
        // The key must survive direct copying, retries and promotion. Avoid
        // end-address addition so even the overlap check cannot overflow.
        let output_storage = output_words * 8;
        if !key.is_empty() && output_storage != 0 {
            let k = key.as_ptr() as usize;
            let o = output as usize;
            let overlaps = if k <= o {
                o - k < key.len()
            } else {
                k - o < output_storage
            };
            if overlaps {
                return Err(Error::InvalidArgument);
            }
        }

        let global_epoch = self.global_epoch();
        loop {
            let e = global_epoch.load(Relaxed); // V1
            // e is at least 2, so the previous arena is cur's predecessor
            // modulo 3; this saves a second division by 3.
            let cur = e % ARENA_COUNT;
            let prev = if cur == 0 { ARENA_COUNT - 1 } else { cur - 1 };
            // SAFETY (both probes): output holds align8(output_capacity)
            // bytes (caller).
            let mut result = unsafe {
                probe(
                    self.arena(&hp, cur),
                    &hp,
                    hash,
                    key,
                    e,
                    output,
                    output_capacity,
                    global_epoch,
                )
            };
            let not_in_cur_arena = result == Err(ProbeError::Miss);
            if not_in_cur_arena {
                result = unsafe {
                    probe(
                        self.arena(&hp, prev),
                        &hp,
                        hash,
                        key,
                        e - 1,
                        output,
                        output_capacity,
                        global_epoch,
                    )
                };
            }
            let lengths = match result {
                Ok(lengths) => lengths,
                Err(ProbeError::Retry) => continue,
                Err(ProbeError::InsufficientCapacity) => return Err(Error::InsufficientCapacity),
                Err(ProbeError::Miss) => return Ok(None),
            };
            let value_len = lengths.value_len();
            if value_len > hp.max_value_size {
                continue;
            }
            if not_in_cur_arena {
                // Promotion may rotate/reuse the source arena. Use only the
                // caller's key and the validated copy, never shared record
                // bytes. Retain its padded word representation so the final
                // logical word is not narrowed back to a byte-sized tail.
                let value = PaddedValue {
                    words: output,
                    logical_size: value_len as usize,
                };
                self.store(slot, slot_index, hash, key, value, true, estimator)?;
            }
            return Ok(Some(value_len as usize));
        }
    }
}
