require 'msgpack'

SIZE = 10240

payload = {
  'server.request.body': {
    abc: 'd' * SIZE,
  },
  'server.request.body.filenames': [],
  'server.request.body.files_field_names': [],
  'server.request.body.raw': "abc=#{'d' * SIZE}",
  'server.request.cookies': {
    c: { a: 3 },
    d: ['5', 6],
  },
  'server.request.headers.no_cookies': {
    'user-agent': 'Mozilla/5.0',
    'content-length': 7_777_777,
    'content-type': 'text/plain',
  },
  'server.request.method': 'GET',
  'server.request.path_params': [ 'my', 'uri' ],
  'server.request.query': { key: 'val' },
  'server.request.uri.raw': '/my/uri/?key=val',
}

File.write 'payload.msgpack', payload.to_msgpack

payload = {
  'server.request.body': '',
  'server.request.body.filenames': [],
  'server.request.body.files_field_names': [],
  'server.request.body.raw': '',
  'server.request.cookies': {},
  'server.request.headers.no_cookies': {
    'user-agent': 'Arachni/v1',
  },
  'server.request.method': 'GET',
  'server.request.path_params': [ 'foo' ],
  'server.request.query': {},
  'server.request.uri.raw': '/foo',
}

File.write 'arachni.msgpack', payload.to_msgpack
