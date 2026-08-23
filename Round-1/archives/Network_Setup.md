# CivicConnect Network Setup

This document records the known-good network architecture for the CivicConnect
demo. It is designed to make the setup reproducible on the demo laptop without
putting MariaDB or the local AI model directly on the public internet.

## Architecture at a glance

```text
                         Public internet
                                │
                                │ HTTPS
                                ▼
                   api.careerinstitute.co.in
                                │
                                │ Cloudflare Tunnel
                                │ outbound tunnel connection
                                ▼
                  Laptop: 127.0.0.1:80
                         Apache/PHP
                       ┌────────┴────────┐
                       │                 │
                       │                 │ HTTP, loopback only
                       │                 ▼
                       │        FastAPI + YOLO detector
                       │             127.0.0.1:8000
                       │
                       │ MySQL protocol over TCP 3306
                       ▼
              AWS Client VPN → AWS RDS

Expo mobile app
    │
    └── HTTPS API calls to https://api.careerinstitute.co.in
        (never directly to Apache LAN IP, RDS, or FastAPI)
```

The laptop is the application host:

- Apache serves the PHP application on local port `80`.
- PHP is the public API boundary for the mobile app and the web application.
- PHP connects outbound to AWS RDS on TCP port `3306`.
- PHP calls the local FastAPI service on `127.0.0.1:8000` for image analysis.
- `cloudflared` creates the public HTTPS path to Apache without opening an
  inbound router port to the laptop.

Only the Cloudflare hostname is public. Port `3306` and port `8000` must not be
configured as public tunnel routes.

## 1. Inbound mobile traffic

### Permanent API origin

The Expo app is permanently configured to use this origin:

```text
https://api.careerinstitute.co.in
```

The value must be the PHP/API origin, not the Expo Metro development server.
Metro normally uses port `8081`; that port is for bundling JavaScript and is not
the CivicConnect backend.

For local Expo commands, create or update the ignored file
`mobile/.env`:

```dotenv
EXPO_PUBLIC_API_BASE_URL=https://api.careerinstitute.co.in
```

The value is read by `mobile/src/config.ts` and is bundled into the app at
startup/build time. Restart Expo after changing it so the new environment value
is included.

For EAS builds, the local `mobile/.env` file is not used by EAS. Set the same
value in each EAS environment used to create a distributable build:

```powershell
cd mobile
npx --yes eas-cli@21.8.0 env:set --environment preview --name EXPO_PUBLIC_API_BASE_URL --value https://api.careerinstitute.co.in --visibility plaintext
npx --yes eas-cli@21.8.0 env:set --environment production --name EXPO_PUBLIC_API_BASE_URL --value https://api.careerinstitute.co.in --visibility plaintext
```

Then build with the corresponding profile:

```powershell
npx --yes eas-cli@21.8.0 build --platform android --profile preview
# or
npx --yes eas-cli@21.8.0 build --platform android --profile production
```

This is intentionally a hostname rather than a laptop LAN address. The mobile
binary therefore remains usable when the phone changes Wi-Fi networks or uses
mobile data, as long as the Cloudflare Tunnel and the laptop services are
running.

### Cloudflare Tunnel route

The active tunnel configuration is stored outside the repository at:

```text
C:\Users\tanma\.cloudflared\config.yml
```

Its important ingress rule is:

```yaml
ingress:
  - hostname: api.careerinstitute.co.in
    service: http://127.0.0.1:80
  - service: http_status:404
```

The tunnel works as follows:

1. The phone resolves `api.careerinstitute.co.in` through Cloudflare.
2. Cloudflare terminates the public HTTPS connection at the edge.
3. `cloudflared` maintains an outbound connection from the laptop to
   Cloudflare.
4. Cloudflare sends the matching hostname's request through that tunnel.
5. `cloudflared` forwards the request to Apache at `127.0.0.1:80`.
6. Apache/PHP returns the JSON or web response through the same path.

The origin service in this setup is local HTTP because the final hop is from
`cloudflared` to the laptop's loopback interface. The public client still uses
HTTPS. Do not change the ingress target to `0.0.0.0`, `localhost:8000`, or the
RDS endpoint.

The repository contains a local `cloudfared.exe` binary. The tunnel ID and
credentials remain in `C:\Users\tanma\.cloudflared`; do not commit the JSON
credentials file, `cert.pem`, or any access token.

## 2. Outbound database traffic to AWS RDS

The PHP backend uses `functions/database/database.php`. Its connection settings
are supplied by environment variables or the ignored local PHP configuration
file `functions/database/database.local.php`:

