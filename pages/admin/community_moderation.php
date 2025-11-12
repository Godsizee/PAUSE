<?php
include_once dirname(__DIR__) . '/partials/header.php';
?>
<div class="page-wrapper admin-dashboard-wrapper">
    <h1 class="main-title">Moderation Schwarzes Brett</h1>
    <div class="dashboard-grid">
        <?php include_once __DIR__ . '/partials/_sidebar.php'; ?>
        <main class="dashboard-content" id="community-moderation">
            <!-- NEU: Tab Navigation -->
            <nav class="tab-navigation">
                <button class="tab-button active" data-target="pending-section">
                    Ausstehend (<?php echo count($pendingPosts); ?>)
                </button>
                <button class="tab-button" data-target="approved-section">
                    Freigegeben (<?php echo count($approvedPosts); ?>)
                </button>
            </nav>
            <!-- NEU: Tab Content Wrapper -->
            <div class="tab-content">
                <!-- Tab 1: Ausstehende Beiträge -->
                <div class="dashboard-section active" id="pending-section">
                    <h3>Ausstehende Beiträge</h3>
                    <p>Diese Beiträge wurden von Schülern erstellt und warten auf Freigabe.</p>
                    <div id="pending-posts-list" class="posts-list-container">
                        <?php if (empty($pendingPosts)): ?>
                            <p class="message info">Aktuell gibt es keine Beiträge, die moderiert werden müssen.</p>
                        <?php else: ?>
                            <?php foreach ($pendingPosts as $post): ?>
                                <div class="community-post-item moderation-item" data-id="<?php echo $post['post_id']; ?>">
                                    <div class="post-header">
                                        <strong class="post-title"><?php echo htmlspecialchars($post['title']); ?></strong>
                                        <span class="post-meta">
                                            Von: <?php echo htmlspecialchars($post['first_name'] . ' ' . $post['last_name']); ?> (@<?php echo htmlspecialchars($post['username']); ?>)
                                            <br>
                                            Am: <?php echo date('d.m.Y H:i', strtotime($post['created_at'])); ?>
                                        </span>
                                    </div>
                                    <div class="post-content-preview">
                                        <?php echo $post['content_html']; // HTML aus Parsedown (als "safe" markiert) ?>
                                    </div>
                                    <div class="moderation-actions">
                                        <button class="btn btn-success btn-small approve-post-btn" data-id="<?php echo $post['post_id']; ?>">Freigeben</button>
                                        <button class="btn btn-danger btn-small reject-post-btn" data-id="<?php echo $post['post_id']; ?>">Ablehnen & Löschen</button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div> <!-- Ende #pending-section -->
                <!-- Tab 2: Freigegebene Beiträge -->
                <div class="dashboard-section" id="approved-section">
                    <h3>Freigegebene Beiträge</h3>
                    <p>Dies sind alle aktuell sichtbaren Beiträge auf dem Schwarzen Brett. Sie können hier bereits freigegebene Beiträge wieder löschen.</p>
                    <div id="approved-posts-list" class="posts-list-container">
                        <?php if (empty($approvedPosts)): ?>
                            <p class="message info">Aktuell sind keine Beiträge freigegeben.</p>
                        <?php else: ?>
                            <?php foreach ($approvedPosts as $post): ?>
                                <div class="community-post-item moderation-item" data-id="<?php echo $post['post_id']; ?>">
                                    <div class="post-header">
                                        <strong class="post-title"><?php echo htmlspecialchars($post['title']); ?></strong>
                                        <span class="post-meta">
                                            Von: <?php echo htmlspecialchars($post['first_name'] . ' ' . $post['last_name']); ?> (@<?php echo htmlspecialchars($post['username']); ?>)
                                            <br>
                                            Am: <?php echo date('d.m.Y H:i', strtotime($post['created_at'])); ?>
                                            <?php if ($post['moderator_id']): // Zeige Moderationsdatum, falls vorhanden ?>
                                                <br><i>Freigegeben am: <?php echo date('d.m.Y H:i', strtotime($post['moderated_at'])); ?></i>
                                            <?php endif; ?>
                                        </span>
                                    </div>
                                    <div class="post-content-preview">
                                        <?php echo $post['content_html']; // HTML aus Parsedown ?>
                                    </div>
                                    <div class="moderation-actions">
                                        <button class="btn btn-danger btn-small delete-approved-btn" data-id="<?php echo $post['post_id']; ?>">Endgültig Löschen</button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div> <!-- Ende #approved-section -->
            </div> <!-- Ende .tab-content -->
        </main>
    </div>
</div>
<?php
include_once dirname(__DIR__) . '/partials/footer.php';
?>