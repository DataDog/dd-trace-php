// Copyright 2026-Present Datadog, Inc. https://www.datadoghq.com/
// SPDX-License-Identifier: Apache-2.0

use super::super::settings::AgentlessEndpoint;
use base64::Engine;
use flate2::read::GzDecoder;
use http::header::{ACCEPT_ENCODING, CONTENT_ENCODING, CONTENT_LENGTH, ETAG, IF_NONE_MATCH};
use http::{Method, Request};
use http_body_util::BodyExt;
use libdd_common::http_common::{self, Body};
use percent_encoding::percent_decode_str;
use std::future::Future;
use std::io::Read;
use std::pin::Pin;
use std::time::Duration;
use tokio_util::sync::CancellationToken;

pub(super) const MAX_RESPONSE_BODY_BYTES: usize = 10 << 20;
const CLIENT_LANGUAGE: &str = "php";
const INTERNAL_UNTRACED_HEADER: &str = "DD-Internal-Untraced-Request";
const API_KEY_HEADER: &str = "DD-API-KEY";
const AUTHORIZATION_HEADER: &str = "Authorization";

pub(super) type FetchFuture<'a> =
    Pin<Box<dyn Future<Output = Result<PollResponse, TransportFailure>> + Send + 'a>>;

pub(super) trait Transport: Send + Sync {
    fn get<'a>(
        &'a self,
        endpoint: &'a AgentlessEndpoint,
        timeout: Duration,
        etag: Option<&'a str>,
        cancellation: &'a CancellationToken,
    ) -> FetchFuture<'a>;
}

#[derive(Debug, Eq, PartialEq)]
pub(super) enum TransportFailure {
    BuildRequest,
    Cancelled,
    TimedOut,
    Request,
    ResponseBody,
    ResponseTooLarge,
}

pub(super) struct PollResponse {
    pub(super) status: u16,
    pub(super) etag: Option<String>,
    pub(super) content_encoding: Option<String>,
    pub(super) body: Vec<u8>,
}

pub(super) struct HyperTransport {
    client: libdd_common::HttpClient,
}

impl HyperTransport {
    pub(super) fn new() -> Self {
        Self {
            // This native client is outside PHP HTTP instrumentation, so it
            // cannot recursively trace its own polling requests. Hyper also
            // leaves 3xx responses visible instead of following redirects.
            client: http_common::new_client_periodic(),
        }
    }

    async fn get_inner(
        &self,
        endpoint: &AgentlessEndpoint,
        timeout: Duration,
        etag: Option<&str>,
        cancellation: &CancellationToken,
    ) -> Result<PollResponse, TransportFailure> {
        let request = build_request(endpoint, etag)?;
        let operation = async {
            let response = self
                .client
                .request(request)
                .await
                .map_err(|_| TransportFailure::Request)?;
            let status = response.status().as_u16();
            let etag = header_value(response.headers(), ETAG);
            let content_encoding = header_value(response.headers(), CONTENT_ENCODING);

            // A non-200 body can never contain configuration. Dropping it here
            // also prevents a malicious error response from consuming memory.
            if status != 200 {
                return Ok(PollResponse {
                    status,
                    etag,
                    content_encoding,
                    body: Vec::new(),
                });
            }

            if response
                .headers()
                .get(CONTENT_LENGTH)
                .and_then(|value| value.to_str().ok())
                .and_then(|value| value.parse::<u64>().ok())
                .is_some_and(|length| length > MAX_RESPONSE_BODY_BYTES as u64)
            {
                return Err(TransportFailure::ResponseTooLarge);
            }

            let body = collect_bounded(response.into_body(), MAX_RESPONSE_BODY_BYTES).await?;
            Ok(PollResponse {
                status,
                etag,
                content_encoding,
                body,
            })
        };

        tokio::select! {
            _ = cancellation.cancelled() => Err(TransportFailure::Cancelled),
            result = tokio::time::timeout(timeout, operation) => {
                result.map_err(|_| TransportFailure::TimedOut)?
            }
        }
    }
}

impl Transport for HyperTransport {
    fn get<'a>(
        &'a self,
        endpoint: &'a AgentlessEndpoint,
        timeout: Duration,
        etag: Option<&'a str>,
        cancellation: &'a CancellationToken,
    ) -> FetchFuture<'a> {
        Box::pin(self.get_inner(endpoint, timeout, etag, cancellation))
    }
}

fn build_request(
    endpoint: &AgentlessEndpoint,
    etag: Option<&str>,
) -> Result<Request<Body>, TransportFailure> {
    let (request_url, authorization) = request_url_and_authorization(endpoint.as_str())?;
    let mut builder = Request::builder()
        .method(Method::GET)
        .uri(request_url)
        .header(ACCEPT_ENCODING, "gzip")
        .header("DD-Client-Library-Language", CLIENT_LANGUAGE)
        .header(
            "DD-Client-Library-Version",
            include_str!("../../../VERSION").trim(),
        )
        .header(INTERNAL_UNTRACED_HEADER, "1");

    if let Some(etag) = etag {
        builder = builder.header(IF_NONE_MATCH, etag);
    }
    if let Some(api_key) = endpoint.api_key() {
        builder = builder.header(API_KEY_HEADER, api_key);
    }
    if let Some(authorization) = authorization {
        builder = builder.header(AUTHORIZATION_HEADER, authorization);
    }

    builder
        .body(Body::empty())
        .map_err(|_| TransportFailure::BuildRequest)
}

