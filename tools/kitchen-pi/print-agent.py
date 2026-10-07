#!/usr/bin/env python3
"""Bon-Druckdienst fuer die Kueche: fragt mySeat alle 2 Sekunden nach dem naechsten Bon und druckt ihn (ESC/POS) auf dem NCR 7197 (serieller Adapter) oder auf dem Epson TM-m30II (USB, PRINT_TTY=/dev/usb/lp0).

Der Bon geht ueber den Treiber des Rechners (io_edgeport, /dev/ttyUSB0) zum Drucker. Der Treiber bleibt dabei an: frueher wurde er fuer jeden Bon geloest, um den Drucker
direkt per USB anzusprechen (pyusb). Danach startete sich der Drucker nach 5 bis 25 Sekunden selbst neu (neue USB-Nummer im Kernelprotokoll) und schnitt ab, was bis dahin nicht
angekommen war (Test 2026-10-05: Bon mit 25 Positionen brach bei Pos 18 ab), und ohne Treiber fehlte die Flusskontrolle (Lieferung #10: ein Stueck mitten im Bon fehlte).
Ueber den Treiber kam derselbe lange Bon vollstaendig an, und der Drucker blieb am Bus.
"""
import base64, fcntl, json, os, sys, termios, time, urllib.parse, urllib.request

URL, KEY = os.environ['AGENT_URL'], os.environ['AGENT_KEY']
TTY = os.environ.get('PRINT_TTY', '/dev/ttyUSB0')

def log(*a): print(time.strftime('%H:%M:%S'), *a, flush=True)

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
    fd = os.open(TTY, os.O_WRONLY | os.O_NOCTTY | os.O_NONBLOCK)
    try:
        fcntl.fcntl(fd, fcntl.F_SETFL, fcntl.fcntl(fd, fcntl.F_GETFL) & ~os.O_NONBLOCK)
        # a serial port (NCR via io_edgeport): raw, no handshake of our own, and no hang-up when the port is closed (the baud rate is not used by this printer).
        # A printer on the USB printer class (Epson TM-m30II, /dev/usb/lp0) is no terminal: bytes just go in, the driver waits for the printer itself
        tty = os.isatty(fd)
        if tty:
            a = termios.tcgetattr(fd)
            a[0] = 0; a[1] = 0; a[2] = termios.CS8 | termios.CREAD | termios.CLOCAL; a[3] = 0; a[4] = a[5] = termios.B9600
            termios.tcsetattr(fd, termios.TCSANOW, a)
        n = 0
        while n < len(data):
            n += os.write(fd, data[n:])
        if tty:
            termios.tcdrain(fd)  # returns when the driver has sent everything
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
                send(data)
                call('done', id=job['id'])
                log('Bon gedruckt, Bestellung', job['order_id'], 'Auftrag', job['id'], '(%d Byte in %.1f s)' % (len(data), time.time() - t0))
                continue
        except Exception as e:
            log('Fehler:', e)
            time.sleep(5)
            continue
        time.sleep(2)

if __name__ == '__main__':
    main()
