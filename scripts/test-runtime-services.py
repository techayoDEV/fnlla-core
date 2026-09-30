#!/usr/bin/env python3
"""Run Core service gates against disposable loopback-only MySQL/Redis instances."""
import os
import pathlib
import pwd
import shutil
import socket
import subprocess
import tempfile
import time

ROOT = pathlib.Path(__file__).resolve().parents[1]


def port():
    with socket.socket() as server:
        server.bind(("127.0.0.1", 0))
        return server.getsockname()[1]


def wait_ready(command, process, timeout=40):
    deadline = time.monotonic() + timeout
    while time.monotonic() < deadline:
        if process.poll() is not None:
            raise RuntimeError("Disposable service exited before becoming ready.")
        if subprocess.run(command, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, timeout=3).returncode == 0:
            return
        time.sleep(0.15)
    raise RuntimeError("Disposable service startup timed out.")


def main():
    for binary in ("php", "mysqld", "mysql", "mysqladmin", "redis-server", "redis-cli"):
        if shutil.which(binary) is None:
            raise RuntimeError("Missing test dependency: " + binary)
    with tempfile.TemporaryDirectory(prefix="fnlla-service-gate-") as temporary:
        directory = pathlib.Path(temporary)
        mysql_port, redis_port = port(), port()
        data = directory / "mysql"
        data.mkdir()
        mysql_socket = str(directory / "mysql.sock")
        log = directory / "mysql.log"
        processes = []
        try:
            subprocess.run(["mysqld", "--no-defaults", "--initialize-insecure",
                            "--user=" + pwd.getpwuid(os.geteuid()).pw_name, "--datadir=" + str(data),
                            "--log-error=" + str(log)], check=True, timeout=60)
            mysql = subprocess.Popen(["mysqld", "--no-defaults", "--user=" + pwd.getpwuid(os.geteuid()).pw_name,
                                      "--datadir=" + str(data), "--socket=" + mysql_socket,
                                      "--port=" + str(mysql_port), "--bind-address=127.0.0.1",
                                      "--mysqlx=0", "--log-error=" + str(log),
                                      "--pid-file=" + str(directory / "mysql.pid")],
                                     stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
            processes.append(mysql)
            wait_ready(["mysqladmin", "--no-defaults", "--socket=" + mysql_socket, "-u", "root", "ping"], mysql)
            subprocess.run(["mysql", "--no-defaults", "--socket=" + mysql_socket, "-u", "root", "-e",
                            "CREATE DATABASE fnlla_core_test; CREATE USER 'fnlla_test'@'127.0.0.1' IDENTIFIED BY 'integration-only'; "
                            "GRANT ALL ON fnlla_core_test.* TO 'fnlla_test'@'127.0.0.1';"], check=True, timeout=10)
            redis = subprocess.Popen(["redis-server", "--bind", "127.0.0.1", "--port", str(redis_port),
                                      "--save", "", "--appendonly", "no", "--dir", temporary],
                                     stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
            processes.append(redis)
            wait_ready(["redis-cli", "-p", str(redis_port), "ping"], redis)
            env = dict(os.environ, FNLLA_CORE_TEST_MYSQL_DSN=f"mysql:host=127.0.0.1;port={mysql_port};dbname=fnlla_core_test;charset=utf8mb4",
                       FNLLA_CORE_TEST_MYSQL_USER="fnlla_test", FNLLA_CORE_TEST_MYSQL_PASSWORD="integration-only",
                       FNLLA_CORE_TEST_REDIS_HOST="127.0.0.1", FNLLA_CORE_TEST_REDIS_PORT=str(redis_port))
            subprocess.run(["php", "tests/ServiceIntegrationTest.php"], cwd=ROOT, env=env, check=True, timeout=180)
            subprocess.run(["python3", "scripts/test-upgrade.py"], cwd=ROOT, env=env, check=True, timeout=180)
        except Exception:
            if log.exists():
                print(log.read_text()[-5000:])
            raise
        finally:
            for process in reversed(processes):
                if process.poll() is None:
                    process.terminate()
                    try:
                        process.wait(timeout=10)
                    except subprocess.TimeoutExpired:
                        process.kill()
                        process.wait()
    print("Disposable MySQL/Redis gates passed; services stopped.")


if __name__ == "__main__":
    main()
