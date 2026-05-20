/**
 * Global Admin Dashboard Scripts & Micro-interactions
 */

// Modal open/close helpers
function openModal(id) {
    const modal = document.getElementById(id);
    if (modal) {
        modal.classList.add('active');
        document.body.style.overflow = 'hidden'; // Disable page scrolling
    }
}

function closeModal(id) {
    const modal = document.getElementById(id);
    if (modal) {
        modal.classList.remove('active');
        document.body.style.overflow = ''; // Re-enable page scrolling
    }
}

// Close modals when clicking outside the box
window.addEventListener('click', function(e) {
    if (e.target.classList.contains('modal')) {
        e.target.classList.remove('active');
        document.body.style.overflow = '';
    }
});

// Dynamic hover effects and animations for table rows and buttons
document.addEventListener('DOMContentLoaded', () => {
    // Animate statistics cards on page load
    const statCards = document.querySelectorAll('.stat-card');
    statCards.forEach((card, index) => {
        card.style.opacity = '0';
        card.style.transform = 'translateY(15px)';
        card.style.transition = 'all 0.4s ease';
        
        setTimeout(() => {
            card.style.opacity = '1';
            card.style.transform = 'translateY(0)';
        }, 100 * index);
    });

    // Theme Switcher Logic
    const themeSwitch = document.getElementById('themeSwitch');
    if (themeSwitch) {
        const options = themeSwitch.querySelectorAll('.theme-switch-option');
        const currentTheme = document.documentElement.getAttribute('data-theme') || 'dark';
        
        // Match active class with loaded theme
        options.forEach(opt => {
            if (opt.getAttribute('data-theme') === currentTheme) {
                opt.classList.add('active');
            } else {
                opt.classList.remove('active');
            }
        });

        // Add event listeners to toggle options
        options.forEach(button => {
            button.addEventListener('click', () => {
                const targetTheme = button.getAttribute('data-theme');
                
                // Toggle theme on HTML
                document.documentElement.setAttribute('data-theme', targetTheme);
                // Save to localStorage
                localStorage.setItem('theme', targetTheme);
                
                // Toggle active class on option buttons
                options.forEach(opt => opt.classList.remove('active'));
                button.classList.add('active');
            });
        });
    }
});
