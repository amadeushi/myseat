#!/usr/bin/env python3
"""
Uber Eats bridge on the Pi in the kitchen. The Pi shows up in the Uber Eats tablet's printer list like the receipt printer of the restaurant (Epson TM-m30II), takes the
print jobs of the tablet, passes them on to the real printer (so the slip in the kitchen is printed as before) and posts every receipt, as an image, to mySeat
(order/uber_import.php). Reading the order out of the image happens in mySeat.

What the tablet does (recorded 2026-10-06 against a TM-m30II, UB-E04 network card):
- search: UDP broadcast "EPSONQ" to port 3289 (Epson ENPC); a printer answers with its card name, MAC and model. The Pi asks the real printer the same question and returns the
  answer with its own MAC and IP address in place of the printer's (relay()).
- connect: POST /epson_eposdevice/getDeviceList.cgi to port 80 (any HTTP answer will do, the real printer sends a 404), then TCP 9100 (raw ESC/POS).
- before every print: "EPSONC" commands 0x0016 and 0x0015 (answered by the printer) and the query 0x0017 "who is connected": the printer answers with the IP address of the client
  that holds a connection to port 9100 (otherwise four zero bytes). The Pi has to report exactly that, and take the client out again when the connection ends; a client that
  stays in the list makes the app think the printer is busy and it does not even try to connect.
- the receipt is a bitmap: ESC/POS "GS 8 L" (store raster graphics) with a zlib-compressed 1-bit image, 512 dots wide, then print, cut and the request "GS ( H 06 00 30 30 <4 characters>"
  (answer from the printer "37 22 <the 4 characters> 00" when it is done). That request is the end of a job.

Without the real printer (switched off, broken, gone) the Pi answers the tablet by itself: the search answers and the dialog on port 9100 were recorded against the TM-m30II
(OFFLINE_UDP, answer_stream) and are played back; the receipts are taken and sent to mySeat exactly the same, only nothing is printed on paper. With PRINTER_MODE=auto (the default)
the Pi looks every 5 seconds whether the printer answers on port 9100 and passes everything on to it as long as it does; PRINTER_MODE=offline never asks it.

Configuration /etc/uber-bridge.conf (not in the project):  IMPORT_URL=...  IMPORT_KEY=...  PRINTER_IP=...  [PRINTER_MODE=auto|offline|relay]  [IFACE=wlan0]  [KEEP_DAYS=14]
Images wait in /var/spool/uber-bridge/pending until mySeat has taken them, then they move to sent/ (deleted after KEEP_DAYS days). The log (journalctl -u uber-bridge) holds sizes
and times only, never what is on a receipt.
"""
import hashlib, os, re, socket, struct, sys, threading, time, urllib.error, urllib.request, zlib

CONF = os.environ.get('BRIDGE_CONF', '/etc/uber-bridge.conf')
SPOOL = os.environ.get('BRIDGE_SPOOL', '/var/spool/uber-bridge')

def read_conf():
    c = {}
    try:
        for line in open(CONF, encoding='utf-8'):
            line = line.strip()
            if line and not line.startswith('#') and '=' in line:
                k, v = line.split('=', 1)
                c[k.strip()] = v.strip().strip('"').strip("'")
    except OSError:
        pass
    return c

C = read_conf()
PRINTER = C.get('PRINTER_IP', '')
MODE = (C.get('PRINTER_MODE', 'auto') or 'auto').lower()
if MODE not in ('auto', 'offline', 'relay'):
    MODE = 'auto'
if not PRINTER:
    MODE = 'offline'
IMPORT_URL = C.get('IMPORT_URL', 'https://app.amds.at/order/uber_import.php')
IMPORT_KEY = C.get('IMPORT_KEY', '')
IFACE = C.get('IFACE', 'wlan0')
KEEP_DAYS = int(C.get('KEEP_DAYS', '14') or 14)

def log(*a):
    print(time.strftime('%H:%M:%S'), *a, flush=True)

# ---------------------------------------------------------------------------------------------------------------- the address of the Pi
def mac_bytes():
    try:
        return bytes.fromhex(open('/sys/class/net/%s/address' % IFACE).read().strip().replace(':', ''))
    except (OSError, ValueError):
        return bytes(6)

def my_addr():
    s = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
    try:
        s.connect((PRINTER or '192.168.178.1', 9)); return s.getsockname()[0]
    finally:
        s.close()

# ---------------------------------------------------------------------------------------------------------------- search and status (UDP 3289)
ACTIVE = []      # IP addresses of the clients that are connected to port 9100 right now
_real_mac = []
_printer_up = [MODE == 'relay']

