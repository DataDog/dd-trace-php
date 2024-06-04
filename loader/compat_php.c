#include <php.h>
#include <stdbool.h>

#include "compat_php.h"

// Replacement of zend_string_hash_val for compatibility
zend_ulong ddloader_string_hash_val(zend_string *s) {
	return ZSTR_H(s) ? ZSTR_H(s) : (ZSTR_H(s) = zend_hash_func(ZSTR_VAL(s), ZSTR_LEN(s)));
}

// zend_string_equal_content
static bool ddloader_string_equal_content(const zend_string *s1, const zend_string *s2) {
    return ZSTR_LEN(s1) == ZSTR_LEN(s2) && !memcmp(ZSTR_VAL(s1), ZSTR_VAL(s2), ZSTR_LEN(s1));
}

Bucket *php70_71_72_zend_hash_find_bucket(const HashTable *ht, zend_string *key)
{
	zend_ulong h;
	uint32_t nIndex;
	uint32_t idx;
	Bucket *p, *arData;

	h = zend_string_hash_val(key);
	arData = ht->arData;
	nIndex = h | ht->nTableMask;
	idx = HT_HASH_EX(arData, nIndex);
	while (EXPECTED(idx != HT_INVALID_IDX)) {
		p = HT_HASH_TO_BUCKET_EX(arData, idx);
		if (EXPECTED(p->key == key)) { /* check for the same interned string */
			return p;
		} else if (EXPECTED(p->h == h) &&
		     EXPECTED(p->key) &&
		     EXPECTED(ZSTR_LEN(p->key) == ZSTR_LEN(key)) &&
		     EXPECTED(memcmp(ZSTR_VAL(p->key), ZSTR_VAL(key), ZSTR_LEN(key)) == 0)) {
			return p;
		}
		idx = Z_NEXT(p->val);
	}
	return NULL;
}

// Hash must be known: zend_string_hash_val(key)
Bucket *php73_zend_hash_find_bucket(const HashTable *ht, const zend_string *key)
{
	uint32_t nIndex;
	uint32_t idx;
	Bucket *p, *arData;

	ZEND_ASSERT(ZSTR_H(key) != 0 && "Hash must be known");

	arData = ht->arData;
	nIndex = ZSTR_H(key) | ht->nTableMask;
	idx = HT_HASH_EX(arData, nIndex);

	if (UNEXPECTED(idx == HT_INVALID_IDX)) {
		return NULL;
	}
	p = HT_HASH_TO_BUCKET_EX(arData, idx);
	if (EXPECTED(p->key == key)) { /* check for the same interned string */
		return p;
	}

	while (1) {
		if (p->h == ZSTR_H(key) &&
		    EXPECTED(p->key) &&
		    ddloader_string_equal_content(p->key, key)) {
			return p;
		}
		idx = Z_NEXT(p->val);
		if (idx == HT_INVALID_IDX) {
			return NULL;
		}
		p = HT_HASH_TO_BUCKET_EX(arData, idx);
		if (p->key == key) { /* check for the same interned string */
			return p;
		}
	}
}

Bucket *ddloader_zend_hash_find_bucket(int php_api_no, HashTable *ht, zend_string *key) {
    (void)ddloader_string_hash_val(key);

    if (php_api_no <= 20170718) {  // PHP 7.0 - 7.2
        return php70_71_72_zend_hash_find_bucket(ht, key);
    }

    return php73_zend_hash_find_bucket(ht, key);
}

#define HT_FLAGS(ht) (ht)->u.flags
# define HT_ASSERT(ht, expr)

// PHP 70-72
#define PHP70_HT_OK					0x00
#define PHP70_HT_IS_DESTROYING		0x40
#define PHP70_HT_DESTROYED			0x80
#define PHP70_HT_CLEANING				0xc0

#define PHP70_HASH_MASK_CONSISTENCY 0xc0

static void php70_zend_is_inconsistent(const HashTable *ht, const char *file, int line)
{
	if ((ht->u.flags & PHP70_HASH_MASK_CONSISTENCY) == PHP70_HT_OK) {
		return;
	}
	switch ((ht->u.flags & PHP70_HASH_MASK_CONSISTENCY)) {
		case PHP70_HT_IS_DESTROYING:
			zend_output_debug_string(1, "%s(%d) : ht=%p is being destroyed", file, line, ht);
			break;
		case PHP70_HT_DESTROYED:
			zend_output_debug_string(1, "%s(%d) : ht=%p is already destroyed", file, line, ht);
			break;
		case PHP70_HT_CLEANING:
			zend_output_debug_string(1, "%s(%d) : ht=%p is being cleaned", file, line, ht);
			break;
		default:
			zend_output_debug_string(1, "%s(%d) : ht=%p is inconsistent", file, line, ht);
			break;
	}
	zend_bailout();
}

// PHP 72
typedef struct _zend_array72 {
	zend_refcounted_h gc;
	union {
		struct {
			ZEND_ENDIAN_LOHI_4(
				zend_uchar    flags,
				zend_uchar    nApplyCount,
				zend_uchar    nIteratorsCount,
				zend_uchar    consistency)
		} v;
		uint32_t flags;
	} u;
	uint32_t          nTableMask;
	Bucket           *arData;
	uint32_t          nNumUsed;
	uint32_t          nNumOfElements;
	uint32_t          nTableSize;
	uint32_t          nInternalPointer;
	zend_long         nNextFreeElement;
	dtor_func_t       pDestructor;
} HashTable72;

