(function() {
    document.addEventListener("DOMContentLoaded", function(event) {
        let neosNotificationContainer = document.getElementById('neos-notification-container');
        if (neosNotificationContainer) {
            setTimeout(function() {
                neosNotificationContainer.style.display = "none";
            }, 10000);
        }
    });
})();


