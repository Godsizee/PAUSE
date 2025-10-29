// public/assets/js/header-ui.js

/**
 * Applies the selected theme (light/dark) to the document.
 * @param {string} theme - 'light' or 'dark'.
 */
function applyTheme(theme) {
    if (theme === 'dark') {
        document.documentElement.classList.add('dark-mode');
        // Update toggle button if it exists (might run before button is ready)
        const toggle = document.getElementById('theme-toggle');
        if (toggle) {
             toggle.setAttribute('aria-pressed', 'true'); // Indicate dark mode is active
        }
    } else {
        document.documentElement.classList.remove('dark-mode');
        // Update toggle button if it exists
        const toggle = document.getElementById('theme-toggle');
         if (toggle) {
             toggle.setAttribute('aria-pressed', 'false'); // Indicate light mode is active
        }
    }
}


/**
 * Initializes header UI interactions: mobile menu toggle, user dropdown, and theme toggle.
 */
export function initializeHeaderUi() {
    const header = document.querySelector('.page-header');
    if (!header) return;

    // --- Theme Toggle Handling ---
    const themeToggle = document.getElementById('theme-toggle');
    if (themeToggle) {
        // Set initial state of the button based on current theme (already applied by inline script)
        const currentTheme = document.documentElement.classList.contains('dark-mode') ? 'dark' : 'light';
        themeToggle.setAttribute('aria-pressed', currentTheme === 'dark');


        themeToggle.addEventListener('click', () => {
            const currentIsDark = document.documentElement.classList.contains('dark-mode');
            const newTheme = currentIsDark ? 'light' : 'dark';
            localStorage.setItem('theme', newTheme);
            applyTheme(newTheme);
             // Update aria-pressed state after applying theme
             themeToggle.setAttribute('aria-pressed', newTheme === 'dark');
        });
    }


    // --- Mobile Menu Handling ---
    const mobileToggle = document.getElementById('mobile-menu-toggle');
    const headerNav = document.getElementById('header-nav');

    if (mobileToggle && headerNav) {

        const closeMobileMenu = () => {
            mobileToggle.classList.remove('is-open');
            headerNav.classList.remove('is-open');
            document.body.classList.remove('menu-open'); // Allow scrolling again
            mobileToggle.setAttribute('aria-expanded', 'false');
        };

        mobileToggle.addEventListener('click', () => {
            const isOpen = headerNav.classList.contains('is-open');
            if (isOpen) {
                closeMobileMenu();
            } else {
                mobileToggle.classList.add('is-open');
                headerNav.classList.add('is-open');
                document.body.classList.add('menu-open'); // Prevent scrolling
                mobileToggle.setAttribute('aria-expanded', 'true');
            }
        });

        // Close mobile menu when a navigation link inside it is clicked
        headerNav.addEventListener('click', (e) => {
            // Check if the clicked element or its parent is a link within the nav
            if (e.target.closest('a')) {
                // Check if the screen width is mobile (where the overlay is active)
                if (window.innerWidth < 769) {
                     closeMobileMenu();
                }
            }
        });
    }

    // --- User Dropdown Handling (Desktop) ---
    const userMenu = header.querySelector('.user-menu');
    // Add a check to prevent re-initializing if script runs multiple times
    if (userMenu && !userMenu.dataset.menuInitialized) {
        const toggleButton = userMenu.querySelector('.user-menu-toggle');
        const dropdown = userMenu.querySelector('.user-menu-dropdown');

        if (toggleButton && dropdown) {
            toggleButton.addEventListener('click', (e) => {
                e.stopPropagation(); // Prevent document click listener from closing it immediately
                const isOpen = dropdown.classList.toggle('is-open');
                toggleButton.setAttribute('aria-expanded', isOpen);
            });

            // Close dropdown if clicking outside of it
            document.addEventListener('click', (e) => {
                // Check if the click was outside the user menu and the dropdown is open
                if (!userMenu.contains(e.target) && dropdown.classList.contains('is-open')) {
                    dropdown.classList.remove('is-open');
                    toggleButton.setAttribute('aria-expanded', 'false');
                }
            });

            // Close dropdown if a link inside it is clicked
            dropdown.addEventListener('click', (e) => {
                if (e.target.closest('a')) {
                     dropdown.classList.remove('is-open');
                     toggleButton.setAttribute('aria-expanded', 'false');
                }
            });

            userMenu.dataset.menuInitialized = 'true'; // Mark as initialized
        }
    }
}

// Apply initial theme on script load (redundant with inline script but safe)
// const initialTheme = localStorage.getItem('theme') || (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
// applyTheme(initialTheme);
// Removed this as the inline script in header.php handles the initial, flash-preventing application.