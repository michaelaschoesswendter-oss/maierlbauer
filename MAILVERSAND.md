# Anfrageformular: Mailversand auf CableLink

Das Anfrageformular sendet seine Daten an `api/send-inquiry.php`. Der PHP-Endpunkt nutzt den CableLink-SMTP-Server (`smtp.cablelink.at`, Port 587, STARTTLS) und leitet die Anfrage an `info@maierlbauer.at` weiter. Die Mailbox ist auch die Absenderadresse; Antworten gehen über `Reply-To` direkt an die anfragende Person.

## Einrichtung am Webspace

1. Die Vite-Seite bauen und den Inhalt von `dist` in den CableLink-Webspace hochladen. Dabei müssen `api/send-inquiry.php` und `api/.htaccess` mit hochgeladen werden.
2. `api/mail-config.example.php` am Webspace als `api/mail-config.php` ablegen.
3. In `api/mail-config.php` das CableLink-Mailboxpasswort für `info@maierlbauer.at` eintragen. Die Datei ist in `.gitignore` ausgeschlossen und der Zugriff über den Webserver wird durch `.htaccess` gesperrt. Niemals diese echte Konfigurationsdatei committen oder öffentlich teilen.
4. Für den produktiven Versand muss PHP auf dem Webspace aktiviert sein. Das Anfrageformular zeigt bei Server- oder Versandfehlern einen Hinweis mit der E-Mail-Adresse als Alternative.

Die lokale Vite-Entwicklungsseite kann PHP nicht ausführen. Der Versand funktioniert nach dem Upload auf den PHP-fähigen Webspace und Einrichtung der Mailkonfiguration.
