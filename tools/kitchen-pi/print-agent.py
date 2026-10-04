#!/usr/bin/env python3
"""Bon-Druckdienst fuer die Kueche: fragt mySeat alle 2 Sekunden nach dem naechsten Bon und druckt ihn per USB (ESC/POS) auf dem NCR 7197."""
import base64, json, os, sys, time, urllib.parse, urllib.request
import usb.core, usb.util

URL, KEY = os.environ['AGENT_URL'], os.environ['AGENT_KEY']
VID, PID = 0x0404, 0x0312

def log(*a): print(time.strftime('%H:%M:%S'), *a, flush=True)

def call(op, **fields):
    body = urllib.parse.urlencode(dict(op=op, **fields)).encode()
    req = urllib.request.Request(URL, data=body, headers={'X-Agent-Key': KEY, 'User-Agent': 'bon-agent/1'})
    with urllib.request.urlopen(req, timeout=15) as r:
        return json.loads(r.read().decode())

def write_once(data):
    # the printer is looked up for every slip: it may have registered again on the USB bus since the last one
    d = usb.core.find(idVendor=VID, idProduct=PID)
    if d is None:
        raise RuntimeError('Drucker nicht gefunden (USB)')
    try:
        if d.is_kernel_driver_active(0): d.detach_kernel_driver(0)
    except Exception: pass
    try: d.get_active_configuration()
    except Exception: d.set_configuration()
    intf = d.get_active_configuration()[(0, 0)]
    ep = usb.util.find_descriptor(intf, custom_match=lambda e: usb.util.endpoint_direction(e.bEndpointAddress) == usb.util.ENDPOINT_OUT)
    try:
        for i in range(0, len(data), 4096):
            ep.write(data[i:i + 4096], timeout=10000)
    finally:
        usb.util.dispose_resources(d)

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
                send(base64.b64decode(job['data']))
                call('done', id=job['id'])
                log('Bon gedruckt, Bestellung', job['order_id'], 'Auftrag', job['id'])
                continue
        except Exception as e:
            log('Fehler:', e)
            time.sleep(5)
            continue
        time.sleep(2)

if __name__ == '__main__':
    main()
