function applyTheme(theme) {
    if (theme === 'dark') {
        document.documentElement.classList.add('dark-mode');
        const toggle = document.getElementById('theme-toggle');
        if (toggle) {
             toggle.setAttribute('aria-pressed', 'true'); 
        }
    } else {
        document.documentElement.classList.remove('dark-mode');
        const toggle = document.getElementById('theme-toggle');
         if (toggle) {
             toggle.setAttribute('aria-pressed', 'false'); 
        }
    }
}
export function initializeHeaderUi() {
    const header = document.querySelector('.page-header');
    if (!header) return;
    const themeToggle = document.getElementById('theme-toggle');
    if (themeToggle) {
        const currentTheme = document.documentElement.classList.contains('dark-mode') ? 'dark' : 'light';
        themeToggle.setAttribute('aria-pressed', currentTheme === 'dark');
        themeToggle.addEventListener('click', () => {
            const currentIsDark = document.documentElement.classList.contains('dark-mode');
            const newTheme = currentIsDark ? 'light' : 'dark';
            localStorage.setItem('theme', newTheme);
            applyTheme(newTheme);
             themeToggle.setAttribute('aria-pressed', newTheme === 'dark');
        });
    }
    const mobileToggle = document.getElementById('mobile-menu-toggle');
    const headerNav = document.getElementById('header-nav');
    if (mobileToggle && headerNav) {
        const closeMobileMenu = () => {
            mobileToggle.classList.remove('is-open');
            headerNav.classList.remove('is-open');
            document.body.classList.remove('menu-open'); 
            mobileToggle.setAttribute('aria-expanded', 'false');
        };
        mobileToggle.addEventListener('click', () => {
            const isOpen = headerNav.classList.contains('is-open');
            if (isOpen) {
                closeMobileMenu();
            } else {
                mobileToggle.classList.add('is-open');
                headerNav.classList.add('is-open');
                document.body.classList.add('menu-open'); 
                mobileToggle.setAttribute('aria-expanded', 'true');
            }
        });
        headerNav.addEventListener('click', (e) => {
            if (e.target.closest('a')) {
                if (window.innerWidth < 769) {
                     closeMobileMenu();
                }
            }
        });
    }
    const userMenu = header.querySelector('.user-menu');
    if (userMenu && !userMenu.dataset.menuInitialized) {
        const toggleButton = userMenu.querySelector('.user-menu-toggle');
        const dropdown = userMenu.querySelector('.user-menu-dropdown');
        if (toggleButton && dropdown) {
            toggleButton.addEventListener('click', (e) => {
                e.stopPropagation(); 
                const isOpen = dropdown.classList.toggle('is-open');
                toggleButton.setAttribute('aria-expanded', isOpen);
            });
            document.addEventListener('click', (e) => {
                if (!userMenu.contains(e.target) && dropdown.classList.contains('is-open')) {
                    dropdown.classList.remove('is-open');
                    toggleButton.setAttribute('aria-expanded', 'false');
                }
            });
            dropdown.addEventListener('click', (e) => {
                if (e.target.closest('a')) {
                     dropdown.classList.remove('is-open');
                     toggleButton.setAttribute('aria-expanded', 'false');
                }
            });
            userMenu.dataset.menuInitialized = 'true'; 
        }
    }
}