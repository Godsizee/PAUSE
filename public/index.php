<?php
// public/index.php

// Diese Datei leitet alle Anfragen an den Haupt-Controller im übergeordneten Verzeichnis weiter.
// Dies ist eine bewährte Sicherheitspraxis, da der Webserver-Root nur auf diesen
// Ordner zeigen sollte und der Anwendungscode somit geschützt ist.

// KORREKTUR: Der Pfad geht eine Ebene nach oben (../), um init.php zu finden.
require_once __DIR__ . '/../init.php';

// Die Hauptanwendungslogik wird von der index.php im Projektstammverzeichnis behandelt.
// KORREKTUR: Auch dieser Pfad muss eine Ebene nach oben gehen.
require_once __DIR__ . '/../index.php';