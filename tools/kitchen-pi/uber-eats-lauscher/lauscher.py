#!/usr/bin/env python3
"""Test listener on the Pi: acts as a network receipt printer for Uber Eats and records what the tablet sends. It prints nothing and sends nothing to mySeat.

- TCP 9100 (raw ESC/POS, what Epson TM-T88IV printers take): every connection is stored as a .bin file, with the time and size in the log. The status queries a
  client sends before printing (DLE EOT n, "10 04 n") are answered with "printer ready", otherwise the client may give up.
- UDP 3289 (Epson search, "EPSON" "Q" ...): answered with a guessed reply (the layout of the payload is not public; this is an experiment). Every query is logged.
- UDP 22222 (Star search, "STR_BCAST"): only logged, not answered.

Start (as user hamun, no root needed):  python3 lauscher.py [directory]     Stop with Ctrl-C or kill; nothing stays installed.
"""
import os, socket, struct, sys, threading, time

OUT = sys.argv[1] if len(sys.argv) > 1 else '/var/tmp/ubereats'
PROXY = os.environ.get('PROXY_TO')  # IP of a real printer to pass port 9100 through to (see proxy_conn), without it the connection is only recorded
os.makedirs(OUT, exist_ok=True)
LOG = open(os.path.join(OUT, 'lauscher.log'), 'a', buffering=1)

def log(*a):
    line = time.strftime('%H:%M:%S ') + ' '.join(str(x) for x in a)
    print(line, flush=True); LOG.write(line + '\n')

def my_addr():
    s = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
    try:
        s.connect(('192.168.178.1', 9)); return s.getsockname()[0]
    finally:
        s.close()

def mac_bytes():
    try:
        return bytes.fromhex(open('/sys/class/net/wlan0/address').read().strip().replace(':', ''))
    except OSError:
        return bytes(6)

ACTIVE = []  # IP addresses of the clients that are connected to port 9100 right now
REAL_MAC = bytes.fromhex('0026ab7ce831')
REAL_IP = bytes.fromhex('c0a8b23d')
REAL_REPLY = {  # key: device type + device number + function of the query; recorded 2026-10-05
    bytes.fromhex('00000010'): bytes.fromhex('4550534f4e710000001000000017010026ab7ce8310014c0a8b23dffffff00c0a8b201801c'),
    bytes.fromhex('03000001'): bytes.fromhex('4550534f4e7103000001000000aa') + REAL_MAC + b'UB-E03' + bytes(26) + bytes.fromhex('05200264') + b'TM-T88V' + bytes(121),
    bytes.fromhex('03000131'): bytes.fromhex('4550534f4e7103000131ffff0000'),
    bytes.fromhex('03000017'): bytes.fromhex('4550534f4e7103') + bytes(2) + bytes.fromhex('17') + bytes(3) + bytes.fromhex('04') + bytes(4),
    bytes.fromhex('03000010'): bytes.fromhex('4550534f4e7103') + bytes(2) + bytes.fromhex('10') + bytes(3) + bytes.fromhex('0d0e14') + bytes(2) + bytes.fromhex('0fffffffffffffffff'),
    bytes.fromhex('03000002'): (bytes.fromhex('4550534f4e7103') + bytes(2) + bytes.fromhex('02') + bytes(3) + bytes.fromhex('ad') + bytes(1)
                                + bytes.fromhex('26ab7ce83155422d453033') + bytes(28) + bytes.fromhex('20') + bytes(2) + bytes.fromhex('0264544d2d54383856') + bytes(121)),
}

_real_mac = []

def relay(query):
    # with PROXY_TO set the search queries go to the real printer as well and its answer is returned with its MAC address and IP address (also the MAC as text in the
    # card name, "UB-E" + last 3 bytes) replaced by those of the Pi; None if the printer does not answer
    try:
        s = socket.socket(socket.AF_INET, socket.SOCK_DGRAM); s.settimeout(1.2)
        if not _real_mac:
            s.sendto(b'EPSONQ' + bytes.fromhex('03000001') + bytes(4), (PROXY, 3289))
            _real_mac.append(s.recvfrom(4096)[0][14:20])
        s.sendto(query, (PROXY, 3289))
        r = s.recvfrom(4096)[0]
    except OSError:
        return None
    mac, pi = _real_mac[0], mac_bytes()
    return (r.replace(mac, pi).replace(mac[3:].hex().upper().encode(), pi[3:].hex().upper().encode())
             .replace(socket.inet_aton(PROXY), socket.inet_aton(my_addr())))

