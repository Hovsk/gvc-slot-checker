# GVC Slot Checker — GitHub Actions

Automatically checks GVC World Yerevan for available appointment slots every 5 minutes during morning hours and sends a Telegram alert when found.

## How it works

- Runs every 5 minutes from **8:45 AM to 11:00 AM Yerevan time** (Mon–Fri)
- Checks dates from **1 week to 3 weeks** from today
- Sends **Telegram alert** instantly when slot found
- Completely free using GitHub Actions

## Setup (5 minutes)

### Step 1 — Create GitHub repository

1. Go to [github.com](https://github.com) and create a free account
2. Click **New repository**
3. Name it `gvc-slot-checker`
4. Set to **Private**
5. Click **Create repository**

### Step 2 — Upload files

Upload these two files to your repository:
- `checker.py`
- `.github/workflows/check.yml`

### Step 3 — Add secrets

Go to your repository → **Settings** → **Secrets and variables** → **Actions** → **New repository secret**

Add these 4 secrets:

| Secret name | Value |
|-------------|-------|
| `TELEGRAM_TOKEN` | Your bot token |
| `TELEGRAM_CHAT_ID` | Your chat ID |
| `GVC_USERNAME` | Your GVC login username |
| `GVC_PASSWORD` | Your GVC login password |

### Step 4 — Enable Actions

Go to **Actions** tab → Click **Enable Actions**

### Step 5 — Test it

Go to **Actions** → **GVC Slot Checker** → **Run workflow** → **Run workflow**

Check your Telegram — you should get a status message!

## Telegram messages

**When checker starts (8:45 AM):**
```
👁️ GVC Watcher started for today
Checking 10 dates every 5 min
Range: 28/03/2026 → 11/04/2026
```

**When slot found:**
```
🚨🚨🚨 GVC SLOT AVAILABLE! 🚨🚨🚨

📅 Date: 02/04/2026
🕐 Times: 09:00 · 09:15 · 10:30

👉 Book NOW! [link]

⚡ Act fast — slots disappear in minutes!
```

## Customization

Edit `checker.py` to change:
- **Date range** — change `timedelta(days=8)` and `timedelta(days=22)`
- **Check window** — edit the cron schedule in `check.yml`

## Cost

**Completely free!** GitHub gives 2000 free minutes/month. This script uses ~50 minutes/month.
