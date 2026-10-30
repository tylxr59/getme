"""Exercise the actual entry point, not just service methods."""
import json
import os
from pathlib import Path
import socket
import subprocess
import tempfile
import time
import urllib.request
import urllib.error

root = Path(__file__).resolve().parents[1]
with tempfile.TemporaryDirectory(prefix='getme-http-') as directory:
    with socket.socket() as sock:
        sock.bind(('127.0.0.1', 0))
        port = sock.getsockname()[1]
    url = f'http://127.0.0.1:{port}/'
    env = dict(os.environ, GETME_DATABASE=directory + '/test.db')
    with open(directory + '/server.log', 'w') as log:
        server = subprocess.Popen(['php', '-S', f'127.0.0.1:{port}', '-t', str(root)], env=env, stdout=log, stderr=log)
        try:
            for attempt in range(100):
                try:
                    with urllib.request.urlopen(url) as response:
                        page = response.read().decode()
                    break
                except urllib.error.URLError:
                    time.sleep(0.02)
            else:
                raise RuntimeError('PHP server did not start')
            assert 'web/api-client.js' in page and 'web/list-state.js' in page
            def request(body, method='POST'):
                data = body.encode() if isinstance(body, str) else json.dumps(body).encode()
                req = urllib.request.Request(url, data=data, method=method, headers={'Content-Type': 'application/json'})
                try:
                    with urllib.request.urlopen(req) as response:
                        return response.status, response.read(), response.headers
                except urllib.error.HTTPError as error:
                    return error.code, error.read(), error.headers
            status, body, headers = request({'version': 1, 'action': 'read'})
            assert status == 200 and json.loads(body)['revision'] == 0
            assert headers['Cache-Control'] == 'no-store'
            assert request('{broken')[0] == 400
            assert request({'version': 2, 'action': 'read'})[0] == 400
            assert request({}, 'OPTIONS')[0] == 204
            status, body, _ = request({'version': 1, 'action': 'apply', 'revision': 0, 'operations': [{'id': 'http-1', 'type': 'add', 'name': '<script>Milk</script>'}]})
            assert status == 200 and json.loads(body)['revision'] == 1
            assert request({'version': 1, 'action': 'apply', 'revision': 0, 'operations': [{'id': 'http-2', 'type': 'add', 'name': 'Stale'}]})[0] == 409
            status, body, _ = request({'action': 'fetch'})
            assert status == 200 and len(json.loads(body)['items']) == 1
            with urllib.request.urlopen(url) as response:
                page = response.read().decode()
            assert '&lt;script&gt;Milk&lt;/script&gt;' in page
            assert '\\u003Cscript\\u003E' in page
            print('HTTP integration tests passed')
        finally:
            server.terminate()
            server.wait(timeout=5)
