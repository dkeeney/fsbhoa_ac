const statusMessage = document.getElementById('status-message');
const lastCardSwipeDiv = document.getElementById('last-card-swipe');
const amenityButtonsDiv = document.getElementById('amenity-buttons');
const idleScreen = document.getElementById('idle-screen');
const logoImage = document.getElementById('logo-image');
const cardDisplay = document.getElementById('card-display');
const cardPhoto = document.getElementById('card-photo');
const cardName = document.getElementById('card-name');
const cardReaderInput = document.getElementById('card-reader-input');
const amenityButtonsContainer = document.getElementById('amenity-buttons-container');
const guestButtonsContainer = document.getElementById('guest-buttons-container');
const guestButtonsDiv = document.getElementById('guest-buttons');
const splashScreen = document.getElementById('splash-screen'); 
const splashImage = document.getElementById('splash-image'); 
const AMENITY_SELECTION_TIMEOUT = 30000; // After swipe Timeout in milliseconds (30 seconds)

let idleTimeout; // This will hold our after swipe timer
let sessionActive = false;
let idleTimerFired = false;
let resetKioskTimer = null; // This will hold our reset timer
let selectedGuestCount = null;
let rfidTimeout;
let kioskConfig = {};
let lastSwipedCard = null;
let socket = null;
let audioCtx;
let hasConnectedBefore = false; // Flag to track initial connection
let longPressTimer;

// logging function
function logToScreen(message) {
    console.log(message);   // in case running on a PC
    // else, for when using the touchscreen
    const logContainer = document.getElementById('debug-log');
    if (logContainer) {
        const timestamp = new Date().toLocaleTimeString();
        const newMessage = document.createElement('div');
        newMessage.textContent = `${timestamp}: ${message}`;
        logContainer.appendChild(newMessage);
        // Auto-scroll to the bottom
        logContainer.scrollTop = logContainer.scrollHeight;
    }
}

// Function to play beeps
function beep(count, volume, duration) {
    if (!audioCtx) {
        audioCtx = new (window.AudioContext || window.webkitAudioContext)();
    }
    for (let i = 0; i < count; i++) {
        setTimeout(() => {
            const oscillator = audioCtx.createOscillator();
            const gainNode = audioCtx.createGain();
            oscillator.connect(gainNode);
            gainNode.connect(audioCtx.destination);
            gainNode.gain.value = volume;
            oscillator.frequency.value = 880;
            oscillator.type = 'sine';
            oscillator.start(audioCtx.currentTime);
            oscillator.stop(audioCtx.currentTime + (duration / 1000));
        }, i * (duration + 100));
    }
}

function connect() {
    const protocol = window.location.protocol === 'https:' ? 'wss:' : 'ws:';
    const socketURL = `${protocol}//${window.location.host}/ws`;

    logToScreen(`Connecting to WebSocket at: ${socketURL}`);
    socket = new WebSocket(socketURL);

    socket.addEventListener('open', () => {
        if (hasConnectedBefore) {
            // The reload was causing a reconnect loop. A stable connection is sufficient.
            // location.reload(true); 
            resetKiosk(); // Reset to the idle screen on reconnect
        } else {
            hasConnectedBefore = true;
            resetKiosk();
        }
    });

    socket.addEventListener('message', (event) => {
        try {
            const message = JSON.parse(event.data);
            logToScreen("Received message object:", message);

            if (message.event === 'kioskConfig') {
                kioskConfig = message.payload;
                if (kioskConfig.logo_url) {
                    logoImage.src = kioskConfig.logo_url;
                    logoImage.style.display = 'block';
                }
                if (kioskConfig.splash_url) {
                    splashImage.src = kioskConfig.splash_url;
                }
            } else if (message.event === 'cardSwiped') {
                const swipeData = message.payload;

                if (swipeData.isValid) {
                    sessionActive = true;
                    lastSwipedCard = swipeData.rfid;
                    stopFocusCapture();
                    idleScreen.style.display = 'none';
                    document.getElementById('main-layout-table').style.display = 'table'; // Use new table ID

                    statusMessage.textContent = '';

                    cardName.textContent = swipeData.cardholder.name;
                    if (swipeData.cardholder.photo) {
                        cardPhoto.src = `data:image/jpeg;base64,${swipeData.cardholder.photo}`;
                    } else {
                        cardPhoto.src = '';
                    }
                    cardDisplay.className = 'card-display-visible';

                    selectedGuestCount = 0;

                    guestButtonsContainer.style.display = 'block';
                    amenityButtonsContainer.style.display = 'block';
                    
                    createGuestButtons();
                    createAmenityButtons(kioskConfig.amenities);

                    const zeroButton = document.querySelector('.guest-button[data-count="0"]');
                    if(zeroButton) {
                        zeroButton.classList.add('selected');
                    }
                    // Add these two lines to start the after-swipe idle timer
                    idleTimerFired = false;
                    clearTimeout(idleTimeout);
logToScreen(`TIMER: swiped - Setting 30-second idle timeout at ${new Date().toLocaleTimeString()}`);
                    idleTimeout = setTimeout(handleIdleTimeout, AMENITY_SELECTION_TIMEOUT);
logToScreen(`TIMER: >>>> New idleTimeout ID is: ${idleTimeout}`);
                } else {
                    statusMessage.textContent = swipeData.message;
                    statusMessage.style.color = 'red';
                    beep(2, 0.1, 150);
                    setTimeout(resetKiosk, 4000);
                }
            }
        } catch (e) {
            console.error("Failed to parse incoming message:", e);
        }
    });

    socket.addEventListener('close', () => {
        logToScreen('WebSocket connection closed. Retrying in 3 seconds.');
        statusMessage.textContent = 'Status: Disconnected. Attempting to reconnect...';
        statusMessage.style.color = 'orange';
        setTimeout(connect, 5000);
    });
}