fn request_url_and_authorization(
    endpoint: &str,
) -> Result<(String, Option<String>), TransportFailure> {
    let mut url = url::Url::parse(endpoint).map_err(|_| TransportFailure::BuildRequest)?;
    if url.username().is_empty() {
        return Ok((endpoint.to_owned(), None));
    }

    let mut credentials = percent_decode_str(url.username()).collect::<Vec<_>>();
    credentials.push(b':');
    if let Some(password) = url.password() {
        credentials.extend(percent_decode_str(password));
    }
    let authorization = format!(
        "Basic {}",
        base64::engine::general_purpose::STANDARD.encode(credentials)
    );

    url.set_username("")
        .map_err(|_| TransportFailure::BuildRequest)?;
    url.set_password(None)
        .map_err(|_| TransportFailure::BuildRequest)?;
    Ok((url.to_string(), Some(authorization)))
}

fn header_value(headers: &http::HeaderMap, name: http::header::HeaderName) -> Option<String> {
    headers
        .get(name)
        .and_then(|value| value.to_str().ok())
        .map(str::trim)
        .filter(|value| !value.is_empty())
        .map(str::to_owned)
}

async fn collect_bounded(
    mut body: hyper::body::Incoming,
    limit: usize,
) -> Result<Vec<u8>, TransportFailure> {
    let mut bytes = Vec::new();
    while let Some(frame) = body.frame().await {
        let frame = frame.map_err(|_| TransportFailure::ResponseBody)?;
        if let Some(data) = frame.data_ref() {
            let remaining = limit.saturating_sub(bytes.len());
            if data.len() > remaining {
                return Err(TransportFailure::ResponseTooLarge);
            }
            bytes.extend_from_slice(data);
        }
    }
    Ok(bytes)
}

pub(super) fn decode_response_body(
    body: &[u8],
    content_encoding: Option<&str>,
    limit: usize,
) -> Result<Vec<u8>, TransportFailure> {
    if !content_encoding.is_some_and(|value| value.trim().eq_ignore_ascii_case("gzip")) {
        if body.len() > limit {
            return Err(TransportFailure::ResponseTooLarge);
        }
        return Ok(body.to_vec());
    }

    let decoder = GzDecoder::new(body);
    let mut decoded = Vec::new();
    decoder
        .take(limit as u64 + 1)
        .read_to_end(&mut decoded)
        .map_err(|_| TransportFailure::ResponseBody)?;
    if decoded.len() > limit {
        return Err(TransportFailure::ResponseTooLarge);
    }
    Ok(decoded)
}

#[cfg(test)]
mod tests {
    use super::*;
    use std::io::Write;

    fn gzip(payload: &[u8]) -> Vec<u8> {
        let mut encoder = flate2::write::GzEncoder::new(Vec::new(), flate2::Compression::fast());
        encoder.write_all(payload).expect("compress test payload");
        encoder.finish().expect("finish test payload")
    }

    #[test]
    fn decoded_body_limit_applies_after_gzip() {
        let payload = vec![b'a'; 256];
        let compressed = gzip(&payload);
        assert!(compressed.len() < 64);
        assert_eq!(
            decode_response_body(&compressed, Some(" GZIP "), 64),
            Err(TransportFailure::ResponseTooLarge)
        );
        assert_eq!(
            decode_response_body(&gzip(b"configuration"), Some("gzip"), 64).expect("decode gzip"),
            b"configuration"
        );
    }

    #[test]
    fn request_authentication_is_managed_only() {
        let managed =
            AgentlessEndpoint::build("", "datadoghq.com", "", "secret").expect("managed endpoint");
        let custom =
            AgentlessEndpoint::build("https://user:password@localhost/custom", "", "", "secret")
                .expect("custom endpoint");

        let managed_request = build_request(&managed, Some("etag")).expect("managed request");
        assert_eq!(managed_request.headers()[API_KEY_HEADER], "secret");
        assert_eq!(managed_request.headers()[IF_NONE_MATCH], "etag");
        assert_eq!(managed_request.headers()[INTERNAL_UNTRACED_HEADER], "1");

        let custom_request = build_request(&custom, None).expect("custom request");
        assert!(!custom_request.headers().contains_key(API_KEY_HEADER));
        assert_eq!(
            custom_request.headers()[AUTHORIZATION_HEADER],
            "Basic dXNlcjpwYXNzd29yZA=="
        );
        assert_eq!(custom_request.uri(), "https://localhost/custom");
    }
}
