<?php
include_once dirname(__DIR__) . '/partials/header.php';
?>
<div class="page-wrapper admin-dashboard-wrapper">
    <h1 class="main-title">Admin Dashboard</h1>
    <div class="dashboard-grid">
        <?php include_once __DIR__ . '/partials/_sidebar.php'; ?>
        <main class="dashboard-content" id="admin-dashboard-overview">
            <?php if (isset($dashboardData['error'])): ?>
                <div class="message error"><?php echo htmlspecialchars($dashboardData['error']); ?></div>
            <?php endif; ?>
            <div class="dashboard-widget-grid" id="dashboard-widget-grid">
                <div class="dashboard-widget widget-stats" draggable="true" id="widget-stats">
                    <h3>📊 Systemübersicht</h3>
                    <div class="stat-grid">
                        <div class="stat-item main-stat">
                            <span class="stat-value"><?php echo $dashboardData['totalUsers'] ?? 0; ?></span>
                            <span class="stat-label">Benutzer gesamt</span>
                        </div>
                        <div class="stat-item sub-stat">
                            <span class="stat-value"><?php echo $dashboardData['userCounts']['schueler'] ?? 0; ?></span>
                            <span class="stat-label">Schüler</span>
                        </div>
                        <div class="stat-item sub-stat">
                            <span class="stat-value"><?php echo $dashboardData['userCounts']['lehrer'] ?? 0; ?></span>
                            <span class="stat-label">Lehrer</span>
                        </div>
                        <div class="stat-item sub-stat">
                            <span class="stat-value"><?php echo $dashboardData['userCounts']['planer'] ?? 0; ?></span>
                            <span class="stat-label">Planer</span>
                        </div>
                        <div class="stat-item sub-stat">
                            <span class="stat-value"><?php echo $dashboardData['userCounts']['admin'] ?? 0; ?></span>
                            <span class="stat-label">Admins</span>
                        </div>
                    </div>
                    <hr class="stat-divider">
                    <div class="stat-grid bottom-grid">
                        <div class="stat-item">
                            <span class="stat-value"><?php echo $dashboardData['classCount'] ?? 0; ?></span>
                            <span class="stat-label">Klassen</span>
                        </div>
                        <div class="stat-item">
                            <span class="stat-value"><?php echo $dashboardData['teacherCount'] ?? 0; ?></span>
                            <span class="stat-label">Lehrerprofile</span>
                        </div>
                        <div class="stat-item">
                            <span class="stat-value"><?php echo $dashboardData['subjectCount'] ?? 0; ?></span>
                            <span class="stat-label">Fächer</span>
                        </div>
                         <div class="stat-item">
                            <span class="stat-value"><?php echo $dashboardData['roomCount'] ?? 0; ?></span>
                            <span class="stat-label">Räume</span>
                        </div>
                    </div>
                </div>
                <div class="dashboard-widget widget-maintenance" draggable="true" id="widget-maintenance">
                    <h3>🔧 Wartungsmodus</h3>
                    <div class="setting-control" style="justify-content: space-between;">
                        <label class="toggle-switch">
                            <input type="checkbox"
                                   id="dashboard_maintenance_mode"
                                   name="maintenance_mode"
                                   value="1"
                                   <?php echo($dashboardData['settings']['maintenance_mode'] ? 'checked' : ''); ?>
                                   data-csrf-token="<?php echo htmlspecialchars(\App\Core\Security::getCsrfToken()); ?>"> <?php  ?>
                            <span class="slider round"></span>
                        </label>
                        <span id="dashboard-maintenance-status" class="toggle-status" style="font-weight: bold;">
                             <?php echo($dashboardData['settings']['maintenance_mode'] ? 'Aktiviert' : 'Deaktiviert'); ?>
                        </span>
                    </div>
                    <p style="font-size: 0.85rem; color: var(--color-text-muted); margin-top: 10px;">
                        Schaltet den Zugang für Schüler und Lehrer an/aus.
                        <a href="<?php echo htmlspecialchars(\App\Core\Utils::url('admin/settings')); ?>" style="font-size: inherit;">Nachricht/Whitelist anpassen</a>
                    </p>
                    <span id="maintenance-toggle-spinner" class="loading-spinner small" style="display: none; margin-left: 15px;"></span>
                </div>
                <div class="dashboard-widget widget-publish-status" draggable="true" id="widget-publish-status">
                    <h3>🚀 Veröffentlichungsstatus</h3>
                    <div class="publish-status-group">
                        <strong>Aktuelle Woche (KW <?php echo htmlspecialchars($dashboardData['publishStatus']['currentWeekNum'] ?? '-'); ?>):</strong>
                        <div class="status-indicators">
                            <span class="status-indicator <?php echo($dashboardData['publishStatus']['current']['student'] ?? false) ? 'published' : 'not-published'; ?>">
                                Schüler
                            </span>
                            <span class="status-indicator <?php echo($dashboardData['publishStatus']['current']['teacher'] ?? false) ? 'published' : 'not-published'; ?>">
                                Lehrer
                            </span>
                        </div>
                    </div>
                    <div class="publish-status-group">
                        <strong>Nächste Woche (KW <?php echo htmlspecialchars($dashboardData['publishStatus']['nextWeekNum'] ?? '-'); ?>):</strong>
                        <div class="status-indicators">
                            <span class="status-indicator <?php echo($dashboardData['publishStatus']['next']['student'] ?? false) ? 'published' : 'not-published'; ?>">
                                Schüler
                            </span>
                            <span class="status-indicator <?php echo($dashboardData['publishStatus']['next']['teacher'] ?? false) ? 'published' : 'not-published'; ?>">
                                Lehrer
                            </span>
                        </div>
                    </div>
                    <a href="<?php echo htmlspecialchars(\App\Core\Utils::url('planer/dashboard')); ?>" class="widget-link">Jetzt verwalten &raquo;</a>
                </div>
                <div class="dashboard-widget widget-activity" draggable="true" id="widget-activity">
                    <h3>🕒 Letzte Aktivitäten</h3>
                    <?php if (!empty($dashboardData['latestLogs'])): ?>
                        <ul class="activity-list">
                            <?php foreach ($dashboardData['latestLogs'] as $log): ?>
                                <li>
                                    <span class="log-time"><?php echo date('d.m. H:i', strtotime($log['timestamp'])); ?>:</span>
                                    <span class="log-user"><?php echo htmlspecialchars($log['username'] ?? 'System'); ?></span>
                                    <span class="log-action"><?php echo htmlspecialchars($log['action']); ?></span>
                                    <?php if ($log['target_type'] || $log['target_id']): ?>
                                        <span class="log-target">(<?php echo htmlspecialchars($log['target_type'] ?? ''); ?> <?php echo htmlspecialchars($log['target_id'] ?? ''); ?>)</span>
                                    <?php endif; ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        <p style="margin-bottom: 15px;">Keine Protokolleinträge vorhanden.</p>
                    <?php endif; ?>
                     <a href="<?php echo htmlspecialchars(\App\Core\Utils::url('admin/audit-logs')); ?>" class="widget-link">Vollständiges Log anzeigen &raquo;</a>
                </div>
                 <div class="dashboard-widget widget-quicklinks" draggable="true" id="widget-quicklinks">
                    <h3>🚀 Schnellzugriffe</h3>
                    <div class="quicklink-buttons">
                         <a href="<?php echo htmlspecialchars(\App\Core\Utils::url('admin/users')); ?>" class="btn btn-secondary">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16"><path d="M15 14s1 0 1-1-1-4-6-4-6 3-6 4 1 1 1 1zM11 6a3 3 0 1 1-6 0 3 3 0 0 1 6 0M8 9a5 5 0 0 0-5 5v1h10v-1a5 5 0 0 0-5-5"/></svg>
                            Benutzer
                         </a>
                         <a href="<?php echo htmlspecialchars(\App\Core\Utils::url('admin/stammdaten')); ?>" class="btn btn-secondary">
                             <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16"><path d="M8 15A7 7 0 1 1 8 1a7 7 0 0 1 0 14m0 1A8 8 0 1 0 8 0a8 8 0 0 0 0 16"/><path d="M8 4a.5.5 0 0 1 .5.5v3h3a.5.5 0 0 1 0 1h-3v3a.5.5 0 0 1-1 0v-3h-3a.5.5 0 0 1 0-1h3v-3A.5.5 0 0 1 8 4"/></svg>
                            Stammdaten
                        </a>
                         <a href="<?php echo htmlspecialchars(\App\Core\Utils::url('planer/dashboard')); ?>" class="btn btn-secondary">
                             <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16"><path d="M3.5 0a.5.5 0 0 1 .5.5V1h8V.5a.5.5 0 0 1 1 0V1h1a2 2 0 0 1 2 2v11a2 2 0 0 1-2 2H2a2 2 0 0 1-2-2V3a2 2 0 0 1 2-2h1V.5a.5.5 0 0 1 .5-.5M1 4v10a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1V4z"/></svg>
                            Planer
                        </a>
                         <a href="<?php echo htmlspecialchars(\App\Core\Utils::url('admin/settings')); ?>" class="btn btn-secondary">
                             <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16"><path d="M9.405 1.05c-.413-1.4-2.397-1.4-2.81 0l-.1.34a1.464 1.464 0 0 1-2.105.872l-.31-.17c-1.283-.698-2.686.705-1.987 1.987l.169.311a1.464 1.464 0 0 1-.872 2.105l-.34.1c-1.4.413-1.4 2.397 0 2.81l.34.1a1.464 1.464 0 0 1 .872 2.105l-.17.31c-.698 1.283.705 2.686 1.987 1.987l.311-.169a1.464 1.464 0 0 1 2.105.872l.1.34c.413 1.4 2.397 1.4 2.81 0l.1-.34a1.464 1.464 0 0 1 2.105-.872l.31.17c1.283.698 2.686-.705 1.987-1.987l-.169-.311a1.464 1.464 0 0 1 .872-2.105l.34-.1c1.4-.413-1.4-2.397 0-2.81l-.34-.1a1.464 1.464 0 0 1-.872-2.105l.17-.31c.698-1.283-.705-2.686-1.987-1.987l-.311.169a1.464 1.464 0 0 1-2.105-.872zM8 10.93a2.929 2.929 0 1 1 0-5.86 2.929 2.929 0 0 1 0 5.858z"/></svg>
                            Einstellungen
                        </a>
                    </div>
                </div>
                <div class="dashboard-widget widget-system-status" draggable="true" id="widget-system-status">
                    <h3>✅ System-Status</h3>
                    <ul class="system-check-list">
                        <?php if (empty($dashboardData['systemChecks'])): ?>
                            <li>Status-Checks konnten nicht geladen werden.</li>
                        <?php else: ?>
                            <?php foreach ($dashboardData['systemChecks'] as $check): ?>
                                <li class="<?php echo $check['status'] ? 'status-ok' : 'status-fail'; ?>"
                                    title="<?php echo htmlspecialchars($check['tooltip'] ?? $check['label']); ?>">
                                    <div class="status-info">
                                        <span class="status-icon"><?php echo $check['status'] ? '✔' : '❌'; ?></span>
                                        <span class="status-label"><?php echo htmlspecialchars($check['label']); ?>:</span>
                                    </div>
                                    <span class="status-message"><?php echo htmlspecialchars($check['message']); ?></span>
                                </li>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </ul>
                     <p style="font-size: 0.8rem; color: var(--color-text-muted); margin-top: 10px; margin-bottom: 0;">
                        Basale Überprüfung der Systemkomponenten.
                    </p>
                    <div class="widget-action-footer">
                         <button class="btn btn-warning btn-small" id="clear-cache-btn" data-csrf-token="<?php echo htmlspecialchars(\App\Core\Security::getCsrfToken()); ?>">
                            Einstellungen-Cache leeren
                         </button>
                         <span id="cache-clear-spinner" class="loading-spinner small" style="display: none;"></span>
                    </div>
                </div>
                <div class="dashboard-widget widget-system-info" draggable="true" id="widget-system-info">
                    <h3>🖥️ System-Info</h3>
                    <div class="system-info-list">
                        <div class="info-item">
                            <span class="info-label">PHP-Version</span>
                            <span class="info-value"><?php echo htmlspecialchars($dashboardData['systemInfo']['php'] ?? 'N/A'); ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">DB-Version</span>
                            <span class="info-value"><?php echo htmlspecialchars($dashboardData['systemInfo']['db'] ?? 'N/A'); ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Webserver</span>
                            <span class="info-value"><?php echo htmlspecialchars($dashboardData['systemInfo']['webserver'] ?? 'N/A'); ?></span>
                        </div>
                    </div>
                </div>
            </div> </main>
    </div>
