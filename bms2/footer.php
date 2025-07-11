</div> <!-- Closing content-wrapper -->
    <footer class="bg-gray-800 text-white p-4 text-center shadow-md w-full mt-auto">
        <div class="container mx-auto">
            <p>&copy; <?php echo date("Y"); ?> Bank Management System. All rights reserved.</p>
        </div>
    </footer>
    <!-- <script>
    // Counter for how many times the user has switched away from the tab
    let tabSwitchCount = 0;
    // Maximum allowed tab switches before logout
    const maxTabSwitches = 2; // User requested 2 times

    // Function to handle visibility change
    function handleVisibilityChange() {
        if (document.hidden) {
            // Tab is hidden (user switched away or minimized)
            tabSwitchCount++;
            console.log("User left the tab! Count: " + tabSwitchCount);

            // Remove any existing warning message box before showing a new one
            const existingMessageBox = document.querySelector('.warning-message-box');
            if (existingMessageBox) {
                existingMessageBox.remove();
            }

            if (tabSwitchCount >= maxTabSwitches) {
                // User has exceeded the allowed tab switches, force logout
                const sessionEndMessageBox = document.createElement('div');
                sessionEndMessageBox.className = 'warning-message-box'; // Add a class for easy removal
                sessionEndMessageBox.style.cssText = `
                    position: fixed;
                    top: 50%;
                    left: 50%;
                    transform: translate(-50%, -50%);
                    background-color: #f8d7da;
                    color: #721c24;
                    border: 1px solid #f5c6cb;
                    padding: 20px;
                    border-radius: 8px;
                    box-shadow: 0 4px 8px rgba(0,0,0,0.2);
                    z-index: 1000;
                    text-align: center;
                    width: 90%;
                    max-width: 400px;
                `;
                sessionEndMessageBox.innerHTML = `
                    <p class="font-bold text-lg mb-2">Session Ended!</p>
                    <p>You have navigated away from the page too many times. Your session has been terminated.</p>
                    <button onclick="window.location.href='logout';" class="mt-4 bg-red-600 hover:bg-red-700 text-white font-bold py-2 px-4 rounded-md shadow-md transition duration-300 ease-in-out">
                        Go to Login
                    </button>
                `;
                document.body.appendChild(sessionEndMessageBox);

                // Immediately redirect to logout after a short delay to ensure message is seen
                setTimeout(() => {
                    window.location.href = 'logout'; // Redirect to logout page (clean URL)
                }, 3000); // Redirect after 3 seconds
            } else {
                // User has not exceeded the limit, show a warning
                const messageBox = document.createElement('div');
                messageBox.className = 'warning-message-box'; // Add a class for easy removal
                messageBox.style.cssText = `
                    position: fixed;
                    top: 50%;
                    left: 50%;
                    transform: translate(-50%, -50%);
                    background-color: #fff3cd; /* Light yellow for warning */
                    color: #856404; /* Dark yellow text */
                    border: 1px solid #ffeeba;
                    padding: 20px;
                    border-radius: 8px;
                    box-shadow: 0 4px 8px rgba(0,0,0,0.2);
                    z-index: 1000;
                    text-align: center;
                    width: 90%;
                    max-width: 400px;
                `;
                messageBox.innerHTML = `
                    <p class="font-bold text-lg mb-2">Warning!</p>
                    <p>You have navigated away from the page. Please return to continue. (${tabSwitchCount}/${maxTabSwitches})</p>
                    <button onclick="this.parentNode.remove()" class="mt-4 bg-blue-500 hover:bg-blue-600 text-white font-bold py-2 px-4 rounded-md shadow-md transition duration-300 ease-in-out">
                        OK
                    </button>
                `;
                document.body.appendChild(messageBox);
            }
        } else {
            // Tab is visible again (user returned)
            console.log("User returned to the tab.");
            // Remove any warning messages if they exist
            const existingMessageBox = document.querySelector('.warning-message-box');
            if (existingMessageBox) {
                existingMessageBox.remove();
            }
        }
    }

    // Function to handle window blur/focus (less critical for this specific requirement, but good for logging)
    function handleFocusChange() {
        if (!document.hasFocus()) {
            console.log("Window lost focus!");
        } else {
            console.log("Window gained focus.");
        }
    }

    // Add event listeners when the DOM is fully loaded
    document.addEventListener('DOMContentLoaded', function() {
        // Listen for visibility changes (tab switching, minimizing)
        document.addEventListener("visibilitychange", handleVisibilityChange);

        // Listen for window focus/blur (switching applications)
        // This is kept for logging but doesn't trigger the logout counter
        window.addEventListener("blur", handleFocusChange);
        window.addEventListener("focus", handleFocusChange);

        // Optional: Request full-screen mode on page load (user might need to confirm)
        // This is generally not recommended for a standard website as it can be intrusive.
        // document.documentElement.requestFullscreen().catch(err => {
        //     console.warn("Full-screen request denied:", err);
        // });
    });
