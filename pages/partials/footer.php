</main> <?php // Closes <main class="page-wrapper"> from header.php ?>

    <footer class="site-footer">
        <div class="footer-content container">
            <div class="copyright">
                 &copy; <?php echo date('Y'); ?> <?php echo htmlspecialchars(\App\Core\Utils::getSettings()['site_title']); ?>
                 - Ein Produkt des PMI.
            </div>

            <div class="imprint">
                <button id="imprint-toggle" class="imprint-toggle">Impressum &amp; Rechtliches</button>
                <div id="imprint-details" class="imprint-details">
                    <p>
                        <strong>Angaben gemäß § 5 TMG:</strong><br>
                        Der Ultramodulare, Responsiv-Agile Kognitionsarchitekt und Interaktionsoptimierer für Großsprachmodelle – Prompt-Engineering-Meisterschafts-Zentrum<br>
                        kurz: <strong>PMI - Prompt Meister Institut</strong>
                    </p>
                    <p>
                        Future-Allee 1<br>
                        69115 Heidelberg<br>
                        Deutschland
                        </p>
                    <p>
                        <strong>Vertreten durch:</strong><br>
                        [Name des Vertretungsberechtigten]
                        </p>
                    <p>
                        <strong>Kontakt:</strong><br>
                        Telefon: +49 (0) 6221 / [Ihre Nummer]<br>
                        E-Mail: kontakt@prompt-meister-institut.de
                        </p>
                    <p>
                        <strong>Registereintrag:</strong><br>
                         Eintragung im Handelsregister.<br>
                        Registergericht: Amtsgericht Mannheim<br>
                        Registernummer: HRB [Ihre Nummer]
                         </p>
                    <p>
                        <strong>Umsatzsteuer-ID:</strong><br>
                         Umsatzsteuer-Identifikationsnummer gemäß §27a Umsatzsteuergesetz:<br>
                        DE[Ihre USt-IdNr.]
                         </p>
                    <p>
                        <a href="#placeholder-privacy">Datenschutzerklärung</a> |
                        <a href="#placeholder-terms">Nutzungsbedingungen</a>
                         </p>
                     <button id="imprint-close" class="imprint-close">&times; Schließen</button>
                </div>
            </div>
        </div>
    </footer>

    <script type="module" src="<?php echo rtrim($config['base_url'], '/'); ?>/assets/js/main.js"></script>

</body>
</html>