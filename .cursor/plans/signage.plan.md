# Fluxo Signage — Gesamtkonzept

Stand 2026-11-26 · Entwurf zur Abstimmung

Grundlage: die Pakete `system`, `cms`, `relay` und ein bestehendes
Signage-System (Hotfolder + PHP-WebSocket-Server, im Folgenden „Altstand“).
Ziel: **mehrere Signage-Installationen** (Public Screens, Touch-Terminals,
Präsentationsumgebungen) aus **einem** CMS pflegen, jede mit eigener Adresse,
eigenen Templates und eigenem CSS.

Entschieden (2026-11-26): mehrere Instanzen als **Sites** eines Projekts; die
Inhalte einer Installation sind **Seiten in ihrer Site**. Paket
`systopic/signage` ist angelegt, alles Weitere ist Vorschlag.

---

## 1. Kurzfassung

```
 Redaktion ──https──▶ Fluxo CMS (Plesk, Projekt signage.systopic.net)
                         │  Sites: <inst1>.signage.systopic.net, <inst2>, …
                         │  Vorschau: Wand + Touch im Browser (postMessage)
                         │  Freigabe → statisches HTML5-Paket (versioniert, Manifest)
                         ▼
                      VPN-Hub (öffentlich)
                         ▼
 Standort-LAN ── Fluxo Signage Server (Appliance, kein PHP, keine DB)
                    Caddy: HTTPS, liefert das aktive Paket aus
                    Relay: Touch → Wand (WSS, Rechte je Gerät)
                    Deploy: Paket prüfen, atomar umschalten, Rollback, Log
                         ├── Wand-Rechner: Kiosk-Browser → /wall/
                         └── Touch (Surface): Kiosk-Browser → /touch/
```

Drei Grundsätze:

1. **Redaktion zentral, Wiedergabe autark.** Der Standort braucht nach der
   Übertragung weder Internet noch CMS. Kein PHP, keine Datenbank vor Ort.
2. **Die Wand führt den Zustand.** Die Zustandsmaschine (Thema offen, Folie,
   Video, Autoplay, Bildschirmschoner), die heute im PHP-WebSocket-Server
   läuft, wandert in den Player der Wand. Das Relay leitet nur weiter. Damit
   ist es austauschbar (postMessage in der Vorschau, Hub im CMS, Hub vor Ort).
3. **Ein Paket ist unveränderlich.** Jede Freigabe erzeugt eine Version mit
   Manifest (Dateien + sha256). Übertragen wird nur, was dem Ziel fehlt.
   Umschalten ist atomar, die Vorversion bleibt für den Rollback.

---

## 2. Altstand

Hotfolder `var/media/`, per SFTP befüllt. Der Dateiname ist der Platz im
Touch-Raster plus Flags: `4x2A_Titel.pdf` (Spalte 4, Zeile 2, Autoplay),
`R` = Rubrik (Ordner mit Kacheln), `H` = hervorgehoben, `screensaver_*` =
Playlist. Dazu `Welcome.txt` (cp1252: Überschrift, Zeilen, Countdown-Datum)
und `config.txt` (Player-Zeiten). Ein PHP-CLI-WebSocket-Server hält den
Zustand und verteilt Befehle zwischen Touch und Wand.

Schwächen, die das neue System beheben muss: Schreibzugriff der Redaktion per
SFTP/Portfreigabe; `ws://` ohne Verschlüsselung und ohne Anmeldung; PHP vor
Ort; Zustand nur im Serverprozess; Meldungen nur über Textdatei.

---

## 3. Mehrere Installationen = Sites

Ein Projekt `projects/signage.systopic.net`, eine Datenbank, ein Login. Jede
Installation ist eine **Site mit Knoten** (`docs/multi-site.md` in system):

| Site | Zweck |
|---|---|
| `signage.systopic.net` (Default-Site, ohne Knoten) | Übersicht: Installationen, Geräte, Doku für die Redaktionen |
| `<inst>.signage.systopic.net` | je Installation eine Site mit Knoten |

Je Site eigen:

- **Templates und CSS**: `sites/<domain>/templates/{document,components,pages}/`
  vor den gemeinsamen `templates/` des Projekts. Gemeinsame Signage-Templates
  (Topic, Rubrik, Playlist, Start, Player) liegen projektweit; eine Site
  überschreibt nur, was sie anders braucht. Neue Installation = neue Site +
  Corporate-CSS + ggf. eigenes Raster.