</div>
<?php
?>
<script type="module">
    import { apiFetch } from '<?php echo htmlspecialchars(rtrim($config['base_url'], '/')); ?>/assets/js/api-client.js';
    import { showToast } from '<?php echo htmlspecialchars(rtrim($config['base_url'], '/')); ?>/assets/js/notifications.js';
    const maintenanceToggle = document.getElementById('dashboard_maintenance_mode');
    const maintenanceStatus = document.getElementById('dashboard-maintenance-status');
    const maintenanceSpinner = document.getElementById('maintenance-toggle-spinner');
    if (maintenanceToggle && maintenanceStatus) {
        maintenanceToggle.addEventListener('change', async () => {
            const isChecked = maintenanceToggle.checked;
            const csrfToken = maintenanceToggle.dataset.csrfToken;
            if (maintenanceSpinner) maintenanceSpinner.style.display = 'inline-block';
            maintenanceToggle.disabled = true;
            const formData = new FormData();
            formData.append('maintenance_mode', isChecked ? '1' : '0');
            formData.append('_csrf_token', csrfToken);
            formData.append('site_title', <?php echo json_encode($dashboardData['settings']['site_title'] ?? ''); ?>);
            formData.append('maintenance_message', <?php echo json_encode($dashboardData['settings']['maintenance_message'] ?? ''); ?>);
            formData.append('maintenance_whitelist_ips', <?php echo json_encode($dashboardData['settings']['maintenance_whitelist_ips'] ?? ''); ?>);
            formData.append('default_start_hour', <?php echo json_encode($dashboardData['settings']['default_start_hour'] ?? '1'); ?>);
            formData.append('default_end_hour', <?php echo json_encode($dashboardData['settings']['default_end_hour'] ?? '10'); ?>);
            formData.append('max_login_attempts', <?php echo json_encode($dashboardData['settings']['max_login_attempts'] ?? '5'); ?>);
            formData.append('lockout_minutes', <?php echo json_encode($dashboardData['settings']['lockout_minutes'] ?? '15'); ?>);
            formData.append('default_theme', <?php echo json_encode($dashboardData['settings']['default_theme'] ?? 'light'); ?>);
            formData.append('ical_enabled', '<?php echo $dashboardData['settings']['ical_enabled'] ? '1' : '0'; ?>');
            formData.append('ical_weeks_future', '<?php echo htmlspecialchars($dashboardData['settings']['ical_weeks_future'] ?? '8'); ?>');
            formData.append('pdf_footer_text', <?php echo json_encode($dashboardData['settings']['pdf_footer_text'] ?? ''); ?>);
            try {
                const response = await apiFetch('<?php echo htmlspecialchars(\App\Core\Utils::url('api/admin/settings/save')); ?>', {
                    method: 'POST',
                    body: formData
                });
                if (response.success) {
                    maintenanceStatus.textContent = isChecked ? 'Aktiviert' : 'Deaktiviert';
                    maintenanceStatus.style.color = isChecked ? 'var(--color-success)' : 'var(--color-text-muted)';
                    showToast(`Wartungsmodus ${isChecked ? 'aktiviert' : 'deaktiviert'}.`, 'success');
                    const newToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
                    if (newToken) maintenanceToggle.dataset.csrfToken = newToken;
                } else {
                    maintenanceToggle.checked = !isChecked;
                }
            } catch (error) {
                 maintenanceToggle.checked = !isChecked;
            } finally {
                if (maintenanceSpinner) maintenanceSpinner.style.display = 'none';
                maintenanceToggle.disabled = false;
            }
        });
    }
    const clearCacheBtn = document.getElementById('clear-cache-btn');
    const cacheSpinner = document.getElementById('cache-clear-spinner');
    if (clearCacheBtn && cacheSpinner) {
        clearCacheBtn.addEventListener('click', async () => {
            const csrfToken = clearCacheBtn.dataset.csrfToken;
            clearCacheBtn.disabled = true;
            cacheSpinner.style.display = 'inline-block';
            try {
                const response = await apiFetch('<?php echo htmlspecialchars(\App\Core\Utils::url('api/admin/cache/clear')); ?>', {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': csrfToken 
                    }
                });
                if (response.success) {
                    showToast(response.message, 'success');
                    const newToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
                    if (newToken) {
                         clearCacheBtn.dataset.csrfToken = newToken;
                         if (maintenanceToggle) maintenanceToggle.dataset.csrfToken = newToken;
                    }
                }
            } catch (error) {
            } finally {
                clearCacheBtn.disabled = false;
                cacheSpinner.style.display = 'none';
            }
        });
    }
    const grid = document.getElementById('dashboard-widget-grid');
    let draggedItem = null;
    let placeholder = null;
    const WIDGET_ORDER_KEY = 'dashboardWidgetOrder';
    function loadWidgetOrder() {
        const savedOrder = localStorage.getItem(WIDGET_ORDER_KEY);
        if (savedOrder && grid) {
            try {
                const order = JSON.parse(savedOrder);
                order.forEach(id => {
                    const widget = document.getElementById(id);
                    if (widget) {
                        grid.appendChild(widget);
                    }
                });
            } catch (e) {
                console.error("Fehler beim Laden der Widget-Reihenfolge:", e);
                localStorage.removeItem(WIDGET_ORDER_KEY);
            }
        }
    }
    function saveWidgetOrder() {
        if (!grid) return;
        const widgets = grid.querySelectorAll('.dashboard-widget');
        const order = Array.from(widgets).map(widget => widget.id);
        localStorage.setItem(WIDGET_ORDER_KEY, JSON.stringify(order));
    }
    function createPlaceholder() {
        if (!placeholder) {
            placeholder = document.createElement('div');
            placeholder.className = 'widget-placeholder';
            if (draggedItem) {
                const rect = draggedItem.getBoundingClientRect();
                placeholder.style.height = `${rect.height}px`;
                placeholder.style.width = `${rect.width}px`; 
            }
        }
        return placeholder;
    }
    function removePlaceholder() {
        if (placeholder && placeholder.parentNode) {
            placeholder.parentNode.removeChild(placeholder);
        }
        placeholder = null;
    }
    function getDragAfterElement(container, clientX, clientY) {
        const draggableElements = [...container.querySelectorAll('.dashboard-widget:not(.dragging)')];
        return draggableElements.reduce((closest, child) => {
            const box = child.getBoundingClientRect();
            const midX = box.left + box.width / 2;
            const midY = box.top + box.height / 2;
            const distance = Math.sqrt(Math.pow(clientX - midX, 2) + Math.pow(clientY - midY, 2));
            if (distance < (closest.distance || Number.POSITIVE_INFINITY)) {
                return { distance: distance, element: child };
            } else {
                return closest;
            }
        }, { distance: Number.POSITIVE_INFINITY, element: null }).element;
    }
    if (grid) {
        loadWidgetOrder();
        grid.addEventListener('dragstart', (e) => {
            if (e.target.classList.contains('dashboard-widget')) {
                draggedItem = e.target;
                setTimeout(() => {
                    if (draggedItem) {
                        draggedItem.classList.add('dragging');
                    }
                }, 0);
                placeholder = createPlaceholder();
            } else {
                e.preventDefault();
            }
        });
        grid.addEventListener('dragend', (e) => {
            if (draggedItem) {
                draggedItem.classList.remove('dragging');
            }
            draggedItem = null;
            removePlaceholder();
        });
        grid.addEventListener('dragover', (e) => {
            e.preventDefault(); // Notwendig, um 'drop' zu erlauben
            if (!draggedItem) return;
            const target = e.target.closest('.dashboard-widget');
            if (target && target !== draggedItem && target !== placeholder) {
                const rect = target.getBoundingClientRect();
                const closestElement = getDragAfterElement(grid, e.clientX, e.clientY);
                if (closestElement) {
                     const closestRect = closestElement.getBoundingClientRect();
                     const midX = closestRect.left + closestRect.width / 2;
                     const midY = closestRect.top + closestRect.height / 2;
                    if (e.clientY >= closestRect.top && e.clientY <= closestRect.bottom) {
                         if (e.clientX < midX) {
                            grid.insertBefore(placeholder, closestElement);
                         } else {
                            grid.insertBefore(placeholder, closestElement.nextSibling);
                         }
                    } else if (e.clientY < midY) {
                         grid.insertBefore(placeholder, closestElement);
                    } else {
                         grid.insertBefore(placeholder, closestElement.nextSibling);
                    }
                } else if (!grid.contains(placeholder)) {
                    grid.appendChild(placeholder);
                }
            } else if (!target && grid === e.target && !grid.contains(placeholder)) {
                 grid.appendChild(placeholder);
            }
        });
        grid.addEventListener('drop', (e) => {
            e.preventDefault();
            if (!draggedItem || !placeholder || !placeholder.parentNode) {
                removePlaceholder();
                if(draggedItem) draggedItem.classList.remove('dragging');
                draggedItem = null;
                return;
            }
            placeholder.parentNode.insertBefore(draggedItem, placeholder);
            removePlaceholder();
            if (draggedItem) {
                draggedItem.classList.remove('dragging');
            }
            draggedItem = null;
            saveWidgetOrder();
        });
    }
</script>
<?php
include_once dirname(__DIR__) . '/partials/footer.php';
?>