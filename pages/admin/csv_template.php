<?php
// pages/admin/csv_template.php
include_once dirname(__DIR__) . '/partials/header.php';
// $templateData wird vom CsvTemplateController übergeben
?>

<div class="page-wrapper admin-dashboard-wrapper">
    <h1 class="main-title">CSV-Importvorlage (Benutzer)</h1>
    <div class="dashboard-grid">
        <?php include_once __DIR__ . '/partials/_sidebar.php'; ?>
        <main class="dashboard-content" id="csv-template-view">
            <div class="dashboard-section active">
                <div class="section-header">
                    <h3>Vorschau der Vorlagenstruktur</h3>
                    <a href="<?php echo htmlspecialchars(rtrim($config['base_url'], '/')); ?>/assets/templates/user_import_template.csv" download="user_import_template.csv" class="btn btn-primary" style="width: auto; margin-bottom: 0;">
                        Vorlage herunterladen
                    </a>
                </div>
                
                <p>Die CSV-Datei muss exakt diese Spalten in dieser Reihenfolge enthalten. Die erste Zeile muss die Header-Zeile sein. Die Zeilen 2 und 3 sind Beispiele.</p>

                <?php if (isset($templateData['error'])): ?>
                    <p class="message error"><?php echo htmlspecialchars($templateData['error']); ?></p>
                <?php elseif (!empty($templateData['headers'])): ?>
                    <div class="table-container" style="margin-top: 20px;">
                        <div class="table-responsive">
                            <table class="data-table csv-preview-table">
                                <thead>
                                    <tr>
                                        <?php foreach ($templateData['headers'] as $header): ?>
                                            <th><?php echo htmlspecialchars($header); ?></th>
                                        <?php endforeach; ?>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($templateData['rows'] as $row): ?>
                                        <tr>
                                            <?php foreach ($row as $cell): ?>
                                                <td><?php echo htmlspecialchars($cell); ?></td>
                                            <?php endforeach; ?>
                                        </tr>
                                    <?php endforeach; ?>
                                    <?php if (empty($templateData['rows'])): ?>
                                        <tr>
                                            <td colspan="<?php echo count($templateData['headers']); ?>"><i>Die Vorlagendatei enthält keine Beispielzeilen.</i></td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php else: ?>
                     <p class="message error">Die Vorlagendatei ist leer oder konnte nicht korrekt gelesen werden.</p>
                <?php endif; ?>

            </div>
        </main>
    </div>
</div>

<?php
include_once dirname(__DIR__) . '/partials/footer.php';
?>
