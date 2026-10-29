# ZKTeco K40 Pro → Time Card (ADMS)

Same end result as Hikvision: each fingerprint punch creates a row on **Time Card** (IN / Late Coming / OUT / Early OUT via the existing toggle rules).

## How it works

1. Register the device in **Time Card → ZKTeco Fingerprint Device Sync (K40 Pro)** with the terminal **Serial Number (SN)**.
2. On the K40 Pro, set **Cloud Server / ADMS** to the HR **ADMS URL** shown on the device card (ends with `/iclock`).
3. The device POSTs attendance (ATTLOG) to `/iclock/cdata`. HR writes `time_cards` through the same `TimeCardController::attendance` path as Hikvision.

**Device User ID = Attendance Employee No** in HR (leading zeros are matched the same way as Hikvision).

## Setup steps

1. Run migration (or let the API create tables on first use):
   ```bash
   php artisan migrate
   ```
2. Open **Time Card** → expand **ZKTeco Fingerprint Device Sync (K40 Pro)** → **Add Device**.
3. Enter name + **Serial Number** from the terminal (Menu → Comm. / Device Info).
4. Copy the **ADMS / Cloud Server URL**.
5. On the K40 Pro:
   - Menu → **Comm.** → **Cloud Server** / **ADMS**
   - Enable domain name / cloud server
   - Server address = the ADMS URL host path (e.g. `https://apispmhr.cyberneticde.site/iclock`)
   - Port = `443` (HTTPS) or `80` (HTTP)
   - Save / reboot if prompted
6. Wait until the card shows **Online (handshake)**.
7. Enroll fingers with User ID = Attendance Employee No, then scan — refresh Time Card.

## Requirements

- `APP_URL` must be a URL the **device can reach** (public HTTPS for cloud HR, e.g. `https://apijcfood.cyberneticde.site`).
- If `APP_URL` is `http://127.0.0.1`, set `ZKTECO_LAN_HOST` (or `ZKTECO_ADMS_PUBLIC_URL`) so the ADMS URL shown in HR is the LAN/public host the K40 can open.
- **Recent punches** only lists rows already imported into HR — empty means no ADMS push yet (not a LAN pull from device memory).
- Nginx already forwards `/iclock` via `try_files` → `index.php` (see `deploy/nginx-laravel.conf.example`).
- Roster must exist for the punch date (same as other attendance).

## Optional JSON push

`POST /api/zkteco/punches/{token}` with `{ "punches": [ { "empNo": "12", "date": "2026-04-07", "time": "08:30:00" } ] }` for tests or a future office helper.
