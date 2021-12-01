Naming Conventions
------------------

PUBLIC C functions and globals should generally be named starting with `dd_<module
name>_`. Functions and globals that have extern linkage for technical reasons
but that nevertheless are not public (e.g. to support macros/inline functions),
should be prefixed with `_`.

The exceptions are functions or variables named according to PHP conventions, as
defined by PHP macros.

Some exceptions to these rules may be admitted for conciseness in very common or
generic "helper" functions. An example of a deviation is `mlog`.

Public macros:
* Should follow the same conventions as public functions if the fact that they
  are implemented as macros is an unimportant detail.
* Should be all uppercase if they are generic "helper" macros like `LSTRLEN`.
* In general, prefer inline functions to macros, except when macros are needed
  for technical reasons.


Public types should be named starting with `dd_`.

Definition Order
----------------

On .c files, public functions should be defined before their auxiliary
functions. This requires prototypes of at least some of the static functions.
It's also encouraged to define static linkage functions before their own
auxiliary functions, as this helps with readability. In general, make it easier
to understand a source file if it is read from the top to the bottom. For
instance, this implies that a `dd_module_init()` should be defined before
`dd_module_deinit()`, assuming that `deinit` is rolling back state set up by
`init`.

<!-- vim: set spell tw=80: -->
