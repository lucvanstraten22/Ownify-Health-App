#!/usr/bin/env python3
"""
A stand-in for smtp.gmail.com:587, for tools/mail-service-test.php only.

Speaks just enough SMTP to take a message the way Gmail does on 587: EHLO,
STARTTLS (with the certificate it is given), EHLO again, AUTH PLAIN or LOGIN
against one username and password, MAIL FROM, RCPT TO, DATA, QUIT. Refuses
AUTH before TLS, a wrong login with 535 5.7.8 (Gmail's), and a MAIL FROM
that is not the logged-in account with 555. Each accepted message is written
to OUTDIR as message-<n>.eml, and a line per session to OUTDIR/log.txt.

    fake-smtp.py PORT CERT KEY USER PASSWORD OUTDIR

Python standard library only. Never for a real server.
"""
import base64
import os
import socket
import ssl
import sys
import threading

port, cert, key, user, password, outdir = sys.argv[1], sys.argv[2], sys.argv[3], sys.argv[4], sys.argv[5], sys.argv[6]
counter = {"n": 0}
lock = threading.Lock()


def log(line):
    with lock:
        with open(os.path.join(outdir, "log.txt"), "a") as f:
            f.write(line + "\n")


def session(conn):
    tls = False
    authed = False
    rcpt = []
    sender = None
    f = conn.makefile("rb")

    def send(line):
        conn.sendall((line + "\r\n").encode())

    def read():
        raw = f.readline()
        return raw.decode("utf-8", "replace").rstrip("\r\n") if raw else None

    send("220 fake.smtp ESMTP ready")
    while True:
        line = read()
        if line is None:
            break
        cmd = line.upper()
        if cmd.startswith("EHLO") or cmd.startswith("HELO"):
            if tls:
                send("250-fake.smtp\r\n250-AUTH LOGIN PLAIN\r\n250-8BITMIME\r\n250 SMTPUTF8")
            else:
                send("250-fake.smtp\r\n250-STARTTLS\r\n250 8BITMIME")
        elif cmd == "STARTTLS":
            send("220 2.0.0 Ready to start TLS")
            ctx = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
            ctx.load_cert_chain(cert, key)
            conn = ctx.wrap_socket(conn, server_side=True)
            f = conn.makefile("rb")
            tls = True
            log("tls")
        elif cmd.startswith("AUTH"):
            if not tls:
                send("530 5.7.0 Must issue a STARTTLS command first")
                continue
            parts = line.split(" ")
            if parts[1].upper() == "PLAIN":
                blob = parts[2] if len(parts) > 2 else None
                if blob is None:
                    send("334 ")
                    blob = read()
                fields = base64.b64decode(blob).split(b"\0")
                u, p = fields[1].decode(), fields[2].decode()
            else:
                send("334 VXNlcm5hbWU6")
                u = base64.b64decode(read()).decode()
                send("334 UGFzc3dvcmQ6")
                p = base64.b64decode(read()).decode()
            if u == user and p == password:
                authed = True
                send("235 2.7.0 Accepted")
                log("auth ok")
            else:
                send("535-5.7.8 Username and Password not accepted.\r\n535 5.7.8 BadCredentials")
                log("auth refused")
        elif cmd.startswith("MAIL FROM"):
            if not authed:
                send("530-5.7.0 Authentication Required.")
                continue
            sender = line.split(":", 1)[1].strip().split(" ")[0].strip("<>")
            if sender.lower() != user.lower():
                send("555 5.5.2 Syntax error, the sender is not the account")
                continue
            send("250 2.1.0 OK")
        elif cmd.startswith("RCPT TO"):
            rcpt.append(line.split(":", 1)[1].strip().strip("<>"))
            send("250 2.1.5 OK")
        elif cmd == "DATA":
            send("354 Go ahead")
            body = []
            while True:
                l = f.readline()
                if l in (b".\r\n", b".\n", b""):
                    break
                body.append(l)
            with lock:
                counter["n"] += 1
                n = counter["n"]
            with open(os.path.join(outdir, "message-%d.eml" % n), "wb") as out:
                out.write(b"X-Fake-Envelope-From: " + sender.encode() + b"\r\n")
                out.write(b"X-Fake-Envelope-To: " + ",".join(rcpt).encode() + b"\r\n")
                out.write(b"".join(body))
            send("250 2.0.0 OK queued")
            log("message %d" % n)
            rcpt = []
        elif cmd == "RSET":
            rcpt = []
            send("250 2.1.5 Flushed")
        elif cmd == "NOOP":
            send("250 2.0.0 OK")
        elif cmd == "QUIT":
            send("221 2.0.0 closing connection")
            break
        else:
            send("502 5.5.1 Unrecognized command")
    try:
        conn.close()
    except Exception:
        pass


srv = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
srv.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
srv.bind(("127.0.0.1", int(port)))
srv.listen(8)
open(os.path.join(outdir, "ready"), "w").close()
while True:
    c, _ = srv.accept()
    threading.Thread(target=session, args=(c,), daemon=True).start()
