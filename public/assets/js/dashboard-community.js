import { apiFetch } from './api-client.js';
import { showToast } from './notifications.js';
import { escapeHtml } from './planer-utils.js';
export function initializeDashboardCommunity() {
    const section = document.getElementById('section-community-board');
    if (!section) return;
    const form = document.getElementById('community-post-form');
    const titleInput = document.getElementById('post-title');
    const contentInput = document.getElementById('post-content');
    const createButton = document.getElementById('create-post-btn');
    const postSpinner = document.getElementById('post-create-spinner');
    const postListContainer = document.getElementById('community-posts-list');
    let hasLoaded = false; 
    const loadCommunityPosts = async () => {
        if (hasLoaded) return; 
        hasLoaded = true; 
        postListContainer.innerHTML = '<div class="loading-spinner"></div>';
        try {
            const response = await apiFetch(`${window.APP_CONFIG.baseUrl}/api/community/posts`);
            if (response.success && response.data) {
                renderPosts(response.data);
            } else {
                throw new Error(response.message || 'Beiträge konnten nicht geladen werden.');
            }
        } catch (error) {
            console.error("Fehler beim Laden der Community-Beiträge:", error);
            postListContainer.innerHTML = `<p class="message error">${escapeHtml(error.message)}</p>`;
            hasLoaded = false; 
        }
    };
    const renderPosts = (posts) => {
        if (posts.length === 0) {
            postListContainer.innerHTML = '<p class="message info" style="margin: 0;">Keine Beiträge am Schwarzen Brett vorhanden.</p>';
            return;
        }
        postListContainer.innerHTML = posts.map(post => {
            const contentHtml = post.content_html || '<p><em>Kein Inhalt.</em></p>'; 
            const postDate = new Date(post.created_at).toLocaleDateString('de-DE', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' });
            const authorName = `${escapeHtml(post.first_name)} ${escapeHtml(post.last_name[0])}.`;
            const emailLink = post.email 
                ? `(<a href="mailto:${escapeHtml(post.email)}" title="E-Mail an ${escapeHtml(post.first_name)}">${escapeHtml(post.username)}</a>)`
                : `(${escapeHtml(post.username)})`;
            return `
            <div class="community-post-item" data-id="${post.post_id}">
                <div class="post-header">
                    <strong class="post-title">${escapeHtml(post.title)}</strong>
                    <span class="post-meta">
                        Von: ${authorName} ${emailLink}
                        <br>
                        Am: ${postDate} Uhr
                    </span>
                </div>
                <div class="post-content-preview">
                    ${contentHtml}
                </div>
            </div>
            `;
        }).join('');
    };
    if (form) {
        form.addEventListener('submit', async (e) => {
            e.preventDefault();
            const title = titleInput.value.trim();
            const content = contentInput.value.trim();
            if (!title || !content) {
                showToast("Titel und Inhalt dürfen nicht leer sein.", "error");
                return;
            }
            createButton.disabled = true;
            postSpinner.style.display = 'block';
            try {
                const response = await apiFetch(`${window.APP_CONFIG.baseUrl}/api/community/posts/create`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ title, content })
                });
                if (response.success) {
                    showToast(response.message, 'success');
                    form.reset(); 
                    if (response.status === 'approved') {
                        hasLoaded = false; 
                        loadCommunityPosts();
                    }
                }
            } catch (error) {
                console.error("Fehler beim Erstellen des Beitrags:", error);
            } finally {
                createButton.disabled = false;
                postSpinner.style.display = 'none';
            }
        });
    }
    const tabButton = document.querySelector('.tab-button[data-target="section-community-board"]');
    if (tabButton) {
        const loadOnVisible = () => {
            if (section.classList.contains('active') && !hasLoaded) {
                loadCommunityPosts();
            }
        };
        tabButton.addEventListener('click', loadOnVisible);
        if (section.classList.contains('active')) {
            loadOnVisible();
        }
    } else {
        loadCommunityPosts();
    }
}