def epson_reply(query):
    # layout as sent by a real TM-m30II (network card UB-E04, 68 bytes, recorded 2026-10-05): "EPSON" "q", 6 zero bytes, length 54 (2 bytes), then the payload:
    # card name "UB-E" + last 3 MAC bytes in hex (10 characters), "ENPC", zeros up to 36 bytes, 03 00 01 02, MAC (6), 00 00 00 01 00 00 00 01
    # the IP address is not in it, the client takes it from the sender of the packet
    mac = mac_bytes()
    kind, func = query[6:8], query[8:10]
    if query[5:6] == b'C':
        # commands (type "C", before every print: 0x0016 clear the connection timer, 0x0015 set it); the real m30II answers them with type "c", recorded 2026-10-06
        r = relay(query) if PROXY else None
        return r or {bytes.fromhex('03000016'): bytes.fromhex('4550534f4e6303000016f0000001ff'),
                     bytes.fromhex('03000015'): bytes.fromhex('4550534f4e63030000150000000100')}.get(kind + func)
    if PROXY and kind + func != bytes.fromhex('03000017'):  # 0x0017 (who is connected) is answered here, the real printer would name the Pi
        r = relay(query)
        if r:
            return r
    if kind == b'\0\0' and func == b'\0\0':
        payload = (b'UB-E' + mac[3:].hex().upper().encode() + b'ENPC').ljust(36, b'\0') + bytes([3, 0, 1, 2]) + mac + bytes([0, 0, 0, 1, 0, 0, 0, 1])
        return b'EPSON' + b'q' + bytes(6) + struct.pack('>H', len(payload)) + payload
    if kind + func == bytes.fromhex('03000017') and ACTIVE:
        # tested on the real printer: while a client is connected to port 9100 this function returns the IP address of that client (otherwise four zero bytes);
        # the app connects, asks this, and drops the connection when it does not find its own address
        return bytes.fromhex('4550534f4e7103000017') + b'\0\0\0\x04' + socket.inet_aton(ACTIVE[-1])
    real = REAL_REPLY.get(kind + func)
    if real:
        # the answers of the real printer (a TM-T88V with the network card UB-E03, 192.168.178.61), with its MAC and IP address replaced by those of the Pi
        return real.replace(REAL_MAC, mac).replace(REAL_IP, socket.inet_aton(my_addr()))
    return b'EPSON' + b'q' + kind + func + b'\xff\xff\0\0'  # every other function: "not supported", like the real printer (tested: 0x0099, 0x0016, 0x0003)

def udp_loop(port, answer):
    s = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
    s.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
    s.setsockopt(socket.SOL_SOCKET, socket.SO_BROADCAST, 1)
    s.bind(('', port))
    seen = {}
    while True:
        data, peer = s.recvfrom(2048)
        n = seen[data] = seen.get(data, 0) + 1
        noisy = n > 3 and n % 1000  # a client that is not satisfied repeats itself thousands of times: log the first three and every thousandth
        if not noisy:
            log('UDP', port, 'from', peer[0], len(data), 'bytes', data[:32].hex(), '(x%d)' % n)
        if answer:
            r = answer(data)
            if r:
                s.sendto(r, peer)
                if not noisy:
                    log('UDP', port, 'answered', peer[0], len(r), 'bytes', r.hex())

def tcp_conn(c, peer):
    c.settimeout(4)
    buf = b''; status_asked = 0
    try:
        while True:
            try:
                d = c.recv(65536)
            except socket.timeout:
                break
            if not d:
                break
            buf += d
            # real-time status queries DLE EOT n: answer one byte each (n=1 printer 0x16, others 0x12 = no error)
            i = 0
            while True:
                i = d.find(b'\x10\x04', i)
                if i < 0 or i + 2 >= len(d):
                    break
                c.sendall(b'\x16' if d[i + 2] == 1 else b'\x12'); status_asked += 1; i += 3
    except OSError as e:
        log('TCP', peer[0], 'error', e)
    finally:
        c.close()
        if peer[0] in ACTIVE:
            ACTIVE.remove(peer[0])
    name =os.path.join(OUT, time.strftime('%Y%m%d-%H%M%S') + '-' + peer[0] + '.bin')
    if buf:
        with open(name, 'wb') as f:
            f.write(buf)
    log('TCP 9100 from', peer[0], len(buf), 'bytes,', status_asked, 'status queries,', name if buf else 'nothing stored')

