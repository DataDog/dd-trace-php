#include <php.h>
#include <stdbool.h>

#include "compat_php.h"

#define HT_FLAGS(ht) (ht)->u.flags
#define PHP_70_71_72_IS_STR_PERSISTENT (1<<0)
#define PHP_70_71_72_IS_STR_INTERNED (1<<1)
#define PHP_70_71_72_ZSTR_IS_INTERNED(s) (GC_FLAGS(s) & PHP_70_71_72_IS_STR_INTERNED)

ZEND_API zval* ZEND_FASTCALL zend_hash_set_bucket_key(HashTable *ht, Bucket *b, zend_string *key) __attribute__((weak));

static bool ddloader_zstr_is_interned(int php_api_no, zend_string *key) {
    if (php_api_no <= 20170718) {  // PHP 7.0 - 7.2
        return PHP_70_71_72_ZSTR_IS_INTERNED(key);
    }

    return ZSTR_IS_INTERNED(key);
}

static zend_string *php70_71_72_zend_string_alloc(size_t len, int persistent)
{
	zend_string *ret = (zend_string *)pemalloc(ZEND_MM_ALIGNED_SIZE(_ZSTR_STRUCT_SIZE(len)), persistent);

	GC_SET_REFCOUNT(ret, 1);
	GC_TYPE_INFO(ret) = IS_STRING | ((persistent ? PHP_70_71_72_IS_STR_PERSISTENT : 0) << 8);
	zend_string_forget_hash_val(ret);
	ZSTR_LEN(ret) = len;

	return ret;
}

zend_string *ddloader_zend_string_init(int php_api_no, const char *str, size_t len, bool persistent) {
    if (php_api_no <= 20170718) {  // PHP 7.0 - 7.2
        zend_string *ret = php70_71_72_zend_string_alloc(len, persistent);
        memcpy(ZSTR_VAL(ret), str, len);
        ZSTR_VAL(ret)[len] = '\0';

        return ret;
    }

    return zend_string_init(str, len, persistent);
}

void ddloader_zend_string_release(int php_api_no, zend_string *s) {
    if (php_api_no <= 20170718) {  // PHP 7.0 - 7.2
        if (!PHP_70_71_72_ZSTR_IS_INTERNED(s)) {
            if (GC_DELREF(s) == 0)  {
                pefree(s, GC_FLAGS(s) & (PHP_70_71_72_IS_STR_PERSISTENT << 8));
            }
	    }

        return;
    }

    zend_string_release(s);
}

// This is an adaptation of zend_hash_set_bucket_key which is only available only starting from PHP 7.4
// to be compatible with PHP 7.0+
zval *ddloader_zend_hash_set_bucket_key(int php_api_no, HashTable *ht, Bucket *b, zend_string *key) {
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
		GC_ADDREF(key);
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
    ddloader_zend_string_release(php_api_no, b->key);

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
