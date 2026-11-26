# systopic/signage — Stand und Bedienung

Stand 2026-11-26. Konzept, Phasen und offene Entscheidungen:
[../plans/signage.plan.md](../plans/signage.plan.md).

## Was es gibt

- Gerüst des Pakets, Importer für Hotfolder des Altstands
  (`src/Import/`, `bin/import-hotfolder.php`).
- Noch nicht: Templates, Player, Export, Ziele (Plan §9).

Projektbezogener Stand (Sites, Inhalte) steht im jeweiligen Projekt, nicht
hier.

## Projekt einrichten

- Eine Installation je **Site** (`docs/multi-site.md` in system). Default-Site
  = Übersicht, jede Installation eine Site mit Knoten.
- `composer require systopic/signage` (auf DEV per Path-Repo eine Junction auf
  `packages/signage`).
- `config/config.json`: Mediengruppen `slides`, `video`, `document`,
  `background` (`packages.cms.media.groups`).
- DEV: `packages.cms.siteAddress` = `/projects(\.[a-z]+)?/{site}/` in
  `config.local.json`. Fehlt der Eintrag, leitet eine Site auf
  `http://<site>/…` um.
- DEV braucht je Site eine Junction `projects/<site>` → Projektordner
  (CMS › Seiten › Site › „Create site link“ oder `mklink /J`).
- Templates liegen vorerst im Projekt (cms kennt noch keine Paket-Templates,
  Plan §3/E5): `templates/pages/{100_signage_start,200_signage_topic,300_signage_section,400_signage_playlist}`
  und `templates/document/body.tpl.php` mit `$this->withSelected('main')`.

### Inhaltsmodell

| Template | Felder | Mediengruppen |
|---|---|---|
| `signage_start` | `text01` Überschrift, `html01` Zeilen, `text02` Countdown `Y-m-d H:i:s` | `background` |
| `signage_topic` | Label = Titel, `text02` Rasterplatz `4x2`, `text03` Flags (`A` Autoplay, `H` hervorgehoben) | `document` (PDF), `video` (MP4), `slides` (Bilder) |
| `signage_section` | wie Topic, Kinder = Kacheln | — |
| `signage_playlist` | `text02` Schlüssel (`signage.player.screensaverFolder`) | — |

Site-Meta `signage.player`: `screensaverTimeout`, `screensaverFolder`,
`screensaverShowMessage`, `secondsPerSlide`, `secondsPerImage` (aus `config.txt`).

## Hotfolder übernehmen

```bash
cd <projekt>
php vendor/systopic/signage/bin/import-hotfolder.php <altstand>/var/media <site> --dry
php vendor/systopic/signage/bin/import-hotfolder.php <altstand>/var/media <site>
```

- Hotfolder-Format: `4x2A_Titel.pdf` (Rasterplatz + Flags `R` Rubrik,
  `A` Autoplay, `H` hervorgehoben), Bildordner = Galerie, `screensaver_*` =
  Playlist, `Welcome.txt` (cp1252), `config.txt`.
- Nimmt nur eine Site ohne Seiten (sonst doppelt). Kopiert die Dateien, der
  Hotfolder bleibt unverändert. Große PDFs brauchen für die Vorschaubilder
  Minuten.
- Datenbank und `public/var/` reisen nicht mit Git: auf einem anderen
  Arbeitsplatz Backup/Restore im CMS oder den Import erneut laufen lassen.

## Fallen

| Symptom | Ursache | Lösung |
|---|---|---|
| `index.php '<json>' <callable> …` tut nichts, Ausgabe JSON | `AgentCli` fügt alle Argumente zum JSON zusammen, die alte CLI-Strecke von `loader.php` ist nie erreichbar | Skript startet `public/index.php` mit sich selbst als `auto_prepend_file` und hängt sich an `afterConnect` (`bin/import-hotfolder.php`) |
| `Please run "composer install"` aus einem eigenen Skript | `index.php` nimmt die **erste** eingebundene Datei als Einstieg des Projekts | `public/index.php` muss das Hauptskript sein (`auto_prepend_file`) |
| Import bricht nach 60 s ab (`fsFileOperator_imagick`) | `max_execution_time` aus der Config, unter Windows Wanduhrzeit | `set_time_limit(0)` im CLI-Werkzeug |
| Seite rendert, `<body>` leer | Projekt ohne `templates/document/body.tpl.php` | Body mit `$this->withSelected('main')` |
| Default-Site ohne Seiten: leere 200 | „no pages yet“ kommt nur, solange gar keine Seite existiert | offen (cms) |