static void php71_72_zend_is_inconsistent(const HashTable72 *ht, const char *file, int line)
{
	if (ht->u.v.consistency == PHP70_HT_OK) {
		return;
	}
	switch (ht->u.v.consistency) {
		case PHP70_HT_IS_DESTROYING:
			zend_output_debug_string(1, "%s(%d) : ht=%p is being destroyed", file, line, ht);
			break;
		case PHP70_HT_DESTROYED:
			zend_output_debug_string(1, "%s(%d) : ht=%p is already destroyed", file, line, ht);
			break;
		case PHP70_HT_CLEANING:
			zend_output_debug_string(1, "%s(%d) : ht=%p is being cleaned", file, line, ht);
			break;
		default:
			zend_output_debug_string(1, "%s(%d) : ht=%p is inconsistent", file, line, ht);
			break;
	}
	ZEND_ASSERT(0);
}

// Since PHP 7.3
#define PHP73_HT_OK					0x00
#define PHP73_HT_IS_DESTROYING		0x01
#define PHP73_HT_DESTROYED			0x02
#define PHP73_HT_CLEANING				0x03
static void php73_zend_is_inconsistent(const HashTable *ht, const char *file, int line)
{
	if ((HT_FLAGS(ht) & HASH_FLAG_CONSISTENCY) == PHP73_HT_OK) {
		return;
	}
	switch (HT_FLAGS(ht) & HASH_FLAG_CONSISTENCY) {
		case PHP73_HT_IS_DESTROYING:
			zend_output_debug_string(1, "%s(%d) : ht=%p is being destroyed", file, line, ht);
			break;
		case PHP73_HT_DESTROYED:
			zend_output_debug_string(1, "%s(%d) : ht=%p is already destroyed", file, line, ht);
			break;
		case PHP73_HT_CLEANING:
			zend_output_debug_string(1, "%s(%d) : ht=%p is being cleaned", file, line, ht);
			break;
		default:
			zend_output_debug_string(1, "%s(%d) : ht=%p is inconsistent", file, line, ht);
			break;
	}
	ZEND_UNREACHABLE();
}
#define IS_CONSISTENT_PHP_70(a) php70_zend_is_inconsistent(a, __FILE__, __LINE__);
#define IS_CONSISTENT_PHP_71_72(a) php71_72_zend_is_inconsistent(a, __FILE__, __LINE__);
#define IS_CONSISTENT_PHP_73(a) php73_zend_is_inconsistent(a, __FILE__, __LINE__);
#define HT_ASSERT_RC1(ht) HT_ASSERT(ht, GC_REFCOUNT(ht) == 1)

// This is an adaptation of zend_hash_set_bucket_key which is only available only starting from PHP 7.4
// to be compatible with PHP 7.0+
zval* ddloader_hash_set_bucket_key(int php_api_no, HashTable *ht, Bucket *b, zend_string *key)
{
	uint32_t nIndex;
	uint32_t idx, i;
	Bucket *p, *arData;

    if (php_api_no == 20151012) { //  PHP 7.0
        IS_CONSISTENT_PHP_70(ht);
    }
    if (php_api_no <= 20170718) {  // PHP  7.1 - 7.2
        HashTable72 *ht72 = ((HashTable72 *)ht);
        IS_CONSISTENT_PHP_71_72(ht72);
    } else {
        IS_CONSISTENT_PHP_73(ht);
    }

	HT_ASSERT_RC1(ht);
	ZEND_ASSERT(!(HT_FLAGS(ht) & HASH_FLAG_PACKED));

	p = ddloader_zend_hash_find_bucket(php_api_no, ht, key);
	if (UNEXPECTED(p)) {
		return (p == b) ? &p->val : NULL;
	}

	if (!ZSTR_IS_INTERNED(key)) {
		zend_string_addref(key);
		HT_FLAGS(ht) &= ~HASH_FLAG_STATIC_KEYS;
	}

	arData = ht->arData;

	/* del from hash */
	idx = HT_IDX_TO_HASH(b - arData);
	nIndex = b->h | ht->nTableMask;
	i = HT_HASH_EX(arData, nIndex);
	if (i == idx) {
		HT_HASH_EX(arData, nIndex) = Z_NEXT(b->val);
	} else {
		p = HT_HASH_TO_BUCKET_EX(arData, i);
		while (Z_NEXT(p->val) != idx) {
			i = Z_NEXT(p->val);
			p = HT_HASH_TO_BUCKET_EX(arData, i);
		}
		Z_NEXT(p->val) = Z_NEXT(b->val);
	}

    if (php_api_no > 20170718) {
        // FIXME: Disabled from PHP 7.0 to 7.2 because it causes a crash
        // zend_mm_heap corrupted
        // Segmentation fault (core dumped)
	    zend_string_release(b->key);
    }

	/* add to hash */
	idx = b - arData;
	b->key = key;
	b->h = ZSTR_H(key);
	nIndex = b->h | ht->nTableMask;
	idx = HT_IDX_TO_HASH(idx);
	i = HT_HASH_EX(arData, nIndex);
	if (i == HT_INVALID_IDX || i < idx) {
		Z_NEXT(b->val) = i;
		HT_HASH_EX(arData, nIndex) = idx;
	} else {
		p = HT_HASH_TO_BUCKET_EX(arData, i);
		while (Z_NEXT(p->val) != HT_INVALID_IDX && Z_NEXT(p->val) > idx) {
			i = Z_NEXT(p->val);
			p = HT_HASH_TO_BUCKET_EX(arData, i);
		}
		Z_NEXT(b->val) = Z_NEXT(p->val);
		Z_NEXT(p->val) = idx;
	}
	return &b->val;
}
