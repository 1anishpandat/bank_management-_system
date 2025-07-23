
<style>
        /* Complete CSS for Bank Management System Layout */
        body {
            font-family: 'Inter', sans-serif;
            display: flex;
            flex-direction: column;
            min-height: 100vh;
            margin: 0;
            padding: 0;
            background-color: #f9fafb;
        }

        /* Header styling */
        header {
            flex-shrink: 0; /* Prevent header from shrinking */
            z-index: 100;
        }

        /* Content wrapper - this is the key fix */
        .content-wrapper {
            flex: 1; /* Take remaining space between header and footer */
            display: flex;
            flex-direction: column;
            padding: 2rem 1rem; /* Add padding for content spacing */
            overflow-y: auto; /* Allow scrolling if content is too tall */
            -webkit-overflow-scrolling: touch; /* Smooth scrolling on iOS */
        }

        /* Footer styling */
        footer {
            flex-shrink: 0; /* Prevent footer from shrinking */
            margin-top: auto; /* Push footer to bottom */
        }

        /* For pages with centered content (like login forms) */
        .content-wrapper.centered {
            justify-content: center;
            align-items: center;
        }

        /* For pages with regular content flow */
        .content-wrapper.flow {
            justify-content: flex-start;
            align-items: stretch;
        }

        /* Container for content with max-width */
        .content-container {
            width: 100%;
            max-width: 1200px;
            margin: 0 auto;
        }

        /* Disable right-click */
        body.no-right-click {
            -webkit-touch-callout: none;
            -webkit-user-select: none;
            -khtml-user-select: none;
            -moz-user-select: none;
            -ms-user-select: none;
            user-select: none;
        }

        /* Form styling for better appearance */
        .form-container {
            background: white;
            padding: 2rem;
            border-radius: 0.5rem;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            width: 100%;
            max-width: 400px;
        }

        /* Card styling for content sections */
        .content-card {
            background: white;
            padding: 1.5rem;
            border-radius: 0.5rem;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            margin-bottom: 1rem;
        }

        /* Table responsive wrapper */
        .table-wrapper {
            overflow-x: auto;
            margin: 1rem 0;
        }

        /* Ensure tables are responsive */
        table {
            min-width: 100%;
        }

        /* Warning message box styling */
        .warning-message-box {
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            z-index: 9999;
            font-family: 'Inter', sans-serif;
        }

        /* Fullscreen prompt overlay styling */
        #fullscreen-prompt-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.95);
            color: white;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            z-index: 10001;
            text-align: center;
            font-family: 'Inter', sans-serif;
        }

        /* Hide content if fullscreen is required */
        body.fullscreen-required .content-wrapper,
        body.fullscreen-required header {
            display: none !important;
        }

        /* Responsive adjustments */
        @media (max-width: 768px) {
            .content-wrapper {
                padding: 1rem 0.5rem;
            }

            .form-container {
                padding: 1.5rem;
                max-width: 90%;
            }

            .content-card {
                padding: 1rem;
            }

            .warning-message-box {
                width: 95% !important;
                max-width: 350px !important;
            }
        }

        @media (max-width: 480px) {
            .content-wrapper {
                padding: 0.5rem 0.25rem;
            }

            .form-container {
                padding: 1rem;
            }

            .content-card {
                padding: 0.75rem;
            }

            header .container {
                padding: 0 1rem;
            }

            footer .container {
                padding: 0 1rem;
            }
        }

        /* Animation for smooth transitions */
        .warning-message-box {
            animation: fadeIn 0.3s ease-in-out;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translate(-50%, -60%);
            }
            to {
                opacity: 1;
                transform: translate(-50%, -50%);
            }
        }

        /* Button hover effects */
        .btn-hover {
            transition: all 0.3s ease;
        }

        .btn-hover:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
        }

        /* Loading state for forms */
        .loading {
            opacity: 0.7;
            pointer-events: none;
        }

        /* Custom scrollbar for webkit browsers */
        .content-wrapper::-webkit-scrollbar {
            width: 8px;
        }

        .content-wrapper::-webkit-scrollbar-track {
            background: #f1f1f1;
        }

        .content-wrapper::-webkit-scrollbar-thumb {
            background: #c1c1c1;
            border-radius: 4px;
        }

        .content-wrapper::-webkit-scrollbar-thumb:hover {
            background: #a8a8a8;
        }

        /* Focus styles for accessibility */
        input:focus,
        button:focus,
        select:focus,
        textarea:focus {
            outline: 2px solid #3b82f6;
            outline-offset: 2px;
        }

        /* Print styles */
        @media print {
            header,
            footer,
            .warning-message-box {
                display: none !important;
            }

            .content-wrapper {
                padding: 0;
            }

            body {
                background: white;
            }
        }
    </style>
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

        // Completely disable Escape key
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                console.log("Escape key pressed - action prevented");
                event.preventDefault();
                event.stopPropagation();
                return false;
            }
        });
    });
</script>



</body>
</html>