```text
CIVICCONNECT_DB_HOST=<AWS RDS endpoint>
CIVICCONNECT_DB_PORT=3306
CIVICCONNECT_DB_NAME=<database name>
CIVICCONNECT_DB_USER=<database user>
CIVICCONNECT_DB_PASSWORD=<database password>
```

The connection is outbound from the laptop:

```text
Apache/PHP laptop ── TCP 3306 ── AWS Client VPN ── AWS network ── AWS RDS
```

The mobile app does not receive database credentials and never connects to RDS
directly. All database reads and writes go through PHP endpoints under the
Cloudflare hostname.

### Why AWS Client VPN must be connected first

The AWS Client VPN provides the stable network identity and route that AWS uses
to accept the laptop's RDS connection. This matters when the laptop moves
between home Wi-Fi, a phone hotspot, and another network: the physical network's
public IP can change, while the VPN client address/routed CIDR remains the
expected source for the AWS security rules.

Connect the AWS Client VPN before starting the application services. The RDS
security group and network ACLs must allow the VPN path to the RDS endpoint on
TCP `3306`; do not broadly allow `0.0.0.0/0` just to make the demo work.

A quick connectivity check, after replacing the placeholder with the actual RDS
hostname, is:

```powershell
Test-NetConnection <AWS-RDS-ENDPOINT> -Port 3306
```

`TcpTestSucceeded : True` confirms that the network path is open. It does not
replace a real PHP login/query check, which also validates the database name,
credentials, and schema.

If the VPN disconnects or the laptop changes networks, PHP may report an empty
or unavailable database response even though Apache is still running. Reconnect
the VPN and retry the API request before changing the PHP or database
configuration.

## 3. Local AI service

The detector is the FastAPI application in `ai-service/main.py`. It runs only on
the laptop and binds to the loopback address:

```text
http://127.0.0.1:8000
```

The supported analysis endpoint is called by PHP at:

```text
POST http://127.0.0.1:8000/analyze
```

`functions/ai/service.php` defaults `CIVICCONNECT_AI_URL` to
`http://127.0.0.1:8000`. If an override is used, it must still point to the
local service for this laptop demo. If `CIVICCONNECT_AI_TOKEN` is configured,
the same token must be configured for both PHP and FastAPI.

Start it with the project script:

```powershell
cd C:\Users\tanma\Desktop\GitHub\SIH-2026-Prototype
& .\ai-service\start.ps1
```

The script checks `/health`, refuses to take over an occupied port, and starts
Uvicorn with the equivalent command:

```powershell
python -m uvicorn main:app --host 127.0.0.1 --port 8000
```

The YOLO model is loaded by the FastAPI process and uses the laptop's PyTorch
environment, including the RTX 3050 VRAM when the installed PyTorch build and
drivers provide CUDA support. The trained `.pt` model is not shipped in the
Expo application.

### Exposure boundary

- Do not bind FastAPI to `0.0.0.0`.
- Do not create a Cloudflare ingress rule for port `8000`.
- Do not set the mobile API base URL to port `8000`.
- Do not expose the model file or inference endpoint to the phone.

The request path for AI analysis is always:

```text
Phone → Cloudflare → Apache/PHP → 127.0.0.1:8000 FastAPI → PHP response → Phone
```

PHP performs the server-side analysis and remains authoritative for persistence;
the mobile app does not make an independent model decision.

## 4. Exact demo startup sequence

Use the following order every time a demo laptop is restarted or changes
networks.

### Step 1: Connect AWS Client VPN

Open the AWS Client VPN client and connect to the configured AWS endpoint. Wait
for the client to report connected before proceeding.

Optional network check:

```powershell
Test-NetConnection <AWS-RDS-ENDPOINT> -Port 3306
```

Proceed only when the VPN route and TCP connection are available.

### Step 2: Start Apache and MySQL in XAMPP

Open the XAMPP Control Panel and click `Start` for:

1. `Apache`
2. `MySQL`

Apache must be listening on local port `80`, because the Cloudflare ingress
forwards to `127.0.0.1:80`. MySQL is started as part of the established local
demo sequence; the PHP database target is still determined by
`CIVICCONNECT_DB_HOST`. When that value is the AWS RDS endpoint, PHP uses RDS
over the VPN rather than silently switching to the local XAMPP MySQL instance.

Optional local checks:

```powershell
Test-NetConnection 127.0.0.1 -Port 80
Get-NetTCPConnection -LocalPort 80 -State Listen
```

### Step 3: Start FastAPI

From the repository root, open a separate PowerShell window and run:

```powershell
cd C:\Users\tanma\Desktop\GitHub\SIH-2026-Prototype
& .\ai-service\start.ps1
```