- **Site-Meta** (JSON, im CMS bearbeitbar): Block `signage`, siehe §5.
- **Rechte**: Content Groups/ACL auf dem Site-Knoten (`docs/acl.md`) — die
  Redaktion einer Installation sieht nur ihre Site. Rollen getrennt: Redaktion,
  Live-Test, Freigabe, Technik (Controlmap-Rechte `signagePublish`,
  `signageLiveTest`, `signageTargets`).

Offen in cms: Page-Templates können heute nur aus dem Projekt bzw. der Site
kommen. Damit `systopic/signage` seine Standard-Templates selbst mitbringt,
braucht der `ViewResolver` einen Paket-Ordner als letzte Stufe (Vorschlag,
klein). Bis dahin liegen sie im Projekt unter `templates/pages/`.

---

## 4. Inhaltsmodell (so übernommen)

Importer: `src/Import/Hotfolder.php`, Aufruf
`php vendor/systopic/signage/bin/import-hotfolder.php <hotfolder> <site> [--dry]`
(nimmt nur eine leere Site, kopiert die Dateien, der Hotfolder bleibt).

| Template | Herkunft | Felder | Mediengruppen |
|---|---|---|---|
| `signage_start` | `Welcome.txt` | `text01` Überschrift, `html01` Zeilen, `text02` Countdown (`Y-m-d H:i:s`) | `background` |
| `signage_topic` | Datei oder Bildordner | Label = Titel, `text02` Rasterplatz (`4x2`), `text03` Flags (`A`, `H`) | `document` (PDF), `video` (MP4), `slides` (Bilder) |
| `signage_section` | Ordner mit `R` | wie Topic, Kinder = Kacheln | — |
| `signage_playlist` | `screensaver_*` | `text02` Schlüssel | — (Kinder) |

`config.txt` → Site-Meta `signage.player`. Reihenfolge = Baum (natürliche
Sortierung wie im Altstand). Mediengruppen stehen in `config/config.json`
(`packages.cms.media.groups`).

Nächste Schritte am Modell:

- Flags und Rasterplatz als **Template-Settings** (Checkbox, Auswahl) statt
  Textfelder; der Rasterplatz als Klick in eine Rastervorschau.
- **Meldungen** (kurzfristig): Seiten `signage_message` mit dem
  Veröffentlichungsfenster, das cms schon hat (`start/end_publishing`). Beim
  Export ins Paket mit Zeitfenster, der Player blendet sie zeitgesteuert ein —
  läuft dann auch ohne Verbindung.
- PDF bleibt Original (`document`); die Folien entstehen beim Export (§6), in
  der CMS-Vorschau über die Vorschaubilder.

---

## 5. Ansichten und Player

Eine Site hat mehrere **Ansichten** auf denselben Inhalt, je ein Document-
Template + Player-JS:

| Ansicht | Gerät | Rolle |
|---|---|---|
| `wall` | LED-Wand / Public Screen | spielt ab, **führt den Zustand** |
| `touch` | Touchscreen | Bedienung, schickt Befehle, zeigt den Zustand der Wand |
| `screen` (später) | einfacher Bildschirm ohne Touch | nur Playlist |
| `presenter` (später) | Tablet in Präsentationsräumen | Fernsteuerung (Folie, Zoom, Pan) |

Site-Meta, Beispiel:

```json
{
  "signage": {
    "views": {
      "wall":  { "width": 1920, "height": 1080 },
      "touch": { "width": 1920, "height": 1200, "grid": [5, 4] }
    },
    "player": { "screensaverTimeout": 100, "screensaverFolder": "screensaver_filme",
                "screensaverShowMessage": 10, "secondsPerSlide": 10, "secondsPerImage": 20 },
    "targets": ["<standort>-1"]
  }
}
```

**Player** (`public/js/player/` im Paket, ohne Framework): liest `content.json`,
rendert, spielt. Die Logik aus `lib/funcs.php`/`class/state`/`autoplay` des
Altstands (Bildschirmschoner nach Timeout, Autoplay von Rubriken, Folien-
und Bildzeiten, Video-Ende) wird JS in der Wand. Die Wand veröffentlicht ihren
Zustand nach jeder Änderung; ein neu verbundenes Touch bekommt den letzten.