def printer_up():
    # does the real printer take connections? (looked at by printer_watch() every 5 seconds; PRINTER_MODE=relay: always, offline: never)
    return _printer_up[0] if MODE != 'offline' else False

def printer_watch():
    while MODE == 'auto':
        try:
            socket.create_connection((PRINTER, 9100), timeout=1.5).close(); up = True
        except OSError:
            up = False
        if up != _printer_up[0]:
            _printer_up[0] = up
            log('the printer', PRINTER, 'is reachable again: everything is passed on to it' if up else 'does not answer: the Pi answers the tablet by itself')
        time.sleep(5)

# what the TM-m30II answered to the queries of the tablet (recorded 2026-10-06 through the Pi, so with the MAC b8:27:eb:c0:fd:54 and the IP 192.168.178.112 of the Pi in them: both are
# replaced by the current ones). Key: Q/C (EPSONQ/EPSONC) + device type, device number and function of the query.
REC_MAC = bytes.fromhex('b827ebc0fd54'); REC_IP = bytes.fromhex('c0a8b270')
OFFLINE_UDP = {
    'Q00000000': '4550534f4e71000000000000003655422d45433046443534454e534e0000000000000000000000000000000000000001ffff1c000208b827ebc0fd540000000100000001',
    'Q00000010': '4550534f4e71000000100000001701b827ebc0fd540004c0a8b270ffffff00c0a8b201807c',
    'Q03000001': '4550534f4e7103000001000000aab827ebc0fd5455422d453143000000000000000000000000000000000000000000000000000005010201544d2d6d33304949000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000',
    'Q03000010': '4550534f4e71030000100000000d0e1400000fffffffff39414000',
    'Q03000131': '4550534f4e710300013100000020bf179157bf17bfd7bf17b117bf17bfd7bf179157bf17bfd7bf17b117bf17bfd7',
    'C03000015': '4550534f4e63030000150000000100',
    'C03000016': '4550534f4e63030000160000000100',
}

def offline_reply(query):
    key = query[5:6].decode('latin-1') + query[6:10].hex()
    rec = OFFLINE_UDP.get(key)
    if rec is None:   # a function the printer does not know answers "ffff" (that is what the real ones do)
        return b'EPSONq' + query[6:10] + bytes.fromhex('ffff0000')
    mac, ip = mac_bytes(), socket.inet_aton(my_addr())
    return bytes.fromhex(rec).replace(REC_MAC, mac).replace(REC_MAC[3:].hex().upper().encode(), mac[3:].hex().upper().encode()).replace(REC_IP, ip)

def relay(query):
    # the real printer gets the same query; its answer goes back with MAC address and IP address (also the MAC as text in the card name, "UB-E" + last 3 bytes) of the Pi in them
    try:
        s = socket.socket(socket.AF_INET, socket.SOCK_DGRAM); s.settimeout(1.2)
        if not _real_mac:
            s.sendto(b'EPSONQ' + bytes.fromhex('03000001') + bytes(4), (PRINTER, 3289))
            _real_mac.append(s.recvfrom(4096)[0][14:20])
        s.sendto(query, (PRINTER, 3289))
        r = s.recvfrom(4096)[0]
    except OSError:
        return None
    mac, pi = _real_mac[0], mac_bytes()
    return (r.replace(mac, pi).replace(mac[3:].hex().upper().encode(), pi[3:].hex().upper().encode())
             .replace(socket.inet_aton(PRINTER), socket.inet_aton(my_addr())))

def epson_reply(query):
    kind, func = query[6:8], query[8:10]
    if query[5:6] == b'Q' and kind + func == bytes.fromhex('03000017'):
        # who is connected: the IP address of the client that is connected to port 9100, otherwise four zero bytes
        return bytes.fromhex('4550534f4e7103000017') + b'\0\0\0\x04' + (socket.inet_aton(ACTIVE[-1]) if ACTIVE else bytes(4))
    r = relay(query) if printer_up() else None
    return r if r is not None else offline_reply(query)

def udp_loop():
    s = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
    s.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
    s.bind(('', 3289))
    def answer(q, peer):
        r = epson_reply(q)
        if r:
            s.sendto(r, peer)
    while True:
        data, peer = s.recvfrom(2048)
        if data[:5] == b'EPSON' and data[5:6] in (b'Q', b'C'):
            threading.Thread(target=answer, args=(data, peer), daemon=True).start()

