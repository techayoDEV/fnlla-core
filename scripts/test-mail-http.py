"""Real loopback HTTP transport regression; no external deliveries or credentials."""
import http.server
import pathlib
import subprocess
import threading

root = pathlib.Path(__file__).resolve().parents[1]
received = []


class Handler(http.server.BaseHTTPRequestHandler):
    def log_message(self, *_args):
        pass

    def do_POST(self):
        body = self.rfile.read(int(self.headers.get('Content-Length', '0')))
        received.append((self.path, self.headers.get('Authorization'), len(body)))
        if self.path.startswith('/redirect/'):
            self.send_response(int(self.path.rsplit('/', 1)[1]))
            self.send_header('Location', 'http://localhost:' + str(self.server.server_port) + '/sink')
        else:
            self.send_response(200 if self.path != '/failure' else 500)
        self.end_headers()
        try:
            self.wfile.write(b'x' * 1048577 if self.path == '/oversize' else b'{}')
        except (BrokenPipeError, ConnectionResetError):
            pass

    do_GET = do_POST


server = http.server.ThreadingHTTPServer(('127.0.0.1', 0), Handler)
thread = threading.Thread(target=server.serve_forever, daemon=True)
thread.start()
try:
    endpoint = 'http://127.0.0.1:' + str(server.server_port)
    for path in ['/success', '/failure', '/oversize'] + ['/redirect/' + str(code) for code in [301, 302, 303, 307, 308]]:
        result = subprocess.run(['php', str(root / 'tests/fixtures/mail-http-hardening.php'), endpoint + path],
                                capture_output=True, text=True, timeout=10)
        assert result.returncode == (0 if path == '/success' else 2), (path, result.stdout, result.stderr)
        assert result.stderr == '', result.stderr
        assert 'SYNTHETIC_TEST_TOKEN' not in result.stdout
        assert len(received) > 0 and received[-1][0] == path, received
    assert len(received) == 8 and all(row[0] != '/sink' for row in received), received
    assert all(row[1] == 'Bearer SYNTHETIC_TEST_TOKEN' and row[2] > 0 for row in received), received
    print('HTTP mail: success, errors, response bound and five redirects passed; no redirected credentials or body.')
finally:
    server.shutdown()
    server.server_close()
    thread.join(timeout=2)
