// Copyright 2026-Present Datadog, Inc. https://www.datadoghq.com/
// SPDX-License-Identifier: Apache-2.0

use libdd_common::tag::{parse_tags, Tag, TagParser, TagValidationError};
use std::collections::TryReserveError;
use std::hash::{Hash, Hasher};
use std::ops::Range;
use std::sync::Arc;

#[derive(Debug, thiserror::Error)]
pub(crate) enum ProfileTagError {
    #[error("failed to allocate profile tag storage")]
    StorageAllocation(#[from] TryReserveError),

    #[error("profile tag capacity overflow")]
    CapacityOverflow,
    #[error(transparent)]
    Invalid(#[from] TagValidationError),
}

/// An immutable ordered sequence of serialized tags backed by one string.
#[derive(Debug, Default)]
pub(crate) struct ProfileTagSegment {
    storage: String,
    tags: Vec<Range<usize>>,
}

impl PartialEq for ProfileTagSegment {
    fn eq(&self, other: &Self) -> bool {
        // Storage is the exact comma-separated intake representation; ranges
        // are iteration metadata and do not contribute to profile identity.
        self.storage == other.storage
    }
}

impl Eq for ProfileTagSegment {}

impl Hash for ProfileTagSegment {
    fn hash<H: Hasher>(&self, state: &mut H) {
        self.storage.hash(state);
    }
}

impl ProfileTagSegment {
    pub(crate) fn try_from_kv_slice<K, V>(tags: &[(K, V)]) -> Result<Self, ProfileTagError>
    where
        K: AsRef<str>,
        V: AsRef<str>,
    {
        let storage_len = tags.iter().try_fold(0usize, |len, (key, value)| {
            let key = key.as_ref();
            let value = value.as_ref();
            Tag::validate(key, value)?;
            len.checked_add(key.len())
                .and_then(|len| len.checked_add(value.len()))
                .and_then(|len| len.checked_add(":,".len()))
                .ok_or(ProfileTagError::CapacityOverflow)
        })?;

        let mut segment = Self::default();
        segment.try_reserve(tags.len(), storage_len)?;
        for (key, value) in tags {
            segment.push_validated(key.as_ref(), value.as_ref());
        }
        Ok(segment)
    }

    pub(crate) fn try_reserve(
        &mut self,
        additional_tags: usize,
        additional_bytes: usize,
    ) -> Result<(), ProfileTagError> {
        self.tags.try_reserve(additional_tags)?;
        self.storage.try_reserve(additional_bytes)?;
        Ok(())
    }

    pub(crate) fn try_push_kv_tags(
        &mut self,
        tags: &[(String, String)],
    ) -> Result<Option<String>, ProfileTagError> {
        let mut valid_count = 0usize;
        let mut storage_len = 0usize;
        let mut errors = String::new();

        for (key, value) in tags {
            let error = if value.is_empty() {
                TagParser::new(key)
                    .next()
                    .expect("configuration maps cannot contain empty keys")
                    .err()
                    .map(|error| error.to_string())
            } else {
                Tag::validate(key, value)
                    .err()
                    .map(|error| error.to_string())
            };
            if let Some(error) = error {
                errors.try_reserve(error.len().saturating_add(2))?;
                if errors.is_empty() {
                    errors.push_str("Errors while parsing tags: ");
                } else {
                    errors.push_str(", ");
                }
                errors.push_str(&error);
                continue;
            }

            valid_count = valid_count
                .checked_add(1)
                .ok_or(ProfileTagError::CapacityOverflow)?;
            storage_len = storage_len
                .checked_add(key.len())
                .and_then(|len| len.checked_add(value.len()))
                .and_then(|len| len.checked_add(if value.is_empty() { 1 } else { 2 }))
                .ok_or(ProfileTagError::CapacityOverflow)?;
        }

        self.try_reserve(valid_count, storage_len)?;
        for (key, value) in tags {
            let valid = if value.is_empty() {
                TagParser::new(key)
                    .next()
                    .is_some_and(|result| result.is_ok())
            } else {
                Tag::validate(key, value).is_ok()
            };
            if valid {
                self.push_validated(key, value);
            }
        }

        Ok((!errors.is_empty()).then_some(errors))
    }

    fn push_validated(&mut self, key: &str, value: &str) {
        let start = self.storage.len();
        self.storage.push_str(key);
        if !value.is_empty() {
            self.storage.push(':');
            self.storage.push_str(value);
        }
        let end = self.storage.len();
        self.storage.push(',');
        self.tags.push(start..end);
    }

    pub(crate) fn iter(&self) -> impl ExactSizeIterator<Item = &str> {
        self.tags.iter().map(|range| {
            // SAFETY: ranges are private and recorded only at UTF-8 boundaries
            // immediately after appending a complete tag to `storage`.
            unsafe { self.storage.get_unchecked(range.clone()) }
        })
    }

    pub(crate) fn len(&self) -> usize {
        self.tags.len()
    }
}

/// The final Unified Service Tags for one sample, with inline end offsets.
#[derive(Debug, Default)]
pub(crate) struct UnifiedServiceTagSegment {
    storage: String,
    ends: [u32; 3],
    len: u8,
}

impl PartialEq for UnifiedServiceTagSegment {
    fn eq(&self, other: &Self) -> bool {
        // End offsets are iteration metadata for this serialized identity.
        self.storage == other.storage
    }
}

impl Eq for UnifiedServiceTagSegment {}

impl Hash for UnifiedServiceTagSegment {
    fn hash<H: Hasher>(&self, state: &mut H) {
        self.storage.hash(state);
    }
}

impl UnifiedServiceTagSegment {
    pub(crate) fn try_new(
        service: &str,
        env: &str,
        version: &str,
    ) -> Result<Self, ProfileTagError> {
        let mut values = [("", ""); 3];
        let mut len = 0usize;
        let mut storage_len = 0usize;
        for (key, value) in [("service", service), ("env", env), ("version", version)] {
            if value.is_empty() {
                continue;
            }
            Tag::validate(key, value)?;
            storage_len = storage_len
                .checked_add(key.len())
                .and_then(|len| len.checked_add(value.len()))
                .and_then(|len| len.checked_add(":,".len()))
                .ok_or(ProfileTagError::CapacityOverflow)?;
            values[len] = (key, value);
            len += 1;
        }
        if storage_len > u32::MAX as usize {
            return Err(ProfileTagError::CapacityOverflow);
        }

        let mut segment = Self::default();
        segment.storage.try_reserve(storage_len)?;
        for (key, value) in &values[..len] {
            segment.storage.push_str(key);
            segment.storage.push(':');
            segment.storage.push_str(value);
            segment.ends[segment.len as usize] = segment.storage.len() as u32;
            segment.len += 1;
            segment.storage.push(',');
        }
        Ok(segment)
    }

    pub(crate) fn iter(&self) -> impl ExactSizeIterator<Item = &str> {
        (0..self.len as usize).map(|index| {
            let start = if index == 0 {
                0
            } else {
                self.ends[index - 1] as usize + 1
            };
            let end = self.ends[index] as usize;
            // SAFETY: offsets are recorded at UTF-8 boundaries and each
            // preceding end is followed by exactly one comma.
            unsafe { self.storage.get_unchecked(start..end) }
        })
    }

    pub(crate) fn len(&self) -> usize {
        self.len as usize
    }

    #[cfg(any(target_os = "linux", test))]
    pub(crate) fn matches(&self, service: &str, env: &str, version: &str) -> bool {
        let mut expected = [("", ""); 3];
        let mut len = 0usize;
        for (key, value) in [("service", service), ("env", env), ("version", version)] {
            if !value.is_empty() {
                expected[len] = (key, value);
                len += 1;
            }
        }
        if self.len() != len {
            return false;
        }

        self.iter()
            .zip(&expected[..len])
            .all(|(serialized, (key, value))| {
                let key = *key;
                let value = *value;
                serialized.len() == key.len() + 1 + value.len()
                    && serialized.as_bytes().get(key.len()) == Some(&b':')
                    && serialized.starts_with(key)
                    && serialized[key.len() + 1..] == *value
            })
    }
}

/// The complete, immutable profile tags for one sample's profile identity.
#[derive(Debug, Eq, PartialEq, Hash)]
pub struct ProfileTags {
    pub(crate) common: Arc<ProfileTagSegment>,
    pub(crate) unified_service: Arc<UnifiedServiceTagSegment>,
    pub(crate) git: Option<Arc<ProfileTagSegment>>,
    pub(crate) custom: Option<Arc<ProfileTagSegment>>,
}

impl ProfileTags {
    pub(crate) fn iter(&self) -> impl Iterator<Item = &str> {
        self.common
            .iter()
            .chain(self.unified_service.iter())
            .chain(self.git.iter().flat_map(|segment| segment.iter()))
            .chain(self.custom.iter().flat_map(|segment| segment.iter()))
    }

    pub(crate) fn try_materialize(&self) -> anyhow::Result<Vec<Tag>> {
        let capacity = self
            .common
            .len()
            .checked_add(self.unified_service.len())
            .and_then(|len| len.checked_add(self.git.as_ref().map_or(0, |segment| segment.len())))
            .and_then(|len| {
                len.checked_add(self.custom.as_ref().map_or(0, |segment| segment.len()))
            })
            .ok_or(ProfileTagError::CapacityOverflow)?;
        let mut tags = Vec::new();
        tags.try_reserve_exact(capacity)?;

        for serialized in self.iter() {
            if let Some((key, value)) = serialized.split_once(':') {
                tags.push(Tag::new(key, value)?);
            } else {
                let (mut parsed, error) = parse_tags(serialized);
                if let Some(error) = error {
                    anyhow::bail!(error);
                }
                tags.append(&mut parsed);
            }
        }
        Ok(tags)
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn map_tags_are_materialized_as_key_value_tags() {
        let tags = vec![
            ("location".to_owned(), "usa".to_owned()),
            ("region".to_owned(), "west".to_owned()),
            ("standalone".to_owned(), String::new()),
        ];
        let mut segment = ProfileTagSegment::default();
        assert_eq!(segment.try_push_kv_tags(&tags).unwrap(), None);
        assert_eq!(
            segment.iter().collect::<Vec<_>>(),
            ["location:usa", "region:west", "standalone"]
        );
    }

    #[test]
    fn serialized_storage_defines_segment_identity() {
        use std::collections::hash_map::DefaultHasher;

        let one_tag = ProfileTagSegment::try_from_kv_slice(&[("a", "b,c:d")]).unwrap();
        let two_tags = ProfileTagSegment::try_from_kv_slice(&[("a", "b"), ("c", "d")]).unwrap();
        assert_eq!(one_tag.storage, two_tags.storage);
        assert_eq!(one_tag, two_tags);

        let hash = |segment: &ProfileTagSegment| {
            let mut hasher = DefaultHasher::new();
            segment.hash(&mut hasher);
            hasher.finish()
        };
        assert_eq!(hash(&one_tag), hash(&two_tags));
    }

    #[test]
    fn unified_service_tags_use_inline_offsets_and_omit_empty_values() {
        let segment = UnifiedServiceTagSegment::try_new("checkout", "", "1.2.3").unwrap();

        assert_eq!(segment.storage, "service:checkout,version:1.2.3,");
        assert!(segment.matches("checkout", "", "1.2.3"));
        assert!(!segment.matches("checkout", "staging", "1.2.3"));
        assert_eq!(
            segment.iter().collect::<Vec<_>>(),
            ["service:checkout", "version:1.2.3"]
        );
    }

    #[test]
    fn segment_uses_one_comma_separated_string() {
        let segment = ProfileTagSegment::try_from_kv_slice(&[
            ("service", "checkout"),
            ("env", "production"),
            ("standalone", "enabled"),
        ])
        .unwrap();

        assert_eq!(
            segment.storage,
            "service:checkout,env:production,standalone:enabled,"
        );
        assert_eq!(
            segment.iter().collect::<Vec<_>>(),
            ["service:checkout", "env:production", "standalone:enabled"]
        );
    }
}
