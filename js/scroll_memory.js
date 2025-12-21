document.addEventListener("DOMContentLoaded", function() {
    // 1. Generate a unique key for this page (e.g., "scroll_pos_/profile.php")
    // We use 'pathname' so it ignores query parameters like ?edit_id=1
    const pageKey = "scroll_pos_" + window.location.pathname;

    // 2. Restore Scroll Position if it exists for THIS page
    const savedPos = sessionStorage.getItem(pageKey);
    if (savedPos) {
        window.scrollTo(0, parseInt(savedPos));
        sessionStorage.removeItem(pageKey); // Clear it after use
    }

    // 3. Define the Save Function
    function saveScrollPosition() {
        sessionStorage.setItem(pageKey, window.scrollY);
    }

    // 4. Attach Listeners to any element with class 'scroll-trigger'
    // This works for Links (<a>), Buttons (<button>), and Forms (<form>)
    const triggers = document.querySelectorAll('.scroll-trigger');
    
    triggers.forEach(el => {
        if (el.tagName === 'FORM') {
            // For forms, save on 'submit'
            el.addEventListener('submit', saveScrollPosition);
        } else {
            // For links and buttons, save on 'click'
            el.addEventListener('click', saveScrollPosition);
        }
    });
});