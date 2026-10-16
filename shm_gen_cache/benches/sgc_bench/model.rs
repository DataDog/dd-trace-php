//! Reference model of the documented semantics: lookups probe the current
//! generation, then the previous one, and promote a previous-generation hit
//! into the current one; an insert or promotion of a key new to the current
//! generation consumes occupancy; reaching the rotation target rotates,
//! which discards the previous generation. Operation-for-operation it is
//! exact for a single thread; with several it replays a round-robin
//! interleaving.

use crate::sgc_bench::config::MAX_OCCUPANCY;

/// What one stream element does.
#[derive(Clone, Copy, PartialEq, Eq, Debug)]
pub enum OpKind {
    /// Lookup; a miss inserts the key's value.
    LookupOrInsert,
    Lookup,
    Insert,
}

#[derive(Clone, Copy, Default, Debug)]
pub struct ModelCounts {
    pub ops: u64,
    pub hits: u64,
    pub promotions: u64,
    pub inserts: u64,
    pub replacements: u64,
    pub rotations: u64,
}

impl ModelCounts {
    /// The counts accumulated since `before`.
    pub fn since(&self, before: &ModelCounts) -> ModelCounts {
        ModelCounts {
            ops: self.ops - before.ops,
            hits: self.hits - before.hits,
            promotions: self.promotions - before.promotions,
            inserts: self.inserts - before.inserts,
            replacements: self.replacements - before.replacements,
            rotations: self.rotations - before.rotations,
        }
    }
}

pub struct GenerationModel {
    pub counts: ModelCounts,
    /// The newest generation holding each key; 0 = none.
    newest: Vec<u32>,
    epoch: u32,
    occupancy: u32,
}

impl GenerationModel {
    pub fn new(universe: usize) -> Self {
        GenerationModel {
            counts: ModelCounts::default(),
            newest: vec![0; universe],
            epoch: 2,
            occupancy: 0,
        }
    }

    pub fn apply(&mut self, kind: OpKind, key: u32) {
        self.counts.ops += 1;
        if kind == OpKind::Insert {
            self.counts.inserts += 1;
            self.put(key);
            return;
        }
        let newest = self.newest[key as usize];
        if newest == self.epoch {
            self.counts.hits += 1;
        } else if newest + 1 == self.epoch {
            self.counts.hits += 1;
            self.counts.promotions += 1;
            self.put(key);
        } else if kind == OpKind::LookupOrInsert {
            self.counts.inserts += 1;
            self.put(key);
        }
    }

    fn put(&mut self, key: u32) {
        let newest = &mut self.newest[key as usize];
        if *newest == self.epoch {
            self.counts.replacements += 1; // a replacement consumes no bucket
            return;
        }
        *newest = self.epoch;
        self.occupancy += 1;
        if self.occupancy >= MAX_OCCUPANCY {
            self.counts.rotations += 1;
            self.epoch += 1;
            self.occupancy = 0;
        }
    }
}
