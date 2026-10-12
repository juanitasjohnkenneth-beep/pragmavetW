// theme.js
function applySystemTheme() {
    const isDarkMode = window.matchMedia('(prefers-color-scheme: dark)').matches;
    document.documentElement.setAttribute('data-theme', isDarkMode ? 'dark' : 'light');
    
    // Attempt to update icon if it somehow still shows
    const themeIcon = document.getElementById("themeIcon");
    if(themeIcon) {
        if (isDarkMode) {
            themeIcon.classList.remove("fa-moon");
            themeIcon.classList.add("fa-sun");
        } else {
            themeIcon.classList.remove("fa-sun");
            themeIcon.classList.add("fa-moon");
        }
    }
}

// Immediately apply theme state on script load to prevent flash
(function () {
    applySystemTheme();
})();

// Listen for system theme changes in real-time
window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', applySystemTheme);

// Wait for DOM to attach events
document.addEventListener("DOMContentLoaded", () => {
    // Hide the manual toggle button since we use system auto now
    const themeToggleBtn = document.getElementById("themeToggle");
    if(themeToggleBtn) {
        themeToggleBtn.style.display = 'none';
    }
    applySystemTheme();
});
