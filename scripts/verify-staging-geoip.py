#!/usr/bin/env python3
"""Exercise an isolated nginx listener on the staging host using PROXY protocol.

Never enable PROXY protocol or trust test headers on the public listener.
The temporary test configuration must bind only to 127.0.0.1:18443/18080.
"""

import http.client
import socket
import ssl


def request(ip, path, method="GET", headers=None, tls=True):
    port = 18443 if tls else 18080
    family, destination = ("TCP6", "::1") if ":" in ip else ("TCP4", "127.0.0.1")
    with socket.create_connection(("127.0.0.1", port), timeout=10) as raw:
        raw.sendall(f"PROXY {family} {ip} {destination} 12345 {port}\r\n".encode())
        conn = ssl.create_default_context().wrap_socket(
            raw, server_hostname="stg.kamaho-shokusu.jp"
        ) if tls else raw
        with conn:
            lines = [f"{method} {path} HTTP/1.1", "Host: stg.kamaho-shokusu.jp",
                     "Connection: close", "Content-Length: 0"]
            lines.extend(f"{key}: {value}" for key, value in (headers or {}).items())
            conn.sendall(("\r\n".join(lines) + "\r\n\r\n").encode())
            response = http.client.HTTPResponse(conn)
            response.begin()
            body = response.read()
            return response.status, body


def check(label, ip, path, expected, **kwargs):
    status, body = request(ip, path, **kwargs)
    print(f"{label}: expected={expected} actual={status}", flush=True)
    assert status == expected, label
    return body


login = "/kamaho-shokusu/MUserInfo/login"
check("JP login", "133.242.0.1", login, 200)
check("JP root", "133.242.0.1", "/", 302)
check("US blocked", "8.8.8.8", login, 403)
check("CN blocked", "114.114.114.114", login, 403)
check("US root blocked", "8.8.8.8", "/", 403)
check("US dashed login blocked", "8.8.8.8", "/kamaho-shokusu/m-user-info/login", 403)
check("US cannot forge Japanese headers", "8.8.8.8", login, 403,
      headers={"X-Forwarded-For": "133.242.0.1", "X-Real-IP": "133.242.0.1"})
check("JP cannot be blocked by forged US headers", "133.242.0.1", login, 200,
      headers={"X-Forwarded-For": "8.8.8.8", "X-Real-IP": "8.8.8.8"})
check("Unknown country allowed as proposed", "192.0.2.1", login, 200)
check("JP IPv6", "2400:8500::1", login, 200)
check("US IPv6 blocked", "2001:4860:4860::8888", login, 403)
assert check("Overseas liveness", "8.8.8.8", "/healthz", 200) == b"ok\n"
check("Liveness POST rejected", "8.8.8.8", "/healthz", 405, method="POST")
check("Overseas HTTP redirect", "8.8.8.8", "/", 301, tls=False)
assert check("Overseas ACME", "8.8.8.8",
             "/.well-known/acme-challenge/geoip-verification", 200, tls=False) == b"geoip-verification\n"
# Empty POST requests cannot authenticate or change data; CSRF rejects them.
# nginx must still enforce its limit before requests reach the application.
statuses = [request("133.242.0.2", login, method="POST")[0] for _ in range(9)]
print(f"POST rate limit: {statuses}", flush=True)
assert 429 in statuses
check("GET unaffected by POST limit", "133.242.0.2", login, 200)
print("PASS: all staging GeoIP checks")
