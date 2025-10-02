// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.

#include "json_truncated_parser.h"

extern "C" {
#include "logging.h"
#include "php_compat.h"
#include <zend_hash.h>
}
#include <cstdint>
#include <cstdlib>
#include <cstring>
#include <rapidjson/document.h>
#include <rapidjson/error/en.h>
#include <rapidjson/reader.h>
#include <vector>

template <typename T> class PhpAllocator {
public:
    using value_type = T;
    using size_type = std::size_t;
    using difference_type = std::ptrdiff_t;
    using propagate_on_container_move_assignment = std::true_type;

    PhpAllocator() noexcept = default;

    template <typename U>
    explicit PhpAllocator(const PhpAllocator<U> & /* other*/) noexcept
    {}

    auto allocate(size_type n) -> T *
    {
        if (n == 0) {
            return nullptr;
        }
        // NOLINTNEXTLINE(bugprone-sizeof-expression)
        return static_cast<T *>(safe_emalloc(n, sizeof(T), 0));
    }

    void deallocate(T *p, size_type /* n */) noexcept
    {
        if (p != nullptr) {
            efree(p);
        }
    }
};

// Custom allocator for RapidJSON using PHP's emalloc/efree
class RapidJsonPhpAllocator {
public:
    static constexpr bool kNeedFree = true;

    static auto Malloc(size_t size) -> void *
    {
        if (size == 0) {
            return nullptr;
        }
        return emalloc(size);
    }

    static auto Realloc(
        // NOLINTNEXTLINE(bugprone-easily-swappable-parameters)
        void *originalPtr, size_t originalSize, size_t newSize) -> void *
    {
        (void)originalSize;
        if (newSize == 0) {
            if (originalPtr != nullptr) {
                efree(originalPtr);
            }
            return nullptr;
        }
        return erealloc(originalPtr, newSize);
    }

    static void Free(void *ptr)
    {
        if (ptr != nullptr) {
            efree(ptr);
        }
    }
};

// Custom input stream for truncated JSON parsing, based on nginx-datadog
// implementation
class TruncatedJsonInputStream {
public:
    using Ch = char;

    TruncatedJsonInputStream(
        // NOLINTNEXTLINE(bugprone-easily-swappable-parameters)
        const char *data, std::size_t length, std::size_t size_limit = SIZE_MAX)
        : data_{data}, length_{length}, size_limit_{size_limit}
    {}

    [[nodiscard]] auto Peek() const -> Ch
    {
        return (pos_ < length_ && pos_ < size_limit_) ? data_[pos_] : '\0';
    }

    auto Take() -> Ch
    {
        return (pos_ < length_ && pos_ < size_limit_) ? data_[pos_++] : '\0';
    }

    [[nodiscard]] std::size_t Tell() const { return pos_; }

    // required by RapidJSON
    auto PutBegin() -> Ch * { return nullptr; } // NOLINT
    void Put(Ch) {}                             // NOLINT
    void Flush() {}
    auto PutEnd(Ch *) -> size_t { return 0; } // NOLINT

private:
    const char *data_;
    std::size_t length_;
    std::size_t pos_{0};
    std::size_t size_limit_;
};

// Handler to convert JSON events directly to zval, inspired by nginx-datadog's
// ToDdwafObjHandler
class ToZvalHandler {
public:
    ToZvalHandler(const ToZvalHandler &) = delete;
    ToZvalHandler(ToZvalHandler &&) = delete;
    ToZvalHandler &operator=(const ToZvalHandler &) = delete;
    ToZvalHandler &operator=(ToZvalHandler &&) = delete;
    explicit ToZvalHandler(std::size_t max_depth) : max_depth_{max_depth}
    {
        ZVAL_UNDEF(&root_);
    }

    ~ToZvalHandler() { zval_ptr_dtor(&root_); }

    auto Null() -> bool
    {
        zval val;
        ZVAL_NULL(&val);
        return AddValue(val);
    }

    auto Bool(bool b) -> bool
    {
        zval val;
        ZVAL_BOOL(&val, b);
        return AddValue(val);
    }

    auto Int(int i) -> bool
    {
        zval val;
        ZVAL_LONG(&val, i);
        return AddValue(val);
    }

    auto Uint(unsigned u) -> bool
    {
        zval val;
        ZVAL_LONG(&val, static_cast<zend_long>(u));
        return AddValue(val);
    }

    auto Int64(int64_t i) -> bool
    {
        zval val;
        ZVAL_LONG(&val, i);
        return AddValue(val);
    }

    auto Uint64(uint64_t u) -> bool
    {
        zval val;
        if (u > std::numeric_limits<zend_long>::max()) {
            ZVAL_DOUBLE(&val, static_cast<double>(u));
        } else {
            ZVAL_LONG(&val, static_cast<zend_long>(u));
        }
        return AddValue(val);
    }

    auto Double(double d) -> bool
    {
        zval val;
        ZVAL_DOUBLE(&val, d);
        return AddValue(val);
    }

    // NOLINTNEXTLINE(readability-convert-member-functions-to-static)
    auto RawNumber(const TruncatedJsonInputStream::Ch * /* unused */,
        rapidjson::SizeType /* unused */, bool /* unused */) -> bool
    {
        assert("RawNumber should not be called (requires flags we are not "
               "using)" == nullptr);
        return false;
    }

    auto String(const char *str, rapidjson::SizeType length, bool /* copy */)
        -> bool
    {
        zval val;
        ZVAL_STRINGL(&val, str, length);
        return AddValue(val);
    }

