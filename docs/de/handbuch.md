# RadioRing – Handbuch

Willkommen bei RadioRing. Mit RadioRing planst und betreibst du deinen eigenen
Radiosender: Musik und Jingles verwalten, Playlisten bauen, ein Wochenprogramm
zusammenstellen und den fertigen Stream live an Icecast oder laut.fm ausspielen –
inklusive Live-Übernahme per Mikrofon/Encoder.

Dieses Handbuch führt dich in der Reihenfolge durch die App, in der du sie auch
benutzt: von der Station über die Medien und Playlisten bis zur Programmplanung
und zum Senden.

---

## Inhalt

1. [Grundbegriffe](#1-grundbegriffe)
2. [Erste Schritte](#2-erste-schritte)
3. [Medienbibliothek](#3-medienbibliothek)
4. [Playlisten](#4-playlisten)
5. [Externe Quellen](#5-externe-quellen)
6. [Programmplanung: Wochenraster & Rundown](#6-programmplanung-wochenraster--rundown)
7. [Streaming: Ausgänge & Live-Eingang](#7-streaming-ausgänge--live-eingang)
8. [Dashboard: Senden & Steuern](#8-dashboard-senden--steuern)
9. [Protokoll](#9-protokoll)
10. [Team & Stationsverwaltung](#10-team--stationsverwaltung)
11. [Administration](#11-administration)
12. [Typische Abläufe (Spickzettel)](#12-typische-abläufe-spickzettel)
13. [Fehlersuche & FAQ](#13-fehlersuche--faq)

---

## 1. Grundbegriffe

Ein paar Begriffe ziehen sich durch die ganze App:

| Begriff | Bedeutung |
|---|---|
| **Station** | Dein Radiosender. Alle Medien, Playlisten und Einstellungen hängen an einer Station. Du kannst mehrere Stationen besitzen (bis zu deinem Kontingent). |
| **Medienbibliothek** | Der Pool aller Musikstuecke und Jingles. Gehoert deinem **Konto**, nicht einer einzelnen Station: alle deine Stationen nutzen dieselbe Bibliothek. |
| **Playlist** | Eine wiederverwendbare Bausteinliste (z. B. „Vormittag Pop"), die du in das Wochenraster einhängst. |
| **Wochenraster** | Wochenplan mit 7 Tagen × 24 Stunden. Jeder Stundenslot bekommt eine Playlist. |
| **Rundown** | Die konkrete, für eine bestimmte Stunde an einem bestimmten Tag *ausgewürfelte* Abspielliste. Wird aus dem Slot + der Playlist erzeugt und „eingefroren". |
| **Ausgang** | Das Ziel, an das gesendet wird (Icecast-Server oder laut.fm). |
| **Container** | Der pro Station laufende Liquidsoap-Prozess, der den Stream tatsächlich produziert. |

Der grobe Datenfluss:

```
Medien  ─┐
         ├─►  Playlist  ─►  Wochenraster-Slot  ─►  Rundown  ─►  Container  ─►  Ausgang (Icecast/laut.fm)
Tags  ───┘
```

---

## 2. Erste Schritte

### Anmeldung & Registrierung

RadioRing ist ein geschlossenes System: Die Registrierung ist nur mit einem
**Einladungscode** möglich. Hast du keinen, wende dich an einen Administrator
(siehe [Administration](#11-administration)).

Optional kannst du in den **Einstellungen** (Profilmenü unten links → *Settings*)
die **Zwei-Faktor-Authentifizierung (2FA)** aktivieren.

### Station erstellen oder auswählen

- Beim ersten Login ohne Station landest du direkt im Dialog **Radiostation
  erstellen**. Gib einen Namen ein – der technische Kurzname (Slug) wird daraus
  automatisch und eindeutig gebildet.
- Hast du Zugriff auf mehrere Stationen, erscheint die **Stationsauswahl**. Die
  zuletzt gewählte Station bleibt aktiv, bis du wechselst.
- Eine einzelne Station wird automatisch ausgewählt.

> **Hinweis:** Du kannst nur so viele Stationen anlegen, wie dein Kontingent
> erlaubt. Ist es erschöpft, meldet das Erstellungsformular das beim Speichern.

### Die Navigation

Die Seitenleiste ist in Blöcke gegliedert:

- **Dashboard** – Live-Status & Steuerung
- **Playlisten**, **Medienbibliothek**, **Externe Quellen** – Inhalte
- **PROGRAMMPLANUNG**: Wochenraster, Rundown
- **STREAMING**: Ausgänge, Protokoll
- **ADMINISTRATION** (nur für Admins): Nutzer, Einladungscodes

---

## 3. Medienbibliothek

Die Medienbibliothek ist der Vorrat, aus dem sich Playlisten und der
Zufalls-/Auffüll-Mechanismus bedienen.

### Dateien hochladen

1. Klicke auf **Hochladen**.
2. Ziehe deine Dateien in den Upload-Bereich oder wähle sie aus.
   Unterstützt werden **MP3, M4A, OGG, WAV, FLAC**.
3. Die Dateien werden in Teilstücken (Chunks) übertragen – auch große Dateien
   und viele Dateien auf einmal sind kein Problem.
4. Vor dem Speichern kannst du je Datei **Titel**, **Interpret** und den **Typ**
   (Musik oder Jingle) prüfen. Titel und Interpret werden, wenn vorhanden, aus
   den ID3-Tags vorbefüllt.
5. **Speichern** legt die Dateien in der Bibliothek an.

Nach dem Upload wird die **Lautheit (LUFS)** jeder Datei einmalig im Hintergrund
gemessen und für eine gleichmäßige Aussteuerung normalisiert. Das passiert
automatisch – du musst nichts tun.

### Metadaten bearbeiten

Über das Bearbeiten-Symbol einer Datei änderst du **Titel**, **Interpret** und
**Album**. Zusätzlich gibt es die Option **Fade-In**: Ist sie aktiv, wird die
Datei beim Start sanft eingeblendet (nützlich z. B. bei Aufnahmen mit hartem
Anfang).

### Tags

Tags sind frei wählbare Schlagworte (z. B. *Sommer*, *Ruhig*, *90er*, *Station-ID*),
mit denen du Musik gruppierst. Sie sind die Grundlage für **Zufalls-** und
**Auffüll-Elemente** in Playlisten.

- **Tags verwalten**: Tags anlegen und löschen.
- Einer Datei Tags zuweisen: über das Tag-Symbol der Datei.
- **Mehrfachauswahl**: Markiere mehrere Dateien und füge per *Massenaktion* einen
  Tag hinzu oder entferne ihn. „Alle sichtbaren auswählen" erleichtert das.

### Filtern & Suchen

Du kannst die Liste nach **Typ** (Musik/Jingle), nach **Tag** (oder „ohne Tag")
und per Freitextsuche (**Titel/Interpret**) einschränken.

### Duplikate finden

Der Filter **Duplikate** zeigt nur Dateien, die nach normalisiertem
*Interpret + Titel* mehrfach vorkommen – praktisch, um versehentlich doppelt
hochgeladene Stücke aufzuräumen. Duplikate stehen direkt untereinander.

### Gemeinsame Bibliothek mehrerer Stationen

Die Medienbibliothek gehoert deinem **Konto**, nicht einer einzelnen Station. Betreibst du
mehrere Stationen, sehen alle dieselben Dateien. Ein Titel, den du ueber eine Station
hochlaedst, ist sofort in allen nutzbar, ohne etwas zu verlinken oder zu kopieren.

Dasselbe gilt fuer Tags: ein in einer Station angelegter Tag steht in allen zur Verfuegung.

### Wer darf was aendern

| Rolle | Bibliothek |
|---|---|
| **Besitzer** | hochladen, bearbeiten, taggen, loeschen |
| **Bearbeiter** | hochladen, bearbeiten, taggen. **Kein** Loeschen. |

Loeschen entfernt eine Datei aus allen Stationen des Kontos, deshalb bleibt es dem
Besitzer vorbehalten.

---

## 4. Playlisten

Eine Playlist ist eine wiederverwendbare Vorlage. Sie wird nicht direkt gesendet,
sondern in das Wochenraster eingehängt und dort pro Stunde zu einem Rundown
„ausgewürfelt".

### Playlist anlegen

Unter **Playlisten → Neue Playlist** vergibst du:

- **Name**
- **Abspielmodus**:
  - **Sequenziell** – Elemente in der festgelegten Reihenfolge.
  - **Zufällig** – Reihenfolge wird bei der Rundown-Erzeugung gemischt.
- **Startmodus**:
  - **Weich (soft)** – nahtloser Übergang/Crossfade vom vorherigen Programm.
  - **Hart (hard)** – exakter Start zur vollen Stunde; der laufende Track wird
    dafür sauber abgeschnitten. Ideal für Nachrichten o. Ä., die pünktlich
    beginnen müssen.

> Der Startmodus liegt an der **Playlist**, nicht am Rasterslot. Wenn du eine
> Playlist also überall „hart" auf die volle Stunde legen willst, stellst du das
> einmal an der Playlist ein.

### Elemente hinzufügen

Im Playlist-Manager fügst du über **Element hinzufügen** verschiedene Bausteine
ein:

| Typ | Beschreibung |
|---|---|
| **Aus Bibliothek** | Ein konkretes Musikstück oder Jingle aus deiner Bibliothek (mit Suche). |
| **Datei hochladen** | Direkt eine neue Datei hochladen – sie landet zugleich in der Bibliothek. |
| **Zufälliges Element** | Beim Erzeugen des Rundowns wird **ein** zufälliger Titel gezogen – optional aus bestimmten **Tags**. |
| **Auffüllen mit Musik** | Füllt die Stunde mit zufälliger Musik (optional nach Tags), bis eine **maximale Dauer** erreicht ist. Gut, um Stunden ohne festes Skript komplett zu füllen. |
| **URL / Stream** | Eine externe Audiodatei oder ein Stream per URL (mit optionaler Dauer). |
| **Externe Quelle** | Eine zuvor definierte dynamische Quelle (Nachrichten, Wetter, Syndication – siehe [Externe Quellen](#5-externe-quellen)). |
| **Werbeunterbrechung** | Ein Marker (`START_AD_BREAK`) für eine laut.fm-Werbeunterbrechung. |

### Reihenfolge, Offsets & Bearbeiten

- **Sortieren**: Elemente lassen sich per Drag & Drop umordnen.
- **Relativer Offset (MM:SS)**: Optionaler zeitlicher Versatz eines Elements
  innerhalb der Stunde – z. B. „dieser Beitrag soll möglichst bei 30:00 laufen".
  Du kannst MM:SS oder reine Sekunden eingeben.
- **Auffüllen/Zufall bearbeiten**: Für Auffüll- und Zufallselemente lassen sich
  Tags und (beim Auffüllen) die Maximaldauer (60–7200 s) nachträglich ändern.

---

## 5. Externe Quellen

Externe Quellen sind dynamische Inhalte, die kurz vor der Ausspielung frisch
geholt werden – etwa Nachrichten, Wetter oder syndizierte Beiträge. Du legst sie
einmal als wiederverwendbare Bibliotheks-Einträge an und verwendest sie dann als
Playlist-Element vom Typ *Externe Quelle*.

### Quelle anlegen

Unter **Externe Quellen → Neue Quelle**:

- **Name** – Anzeigename (erscheint später als Playlist-Element).
- **Art**:
  - **URL** – feste Audio-URL (Pflichtfeld *URL*).
  - **Nachrichten**, **Wetter**, **Nachrichten + Wetter** – dynamisch erzeugte
    Inhalte.
- **Erwartete Dauer** (optional) – Richtwert für die Programmplanung.
- **Vorlauf (Prefetch)** in Sekunden – wie lange **vor** der Ausspielung der
  Inhalt geholt und vorbereitet wird (Standard 180 s). Größer = mehr Puffer,
  aber weniger „aktuell".
- **Aktualität (Freshness)** in Sekunden – wie lange ein bereits geholter Inhalt
  wiederverwendet werden darf, bevor neu geladen wird (0 = jedes Mal neu).
- **Normalisieren** – Lautheit angleichen (empfohlen).
- **Stille am Anfang abschneiden** – führende Stille entfernen.
- **Fade-In** – sanft einblenden.

Vor der Ausspielung wird der Inhalt heruntergeladen, normalisiert und lokal
zwischengespeichert. Steht zur Sendezeit nichts bereit, greift ein Fallback,
damit keine Lücke entsteht.

---

## 6. Programmplanung: Wochenraster & Rundown

Hier wird aus Playlisten ein echter Sendeplan.

### Wochenraster

Das **Wochenraster** ist ein Gitter aus 7 Wochentagen × 24 Stunden. Jeder
Stundenslot kann eine Playlist bekommen.

- **Slot belegen**: Slot anklicken und eine Playlist zuweisen.
- **Mehrere Slots auf einmal**: Mehrere Zellen markieren und gemeinsam eine
  Playlist zuweisen oder leeren.
- Leere Slots senden in dieser Stunde nichts Geplantes.

Jeder Slot zeigt zusätzlich an, ob für die kommende Ausstrahlung bereits ein
**Rundown** erzeugt wurde.

### Rundowns erzeugen

Ein Rundown ist die konkrete, eingefrorene Abspielliste für *eine bestimmte
Stunde an einem bestimmten Datum*. Erst beim Erzeugen werden Zufalls- und
Auffüll-Elemente real „ausgewürfelt".

- **Einzeln**: Direkt am Slot den Rundown für die nächste passende Ausstrahlung
  generieren.
- **Mehrere**: Über das Generieren-Panel mehrere Wochentage auswählen und alle
  konfigurierten Slots dieser Tage auf einmal erzeugen. Du bekommst ein Protokoll
  mit *erzeugt / übersprungen / Fehler* je Stunde.
- **Nächtlich automatisch**: In den [Stationseinstellungen](#10-team--stationsverwaltung)
  lässt sich „Rundowns nächtlich neu generieren" aktivieren.

### Rundown-Detailansicht

Über **Rundown** (oder einen Slot) öffnest du die Detailansicht einer Stunde. Dort
kannst du:

- den Rundown **neu generieren** (solange er noch nicht gespielt wurde),
- einzelne **Tracks entfernen**,
- einen Track gegen einen anderen aus der Bibliothek **ersetzen**.

> **Wichtig:** Tracks, die bereits gesendet wurden **oder gerade laufen bzw. schon
> vorgeladen sind**, sind gesperrt und können nicht mehr geändert werden. Das
> verhindert, dass dir der Stream „unter den Händen" wegbricht. Ein bereits
> vollständig gespielter Rundown lässt sich nicht mehr neu generieren.

---

## 7. Streaming: Ausgänge & Live-Eingang

### Ausgänge

Ein **Ausgang** ist das Ziel, an das dein Stream gesendet wird. Unter **Ausgänge**
legst du einen oder mehrere an:

- **Typ**: **Icecast** oder **laut.fm**.
- **Host**, **Port**, **Mountpoint**
- **Benutzername** (Standard `source`) und **Passwort**
  (das Passwort wird aus Sicherheitsgründen beim Bearbeiten nie angezeigt; leer
  lassen = unverändert).
- **Bitrate**: 64 / 96 / 128 / 192 / 256 / 320 kbit/s.
- **Aktiv**: Nur aktive Ausgaenge werden bespielt.

Erwartet dein Anbieter Parameter am Mountpoint, etwa `/station?prio=3`, trage sie so ein.
RadioRing nutzt fuer Zugangsdaten den reinen Mount-Namen und gibt den vollen Wert an den
Stream weiter.

> **Nach jeder Änderung an Ausgängen musst du den Container neu starten** (siehe
> Dashboard), damit das neue Sende-Script geladen wird. Die App weist dich darauf
> hin.

### Live-Eingang (Live-Übernahme)

Jede Station hat einen eigenen **Live-Eingang**, über den du das laufende Programm
per Encoder/Mikrofon (z. B. BUTT, mixxx, OBS) übernehmen kannst – etwa für
Moderation oder Live-Sendungen.

Die Zugangsdaten findest du auf dem **Dashboard**:

- **Host**: `{slug}.<stream-domain>`
- **Port**: stationseigener Port
- **Mountpoint**: i. d. R. `/live`
- **Benutzername**: `source`
- **Passwort**: stationsindividuell (automatisch erzeugt)

Sobald sich ein Live-Encoder verbindet, schaltet der Stream automatisch auf den
Live-Input um; trennt er sich, läuft das geplante Programm weiter. Der aktuelle
Live-Status wird auf dem Dashboard angezeigt.

---

## 8. Dashboard: Senden & Steuern

Das Dashboard ist die Schaltzentrale für den laufenden Betrieb.

### Container steuern

- **Starten** – startet den Liquidsoap-Container der Station; der Stream geht auf
  Sendung.
- **Stoppen** – beendet den Container.
- **Neu starten** – lädt das Sende-Script neu. Notwendig nach Änderungen an
  **Ausgängen** und ähnlichen Grundeinstellungen.

> Voraussetzung: Die Container-Steuerung muss serverseitig konfiguriert sein. Ist sie
> das nicht, weist das Dashboard darauf hin, statt zu handeln.

### „Jetzt läuft" & Fortschritt

Das Dashboard zeigt den aktuell laufenden Titel mit Interpret, Fortschrittsbalken
und – sofern verfügbar – die Position des Tracks im Rundown. Während einer
Live-Übernahme erscheint stattdessen der Live-Status.

### Track überspringen

**Nächster Track** überspringt den aktuell laufenden Titel sauber. RadioRing
sorgt dabei dafür, dass tatsächlich nur ein Schritt weitergesprungen wird (und
nicht mehrere, weil intern bereits Titel vorgeladen wurden).

---

## 9. Protokoll

Das **Protokoll** ist das Sendetagebuch der Station. Es zeigt zeitlich sortiert,
was gelaufen ist:

- gespielte **Playlist-Tracks**,
- **Live-Tracks** (während einer Live-Übernahme),
- **Live-An/Aus**-Wechsel,
- **Rundown-Erzeugungen**.

Du kannst nach **Datum**, **Ereignisart** und per Freitext (**Titel/Interpret**)
filtern. Das ist praktisch für Nachweise (z. B. GEMA/GVL-Meldungen) und zur
Fehlersuche („Was lief gestern um 14 Uhr?").

---

## 10. Team & Stationsverwaltung

Über **Station bearbeiten** (nur als Besitzer) verwaltest du:

- **Name** der Station.
- **Status**: *Aktiv* oder *Pausiert*.
- **Rundowns nächtlich neu generieren**: automatische Vorbereitung des Programms.
- **Team**: Weitere Nutzer per **E-Mail-Adresse** als **Bearbeiter (editor)**
  hinzufügen. Bearbeiter dürfen Inhalte pflegen; die Stationsverwaltung (diese
  Seite, Team, Löschen) bleibt dem **Besitzer** vorbehalten.
- **Station löschen**: Entfernt die Station unwiderruflich.

**Rollen kurz:**

- **Besitzer (owner)** - voller Zugriff inkl. Verwaltung, Team und Loeschen.
- **Bearbeiter (editor)** - darf Medien, Playlisten, Raster, Rundowns und Ausgaenge
  pflegen, aber keine Medien loeschen und die Station nicht verwalten.

> Zur gemeinsamen Bibliothek: Wen du als Bearbeiter in **eine** Station einlaedst, der
> erhaelt damit auch Zugriff auf die Medienbibliothek deines **Kontos**, also auch auf
> Material deiner anderen Stationen. Loeschen kann er nicht.

---

## 11. Administration

Nur für Nutzer mit Admin-Rechten sichtbar (Block **ADMINISTRATION**).

- **Nutzer**: Konten einsehen und verwalten.
- **Einladungscodes**: Einmal-Codes erzeugen, mit denen sich neue Nutzer registrieren
  koennen. Ohne gueltigen Code ist keine Registrierung moeglich.
- **Instanz-Einstellungen**: den Betriebsmodus zwischen *standalone* und *cloud*
  umschalten. Die Aenderung wirkt sofort, ohne erneutes Deployment.

Welche Bedienelemente erscheinen, haengt vom Betriebsmodus ab. Im **Standalone**-Modus
entfallen Stations-Quota, Impersonation und das Sperren von Konten, weil eine Installation
mit einem einzigen Mandanten sie nicht braucht.

---

## 12. Typische Abläufe (Spickzettel)

**Sender von Null aufsetzen**

1. Station erstellen.
2. Musik & Jingles in die **Medienbibliothek** hochladen.
3. Mit **Tags** grob ordnen (z. B. Stimmung/Genre/Station-IDs).
4. Eine oder mehrere **Playlisten** bauen (Bibliotheks-, Zufalls-, Auffüll- und
   Jingle-Elemente kombinieren).
5. Playlisten im **Wochenraster** auf Stundenslots legen.
6. **Rundowns** für die nächsten Tage generieren.
7. Unter **Ausgänge** dein Icecast/laut.fm-Ziel eintragen.
8. Auf dem **Dashboard** den Container **starten**.

**Pünktliche Nachrichten zur vollen Stunde**

1. Externe Quelle vom Typ *Nachrichten* (oder URL) anlegen.
2. Eigene Playlist „Nachrichten" mit diesem Element, **Startmodus = Hart**.
3. Diese Playlist in die entsprechenden Stundenslots legen.
4. Rundowns generieren.

**Änderung an einem Ausgang übernehmen**

1. Ausgang bearbeiten/aktivieren.
2. Dashboard → **Container neu starten**.

---

## 13. Fehlersuche & FAQ

**Es kommt kein Ton / der Stream läuft nicht.**
Prüfe auf dem Dashboard, ob der Container läuft. Falls nicht: **Starten**. Prüfe
außerdem, ob ein **aktiver Ausgang** mit korrekten Zugangsdaten existiert.

**Änderung am Ausgang wirkt nicht.**
Ausgänge werden erst beim **Container-Neustart** wirksam – nicht automatisch.

**Eine Stunde bleibt stumm / „leer".**
Vermutlich ist der Stundenslot im **Wochenraster** nicht belegt oder es wurde
kein **Rundown** generiert. Slot belegen und Rundown erzeugen.

**Ich kann einen Track im Rundown nicht entfernen/ersetzen.**
Er wurde bereits gespielt, läuft gerade oder ist schon vorgeladen – solche Tracks
sind gesperrt. Ändere stattdessen spätere Tracks.

**Zufalls-/Auffüll-Element bringt nichts.**
Stelle sicher, dass es Mediendateien mit den passenden **Tags** gibt. Ohne
passende Treffer kann nichts gezogen bzw. aufgefüllt werden.

**Ich kann keine weitere Station anlegen.**
Dein Stationskontingent ist erschoepft. Wende dich an einen Administrator. Im
Standalone-Modus gibt es kein Kontingent.

**Wo sehe ich, was gelaufen ist?**
Im **Protokoll**, gefiltert nach Datum/Ereignis.

**Wie übernehme ich live?**
Verbinde deinen Encoder mit den Live-Zugangsdaten vom Dashboard. Der Stream
schaltet automatisch um und nach dem Trennen zurück aufs Programm.