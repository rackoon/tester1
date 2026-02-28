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
