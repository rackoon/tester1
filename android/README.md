# Android ekraaniäpp (kiosk)

Lihtne kiosk-rakenduse loogika:
1. Polli endpointi `GET /api.php?action=display-feed` iga 2 sekundi järel.
2. Kuva viimane kirje suurelt:
   - `display_message = "Suunata Service Lobby alale"` -> punane taust.
   - `display_message = "Suunata parklasse"` -> roheline taust.
   - `display_message = "Sisenemine keelatud"` -> hall taust + juhis võtta ühendust administraatoriga.
3. App töötab lock-task režiimis (kiosk mode), et kasutaja ei saaks seadet kasutada muuks.

Soovituslik stack: Kotlin + Jetpack Compose + Retrofit.
