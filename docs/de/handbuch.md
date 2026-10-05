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
| **Medienbibliothek** | Der Pool aller Musikstuecke, Jingles und Voicetracks. Gehoert deinem **Konto**, nicht einer einzelnen Station: alle deine Stationen nutzen dieselbe Bibliothek. |
| **Playlist** | Eine wiederverwendbare Bausteinliste (z. B. „Vormittag Pop"), die du in das Wochenraster einhängst. |
| **Wochenraster** | Wochenplan mit 7 Tagen × 24 Stunden. Jeder Stundenslot bekommt eine Playlist. |
| **Rundown** | Die konkrete, für eine bestimmte Stunde an einem bestimmten Tag *ausgewürfelte* Abspielliste. Wird aus dem Slot + der Playlist erzeugt und „eingefroren". |
| **Ausgang** | Das Ziel, an das gesendet wird (Icecast-Server oder laut.fm). |
| **Stationscontainer** | Der pro Station laufende Liquidsoap-Prozess, der den Stream tatsächlich produziert. Im Dashboard kurz „Container“. |
| **Container (Playlist)** | Ein wiederverwendbarer Block aus Elementen, z. B. Jingle + Nachrichten, den du in Playlisten einsetzt. Hat mit dem Stationscontainer nichts zu tun. |

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
4. Vor dem Speichern kannst du je Datei **Titel**, **Interpret**, **Album** und den
   **Typ** (Musik, Jingle oder Voicetrack) prüfen. Titel, Interpret und Album werden,
   wenn vorhanden, aus den ID3-Tags vorbefüllt.
5. Optional wählst du **Tags**, die alle Dateien dieses Uploads bekommen. Ein noch
   nicht vorhandener Tag lässt sich direkt im Formular anlegen.
6. **Speichern** legt die Dateien in der Bibliothek an.

Nach dem Upload wird die **Lautheit (LUFS)** jeder Datei einmalig im Hintergrund
gemessen und für eine gleichmäßige Aussteuerung normalisiert. Das passiert
automatisch – du musst nichts tun.

### Medientypen

- **Musik** – das, woraus Auffüll-Elemente die Stunde füllen.
- **Jingle** – Station-IDs, Trenner, Trailer. Wird nur gespielt, wenn du ihn in eine
  Playlist setzt oder ein Zufallselement ihn zieht.
- **Voicetrack** – eine vorab aufgenommene Moderation. Technisch eine normale
  Audiodatei, aber im Rundown und auf dem Dashboard violett mit Mikrofon markiert,
  damit du siehst, wo jemand spricht. Ein Voicetrack ist für eine bestimmte Stelle
  aufgenommen: Zufallselemente ziehen ihn deshalb nie, du setzt ihn selbst in die
  Playlist.

### Der Datei-Dialog

Ein Klick auf den Titel einer Datei (oder auf das Stift-Symbol) öffnet den Dialog, in dem
alles zu dieser Datei bearbeitet wird:

- **Titel**, **Interpret**, **Album** und **Typ**.
- **Fade-In**: Die Datei wird beim Start sanft eingeblendet (nützlich z. B. bei
  Aufnahmen mit hartem Anfang).
- **Notizen** und **Tags**.
- **Laufzeit** und **Sendefenster** (siehe unten).
- **Datei ersetzen** (nur Besitzer): Eine neue Fassung tritt an die Stelle der alten. Playlisten, Tags
  und Metadaten bleiben, wie sie sind. Bereits generierte Rundowns spielen die alte
  Fassung zu Ende, bis du sie neu generierst. Frühere Fassungen lassen sich
  wiederherstellen und werden automatisch entfernt, sobald kein Rundown mehr auf sie
  verweist.

Titel, Interpret und Album werden beim Speichern auch **in die Datei zurückgeschrieben**
(MP3, FLAC, OGG, M4A), ebenso die Werte aus dem Upload-Formular. Das Audio selbst wird
dabei nicht verändert, Cover und andere Tags bleiben erhalten. Lädst du eine Datei
später herunter oder spielst sie in einem anderen Programm, hat sie dieselben Angaben
wie im Panel. WAV-Dateien werden nicht angefasst.

### Laufzeit und Sendefenster

Beides begrenzt, wann **Auffüll- und Zufallselemente** eine Datei wählen dürfen.
Elemente, die du selbst in eine Playlist setzt, laufen immer.

- **Laufzeit**: ein optionaler Beginn und ein optionales Ende, jeweils mit Datum und
  Uhrzeit. Beispiele: ein Weihnachtsjingle nur vom 1. bis 26. Dezember, ein Trailer, der
  heute um 20 Uhr abläuft. Der Beginn zählt mit, das Ende nicht. In der Bibliothek
  erscheint die Laufzeit als Badge, abgelaufene Dateien sind rot markiert.
- **Sendefenster**: Wochentage plus eine Uhrzeit von/bis, bei Bedarf mehrere. Ein
  Fenster darf über Mitternacht laufen. Beispiel: ein „Guten Morgen“-Jingle nur
  werktags von 6 bis 10 Uhr.

Hat eine Datei beides, müssen Laufzeit **und** ein Sendefenster passen. Geprüft wird der
geplante Sendezeitpunkt, nicht der Moment der Rundown-Erzeugung.

### Tags

Tags sind frei wählbare Schlagworte (z. B. *Sommer*, *Ruhig*, *90er*, *Station-ID*),
mit denen du Musik gruppierst. Sie sind die Grundlage für **Zufalls-** und
**Auffüll-Elemente** in Playlisten.

- **Tags verwalten**: Tags anlegen, umbenennen (Stift-Symbol am Tag) und löschen.
  Zufalls- und Auffüll-Elemente merken sich Tags intern, ein neuer Name ändert also
  nichts daran, was sie auswählen.
- Einer Datei Tags zuweisen: im Datei-Dialog oder schon beim Upload.
- **Mehrfachauswahl**: Markiere mehrere Dateien und füge per *Massenaktion* einen
  Tag hinzu oder entferne ihn. Die Auswahl bleibt beim Blättern erhalten.

### Filtern & Suchen

Du kannst die Liste nach **Typ** (Musik, Jingle, Voicetrack), nach **Tag** (oder
„ohne Tag") und per Freitextsuche (**Titel/Interpret**) einschränken.

Die Bibliothek zeigt **50 Dateien pro Seite**. Ändert sich ein Filter oder die Suche,
geht es wieder auf Seite 1. Bei mehreren Seiten markiert **Seite auswählen** nur die
aktuelle Seite; danach erscheint **Alle *n* Treffer auswählen**, um alle Dateien des
Filters auf einmal zu taggen.

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
| **Gründer**, **Besitzer** | hochladen, bearbeiten, taggen, ersetzen, loeschen |
| **Bearbeiter** | hochladen, bearbeiten, taggen. **Kein** Ersetzen und Loeschen. |

Loeschen und Ersetzen wirken in allen Stationen des Kontos, deshalb bleiben sie den
Besitzern vorbehalten.

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

Ob eine Stunde pünktlich beginnt oder etwas zu einer festen Minute läuft, regelst du mit
**Fixzeit-Elementen** in der Playlist (siehe unten).

### Elemente hinzufügen

Der Editor zeigt links die Playlist und rechts eine **Palette** mit vier Reitern:
**Medien**, **Container**, **Extern** und **Spezial**. Ein Suchfeld gilt für alle.

- **Ein Klick** hängt ein Element ans Ende an.
- **Mehrere ankreuzen** und als Block einfügen, in der Reihenfolge, in der du sie
  angekreuzt hast.
- **Ziehen** setzt ein Element direkt an die gewünschte Stelle.
- Im Reiter *Medien* lädst du über **Neu hochladen** auch direkt eine Datei hoch; sie
  landet zugleich in der Bibliothek.

| Typ | Beschreibung |
|---|---|
| **Datei aus der Bibliothek** | Ein konkretes Musikstück, ein Jingle oder ein Voicetrack. |
| **Zufälliges Element** | Beim Erzeugen des Rundowns wird **eine** Datei gezogen, optional nur aus bestimmten **Tags**. Bevorzugt Dateien, die länger nicht liefen. Voicetracks werden nie gezogen. |
| **Auffüllen mit Musik** | Füllt mit Musik (optional nach Tags) bis zur nächsten Fixzeit oder zur vollen Stunde, auf Wunsch begrenzt durch eine **maximale Dauer**. Die letzten Titel werden so gewählt, dass die Musik möglichst genau dort endet. |
| **URL / Stream** | Eine externe Audiodatei oder ein Stream per URL (mit optionaler Dauer). |
| **Externe Quelle** | Eine zuvor definierte dynamische Quelle (Nachrichten, Wetter, Syndication – siehe [Externe Quellen](#5-externe-quellen)). |
| **Container** | Ein wiederverwendbarer Block aus Elementen (siehe unten). |
| **Fixzeit** | Legt das Element dahinter auf eine Minute der Stunde fest, weich oder hart (siehe unten). |
| **Werbeunterbrechung** | Ein Marker (`START_AD_BREAK`) für eine laut.fm-Werbeunterbrechung. |

Auffüll- und Zufallselemente halten die **Rotationsregeln** ein: zuerst die
GVL-Wiederholungsregeln, dann ein Mindestabstand für denselben Titel, nie zweimal
hintereinander derselbe Interpret, der [Interpretenabstand](#10-team--stationsverwaltung)
der Station und möglichst nicht derselbe Titel zur selben Uhrzeit wie gestern. Reicht die
Bibliothek dafür nicht, wird trotzdem gefüllt und das Protokoll vermerkt es.

### Fixzeiten

Ein **Fixzeit**-Element legt das Element direkt dahinter auf eine Minute der Stunde fest
(z. B. 30:00).

- **Weich** (gelb): Ist die Zeit erreicht, startet keine weitere Auffüll-Musik. Der
  laufende Titel spielt zu Ende, dann folgt das Element.
- **Hart** (rot): Das Programm wird ausgeblendet und geschnitten, das Element startet
  auf die Sekunde. Für Nachrichten zur vollen Stunde setzt du eine harte Fixzeit
  `00:00` an den Anfang der Playlist.

Auffüll-Musik vor einer Fixzeit plant genau bis dorthin. Der Editor warnt, wenn das
Programm davor zu lang ist oder vor einem harten Schnitt eine Lücke lässt.

### Container

Ein Container ist ein wiederverwendbarer Block, z. B. Jingle + Nachrichten +
Werbeunterbrechung, den du in beliebig viele Playlisten setzt. Du legst ihn unter
**Playlisten → Neuer Container** an und bearbeitest ihn im selben Editor. Er kann alle
Elementtypen enthalten, auch Auffüll- und Zufallselemente. Beim Erzeugen des Rundowns wird
er an Ort und Stelle in seine Elemente aufgelöst. Container kommen nicht ins
Wochenraster und lassen sich nicht ineinander verschachteln.

### Reihenfolge & Bearbeiten

- **Sortieren**: Elemente lassen sich per Drag & Drop umordnen.
- **Duplizieren**: Jedes Element hat einen Duplizieren-Knopf, die Kopie landet direkt
  dahinter. Angekreuzte Elemente lassen sich gemeinsam duplizieren oder entfernen.
- **Laufzeit der Stunde**: Jedes Element zeigt seine Startzeit ab Beginn der Playlist,
  der Kopf die Gesamtlänge im Vergleich zur Stunde. Längen, die erst beim Senden
  feststehen (Auffüllen, Zufall), sind als solche markiert.
- **Auffüllen/Zufall bearbeiten**: Für Auffüll- und Zufallselemente lassen sich
  Tags und (beim Auffüllen) die Maximaldauer (60–7200 s) nachträglich ändern; bei einer
  Fixzeit die Minute (MM:SS) und weich/hart.

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

Jede Zeile zeigt die geplante Uhrzeit und ein Badge für die Herkunft (Vorlage, Fill,
Nachrichten, Werbung usw.). **Voicetracks** sind violett hinterlegt und mit einem Mikrofon
markiert, damit du auf einen Blick siehst, wo jemand spricht. Fixzeiten erscheinen in
ihrer Farbe.

> Ein Rundown ist eingefroren. Änderst du danach eine Playlist, eine Fixzeit, die
> Laufzeit oder ein Sendefenster einer Datei, wirkt das erst auf Stunden, die neu
> generiert werden.

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

Darunter folgen die nächsten Elemente mit voraussichtlicher Startzeit. Voicetracks
tragen dort ein violettes Badge mit Mikrofon.

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
- **Rundown-Erzeugungen**,
- **Underruns** (der Rundown lief leer, die Station sendete Stille),
- **fehlende externe Elemente** (nicht rechtzeitig vorbereitet, übersprungen),
- **Rotationsregeln**: ein Rundown verletzt die GVL-Wiederholungsregeln, meist weil der
  Musikpool zu klein ist,
- Beginn und Ende der **Notfallschleife**.

Du kannst nach **Datum**, **Ereignisart** und per Freitext (**Titel/Interpret**)
filtern. Das ist praktisch für Nachweise (z. B. GEMA/GVL-Meldungen) und zur
Fehlersuche („Was lief gestern um 14 Uhr?").

---

## 10. Team & Stationsverwaltung

Über **Station bearbeiten** (nur als Besitzer) verwaltest du:

- **Name** der Station.
- **Status**: *Aktiv* oder *Pausiert*.
- **Rundowns nächtlich neu generieren**: automatische Vorbereitung des Programms.
- **Interpretenabstand** (Standard 45 Minuten): Mindestabstand zwischen zwei Titeln
  desselben Interpreten bei Auffüll- und Zufallselementen. Zweimal hintereinander
  derselbe Interpret kommt ohnehin nie vor, und die GVL-Regeln gelten immer. `0`
  schaltet den Abstand ab.
- **Team**: Weitere Nutzer per **E-Mail-Adresse** hinzufügen. Neue Mitglieder sind
  **Bearbeiter**; die Rolle lässt sich jederzeit zwischen Bearbeiter und Besitzer
  umschalten.
- **Station löschen**: Entfernt die Station unwiderruflich (nur der Gründer).

**Rollen kurz:**

- **Gründer** - wer die Station angelegt hat. Darf alles, was ein Besitzer darf, und
  als Einziger die Station löschen. Seine Rolle lässt sich nicht ändern.
- **Besitzer (owner)** - voller Zugriff inkl. Verwaltung, Team, Medien ersetzen und
  löschen.
- **Bearbeiter (editor)** - darf Medien, Playlisten, Raster, Rundowns und Ausgaenge
  pflegen, aber keine Medien ersetzen oder loeschen und die Station nicht verwalten.

> Zur gemeinsamen Bibliothek: Wen du als Bearbeiter in **eine** Station einlaedst, der
> erhaelt damit auch Zugriff auf die Medienbibliothek deines **Kontos**, also auch auf
> Material deiner anderen Stationen. Loeschen kann er nicht.

### Notfallschleife

Das Stationsskript spielt zuerst eine Live-Übernahme, dann das Programm. Ist keines von
beiden verfügbar, etwa bei einem Update, einem Datenbankausfall oder einer Stunde, deren
Rundown zu früh endet, sendete die Station bisher Stille. Wähle unter **Station bearbeiten**
einige Dateien aus, dann laufen die stattdessen.

- Die Dateien liegen im Stationscontainer und spielen von dort, die Schleife läuft also
  auch weiter, wenn RadioRing selbst nicht erreichbar ist.
- Sie werden zufällig gewählt und wiederholen sich, so lange es nötig ist. Die gemessene
  Lautheit wird angewendet, die Schleife ist damit so laut wie das Programm.
- Sobald das Programm wieder verfügbar ist, übernimmt es und schneidet die Notfalldatei an
  der Stelle ab, an der sie gerade steht.
- Das Dashboard zeigt **NOTFALLSCHLEIFE**, solange sie auf Sendung ist, und das Protokoll
  hält Beginn und Rückkehr zum Programm fest.
- Anzahl und Gesamtgröße der Dateien sind begrenzt: der Container muss sie halten.
- Eine geänderte Auswahl greift innerhalb weniger Sekunden, ohne Neustart des Streams. Die
  Karte zeigt, wann der Container den Satz zuletzt geholt hat.
- Ohne Auswahl bleibt es beim alten Verhalten: Stille bei einer Störung.

Als Inhalt taugt alles: ein Jingle, eine gesprochene Ansage, ein Musikbett. Zwei oder drei
Dateien genügen.

### Alarm-Mails

Der Gründer und alle Besitzer bekommen eine Mail, wenn die Station

- Stille sendet,
- die Notfallschleife spielt,
- laufen soll, aber nichts spielt (Container abgestürzt oder Start fehlgeschlagen),
- für die laufende Stunde keinen Rundown hat.

Ist das Problem behoben, folgt eine zweite Mail. Probleme unter zwei Minuten lösen keine
Mail aus. Bearbeiter bekommen keine Alarme.

- Für die Station abschalten: **Station bearbeiten**, *Alarm-Mails an die Besitzer*.
- Für dich abschalten: **Einstellungen -> Profil**, *Alarm-Mails für meine Stationen*.

Gestoppte oder pausierte Stationen werden nicht überwacht. Mails kommen nur an, wenn ein
Administrator einen Mailserver eingerichtet hat (siehe Abschnitt 11).

---

## 11. Administration

Nur für Nutzer mit Admin-Rechten sichtbar (Block **ADMINISTRATION**).

- **Nutzer**: Konten einsehen und verwalten.
- **Einladungscodes**: Einmal-Codes erzeugen, mit denen sich neue Nutzer registrieren
  koennen. Ohne gueltigen Code ist keine Registrierung moeglich.
- **Instanz-Einstellungen**: den Betriebsmodus zwischen *standalone* und *cloud*
  umschalten. Die Aenderung wirkt sofort, ohne erneutes Deployment.
- **Ausgehende Mails** (in den Instanz-Einstellungen): SMTP-Server, Login und Absender für
  Alarm-Mails und Passwort-Resets. **Testmail senden** prüft die Werte im Formular schon
  vor dem Speichern und zeigt bei einem Fehler die Meldung des Mailservers. Solange
  *Diesen Mailserver verwenden* aus ist, gelten die `MAIL_*`-Werte aus der `.env`.
  *Stations-Mails mit eigenem Absender verschicken* sendet Alarme als
  `<slug>-noreply@<domain>` unter dem Stationsnamen. Nur einschalten, wenn dein Mailserver
  für jede Adresse dieser Domain senden darf.
- **Backups**: Sicherung der Konfiguration und der Datenbank, manuell oder jede Nacht,
  mit Aufbewahrungsgrenze und optionaler Passphrase. Das Archiv lässt sich hier
  herunterladen. Mediendateien sind bewusst nicht enthalten. Wiederherstellen läuft
  über die Kommandozeile, siehe `docs/de/betrieb.md`, Abschnitt 8.

**Update-Hinweis:** Admins sehen am Versions-Badge in der Seitenleiste, wenn ein neues
Release erschienen ist (auf dem Kanal `edge`: wenn `main` neue Commits hat). Ein Klick
darauf zeigt, was neu ist. Abschalten lässt sich die Prüfung in der `.env`, siehe
`docs/de/betrieb.md`.

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
2. In der Playlist ganz oben ein Element **Fixzeit** `00:00`, **hart**, dahinter die
   Nachrichtenquelle. Wer das in vielen Playlisten braucht, packt beides in einen
   **Container**.
3. Rundowns generieren.

**Weihnachtsjingle nur im Dezember**

1. Jingle hochladen, Tag *Jingles* vergeben.
2. Im Datei-Dialog die **Laufzeit** auf 1. Dezember bis 27. Dezember 0:00 setzen.
3. In den Playlisten ein **Zufälliges Element** mit Tag *Jingles* verwenden. Vor und
   nach der Laufzeit zieht es den Weihnachtsjingle nicht.

**Moderierte Stunde mit Voicetracks**

1. Moderationen als **Voicetrack** hochladen.
2. Playlist bauen: Voicetrack, **Auffüllen mit Musik**, **Fixzeit** (z. B. `15:00`,
   weich), nächster Voicetrack und so weiter. Die Musik plant sich bis zur Fixzeit.
3. Für jede Sendung eine eigene Playlist (oder vor dem Generieren die Voicetracks
   austauschen), denn Voicetracks sind für einen bestimmten Tag gesprochen.

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
passende Treffer kann nichts gezogen bzw. aufgefüllt werden. Prüfe außerdem
**Laufzeit** und **Sendefenster** der Dateien: Abgelaufene oder zu diesem Zeitpunkt
gesperrte Dateien werden übergangen. Auffüllen nimmt nur Musik, Zufall nie Voicetracks.

**Eine Änderung an Playlist, Laufzeit oder Sendefenster wirkt nicht.**
Bereits generierte Rundowns sind eingefroren. Generiere die betroffenen Stunden neu.

**Ich kann keine weitere Station anlegen.**
Dein Stationskontingent ist erschoepft. Wende dich an einen Administrator. Im
Standalone-Modus gibt es kein Kontingent.

**Wo sehe ich, was gelaufen ist?**
Im **Protokoll**, gefiltert nach Datum/Ereignis.

**Ich bekomme keine Alarm-Mails.**
Bitte einen Administrator, in den Instanz-Einstellungen eine Testmail zu senden. Prüfe
außerdem, ob Alarm-Mails in den Stationseinstellungen und in deinem Profil eingeschaltet
sind und ob du Besitzer der Station bist.

**Wie übernehme ich live?**
Verbinde deinen Encoder mit den Live-Zugangsdaten vom Dashboard. Der Stream
schaltet automatisch um und nach dem Trennen zurück aufs Programm.