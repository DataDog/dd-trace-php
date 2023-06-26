use std::borrow::Cow;
use std::net::{SocketAddr, UdpSocket};

use ddcommon::tag::Tag;
use std::io::ErrorKind;
#[cfg(target_family = "unix")]
use std::{
    os::unix::net::UnixDatagram,
    path::{Path, PathBuf},
};

pub enum ConnectionType {
    UdpSocket {
        socket: UdpSocket,
        send_to: SocketAddr,
    },

    #[cfg(target_family = "unix")]
    UnixDomainSocket {
        datagram: UnixDatagram,
        path: PathBuf,
    },
}

impl ConnectionType {
    pub fn new() -> std::io::Result<Self> {
        #[cfg(target_family = "unix")]
        {
            // https://docs.datadoghq.com/developers/dogstatsd/unix_socket/
            let path = Path::new("/var/run/datadog/dsd.socket");
            if path.exists() {
                return ConnectionType::uds_connect(path.into());
            }
        }

        ConnectionType::udp_connect("0.0.0.0:8125")
    }

    pub fn udp_connect(value: &str) -> std::io::Result<Self> {
        let socket = UdpSocket::bind("0.0.0.0:0")?;
        socket.set_nonblocking(true)?;
        let send_to: SocketAddr = match value.parse() {
            Ok(addr) => addr,
            Err(err) => return Err(std::io::Error::new(ErrorKind::AddrNotAvailable, err)),
        };
        Ok(ConnectionType::UdpSocket { socket, send_to })
    }

    #[cfg(target_family = "unix")]
    pub fn uds_connect(path: PathBuf) -> std::io::Result<Self> {
        let datagram = UnixDatagram::unbound()?;
        Ok(ConnectionType::UnixDomainSocket { datagram, path })
    }
}

pub struct Client {
    pub connection: ConnectionType,

    /// A string to prefix all metrics with, joined with a '.' if it's not empty.
    pub namespace: Cow<'static, str>,

    /// Tags includes with every metric.
    pub default_tags: Vec<Tag>,
}

impl Client {
    pub fn new() -> std::io::Result<Self> {
        Ok(Self {
            connection: ConnectionType::new()?,
            namespace: Cow::Borrowed(""),
            default_tags: vec![],
        })
    }

    fn format<'a>(
        &mut self,
        stat: Cow<str>,
        value: f64,
        metric_type: char,
        tags: impl IntoIterator<Item = &'a Tag>,
    ) -> std::io::Result<Vec<u8>> {
        let mut buffer = Vec::with_capacity("x:y|z".len());

        // Write the prefix, followed by period.
        if !self.namespace.is_empty() {
            buffer.extend_from_slice(self.namespace.as_bytes());
            buffer.push(b'.');
        }

        // Write the core of the metric.
        use std::io::Write;
        write!(buffer, "{stat}:{value}|{metric_type}")?;

        // Handle tags.
        // The identify mapping `|t| t` decouples a lifetime for chaining,
        // and is actually required or it will not compile.
        #[allow(clippy::map_identity)]
        let other_tags = tags.into_iter().map(|t| t);
        let mut tags = self.default_tags.iter().chain(other_tags);
        if let Some(tag) = tags.next() {
            buffer.extend_from_slice(b"|#");
            let tag_str = tag.as_ref();
            buffer.extend_from_slice(tag_str.as_ref());
            tags.fold(&mut buffer, Self::push_tag);
        }

        Ok(buffer)
    }

    pub fn histogram<'a>(
        &mut self,
        stat: Cow<str>,
        value: f64,
        tags: impl IntoIterator<Item = &'a Tag>,
    ) -> std::io::Result<usize> {
        let mut buffer = self.format(stat, value, 'h', tags)?;

        // Append the newline separator.
        buffer.push(b'\n');

        match &self.connection {
            ConnectionType::UdpSocket { socket, send_to } => socket.send_to(&buffer, send_to),
            ConnectionType::UnixDomainSocket { datagram, path } => datagram.send_to(&buffer, &path),
        }
    }

    fn push_tag<'a>(s: &'a mut Vec<u8>, t: &Tag) -> &'a mut Vec<u8> {
        let tag = t.as_ref();
        s.reserve(tag.len() + 1);
        s.push(b',');
        s.extend_from_slice(tag.as_bytes());
        s
    }
}

#[cfg(test)]
mod test {
    use super::*;

    #[test]
    fn histogram_format() {
        let mut client = Client {
            connection: ConnectionType::new().unwrap(),
            namespace: Cow::Borrowed("datadog.profiling"),
            default_tags: vec![
                Tag::new("env", "dev").unwrap(),
                Tag::new("service", "libdatadog-dogstatsd-test").unwrap(),
            ],
        };

        let buffer = client
            .format(
                Cow::Borrowed("stack_walk_ns"),
                2400.0,
                'c',
                [&Tag::new("reason", "alloc").unwrap()],
            )
            .unwrap();

        let metric = String::from_utf8(buffer).unwrap();
        let expect = "datadog.profiling.stack_walk_ns:2400|c|#env:dev,service:libdatadog-dogstatsd-test,reason:alloc";
        assert_eq!(expect, metric)
    }

    #[test]
    fn histogram() {
        let mut client = Client {
            connection: ConnectionType::new().unwrap(),
            namespace: Cow::Borrowed("datadog.profiling"),
            default_tags: vec![
                Tag::new("env", "dev").unwrap(),
                Tag::new("service", "libdatadog-dogstatsd-test").unwrap(),
            ],
        };

        client
            .histogram(
                Cow::Borrowed("stack_walk_ns"),
                2400.0,
                [&Tag::new("reason", "alloc").unwrap()],
            )
            .unwrap();
    }
}