</script> -->
<script>
    // Counter for how many times the user has switched away from the tab
    let tabSwitchCount = 0;
    const maxTabSwitches = 2;
    let isSessionTerminated = false;
    let isLegitimateLogout = false;
    
    // Track if we're processing an internal navigation
    let isInternalNavigation = false;

    // Function to handle visibility change
    function handleVisibilityChange() {
        if (isSessionTerminated || isInternalNavigation) {
            return;
        }

        if (document.hidden) {
            tabSwitchCount++;
            console.log("User left the tab! Count: " + tabSwitchCount);

            const existingMessageBox = document.querySelector('.warning-message-box');
            if (existingMessageBox) {
                existingMessageBox.remove();
            }

            if (tabSwitchCount >= maxTabSwitches) {
                terminateSession("You have navigated away from the page too many times.");
            } else {
                const messageBox = document.createElement('div');
                messageBox.className = 'warning-message-box';
                messageBox.style.cssText = `
                    position: fixed;
                    top: 50%;
                    left: 50%;
                    transform: translate(-50%, -50%);
                    background-color: #fff3cd;
                    color: #856404;
                    border: 1px solid #ffeeba;
                    padding: 20px;
                    border-radius: 8px;
                    box-shadow: 0 4px 8px rgba(0,0,0,0.2);
                    z-index: 9999;
                    text-align: center;
                    width: 90%;
                    max-width: 400px;
                `;
                messageBox.innerHTML = `
                    <p class="font-bold text-lg mb-2">Warning!</p>
                    <p>You have navigated away from the page. Please return to continue. (${tabSwitchCount}/${maxTabSwitches})</p>
                    <button onclick="this.parentNode.remove()" class="mt-4 bg-blue-500 hover:bg-blue-600 text-white font-bold py-2 px-4 rounded-md shadow-md transition duration-300 ease-in-out">
                        OK
                    </button>
                `;
                document.body.appendChild(messageBox);
            }
        } else {
            console.log("User returned to the tab.");
            if (!isSessionTerminated) {
                const existingMessageBox = document.querySelector('.warning-message-box');
                if (existingMessageBox) {
                    existingMessageBox.remove();
                }
            }
        }
    }

    // Function to terminate the session
    function terminateSession(reason) {
        if (isSessionTerminated) return;
        
        isSessionTerminated = true;
        isLegitimateLogout = true;

        const contentWrapper = document.querySelector('.content-wrapper');
        if (contentWrapper) {
            contentWrapper.style.display = 'none';
        }

        const existingMessageBox = document.querySelector('.warning-message-box');
        if (existingMessageBox) {
            existingMessageBox.remove();
        }

        const sessionEndMessageBox = document.createElement('div');
        sessionEndMessageBox.className = 'warning-message-box';
        sessionEndMessageBox.style.cssText = `
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 4px 8px rgba(0,0,0,0.2);
            z-index: 9999;
            text-align: center;
            width: 90%;
            max-width: 400px;
        `;
        sessionEndMessageBox.innerHTML = `
            <p class="font-bold text-lg mb-2">Session Ended!</p>
            <p>${reason} Your session has been terminated.</p>
            <button onclick="window.location.href='logout';" class="mt-4 bg-red-600 hover:bg-red-700 text-white font-bold py-2 px-4 rounded-md shadow-md transition duration-300 ease-in-out">
                Go to Login
            </button>
        `;
        document.body.appendChild(sessionEndMessageBox);

        setTimeout(() => {
            window.location.href = 'logout';
        }, 1000);
    }

    // Track internal navigation
    document.addEventListener('DOMContentLoaded', function() {
        // Mark internal navigation start
        document.querySelectorAll('a').forEach(link => {
            link.addEventListener('click', function() {
                if (this.href && this.href.includes(window.location.hostname)) {
                    isInternalNavigation = true;
                    setTimeout(() => { isInternalNavigation = false; }, 1000);
                }
            });
        });

        // Also handle form submissions
        document.querySelectorAll('form').forEach(form => {
            form.addEventListener('submit', function() {
                if (this.action && this.action.includes(window.location.hostname)) {
                    isInternalNavigation = true;
                    setTimeout(() => { isInternalNavigation = false; }, 1000);
                }
            });
        });

        // Full-screen handling
        document.documentElement.requestFullscreen().catch(err => {
            console.warn("Full-screen request denied:", err);
        });

        // Event listeners
        document.addEventListener("visibilitychange", handleVisibilityChange);
        window.addEventListener("blur", handleFocusChange);
        window.addEventListener("focus", handleFocusChange);

        document.addEventListener('fullscreenchange', function() {
            if (!document.fullscreenElement && !isSessionTerminated && !isInternalNavigation) {
                terminateSession("You exited full-screen mode.");
            }
        });

        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                if (isSessionTerminated) return;
                
                if (document.fullscreenElement) {
                    console.log("Escape pressed while in full-screen. Exiting full-screen.");
                } else {
                    console.log("Escape pressed while not in full-screen. Navigating back.");
                    history.back();
                    event.preventDefault();
                }
            }
        });
    });
</script>



</body>
</html>
