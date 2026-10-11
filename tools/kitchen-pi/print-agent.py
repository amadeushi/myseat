#!/usr/bin/env python3
"""Bon-Druckdienst fuer die Kueche: fragt mySeat alle 2 Sekunden nach dem naechsten Bon und druckt ihn (ESC/POS) auf dem NCR 7197 (serieller Adapter) oder auf dem Epson TM-m30II (USB, PRINT_TTY=/dev/usb/lp0).

Der Bon geht ueber den Treiber des Rechners (io_edgeport, /dev/ttyUSB0) zum Drucker. Der Treiber bleibt dabei an: frueher wurde er fuer jeden Bon geloest, um den Drucker
direkt per USB anzusprechen (pyusb). Danach startete sich der Drucker nach 5 bis 25 Sekunden selbst neu (neue USB-Nummer im Kernelprotokoll) und schnitt ab, was bis dahin nicht
angekommen war (Test 2026-10-05: Bon mit 25 Positionen brach bei Pos 18 ab), und ohne Treiber fehlte die Flusskontrolle (Lieferung #10: ein Stueck mitten im Bon fehlte).
Ueber den Treiber kam derselbe lange Bon vollstaendig an, und der Drucker blieb am Bus.
"""
import base64, fcntl, hashlib, json, os, signal, sys, termios, time, urllib.parse, urllib.request

URL, KEY = os.environ['AGENT_URL'], os.environ['AGENT_KEY']
TTY = os.environ.get('PRINT_TTY', '/dev/ttyUSB0')

def log(*a): print(time.strftime('%H:%M:%S'), *a, flush=True)

KEEP_DIR = os.environ.get('BON_KEEP_DIR', '/var/tmp/bon-agent')   # the last bons as they were sent (to compare with the paper when something is missing)
KEEP_N = 40

def keep(job_id, data):
    try:
        os.makedirs(KEEP_DIR, exist_ok=True)
        with open(os.path.join(KEEP_DIR, 'job-%s.bin' % job_id), 'wb') as f: f.write(data)
        old = sorted((os.path.join(KEEP_DIR, n) for n in os.listdir(KEEP_DIR) if n.endswith('.bin')), key=os.path.getmtime)
        for p in old[:-KEEP_N]: os.remove(p)
    except Exception as e:
        log('Bon konnte nicht gesichert werden:', e)

def _timeout(*a): raise TimeoutError

def status(fd, n):
    """Real-time status of an ESC/POS printer (DLE EOT n): one byte, or None when the printer does not answer in two seconds (the port has to be blocking for the read)."""
    try:
        os.write(fd, bytes([0x10, 0x04, n])); time.sleep(0.15)
        signal.signal(signal.SIGALRM, _timeout); signal.alarm(2)
        try:
            d = os.read(fd, 1)
        finally:
            signal.alarm(0)
        return d[0] if d else None
    except Exception:
        return None

def describe(fd):
    """The state of the printer as a short text: only what is certain is named (offline, paper end), plus the raw status bytes (n=1 printer, n=2 offline cause, n=4 paper sensor) for comparing with a bon that went wrong."""
    st = {n: status(fd, n) for n in (1, 2, 4)}
    if st[1] is None: return 'keine Antwort'
    p = []
    if st[1] & 0x08: p.append('OFFLINE')
    if st[2] is not None and st[2] & 0x20: p.append('PAPIERENDE')
    if st[2] is not None and st[2] & 0x40: p.append('FEHLER')
    if st[4] is not None and st[4] & 0x60: p.append('PAPIERENDE (Sensor)')
    elif st[4] is not None and st[4] & 0x0c: p.append('Papier fast zu Ende')
    raw = ' '.join('s%d=%s' % (n, '--' if v is None else '%02x' % v) for n, v in st.items())
    return (', '.join(p) or 'ok') + ' [' + raw + ']'

def call(op, **fields):
    body = urllib.parse.urlencode(dict(op=op, **fields)).encode()
    req = urllib.request.Request(URL, data=body, headers={'X-Agent-Key': KEY, 'User-Agent': 'bon-agent/1'})
    with urllib.request.urlopen(req, timeout=15) as r:
        return json.loads(r.read().decode())

def write_once(data):
    # after the printer has registered on the USB bus again the device node can take a moment to appear
    for _ in range(40):
        if os.path.exists(TTY): break
        time.sleep(0.25)
    else:
        raise RuntimeError('Drucker nicht gefunden (%s)' % TTY)
    fd = os.open(TTY, os.O_RDWR | os.O_NOCTTY | os.O_NONBLOCK)
    try:
        fcntl.fcntl(fd, fcntl.F_SETFL, fcntl.fcntl(fd, fcntl.F_GETFL) & ~os.O_NONBLOCK)
        # a serial port (NCR via io_edgeport): raw, no handshake of our own, and no hang-up when the port is closed (the baud rate is not used by this printer).
        # A printer on the USB printer class (Epson TM-m30II, /dev/usb/lp0) is no terminal: bytes just go in, the driver waits for the printer itself
        tty = os.isatty(fd)
        if tty:
            a = termios.tcgetattr(fd)
            a[0] = 0; a[1] = 0; a[2] = termios.CS8 | termios.CREAD | termios.CLOCAL; a[3] = 0; a[4] = a[5] = termios.B9600
            termios.tcsetattr(fd, termios.TCSANOW, a)
        if not tty:
            before = describe(fd)
            log('Druckerstatus vor dem Bon:', before)
        n = 0
        while n < len(data):
            n += os.write(fd, data[n:])
        if tty:
            termios.tcdrain(fd)  # returns when the driver has sent everything
        else:
            # the bytes are in the printer; it has finished the bon when it is no longer offline (busy printing and cutting): wait for that, then say how it ended
            t0 = time.time(); after = describe(fd)
            while after.startswith('OFFLINE') and time.time() - t0 < 25:
                time.sleep(0.5); after = describe(fd)
            log('Druckerstatus nach dem Bon:', after, '(%.1f s)' % (time.time() - t0))
    finally:
        os.close(fd)

def send(data):
    last = None
    for attempt in range(3):
        try:
            write_once(data)
            return
        except Exception as e:
            last = e
            log('USB-Fehler, neuer Versuch:', e)
            time.sleep(1)
    raise last

def main():
    log('Druckdienst gestartet')
    while True:
        try:
            r = call('next')
            job = r.get('job') if r.get('ok') else None
            if job:
                data = base64.b64decode(job['data']); t0 = time.time()
                keep(job['id'], data)
                send(data)
                call('done', id=job['id'])
                log('Bon gedruckt, Bestellung', job['order_id'], 'Auftrag', job['id'], '(%d Byte, sha1 %s, in %.1f s)' % (len(data), hashlib.sha1(data).hexdigest()[:8], time.time() - t0))
                continue
        except Exception as e:
            log('Fehler:', e)
            time.sleep(5)
            continue
        time.sleep(2)

if __name__ == '__main__':
    main()
