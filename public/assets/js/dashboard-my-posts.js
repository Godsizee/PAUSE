// public/assets/js/dashboard-my-posts.js
import { apiFetch } from './api-client.js';
import { showToast, showConfirm } from './notifications.js';
import { escapeHtml } from './planer-utils.js'; // Stellt sicher, dass diese Hilfsfunktion existiert

/**
 * Initialisiert die "Meine Beiträge"-Sektion im Dashboard.
 */
export function initializeMyCommunityPosts() {
    const section = document.getElementById('section-my-posts');
    if (!section) return;

    const postListContainer = document.getElementById('my-posts-list');
    const postModal = document.getElementById('my-post-edit-modal');
    const postForm = document.getElementById('my-post-edit-form');
    const postTitleInput = document.getElementById('edit-post-title');
    const postContentInput = document.getElementById('edit-post-content');
    const postIdInput = document.getElementById('edit-post-id');
    const postSpinner = document.getElementById('edit-post-spinner');
    const cancelEditBtn = document.getElementById('my-post-modal-cancel-btn');
    const closeEditBtn = document.getElementById('my-post-modal-close-btn');

    let hasLoaded = false;
    let isSaving = false;

    /**
     * Lädt die Beiträge des Benutzers.
     */
    const loadMyPosts = async () => {
        if (hasLoaded) return; // Nur einmal laden, es sei denn, es wird explizit neu geladen
        hasLoaded = true;

        if (!postListContainer) return;
        postListContainer.innerHTML = '<div class="loading-spinner"></div>';

        try {
            const response = await apiFetch(`${window.APP_CONFIG.baseUrl}/api/community/my-posts`);
            if (response.success && response.data) {
                renderMyPosts(response.data);
            } else {
                throw new Error(response.message || 'Beiträge konnten nicht geladen werden.');
            }
        } catch (error) {
            console.error("Fehler beim Laden meiner Beiträge:", error);
            if (postListContainer) {
                postListContainer.innerHTML = `<p class="message error">${escapeHtml(error.message)}</p>`;
            }
            hasLoaded = false; // Erlaube erneutes Laden bei Fehler
        }
    };

    /**
     * Rendert die Liste der Beiträge.
     * @param {Array} posts - Array von Post-Objekten.
     */
    const renderMyPosts = (posts) => {
        if (!postListContainer) return;
        if (posts.length === 0) {
            postListContainer.innerHTML = '<p class="message info" style="margin: 0;">Du hast noch keine Beiträge erstellt.</p>';
            return;
        }

        // KORRIGIERTES STYLING (flexibel)
        postListContainer.innerHTML = posts.map(post => {
            const postDate = new Date(post.created_at).toLocaleString('de-DE', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' }) + ' Uhr';
            const contentHtml = post.content_html || '<p><em>Kein Inhalt.</em></p>';
            
            let statusClass = '';
            let statusText = '';
            switch (post.status) {
                case 'pending':
                    statusClass = 'status-pending';
                    statusText = 'Ausstehend';
                    break;
                case 'approved':
                    statusClass = 'status-approved';
                    statusText = 'Genehmigt';
                    break;
                case 'rejected':
                    statusClass = 'status-rejected';
                    statusText = 'Abgelehnt';
                    break;
            }

            return `
                <div class="community-post-item my-post-item" data-id="${post.post_id}" data-title="${escapeHtml(post.title)}" data-content="${escapeHtml(post.content)}">
                    <div class="post-content-preview">
                        <strong class="post-title">${escapeHtml(post.title)}</strong>
                        ${contentHtml}
                    </div>
                    <div class="my-post-meta">
                        <div class="post-status">
                            <span>Erstellt am: ${postDate}</span>
                            <span class="status-badge ${statusClass}">${statusText}</span>
                        </div>
                        <div class="post-actions">
                            <button class="btn btn-secondary btn-small edit-my-post-btn">Bearbeiten</button>
                            <button class="btn btn-danger btn-small delete-my-post-btn">Löschen</button>
                        </div>
                    </div>
                </div>
            `;
        }).join('');
    };

    /**
     * Öffnet das Bearbeiten-Modal und füllt es.
     * @param {HTMLElement} postItem - Das angeklickte Beitrags-Element.
     */
    const openEditModal = (postItem) => {
        if (!postModal) return;
        
        // Daten aus data-Attributen holen (sicherer gegen XSS als innerHTML)
        const id = postItem.dataset.id;
        const title = postItem.dataset.title;
        const content = postItem.dataset.content; // Roh-Markdown holen

        postIdInput.value = id;
        postTitleInput.value = title;
        postContentInput.value = content;
        
        postModal.classList.add('visible');
        postTitleInput.focus();
    };

    /**
     * Schließt das Bearbeiten-Modal.
     */
    const closeEditModal = () => {
        if (postModal) {
            postModal.classList.remove('visible');
            postForm.reset();
            postIdInput.value = '';
            postSpinner.style.display = 'none';
            postForm.querySelector('button[type="submit"]').disabled = false;
        }
    };

    /**
     * Behandelt das Speichern (Aktualisieren) eines Beitrags.
     * @param {Event} e - Das Submit-Event.
     */
    const handleUpdatePost = async (e) => {
        e.preventDefault();
        if (isSaving) return;

        const id = postIdInput.value;
        const title = postTitleInput.value.trim();
        const content = postContentInput.value.trim();

        if (!id || !title || !content) {
            showToast("Titel und Inhalt dürfen nicht leer sein.", "error");
            return;
        }

        isSaving = true;
        postSpinner.style.display = 'block';
        postForm.querySelector('button[type="submit"]').disabled = true;

        try {
            const response = await apiFetch(`${window.APP_CONFIG.baseUrl}/api/community/post/update`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ post_id: id, title: title, content: content })
            });

            if (response.success) {
                showToast(response.message, 'success');
                closeEditModal();
                hasLoaded = false; // Erzwinge Neuladen der Liste, um Status zu aktualisieren
                loadMyPosts();
            }
            // Fehler wird von apiFetch als Toast angezeigt
        } catch (error) {
            console.error("Fehler beim Aktualisieren des Beitrags:", error);
            // Fehler-Toast wird bereits von apiFetch angezeigt
        } finally {
            isSaving = false;
            postSpinner.style.display = 'none';
            // Button wird im closeEditModal() wieder aktiviert
        }
    };

    /**
     * Behandelt das Löschen eines Beitrags.
     * @param {HTMLElement} postItem - Das Beitrags-Element.
     */
    const handleDeletePost = async (postItem) => {
        const id = postItem.dataset.id;
        const title = postItem.dataset.title;

        if (await showConfirm("Beitrag löschen", `Möchtest du deinen Beitrag "${escapeHtml(title)}" wirklich endgültig löschen?`)) {
            try {
                const response = await apiFetch(`${window.APP_CONFIG.baseUrl}/api/community/post/delete`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ post_id: id })
                });

                if (response.success) {
                    showToast(response.message, 'success');
                    // Beitrag aus der Liste entfernen
                    postItem.style.transition = 'opacity 0.3s ease, height 0.3s ease, margin 0.3s ease, padding 0.3s ease';
                    postItem.style.opacity = '0';
                    postItem.style.height = '0px';
                    postItem.style.paddingTop = '0';
                    postItem.style.paddingBottom = '0';
                    postItem.style.margin = '0';
                    setTimeout(() => {
                        postItem.remove();
                        if (postListContainer.childElementCount === 0) {
                            renderMyPosts([]);
                        }
                    }, 300);
                }
                // Fehler wird von apiFetch angezeigt
            } catch (error) {
                console.error("Fehler beim Löschen des Beitrags:", error);
            }
        }
    };

    // --- Event Listeners ---

    // Event Delegation für Bearbeiten- und Löschen-Buttons
    postListContainer.addEventListener('click', (e) => {
        const editButton = e.target.closest('.edit-my-post-btn');
        const deleteButton = e.target.closest('.delete-my-post-btn');
        
        if (editButton) {
            const postItem = editButton.closest('.my-post-item');
            if (postItem) openEditModal(postItem);
            return;
        }
        
        if (deleteButton) {
            const postItem = deleteButton.closest('.my-post-item');
            if (postItem) handleDeletePost(postItem);
            return;
        }
    });

    // Formular-Speichern
    if (postForm) {
        postForm.addEventListener('submit', handleUpdatePost);
    }

    // Modal schließen
    if (cancelEditBtn) {
        cancelEditBtn.addEventListener('click', closeEditModal);
    }
    if (closeEditBtn) {
        closeEditBtn.addEventListener('click', closeEditModal);
    }
    if (postModal) {
        postModal.addEventListener('click', (e) => {
            if (e.target.id === 'my-post-edit-modal') {
                closeEditModal();
            }
        });
    }

    // Tab-Lazy-Loading (wird von dashboard.js gesteuert)
    const tabButton = document.querySelector('.tab-button[data-target="section-my-posts"]');
    if (tabButton) {
        const loadOnVisible = () => {
            if (section.classList.contains('active') && !hasLoaded) {
                loadMyPosts();
            }
        };
        // Beim Klick auf den Tab (wird von dashboard.js gehandhabt)
        tabButton.addEventListener('click', loadOnVisible);
        // Beim Initial-Load (falls der Tab bereits aktiv ist, obwohl das nicht der Standard sein sollte)
        if (section.classList.contains('active')) {
            loadOnVisible();
        }
    }
}

