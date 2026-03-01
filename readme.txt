Planner rakendus territooriumi liikluse haldamiseks

Käivitamine:
1) php -S 0.0.0.0:8080
2) Ava http://localhost:8080/admin.php

Vaikimisi admin:
- kasutaja: admin
- parool: Tere1234

Funktsionaalsus:
- Frigate numbrituvastuse API endpoint (plate-event)
- VoIP/SIP kõnede API endpoint (phone-event)
- Shelly relee käivitus üle HTTP API
- Shelly Pro 2PM tugi (RPC /rpc/Switch.Set) + relay fallback
- Ampron LED ekraani tugi (Service Lobby vaiketekst + plate kuva service_lobby suunamise korral)
- Kohalikud lubade reeglid + partneri infosüsteemi päring fallbackina
- Ajapõhised erandid ilma loata sisenemisele (CRUD "Load" alajaotuses)
- Ajakava reeglid (nt E-R 7-19 või 24/7)
- Kasutajaõigused (viewer/operator/admin)
- Android ekraani feed (display-feed)

Shelly seadistuse soovitus (Seaded -> Shelly relee seadistus):
- Shelly baas URL: http://SEADME_IP
- Shelly mode: rpc (Pro/Gen2)
- Switch ID: 0 või 1
- toggle_after: 1 (sek)

Ampron LED seadistus (Seaded -> Ampron LED ekraan):
- Luba integratsioon "Ampron tugi lubatud"
- Pane baas URL kujul http://DISPLAY_IP:PORT (voi .../mlds)
- Määra display ID, standby layout/field/text ja plate layout/field
- Kui otsus on allowed + zone=service_lobby, saadab PLN plate väärtuse Amproni ekraanile
- Muudel juhtudel saadab PLN standby teksti (vaikimisi "Service Lobby")

Mitmekeelsus:
- PLN admin toetab keeli: et, en, fi, sv, lv, lt
- Keelt saab vahetada admini ylaribal (salvestub cookie-sse)
- LPR admin toetab keeli: et, en, fi, sv, lv, lt
- Android monitor kasutab locale-pohiseid `strings.xml` faile
