import { apiFetch } from './api-client.js';
import { showToast, showConfirm } from './notifications.js';
import { escapeHtml } from './planer-utils.js'; 
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
    const loadMyPosts = async () => {
        if (hasLoaded) return; 
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
            hasLoaded = false; 
        }
    };
    const renderMyPosts = (posts) => {
        if (!postListContainer) return;
        if (posts.length === 0) {
            postListContainer.innerHTML = '<p class="message info" style="margin: 0;">Du hast noch keine Beiträge erstellt.</p>';
            return;
        }
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
    const openEditModal = (postItem) => {
        if (!postModal) return;
        const id = postItem.dataset.id;
        const title = postItem.dataset.title;
        const content = postItem.dataset.content; 
        postIdInput.value = id;
        postTitleInput.value = title;
        postContentInput.value = content;
        postModal.classList.add('visible');
        postTitleInput.focus();
    };
    const closeEditModal = () => {
        if (postModal) {
            postModal.classList.remove('visible');
            postForm.reset();
            postIdInput.value = '';
            postSpinner.style.display = 'none';
            postForm.querySelector('button[type="submit"]').disabled = false;
        }
    };
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
                hasLoaded = false; 
                loadMyPosts();
            }
        } catch (error) {
            console.error("Fehler beim Aktualisieren des Beitrags:", error);
        } finally {
            isSaving = false;
            postSpinner.style.display = 'none';
        }
    };
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
            } catch (error) {
                console.error("Fehler beim Löschen des Beitrags:", error);
            }
        }
    };
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
    if (postForm) {
        postForm.addEventListener('submit', handleUpdatePost);
    }
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
    const tabButton = document.querySelector('.tab-button[data-target="section-my-posts"]');
    if (tabButton) {
        const loadOnVisible = () => {
            if (section.classList.contains('active') && !hasLoaded) {
                loadMyPosts();
            }
        };
        tabButton.addEventListener('click', loadOnVisible);
        if (section.classList.contains('active')) {
            loadOnVisible();
        }
    }
}