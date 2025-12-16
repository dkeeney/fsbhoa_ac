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
let isDirectLoad = false;
let overrideDoorNumber = null;

// --- KIOSK IDENTITY LOGIC ---
let kioskIdentity = null;

// Main entry point for initialization
async function initializeKiosk() {
    logToScreen("Initializing Kiosk Logic...");
    const urlParams = new URLSearchParams(window.location.search);

    // 1. Direct Load (PHP initiated with specific cardholder and door)
    if (urlParams.has('cardholder_id') && urlParams.has('door_number')) {
        logToScreen("Step 1: Direct Load parameters detected.");
        await handleDirectLoad(urlParams);
        return;
    }

    // 2. Reset Command (Clear storage and restart)
    if (urlParams.has('reset_kiosk')) {
        logToScreen("Step 2: Reset command detected.");
        localStorage.removeItem('fsbhoa_kiosk_identity');
        alert("Kiosk Configuration Cleared. Reloading...");
        // Reload without query parameters to trigger Setup Screen
        window.location.href = window.location.pathname; 
        return;
    }

    // 3. Auto Config (Derive ID from 'auto_name' param)
    if (urlParams.has('auto_name')) {
        logToScreen("Step 3: Auto-Config detected.");
        const success = await handleAutoConfig(urlParams.get('auto_name'));
        if (success) {
            connect(); // Connect immediately after saving
            return;
        }
        // If auto-config fails, we fall through to Setup Screen
    }

    // 4. Check Storage (Existing configuration)
    const stored = localStorage.getItem('fsbhoa_kiosk_identity');
    if (stored) {
        logToScreen("Step 4: Found existing identity in storage.");
        try {
            kioskIdentity = JSON.parse(stored);
            logToScreen(`Identity Loaded: ${kioskIdentity.name} (ID: ${kioskIdentity.id})`);
            
            // Hide Setup, Show Idle
            document.getElementById('setup-screen').style.display = 'none';
            document.getElementById('idle-screen').style.display = 'block';
            
            connect();
            return;
        } catch (e) {
            logToScreen("Error: Storage corrupted. Clearing. " + e.message);
            localStorage.removeItem('fsbhoa_kiosk_identity');
        }
    }

    // 5. Fallback: Display Setup Screen
    logToScreen("Step 5: No identity found. Showing setup screen.");
    showSetupScreen();
}

// --- HANDLERS ---

async function handleDirectLoad(params) {
    const doorNum = params.get('door_number'); // This acts as the gate_id for connection
    const cardId = params.get('cardholder_id');
    
    // We create a temporary identity for this session only.
    // We do NOT save this to localStorage.
    kioskIdentity = {
        id: parseInt(doorNum, 10),
        name: "Direct Load Session",
        serial: "000000", // Default/Ignored for direct load
        door: 1           // Default
    };

    isDirectLoad = true;
    overrideDoorNumber = kioskIdentity.id;

    logToScreen(`Direct Load: Cardholder ${cardId} at Gate ${doorNum}`);
    
    document.getElementById('setup-screen').style.display = 'none';
    document.getElementById('idle-screen').style.display = 'block';

    connect(); 
    // Connection logic in connect() will pick up the 'cardholder_id' from URL 
    // and trigger the startSessionById event automatically.
}

async function handleAutoConfig(gateName) {
    try {
        logToScreen(`Attempting Auto-Config for Gate: '${gateName}'...`);
        
        // Fetch list of gates to find the ID that matches this name
        const doors = await fetchGateList();
        const match = doors.find(d => d.friendly_name === gateName);

        if (match) {
            saveIdentityToStorage(match);
            logToScreen(`Auto-Config Success. ID derived: ${match.door_record_id}`);
            
            // Clean URL so a refresh doesn't trigger config again unnecessarily
            window.history.replaceState({}, document.title, window.location.pathname);
            return true;
        } else {
            logToScreen(`Error: No gate found with name '${gateName}'`);
            alert(`Auto-Config Failed: Gate '${gateName}' not found.`);
            return false;
        }
    } catch (err) {
        logToScreen("Auto-Config Network Error: " + err.message);
        return false;
    }
}

function showSetupScreen() {
    document.getElementById('idle-screen').style.display = 'none';
    document.getElementById('setup-screen').style.display = 'block';

    fetchGateList()
        .then(doors => {
            const select = document.getElementById('kiosk-location-select');
            select.innerHTML = '<option value="">-- Select Gate Location --</option>';
            
            doors.forEach(door => {
                // We create a value string that we can easily parse back into our identity object
                const valObj = {
                    id: door.door_record_id,         // The Gate ID used for WebSocket
                    name: door.friendly_name,        // The readable name
                    serial: door.uhppoted_device_id, // Controller Serial
                    door: door.door_number_on_controller // Controller Door Port (1-4)
                };
                
                const opt = document.createElement('option');
                opt.value = JSON.stringify(valObj);
                opt.textContent = door.friendly_name;
                select.appendChild(opt);
            });
        })
        .catch(err => {
            logToScreen("Error fetching gate list: " + err.message);
        });
}

