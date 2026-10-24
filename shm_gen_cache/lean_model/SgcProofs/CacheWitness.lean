import SgcProofs.Ranked
import SgcProofs.Promotion
import SgcProofs.Access

/-!
# A witness for the cache, the arena, the index, the reservations and the values

`CacheProtocol`, `ArenaProtocol`, `IndexProtocol`, `ReserveProtocol`,
`ValueProtocol`, `ReadinessProtocol`, `PromotionProtocol` and `Hit` are long
lists of hypotheses. This file shows they hold at once by building one
consistent execution of 126 events: two writers three epochs apart on one
arena, the three rotations between them (one of which certifies its
just-sealed epoch), a lookup hit, a lookup that finds the previous
generation not yet certified, certifies it with a readiness recheck,
reprobes it and promotes the record into the next arena, and a second
attempt of the later epoch that cuts its record from the chunk its first
attempt claimed and replaces an index entry. Writer `a` stores key 1 with
an 8-byte value (32-byte records: header, lengths, key word, value word);
writer `b` stores key 2 with an empty value (16-byte records: header and
lengths).

* Thread 0 initialises: the owner word, the epoch (2), three control words
  (`c2` serves epochs 2 and 5 and starts as `(2, unsealed)`, the others as
  `(0, sealed)`; `c0` serves epoch 3), the readiness words of `c0`'s and
  `c2`'s arenas (`rdy0`, `rdy2`, both 0; `c1`'s, `rdy1`, is never
  initialised), the slot's state, start-time and pin words, the record
  words `rcd` and `rc2` (bytes 0 and 8 of `c2`'s record area; `rcA` and
  `rcB`, bytes 16 and 24, and `c0`'s `rc3` to `rc3d` are never
  initialised), the index slots `idx` (of `c2`'s arena) and `idx3`
  (of `c0`'s), and the slot's chunk words `ce`, `cc` and `cn`.
* Thread 1, writer `a`, claims the slot (`aC`, `aT`, the chunk reset `aHe`,
  `aHc`, `aHn`, then `aG`), loads epoch 2 (`aQ`), pins it (`aW`), fences
  (`aF`), loads `c2` unsealed (`aL`), reserves its 32-byte record exactly
  (`aB`), stores the lengths, key and value words and then the header
  (`aRl`, `aRk`, `aRv`, `aR`), publishes it with a CAS on the index slot
  (`aI`) and unpins (`aU`).
* Threads 2, 3 and 4 are the rotations `t1`, `t2`, `t3`. Each acquires the
  owner word (`A`, O2), rechecks the epoch (`q`, R0), seals `ctlLoc e`
  (`Z`, R1), fences under ownership (`F`, R1F), scans the pin (`S`, R3),
  resets `ctlLoc (e + 1)` (`R`, R4), publishes `e + 1` (`P`, R5) and
  releases (`O`, O4). `t1` runs while writer `a` is still pinned: its scan
  passes on the live pin of the current epoch 2, which also keeps it from
  certifying 2. `t2`'s scan sees no pin of 3, so it certifies 3 with a
  `fetch_max` on `c0`'s readiness word (`t2C`, R3C). `t3` rechecks 4 and
  resets `c2` for epoch 5; the certificate for 4 that its scan would allow
  is not modelled.
* Thread 1 then looks its key up after `t1`: it loads epoch 3 (`aV1`) and
  misses in `c0`'s arena: the probe's home index load reads the zero fill
  of `idx3`, whose stamp is not 3 (`aI1c`, the probe's only load). Before
  probing the previous arena, it acquire-loads `c2`'s readiness word, which
  still reads 0, so epoch 2 is not certified (`aC0`, C0). Its first probe of
  the previous arena, which hits writer `a`'s record unpromotably, is not
  modelled. Its readiness recheck loads epoch 3 with acquire (`aC1`, C1),
  fences (`aC2`, C2), finds the pin unpinned (`aC3`, C3) and certifies 2
  (`aC4`, C4, a `fetch_max` on `c2`'s readiness word, made unpinned). The
  reprobe of the previous arena (`hitA`) loads `idx` (`aJ`, writer `a`'s
  entry), fences (`aJF`), loads the header, the lengths, the key word and
  the value word (`aY`, `aYl`, `aYk`, `aYv`), fences (`aD`) and reloads
  epoch 3 (`aV2`), which passes. It promotes the hit: it loads epoch 3
  (`aQ3`), pins it (`aW3`), fences (`aF3`), loads `c0` as `t1` reset it
  (`aL3`), reserves 32 bytes there (`aB3`), stores the record in `rc3` to
  `rc3d` (`aR3l`, `aR3k`, `aR3v`, `aR3`), publishes it with a CAS on `idx3`
  (`aI3`), unpins (`aU3`) and frees the slot (`aX`). `t2` seals `c0` over that reservation, and its
  scan and `t3`'s read `aU3`.
* Thread 5, writer `b`, claims the freed slot (resetting the chunk words),
  loads epoch 5, pins it, loads `c2` as `t3` reset it, claims a 32-byte
  chunk for its 16-byte record (`bB`), stores the chunk (`bKe`, `bKc`,
  `bKn`), stores the record (`bRl`, `bR`) and publishes it with a CAS over
  writer `a`'s stale index entry; it then unpins.
* Thread 6, a lookup (`hitK`), loads the epoch (`kV1`), loads the index
  slot (`kI`), fences (`kF`), loads the header and the lengths (`kY`,
  `kYl`), fences (`kD`) and reloads the epoch (`kV`).
* Thread 5 then makes a second attempt in epoch 5 for the same key: it pins
  again (`bW2`), finds its chunk (`bLe`, `bLc`, `bLn`) and cuts its record
  from it (`bMc`) without a B1, stores the record at bytes 16 and 24
  (`bR2l`, `bR2`), loads the index slot (`bJ`), checks the key of the entry
  it found with a header and a lengths load of writer `b`'s record (`bK`,
  `bKl`), and replaces the entry with a store (`bS`), then unpins.

Event names are mnemonic: in a writer's events `Q` is W1, `W` the pin W2,
`F` the fence W3, `L` the precheck B0, `B` the B1, `R...` record stores W4
and W5, `I`/`S` the publication I2, `U` the unpin W7, `C`/`G`/`X` P1/P2/P3;
`He`, `Hc`, `Hn` the chunk reset H0, `Ke`, `Kc`, `Kn` the chunk claim H2,
`Le`, `Lc`, `Ln`, `Mc` the chunk cut H1; `bJ` is I0 and `bK`, `bKl` the key
check's D4 and D5. In a lookup, `V1`/`V`/`V2` are V1 and V2, `J`/`I`/`I1c`
the index load I1, `JF`/`F` I1F, `Y...` the record loads D4 and D5, `D` the
fence D6, and `C0` to `C4` the readiness load and recheck. In a rotation,
`F` is R1F and `t2C` is R3C.

Every relation agrees with one total order (`rank`), so the execution is
consistent by `Ranked`. The main theorems of `Cache.lean`, `Reserve.lean`,
`Lookup.lean`, `Provenance.lean`, `Promotion.lean` and `Access.lean` are
instantiated on it at the end; `RecencyWitness.lean` adds insert operations
and thread 1's lookup as a `LookupRun`.
-/

namespace Sgc
namespace CacheWitness

open Execution

inductive E
  | iOwn | iEp | iC0 | iC1 | iC2 | iSt | iT | iPin | iRec | iRec2 | iIdx | iCE | iCC | iCN
  | iIdx3 | iRdy0 | iRdy2
  | aC | aT | aHe | aHc | aHn | aG | aQ | aW | aF | aL | aB | aRl | aRk | aRv | aR | aI
  | t1A | t1q | t1Z | t1F | t1S | t1R | t1P | t1O
  | aU | aV1 | aI1c | aC0 | aC1 | aC2 | aC3 | aC4 | aJ | aJF | aY | aYl | aYk | aYv | aD | aV2
  | aQ3 | aW3 | aF3 | aL3 | aB3 | aR3l | aR3k | aR3v | aR3 | aI3 | aU3 | aX
  | t2A | t2q | t2Z | t2F | t2S | t2C | t2R | t2P | t2O
  | t3A | t3q | t3Z | t3F | t3S | t3R | t3P | t3O
  | bC | bT | bHe | bHc | bHn | bG | bQ | bW | bF | bL | bB | bKe | bKc | bKn | bRl | bR | bI
  | bU
  | kV1 | kI | kF | kY | kYl | kD | kV
  | bQ2 | bW2 | bF2 | bL2 | bLe | bLc | bLn | bMc | bR2l | bR2 | bJ | bK | bKl | bS | bU2
  deriving DecidableEq

inductive Word
  | own | ep | c0 | c1 | c2 | st | tdis | pin | rcd | rc2 | idx | ce | cc | cn | rc3 | idx3
  | rcA | rcB | rc3b | rc3c | rc3d | rdy0 | rdy1 | rdy2
  deriving DecidableEq

open E

def rank : E → Nat
  | iOwn => 0 | iEp => 1 | iC0 => 2 | iC1 => 3 | iC2 => 4 | iSt => 5 | iT => 6 | iPin => 7
  | iRec => 8 | iRec2 => 9 | iIdx => 10 | iCE => 11 | iCC => 12 | iCN => 13 | iIdx3 => 14
  | iRdy0 => 15 | iRdy2 => 16 | aC => 17 | aT => 18 | aHe => 19 | aHc => 20 | aHn => 21 | aG => 22
  | aQ => 23 | aW => 24 | aF => 25 | aL => 26 | aB => 27 | aRl => 28 | aRk => 29 | aRv => 30
  | aR => 31 | aI => 32 | t1A => 33 | t1q => 34 | t1Z => 35 | t1F => 36 | t1S => 37 | t1R => 38
  | t1P => 39 | t1O => 40 | aU => 41 | aV1 => 42 | aI1c => 43 | aC0 => 44 | aC1 => 45 | aC2 => 46
  | aC3 => 47 | aC4 => 48 | aJ => 49 | aJF => 50 | aY => 51 | aYl => 52 | aYk => 53 | aYv => 54
  | aD => 55 | aV2 => 56 | aQ3 => 57 | aW3 => 58 | aF3 => 59 | aL3 => 60 | aB3 => 61 | aR3l => 62
  | aR3k => 63 | aR3v => 64 | aR3 => 65 | aI3 => 66 | aU3 => 67 | aX => 68 | t2A => 69 | t2q => 70
  | t2Z => 71 | t2F => 72 | t2S => 73 | t2C => 74 | t2R => 75 | t2P => 76 | t2O => 77 | t3A => 78
  | t3q => 79 | t3Z => 80 | t3F => 81 | t3S => 82 | t3R => 83 | t3P => 84 | t3O => 85 | bC => 86
  | bT => 87 | bHe => 88 | bHc => 89 | bHn => 90 | bG => 91 | bQ => 92 | bW => 93 | bF => 94
  | bL => 95 | bB => 96 | bKe => 97 | bKc => 98 | bKn => 99 | bRl => 100 | bR => 101 | bI => 102
  | bU => 103 | kV1 => 104 | kI => 105 | kF => 106 | kY => 107 | kYl => 108 | kD => 109 | kV => 110
  | bQ2 => 111 | bW2 => 112 | bF2 => 113 | bL2 => 114 | bLe => 115 | bLc => 116 | bLn => 117
  | bMc => 118 | bR2l => 119 | bR2 => 120 | bJ => 121 | bK => 122 | bKl => 123 | bS => 124
  | bU2 => 125

/-- The inverse of `rank`, to show it injective without a quadratic case
split. -/
def ofRank : Nat → E
  | 0 => iOwn | 1 => iEp | 2 => iC0 | 3 => iC1 | 4 => iC2 | 5 => iSt | 6 => iT | 7 => iPin
  | 8 => iRec | 9 => iRec2 | 10 => iIdx | 11 => iCE | 12 => iCC | 13 => iCN | 14 => iIdx3
  | 15 => iRdy0 | 16 => iRdy2 | 17 => aC | 18 => aT | 19 => aHe | 20 => aHc | 21 => aHn | 22 => aG
  | 23 => aQ | 24 => aW | 25 => aF | 26 => aL | 27 => aB | 28 => aRl | 29 => aRk | 30 => aRv
  | 31 => aR | 32 => aI | 33 => t1A | 34 => t1q | 35 => t1Z | 36 => t1F | 37 => t1S | 38 => t1R
  | 39 => t1P | 40 => t1O | 41 => aU | 42 => aV1 | 43 => aI1c | 44 => aC0 | 45 => aC1 | 46 => aC2
  | 47 => aC3 | 48 => aC4 | 49 => aJ | 50 => aJF | 51 => aY | 52 => aYl | 53 => aYk | 54 => aYv
  | 55 => aD | 56 => aV2 | 57 => aQ3 | 58 => aW3 | 59 => aF3 | 60 => aL3 | 61 => aB3 | 62 => aR3l
  | 63 => aR3k | 64 => aR3v | 65 => aR3 | 66 => aI3 | 67 => aU3 | 68 => aX | 69 => t2A | 70 => t2q
  | 71 => t2Z | 72 => t2F | 73 => t2S | 74 => t2C | 75 => t2R | 76 => t2P | 77 => t2O | 78 => t3A
  | 79 => t3q | 80 => t3Z | 81 => t3F | 82 => t3S | 83 => t3R | 84 => t3P | 85 => t3O | 86 => bC
  | 87 => bT | 88 => bHe | 89 => bHc | 90 => bHn | 91 => bG | 92 => bQ | 93 => bW | 94 => bF
  | 95 => bL | 96 => bB | 97 => bKe | 98 => bKc | 99 => bKn | 100 => bRl | 101 => bR | 102 => bI
  | 103 => bU | 104 => kV1 | 105 => kI | 106 => kF | 107 => kY | 108 => kYl | 109 => kD | 110 => kV
  | 111 => bQ2 | 112 => bW2 | 113 => bF2 | 114 => bL2 | 115 => bLe | 116 => bLc | 117 => bLn
  | 118 => bMc | 119 => bR2l | 120 => bR2 | 121 => bJ | 122 => bK | 123 => bKl | 124 => bS
  | _ => bU2

/-- Thread of an event; 0 is initialisation. -/
def thr : E → Nat
  | iOwn | iEp | iC0 | iC1 | iC2 | iSt | iT | iPin | iRec | iRec2 | iIdx | iCE | iCC | iCN => 0
  | iIdx3 | iRdy0 | iRdy2 => 0
  | aC | aT | aHe | aHc | aHn | aG | aQ | aW | aF | aL | aB | aRl | aRk | aRv | aR | aI => 1
  | aU | aX | aV1 | aI1c | aJ | aJF | aY | aYl | aYk | aYv | aD | aV2 | aQ3 | aW3 | aF3 | aL3 => 1
  | aB3 | aR3l | aR3k | aR3v | aR3 | aI3 | aU3 | aC0 | aC1 | aC2 | aC3 | aC4 => 1
  | t1F | t1A | t1q | t1Z | t1S | t1R | t1P | t1O => 2
  | t2F | t2A | t2q | t2Z | t2S | t2C | t2R | t2P | t2O => 3
  | t3F | t3A | t3q | t3Z | t3S | t3R | t3P | t3O => 4
  | bC | bT | bHe | bHc | bHn | bG | bQ | bW | bF | bL | bB | bKe | bKc | bKn | bRl | bR => 5
  | bI | bU => 5
  | bQ2 | bW2 | bF2 | bL2 | bLe | bLc | bLn | bMc | bR2l | bR2 | bJ | bK | bKl | bS | bU2 => 5
  | kV1 | kI | kF | kY | kYl | kD | kV => 6

def locOf : E → Option Word
  | iOwn | t1A | t1O | t2A | t2O | t3A | t3O => some Word.own
  | iEp | aQ | t1q | t1P | t2q | t2P | t3q | t3P | bQ | kV | bQ2 => some Word.ep
  | aV1 | aV2 | aQ3 | kV1 | aC1 => some Word.ep
  | iC0 | t1R | t2Z | aL3 | aB3 => some Word.c0
  | iC1 | t2R | t3Z => some Word.c1
  | iC2 | aL | aB | t1Z | t3R | bL | bB | bL2 => some Word.c2
  | iSt | aC | aG | aX | bC | bG => some Word.st
  | iT | aT | bT => some Word.tdis
  | iPin | aW | aU | t1S | t2S | t3S | bW | bU | bW2 | bU2 | aW3 | aU3 | aC3 => some Word.pin
  | iRec | aR | bR | kY | aY | bK => some Word.rcd
  | iRec2 | aRl | aYl | bRl | kYl | bKl => some Word.rc2
  | aRk | aYk | bR2 => some Word.rcA
  | aRv | aYv | bR2l => some Word.rcB
  | iIdx | aI | bI | kI | bJ | bS | aJ => some Word.idx
  | aR3 => some Word.rc3
  | aR3l => some Word.rc3b
  | aR3k => some Word.rc3c
  | aR3v => some Word.rc3d
  | iIdx3 | aI3 | aI1c => some Word.idx3
  | iCE | aHe | bHe | bKe | bLe => some Word.ce
  | iCC | aHc | bHc | bKc | bLc | bMc => some Word.cc
  | iCN | aHn | bHn | bKn | bLn => some Word.cn
  | iRdy0 | t2C => some Word.rdy0
  | iRdy2 | aC0 | aC4 => some Word.rdy2
  | aF | t1F | t2F | t3F | bF | kF | kD | bF2 | aJF | aD | aF3 | aC2 => none

def isWB : E → Bool
  | iOwn | iEp | iC0 | iC1 | iC2 | iSt | iT | iPin | iRec | iRec2 | iIdx | iCE | iCC | iCN => true
  | aC | aT | aHe | aHc | aHn | aG | aW | aB | aRl | aRk | aRv | aR | aI | aU | aX => true
  | iIdx3 | aW3 | aB3 | aR3l | aR3k | aR3v | aR3 | aI3 | aU3 => true
  | iRdy0 | iRdy2 | aC4 | t2C => true
  | t1A | t1Z | t1R | t1P | t1O => true
  | t2A | t2Z | t2R | t2P | t2O => true
  | t3A | t3Z | t3R | t3P | t3O => true
  | bC | bT | bHe | bHc | bHn | bG | bW | bB | bKe | bKc | bKn | bRl | bR | bI | bU => true
  | bW2 | bMc | bR2l | bR2 | bS | bU2 => true
  | _ => false

def isRB : E → Bool
  | aC | aG | aQ | aL | aB | aI | aX => true
  | aV1 | aJ | aY | aYl | aYk | aYv | aV2 | aQ3 | aL3 | aB3 | aI3 | aI1c => true
  | aC0 | aC1 | aC3 | aC4 => true
  | t1A | t1q | t1Z | t1S => true
  | t2A | t2q | t2Z | t2S | t2C => true
  | t3A | t3q | t3Z | t3S => true
  | bC | bG | bQ | bL | bB | bI => true
  | kV1 | kI | kY | kYl | kV => true
  | bQ2 | bL2 | bLe | bLc | bLn | bJ | bK | bKl => true
  | _ => false

/-- The write each read takes its value from. -/
def src : E → Option E
  | aC => some iSt | aG => some aC | aQ => some iEp | aL => some iC2
  | aB => some iC2 | aI => some iIdx | aX => some aG
  | t1A => some iOwn | t1q => some iEp | t1Z => some aB | t1S => some aW
  | aI1c => some iIdx3
  | aV1 => some t1P | aJ => some aI | aY => some aR | aV2 => some t1P | aQ3 => some t1P
  | aYl => some aRl | aYk => some aRk | aYv => some aRv
  | aC0 => some iRdy2 | aC1 => some t1P | aC3 => some aU | aC4 => some iRdy2
  | aL3 => some t1R | aB3 => some t1R | aI3 => some iIdx3
  | t2A => some t1O | t2q => some t1P | t2Z => some aB3 | t2S => some aU3 | t2C => some iRdy0
  | t3A => some t2O | t3q => some t2P | t3Z => some t2R | t3S => some aU3
  | bC => some aX | bG => some bC | bQ => some t3P | bL => some t3R
  | bB => some t3R | bI => some aI
  | kV1 => some t3P | kI => some bI | kY => some bR | kYl => some bRl | kV => some t3P
  | bQ2 => some t3P | bL2 => some bB | bLe => some bKe | bLc => some bKc | bLn => some bKn
  | bJ => some bI | bK => some bR | bKl => some bRl
  | _ => none

/-- Values written. A control word is `2 * stamp + sealed`, plus 100 per
reserved byte; start times are ten times the thread number; a pin holds its
epoch, or 0 when unpinned. The chunk words hold 0 unless listed. An index
entry is `stamp + 2^32 * (offset / 8)`, a record header `stamp + 2^32 * len`
and a lengths word `key_len + 2^32 * value_len`: writer `a`'s records have
an 8-byte key and an 8-byte value, writer `b`'s an empty key and value. -/
def wv : E → Nat
  | iEp => 2 | t1P => 3 | t2P => 4 | t3P => 5
  | iC0 => 1 | iC1 => 1 | iC2 => 4
  | aB => 3204 | t1Z => 3205 | t1R => 6 | t2Z => 3207 | t2R => 8 | t3Z => 9 | t3R => 10
  | aB3 => 3206 | bB => 3210
  | aC4 => 2 | t2C => 3
  | bKe => 5 | bKc => 16 | bKn => 32 | bMc => 32
  | aT => 10 | bT => 50
  | aW => 2 | bW => 5 | bW2 => 5 | aW3 => 3
  | aI => 2 | bI => 5 | aI3 => 3 | bS => 8589934597
  | aRl => 34359738376 | aR3l => 34359738376
  | aR => 103079215106 | aR3 => 103079215107 | bR => 34359738373 | bR2 => 34359738373
  | aRk => 7 | aRv => 42 | aR3k => 7 | aR3v => 42
  | _ => 0

def rv (e : E) : Nat :=
  match src e with
  | some w => wv w
  | none => 0

def relB : E → Bool
  | aG | aW | aI | aU | aX | aW3 | aI3 | aU3 | aC4 | t2C => true
  | t1A | t1P | t1O | t2A | t2P | t2O | t3A | t3P | t3O => true
  | bG | bW | bI | bU | bW2 | bS | bU2 => true
  | _ => false

def acqB : E → Bool
  | aC | aQ | bC | bQ | bQ2 | bJ | aQ3 | aC0 | aC1 | aC3 => true
  | t1A | t1q | t1S | t2A | t2q | t2S | t3A | t3q | t3S => true
  | _ => false

/-- The `SeqCst` fences: W3 of each writer's pass, R1F of each rotation
(after its seal, under ownership), and the recheck's C2. -/
def scFB : E → Bool
  | aF | t1F | t2F | t3F | bF | bF2 | aF3 | aC2 => true
  | _ => false

/-- The lookups' acquire fences: I1F and D6. -/
def acqFB : E → Bool
  | kF | kD | aJF | aD => true
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
  isRelFence := fun e => scFB e = true
  isAcqFence := fun e => (scFB e || acqFB e) = true
  isScFence := fun e => scFB e = true
  sb := sbP
  rf := rfP
  mo := moP
  asw := fun _ _ => False
  wval := wv
  rval := rv

/-! The relations of `X`, decidable as their definitions are. -/

instance (e : E) : Decidable (X.isW e) := inferInstanceAs (Decidable (isWB e = true))
instance (e : E) : Decidable (X.isR e) := inferInstanceAs (Decidable (isRB e = true))
instance (e : E) : Decidable (X.isRel e) := inferInstanceAs (Decidable (relB e = true))
instance (e : E) : Decidable (X.isAcq e) := inferInstanceAs (Decidable (acqB e = true))
instance (e : E) : Decidable (X.isAcqFence e) :=
  inferInstanceAs (Decidable ((scFB e || acqFB e) = true))
instance (e : E) : Decidable (X.isScFence e) := inferInstanceAs (Decidable (scFB e = true))
instance (a b : E) : Decidable (X.sb a b) := inferInstanceAs (Decidable (sbP a b))
instance (a b : E) : Decidable (X.rf a b) := inferInstanceAs (Decidable (rfP a b))
instance (a b : E) : Decidable (X.mo a b) := inferInstanceAs (Decidable (moP a b))

/-! ## Consistency -/

theorem ofRank_rank : ∀ a : E, ofRank (rank a) = a := by
  intro a; cases a <;> rfl

theorem rank_inj (a b : E) (h : rank a = rank b) : a = b := by
  rw [← ofRank_rank a, ← ofRank_rank b, h]

theorem rf_rank : ∀ w r : E, rfP w r → rank w < rank r := by
  intro w r h
  cases r <;> simp [rfP, src] at h <;> (subst h; decide)

theorem rf_wf_raw : ∀ w r : E, rfP w r →
    isWB w = true ∧ isRB r = true ∧ locOf w = locOf r ∧ locOf w ≠ none := by
  intro w r h
  cases r <;> simp [rfP, src] at h <;> (subst h; decide)

theorem rmw_mo_raw : ∀ w u : E, rfP w u → isWB u = true → moP w u := by
  intro w u h
  cases u <;> simp [rfP, src] at h <;> (subst h; decide)

/-- A read takes the last write before it: nothing later in `mo` than its
source comes before the read. -/
theorem rb_rank : ∀ r w w₀ : E, rfP w₀ r → moP w₀ w → rank r ≤ rank w := by
  intro r w w₀ h
  cases r <;> simp [rfP, src] at h <;> (subst h; cases w <;> decide)

def ranked : Ranked X where
  rank := rank
  sb_lt := fun h => h.1
  asw_lt := fun h => h.elim
  rf_lt := fun h => rf_rank _ _ h
  mo_lt := fun h => h.2.2.2.2
  rb_le := fun ⟨_, w₀, hrf, hmo⟩ => rb_rank _ _ w₀ hrf hmo

theorem consistent : X.Consistent where
  rf_wf := fun h => rf_wf_raw _ _ h
  mo_wf := fun h => ⟨h.1, h.2.1, h.2.2.1, h.2.2.2.1⟩
  mo_irrefl := ranked.mo_irrefl
  mo_trans := fun h₁ h₂ =>
    ⟨h₁.1, h₂.2.1, h₁.2.2.1.trans h₂.2.2.1, h₁.2.2.2.1, Nat.lt_trans h₁.2.2.2.2 h₂.2.2.2.2⟩
  mo_wellFounded := ranked.mo_wellFounded
  rf_unique := fun (h : src _ = some _) h' => Option.some.inj (h.symm.trans h')
  rf_val := fun {w r} (h : src r = some w) => by simp [X, rv, h]
  mo_total := by
    intro a b ha hb hloc hsome
    rcases Nat.lt_trichotomy (rank a) (rank b) with h | h | h
    · exact Or.inr (Or.inl ⟨ha, hb, hloc, hsome, h⟩)
    · exact Or.inl (rank_inj a b h)
    · exact Or.inr (Or.inr ⟨hb, ha, hloc.symm, fun h' => hsome (hloc.trans h'), h⟩)
  scFence_relAcq := fun {f} (h : scFB f = true) =>
    ⟨h, show (scFB f || acqFB f) = true by rw [h]; rfl⟩
  hb_irrefl := ranked.hb_irrefl
  coherence := ranked.coherence
  rmw_mo := fun h hu => rmw_mo_raw _ _ h hu
  atomicity := ranked.atomicity
  sc_fences := ranked.sc_fences

/-- Two events of one thread are the same or sequenced. -/
theorem thread_total {e f : E} (h : thr e = thr f) : e = f ∨ sbP e f ∨ sbP f e := by
  rcases Nat.lt_trichotomy (rank e) (rank f) with h' | h' | h'
  · exact Or.inr (Or.inl ⟨h', Or.inl h⟩)
  · exact Or.inl (rank_inj e f h')
  · exact Or.inr (Or.inr ⟨h', Or.inl h.symm⟩)

/-! ## Assumption A3: no verdict is ever given -/

def liveness : Liveness X where
  thrOf := thr
  pidOf := id
  startOf := fun θ => 10 * θ
  dead := fun _ _ _ => False
  sound := fun h => h.elim

/-! ## The slot protocol: two registrations, the first ended by a P3 -/

abbrev isClaimP (c : E) : Prop := c = aC ∨ c = bC

def regF : E → E
  | aC => aG | bC => bG | _ => iSt

def tF : E → E
  | aC => aT | bC => bT | _ => iT

abbrev regOfP (c g : E) : Prop := isClaimP c ∧ g = regF c
abbrev tOfP (c s : E) : Prop := isClaimP c ∧ s = tF c
abbrev actP (c e : E) : Prop := (c = aC ∧ thr e = 1) ∨ (c = bC ∧ thr e = 5)

theorem st_first : ∀ e : E, isWB e = true → locOf e = some Word.st → e ≠ iSt → sbP iSt e := by
  intro e; cases e <;> decide

theorem st_writes : ∀ e : E, isWB e = true → locOf e = some Word.st →
    e = iSt ∨ (isClaimP e ∨ False) ∨ (∃ c, regOfP c e) ∨ (∃ c, c = aC ∧ e = aX) := by
  intro e hw hl
  cases e <;> first
    | exact Or.inl rfl
    | exact Or.inr (Or.inl (Or.inl (by decide)))
    | exact Or.inr (Or.inr (Or.inl ⟨aC, by decide⟩))
    | exact Or.inr (Or.inr (Or.inl ⟨bC, by decide⟩))
    | exact Or.inr (Or.inr (Or.inr ⟨aC, rfl, rfl⟩))
    | exact absurd hw (by decide)
    | exact absurd hl (by decide)

theorem claim_cases {P : E → Prop} (ha : P aC) (hb : P bC) : ∀ c, isClaimP c ∨ False → P c := by
  rintro c ((rfl | rfl) | h)
  · exact ha
  · exact hb
  · exact h.elim

theorem act_sb_raw : ∀ c e : E, actP c e → e = c ∨ sbP c e := by
  rintro c e (⟨rfl, h⟩ | ⟨rfl, h⟩) <;> revert h <;> cases e <;> decide

theorem act_thr_raw : ∀ c e : E, actP c e → thr e = thr c := by
  rintro c e (⟨rfl, h⟩ | ⟨rfl, h⟩) <;> exact h

theorem act_before_free_raw : ∀ e : E, actP aC e → e = aX ∨ sbP e aX := by
  rintro e (⟨-, h⟩ | ⟨h, -⟩)
  · revert h; cases e <;> decide
  · exact absurd h (by decide)

theorem t_first : ∀ e : E, isWB e = true → locOf e = some Word.tdis → e ≠ iT → sbP iT e := by
  intro e; cases e <;> decide

theorem t_writes_raw : ∀ e : E, isWB e = true → locOf e = some Word.tdis →
    e = iT ∨ ∃ c, tOfP c e := by
  intro e hw hl
  cases e <;> first
    | exact Or.inl rfl
    | exact Or.inr ⟨aC, by decide⟩
    | exact Or.inr ⟨bC, by decide⟩
    | exact absurd hw (by decide)
    | exact absurd hl (by decide)

def slotP : SlotProtocol X liveness where
  st := Word.st
  tdis := Word.tdis
  init := iSt
  tInit := iT
  isFreeClaim := isClaimP
  isSteal := fun _ => False
  stealOf := fun _ _ => False
  regOf := regOfP
  freeOf := fun c x => c = aC ∧ x = aX
  act := actP
  tOf := tOfP
  init_w := rfl
  init_loc := rfl
  init_first := fun hw hl hne => hb_of_sb (st_first _ hw hl hne)
  writes := fun hw hl => st_writes _ hw hl
  claim_w := fun h => claim_cases (P := fun c => X.isW c) rfl rfl _ h
  claim_loc := fun h => claim_cases (P := fun c => X.loc c = some Word.st) rfl rfl _ h
  fc_isAcq := fun {c} h => by rcases h with rfl | rfl <;> rfl
  fc_reads := fun {c} h => by
    rcases h with rfl | rfl
    · exact ⟨iSt, rfl, Or.inl rfl⟩
    · exact ⟨aX, rfl, Or.inr ⟨aC, rfl, rfl⟩⟩
  steal_isRel := fun h => h.elim
  steal_of := fun h => h.elim
  stealOf_claims := fun h => h.elim
  steal_ev := fun h => h.elim
  reg_claim := fun h => Or.inl h.1
  reg_w := fun {c g} h => by obtain ⟨hc, rfl⟩ := h; rcases hc with rfl | rfl <;> rfl
  reg_loc := fun {c g} h => by obtain ⟨hc, rfl⟩ := h; rcases hc with rfl | rfl <;> rfl
  reg_isRel := fun {c g} h => by obtain ⟨hc, rfl⟩ := h; rcases hc with rfl | rfl <;> rfl
  reg_reads := fun {c g} h => by obtain ⟨hc, rfl⟩ := h; rcases hc with rfl | rfl <;> rfl
  reg_not_claim := fun {c g} h => by obtain ⟨hc, rfl⟩ := h; rcases hc with rfl | rfl <;> decide
  reg_owner := fun {c c' g} h h' => by
    obtain ⟨hc, rfl⟩ := h
    obtain ⟨hc', h'⟩ := h'
    rcases hc with rfl | rfl <;> rcases hc' with rfl | rfl <;>
      first | rfl | exact absurd h' (by decide)
  free_w := fun h => by obtain ⟨rfl, rfl⟩ := h; rfl
  free_loc := fun h => by obtain ⟨rfl, rfl⟩ := h; rfl
  free_isRel := fun h => by obtain ⟨rfl, rfl⟩ := h; rfl
  free_reads := fun h => by obtain ⟨rfl, rfl⟩ := h; exact ⟨aG, ⟨Or.inl rfl, rfl⟩, rfl⟩
  free_not_claim := fun h => by obtain ⟨rfl, rfl⟩ := h; decide
  act_self := fun h => claim_cases (P := fun c => actP c c) (by decide) (by decide) _ h
  act_sb := fun h => act_sb_raw _ _ h
  act_claim := fun h => by rcases h with ⟨rfl, _⟩ | ⟨rfl, _⟩ <;> decide
  act_thr := fun h => act_thr_raw _ _ h
  act_total := fun h h' => thread_total ((act_thr_raw _ _ h).trans (act_thr_raw _ _ h').symm)
  act_before_free := fun h he => by obtain ⟨rfl, rfl⟩ := h; exact act_before_free_raw _ he
  tInit_w := rfl
  tInit_loc := rfl
  tInit_first := fun hw hl hne => hb_of_sb (t_first _ hw hl hne)
  t_writes := fun hw hl => t_writes_raw _ hw hl
  t_w := fun {c s} h => by obtain ⟨hc, rfl⟩ := h; rcases hc with rfl | rfl <;> rfl
  t_loc := fun {c s} h => by obtain ⟨hc, rfl⟩ := h; rcases hc with rfl | rfl <;> rfl
  t_act := fun {c s} h => by obtain ⟨hc, rfl⟩ := h; rcases hc with rfl | rfl <;> decide
  t_before_reg := fun {c s g} h h' => by
    obtain ⟨hc, rfl⟩ := h
    obtain ⟨-, rfl⟩ := h'
    rcases hc with rfl | rfl <;> decide
  t_unique := fun h h' => h.2.trans h'.2.symm
  t_of_reg := fun h => ⟨_, h.1, rfl⟩
  t_val := fun {c s} h => by obtain ⟨hc, rfl⟩ := h; rcases hc with rfl | rfl <;> decide

instance (c e : E) : Decidable (slotP.act c e) := inferInstanceAs (Decidable (actP c e))

/-! ## The pin protocol: four attempts, each ended by its unpin -/

abbrev isAttP (w : E) : Prop := w = aW ∨ w = bW ∨ w = bW2 ∨ w = aW3

/-- The registration that makes an attempt. -/
def attClaim : E → E
  | aW => aC | bW => bC | bW2 => bC | aW3 => aC | _ => iSt

def unpinF : E → E
  | aW => aU | bW => bU | bW2 => bU2 | aW3 => aU3 | _ => iPin

theorem pin_first : ∀ e : E, isWB e = true → locOf e = some Word.pin → e ≠ iPin →
    sbP iPin e := by
  intro e; cases e <;> decide

theorem pin_writes_raw : ∀ e : E, isWB e = true → locOf e = some Word.pin →
    e = iPin ∨ False ∨ ((∃ c, isAttP e ∧ c = attClaim e) ∨ (∃ w, isAttP w ∧ e = unpinF w) ∨
      (∃ _c : E, False)) := by
  intro e hw hl
  cases e <;> first
    | exact Or.inl rfl
    | exact Or.inr (Or.inr (Or.inl ⟨_, by decide, rfl⟩))
    | exact Or.inr (Or.inr (Or.inr (Or.inl ⟨aW, Or.inl rfl, rfl⟩)))
    | exact Or.inr (Or.inr (Or.inr (Or.inl ⟨bW, Or.inr (Or.inl rfl), rfl⟩)))
    | exact Or.inr (Or.inr (Or.inr (Or.inl ⟨bW2, Or.inr (Or.inr (Or.inl rfl)), rfl⟩)))
    | exact Or.inr (Or.inr (Or.inr (Or.inl ⟨aW3, Or.inr (Or.inr (Or.inr rfl)), rfl⟩)))
    | exact absurd hw (by decide)
    | exact absurd hl (by decide)

/-- An attempt's events are its writer's. -/
theorem att_thr : ∀ w x : E, isAttP w → (x = w ∨ sbP w x) → thr x = thr w := by
  rintro w x hw (rfl | h)
  · rfl
  · rcases hw with rfl | rfl | rfl | rfl <;> exact (h.2.resolve_right (by decide)).symm

theorem att_act_raw : ∀ w e : E, isAttP w → (e = w ∨ sbP w e) → actP (attClaim w) e := by
  intro w e hw h
  have := att_thr w e hw h
  rcases hw with rfl | rfl | rfl | rfl
  · exact Or.inl ⟨rfl, this⟩
  · exact Or.inr ⟨rfl, this⟩
  · exact Or.inr ⟨rfl, this⟩
  · exact Or.inl ⟨rfl, this⟩

theorem att_claim : ∀ w : E, isAttP w → isClaimP (attClaim w) := by
  rintro w (rfl | rfl | rfl | rfl) <;> decide

def pinsP : PinProtocol X slotP where
  pin := Word.pin
  pinInit := iPin
  pinVal := id
  isAnnounce := fun _ => False
  w2Of := fun c w => isAttP w ∧ c = attClaim w
  unpinOf := fun w u => isAttP w ∧ u = unpinF w
  clearOf := fun _ _ => False
  pinInit_w := rfl
  pinInit_loc := rfl
  pinInit_first := fun hw hl hne => hb_of_sb (pin_first _ hw hl hne)
  pin_writes := fun hw hl => pin_writes_raw _ hw hl
  ann_rmw := fun h => h.elim
  ann_not := fun h => h.elim
  init_not := by
    rintro (⟨c, hw, _⟩ | ⟨w, hw, h⟩ | ⟨_, h⟩)
    · exact absurd hw (by decide)
    · rcases hw with rfl | rfl | rfl | rfl <;> exact absurd h (by decide)
    · exact h
  w2_act := fun {c w} h => by obtain ⟨hw, rfl⟩ := h; exact att_act_raw w w hw (Or.inl rfl)
  w2_w := fun {c w} h => by obtain ⟨hw, -⟩ := h; rcases hw with rfl | rfl | rfl | rfl <;> rfl
  w2_loc := fun {c w} h => by obtain ⟨hw, -⟩ := h; rcases hw with rfl | rfl | rfl | rfl <;> rfl
  w2_isRel := fun {c w} h => by
    obtain ⟨hw, -⟩ := h; rcases hw with rfl | rfl | rfl | rfl <;> rfl
  unpin_w2 := fun {w u} h => by
    obtain ⟨hw, rfl⟩ := h
    exact ⟨_, ⟨hw, rfl⟩, att_act_raw w _ hw
      (Or.inr (by rcases hw with rfl | rfl | rfl | rfl <;> decide))⟩
  unpin_w := fun {w u} h => by
    obtain ⟨hw, rfl⟩ := h; rcases hw with rfl | rfl | rfl | rfl <;> rfl
  unpin_loc := fun {w u} h => by
    obtain ⟨hw, rfl⟩ := h; rcases hw with rfl | rfl | rfl | rfl <;> rfl
  unpin_isRel := fun {w u} h => by
    obtain ⟨hw, rfl⟩ := h; rcases hw with rfl | rfl | rfl | rfl <;> rfl
  clear_act := fun h => h.elim
  clear_w := fun h => h.elim
  clear_loc := fun h => h.elim
  clear_isRel := fun h => h.elim
  att_act := fun {c w e} h he _ => by obtain ⟨hw, rfl⟩ := h; exact att_act_raw w e hw he
  pass_quiet := fun {c w u N} h hu _ hN hsb hNu => by
    obtain ⟨hw, rfl⟩ := h
    obtain ⟨-, rfl⟩ := hu
    rcases hN with ⟨c', hN', -⟩ | ⟨w', hw', rfl⟩ | ⟨_, h⟩
    · rcases hN' with rfl | rfl | rfl | rfl <;> rcases hw with rfl | rfl | rfl | rfl <;>
        first | exact absurd hsb (by decide) | exact absurd hNu (by decide)
    · rcases hw' with rfl | rfl | rfl | rfl <;> rcases hw with rfl | rfl | rfl | rfl <;>
        first | exact absurd hsb (by decide) | exact absurd hNu (by decide)
    · exact h.elim
  pass_exit := fun {c w N} h _ _ _ => by
    obtain ⟨hw, rfl⟩ := h
    exact ⟨_, ⟨hw, rfl⟩, att_act_raw w _ hw
      (Or.inr (by rcases hw with rfl | rfl | rfl | rfl <;> decide))⟩

/-- Building `inAttempt` from two decidable facts. -/
theorem inAtt_mk {w e : E} (h₁ : e = w ∨ sbP w e)
    (h₂ : e = unpinF w ∨ sbP e (unpinF w)) : pinsP.inAttempt w e :=
  ⟨h₁, fun _ hu => hu.2 ▸ h₂⟩

/-- `inAttempt`, as two decidable facts. -/
theorem inAtt_dec {w e : E} (hw : isAttP w) (h : pinsP.inAttempt w e) :
    (e = w ∨ sbP w e) ∧ (e = unpinF w ∨ sbP e (unpinF w)) :=
  ⟨h.1, h.2 _ ⟨hw, rfl⟩⟩

/-! ## The owner protocol: three tenures, each released -/

abbrev isTenure (e : E) : Prop := e = t1A ∨ e = t2A ∨ e = t3A

/-- The event of each tenure, by its acquiring CAS. -/
def perTenure (x₁ x₂ x₃ : E) : E → E
  | t1A => x₁ | t2A => x₂ | t3A => x₃ | _ => iOwn

abbrev ofTenure (x₁ x₂ x₃ : E) (A e : E) : Prop := isTenure A ∧ e = perTenure x₁ x₂ x₃ A

abbrev relOfP := ofTenure t1O t2O t3O

theorem tenure_cases {P : E → Prop} (h₁ : P t1A) (h₂ : P t2A) (h₃ : P t3A) :
    ∀ A, isTenure A → P A := by
  rintro A (rfl | rfl | rfl)
  · exact h₁
  · exact h₂
  · exact h₃

theorem own_first : ∀ e : E, isWB e = true → locOf e = some Word.own → e ≠ iOwn →
    sbP iOwn e := by
  intro e; cases e <;> decide

theorem own_writes : ∀ e : E, isWB e = true → locOf e = some Word.own →
    e = iOwn ∨ isTenure e ∨ ∃ A, relOfP A e := by
  intro e hw hl
  cases e <;> first
    | exact Or.inl rfl
    | exact Or.inr (Or.inl (by decide))
    | exact Or.inr (Or.inr ⟨t1A, by decide⟩)
    | exact Or.inr (Or.inr ⟨t2A, by decide⟩)
    | exact Or.inr (Or.inr ⟨t3A, by decide⟩)
    | exact absurd hw (by decide)
    | exact absurd hl (by decide)

theorem acq_facts : ∀ A : E, isTenure A →
    isWB A = true ∧ locOf A = some Word.own ∧ acqB A = true ∧ relB A = true ∧
    (src A).isSome = true :=
  tenure_cases (by decide) (by decide) (by decide)

theorem rel_facts : ∀ A r : E, relOfP A r →
    isWB r = true ∧ locOf r = some Word.own ∧ relB r = true ∧ ¬ isTenure r ∧ sbP A r := by
  rintro A r ⟨hA, rfl⟩
  exact tenure_cases (P := fun A => isWB (perTenure t1O t2O t3O A) = true ∧
      locOf (perTenure t1O t2O t3O A) = some Word.own ∧
      relB (perTenure t1O t2O t3O A) = true ∧ ¬ isTenure (perTenure t1O t2O t3O A) ∧
      sbP A (perTenure t1O t2O t3O A))
    (by decide) (by decide) (by decide) A hA

/-- No tenure is taken over: every acquiring CAS reads a `NONE`. -/
theorem no_takeover : ∀ A T : E, isTenure A → isTenure T → ¬ rfP A T := by
  rintro A T (rfl | rfl | rfl) (rfl | rfl | rfl) <;> decide

def owner : OwnerProtocol X where
  own := Word.own
  init := iOwn
  isAcqCas := isTenure
  relOf := relOfP
  init_w := rfl
  init_loc := rfl
  init_first := fun hw hl hne => hb_of_sb (own_first _ hw hl hne)
  writes := fun hw hl => own_writes _ hw hl
  acq_w := fun h => (acq_facts _ h).1
  acq_loc := fun h => (acq_facts _ h).2.1
  acq_isAcq := fun h _ _ => (acq_facts _ h).2.2.1
  acq_isRel := fun h => (acq_facts _ h).2.2.2.1
  acq_reads := fun {A} h => by
    have := (acq_facts A h).2.2.2.2
    cases hs : src A with
    | none => rw [hs] at this; exact absurd this (by decide)
    | some w => exact ⟨w, hs⟩
  rel_acq := fun h => h.1
  rel_w := fun h => (rel_facts _ _ h).1
  rel_loc := fun h => (rel_facts _ _ h).2.1
  rel_isRel := fun h => (rel_facts _ _ h).2.2.1
  rel_not_acq := fun h => (rel_facts _ _ h).2.2.2.1
  rel_sb := fun h => (rel_facts _ _ h).2.2.2.2
  rel_unique := fun h h' => h.2.trans h'.2.symm
  takeover := fun hA hT hrf => absurd hrf (no_takeover _ _ hA hT)

/-- The two halves of `under`, in a form that can be decided. -/
abbrev underP (A e : E) : Prop :=
  (e = A ∨ sbP A e) ∧ (isTenure A → e = perTenure t1O t2O t3O A ∨ sbP e (perTenure t1O t2O t3O A))

theorem under_of {A e : E} (h : underP A e) : owner.under A e :=
  ⟨h.1, fun _ hr => hr.2 ▸ h.2 hr.1⟩

/-! ## The rotation protocol -/

abbrev r0OfP := ofTenure t1q t2q t3q
abbrev r5OfP := ofTenure t1P t2P t3P
abbrev sealOfP := ofTenure t1Z t2Z t3Z
abbrev r4OfP := ofTenure t1R t2R t3R

/-- Three arenas, used in turn: epoch `n` is served by `c (n % 3)`. -/
def ctlLocF (n : Nat) : Word :=
  if n % 3 = 0 then Word.c0 else if n % 3 = 1 then Word.c1 else Word.c2

theorem ctlLoc_cases (n : Nat) :
    ctlLocF n = Word.c0 ∨ ctlLocF n = Word.c1 ∨ ctlLocF n = Word.c2 := by
  unfold ctlLocF
  split
  · exact Or.inl rfl
  · split
    · exact Or.inr (Or.inl rfl)
    · exact Or.inr (Or.inr rfl)

theorem ctl_distinct_raw (n : Nat) :
    ctlLocF (n + 1) ≠ ctlLocF n ∧ ctlLocF (n + 2) ≠ ctlLocF n := by
  unfold ctlLocF
  have : n % 3 = 0 ∨ n % 3 = 1 ∨ n % 3 = 2 := by omega
  rcases this with h | h | h
  · have h₁ : (n + 1) % 3 = 1 := by omega
    have h₂ : (n + 2) % 3 = 2 := by omega
    simp [h, h₁, h₂]
  · have h₁ : (n + 1) % 3 = 2 := by omega
    have h₂ : (n + 2) % 3 = 0 := by omega
    simp [h, h₁, h₂]
  · have h₁ : (n + 1) % 3 = 0 := by omega
    have h₂ : (n + 2) % 3 = 1 := by omega
    simp [h, h₁, h₂]

abbrev isCtlInitP (e : E) : Prop := e = iC0 ∨ e = iC1 ∨ e = iC2
abbrev isB1P (e : E) : Prop := e = aB ∨ e = bB ∨ e = aB3

theorem ep_first : ∀ e : E, isWB e = true → locOf e = some Word.ep → e ≠ iEp → sbP iEp e := by
  intro e; cases e <;> decide

theorem ep_writes_raw : ∀ e : E, isWB e = true → locOf e = some Word.ep →
    e = iEp ∨ ∃ A, r5OfP A e := by
  intro e hw hl
  cases e <;> first
    | exact Or.inl rfl
    | exact Or.inr ⟨t1A, by decide⟩
    | exact Or.inr ⟨t2A, by decide⟩
    | exact Or.inr ⟨t3A, by decide⟩
    | exact absurd hw (by decide)
    | exact absurd hl (by decide)

theorem ctl_writes_raw : ∀ (e : E) (n : Nat), isWB e = true → locOf e = some (ctlLocF n) →
    isCtlInitP e ∨ isB1P e ∨ (∃ A, sealOfP A e) ∨ (∃ A, r4OfP A e) := by
  intro e n hw hl
  cases e <;> first
    | exact Or.inl (by decide)
    | exact Or.inr (Or.inl (by decide))
    | exact Or.inr (Or.inr (Or.inl ⟨t1A, by decide⟩))
    | exact Or.inr (Or.inr (Or.inl ⟨t2A, by decide⟩))
    | exact Or.inr (Or.inr (Or.inl ⟨t3A, by decide⟩))
    | exact Or.inr (Or.inr (Or.inr ⟨t1A, by decide⟩))
    | exact Or.inr (Or.inr (Or.inr ⟨t2A, by decide⟩))
    | exact Or.inr (Or.inr (Or.inr ⟨t3A, by decide⟩))
    | exact absurd hw (by decide)
    | (rcases ctlLoc_cases n with h | h | h <;> rw [h] at hl <;> exact absurd hl (by decide))

theorem ctlInit_first_raw : ∀ i e : E, isCtlInitP i → isWB e = true → locOf e = locOf i →
    e ≠ i → sbP i e := by
  rintro i e (rfl | rfl | rfl) <;> cases e <;> decide

def rotation : RotationProtocol X where
  toOwnerProtocol := owner
  ep := Word.ep
  epInit := iEp
  r0Of := r0OfP
  r5Of := r5OfP
  ctlLoc := ctlLocF
  stamp := fun v => v % 100 / 2
  isSealed := fun v => v % 2 = 1
  isCtlInit := isCtlInitP
  isB1 := isB1P
  sealOf := sealOfP
  r4Of := r4OfP
  r1fOf := fun A q f =>
    (A = t1A ∧ q = t1q ∧ f = t1F) ∨
    (A = t2A ∧ q = t2q ∧ f = t2F) ∨
    (A = t3A ∧ q = t3q ∧ f = t3F)
  epInit_w := rfl
  epInit_loc := rfl
  epInit_val := rfl
  epInit_first := fun hw hl hne => hb_of_sb (ep_first _ hw hl hne)
  ep_writes := fun hw hl => ep_writes_raw _ hw hl
  r0_acq := fun h => h.1
  r0_under := fun {A q} h => by
    obtain ⟨hA, rfl⟩ := h
    rcases hA with rfl | rfl | rfl <;> exact under_of (by decide)
  r0_loc := fun {A q} h => by obtain ⟨hA, rfl⟩ := h; rcases hA with rfl | rfl | rfl <;> rfl
  r0_reads := fun {A q} h => by
    obtain ⟨hA, rfl⟩ := h
    rcases hA with rfl | rfl | rfl <;> exact ⟨_, rfl⟩
  r0_unique := fun h h' => h.2.trans h'.2.symm
  r5_acq := fun h => h.1
  r5_under := fun {A p} h => by
    obtain ⟨hA, rfl⟩ := h
    rcases hA with rfl | rfl | rfl <;> exact under_of (by decide)
  r5_w := fun {A p} h => by obtain ⟨hA, rfl⟩ := h; rcases hA with rfl | rfl | rfl <;> rfl
  r5_loc := fun {A p} h => by obtain ⟨hA, rfl⟩ := h; rcases hA with rfl | rfl | rfl <;> rfl
  r5_unique := fun h h' => h.2.trans h'.2.symm
  r5_r0 := fun {A p} h => by
    obtain ⟨hA, rfl⟩ := h
    exact ⟨_, ⟨hA, rfl⟩, by rcases hA with rfl | rfl | rfl <;> decide,
      by rcases hA with rfl | rfl | rfl <;> decide⟩
  ctl_writes := fun hw hl => ctl_writes_raw _ _ hw hl
  ctlInit_first := fun hi hw hl hne => hb_of_sb (ctlInit_first_raw _ _ hi hw hl hne)
  ctlInit_val := fun {i} hi => by rcases hi with rfl | rfl | rfl <;> decide
  b1_rmw := fun {e} h => by
    rcases h with rfl | rfl | rfl
    · exact ⟨iC2, rfl, by decide, by decide⟩
    · exact ⟨t3R, rfl, by decide, by decide⟩
    · exact ⟨t1R, rfl, by decide, by decide⟩
  seal_acq := fun h => h.1
  seal_under := fun {A Z} h => by
    obtain ⟨hA, rfl⟩ := h
    rcases hA with rfl | rfl | rfl <;> exact under_of (by decide)
  seal_w := fun {A Z} h => by obtain ⟨hA, rfl⟩ := h; rcases hA with rfl | rfl | rfl <;> rfl
  seal_sealed := fun {A Z} h => by
    obtain ⟨hA, rfl⟩ := h; rcases hA with rfl | rfl | rfl <;> decide
  seal_r0 := fun {A Z} h => by
    obtain ⟨hA, rfl⟩ := h
    exact ⟨_, ⟨hA, rfl⟩, by rcases hA with rfl | rfl | rfl <;> decide,
      by rcases hA with rfl | rfl | rfl <;> decide⟩
  seal_before_r5 := fun {A Z p} h h' => by
    obtain ⟨hA, rfl⟩ := h
    obtain ⟨-, rfl⟩ := h'
    rcases hA with rfl | rfl | rfl <;> decide
  seal_before_r4 := fun {A Z w} h h' => by
    obtain ⟨hA, rfl⟩ := h
    obtain ⟨-, rfl⟩ := h'
    rcases hA with rfl | rfl | rfl <;> decide
  r4_acq := fun h => h.1
  r4_under := fun {A w} h => by
    obtain ⟨hA, rfl⟩ := h
    rcases hA with rfl | rfl | rfl <;> exact under_of (by decide)
  r4_w := fun {A w} h => by
    obtain ⟨hA, rfl⟩ := h
    rcases hA with rfl | rfl | rfl <;> decide
  r4_r0 := fun {A w} h => by
    obtain ⟨hA, rfl⟩ := h
    exact ⟨_, ⟨hA, rfl⟩, by rcases hA with rfl | rfl | rfl <;> decide⟩
  r4_unsealed := fun {A w} h => by
    obtain ⟨hA, rfl⟩ := h; rcases hA with rfl | rfl | rfl <;> decide
  loop_exit := fun {A p q} h _ => Or.inl ⟨_, h.1, rfl⟩
  r1f_facts := fun {A q f} h => by
    rcases h with ⟨rfl, rfl, rfl⟩ | ⟨rfl, rfl, rfl⟩ | ⟨rfl, rfl, rfl⟩
    · refine ⟨by decide, by decide, under_of (by decide), ?_, Or.inl ⟨t1Z, by decide⟩⟩
      intro Z hZ
      obtain ⟨_, rfl⟩ := hZ
      decide
    · refine ⟨by decide, by decide, under_of (by decide), ?_, Or.inl ⟨t2Z, by decide⟩⟩
      intro Z hZ
      obtain ⟨_, rfl⟩ := hZ
      decide
    · refine ⟨by decide, by decide, under_of (by decide), ?_, Or.inl ⟨t3Z, by decide⟩⟩
      intro Z hZ
      obtain ⟨_, rfl⟩ := hZ
      decide

instance (v : Nat) : Decidable (rotation.isSealed v) := inferInstanceAs (Decidable (v % 2 = 1))

/-! ## The cache protocol: one slot -/

def cache : CacheProtocol X where
  Lv := liveness
  ι := Unit
  slot := fun _ => slotP
  pins := fun _ => pinsP
  R := rotation
  ctl_distinct := ctl_distinct_raw
  ctl_cycle := by
    intro n
    change ctlLocF (n + 3) = ctlLocF n
    simp only [ctlLocF, show (n + 3) % 3 = n % 3 by omega]
  r5_isRel := fun {A p} h => by
    obtain ⟨hA, rfl⟩ := h; rcases hA with rfl | rfl | rfl <;> rfl
  r5_r4 := fun {A p} h => by
    obtain ⟨hA, rfl⟩ := h
    exact ⟨_, ⟨hA, rfl⟩, by rcases hA with rfl | rfl | rfl <;> decide⟩
  r4_unique := fun h h' => h.2.trans h'.2.symm
  r4_loc := fun {A w q} h hq => by
    obtain ⟨hA, rfl⟩ := h
    obtain ⟨-, rfl⟩ := hq
    rcases hA with rfl | rfl | rfl <;> decide
  ctlInit_stamp := fun {i} hi => by rcases hi with rfl | rfl | rfl <;> decide
  seal_rmw := fun {A Z} h => by
    obtain ⟨hA, rfl⟩ := h
    rcases hA with rfl | rfl | rfl <;> exact ⟨_, rfl, by decide, by decide⟩
  scan := fun {B w4 qB} h4 hq => by
    obtain ⟨hB, rfl⟩ := h4
    obtain ⟨-, rfl⟩ := hq
    rcases hB with rfl | rfl | rfl
    · exact ⟨t1F, rfl, under_of (by decide),
        fun _ => ⟨t1S, aW, rfl, rfl, rfl, by decide, by decide, by decide⟩⟩
    · exact ⟨t2F, rfl, under_of (by decide),
        fun _ => ⟨t2S, aU3, rfl, rfl, rfl, by decide, by decide, by decide⟩⟩
    · exact ⟨t3F, rfl, under_of (by decide),
        fun _ => ⟨t3S, aU3, rfl, rfl, rfl, by decide, by decide, by decide⟩⟩
  isAttempt := fun _ w => isAttP w
  attEpoch := wv
  att_w2 := fun h => ⟨_, h, rfl⟩
  att_pin := fun _ => rfl
  att_w1 := fun {i w} h => by
    rcases h with rfl | rfl | rfl | rfl
    · exact ⟨aQ, iEp, rfl, rfl, rfl, by decide, rfl⟩
    · exact ⟨bQ, t3P, rfl, rfl, rfl, by decide, rfl⟩
    · exact ⟨bQ2, t3P, rfl, rfl, rfl, by decide, rfl⟩
    · exact ⟨aQ3, t1P, rfl, rfl, rfl, by decide, rfl⟩
  att_b0 := fun {i w} h => by
    rcases h with rfl | rfl | rfl | rfl
    · exact ⟨aF, aL, iC2, rfl, by decide, by decide, rfl, by decide, by decide, by decide⟩
    · exact ⟨bF, bL, t3R, rfl, by decide, by decide, rfl, by decide, by decide, by decide⟩
    · exact ⟨bF2, bL2, bB, rfl, by decide, by decide, rfl, by decide, by decide, by decide⟩
    · exact ⟨aF3, aL3, t1R, rfl, by decide, by decide, rfl, by decide, by decide, by decide⟩
  b1_after_b0 := fun {i w b} hw hb hb1 => by
    have hd := inAtt_dec hw hb
    rcases hw with rfl | rfl | rfl | rfl <;> rcases hb1 with rfl | rfl | rfl <;>
      first
        | exact absurd hd (by decide)
        | exact ⟨rfl, by decide, aL, iC2, by decide, rfl, by decide, by decide⟩
        | exact ⟨rfl, by decide, bL, t3R, by decide, rfl, by decide, by decide⟩
        | exact ⟨rfl, by decide, aL3, t1R, by decide, rfl, by decide, by decide⟩

/-! ## The arena -/

/-- The record words, in the order of `ReserveProtocol.isRecord` below. -/
abbrev isRecordP (l : Word) : Prop :=
  l = Word.rcd ∨ l = Word.rc2 ∨ l = Word.rcA ∨ l = Word.rcB ∨ l = Word.rc3 ∨ l = Word.rc3b ∨
    l = Word.rc3c ∨ l = Word.rc3d

abbrev isArenaP (l : Word) : Prop := isRecordP l ∨ l = Word.idx ∨ l = Word.idx3
abbrev isArenaInitP (e : E) : Prop := e = iRec ∨ e = iRec2 ∨ e = iIdx ∨ e = iIdx3

/-- The arena of an arena word, named by its control word: `rcd`, `rc2`,
`rcA`, `rcB` and `idx` belong to `c2`'s arena (epochs 2 and 5), `rc3` to
`rc3d` and `idx3` to `c0`'s (epoch 3). -/
def arenaW : Word → Word
  | Word.rc3 | Word.rc3b | Word.rc3c | Word.rc3d | Word.idx3 => Word.c0
  | _ => Word.c2

theorem arenaInit_first_raw : ∀ i x : E, isArenaInitP i → isWB x = true →
    locOf x = locOf i → x ≠ i → sbP i x := by
  rintro i x (rfl | rfl | rfl | rfl) <;> cases x <;> decide

/-- An attempt writes only the arena words of its own epoch's arena. -/
theorem att_arena : ∀ (w x : E) (l : Word), isAttP w →
    (x = w ∨ sbP w x) ∧ (x = unpinF w ∨ sbP x (unpinF w)) → isWB x = true →
    locOf x = some l → isArenaP l → arenaW l = ctlLocF (wv w) := by
  intro w x l hw hd hW hl ha
  rcases ha with (rfl | rfl | rfl | rfl | rfl | rfl | rfl | rfl) | rfl | rfl <;>
    rcases hw with rfl | rfl | rfl | rfl <;>
    first
      | decide
      | (revert hd hW hl; cases x <;> decide)

def arena : ArenaProtocol X cache where
  isArena := isArenaP
  isArenaInit := isArenaInitP
  arenaInit_first := fun hi hw hl hne => hb_of_sb (arenaInit_first_raw _ _ hi hw hl hne)
  arenaInit_not_att := fun {x i w} hx hw ha => by
    have hd := inAtt_dec hw ha
    rcases hx with rfl | rfl | rfl | rfl <;> rcases hw with rfl | rfl | rfl | rfl <;>
      exact absurd hd (by decide)
  aw_owner := fun {x l} hw hl ha => by
    rcases ha with (rfl | rfl | rfl | rfl | rfl | rfl | rfl | rfl) | rfl | rfl <;> cases x <;>
      first
      | exact Or.inl (by decide)
      | exact Or.inr ⟨(), aW, Or.inl rfl, inAtt_mk (by decide) (by decide)⟩
      | exact Or.inr ⟨(), bW, Or.inr (Or.inl rfl), inAtt_mk (by decide) (by decide)⟩
      | exact Or.inr ⟨(), bW2, Or.inr (Or.inr (Or.inl rfl)), inAtt_mk (by decide) (by decide)⟩
      | exact Or.inr ⟨(), aW3, Or.inr (Or.inr (Or.inr rfl)), inAtt_mk (by decide) (by decide)⟩
      | exact absurd hw (by decide)
      | exact absurd hl (by decide)
  owner_epoch := fun {x i w j w'} hw hx hw' hx' => by
    have hd := inAtt_dec hw hx
    have hd' := inAtt_dec hw' hx'
    rcases hw with rfl | rfl | rfl | rfl <;> rcases hw' with rfl | rfl | rfl | rfl <;>
      first | rfl | (revert hd hd'; cases x <;> decide)
  same_arena := fun {x x' l i w j w'} hxl hx'l hl hW hW' hw hx hw' hx' => by
    have h₁ := att_arena w x l hw (inAtt_dec hw hx) hW hxl hl
    have h₂ := att_arena w' x' l hw' (inAtt_dec hw' hx') hW' hx'l hl
    have h := h₁.symm.trans h₂
    rcases hw with rfl | rfl | rfl | rfl <;> rcases hw' with rfl | rfl | rfl | rfl <;>
      first | decide | exact absurd h (by decide)
  aw_after_fence := fun {x l i w} hW hl ha hw hx => by
    have hd := inAtt_dec hw hx
    rcases hw with rfl | rfl | rfl | rfl
    · refine ⟨aQ, iEp, aF, rfl, rfl, rfl, by decide, rfl, ?_⟩
      rcases ha with (rfl | rfl | rfl | rfl | rfl | rfl | rfl | rfl) | rfl | rfl <;> cases x <;>
        first
        | decide
        | exact absurd hl (by decide)
        | exact absurd hW (by decide)
        | exact absurd hd (by decide)
    · refine ⟨bQ, t3P, bF, rfl, rfl, rfl, by decide, rfl, ?_⟩
      rcases ha with (rfl | rfl | rfl | rfl | rfl | rfl | rfl | rfl) | rfl | rfl <;> cases x <;>
        first
        | decide
        | exact absurd hl (by decide)
        | exact absurd hW (by decide)
        | exact absurd hd (by decide)
    · refine ⟨bQ2, t3P, bF2, rfl, rfl, rfl, by decide, rfl, ?_⟩
      rcases ha with (rfl | rfl | rfl | rfl | rfl | rfl | rfl | rfl) | rfl | rfl <;> cases x <;>
        first
        | decide
        | exact absurd hl (by decide)
        | exact absurd hW (by decide)
        | exact absurd hd (by decide)
    · refine ⟨aQ3, t1P, aF3, rfl, rfl, rfl, by decide, rfl, ?_⟩
      rcases ha with (rfl | rfl | rfl | rfl | rfl | rfl | rfl | rfl) | rfl | rfl <;> cases x <;>
        first
        | decide
        | exact absurd hl (by decide)
        | exact absurd hW (by decide)
        | exact absurd hd (by decide)

/-! ## The reservations and the chunk words -/

instance (v : Nat) : Decidable (cache.R.isSealed v) := inferInstanceAs (Decidable (v % 2 = 1))

/-- Writer `a`'s records (an 8-byte key and an 8-byte value) are a chunk
long, so it claims exactly its record; writer `b`'s (an empty key and value)
are half a chunk. -/
def needF : E → Nat
  | aW => 32
  | aW3 => 32
  | _ => 16

/-- The lengths word of each attempt: `key_len + 2^32 * value_len`. -/
def lensF : E → Nat
  | aW => 34359738376
  | aW3 => 34359738376
  | _ => 0

abbrev isChunkInitP (e : E) : Prop := e = iCE ∨ e = iCC ∨ e = iCN

/-- The stores of `reset_chunk()` in each claim. -/
abbrev resetOfP (c x : E) : Prop :=
  (c = aC ∧ (x = aHe ∨ x = aHc ∨ x = aHn)) ∨ (c = bC ∧ (x = bHe ∨ x = bHc ∨ x = bHn))

/-- `rcd`, `rc2`, `rcA` and `rcB` are the first four words of `c2`'s record
area, `rc3` to `rc3d` the first four of `c0`'s. -/
def recOffF : Word → Nat
  | Word.rc2 | Word.rc3b => 8
  | Word.rcA | Word.rc3c => 16
  | Word.rcB | Word.rc3d => 24
  | _ => 0

theorem rec_ext_raw : ∀ l l' : Word, isRecordP l → isRecordP l' → arenaW l = arenaW l' →
    recOffF l = recOffF l' → l = l' := by
  intro l l' hl hl'
  rcases hl with rfl | rfl | rfl | rfl | rfl | rfl | rfl | rfl <;>
    rcases hl' with rfl | rfl | rfl | rfl | rfl | rfl | rfl | rfl <;> decide

theorem chunkInit_first_raw : ∀ i x : E, isChunkInitP i → isWB x = true →
    locOf x = locOf i → x ≠ i → sbP i x := by
  rintro i x (rfl | rfl | rfl) <;> cases x <;> decide

/-- A record area of 64 bytes and chunks of 32. Writer `a` claims its record
of 32 bytes at B1. Writer `b`'s first attempt claims a chunk at B1 and stores
it (`bKe`, `bKc`, `bKn`) after its record of 16 bytes; its second attempt
cuts its record from that chunk (`bLe`, `bLc`, `bLn`, `bMc`) instead of a
B1. -/
def reserve : ReserveProtocol X cache where
  cap := 64
  chunk := 32
  maxClaim := 32
  bump := fun v => v / 100
  need := needF
  claim := fun _ => 32
  slots := [()]
  slots_nodup := List.nodup_cons.mpr ⟨List.not_mem_nil, List.nodup_nil⟩
  slots_all := fun _ => List.mem_singleton.mpr rfl
  lens := lensF
  need_lens := fun {i w} hw => by rcases hw with rfl | rfl | rfl | rfl <;> decide
  need_le := fun {i w} hw => by rcases hw with rfl | rfl | rfl | rfl <;> decide
  chunk_le := by decide
  a5 := by decide
  att_unique := fun {i j w w' x} hw hw' hx hx' => by
    cases i; cases j
    have hd := inAtt_dec hw hx
    have hd' := inAtt_dec hw' hx'
    refine ⟨rfl, ?_⟩
    rcases hw with rfl | rfl | rfl | rfl <;> rcases hw' with rfl | rfl | rfl | rfl <;>
      first | rfl | (revert hd hd'; cases x <;> decide)
  att_slot := fun _ _ => rfl
  ctlInit_bump := fun {x} h => by rcases h with rfl | rfl | rfl <;> decide
  r4_bump := fun {A x} h => by obtain ⟨hA, rfl⟩ := h; rcases hA with rfl | rfl | rfl <;> decide
  seal_bump := fun {A Z} h => by
    obtain ⟨hA, rfl⟩ := h
    rcases hA with rfl | rfl | rfl <;> exact ⟨_, rfl, by decide⟩
  b1_bump := fun {b} h => by rcases h with rfl | rfl | rfl <;> decide
  b1_att := fun {b} h => by
    rcases h with rfl | rfl | rfl
    · exact ⟨(), aW, Or.inl rfl, inAtt_mk (by decide) (by decide)⟩
    · exact ⟨(), bW, Or.inr (Or.inl rfl), inAtt_mk (by decide) (by decide)⟩
    · exact ⟨(), aW3, Or.inr (Or.inr (Or.inr rfl)), inAtt_mk (by decide) (by decide)⟩
  b1_unique := fun {i w b b'} hw hb hb' hB hB' => by
    have hd := inAtt_dec hw hb
    have hd' := inAtt_dec hw hb'
    rcases hw with rfl | rfl | rfl | rfl <;> rcases hB with rfl | rfl | rfl <;>
      rcases hB' with rfl | rfl | rfl <;>
      first | rfl | exact absurd hd (by decide) | exact absurd hd' (by decide)
  b1_b0 := fun {i w b} hw hb hB => by
    have hd := inAtt_dec hw hb
    rcases hw with rfl | rfl | rfl | rfl <;> rcases hB with rfl | rfl | rfl <;>
      first
        | exact absurd hd (by decide)
        | exact ⟨aL, iC2, by decide, by decide, rfl, by decide, by decide, by decide,
            by decide⟩
        | exact ⟨bL, t3R, by decide, by decide, rfl, by decide, by decide, by decide,
            by decide⟩
        | exact ⟨aL3, t1R, by decide, by decide, rfl, by decide, by decide, by decide,
            by decide⟩
  ceLoc := fun _ => Word.ce
  ccLoc := fun _ => Word.cc
  cnLoc := fun _ => Word.cn
  isChunkInit := isChunkInitP
  chunkInit_w := fun {x} h => by rcases h with rfl | rfl | rfl <;> rfl
  chunkInit_val := fun {x} h => by rcases h with rfl | rfl | rfl <;> rfl
  chunkInit_first := fun hi hw hl hne => hb_of_sb (chunkInit_first_raw _ _ hi hw hl hne)
  resetOf := fun _ c x => resetOfP c x
  reset_act := fun {i c x} h => by
    rcases h with ⟨rfl, h⟩ | ⟨rfl, h⟩ <;> rcases h with rfl | rfl | rfl <;>
      first | exact Or.inl ⟨rfl, rfl⟩ | exact Or.inr ⟨rfl, rfl⟩
  reset_val := fun {i c x} h => by
    rcases h with ⟨rfl, h⟩ | ⟨rfl, h⟩ <;> rcases h with rfl | rfl | rfl <;> rfl
  reset_before := fun {i c x w} h h2 => by
    obtain ⟨hw, rfl⟩ := h2
    rcases hw with rfl | rfl | rfl | rfl <;> rcases h with ⟨h, hx⟩ | ⟨h, hx⟩ <;>
      first | exact absurd h (by decide) | (rcases hx with rfl | rfl | rfl <;> decide)
  reset_ce := fun {i c w} h2 => by
    obtain ⟨hw, rfl⟩ := h2
    rcases hw with rfl | rfl | rfl | rfl
    · exact ⟨aHe, Or.inl ⟨rfl, Or.inl rfl⟩, rfl, rfl⟩
    · exact ⟨bHe, Or.inr ⟨rfl, Or.inl rfl⟩, rfl, rfl⟩
    · exact ⟨bHe, Or.inr ⟨rfl, Or.inl rfl⟩, rfl, rfl⟩
    · exact ⟨aHe, Or.inl ⟨rfl, Or.inl rfl⟩, rfl, rfl⟩
  chunkOf := fun _ w b xe xc xn => w = bW ∧ b = bB ∧ xe = bKe ∧ xc = bKc ∧ xn = bKn
  chunk_b1 := fun {i w b xe xc xn} h => by
    obtain ⟨rfl, rfl, rfl, rfl, rfl⟩ := h
    exact ⟨Or.inr (Or.inl rfl), inAtt_mk (by decide) (by decide), Or.inr (Or.inl rfl),
      by decide, by decide⟩
  chunk_after_b1 := fun {i w b xe xc xn} h => by
    obtain ⟨rfl, rfl, rfl, rfl, rfl⟩ := h
    decide
  chunk_after_b1_c := fun {i w b xe xc xn} h => by
    obtain ⟨rfl, rfl, rfl, rfl, rfl⟩ := h
    decide
  chunk_after_b1_n := fun {i w b xe xc xn} h => by
    obtain ⟨rfl, rfl, rfl, rfl, rfl⟩ := h
    decide
  chunk_ev := fun {i w b xe xc xn} h => by
    obtain ⟨rfl, rfl, rfl, rfl, rfl⟩ := h
    exact ⟨by decide, by decide, by decide, inAtt_mk (by decide) (by decide),
      inAtt_mk (by decide) (by decide), inAtt_mk (by decide) (by decide), rfl, rfl, rfl,
      rfl, rfl, rfl, by decide, by decide, by decide⟩
  chunk_unique := fun h h' => by
    obtain ⟨rfl, rfl, rfl, rfl, rfl⟩ := h
    obtain ⟨-, -, rfl, rfl, rfl⟩ := h'
    exact ⟨rfl, rfl, rfl⟩
  useOf := fun _ w le lc ln s => w = bW2 ∧ le = bLe ∧ lc = bLc ∧ ln = bLn ∧ s = bMc
  use_ev := fun {i w le lc ln s} h => by
    obtain ⟨rfl, rfl, rfl, rfl, rfl⟩ := h
    exact ⟨Or.inr (Or.inr (Or.inl rfl)), by decide, by decide, by decide, by decide, by decide,
      inAtt_mk (by decide) (by decide), inAtt_mk (by decide) (by decide),
      inAtt_mk (by decide) (by decide), inAtt_mk (by decide) (by decide), rfl, rfl, rfl,
      rfl, rfl, ⟨_, rfl⟩, ⟨_, rfl⟩, ⟨_, rfl⟩, by decide, by decide, by decide⟩
  use_no_b1 := fun {i w le lc ln s b} h hb hB => by
    obtain ⟨rfl, -⟩ := h
    have hd := inAtt_dec (Or.inr (Or.inr (Or.inl rfl))) hb
    rcases hB with rfl | rfl | rfl <;> exact absurd hd (by decide)
  use_unique := fun h h' => by
    obtain ⟨rfl, rfl, rfl, rfl, rfl⟩ := h
    obtain ⟨-, rfl, rfl, rfl, rfl⟩ := h'
    exact ⟨rfl, rfl, rfl, rfl⟩
  ce_writes := fun {i x} hw hl => by
    cases i
    cases x <;> first
      | exact Or.inl (by decide)
      | exact Or.inr (Or.inl ⟨aC, Or.inl ⟨rfl, by decide⟩⟩)
      | exact Or.inr (Or.inl ⟨bC, Or.inr ⟨rfl, by decide⟩⟩)
      | exact Or.inr (Or.inr ⟨bW, bB, bKc, bKn, rfl, rfl, rfl, rfl, rfl⟩)
      | exact absurd hw (by decide)
      | exact absurd hl (by decide)
  cc_writes := fun {i x} hw hl => by
    cases i
    cases x <;> first
      | exact Or.inl (by decide)
      | exact Or.inr (Or.inl ⟨aC, Or.inl ⟨rfl, by decide⟩⟩)
      | exact Or.inr (Or.inl ⟨bC, Or.inr ⟨rfl, by decide⟩⟩)
      | exact Or.inr (Or.inr (Or.inl ⟨bW, bB, bKe, bKn, rfl, rfl, rfl, rfl, rfl⟩))
      | exact Or.inr (Or.inr (Or.inr ⟨bW2, bLe, bLc, bLn, rfl, rfl, rfl, rfl, rfl⟩))
      | exact absurd hw (by decide)
      | exact absurd hl (by decide)
  cn_writes := fun {i x} hw hl => by
    cases i
    cases x <;> first
      | exact Or.inl (by decide)
      | exact Or.inr (Or.inl ⟨aC, Or.inl ⟨rfl, by decide⟩⟩)
      | exact Or.inr (Or.inl ⟨bC, Or.inr ⟨rfl, by decide⟩⟩)
      | exact Or.inr (Or.inr ⟨bW, bB, bKe, bKc, rfl, rfl, rfl, rfl, rfl⟩)
      | exact absurd hw (by decide)
      | exact absurd hl (by decide)
  isRecord := isRecordP
  recOff := recOffF
  recArena := arenaW
  rec_ext := fun {l l'} h h' ha ho => rec_ext_raw l l' h h' ha ho
  rec_arena := fun {i w x l} hw hx hW hl hr => by
    cases i
    exact att_arena w x l hw (inAtt_dec hw hx) hW hl (Or.inl hr)
  rec_in_res := fun {i w x l} hw hx hW hl hr => by
    cases i
    have hd := inAtt_dec hw hx
    rcases hw with rfl | rfl | rfl | rfl
    · refine ⟨0, Or.inl ⟨aB, inAtt_mk (by decide) (by decide), Or.inl rfl, by decide,
        by decide⟩, ?_⟩
      rcases hr with rfl | rfl | rfl | rfl | rfl | rfl | rfl | rfl <;> decide
    · refine ⟨0, Or.inl ⟨bB, inAtt_mk (by decide) (by decide), Or.inr (Or.inl rfl),
        by decide, by decide⟩, ?_⟩
      rcases hr with rfl | rfl | rfl | rfl | rfl | rfl | rfl | rfl <;>
        first | decide | (revert hd hW hl; cases x <;> decide)
    · refine ⟨16, Or.inr ⟨bLe, bLc, bLn, bMc, ⟨rfl, rfl, rfl, rfl, rfl⟩, by decide⟩, ?_⟩
      rcases hr with rfl | rfl | rfl | rfl | rfl | rfl | rfl | rfl <;>
        first | decide | (revert hd hW hl; cases x <;> decide)
    · refine ⟨0, Or.inl ⟨aB3, inAtt_mk (by decide) (by decide), Or.inr (Or.inr rfl),
        by decide, by decide⟩, ?_⟩
      rcases hr with rfl | rfl | rfl | rfl | rfl | rfl | rfl | rfl <;> decide
  rec_once := fun {i w x x' l} hw hx hx' hW hW' hl hl' hr => by
    cases i
    have hd := inAtt_dec hw hx
    have hd' := inAtt_dec hw hx'
    rcases hr with rfl | rfl | rfl | rfl | rfl | rfl | rfl | rfl <;>
      rcases hw with rfl | rfl | rfl | rfl <;> cases x <;>
      first
      | exact absurd hl (by decide)
      | exact absurd hW (by decide)
      | exact absurd hd (by decide)
      | (cases x' <;> first
          | rfl
          | exact absurd hl' (by decide)
          | exact absurd hW' (by decide)
          | exact absurd hd' (by decide))

/-! ## The index -/

abbrev isPubCasP (e : E) : Prop := e = aI ∨ e = bI ∨ e = aI3
abbrev isIndexP (l : Word) : Prop := l = Word.idx ∨ l = Word.idx3

/-- Writer `a`'s key is 1, writer `b`'s is 2. -/
def pubKeyF : E → Nat
  | aI | aI3 => 1
  | _ => 2

/-- Every epoch load returns at most 5. -/
theorem ep_small : ∀ e : E, X.loc e = some Word.ep → X.rval e ≤ 5 := by
  intro e; cases e <;> decide

/-- The words of a 32-byte record at offset 0. -/
theorem cover4 {o : Nat} (h₁ : 0 ≤ o) (h₂ : o + 8 ≤ 0 + 32) (h₃ : o % 8 = 0) :
    o = 0 ∨ o = 8 ∨ o = 16 ∨ o = 24 := by omega

/-- The words of a 16-byte record at offset `off`. -/
theorem cover2 {o off : Nat} (h₁ : off ≤ o) (h₂ : o + 8 ≤ off + 16) (h₃ : o % 8 = 0)
    (h₄ : off % 8 = 0) : o = off ∨ o = off + 8 := by omega

/-- Writers `a` and `b` publish with a CAS, `a`'s promotion too (in `c0`'s
arena); writer `b`'s second attempt finds its key, with a header and a
lengths load (`bK`, `bKl`) of writer `b`'s record, and replaces the entry
with a store. Each key's probe sequence starts at the slot it uses. -/
def index : IndexProtocol X arena reserve where
  isPubCas := isPubCasP
  isPubStore := fun e => e = bS
  pub_kinds_disjoint := fun h h' => by subst h'; exact (by decide : ¬ isPubCasP bS) h
  pubKey := pubKeyF
  pub_rel := fun {x} h => by rcases h with (rfl | rfl | rfl) | rfl <;> rfl
  isIndex := isIndexP
  index_arena := fun {l} h => by
    rcases h with rfl | rfl
    · exact Or.inr (Or.inl rfl)
    · exact Or.inr (Or.inr rfl)
  record_arena := fun {l} h => Or.inl h
  arenaOf := arenaW
  pub_slot := fun {x i w l} hxk hw hx hl => by
    have hd := inAtt_dec hw hx
    rcases hxk with (rfl | rfl | rfl) | rfl <;> cases Option.some.inj hl <;>
      rcases hw with rfl | rfl | rfl | rfl <;>
      first
        | exact ⟨Or.inl rfl, by decide⟩
        | exact ⟨Or.inr rfl, by decide⟩
        | exact absurd hd (by decide)
  entryOff := fun v => v / wordLimit * 8
  entryOff_aligned := fun v => Nat.mul_mod_left _ _
  zero_off := rfl
  idx_writes := fun {x l} hW hl hi => by
    rcases hi with rfl | rfl <;> cases x <;> first
      | exact Or.inl ⟨Or.inr (Or.inr (Or.inl rfl)), rfl⟩
      | exact Or.inl ⟨Or.inr (Or.inr (Or.inr rfl)), rfl⟩
      | exact Or.inr ⟨Or.inl (Or.inl rfl), (), aW, Or.inl rfl, inAtt_mk (by decide) (by decide)⟩
      | exact Or.inr ⟨Or.inl (Or.inr (Or.inl rfl)), (), bW, Or.inr (Or.inl rfl),
          inAtt_mk (by decide) (by decide)⟩
      | exact Or.inr ⟨Or.inl (Or.inr (Or.inr rfl)), (), aW3, Or.inr (Or.inr (Or.inr rfl)),
          inAtt_mk (by decide) (by decide)⟩
      | exact Or.inr ⟨Or.inr rfl, (), bW2, Or.inr (Or.inr (Or.inl rfl)),
          inAtt_mk (by decide) (by decide)⟩
      | exact absurd hW (by decide)
      | exact absurd hl (by decide)
  pub_off := fun {x i w} hxk hw hx => by
    cases i
    have hd := inAtt_dec hw hx
    rcases hxk with (rfl | rfl | rfl) | rfl <;> rcases hw with rfl | rfl | rfl | rfl <;>
      first
        | exact absurd hd (by decide)
        | exact Or.inl ⟨aB, inAtt_mk (by decide) (by decide), Or.inl rfl,
            ⟨by decide, by decide⟩, by decide⟩
        | exact Or.inl ⟨bB, inAtt_mk (by decide) (by decide), Or.inr (Or.inl rfl),
            ⟨by decide, by decide⟩, by decide⟩
        | exact Or.inl ⟨aB3, inAtt_mk (by decide) (by decide), Or.inr (Or.inr rfl),
            ⟨by decide, by decide⟩, by decide⟩
        | exact Or.inr ⟨bLe, bLc, bLn, bMc, ⟨rfl, rfl, rfl, rfl, rfl⟩, by decide⟩
  pub_stamp := fun {x i w} hxk hw hx => by
    have hd := inAtt_dec hw hx
    rcases hxk with (rfl | rfl | rfl) | rfl <;> rcases hw with rfl | rfl | rfl | rfl <;>
      first | decide | exact absurd hd (by decide)
  pub_record := fun {x i w} hxk hw hx o h₁ h₂ h₃ => by
    cases i
    have hd := inAtt_dec hw hx
    rcases hxk with (rfl | rfl | rfl) | rfl <;> rcases hw with rfl | rfl | rfl | rfl <;>
      first | exact absurd hd (by decide) | skip
    · -- Writer `a`'s record, at offset 0 of `c2`'s area.
      rcases cover4 h₁ h₂ h₃ with rfl | rfl | rfl | rfl
      · exact ⟨aR, Word.rcd, inAtt_mk (by decide) (by decide), rfl, rfl,
          Or.inl rfl, by decide, rfl, by decide, by decide⟩
      · exact ⟨aRl, Word.rc2, inAtt_mk (by decide) (by decide), rfl, rfl,
          Or.inr (Or.inl rfl), by decide, rfl, by decide, by decide⟩
      · exact ⟨aRk, Word.rcA, inAtt_mk (by decide) (by decide), rfl, rfl,
          Or.inr (Or.inr (Or.inl rfl)), by decide, rfl, by decide, by decide⟩
      · exact ⟨aRv, Word.rcB, inAtt_mk (by decide) (by decide), rfl, rfl,
          Or.inr (Or.inr (Or.inr (Or.inl rfl))), by decide, rfl, by decide, by decide⟩
    · -- Writer `b`'s first record, at offset 0 of `c2`'s area.
      rcases cover2 (off := 0) h₁ h₂ h₃ rfl with rfl | rfl
      · exact ⟨bR, Word.rcd, inAtt_mk (by decide) (by decide), rfl, rfl,
          Or.inl rfl, by decide, rfl, by decide, by decide⟩
      · exact ⟨bRl, Word.rc2, inAtt_mk (by decide) (by decide), rfl, rfl,
          Or.inr (Or.inl rfl), by decide, rfl, by decide, by decide⟩
    · -- The promotion's record, at offset 0 of `c0`'s area.
      rcases cover4 h₁ h₂ h₃ with rfl | rfl | rfl | rfl
      · exact ⟨aR3, Word.rc3, inAtt_mk (by decide) (by decide), rfl, rfl,
          Or.inr (Or.inr (Or.inr (Or.inr (Or.inl rfl)))), by decide, rfl, by decide, by decide⟩
      · exact ⟨aR3l, Word.rc3b, inAtt_mk (by decide) (by decide), rfl, rfl,
          Or.inr (Or.inr (Or.inr (Or.inr (Or.inr (Or.inl rfl))))), by decide, rfl, by decide,
          by decide⟩
      · exact ⟨aR3k, Word.rc3c, inAtt_mk (by decide) (by decide), rfl, rfl,
          Or.inr (Or.inr (Or.inr (Or.inr (Or.inr (Or.inr (Or.inl rfl)))))), by decide, rfl,
          by decide, by decide⟩
      · exact ⟨aR3v, Word.rc3d, inAtt_mk (by decide) (by decide), rfl, rfl,
          Or.inr (Or.inr (Or.inr (Or.inr (Or.inr (Or.inr (Or.inr rfl)))))), by decide, rfl,
          by decide, by decide⟩
    · -- Writer `b`'s second record, cut at offset 16 of `c2`'s area.
      rcases cover2 (off := 16) h₁ h₂ h₃ rfl with rfl | rfl
      · exact ⟨bR2, Word.rcA, inAtt_mk (by decide) (by decide), rfl, rfl,
          Or.inr (Or.inr (Or.inl rfl)), by decide, rfl, by decide, by decide⟩
      · exact ⟨bR2l, Word.rcB, inAtt_mk (by decide) (by decide), rfl, rfl,
          Or.inr (Or.inr (Or.inr (Or.inl rfl))), by decide, rfl, by decide, by decide⟩
  cap_ge := by decide
  cap_aligned := by decide
  entry_life := fun {s v Y x l} _ hv _ _ _ _ => by
    have := ep_small v hv
    exact ⟨fun _ => by unfold wordLimit; omega, fun _ _ _ _ => by unfold wordLimit; omega⟩
  cas_stamp := fun {x i w} hx hw hxw => by
    have hd := inAtt_dec hw hxw
    rcases hx with rfl | rfl | rfl <;> rcases hw with rfl | rfl | rfl | rfl <;>
      first | decide | exact absurd hd (by decide)
  cas_reads := fun {x} h => by rcases h with rfl | rfl | rfl <;> exact ⟨_, rfl⟩
  store_src := fun {x i w l} hx hw hxw hl => by
    subst hx
    have hd := inAtt_dec hw hxw
    cases Option.some.inj hl
    rcases hw with rfl | rfl | rfl | rfl
    · exact absurd hd (by decide)
    · exact absurd hd (by decide)
    · refine ⟨bJ, bI, rfl, rfl, by decide, inAtt_mk (by decide) (by decide), rfl, by decide,
        fun Y => Y = bK ∨ Y = bKl, fun Y hY => ?_, fun Y hY => ?_, fun _ _ _ _ _ => rfl⟩
      · rcases hY with rfl | rfl
        · exact ⟨Word.rcd, rfl, Or.inl rfl, rfl, Or.inl (by decide)⟩
        · exact ⟨Word.rc2, rfl, Or.inr (Or.inl rfl), rfl, Or.inr (Or.inl (by decide))⟩
      · rcases hY with rfl | rfl
        · exact ⟨by decide, inAtt_mk (by decide) (by decide)⟩
        · exact ⟨by decide, inAtt_mk (by decide) (by decide)⟩
    · exact absurd hd (by decide)
  dist := fun _ _ => 0
  dist_inj := fun {k l l'} h h' ha _ => by
    rcases h with rfl | rfl <;> rcases h' with rfl | rfl <;>
      first | rfl | exact absurd ha (by decide)
  pub_skip := fun {x i w l l'} _ _ _ _ _ _ hd => absurd hd (Nat.lt_irrefl 0)

/-! ## The lookups' hits -/

/-- Thread 6's hit on writer `b`'s first entry, in epoch 5: a header and a
lengths load. -/
def hitK : Hit index where
  v1 := kV1
  s1 := t3P
  v1_rf := rfl
  v1_loc := rfl
  e := 5
  e_def := Or.inl rfl
  i1 := kI
  slot := Word.idx
  x2 := bI
  i1_loc := rfl
  slot_index := Or.inl rfl
  i1_rf := rfl
  v1_i1 := by decide
  i1_stamp := by decide
  i1f := kF
  d6 := kD
  v2 := kV
  s2 := t3P
  i1_i1f := by decide
  i1f_acq := rfl
  i1_d6 := by decide
  d6_acq := rfl
  d6_v2 := by decide
  v2_rf := rfl
  v2_loc := rfl
  v2_pass := by decide
  loads := fun Y => Y = kY ∨ Y = kYl
  loads_rec := fun Y hY => by
    rcases hY with rfl | rfl
    · exact ⟨Word.rcd, rfl, Or.inl rfl, rfl, Or.inl (by decide)⟩
    · exact ⟨Word.rc2, rfl, Or.inr (Or.inl rfl), rfl, Or.inr (Or.inl (by decide))⟩
  loads_sb := fun Y hY => by rcases hY with rfl | rfl <;> exact ⟨by decide, by decide⟩
  life := by decide

/-- Thread 1's hit on writer `a`'s entry, in the previous generation (epoch 2,
V1 having read 3): the header, the lengths, the key word and the value
word. -/
def hitA : Hit index where
  v1 := aV1
  s1 := t1P
  v1_rf := rfl
  v1_loc := rfl
  e := 2
  e_def := Or.inr rfl
  i1 := aJ
  slot := Word.idx
  x2 := aI
  i1_loc := rfl
  slot_index := Or.inl rfl
  i1_rf := rfl
  v1_i1 := by decide
  i1_stamp := by decide
  i1f := aJF
  d6 := aD
  v2 := aV2
  s2 := t1P
  i1_i1f := by decide
  i1f_acq := rfl
  i1_d6 := by decide
  d6_acq := rfl
  d6_v2 := by decide
  v2_rf := rfl
  v2_loc := rfl
  v2_pass := by decide
  loads := fun Y => Y = aY ∨ Y = aYl ∨ Y = aYk ∨ Y = aYv
  loads_rec := fun Y hY => by
    rcases hY with rfl | rfl | rfl | rfl
    · exact ⟨Word.rcd, rfl, Or.inl rfl, rfl, Or.inl (by decide)⟩
    · exact ⟨Word.rc2, rfl, Or.inr (Or.inl rfl), rfl, Or.inr (Or.inl (by decide))⟩
    · exact ⟨Word.rcA, rfl, Or.inr (Or.inr (Or.inl rfl)), rfl, Or.inr (Or.inr
        ⟨aYl, Word.rc2, Or.inr (Or.inl rfl), ⟨aRl, rfl⟩, rfl, Or.inr (Or.inl rfl), rfl,
          by decide, Or.inl ⟨0, rfl, by decide, by decide⟩⟩)⟩
    · exact ⟨Word.rcB, rfl, Or.inr (Or.inr (Or.inr (Or.inl rfl))), rfl, Or.inr (Or.inr
        ⟨aYl, Word.rc2, Or.inr (Or.inl rfl), ⟨aRl, rfl⟩, rfl, Or.inr (Or.inl rfl), rfl,
          by decide, Or.inr ⟨0, rfl, by decide, by decide⟩⟩)⟩
  loads_sb := fun Y hY => by
    rcases hY with rfl | rfl | rfl | rfl <;> exact ⟨by decide, by decide⟩
  life := by decide

/-! ## The values: writer `a`'s promotion -/

/-- Writer `a` stores key 1 with value 42, writer `b` key 2 with an empty
value (0); writer `a`'s third attempt is a promotion of the record its
lookup hit (`hitA`, writer `a`'s first record), after loading epoch 3 at
V1. -/
def value : ValueProtocol X index where
  attKey := fun w => if w = aW ∨ w = aW3 then 1 else 2
  attVal := fun w => if w = aW ∨ w = aW3 then 42 else 0
  inserted := fun k v => (k = 1 ∧ v = 42) ∨ (k = 2 ∧ v = 0)
  isPromo := fun w => w = aW3
  src := fun _ => aJ
  ins_val := fun {i w} hw hp => by
    rcases hw with rfl | rfl | rfl | rfl
    · exact Or.inl ⟨rfl, rfl⟩
    · exact Or.inr ⟨rfl, rfl⟩
    · exact Or.inr ⟨rfl, rfl⟩
    · exact absurd rfl hp
  pub_key := fun {x i w} hxk hw hx => by
    have hd := inAtt_dec hw hx
    rcases hxk with (rfl | rfl | rfl) | rfl <;> rcases hw with rfl | rfl | rfl | rfl <;>
      first | rfl | exact absurd hd (by decide)
  promo_src := fun {i w} _ hp => by
    subst hp
    refine ⟨hitA, rfl, rfl, fun j w0 hw0 hread => ?_⟩
    have hd := inAtt_dec hw0 (hread aY (Or.inl rfl) aR rfl)
    rcases hw0 with rfl | rfl | rfl | rfl
    · exact ⟨rfl, rfl⟩
    all_goals exact absurd hd (by decide)

/-! ## Readiness: `t2`'s R3C and the recheck's C4 -/

abbrev isCertP (c : E) : Prop := c = t2C ∨ c = aC4

/-- The readiness word of the arena of epoch `n`, as `ctlLocF`. -/
def rdyLocF (n : Nat) : Word :=
  if n % 3 = 0 then Word.rdy0 else if n % 3 = 1 then Word.rdy1 else Word.rdy2

theorem rdyLoc_cases (n : Nat) :
    rdyLocF n = Word.rdy0 ∨ rdyLocF n = Word.rdy1 ∨ rdyLocF n = Word.rdy2 := by
  unfold rdyLocF
  split
  · exact Or.inl rfl
  · split
    · exact Or.inr (Or.inl rfl)
    · exact Or.inr (Or.inr rfl)

/-- `t2C` certifies 3 on `c0`'s readiness word (R3C); `aC4` certifies 2 on
`c2`'s (C4). Both read 0. -/
def readiness : ReadinessProtocol X cache where
  rdyLoc := rdyLocF
  isRdyInit := fun e => e = iRdy0 ∨ e = iRdy2
  isCert := isCertP
  certArg := wv
  rdyInit_val := fun {i} h => by rcases h with rfl | rfl <;> rfl
  rdy_writes := fun {e n} hw hl => by
    cases e <;> first
      | exact Or.inl (Or.inl rfl)
      | exact Or.inl (Or.inr rfl)
      | exact Or.inr (Or.inl rfl)
      | exact Or.inr (Or.inr rfl)
      | exact absurd hw (by decide)
      | (rcases rdyLoc_cases n with h | h | h <;> rw [h] at hl <;> exact absurd hl (by decide))
  cert_w := fun {c} h => by rcases h with rfl | rfl <;> rfl
  cert_loc := fun {c} h => by rcases h with rfl | rfl <;> rfl
  cert_rel := fun {c} h => by rcases h with rfl | rfl <;> rfl
  cert_rmw := fun {c} h => by
    rcases h with rfl | rfl
    · exact ⟨iRdy0, rfl, rfl⟩
    · exact ⟨iRdy2, rfl, rfl⟩
  cert_src := fun {c} h => by
    rcases h with rfl | rfl
    · -- R3C: `t2` sealed 3 itself, fenced, and its scan read `aU3`.
      exact Or.inl ⟨t2A, t2q, t2F, ⟨Or.inr (Or.inl rfl), rfl⟩, rfl, rfl, under_of (by decide),
        by decide, fun {Z} hZ => by obtain ⟨-, rfl⟩ := hZ; decide,
        Or.inl ⟨t2Z, ⟨Or.inr (Or.inl rfl), rfl⟩⟩,
        fun i => by cases i; exact ⟨t2S, aU3, rfl, rfl, rfl, by decide, by decide, by decide⟩⟩
    · -- C4: C1 read 3 from `t1`'s R5, C2 fenced, C3 read `aU`.
      exact Or.inr ⟨aC1, t1P, aC2, rfl, rfl, rfl, rfl, by decide, rfl, by decide,
        fun i => by cases i; exact ⟨aC3, aU, rfl, rfl, rfl, by decide, by decide, by decide⟩⟩

/-- Writer `a`'s promotion copies the reprobe's hit `hitA`, which follows
the recheck's certificate `aC4`. -/
theorem hitA_promo : PromoHit value readiness aW3 hitA := by
  refine ⟨⟨rfl, rfl, fun j w0 hw0 hread => ?_⟩, Or.inr ⟨aC4, Or.inr rfl, rfl, by decide⟩, rfl⟩
  have hd := inAtt_dec hw0 (hread aY (Or.inl rfl) aR rfl)
  rcases hw0 with rfl | rfl | rfl | rfl
  · exact ⟨rfl, rfl⟩
  all_goals exact absurd hd (by decide)

/-- The promotion follows `hitA` and publishes with a CAS (`aI3`). -/
theorem promotion : PromotionProtocol X value readiness where
  promo_ready := fun {i w} _ hp => by subst hp; exact ⟨hitA, hitA_promo⟩
  promo_cas := fun {i w x} _ hp hx hxk => by
    subst hp
    have hd := inAtt_dec (Or.inr (Or.inr (Or.inr rfl))) hx
    rcases hxk with h | rfl
    · exact h
    · exact absurd hd (by decide)

/-! ## The theorems on this execution -/

/-- EP4: the third rotation's reset of `c2` happens before writer `b`'s
W1, which reads the epoch it published. -/
theorem witness_reset_before_w1 : X.hb t3R bQ :=
  cache.reset_before_w1 (A := t3A) ⟨Or.inr (Or.inr rfl), rfl⟩ ⟨Or.inr (Or.inr rfl), rfl⟩
    rfl rfl

/-- QU1: writer `a`'s record store happens before the reset of its arena by
the tenure that rechecks epoch 4. -/
theorem witness_attempt_before_resets : X.hb aR t3R :=
  cache.attempt_before_resets consistent (i := ()) (w := aW) (B := t3A) (qB := t3q)
    (Or.inl rfl) ⟨Or.inr (Or.inr rfl), rfl⟩ ⟨Or.inr (Or.inr rfl), rfl⟩ (by decide)
    (inAtt_mk (by decide) (by decide))

/-- QU2(a): the two incarnations of `c2`'s arena are serial. -/
theorem witness_attempts_ordered : X.hb aR bR :=
  cache.attempts_ordered consistent (i := ()) (j := ()) (w := aW) (w' := bW)
    (Or.inl rfl) (Or.inr (Or.inl rfl)) (by decide)
    (inAtt_mk (by decide) (by decide)) (inAtt_mk (by decide) (by decide))

/-- QU2(b): writer `a` reads nothing writer `b` wrote. -/
theorem witness_no_future_read : ¬ X.rf bR aR :=
  cache.no_future_read consistent (i := ()) (j := ()) (w := aW) (w' := bW)
    (Or.inl rfl) (Or.inr (Or.inl rfl)) (by decide)
    (inAtt_mk (by decide) (by decide)) (inAtt_mk (by decide) (by decide))

/-- SP5, the order: the two record stores to `rcd` are in epoch order. -/
theorem witness_arena_writes_ordered : X.mo aR bR :=
  cache.arena_writes_ordered consistent (i := ()) (j := ()) (w := aW) (w' := bW)
    (Or.inl rfl) (Or.inr (Or.inl rfl)) (by decide)
    (inAtt_mk (by decide) (by decide)) (inAtt_mk (by decide) (by decide))
    rfl rfl rfl (by decide)

/-- SP1: writer `b`'s first reservation lands in incarnation 5. -/
theorem witness_b1_lands : cache.R.stamp (X.rval bB) = cache.attEpoch bW :=
  cache.b1_lands consistent (i := ()) (Or.inr (Or.inl rfl))
    (inAtt_mk (by decide) (by decide)) (Or.inr (Or.inl rfl))

/-- SP1, the reset: the reset of `c2` for epoch 5 happens before writer
`b`'s first reservation. -/
theorem witness_r4_before_b1 : X.hb t3R bB :=
  cache.r4_before_b1 consistent (i := ()) (w := bW) (B := t3A) (qB := t3q)
    (Or.inr (Or.inl rfl)) (inAtt_mk (by decide) (by decide)) ⟨Or.inr (Or.inr rfl), rfl⟩
    ⟨Or.inr (Or.inr rfl), rfl⟩ (by decide)

/-- EP3, R4: the third rotation resets `c2` after the seal of epoch 4. -/
theorem witness_seal_before_reset : ∃ B Z, cache.R.sealOf B Z ∧
    cache.R.stamp (X.wval Z) = X.rval t3q ∧ X.hb Z t3R :=
  cache.R.seal_before_reset consistent (A := t3A) ⟨Or.inr (Or.inr rfl), rfl⟩
    ⟨Or.inr (Or.inr rfl), rfl⟩ ⟨Or.inr (Or.inr rfl), rfl⟩

/-- EP3(iii): the seal of epoch 2 happens before the R1F of the rotation
that rechecks epoch 4, the fence of its reuse scan. -/
theorem witness_seal_before_scan : ∃ A Z, cache.R.sealOf A Z ∧
    cache.R.stamp (X.wval Z) = 2 ∧ X.hb Z t3F :=
  cache.R.seal_before_scan consistent (B := t3A) (qB := t3q) ⟨Or.inr (Or.inr rfl), rfl⟩
    rfl (by decide) (under_of (by decide))

/-- SP2: writer `b`'s B1 does not carry out of the bump field. -/
theorem witness_no_wrap : reserve.bump (X.rval bB) + reserve.claim bB < bumpLimit :=
  reserve.no_wrap consistent (i := ()) (Or.inr (Or.inl rfl))
    (inAtt_mk (by decide) (by decide)) (Or.inr (Or.inl rfl))

/-- SP3: writer `b`'s second attempt cuts its record from the chunk its
first attempt claimed, and its epoch and end loads read that claim's
stores. -/
theorem witness_chunk_source : ∃ w0 b0 xe xc xn, reserve.chunkOf () w0 b0 xe xc xn ∧
    X.rf xe bLe ∧ X.rf xn bLn := by
  obtain ⟨_, w0, b0, xe, xc, xn, hch, -, -, -, -, hxe, hxn, -⟩ :=
    reserve.chunk_source consistent bMc (i := ()) (w := bW2) (le := bLe) (lc := bLc)
      (ln := bLn) ⟨rfl, rfl, rfl, rfl, rfl⟩
  exact ⟨w0, b0, xe, xc, xn, hch, hxe, hxn⟩

/-- SP4, in the area: the cut lies in the record area. -/
theorem witness_reserve_in_area : 16 + reserve.need bW2 ≤ reserve.cap :=
  reserve.reserve_in_area consistent (i := ())
    (Or.inr ⟨bLe, bLc, bLn, bMc, ⟨rfl, rfl, rfl, rfl, rfl⟩, by decide⟩)

/-- SP4, disjoint: in epoch 5, writer `b`'s record won at B1 and the
record cut from its chunk are disjoint. -/
theorem witness_reserve_disjoint :
    0 + reserve.need bW ≤ 16 ∨ 16 + reserve.need bW2 ≤ 0 :=
  reserve.reserve_disjoint consistent (i := ()) (j := ()) (w := bW) (w' := bW2)
    (Or.inr (Or.inl rfl)) (Or.inr (Or.inr (Or.inl rfl))) rfl (by decide)
    (Or.inl ⟨bB, inAtt_mk (by decide) (by decide), Or.inr (Or.inl rfl),
      ⟨by decide, by decide⟩, by decide⟩)
    (Or.inr ⟨bLe, bLc, bLn, bMc, ⟨rfl, rfl, rfl, rfl, rfl⟩, by decide⟩)

/-- SP5, write-once: the stores of epoch 5 to the record word `rcd` are
writer `b`'s first record store. -/
theorem witness_record_write_once : ∀ {x : E}, (cache.pins ()).inAttempt bW2 x →
    X.isW x → X.loc x = some Word.rcd → x = bR := fun hx hW hl =>
  (reserve.record_write_once consistent (i := ()) (j := ()) (w := bW) (w' := bW2) (x := bR)
    (Or.inr (Or.inl rfl)) (inAtt_mk (by decide) (by decide)) (Or.inr (Or.inr (Or.inl rfl))) hx
    rfl hW rfl hl (Or.inl rfl) rfl).symm

/-- HT1: the lookup's epoch reload, after its fence, sees at least
the epoch of the record store it read. -/
theorem witness_recycled_caught : cache.attEpoch bW ≤ X.rval kV :=
  arena.recycled_caught consistent (x := bR) (Y := kY) (d6 := kD) (v2 := kV) (s2 := t3P)
    (l := Word.rcd) (i := ()) (w := bW) rfl rfl (Or.inl (Or.inl rfl)) (Or.inr (Or.inl rfl))
    (inAtt_mk (by decide) (by decide)) rfl (by decide) rfl (by decide) rfl rfl

/-- HT2: thread 6's hit followed writer `b`'s publication, of the epoch it
probed, and its header and lengths loads read writer `b`'s stores. -/
theorem witness_hit_integrity : ∃ i w, cache.isAttempt i w ∧ (cache.pins i).inAttempt w bI ∧
    (index.isPubCas bI ∨ index.isPubStore bI) ∧ cache.attEpoch w = 5 ∧
    ∀ Y, hitK.loads Y → ∀ z, X.rf z Y → (cache.pins i).inAttempt w z :=
  index.hit_integrity consistent hitK

/-- HT2, in the previous generation: the promoting lookup's key and value
loads, whose addresses come from the lengths it loaded, read writer `a`'s
stores. -/
theorem witness_hit_integrity_prev : ∃ i w, cache.isAttempt i w ∧
    (cache.pins i).inAttempt w aI ∧ (index.isPubCas aI ∨ index.isPubStore aI) ∧
    cache.attEpoch w = 2 ∧ ∀ Y, hitA.loads Y → ∀ z, X.rf z Y → (cache.pins i).inAttempt w z :=
  index.hit_integrity consistent hitA

/-- RB4 (one key per slot): writer `b`'s CAS and the replacing store of its second attempt,
both of epoch 5, carry one key. The store's key check (`bK`, `bKl`) is
discharged by RB3, `current_entry` and `keyCheck_holds`. -/
theorem witness_slot_key_fixed : index.pubKey bI = index.pubKey bS :=
  index.slot_key_fixed consistent (l := Word.idx) (Or.inr (Or.inl rfl)) (x := bS)
    (y := bI) (i := ()) (j := ()) (w := bW2) (w' := bW)
    (Or.inr rfl) rfl rfl (Or.inr (Or.inr (Or.inl rfl))) (inAtt_mk (by decide) (by decide))
    (Or.inl (Or.inr (Or.inl rfl))) rfl rfl (Or.inr (Or.inl rfl))
    (inAtt_mk (by decide) (by decide)) rfl

/-- RB3 (the key check): writer `b`'s second attempt, pinned to epoch 5, acquired
writer `b`'s entry, so its key-check load `bK` read writer `b`'s record
store. -/
theorem witness_pinned_read : ∀ z, X.rf z bK → z = bR := fun z hz =>
  arena.pinned_read consistent reserve (sY := bR) (l := Word.rcd) (i := ()) (j := ())
    (w := bW2) (w' := bW) (Or.inr (Or.inr (Or.inl rfl))) (inAtt_mk (by decide) (by decide))
    (Or.inr (Or.inl rfl)) (inAtt_mk (by decide) (by decide)) rfl rfl rfl (Or.inl (Or.inl rfl))
    (Or.inl rfl) rfl
    (hb_trans (hb_of_sb (by decide : X.sb bR bI))
      (hb_trans (hb_of_sw (sw.relAcq (show X.isRel bI from rfl) (rs.refl bI)
        (show X.rf bI bJ from rfl) (show X.isAcq bJ from rfl)))
        (hb_of_sb (by decide : X.sb bJ bK)))) hz

/-- RB4 (one slot per key, no gap): writer `b`'s CAS and its second attempt's store, for one key in
epoch 5, are in one slot. -/
theorem witness_key_one_slot : Word.idx = Word.idx :=
  index.key_one_slot consistent (x := bI) (x' := bS) (i := ()) (j := ()) (w := bW)
    (w' := bW2) (Or.inl (Or.inr (Or.inl rfl))) rfl rfl (Or.inr (Or.inl rfl))
    (inAtt_mk (by decide) (by decide)) (Or.inr rfl) rfl rfl (Or.inr (Or.inr (Or.inl rfl)))
    (inAtt_mk (by decide) (by decide)) rfl rfl

/-- HT3, the step: writer `a`'s promotion, in epoch 3, stores the key and
value of an attempt of an earlier epoch (writer `a`'s first, of epoch 2). -/
theorem witness_promo_earlier : ∃ j w0, cache.isAttempt j w0 ∧
    cache.attEpoch w0 < cache.attEpoch aW3 ∧ value.attKey w0 = value.attKey aW3 ∧
    value.attVal aW3 = value.attVal w0 :=
  value.promo_earlier consistent (i := ()) (Or.inr (Or.inr (Or.inr rfl))) rfl

/-- HT3: the promotion's publication pairs its key with a value some caller
inserted for it. -/
theorem witness_provenance : value.inserted (index.pubKey aI3) (value.attVal aW3) :=
  value.published_provenance consistent (i := ()) (Or.inl (Or.inr (Or.inr rfl)))
    (Or.inr (Or.inr (Or.inr rfl))) (inAtt_mk (by decide) (by decide))

/-- BD1: the promotion's record store is in the record area. -/
theorem witness_record_store_in_area : reserve.recOff Word.rc3 + 8 ≤ reserve.cap :=
  reserve.record_store_in_area consistent (i := ()) (w := aW3) (x := aR3)
    (Or.inr (Or.inr (Or.inr rfl))) (inAtt_mk (by decide) (by decide)) rfl rfl
    (Or.inr (Or.inr (Or.inr (Or.inr (Or.inl rfl)))))

/-- BD2: the entry writer `b`'s second attempt stored, at offset 16,
has its header and lengths words in the record area. -/
theorem witness_entry_in_area : index.entryOff (X.wval bS) + 16 ≤ reserve.cap :=
  index.entry_in_area consistent (x := bS) (l := Word.idx) rfl rfl (Or.inl rfl)

/-- BD2: the lookup's index load returns an entry whose
record, for a header of length 16 with an empty key and an 8-byte value,
lies in the record area. -/
theorem witness_reader_in_bounds :
    index.entryOff (X.rval kI) + 8 ≤ reserve.cap ∧
      index.entryOff (X.rval kI) + 16 ≤ reserve.cap ∧
      index.entryOff (X.rval kI) + 16 + (0 + 7) / 8 * 8 ≤ reserve.cap ∧
      index.entryOff (X.rval kI) + 16 + (0 + 7) / 8 * 8 + 0 + 8 ≤ reserve.cap :=
  index.reader_in_bounds consistent (x := bI) (l := Word.idx) (len := 16) (kl := 0) (vl := 8)
    (pos := 0) rfl rfl (Or.inl rfl) (by decide) (by decide) (by decide) (by decide) rfl

/-- RD1 for R3C: everything the promotion's attempt does, its publication
`aI3` included, happens before `t2`'s certificate for epoch 3. -/
theorem witness_cert_sound_rotation : X.hb aI3 t2C :=
  readiness.cert_sound consistent (i := ()) (w := aW3) (Or.inl rfl)
    (Or.inr (Or.inr (Or.inr rfl))) rfl (inAtt_mk (by decide) (by decide))

/-- RD1 for C4: writer `a`'s publication in epoch 2 happens before the
recheck's certificate for epoch 2. -/
theorem witness_cert_sound_recheck : X.hb aI aC4 :=
  readiness.cert_sound consistent (i := ()) (w := aW) (Or.inr rfl) (Or.inl rfl) rfl
    (inAtt_mk (by decide) (by decide))

/-- FR1: writer `a`'s publication in epoch 2 happens before the reprobe's
index load. -/
theorem witness_promo_source_after : X.hb aI aJ :=
  PromotionProtocol.promo_source_after consistent hitA_promo (j := ()) (w' := aW)
    (Or.inl rfl) rfl (inAtt_mk (by decide) (by decide))

/-- FR2: the promoted entry is writer `a`'s publication of key 1 in epoch
2, the promotion stores its value, and every publication of key 1 in epoch 2
is that entry or precedes it in its slot. -/
theorem witness_promo_fresh : ∃ j w0, cache.isAttempt j w0 ∧ (cache.pins j).inAttempt w0 aI ∧
    (index.isPubCas aI ∨ index.isPubStore aI) ∧ cache.attEpoch w0 = 2 ∧
    index.pubKey aI = value.attKey aW3 ∧ value.attVal aW3 = value.attVal w0 ∧
    ∀ {y l k w'}, (index.isPubCas y ∨ index.isPubStore y) → X.isW y → X.loc y = some l →
      cache.isAttempt k w' → (cache.pins k).inAttempt w' y → cache.attEpoch w' = 2 →
      index.pubKey y = index.pubKey aI → l = Word.idx ∧ (y = aI ∨ X.mo y aI) :=
  PromotionProtocol.promo_fresh consistent hitA_promo

/-- F9 with RB4(d): the promotion's CAS in `idx3` is preceded there by no
write of any attempt of epoch 3. -/
theorem witness_promo_first : ∀ {j : cache.ι} {w' y : E}, cache.isAttempt j w' →
    (cache.pins j).inAttempt w' y → X.isW y → X.loc y = some Word.idx3 →
    cache.attEpoch w' = 3 → ¬ X.mo y aI3 := fun hw' hy hyW hyl hE =>
  promotion.promo_first consistent (i := ()) (Or.inr (Or.inr (Or.inr rfl))) rfl
    (inAtt_mk (by decide) (by decide)) (Or.inl (Or.inr (Or.inr rfl))) rfl rfl
    hw' hy hyW hyl hE

end CacheWitness
end Sgc
