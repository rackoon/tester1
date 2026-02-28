# Android ekraaniapp (kiosk)

See kaust sisaldab valmis Android rakendust:
- Kotlin + Jetpack Compose + Retrofit
- hoiab pusiuhendust `GET /api.php?action=display-stream` (SSE)
- server saadab muutuse kohe, pollimist ei kasutata
- kuvab viimast `display_message` vaartust suurelt
- varvireeglid:
  - `Suunata Service Lobby alale` -> punane taust
  - `Suunata parklasse` -> roheline taust
  - `Sisenemine keelatud` -> hall taust + admini juhis
- proovib `startLockTask()` kiosk reziimiks (toimib hallatud seadmel)

## Build

Nouded:
- JDK 17
- Android SDK (Platform 34 + Build Tools 34.x)
- internetiuhendus Maven/Google reposse (esimene build laeb soltuvused)

Kaivitus:
1. `cd android`
2. `GRADLE_USER_HOME=../.gradle ./gradlew assembleDebug`

APK asukoht:
- `android/app/build/outputs/apk/debug/app-debug.apk`

## API aadress

Vaikimisi kasutab app `http://10.0.2.2:8080` (Android emulator -> host masin).

Kui kasutad fyysilist tahvlit/telefoni:
- vajuta avavaates ylemisel yhenduse tekstil
- sisesta serveri baas-URL (naide `http://192.168.1.10:8080`)
- app salvestab URL-i lokaalselt ja reconnectib automaatselt
