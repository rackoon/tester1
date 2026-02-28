#!/usr/bin/env python3
import json
import os
import re
import signal
import sqlite3
import subprocess
import sys
import time
import urllib.request

BASE_DIR = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
DB_PATH = os.path.join(BASE_DIR, 'data', 'planner.sqlite')
RUNTIME_DIR = os.path.join(BASE_DIR, 'runtime', 'sip-agent')
LOG_PATH = os.path.join(BASE_DIR, 'runtime', 'sip-agent.log')

STOP = False


def log(msg: str) -> None:
    line = f"[{time.strftime('%Y-%m-%d %H:%M:%S')}] {msg}"
    print(line, flush=True)
    try:
        with open(LOG_PATH, 'a', encoding='utf-8') as f:
            f.write(line + "\n")
    except Exception:
        pass


def sig_handler(signum, frame):
    global STOP
    STOP = True


def db_get(conn: sqlite3.Connection, key: str, default: str = '') -> str:
    try:
        cur = conn.execute('SELECT value FROM app_settings WHERE key = ?', (key,))
        row = cur.fetchone()
        if not row or row[0] is None or row[0] == '':
            return default
        return str(row[0])
    except Exception:
        return default


def load_cfg():
    conn = sqlite3.connect(DB_PATH)
    conn.row_factory = sqlite3.Row
    try:
        enabled = db_get(conn, 'sip_agent_enabled', '0') == '1'
        api_url = db_get(conn, 'planner_api_url', 'https://one.crebit.eu/pln/api.php')
        user = db_get(conn, 'sip_user', '')
        password = db_get(conn, 'sip_password', '')
        domain = db_get(conn, 'sip_domain', '')
        transport = db_get(conn, 'sip_transport', 'udp').lower()
        display = db_get(conn, 'sip_display_name', '')
        outbound = db_get(conn, 'sip_outbound', '')
        regint = db_get(conn, 'sip_regint', '300')
        try:
            regint_i = int(regint)
            if regint_i < 30:
                regint_i = 30
        except Exception:
            regint_i = 300
        return {
            'enabled': enabled,
            'api_url': api_url,
            'user': user,
            'password': password,
            'domain': domain,
            'transport': transport if transport in ('udp', 'tcp', 'tls') else 'udp',
            'display': display,
            'outbound': outbound,
            'regint': regint_i,
        }
    finally:
        conn.close()


def ensure_runtime(cfg):
    os.makedirs(RUNTIME_DIR, exist_ok=True)

    modules = """module_path\t\t/usr/lib/baresip/modules

module\t\t\tg711.so
module\t\t\tstun.so
module\t\t\tturn.so
module\t\t\tice.so
module\t\t\talsa.so

module_tmp\t\tuuid.so
module_tmp\t\taccount.so

module_app\t\tmenu.so
module_app\t\tauloop.so
module_app\t\tcontact.so
"""

    config = f"""poll_method\t\tepoll
audio_player\t\talsa,default
audio_source\t\talsa,default
{modules}
"""

    with open(os.path.join(RUNTIME_DIR, 'config'), 'w', encoding='utf-8') as f:
        f.write(config)

    disp = cfg['display'].strip()
    display_prefix = f'\"{disp}\" ' if disp else ''
    acc = (
        f"{display_prefix}<sip:{cfg['user']}@{cfg['domain']};transport={cfg['transport']}>"
        f";auth_user={cfg['user']}"
        f";auth_pass={cfg['password']}"
        f";answermode=auto"
        f";regint={cfg['regint']}"
    )

    if cfg['outbound'].strip():
        acc += f";outbound=\"sip:{cfg['outbound'].strip()};transport={cfg['transport']}\""

    with open(os.path.join(RUNTIME_DIR, 'accounts'), 'w', encoding='utf-8') as f:
        f.write(acc + "\n")


def normalize_phone(userpart: str) -> str:
    cleaned = userpart.strip()
    cleaned = re.sub(r'[^0-9+]', '', cleaned)
    return cleaned


def send_phone_event(api_url: str, phone: str):
    payload = json.dumps({'phone': phone, 'has_reservation': False, 'source': 'sip-agent'}).encode('utf-8')
    req = urllib.request.Request(
        api_url + ('&' if '?' in api_url else '?') + 'action=phone-event',
        data=payload,
        headers={'Content-Type': 'application/json'},
        method='POST',
    )
    with urllib.request.urlopen(req, timeout=8) as resp:
        body = resp.read().decode('utf-8', errors='ignore')
        log(f'phone-event sent for {phone}: {body[:240]}')


def handle_line(cfg, line: str):
    txt = line.strip()
    if not txt:
        return
    low = txt.lower()
    if 'incoming call' not in low:
        return

    # Example patterns seen in SIP logs may vary; parse sip URI user-part robustly.
    m = re.search(r'sip:([^@>;\s]+)@', txt, flags=re.IGNORECASE)
    if not m:
        m = re.search(r'from\s+"?[^"]*"?\s*<?([^@>\s]+)@', txt, flags=re.IGNORECASE)
    if not m:
        log(f'Incoming call detected, but caller parse failed: {txt}')
        return

    phone = normalize_phone(m.group(1))
    if phone == '':
        log(f'Incoming call caller empty after normalize: {txt}')
        return

    try:
        send_phone_event(cfg['api_url'], phone)
    except Exception as e:
        log(f'Failed to send phone-event for {phone}: {e}')


def run_once(cfg):
    ensure_runtime(cfg)
    cmd = ['baresip', '-f', RUNTIME_DIR, '-v']
    log('Starting baresip SIP agent')
    proc = subprocess.Popen(cmd, stdout=subprocess.PIPE, stderr=subprocess.STDOUT, text=True, bufsize=1)

    try:
        assert proc.stdout is not None
        for line in proc.stdout:
            if STOP:
                break
            log(line.rstrip())
            handle_line(cfg, line)
    finally:
        if proc.poll() is None:
            try:
                proc.terminate()
                proc.wait(timeout=5)
            except Exception:
                proc.kill()


def main():
    signal.signal(signal.SIGTERM, sig_handler)
    signal.signal(signal.SIGINT, sig_handler)

    os.makedirs(os.path.dirname(LOG_PATH), exist_ok=True)
    log('SIP agent supervisor started')

    while not STOP:
        try:
            cfg = load_cfg()
            if not cfg['enabled']:
                time.sleep(5)
                continue

            missing = [k for k in ('user', 'password', 'domain') if not cfg[k]]
            if missing:
                log(f'SIP agent waiting for config, missing: {", ".join(missing)}')
                time.sleep(5)
                continue

            run_once(cfg)
        except Exception as e:
            log(f'SIP agent loop error: {e}')

        if not STOP:
            time.sleep(3)

    log('SIP agent supervisor stopped')


if __name__ == '__main__':
    main()