Leave this window running. Confirm the service is healthy before testing a
report upload:

```powershell
Invoke-RestMethod http://127.0.0.1:8000/health
```

### Step 4: Start the Cloudflare Tunnel

Open another PowerShell window and run the named tunnel using its external
configuration:

```powershell
cd C:\Users\tanma\Desktop\GitHub\SIH-2026-Prototype
& .\cloudfared.exe tunnel --config "$env:USERPROFILE\.cloudflared\config.yml" run
```

Leave this window running. The tunnel must report a connected/running state.
The configured hostname then forwards to Apache at `127.0.0.1:80`.

### Optional: start FastAPI and cloudflared together

After AWS Client VPN and XAMPP are ready, the repository-level launcher can
start both local services together:

```powershell
cd C:\Users\tanma\Desktop\GitHub\SIH-2026-Prototype
.\start-demo-services.ps1
```

The launcher checks that Apache is already listening on port `80`, validates
the Cloudflare ingress file, reuses FastAPI if its health endpoint is already
available, and otherwise starts it before starting the tunnel. By default the
two processes run in hidden windows and write logs to
`$env:TEMP\CivicConnect-demo-services`. Use `-ShowWindows` when you want the
service windows visible:

```powershell
.\start-demo-services.ps1 -ShowWindows
```

Stop only the services started by the launcher with:

```powershell
.\stop-demo-services.ps1
```

The stop script does not stop XAMPP or disconnect AWS Client VPN. If FastAPI
was already healthy before the launcher ran, it is reused and is not stopped by
the launcher cleanup.

### Step 5: Verify the public path

From the laptop or a phone, open:

```text
https://api.careerinstitute.co.in
```

Then launch the Expo app or the EAS-built APK. Verify that it loads data from
the public HTTPS origin and that a report upload can reach PHP and complete its
server-side AI analysis.

The important dependency order is:

```text
AWS Client VPN
    → XAMPP Apache/MySQL
    → FastAPI on 127.0.0.1:8000
    → cloudflared tunnel
    → Expo app / EAS build
```

## 5. Shutdown sequence

For a clean stop after the demo:

1. Close or press `Ctrl+C` in the `cloudflared` window.
2. Close or press `Ctrl+C` in the FastAPI window.
3. Stop Apache and MySQL in XAMPP.
4. Disconnect AWS Client VPN.

Stopping the tunnel first prevents new public requests while the local services
are being taken down.

## 6. Troubleshooting

### The mobile app cannot load data

Check the following first:

- `EXPO_PUBLIC_API_BASE_URL` is exactly
  `https://api.careerinstitute.co.in`.
- The app was restarted or rebuilt after changing the variable.
- The app is not pointing to `127.0.0.1`, `localhost`, a LAN IP, or Metro port
  `8081`.
- Apache is running on port `80`.
- The Cloudflare Tunnel window is still connected.

### The public hostname returns a Cloudflare error

Check that:

- `cloudfared` is running with the intended
  `C:\Users\tanma\.cloudflared\config.yml`.
- The config hostname is exactly `api.careerinstitute.co.in`.
- Apache responds locally at `http://127.0.0.1:80`.
- The tunnel's credentials file still exists and is readable by the current
  Windows user.

### PHP shows database or dashboard errors

Check the AWS Client VPN before changing code. Then verify:

- The RDS hostname is the current `CIVICCONNECT_DB_HOST`.
- `CIVICCONNECT_DB_PORT` is `3306`.
- The RDS security group allows the VPN source/routed CIDR.
- The database name and credentials are correct.
- PHP is loading the intended environment variables or
  `functions/database/database.local.php`.

### Report analysis fails

Check that:

- FastAPI is running and `/health` responds on `127.0.0.1:8000`.
- PHP's `CIVICCONNECT_AI_URL` is not pointing at a phone, LAN address, or
  public hostname for this local setup.
- The FastAPI process has access to the model and the CUDA/PyTorch environment
  is healthy.
- If authentication is enabled, `CIVICCONNECT_AI_TOKEN` matches on both sides.

## 7. Secrets and files that stay local

Do not commit any of the following:

- `mobile/.env` when it contains local settings or future private values.
- `functions/database/database.local.php`.
- AWS Client VPN profiles, certificates, or private keys.
- `C:\Users\tanma\.cloudflared\cert.pem`.
- `C:\Users\tanma\.cloudflared\*_*.json` tunnel credentials.
- Database passwords or `CIVICCONNECT_AI_TOKEN` values.
- YOLO `.pt` model weights.

The public API hostname is safe to include in source and EAS configuration. The
tunnel credentials, database credentials, VPN credentials, and AI shared token
are not.