// Helper to fetch gates from PHP (Proxy)
async function fetchGateList() {
    const targetEndpoint = '/fsbhoa/v1/monitor/gates?role=KIOSK';
    const response = await fetch(`/api/proxy?endpoint=${encodeURIComponent(targetEndpoint)}`);
    if (!response.ok) throw new Error(`API Error: ${response.status}`);
    return await response.json();
}

// Helper to standardise saving identity
function saveIdentityToStorage(doorRecord) {
    const config = {
        id: doorRecord.door_record_id,
        name: doorRecord.friendly_name,
        serial: doorRecord.uhppoted_device_id,
        door: doorRecord.door_number_on_controller
    };
    localStorage.setItem('fsbhoa_kiosk_identity', JSON.stringify(config));
    
    // Update local variable immediately
    kioskIdentity = config;
}

// Global function for the Manual Setup button (called from HTML)
window.saveKioskIdentity = function() {
    const select = document.getElementById('kiosk-location-select');
    if (select.value) {
        // The value is already a JSON string of our config object
        localStorage.setItem('fsbhoa_kiosk_identity', select.value);
        
        // Reload page to perform a clean startup with the new identity in Step 4
        location.reload();
    } else {
        alert("Please select a location.");
    }
};

// --- END KIOSK IDENTITY LOGIC ---


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

    // Check for identity and append door ID
    let doorParam = '';
    // We check for 'id' (the new field)
    if (kioskIdentity && kioskIdentity.id) {
        doorParam = `?doorId=${kioskIdentity.id}`;
    }
    const protocol = window.location.protocol === 'https:' ? 'wss:' : 'ws:';
    const socketURL = `${protocol}//${window.location.host}/ws${doorParam}`;

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
        // After connecting, check if we were loaded with a cardholder ID.
        const urlParams = new URLSearchParams(window.location.search);
        const cardholderId = urlParams.get('cardholder_id');
        // Check for door_number param (default to 0 if missing)
        const doorNumberParam = urlParams.get('door_number'); 
        const doorNumber = doorNumberParam ? parseInt(doorNumberParam, 10) : 0;
        
        if (cardholderId) {
            isDirectLoad = true; // Set flag if loaded directly
            // Send the override door number to the backend context
            // Note: We already captured overrideDoorNumber in checkIdentity, 
            // but we can send it in the payload if the backend supports it.
            const payload = { id: cardholderId };
            if (overrideDoorNumber !== null) {
                payload.door_number = overrideDoorNumber;
            }
            logToScreen(`Direct load requested for cardholder ID: ${cardholderId}, Door: ${doorNumber}`);
            socket.send(JSON.stringify({
                event: 'startSessionById',
                payload: payload
            }));
        } else {
            isDirectLoad = false;  // Ensure flag is false otherwise
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
//logToScreen(`TIMER: swiped - Setting 30-second idle timeout at ${new Date().toLocaleTimeString()}`);
                    idleTimeout = setTimeout(handleIdleTimeout, AMENITY_SELECTION_TIMEOUT);
//logToScreen(`TIMER: >>>> New idleTimeout ID is: ${idleTimeout}`);
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

            // Determine the correct door number
            // If we have an override (from Admin Console), use it.
            // Otherwise, use the Kiosk's configured door.
            // If neither (default), use 1.
            let finalDoorNumber = 1;
            let finalSerial = '900000';

            if (kioskIdentity) {
                finalDoorNumber = kioskIdentity.door;
                finalSerial = kioskIdentity.serial;
            }

            if (overrideDoorNumber !== null) {
                finalDoorNumber = overrideDoorNumber;
            }

            try {
                const messageToSend = {
                    event: 'amenitySelected',
                    payload: {
                        rfid: lastSwipedCard,
                        amenity: selectedAmenityName,
                        guests: selectedGuestCount,
                        serial_number: finalSerial,
                        door_number: finalDoorNumber
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
                guests: selectedGuestCount,
                serial_number: kioskIdentity ? kioskIdentity.serial : '900000',
                door_number: kioskIdentity ? kioskIdentity.door : 1
            }
        }));

        // Briefly show a message before resetting
        statusMessage.textContent = 'Sign-in for Lobby recorded due to inactivity.';
        
        // Reset the kiosk after a short delay
        setTimeout(resetKiosk, 3000);
}

function resetKiosk() {
        // If this session was started from WordPress direct login icon, just close the tab.
        if (isDirectLoad) {
            // We set the flag to false first as a safeguard
            isDirectLoad = false;
            window.close();
            return; // Stop the function here
        }
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



// ---  LONG-PRESS EVENT LISTENERS ---
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


// --- STARTUP ---
// Call initializeKiosk() instead of just connect() at the bottom of the file.
// connect() will be called after identity has been set.
initializeKiosk();
