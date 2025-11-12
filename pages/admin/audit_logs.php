<?php
include_once dirname(__DIR__) . '/partials/header.php';
?>
<div class="page-wrapper admin-dashboard-wrapper">
    <h1 class="main-title">Audit Log (Aktionsprotokoll)</h1>
    <div class="dashboard-grid">
        <?php include_once __DIR__ . '/partials/_sidebar.php'; ?>
        <main class="dashboard-content" id="audit-log-management">
            <!-- Sektion für Filter -->
            <div class.="dashboard-section" id="filter-section">
                 <div class="form-container" style="background-color: var(--color-surface-alt); margin-bottom: 20px;">
                    <form id="audit-filter-form" class="filter-form">
                        <div class="filter-grid">
                            <!-- Benutzerfilter -->
                            <div class="form-group">
                                <label for="filter-user">Benutzer</label>
                                <select name="user_id" id="filter-user">
                                    <option value="">Alle Benutzer</option>
                                    <?php foreach ($availableUsers as $user): ?>
                                        <option value="<?php echo $user['user_id']; ?>">
                                            <?php echo htmlspecialchars($user['last_name'] . ', ' . $user['first_name'] . ' (' . $user['username'] . ')'); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <!-- Aktionsfilter -->
                            <div class="form-group">
                                <label for="filter-action">Aktion</label>
                                <select name="action" id="filter-action">
                                    <option value="">Alle Aktionen</option>
                                     <?php foreach ($availableActions as $action): ?>
                                        <option value="<?php echo htmlspecialchars($action); ?>">
                                            <?php echo htmlspecialchars($action); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <!-- Datumsfilter (Von) -->
                            <div class="form-group">
                                <label for="filter-date-from">Von Datum</label>
                                <input type="date" name="date_from" id="filter-date-from">
                            </div>
                            <!-- Datumsfilter (Bis) -->
                            <div class="form-group">
                                <label for="filter-date-to">Bis Datum</label>
                                <input type="date" name="date_to" id="filter-date-to">
                            </div>
                        </div>
                        <div class="form-actions" style="margin-top: 10px;">
                            <button type="submit" class="btn btn-primary btn-small" style="width: 150px; margin-bottom: 0;">Filtern</button>
                            <button type="reset" class="btn btn-secondary btn-small" id="filter-reset-btn" style="width: 150px; margin-bottom: 0;">Zurücksetzen</button>
                        </div>
                    </form>
                 </div>
            </div>
            <!-- Sektion für die Log-Tabelle -->
            <div class="dashboard-section active" id="log-list-section">
                <div class="section-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                    <h3>Protokoll-Einträge</h3>
                    <div id="pagination-summary" class="pagination-summary" style="font-size: 0.9rem; color: var(--color-text-muted);"></div>
                </div>
                <div class="table-container">
                    <div class="table-responsive">
                        <table class="data-table" id="audit-logs-table">
                            <thead>
                                <tr>
                                    <th style="width: 150px;">Zeitstempel</th>
                                    <th style="width: 180px;">Benutzer</th>
                                    <th style="width: 130px;">Aktion</th>
                                    <th style_width="100px;">Ziel-Typ</th>
                                    <th style="width: 80px;">Ziel-ID</th>
                                    <th>Details</th>
                                    <th style="width: 110px;">IP-Adresse</th>
                                </tr>
                            </thead>
                            <tbody id="audit-logs-tbody">
                                <!-- Inhalt wird per JS geladen -->
                                <tr><td colspan="7" style="text-align: center; padding: 40px;">
                                    <div class="loading-spinner"></div>
                                </td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
                <!-- Paginierung -->
                <div class="pagination-controls" id="pagination-controls" style="margin-top: 20px; display: flex; justify-content: center; align-items: center; gap: 10px;">
                    <!-- Paginierung wird per JS erstellt -->
                </div>
            </div>
        </main>
    </div>
</div>
<?php
include_once dirname(__DIR__) . '/partials/footer.php';
?>