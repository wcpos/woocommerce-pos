# Star CloudPRNT Simulator

A small Node CLI that simulates a Star CloudPRNT printer polling a StarIO.Online CloudPRNT device URL. Use it to test WCPOS Star Online printing without physical Star printer hardware.

## Usage

```bash
pnpm start -- \
  --url "https://eu-device.stario.online/cloudprnt/<group>"
```

Useful options:

```bash
--mac "00:11:62:1d:e8:30"          # fake printer MAC
--serial "2602319010600001"        # fake printer serial number
--poll-seconds 2                    # polling interval
--once                              # run a single poll, useful for smoke tests
```

Environment variables are also supported:

```bash
CLOUDPRNT_URL="https://eu-device.stario.online/cloudprnt/<group>" \
PRINTER_MAC="00:11:62:1d:e8:30" \
pnpm start
```

## StarIO.Online setup

1. Create or choose a device group in StarIO.Online.
2. Copy its CloudPRNT URL.
3. Make sure the group allows a new CloudPRNT device to connect. If the group requires a device-specific key, disable that for local simulation unless you know the expected key flow.
4. Run this simulator against the CloudPRNT URL.
5. Once the simulator appears in the StarIO.Online device list, select it in WCPOS and submit a test print.

The simulator logs received print payloads and confirms them as successful with CloudPRNT `DELETE code=200 OK`.
