# SERP UPI Android (Capacitor + API)

Same pattern as The Keypad:

- App UI lives in `mobile/www`
- Data comes from `serpupi/api/index.php` as JSON (`action=login|home|accounts|bills|...`)

## API URL

Edit `mobile/www/config.js`:

- Android emulator: `http://10.0.2.2/serpupi/api/index.php`
- Phone on same Wi‑Fi: `http://YOUR_MAC_LAN_IP/serpupi/api/index.php`

Or change it inside the app on the More screen.

## Useful actions

| action | purpose |
| --- | --- |
| `health` | API check |
| `login` | email + password → token |
| `home` | due bills + wallet |
| `accounts` / `link_account` / `fetch_bill` | linked accounts |
| `bills` / `bill` | bill lists |
| `pay_wallet` | pay with SERP PIN |
| `razorpay_order` / `razorpay_confirm` | Razorpay checkout |

## Build APK

```bash
cd mobile
npx cap sync android
cd android
JAVA_HOME="/Applications/Android Studio.app/Contents/jbr/Contents/Home" \
ANDROID_HOME="$HOME/Library/Android/sdk" \
./gradlew assembleDebug
```

APK:

`mobile/dist/SERP-UPI-debug.apk`