# ---------------------------------------------------------------------------------------------------------------- the web server of the card (port 80)
def http_conn(c, peer):
    # like the real printer: "/" is redirected to https, everything else is a 404 with a CORS header (the app calls POST /epson_eposdevice/getDeviceList.cgi and goes on)
    c.settimeout(5)
    try:
        while True:
            d = c.recv(4096)
            if not d:
                break
            lines = d.decode('latin-1').split('\r\n')
            parts = lines[0].split(' ')
            path = parts[1] if len(parts) > 1 else '/'
            host = next((l.split(':', 1)[1].strip() for l in lines[1:] if l.lower().startswith('host:')), my_addr())
            date = time.strftime('%a, %d %b %Y %H:%M:%S GMT', time.gmtime())
            if path == '/':
                c.sendall(('HTTP/1.1 301 Moved Permanently\r\nLocation: https://%s/\r\nDate: %s\r\nConnection: keep-alive\r\nContent-Length: 0\r\n\r\n' % (host, date)).encode())
            else:
                c.sendall(('HTTP/1.1 404 Not Found\r\nAccess-Control-Allow-Origin: *\r\nDate: %s\r\nConnection: keep-alive\r\nContent-Length: 0\r\n\r\n' % date).encode())
    except OSError:
        pass
    finally:
        c.close()

# ---------------------------------------------------------------------------------------------------------------- receipts out of the print jobs
JOB_END = re.compile(rb'\x1d\(H\x06\x00\x30\x30[\x20-\x7e]{4}')   # GS ( H: "answer when done" with the number of the job; the last thing of every job

def png_1bit(rows, width):
    # rows: packed bits (1 = black, as the printer takes them); written as 1-bit grey (0 = black), so the file is small and the image exact
    def chunk(t, d):
        c = struct.pack('>I', len(d)) + t + d
        return c + struct.pack('>I', zlib.crc32(t + d) & 0xffffffff)
    raw = b''.join(b'\x00' + bytes(b ^ 0xff for b in r) for r in rows)
    return (b'\x89PNG\r\n\x1a\n' + chunk(b'IHDR', struct.pack('>IIBBBBB', width, len(rows), 1, 0, 0, 0, 0))
            + chunk(b'IDAT', zlib.compress(raw, 9)) + chunk(b'IEND', b''))

def job_image(job):
    # all the "GS 8 L" graphics blocks of one job, one below the other; None if there is no image in it
    blocks = []; i = 0
    while True:
        i = job.find(b'\x1d8L', i)
        if i < 0 or i + 17 > len(job):
            break
        p = int.from_bytes(job[i + 3:i + 7], 'little')
        m, fn, a, bx, by, c, xl, xh, yl, yh = job[i + 7:i + 17]
        w, h = xl | xh << 8, yl | yh << 8
        body = job[i + 17:i + 7 + p]
        if fn == 0x70 and w and h and w % 8 == 0:
            try:
                raw = zlib.decompress(body) if body[:1] == b'\x78' else body
            except zlib.error:
                raw = b''
            bpr = w // 8
            if len(raw) >= bpr * h:
                blocks.append((w, [raw[y * bpr:(y + 1) * bpr] for y in range(h)]))
        i += 3
    if not blocks or len({w for w, _ in blocks}) != 1:
        return None
    return png_1bit([r for _, rows in blocks for r in rows], blocks[0][0])

class JobSplitter:
    # collects what the tablet sends on one connection and calls on_job(job bytes) whenever a job is complete
    def __init__(self, on_job):
        self.buf = b''; self.on_job = on_job
    def feed(self, data):
        self.buf += data
        while True:
            m = JOB_END.search(self.buf)
            if not m:
                break
            job, self.buf = self.buf[:m.end()], self.buf[m.end():]
            self.on_job(job)
        if len(self.buf) > 4 * 1024 * 1024:   # something that is not a job: do not grow without end
            self.buf = b''

def save_image(png):
    os.makedirs(os.path.join(SPOOL, 'pending'), exist_ok=True)
    name = '%s-%s.png' % (time.strftime('%Y%m%d-%H%M%S'), hashlib.sha1(png).hexdigest()[:8])
    tmp = os.path.join(SPOOL, 'pending', name + '.part')
    with open(tmp, 'wb') as f:
        f.write(png)
    os.chmod(tmp, 0o640)
    os.replace(tmp, os.path.join(SPOOL, 'pending', name))
    return name

def on_job(job):
    png = job_image(job)
    if png is None:
        # not a receipt image (a text job would not be one): kept for a look, not sent
        os.makedirs(os.path.join(SPOOL, 'other'), exist_ok=True)
        name = time.strftime('%Y%m%d-%H%M%S') + '-job.bin'
        with open(os.path.join(SPOOL, 'other', name), 'wb') as f:
            f.write(job)
        log('job without image,', len(job), 'bytes kept as other/' + name)
        return
    name = save_image(png)
    log('receipt', name, len(png), 'bytes (job %d bytes)' % len(job))

