#include <php.h>
#include <stdbool.h>

#include "compat_php.h"

#define HT_FLAGS(ht) (ht)->u.flags
#define PHP_70_71_72_IS_STR_INTERNED (1<<1)
#define PHP_70_71_72_ZSTR_IS_INTERNED(s) (GC_FLAGS(s) & PHP_70_71_72_IS_STR_INTERNED)

ZEND_API zval* ZEND_FASTCALL zend_hash_set_bucket_key(HashTable *ht, Bucket *b, zend_string *key) __attribute__((weak));

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
    // Use the real implementation if it exists
    if (zend_hash_set_bucket_key) {
        return zend_hash_set_bucket_key(ht, b, key);
    }

    // Fallback for PHP < 7.4
	uint32_t nIndex;
	uint32_t idx, i;
	Bucket *p, *arData;

	ZEND_ASSERT(!(HT_FLAGS(ht) & HASH_FLAG_PACKED));

	p = (Bucket*)zend_hash_find(ht, key);
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
