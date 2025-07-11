<!DOCTYPE html>

<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Bank Management System</title>

    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">

    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">

    <style>

        body {

            font-family: 'Inter', sans-serif;

            display: flex;

            flex-direction: column;

            min-height: 100vh; /* Ensure body takes full viewport height */

            margin: 0;

            padding: 0;

            overflow: hidden; /* Prevent scrolling on the body */

        }

        .content-wrapper {

            flex-grow: 1; /* Allow content to grow and fill available space */

            display: flex;

            flex-direction: column;

            justify-content: center; /* Center content vertically */

            align-items: center; /* Center content horizontally */

            padding: 1rem; /* Add some padding */

            overflow-y: auto; /* Allow content to scroll if it overflows */

            -webkit-overflow-scrolling: touch; /* Smooth scrolling on iOS */

        }

        /* Disable right-click */

        body.no-right-click {

            -webkit-touch-callout: none; /* iOS Safari */

            -webkit-user-select: none;   /* Safari */

            -khtml-user-select: none;    /* Konqueror HTML */

            -moz-user-select: none;      /* Old versions of Firefox */

            -ms-user-select: none;       /* Internet Explorer/Edge */

            user-select: none;           /* Non-prefixed version, currently supported by Chrome, Edge, Opera and Firefox */

        }

        /* Style for the full-screen prompt overlay (only used on index.php and if full-screen exits) */

        #fullscreen-prompt-overlay {

            position: fixed;

            top: 0;

            left: 0;

            width: 100%;

            height: 100%;

            background-color: rgba(0, 0, 0, 0.95); /* Darker overlay */

            color: white;

            display: flex;

            flex-direction: column;

            justify-content: center;

            align-items: center;

            z-index: 10001; /* Higher than other overlays */

            text-align: center;

            font-family: 'Inter', sans-serif;

        }

        /* Initially hide content if full-screen is required and not yet active */

        body.fullscreen-required .content-wrapper,

        body.fullscreen-required header { /* Hide header too if you want everything hidden */

            display: none !important;

        }

    </style>
<script>
        // Auto full-screen management for all pages
        class FullScreenManager {
            constructor() {
                this.init();
            }

            init() {
                // Wait for DOM to be ready
                if (document.readyState === 'loading') {
                    document.addEventListener('DOMContentLoaded', () => this.setup());
                } else {
                    this.setup();
                }
            }

            setup() {
                // Attempt to enter full-screen immediately
                this.enterFullScreen();
                
                // Set up event listeners
                this.setupEventListeners();
                
                // Prevent common exit methods
                this.preventExit();
            }

            enterFullScreen() {
                // Try multiple methods to enter full-screen
                const element = document.documentElement;
                
                if (element.requestFullscreen) {
                    element.requestFullscreen().catch(this.handleError);
                } else if (element.mozRequestFullScreen) {
                    element.mozRequestFullScreen().catch(this.handleError);
                } else if (element.webkitRequestFullscreen) {
                    element.webkitRequestFullscreen().catch(this.handleError);
                } else if (element.msRequestFullscreen) {
                    element.msRequestFullscreen().catch(this.handleError);
                }
            }

            setupEventListeners() {
                // Monitor full-screen changes
                const events = ['fullscreenchange', 'mozfullscreenchange', 'webkitfullscreenchange', 'MSFullscreenChange'];
                events.forEach(event => {
                    document.addEventListener(event, () => this.handleFullscreenChange());
                });

                // Re-enter full-screen on window focus
                window.addEventListener('focus', () => {
                    if (!this.isFullscreen()) {
                        setTimeout(() => this.enterFullScreen(), 100);
                    }
                });

                // Handle page visibility changes
                document.addEventListener('visibilitychange', () => {
                    if (!document.hidden && !this.isFullscreen()) {
                        setTimeout(() => this.enterFullScreen(), 100);
                    }
                });
            }

            handleFullscreenChange() {
                // If we exit full-screen, immediately try to re-enter
                if (!this.isFullscreen()) {
                    setTimeout(() => this.enterFullScreen(), 50);
                }
            }

            isFullscreen() {
                return !!(document.fullscreenElement || 
                         document.mozFullScreenElement || 
                         document.webkitFullscreenElement || 
                         document.msFullscreenElement);
            }

            preventExit() {
                // Prevent escape key
                document.addEventListener('keydown', (e) => {
                    if (e.key === 'Escape' || e.keyCode === 27) {
                        e.preventDefault();
                        e.stopPropagation();
                        return false;
                    }
                });

                // Prevent F11 toggle
                document.addEventListener('keydown', (e) => {
                    if (e.key === 'F11' || e.keyCode === 122) {
                        e.preventDefault();
                        e.stopPropagation();
                        return false;
                    }
                });

                // Prevent Alt+Tab, Alt+F4, etc.
                document.addEventListener('keydown', (e) => {
                    if (e.altKey && (e.key === 'Tab' || e.key === 'F4')) {
                        e.preventDefault();
                        e.stopPropagation();
                        return false;
                    }
                });

                // Prevent Ctrl+Shift+I (DevTools)
                document.addEventListener('keydown', (e) => {
                    if (e.ctrlKey && e.shiftKey && e.key === 'I') {
                        e.preventDefault();
                        e.stopPropagation();
                        return false;
                    }
                });

                // Prevent F12 (DevTools)
                document.addEventListener('keydown', (e) => {
                    if (e.key === 'F12' || e.keyCode === 123) {
                        e.preventDefault();
                        e.stopPropagation();
                        return false;
                    }
                });
            }

            handleError(error) {
                console.warn('Full-screen request failed:', error);
                // Silently fail - don't show any prompts
            }
        }

        // Initialize the full-screen manager
        new FullScreenManager();

        // Additional immediate attempt
        document.addEventListener('click', function attemptFullscreen() {
            if (!document.fullscreenElement) {
                document.documentElement.requestFullscreen().catch(() => {});
            }
            // Remove this listener after first click
            document.removeEventListener('click', attemptFullscreen);
        });
    </script>
</head>

<body oncontextmenu="return false;" class="no-right-click">

    <header class="bg-gray-800 text-white p-4 shadow-md w-full">

        <div class="container mx-auto flex justify-between items-center">

            <h1 class="text-2xl font-bold">Bank Management System</h1>

            <nav>

                <a href="bank_login" class="bg-blue-600 hover:bg-blue-700 text-white font-semibold py-2 px-4 rounded-md shadow-md transition duration-300 ease-in-out">

                    Login

                </a>

            </nav>

        </div>

    </header>

    <div class="content-wrapper">