# ---------------------------------------------------------------------------------------------------------------- port 9100 without the printer: the recorded dialog
ASB = bytes.fromhex('1400000f')       # automatic status (GS a 255 switches it on): printer ready, paper there, cover closed
INIT_BLOCK = bytes.fromhex('101407051b3d011d61ff1b3d011d2845020006031d496e1b3d011d28450200060b1b3d011d2848020040011b3d011d2842070061312c332d46371b3d011d4901')
INIT_REPLY = ASB + bytes.fromhex('3727331f36003d6e00372731311f300001')   # the answers of the TM-m30II to the queries in INIT_BLOCK
STEPS = (   # what the tablet sends outside of a print job -> what the printer answers
    (bytes.fromhex('100401'), b'\x16'),
    (bytes.fromhex('10041201'), b'\x16'),
    (INIT_BLOCK, INIT_REPLY),
    (bytes.fromhex('10140801031401060208'), bytes.fromhex('372500')),
    (bytes.fromhex('1b3d011d61ff'), ASB),
    (bytes.fromhex('101406040001031401060208'), bytes.fromhex('375c3000')),
)
JOB_START = bytes.fromhex('1b3d011b401d61ff')   # a print job starts with: reset, ASB on (answered with the status)
FEED = re.compile(rb'\x1b\x3d\x01\x1b\x4a.\x0c', re.S)  # "feed n dots" and form feed after the job (7 bytes): no answer

class Emulator:
    # one connection to port 9100: feed() takes what the tablet sent and returns what the printer would answer. Outside of a job the known steps (STEPS) are answered one by one;
    # a job is collected up to the end marker "GS ( H" (also when it is cut in two packets) and handed to on_job; the printer answers "37 22 <the 4 characters> 00" when it has printed
    # the job (here: when it has arrived).
    def __init__(self):
        self.buf = b''; self.job = b''; self.in_job = False

    def feed(self, data):
        out = b''; self.buf += data
        while self.buf:
            if self.in_job:
                self.job += self.buf; self.buf = b''
                m = JOB_END.search(self.job)
                if not m:
                    if len(self.job) > 4 * 1024 * 1024:     # not a job: do not grow without end
                        self.job = b''; self.in_job = False
                    break
                job, self.buf = self.job[:m.end()], self.job[m.end():]
                self.job = b''; self.in_job = False
                try:
                    on_job(job)
                except Exception as e:                       # never let the reading of a receipt disturb the dialog
                    log('error while reading a job:', e)
                out += b'\x37\x22' + job[-4:] + b'\x00'
                continue
            for req, rep_ in STEPS:
                if self.buf.startswith(req):
                    out += rep_; self.buf = self.buf[len(req):]; break
            else:
                if self.buf.startswith(JOB_START):
                    out += ASB; self.in_job = True              # the job, from its first byte on, is collected in self.job
                    continue
                m = FEED.match(self.buf)
                if m:
                    self.buf = self.buf[m.end():]; continue
                if any(r.startswith(self.buf) for r, _ in STEPS) or JOB_START.startswith(self.buf) or (len(self.buf) < 8 and self.buf[:1] == b'\x1b'):
                    break                                          # the beginning of something known: wait for the rest
                log('unknown bytes from the tablet, skipped:', self.buf[:16].hex()); self.buf = self.buf[1:]
        return out

def emulate_conn(c, peer):
    ACTIVE.append(peer[0])
    t0 = time.time(); nbytes = 0; emu = Emulator()
    try:
        c.settimeout(120)
        while True:
            d = c.recv(65536)
            if not d:
                break
            nbytes += len(d)
            out = emu.feed(d)
            if out:
                c.sendall(out)
    except OSError:
        pass
    finally:
        try: c.close()
        except OSError: pass
        if peer[0] in ACTIVE:       # the client is not connected any more: 0x0017 has to say so
            ACTIVE.remove(peer[0])
        log('connection from', peer[0], '(without the printer) ended after %.0f s, %d bytes from the tablet' % (time.time() - t0, nbytes))

