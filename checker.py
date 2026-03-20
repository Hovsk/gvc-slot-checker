import os
import re
import json
import requests
from datetime import datetime, timedelta

# ── Config from environment variables ────────────────────────────────────────
TELEGRAM_TOKEN  = os.environ['TELEGRAM_TOKEN']
TELEGRAM_CHAT_ID = os.environ['TELEGRAM_CHAT_ID']
GVC_USERNAME    = os.environ['GVC_USERNAME']
GVC_PASSWORD    = os.environ['GVC_PASSWORD']

GVC_BASE        = 'https://am-gr-services.gvcworld.eu'
LOGIN_URL       = f'{GVC_BASE}/login/AM/en'
APPOINTMENTS_URL= f'{GVC_BASE}/appointments/add'
SLOTS_URL       = f'{GVC_BASE}/appointments/slots'

# Date range: check from 1 week from now to 3 weeks from now (workdays only)
def get_dates_to_check():
    dates = []
    start = datetime.now() + timedelta(days=8)
    end   = datetime.now() + timedelta(days=22)
    d = start
    while d <= end:
        if d.weekday() < 5:  # Monday=0 ... Friday=4
            dates.append(d.strftime('%d/%m/%Y'))
        d += timedelta(days=1)
    return dates

# ── Telegram ──────────────────────────────────────────────────────────────────
def send_telegram(message, parse_mode='HTML'):
    url = f'https://api.telegram.org/bot{TELEGRAM_TOKEN}/sendMessage'
    payload = {
        'chat_id': TELEGRAM_CHAT_ID,
        'text': message,
        'parse_mode': parse_mode,
        'disable_notification': False,  # Always notify with sound
    }
    try:
        r = requests.post(url, json=payload, timeout=10)
        r.raise_for_status()
        print(f'Telegram sent: {message[:60]}...')
    except Exception as e:
        print(f'Telegram error: {e}')

def send_alert(date, times):
    times_str = '  ·  '.join(times)
    message = (
        '🚨🚨🚨 <b>GVC SLOT AVAILABLE!</b> 🚨🚨🚨\n\n'
        f'📅 Date: <b>{date}</b>\n'
        f'🕐 Times: <b>{times_str}</b>\n\n'
        '👉 <a href="https://am-gr-services.gvcworld.eu/appointments/add">Book NOW!</a>\n\n'
        '⚡ Act fast — slots disappear in minutes!'
    )
    send_telegram(message)

# ── GVC Session ───────────────────────────────────────────────────────────────
class GVCChecker:
    def __init__(self):
        self.session = requests.Session()
        self.session.headers.update({
            'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            'Accept': 'application/json, text/plain, */*',
            'Accept-Language': 'en-US,en;q=0.9',
        })

    def login(self):
        print('Logging in to GVC...')
        # Get login page first to get CSRF token
        r = self.session.get(LOGIN_URL, timeout=15)
        
        # Extract CSRF token if present
        csrf = ''
        csrf_match = re.search(r'name="_csrf"[^>]*value="([^"]+)"', r.text)
        if csrf_match:
            csrf = csrf_match.group(1)

        # Submit login
        payload = {
            'username': GVC_USERNAME,
            'password': GVC_PASSWORD,
            '_csrf': csrf,
        }
        r = self.session.post(LOGIN_URL, data=payload, timeout=15, allow_redirects=True)
        
        # Check if logged in
        if 'hovhannesn1' in r.text or 'logout' in r.text.lower() or 'dashboard' in r.text.lower():
            print('Login successful!')
            return True
        
        # Try alternative check
        me = self.session.get(f'{GVC_BASE}/user/me', timeout=10)
        if me.status_code == 200 and 'username' in me.text.lower():
            print('Login successful!')
            return True
            
        print('Login may have failed — continuing anyway')
        return True  # Continue even if unsure, slots API will tell us

    def check_date(self, date_str):
        """Check slots for a specific date. Returns list of available times."""
        try:
            # The slots endpoint expects the date and form params
            payload = {
                'datefrom': date_str,
                'bookingfor': '1',    # Group
                'members': '2',       # 2 people
                'type': '0',          # Schengen Type C
                'appointmentmethod': '1',  # Same time
                'vac': '1',           # Yerevan VAC
            }
            
            r = self.session.post(
                SLOTS_URL,
                data=payload,
                timeout=15,
                headers={'X-Requested-With': 'XMLHttpRequest'}
            )
            
            if r.status_code != 200:
                print(f'  {date_str} — HTTP {r.status_code}')
                return []

            # Parse response
            try:
                data = r.json()
                return self.parse_slots_json(data)
            except:
                # Try parsing HTML response
                return self.parse_slots_html(r.text)

        except Exception as e:
            print(f'  {date_str} — error: {e}')
            return []

    def parse_slots_json(self, data):
        """Parse JSON slot response."""
        available = []
        if isinstance(data, list):
            for slot in data:
                if isinstance(slot, dict):
                    time = slot.get('time') or slot.get('period') or slot.get('label', '')
                    booked = slot.get('booked', False) or slot.get('available') == False
                    if time and not booked:
                        available.append(str(time)[:5])  # HH:MM
        elif isinstance(data, dict):
            slots = data.get('slots') or data.get('periods') or data.get('data') or []
            for slot in slots:
                time = slot.get('time') or slot.get('period', '')
                booked = slot.get('booked', False)
                if time and not booked:
                    available.append(str(time)[:5])
        return available

    def parse_slots_html(self, html):
        """Parse HTML slot response — look for time patterns with green color."""
        available = []
        # Look for time patterns like 09:00, 09:15 etc in the response
        times = re.findall(r'\b(\d{2}:\d{2})\b', html)
        
        # If we got times and no "cannot book" or "no results" message
        no_slots_indicators = [
            'cannot book',
            'no results',
            'no availability',
            'fully booked',
        ]
        
        html_lower = html.lower()
        if any(ind in html_lower for ind in no_slots_indicators):
            return []
        
        # Return unique times found
        seen = set()
        for t in times:
            if t not in seen and '00:00' not in t:
                available.append(t)
                seen.add(t)
        
        return available

    def run(self):
        print(f'\n=== GVC Slot Check — {datetime.now().strftime("%Y-%m-%d %H:%M")} ===')
        
        if not self.login():
            send_telegram('⚠️ GVC Watcher: Login failed. Please check credentials.')
            return

        dates = get_dates_to_check()
        print(f'Checking {len(dates)} dates from {dates[0]} to {dates[-1]}')

        found_any = False
        for date in dates:
            print(f'  Checking {date}...', end=' ')
            slots = self.check_date(date)
            
            if slots:
                print(f'SLOTS FOUND: {slots}')
                send_alert(date, slots)
                found_any = True
            else:
                print('no slots')

        if not found_any:
            print('No slots found today.')
            # Optional: send a daily summary at first run of the day
            now = datetime.utcnow()
            if now.hour == 4 and now.minute < 50:  # ~8:45 AM Yerevan
                send_telegram(
                    f'👁️ GVC Watcher started for today\n'
                    f'Checking {len(dates)} dates every 5 min\n'
                    f'Range: {dates[0]} → {dates[-1]}'
                )

# ── Run ───────────────────────────────────────────────────────────────────────
if __name__ == '__main__':
    checker = GVCChecker()
    checker.run()