**Transport** als austauschbare Schicht, gleiche Nachrichten:

| Transport | Wo |
|---|---|
| `postMessage` | Vorschau am Arbeitsplatz: CMS öffnet Wand + Touch als Fenster, `window.opener` für Rückmeldungen |
| Hub im CMS (`systopic/relay`) | Vorschau auf zwei Geräten, z. B. Tablet als Touch |
| Hub auf der Appliance | Betrieb vor Ort |

Nachrichten (Version 1, JSON): `select {topic}`, `close`, `slide {index}`,
`video {play|pause|seek, position}`, `state {…}` (nur Wand), `hello {device}`.
Themen-Ids sind Knoten-Ids der Seiten; das Manifest listet die gültigen.

---

## 6. Export: das Paket

Nach Freigabe baut `Export\Builder` ein statisches Paket:

```
var/signage/<site>/<version>/          (CMS, nicht gesynct)
  manifest.json      site, version, erstellt, freigegeben von, Ansichten,
                     Themen-Ids, files: { pfad: { sha256, size } }
  content.json       Baum: Start, Kacheln, Rubriken, Playlists, Folien, Player-Config
  wall/index.html    gerendert mit den Site-Templates im Export-Modus
  touch/index.html   (relative Pfade, keine CMS-Skripte)
  assets/            CSS, Schriften, Player-JS
  media/<sha256>.<ext>   inhaltsadressiert → inkrementell
```

- Rendern über den normalen Renderer (Route + Templates) mit einem Export-
  Schalter, nicht über eigene Templates — die Vorschau zeigt, was ausgeliefert
  wird.
- PDF → Folien (Imagick + Ghostscript, Dichte wie Altstand: ~4 MP je Seite),
  Bilder auf die Ansichtsgröße, Videos unverändert (Poster/Transcoding später).
- Version: fortlaufend je Site; Tabelle `sig_publications` (Site, Version,
  Manifest-Hash, Status, erstellt von, freigegeben von, Ziele, Zeitpunkte).
- Ein Paket ist auch **lokal lauffähig** (Ordner im Browser öffnen bzw. über
  Laragon) — das ist die Vorschau „wie vor Ort“ und Phase 3 (§10).

---

## 7. Ziele, Übertragung, Aktivierung

Ziele als Treiber + Instanz (wie im Publishing, `plans/publishing-system.md`
in system):

| Treiber | Wofür |
|---|---|
| `local` | Ordner auf dem CMS-Server: Demosystem, DEV, Abnahme |
| `appliance` | Signage Server über VPN |

Ablauf `appliance` (CMS → Appliance, nur über VPN erreichbar):

1. `status` holen: aktive Version, vorhandene Hashes.
2. Fehlende Dateien übertragen (inkrementell, Hash-Store auf der Appliance).
3. `stage <version>`: Appliance prüft Vollständigkeit, sha256, Startseiten.
   Das laufende Programm bleibt aktiv.
4. `activate <version>`: Symlink `current` atomar umschalten, Kiosk-Browser
   laden neu (Nachricht `reload` über das Relay).
5. Protokoll (Zeit, Version, Benutzer, Ergebnis) auf der Appliance und im CMS.
   `activate <vorversion>` = Rollback. Ein unvollständiges Paket kann nie aktiv
   werden.

**Ersatzgerät**: identisch vorkonfiguriert, anstecken, im CMS als
Ziel der Site wählen, letzten freigegebenen Stand übertragen.

---

## 8. Fluxo Signage Server (Appliance)

Debian (stable), lüfterloser Mini-PC, Image identisch für alle Standorte.

| Dienst | Vorschlag | Aufgabe |
|---|---|---|
| Web | **Caddy** | HTTPS (Zertifikat aus eigener CA — das „vertrauenswürdige Stammzertifikat“ auf den Kiosk-Geräten), liefert `current/` aus, leitet `/connection/` ans Relay |
| Relay | **Centrifugo** (wie `systopic/relay`, Installskript für Ubuntu/Debian vorhanden) | WSS auf 443 über Caddy. Geräte-Token (JWT, vom CMS je Ziel signiert) mit festen Kanälen und Rechten: Touch darf nur in `control` veröffentlichen, die Wand nur `state` |
| Deploy | Benutzer `deploy`, SSH nur über VPN, Forced Command (`rrsync` + `activate.sh`) | §7: Hash-Store, Prüfung, atomares Umschalten, Rollback, Log |
| VPN | WireGuard-Client | Verbindung zum Hub, nur ausgehend über den Router des Standorts |

