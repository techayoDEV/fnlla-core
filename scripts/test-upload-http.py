"""Exercise upload guards through a real Apache/PHP handler on a loopback port."""

import argparse
import getpass
import grp
import os
from pathlib import Path
import shutil
import socket
import subprocess
import tempfile
import time
import urllib.error
import urllib.request


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("public", type=Path, help="Public directory containing the maintained .htaccess")
    parser.add_argument("--apache", default="/usr/sbin/apache2")
    parser.add_argument("--modules", type=Path, default=Path("/usr/lib/apache2/modules"))
    args = parser.parse_args()
    php_modules = sorted(args.modules.glob("libphp*.so"))
    if not php_modules or not (args.public / ".htaccess").is_file():
        parser.error("Apache mod_php and the public .htaccess are required; this gate cannot skip.")
    with tempfile.TemporaryDirectory(prefix="fnlla-upload-http-") as temporary:
        root = Path(temporary)
        root.chmod(0o755)
        public = root / "public"
        uploads = public / "uploads"
        uploads.mkdir(parents=True)
        shutil.copyfile(args.public / ".htaccess", public / ".htaccess")
        payload = '<?php echo "FNLLA_EXECUTED_UPLOAD";'
        blocked = ["legacy.php", "legacy.php.jpg", "legacy.phtml", "legacy.phar", "legacy.PHP5", "active.svg", "active.html", "active.js"]
        for name in blocked:
            (uploads / name).write_text(payload, encoding="utf-8")
        (public / "probe.php").write_text('<?php echo "PHP_HANDLER_OK";', encoding="utf-8")
        (public / "probe.php.jpg").write_text('<?php echo "PHP_HANDLER_OK";', encoding="utf-8")
        (public / "index.php").write_text('<?php http_response_code(404); echo "NOT_FOUND";', encoding="utf-8")
        for name in ["safe.txt", "safe.pdf", "safe.jpg"]:
            (uploads / name).write_text("SAFE_UPLOAD", encoding="utf-8")
        with socket.socket() as reservation:
            reservation.bind(("127.0.0.1", 0))
            port = reservation.getsockname()[1]
        user = "www-data" if os.getuid() == 0 else getpass.getuser()
        group = "www-data" if os.getuid() == 0 else grp.getgrgid(os.getgid()).gr_name
        config = root / "httpd.conf"
        modules = "\n".join(
            f'LoadModule {name}_module "{args.modules / ("mod_" + name + ".so")}"'
            for name in ["mpm_prefork", "authz_core", "mime", "dir", "rewrite"]
        )
        config.write_text(f'''
ServerRoot "{root}"
PidFile "{root / 'apache.pid'}"
ErrorLog "{root / 'error.log'}"
Listen 127.0.0.1:{port}
ServerName 127.0.0.1
{modules}
LoadModule php_module "{php_modules[0]}"
User {user}
Group {group}
TypesConfig /etc/mime.types
DocumentRoot "{public}"
DirectoryIndex index.php
<Directory "{public}">
    AllowOverride All
    Require all granted
    Options -Indexes
</Directory>
<FilesMatch "\\.php[0-9]*(\\.|$)|\\.phtml$|\\.phar$">
    SetHandler application/x-httpd-php
</FilesMatch>
''', encoding="utf-8")
        process = subprocess.Popen([args.apache, "-f", str(config), "-DFOREGROUND"], stdout=subprocess.DEVNULL, stderr=subprocess.PIPE, start_new_session=True)
        opener = urllib.request.build_opener(urllib.request.ProxyHandler({}))

        def request(path):
            try:
                with opener.open(f"http://127.0.0.1:{port}{path}", timeout=2) as response:
                    return response.status, response.read().decode()
            except urllib.error.HTTPError as error:
                return error.code, error.read().decode()

        try:
            deadline = time.monotonic() + 15
            while True:
                try:
                    status, body = request("/probe.php")
                    break
                except (OSError, urllib.error.URLError):
                    if process.poll() is not None or time.monotonic() > deadline:
                        log = (root / "error.log").read_text() if (root / "error.log").exists() else ""
                        raise RuntimeError("Apache failed to start: " + log)
                    time.sleep(0.1)
            if (status, body) != (200, "PHP_HANDLER_OK") or request("/probe.php.jpg") != (200, "PHP_HANDLER_OK"):
                raise RuntimeError("PHP handler fixture is not executing; upload denial would be inconclusive.")
            for name in blocked:
                status, body = request("/uploads/" + name)
                if status != 403 or "FNLLA_EXECUTED_UPLOAD" in body:
                    raise RuntimeError(f"Apache did not deny active upload {name}: {status}")
            for path in ["/uploads/legacy%2ephp.jpg", "/Uploads/legacy.php.jpg", "/uploads/legacy.php/extra"]:
                status, body = request(path)
                if status not in (403, 404) or "FNLLA_EXECUTED_UPLOAD" in body:
                    raise RuntimeError(f"Apache delegated active upload path {path}: {status}")
            for name in ["safe.txt", "safe.pdf", "safe.jpg"]:
                if request("/uploads/" + name) != (200, "SAFE_UPLOAD"):
                    raise RuntimeError(f"Safe upload became inaccessible: {name}")
            print("Apache/PHP upload denial and safe-file serving passed.")
        finally:
            process.terminate()
            try:
                process.communicate(timeout=5)
            except subprocess.TimeoutExpired:
                process.kill()
                process.communicate()


if __name__ == "__main__":
    main()
