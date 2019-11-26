dnl If needed, define the m4_ifblank and m4_ifnblank macros from autoconf 2.64
dnl This allows us to run with earlier Autoconfs as well.
dnl
dnl m4_ifblank(COND, [IF-BLANK], [IF-TEXT])
dnl m4_ifnblank(COND, [IF-TEXT], [IF-BLANK])
dnl ----------------------------------------
dnl If COND is empty, or consists only of blanks (space, tab, newline),
dnl then expand IF-BLANK, otherwise expand IF-TEXT.  This differs from
dnl m4_ifval only if COND has just whitespace, but it helps optimize in
dnl spite of users who mistakenly leave trailing space after what they
dnl thought was an empty argument:
dnl   macro(
dnl         []
dnl        )
dnl
dnl Writing one macro in terms of the other causes extra overhead, so
dnl we inline both definitions.
ifdef([m4_ifblank],[],[
m4_define([m4_ifblank],
[m4_if(m4_translit([[$1]],  [ ][	][
]), [], [$2], [$3])])])

ifdef([m4_ifnblank],[],[
m4_define([m4_ifnblank],
[m4_if(m4_translit([[$1]],  [ ][	][
]), [], [$3], [$2])])])

