// js/verify_phone.js

$(document).ready(function() {
    
    // Handle "Send OTP" button click
    $('#btnSendOtp').click(function() {
        var phone = $('#phone_number').val();
        var btn = $(this);

        // Basic client-side validation
        if(phone.length < 9) {
            alert('Please enter a valid phone number first.');
            return;
        }

        // Disable button to prevent double-clicking/spamming
        btn.prop('disabled', true).text('Sending...');

        // Perform AJAX request to the PHP backend
        $.ajax({
            url: 'verify_phone.php', // Target the same file
            type: 'POST',
            data: { 
                ajax_action: 'send_otp',
                phone: phone 
            },
            dataType: 'json',
            success: function(response) {
                if(response.success) {
                    // Success: Show the OTP (simulated SMS)
                    alert(response.message); 
                    
                    // Update UI to show it was sent
                    $('#otpHelp').show().text(response.message);
                    btn.text('Resend');
                } else {
                    // Error from server (e.g., invalid format)
                    alert(response.message);
                    btn.text('Send OTP');
                }
            },
            error: function() {
                alert('System error: Could not connect to server.');
                btn.text('Send OTP');
            },
            complete: function() {
                // Re-enable the button after 3 seconds (cool-down period)
                setTimeout(function(){ 
                    btn.prop('disabled', false); 
                }, 3000);
            }
        });
    });
});