# Android ekraaniapp (kiosk)

See kaust sisaldab valmis Android rakendust:
- Kotlin + Jetpack Compose + Retrofit
- pollib `GET /api.php?action=display-feed` iga 2 sekundi jarel
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

Kui kasutad fyysilist tahvlit/telefoni, muuda failis `app/build.gradle.kts` vaartust:
- `buildConfigField("String", "API_BASE_URL", "\"http://SINU_SERVER:PORT\"")`