    auto StartObject() -> bool
    {
        zval obj;
        array_init(&obj);
        return AddValue(obj);
    }

    auto Key(const char *str, rapidjson::SizeType length, bool /* copy */)
        -> bool
    {
        pending_key_.reset(zend_string_init(str, length, false));
        return true;
    }

    auto EndObject(rapidjson::SizeType /* memberCount */) -> bool
    {
        return end_container();
    }

    auto StartArray() -> bool
    {
        zval arr;
        array_init(&arr);
        return AddValue(arr);
    }

    auto EndArray(rapidjson::SizeType /* elementCount */) -> bool
    {
        return end_container();
    }

    [[nodiscard]] auto HasResult() const -> bool
    {
        return Z_TYPE_P(&root_) != IS_UNDEF;
    }

    auto GetResult() -> zval
    {
        zval result = root_;
        ZVAL_UNDEF(&root_);
        pending_key_.reset();
        value_stack_.clear();
        suppressed_depth_ = 0;
        return result;
    }

private:
    auto AddValue(zval val) -> bool
    {
        if (is_suppressing()) {
            if (Z_TYPE_P(&val) == IS_ARRAY) {
                suppressed_depth_++;
            }
            zval_ptr_dtor(&val);
            return true;
        }

        if (Z_TYPE_P(&root_) == IS_UNDEF) {
            root_ = val;
            if (Z_TYPE(val) == IS_ARRAY) {
                if (stack_depth() == max_depth_) {
                    suppressed_depth_++;
                } else {
                    value_stack_.push_back(&root_);
                }
            }
            return true;
        }

        if (value_stack_.empty()) {
            return false;
        }

        zval *container = value_stack_.back();

        if (Z_TYPE_P(container) == IS_ARRAY) {
            zval *new_val;
            if (pending_key_) {
                new_val = zend_symtable_update(
                    Z_ARRVAL_P(container), pending_key_.get(), &val);
                discard_pending_key();
            } else {
                new_val =
                    zend_hash_next_index_insert(Z_ARRVAL_P(container), &val);
            }

            if (new_val == nullptr) {
                return false;
            }

            if (Z_TYPE_P(new_val) == IS_ARRAY) {
                if (stack_depth() == max_depth_) {
                    suppressed_depth_++;
                } else {
                    value_stack_.push_back(new_val);
                }
            }
        } else {
            return false;
        }

        return true;
    }

    auto end_container() -> bool
    {
        if (is_suppressing()) {
            suppressed_depth_--;
            return true;
        }

        if (value_stack_.empty()) {
            return false;
        }
        value_stack_.pop_back();
        return true;
    }

    [[nodiscard]] auto stack_depth() const -> std::size_t
    {
        return value_stack_.size();
    }

    auto discard_pending_key() -> void { pending_key_.reset(); }

    [[nodiscard]] auto is_suppressing() const -> bool
    {
        return suppressed_depth_ > 0;
    }

    zval root_{};
    std::unique_ptr<zend_string, decltype(&zend_string_release)> pending_key_{
        nullptr, zend_string_release};
    std::size_t max_depth_;
    std::size_t suppressed_depth_{0};
    std::vector<zval *, PhpAllocator<zval *>> value_stack_;
};

extern "C" {

auto dd_parse_json_truncated(
    // NOLINTNEXTLINE(bugprone-easily-swappable-parameters)
    const char *json_data, std::size_t json_len, int max_depth) -> zval
{
    zval result;
    ZVAL_UNDEF(&result);

    if ((json_data == nullptr) || json_len == 0) {
        return result;
    }

    // Use the same parsing flags as nginx-datadog for handling
    // truncated/malformed JSON
    static constexpr unsigned parse_flags =
        rapidjson::kParseStopWhenDoneFlag |
        rapidjson::kParseEscapedApostropheFlag |
        rapidjson::kParseNanAndInfFlag | rapidjson::kParseTrailingCommasFlag |
        rapidjson::kParseCommentsFlag | rapidjson::kParseIterativeFlag;

    TruncatedJsonInputStream stream(json_data, json_len);
    ToZvalHandler handler(max_depth);
    RapidJsonPhpAllocator allocator;
    rapidjson::GenericReader<rapidjson::UTF8<>, rapidjson::UTF8<>,
        RapidJsonPhpAllocator>
        reader(&allocator);

    rapidjson::ParseResult const parse_result =
        reader.Parse<parse_flags>(stream, handler);

    if (parse_result != rapidjson::kParseErrorNone) {
        if (!handler.HasResult()) {
            mlog_g(dd_log_debug, "Error parsing JSON (no data): %s",
                rapidjson::GetParseError_En(parse_result.Code()));
            return result;
        }

        mlog_g(dd_log_debug, "Error parsing JSON (with partial data): %s",
            rapidjson::GetParseError_En(parse_result.Code()));
    } else {
        mlog_g(dd_log_debug, "Successfully parsed JSON (full object)");
    }

    assert(handler.HasResult());

    result = handler.GetResult();
    if (dd_log_level() >= dd_log_trace) {
        zend_string *zv_str = zend_print_zval_r_to_str(&result, 0);
        if (ZSTR_LEN(zv_str) < INT_MAX) {
            mlog_g(dd_log_trace, "JSON result: %.*s", (int)ZSTR_LEN(zv_str),
                ZSTR_VAL(zv_str));
        }
        zend_string_release(zv_str);
    }

    return result;
}

} // extern "C"
