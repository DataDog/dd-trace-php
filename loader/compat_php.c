#include <php.h>
#include <stdbool.h>

#include "compat_php.h"

// Replacement of zend_string_hash_val for compatibility
static zend_ulong ddloader_zend_string_hash_val(zend_string *s) {
	return ZSTR_H(s) ? ZSTR_H(s) : (ZSTR_H(s) = zend_hash_func(ZSTR_VAL(s), ZSTR_LEN(s)));
}

// Replacement of zend_string_equal_content for compatibility
static bool ddloader_zend_string_equal_content(const zend_string *s1, const zend_string *s2) {
    return ZSTR_LEN(s1) == ZSTR_LEN(s2) && !memcmp(ZSTR_VAL(s1), ZSTR_VAL(s2), ZSTR_LEN(s1));
}

static Bucket *php70_71_72_zend_hash_find_bucket(const HashTable *ht, zend_string *key)
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

static Bucket *php73_zend_hash_find_bucket(const HashTable *ht, const zend_string *key)
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
		    ddloader_zend_string_equal_content(p->key, key)) {
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
    // The hash must be known
    (void)ddloader_zend_string_hash_val(key);

    if (php_api_no <= 20170718) {  // PHP 7.0 - 7.2
        return php70_71_72_zend_hash_find_bucket(ht, key);
    }

    return php73_zend_hash_find_bucket(ht, key);
}

#define HT_FLAGS(ht) (ht)->u.flags
#define PHP_70_71_72_IS_STR_INTERNED (1<<1)
#define PHP_70_71_72_ZSTR_IS_INTERNED(s) (GC_FLAGS(s) & PHP_70_71_72_IS_STR_INTERNED)

static bool ddloader_zstr_is_interned(int php_api_no, zend_string *key) {
    if (php_api_no <= 20170718) {  // PHP 7.0 - 7.2
        return PHP_70_71_72_ZSTR_IS_INTERNED(key);
    } else {
        return ZSTR_IS_INTERNED(key);
    }
}

// This is an adaptation of zend_hash_set_bucket_key which is only available only starting from PHP 7.4
// to be compatible with PHP 7.0+
zval* ddloader_zend_hash_set_bucket_key(int php_api_no, HashTable *ht, Bucket *b, zend_string *key) {
	uint32_t nIndex;
	uint32_t idx, i;
	Bucket *p, *arData;

	ZEND_ASSERT(!(HT_FLAGS(ht) & HASH_FLAG_PACKED));

	p = ddloader_zend_hash_find_bucket(php_api_no, ht, key);
	if (UNEXPECTED(p)) {
		return (p == b) ? &p->val : NULL;
	}

	if (!ddloader_zstr_is_interned(php_api_no, key)) {
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
        // Disabled from PHP 7.0 to 7.2 because it causes a crash:
        //      zend_mm_heap corrupted
        //      Segmentation fault (core dumped)
	    // That's because the values of the string flags, like IS_STR_PERSISTENT are differents.
        // Better leak than crash.
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