# ---------------------------------------------------------------------------------------------------------------- the connection to port 9100: passed through to the real printer
def proxy_conn(c, peer):
    if not printer_up():
        return emulate_conn(c, peer)
    ACTIVE.append(peer[0])
    t0 = time.time(); nbytes = [0]
    try:
        try:
            up = socket.create_connection((PRINTER, 9100), timeout=5)
        except OSError as e:
            log('the printer', PRINTER, 'cannot be reached:', e); ACTIVE.remove(peer[0]); return emulate_conn(c, peer)
        splitter = JobSplitter(on_job)
        def pump(src, dst, split):
            try:
                while True:
                    d = src.recv(65536)
                    if not d:
                        break
                    dst.sendall(d)      # first on to its destination, only then looked at
                    if split:
                        nbytes[0] += len(d)
                        try:
                            splitter.feed(d)
                        except Exception as e:   # never let the reading of a receipt disturb the printing
                            log('error while reading a job:', e)
            except OSError:
                pass
            finally:
                for x in (src, dst):
                    try: x.shutdown(socket.SHUT_RDWR)
                    except OSError: pass
        c.settimeout(120); up.settimeout(120)
        t = threading.Thread(target=pump, args=(up, c, False), daemon=True); t.start()
        pump(c, up, True)
        t.join(2); c.close(); up.close()
        log('connection from', peer[0], 'ended after %.0f s, %d bytes from the tablet' % (time.time() - t0, nbytes[0]))
    finally:
        if peer[0] in ACTIVE:       # the client is not connected any more: 0x0017 has to say so (see above)
            ACTIVE.remove(peer[0])

def tcp_loop(port, handler):
    s = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
    s.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
    s.bind(('', port)); s.listen(8)
    while True:
        c, peer = s.accept()
        threading.Thread(target=handler, args=(c, peer), daemon=True).start()

# ---------------------------------------------------------------------------------------------------------------- the way to mySeat
def upload(path):
    with open(path, 'rb') as f:
        data = f.read()
    req = urllib.request.Request(IMPORT_URL, data=data, method='POST', headers={'X-Api-Key': IMPORT_KEY, 'Content-Type': 'image/png'})
    try:
        with urllib.request.urlopen(req, timeout=30) as r:
            return 'ok', r.read(200).decode('utf-8', 'replace')
    except urllib.error.HTTPError as e:
        body = e.read(200).decode('utf-8', 'replace')
        return ('retry' if e.code >= 500 else 'rejected'), '%d %s' % (e.code, body)
    except (urllib.error.URLError, OSError) as e:
        return 'retry', str(e)

def move(path, folder):
    os.makedirs(os.path.join(SPOOL, folder), exist_ok=True)
    os.replace(path, os.path.join(SPOOL, folder, os.path.basename(path)))

def sender_loop():
    last_clean = 0; failed = {}; warned = False
    while True:
        pend = os.path.join(SPOOL, 'pending')
        try:
            names = sorted(n for n in os.listdir(pend) if n.endswith('.png'))
        except OSError:
            names = []
        for n in names:
            p = os.path.join(pend, n)
            if not IMPORT_KEY:
                if not warned:
                    log('no IMPORT_KEY in', CONF, '- receipts wait in pending/'); warned = True
                break
            state, msg = upload(p)
            if state == 'ok':
                move(p, 'sent'); failed.pop(n, None); log('receipt', n, 'sent to mySeat:', msg[:80])
            elif state == 'rejected':
                move(p, 'rejected'); log('receipt', n, 'refused by mySeat, moved to rejected/:', msg[:120])
            else:
                if failed.get(n) != msg:
                    log('receipt', n, 'not sent, will try again:', msg[:120]); failed[n] = msg
                break                  # mySeat is not reachable: try the rest later
        if time.time() - last_clean > 3600:
            last_clean = time.time()
            for folder in ('sent', 'rejected', 'other'):
                d = os.path.join(SPOOL, folder)
                try:
                    for n in os.listdir(d):
                        if os.path.getmtime(os.path.join(d, n)) < time.time() - KEEP_DAYS * 86400:
                            os.remove(os.path.join(d, n))
                except OSError:
                    pass
        time.sleep(5)

def main():
    log('uber bridge started: the Pi', my_addr(), 'MAC', mac_bytes().hex(), ('mode ' + MODE + ', printer ' + PRINTER) if PRINTER else 'mode offline (no PRINTER_IP)')
    if MODE == 'auto':
        try:
            socket.create_connection((PRINTER, 9100), timeout=1.5).close(); _printer_up[0] = True
        except OSError:
            _printer_up[0] = False
        log('the printer', PRINTER, 'answers: passed on' if _printer_up[0] else 'does not answer: the Pi answers the tablet by itself')
    for target, args in ((udp_loop, ()), (tcp_loop, (80, http_conn)), (tcp_loop, (9100, proxy_conn)), (sender_loop, ()), (printer_watch, ())):
        threading.Thread(target=target, args=args, daemon=True).start()
    while True:
        time.sleep(3600)

if __name__ == '__main__':
    main()
