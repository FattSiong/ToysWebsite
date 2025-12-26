document.addEventListener("DOMContentLoaded", function () {
    // Select all elements with the class 'toggle-password'
    const toggleButtons = document.querySelectorAll(".toggle-password");

    toggleButtons.forEach(button => {
        button.addEventListener("click", function () {
            // 1. Try to get the input ID from the 'data-target' attribute (used in register.php)
            const targetId = this.getAttribute("data-target");
            let input = targetId ? document.getElementById(targetId) : null;

            // 2. If not found, fallback to finding the input within the same parent wrapper (fixes login.php)
            if (!input) {
                input = this.parentElement.querySelector("input");
            }

            if (input) {
                // Check current state
                const currentType = input.getAttribute("type");
                
                if (currentType === "password") {
                    // Switch to Visible
                    input.setAttribute("type", "text");
                    
                    // Toggle Icons: Remove slash, add eye
                    this.classList.remove("icon-eye-slash");
                    this.classList.add("fa-eye");
                } else {
                    // Switch to Hidden
                    input.setAttribute("type", "password");
                    
                    // Toggle Icons: Remove eye, add slash
                    this.classList.remove("fa-eye");
                    this.classList.add("icon-eye-slash");
                }
            }
        });
    });
});