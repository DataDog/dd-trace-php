// @see http://www.math.sci.hiroshima-u.ac.jp/~m-mat/MT/VERSIONS/C-LANG/mt19937-64.c
#ifndef DD_MT19937_64_H
#define DD_MT19937_64_H
void init_genrand64(unsigned long long seed);
unsigned long long genrand64_int64(void);
#endif  // DD_MT19937_64_H
