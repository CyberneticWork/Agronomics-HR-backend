# Hikvision DS-K1T320MFWX Integration Guide

This HR system supports **both** real-time and batch attendance from your Hikvision terminal (DS-K1T320MFWX, serial GG4907842) via **iVMS-4200** or **Hik-Connect**.

## Prerequisites

1. **Same LAN** — HR server/PC must reach the device IP (e.g. `192.168.1.64`).
2. **Employee IDs** — Device **Person No** = HR **Attendance Employee No**.
3. **Roster** — Each employee needs a shift roster for punch dates.
4. **Run migration**:
   ```bash
   cd sohan_hr_system_backend
   php artisan migrate
   ```

## Step 1 — Register the device in HR

1. Open **Time Card** in the HR frontend.
2. Expand **Hikvision Fingerprint Device**.
3. Click **Add Device** and enter:

   | Field | Example |
   |-------|---------|
   | Name | Main Entrance |
   | Model | DS-K1T320MFWX |
   | Serial | GG4907842 |
   | IP | 192.168.1.64 |
   | Port | **443** if the device web UI is `https://…` (common); else 80 |
   | Username | admin |
   | Password | (device admin password) |

4. Click **Test** — should show “Connected successfully”.
5. Copy the **Webhook URL** shown on the device card.

## Step 2 — Real-time mode (webhook push)

### Option A — Auto configure (ISAPI)

Click **Configure Webhook** on the device card. The backend calls the device ISAPI API to register the webhook URL.

### Option B — Manual (device web UI or iVMS-4200)

1. Open device web page: `http://DEVICE_IP`
2. Go to **Configuration → Network → Advanced → HTTP Listening** (or **Event → Notification**).
3. Enable HTTP host and paste the webhook URL from HR, e.g.:
   ```
   http://YOUR-HR-SERVER:8000/api/hikvision/webhook/xxxxxxxx
   ```
4. Event type: **Access Control Event** / **Attendance**.
5. Save.

When an employee scans a finger, the device POSTs to HR and a time card is created automatically.

### Production notes

- Set `APP_URL` in `.env` to the URL the **device can reach** (not `127.0.0.1` if the device is on another machine).
- Optional: set `HIKVISION_WEBHOOK_SECRET` in `.env` and configure the same header on the device if supported.

## Step 3 — Polling mode (scheduled sync)

Every **5 minutes** (configurable), Laravel polls the device for new access events:

```env
HIKVISION_POLL_INTERVAL=5
```

Manual sync:

```bash
php artisan hikvision:sync-attendance
php artisan hikvision:sync-attendance --device=1
```

Or click **Sync Now** on the Time Card device panel.

**Keep scheduler running in production:**

```bash
php artisan schedule:work
# or add to cron: * * * * * php /path/to/artisan schedule:run
```

## Step 4 — Excel import (iVMS-4200 / Hik-Connect)

1. In **iVMS-4200**: Time & Attendance → Reports → Export Excel  
   (or Hik-Connect app → attendance export)
2. In HR **Time Card** → Import section:
   - Select **iVMS-4200 / Hik-Connect Export**
   - Choose company, date range, upload file
   - Click **Import Hikvision Excel**

Supported columns (auto-detected): Person No, Date/Time, Employee No, etc.

## Step 5 — Enroll employees on the device

In iVMS-4200:

1. **Person** → Add person with **Employee No** matching HR `attendance_employee_no`.
2. Enroll fingerprint on DS-K1T320.
3. Assign person to the device/door.

## Troubleshooting

| Issue | Fix |
|-------|-----|
| Employee not found | Match Person No on device with Attendance Employee No in HR |
| No roster assigned | Assign shift roster for that date |
| Webhook not received | Check firewall; device must reach HR server IP:port |
| Test connection fails | Verify IP, password, ISAPI enabled on device |
| Duplicate punch (409) | Normal if same punch sent twice within 5 minutes |
| Hik-Connect only (cloud) | Use Excel export; cloud devices may not expose LAN ISAPI |

## API reference

| Endpoint | Purpose |
|----------|---------|
| `POST /api/hikvision/webhook/{token}` | Device event push |
| `GET /api/hikvision/devices` | List devices |
| `POST /api/hikvision/devices/{id}/sync` | Poll device now |
| `POST /api/attendance/import-hikvision-excel` | iVMS Excel import |

## Environment variables

```env
APP_URL=http://192.168.1.10:8000
HIKVISION_POLL_INTERVAL=5
HIKVISION_WEBHOOK_SECRET=
HIKVISION_HTTP_HOST_ID=1
```