function createGuestButtons() {
    while (guestButtonsDiv.firstChild) {
        guestButtonsDiv.removeChild(guestButtonsDiv.firstChild);
    }
    const maxGuests = kioskConfig.max_guests !== undefined ? kioskConfig.max_guests : 6;

    for (let i = 0; i <= maxGuests; i++) {
        const button = document.createElement('button');
        button.className = 'guest-button';
        button.textContent = i;
        button.dataset.count = i;

        button.addEventListener('click', function() {
            selectedGuestCount = parseInt(this.dataset.count, 10);
            document.querySelectorAll('.guest-button').forEach(btn => btn.classList.remove('selected'));
            this.classList.add('selected');
        });
        guestButtonsDiv.appendChild(button);
    }
}

function createAmenityButtons(amenities) {
    while (amenityButtonsDiv.firstChild) {
        amenityButtonsDiv.removeChild(amenityButtonsDiv.firstChild);
    }
    if (!amenities) return;

    amenities.forEach(amenity => {
        const button = document.createElement('button');
        button.className = 'amenity-button';
        button.dataset.name = amenity.name;

        if (amenity.image_url) {
            const img = document.createElement('img');
            img.src = amenity.image_url;
            button.appendChild(img);
        }

        const span = document.createElement('span');
        span.textContent = amenity.name;
        button.appendChild(span);

        const handleAmenitySelection = function(event) {
            logToScreen('Amenity button clicked/tapped.');

            // Prevent the browser from firing both touchstart and a simulated click
            event.preventDefault(); 
            logToScreen(`Checking session status. sessionActive is: ${sessionActive}`);
            if (!sessionActive) { return; }
            sessionActive = false;

            logToScreen(`TIMER: Clearing 30-second idle timeout at ${new Date().toLocaleTimeString()}`);
            if (idleTimerFired) { return; }
            clearTimeout(idleTimeout);
            clearTimeout(resetKioskTimer); 

            const selectedAmenityName = this.dataset.name;
            logToScreen(`Selected Amenity: ${selectedAmenityName}`);

            try {
                const messageToSend = {
                    event: 'amenitySelected',
                    payload: {
                        rfid: lastSwipedCard,
                        amenity: selectedAmenityName,
                        guests: selectedGuestCount
                    }
                };

                logToScreen(`About to send amenity selection: ${selectedAmenityName}`);
                socket.send(JSON.stringify(messageToSend));
                logToScreen('Successfully sent message to Go service.');

            } catch (e) {
                // If the send fails, this will catch the error and log it.
                logToScreen(`CRITICAL ERROR: Failed to send message via WebSocket. Error: ${e.message}`);
            }

            if (kioskConfig.splash_url) {
                // If it is, use it.
                splashImage.src = kioskConfig.splash_url;
            } else {
                // Otherwise, find the icon of the amenity that was just clicked.
                const selectedAmenity = kioskConfig.amenities.find(a => a.name === selectedAmenityName);
                if (selectedAmenity && selectedAmenity.image_url) {
                    // If we found the amenity and it has an image, use that image.
                    splashImage.src = selectedAmenity.image_url;
                } else {
                    // As a final fallback, if the amenity has no image, show logo image.
                    splashImage.src = kioskConfig.logo_url;
                }
            }
            
            // The visual feedback logic you added
            statusMessage.textContent = `Thank you for signing in to ${this.dataset.name}!`;

            // Make sure the idle screen with the main logo and title is visible
            idleScreen.style.display = 'block';

            // Hide the main table that contains the card photo and all the buttons
            document.getElementById('main-layout-table').style.display = 'none';

            // Show our new splash screen container
            splashScreen.style.display = 'flex';

            logToScreen(`TIMER: Setting 2-second reset timer at ${new Date().toLocaleTimeString()}`);
            resetKioskTimer = setTimeout(resetKiosk, 2000);
        };

        // Attach the same handler to both events
        button.addEventListener('click', handleAmenitySelection);
        button.addEventListener('touchstart', handleAmenitySelection);

        amenityButtonsDiv.appendChild(button);
    });
}