def proxy_conn(c, peer):
    # PROXY_TO=<IP of a real printer>: everything the tablet sends to port 9100 is passed on to that printer and everything it answers is passed back; both directions are
    # logged (hex), so the answers of a real printer to the app's setup commands and the receipt itself are on record
    try:
        up = socket.create_connection((PROXY, 9100), timeout=5)
    except OSError as e:
        log('PROXY cannot reach the printer', PROXY, e); c.close()
        if peer[0] in ACTIVE:
            ACTIVE.remove(peer[0])
        return
    def pump(src, dst, tag):
        try:
            while True:
                d = src.recv(65536)
                if not d:
                    break
                log('PROXY', tag, len(d), 'bytes', d.hex())
                dst.sendall(d)
        except OSError:
            pass
        finally:
            for x in (src, dst):
                try: x.shutdown(socket.SHUT_RDWR)
                except OSError: pass
    c.settimeout(60); up.settimeout(60)
    t = threading.Thread(target=pump, args=(up, c, 'printer>tablet'), daemon=True); t.start()
    pump(c, up, 'tablet>printer')
    t.join(2); c.close(); up.close()
    if peer[0] in ACTIVE:  # the client is no longer connected: 0x0017 must report "nobody" again (it was left in the list before, and the app then saw the printer as occupied)
        ACTIVE.remove(peer[0])
    log('PROXY connection from', peer[0], 'ended')

def http_conn(c, peer):
    # port 80: the web server of the printer's network card. Like the real one (TM-m30II, 2026-10-06): "/" is redirected to https, any other path is a 404 with a CORS header.
    # The request is logged (request line and headers).
    c.settimeout(5)
    try:
        while True:
            d = c.recv(4096)
            if not d:
                break
            log('HTTP 80 from', peer[0], repr(d[:400]))
            lines = d.decode('latin-1').split('\r\n')
            parts = lines[0].split(' ')
            path = parts[1] if len(parts) > 1 else '/'
            host = next((l.split(':', 1)[1].strip() for l in lines[1:] if l.lower().startswith('host:')), my_addr())
            date = time.strftime('%a, %d %b %Y %H:%M:%S GMT', time.gmtime())
            if path == '/':
                c.sendall(('HTTP/1.1 301 Moved Permanently\r\nLocation: https://%s/\r\nDate: %s\r\nConnection: keep-alive\r\nContent-Length: 0\r\n\r\n' % (host, date)).encode())
            else:
                c.sendall(('HTTP/1.1 404 Not Found\r\nAccess-Control-Allow-Origin: *\r\nDate: %s\r\nConnection: keep-alive\r\nContent-Length: 0\r\n\r\n' % date).encode())
    except socket.timeout:
        pass
    except OSError as e:
        log('HTTP 80', peer[0], 'error', e)
    finally:
        c.close()

def tcp_loop(port):
    s = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
    s.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
    s.bind(('', port)); s.listen(5)
    while True:
        c, peer = s.accept()
        if port == 9100:
            ACTIVE.append(peer[0])
        if port == 80:
            threading.Thread(target=http_conn, args=(c, peer), daemon=True).start()
            continue
        log('TCP', port, 'connection from', peer[0])
        threading.Thread(target=proxy_conn if (PROXY and port == 9100) else tcp_conn, args=(c, peer), daemon=True).start()

def main():
    log('listener started, address', my_addr(), 'output', OUT)
    for target, args in ((udp_loop, (3289, lambda q: epson_reply(q) if q[:5] == b'EPSON' and q[5:6] in (b'Q', b'C') else None)), (udp_loop, (22222, None)), (tcp_loop, (9100,)), (tcp_loop, (8008,)), (tcp_loop, (80,))):
        threading.Thread(target=target, args=args, daemon=True).start()
    while True:
        time.sleep(3600)

if __name__ == '__main__':
    main()
