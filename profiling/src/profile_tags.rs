// Copyright 2026-Present Datadog, Inc. https://www.datadoghq.com/
// SPDX-License-Identifier: Apache-2.0

use libdd_common::tag::{parse_tags, Tag, TagParser, TagValidationError};
use std::collections::TryReserveError;
use std::fmt::Write;
use std::ops::Range;
use std::sync::{Arc, LazyLock};

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
#[derive(Debug, Default, Eq, PartialEq, Hash)]
pub(crate) struct ProfileTagSegment {
    storage: String,
    tags: Vec<Range<usize>>,
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

    pub(crate) fn try_unified_service(
        service: &str,
        env: &str,
        version: &str,
    ) -> Result<Self, ProfileTagError> {
        let mut tags = [("", ""); 3];
        let mut len = 0;
        for tag in [("service", service), ("env", env), ("version", version)] {
            if !tag.1.is_empty() {
                tags[len] = tag;
                len += 1;
            }
        }
        Self::try_from_kv_slice(&tags[..len])
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

    pub(crate) fn try_push_tags(&mut self, input: &str) -> Result<Option<String>, ProfileTagError> {
        let (tag_count, storage_len) = TagParser::new(input).try_fold(
            (0usize, 0usize),
            |(count, len), result| match result {
                Ok(tag) => Ok::<_, ProfileTagError>((
                    count
                        .checked_add(1)
                        .ok_or(ProfileTagError::CapacityOverflow)?,
                    len.checked_add(tag.len())
                        .and_then(|len| len.checked_add(1))
                        .ok_or(ProfileTagError::CapacityOverflow)?,
                )),
                Err(_) => Ok((count, len)),
            },
        )?;
        self.try_reserve(tag_count, storage_len)?;

        let mut errors = String::new();
        for result in TagParser::new(input) {
            match result {
                Ok(tag) => self.push_serialized_validated(tag),
                Err(error) => {
                    let additional = error
                        .value
                        .len()
                        .checked_add(64)
                        .ok_or(ProfileTagError::CapacityOverflow)?;
                    errors.try_reserve(additional)?;
                    if errors.is_empty() {
                        errors.push_str("Errors while parsing tags: ");
                    } else {
                        errors.push_str(", ");
                    }
                    // Writing to String only fails if its formatter does, which it does not.
                    let _ = write!(errors, "{error}");
                }
            }
        }

        Ok((!errors.is_empty()).then_some(errors))
    }

    fn push_validated(&mut self, key: &str, value: &str) {
        let start = self.storage.len();
        self.storage.push_str(key);
        self.storage.push(':');
        self.storage.push_str(value);
        let end = self.storage.len();
        self.storage.push(',');
        self.tags.push(start..end);
    }

    fn push_serialized_validated(&mut self, tag: &str) {
        let start = self.storage.len();
        self.storage.push_str(tag);
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

pub(crate) fn empty_profile_tag_segment() -> &'static Arc<ProfileTagSegment> {
    static EMPTY: LazyLock<Arc<ProfileTagSegment>> =
        LazyLock::new(|| Arc::new(ProfileTagSegment::default()));
    &EMPTY
}

/// The complete, immutable profile tags for one sample's profile identity.
#[derive(Debug, Eq, PartialEq, Hash)]
pub struct ProfileTags {
    pub(crate) common: Arc<ProfileTagSegment>,
    pub(crate) unified_service: ProfileTagSegment,
    pub(crate) git: Arc<ProfileTagSegment>,
    pub(crate) custom: Arc<ProfileTagSegment>,
}

impl ProfileTags {
    pub(crate) fn iter(&self) -> impl Iterator<Item = &str> {
        self.common
            .iter()
            .chain(self.unified_service.iter())
            .chain(self.git.iter())
            .chain(self.custom.iter())
    }

    pub(crate) fn try_materialize(&self) -> anyhow::Result<Vec<Tag>> {
        let capacity = self
            .common
            .len()
            .checked_add(self.unified_service.len())
            .and_then(|len| len.checked_add(self.git.len()))
            .and_then(|len| len.checked_add(self.custom.len()))
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
    fn segment_uses_one_comma_separated_string() {
        let mut segment =
            ProfileTagSegment::try_from_kv_slice(&[("service", "checkout"), ("env", "production")])
                .unwrap();
        segment.try_push_tags("standalone").unwrap();

        assert_eq!(
            segment.storage,
            "service:checkout,env:production,standalone,"
        );
        assert_eq!(
            segment.iter().collect::<Vec<_>>(),
            ["service:checkout", "env:production", "standalone"]
        );
    }
}