function handleIdleTimeout() {
logToScreen(`TIMER: 30-second idle timeout FIRED at ${new Date().toLocaleTimeString()}`);
        if (!sessionActive) { return; }
        sessionActive = false;
        idleTimerFired = true;
        if (!lastSwipedCard) {
            resetKiosk(); // Failsafe in case there's no card
            return;
        }

        // Send the 'Lobby' event to the server
        socket.send(JSON.stringify({
            event: 'amenitySelected',
            payload: {
                rfid: lastSwipedCard,
                amenity: 'Lobby', // Default to Lobby
                guests: selectedGuestCount
            }
        }));

        // Briefly show a message before resetting
        statusMessage.textContent = 'Sign-in for Lobby recorded due to inactivity.';
        
        // Reset the kiosk after a short delay
        setTimeout(resetKiosk, 3000);
}

function resetKiosk() {
        clearTimeout(idleTimeout);
        clearTimeout(resetKioskTimer);
        splashScreen.style.display = 'none';
        document.getElementById('main-layout-table').style.display = 'none';
        idleScreen.style.display = 'block';
        guestButtonsContainer.style.display = 'none';
        amenityButtonsContainer.style.display = 'none';
        cardDisplay.className = 'card-display-hidden';
        lastSwipedCard = null;
        lastCardSwipeDiv.textContent = '';
        amenityButtonsDiv.innerHTML = '';
        statusMessage.textContent = 'Please Swipe Your Card';
        statusMessage.style.color = '';
        cardReaderInput.value = '';
        startFocusCapture();  // we are reading via the Browser's card reader
}

// Initial connection attempt
connect();

function handleCardInput(event) {
    // Always clear any previous timer when new input arrives.
    clearTimeout(rfidTimeout);

    // Set a very short timer (e.g., 50 milliseconds).
    // If more digits arrive within this window, the timer will be reset.
    // When the digits STOP arriving, this timer will finally execute.
    rfidTimeout = setTimeout(() => {
        const rfid = cardReaderInput.value.replace(/\D/g, '');

        logToScreen(`Swipe finished. Captured: ${rfid}`);
        
        // Only process if we have a plausible number of digits.
        if (rfid.length >= 7 && rfid.length <= 10) { // Allow for some variance
            // The card reader sometimes sends a 10-digit number.
            // We only want the last 8 digits.
            const finalRfid = rfid.slice(-8);
            logToScreen(`Processing final 8 digits: ${finalRfid}. Sending to backend.`);
            
            socket.send(JSON.stringify({
                event: 'manualSwipe',
                payload: { rfid: finalRfid }
            }));
        } else {
            logToScreen(`Discarding invalid swipe length: ${rfid.length}`);
        }

        // Clear the input field for the next swipe.
        cardReaderInput.value = '';

    }, 50); // A 50ms delay is usually enough to capture a full swipe burst.
}


function forceFocus() {
    cardReaderInput.focus();
}

function startFocusCapture() {
    cardReaderInput.addEventListener('blur', forceFocus);
    cardReaderInput.addEventListener('input', handleCardInput);
    forceFocus();
}

function stopFocusCapture() {
    cardReaderInput.removeEventListener('blur', forceFocus);
    cardReaderInput.removeEventListener('input', handleCardInput);
    clearTimeout(rfidTimeout);
}

function toggleLogVisibility() {
    const logContainer = document.getElementById('debug-log');
    if (window.getComputedStyle(logContainer).display === 'none') {
    logContainer.style.display = 'block';
    localStorage.setItem('kioskLogVisible', 'true');
    logToScreen('Debug log enabled.');
    } else {
        logContainer.style.display = 'none';
        localStorage.setItem('kioskLogVisible', 'false');
    }
}

function setInitialLogState() {
    if (localStorage.getItem('kioskLogVisible') === 'true') {
        const logContainer = document.getElementById('debug-log');
        logContainer.style.display = 'block';
        logToScreen('Debug log was previously enabled.');
    }
}

// ---  NEW LONG-PRESS EVENT LISTENERS ---
logoImage.addEventListener('mousedown', startPressTimer);
logoImage.addEventListener('touchstart', startPressTimer);

logoImage.addEventListener('mouseup', cancelPressTimer);
logoImage.addEventListener('touchend', cancelPressTimer);

function startPressTimer(e) {
    // Start a 2-second timer when the user presses down.
    longPressTimer = setTimeout(toggleLogVisibility, 2000);
}

function cancelPressTimer(e) {
    // If they lift their finger before 2 seconds, cancel the timer.
    clearTimeout(longPressTimer);
}