Live-Test: Der Editor klickt im CMS ein Thema → PUT an PHP → PHP
veröffentlicht über die **HTTP-API des Hubs der Appliance** (über VPN, API-
Schlüssel je Ziel) `select` an die Wand. Keine Kopplung zweier Hubs nötig.

Themenprüfung „nur bekannte Themen des aktiven Pakets“: die Wand
verwirft unbekannte Ids (sie kennt das Manifest); die Rechte je Gerät erzwingt
der Hub. Soll auch der Hub den Inhalt prüfen, braucht Centrifugo einen
Publish-Proxy — dann wäre ein kleiner eigener Agent (Go, ein Binary: Relay +
Prüfung + Deploy) die einfachere Wahl. **Entscheidung offen (E2).**

Kiosk-Geräte: Stammzertifikat installieren, Start-URL setzen
(`https://signage.<standort>.local/wall/#device=<token>` bzw. `/touch/`).
Wand-Rechner: Chromium-Kiosk, Touch (Surface): Edge-Kiosk.

Monitoring im CMS je Site: Ziel erreichbar, aktive Version, verbundene Geräte
(Presence des Hubs über die API), letzter Deploy, Zustand der Wand.

---

## 9. Paket `systopic/signage`

```
src/Import/        Hotfolder, HotfolderName              ✓ 2026-11-26
src/Content/       Baum → content.json
src/Export/        Builder, Manifest, Slides (PDF → Bilder)
src/Target/        Target, Local, Appliance (status/push/stage/activate/log)
src/LiveTest/      Publish an den Hub eines Ziels
src/Tables/        Publications (sig_publications), Targets
panels/admin/signage/   Versionen, Freigabe, Übertragen, Rollback, Log, Live-Test, Geräte
templates/         Standard-Templates der Ansichten (sobald cms Paket-Templates kennt)
public/js/player/  wall.js, touch.js, transport/{postmessage,relay,appliance}.js
appliance/         install.sh (Debian), Caddyfile, centrifugo.json, activate.sh, systemd
bin/import-hotfolder.php                                 ✓ 2026-11-26
```

---

## 10. Phasen

| # | Schritt | Stand |
|---|---|---|
| 0 | Altstand als Referenz lokal lauffähig | ✓ 2026-11-26 |
| 1 | Bestandsdaten übernehmen: Site, Seiten, Medien, Player-Config | ✓ Importer 2026-11-26; Abgleich mit dem Bestand offen |
| 2 | Templates + Player (Wand, Touch) in der CMS-Vorschau, Optik des Altstands (CSS übernehmen), Transport `postMessage` | |
| 3 | Export + Ziel `local`; Paket läuft statisch und offline | |
| 4 | Appliance-Image, Ziel `appliance`, autarker Test (Netz getrennt, Neustart, unvollständiges Paket, Rollback) | |
| 5 | Freigabe-Workflow, Rechte, Live-Test über VPN, Monitoring | |
| 6 | Vor Ort in Betrieb nehmen | |
| 7 | weitere Installationen als Sites | |

---

## 11. Offene Entscheidungen

| # | Frage | Vorschlag |
|---|---|---|
| E1 | Name/Domain der Sites | `<kunde>.signage.systopic.net` (CMS-Vorschau); vor Ort eigener lokaler Name |
| E2 | Relay auf der Appliance | Centrifugo (Standard, wie relay); eigener Go-Agent nur, wenn der Hub Inhalte prüfen muss |
| E3 | VPN | WireGuard, Hub auf einem eigenen kleinen Server (nicht dem Plesk) |
| E4 | Wer führt den Zustand | die Wand (Player), das Relay bleibt dumm |
| E5 | Standard-Templates im Paket | kleine Erweiterung im `ViewResolver` von cms |
| E6 | Freigabe | Vier-Augen (Redaktion ≠ Freigabe) als Option je Site |
| E7 | weitere Installationen | Welche Ansichten, welche Auflösungen? |
| E8 | Medien vor Ort | Videos unverändert oder auf Wand-Auflösung transkodieren (ffmpeg im Export) |
