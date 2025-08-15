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
const AMENITY_SELECTION_TIMEOUT = 30000; // After swipe Timeout in milliseconds (30 seconds)

let idleTimeout; // This will hold our after swipe timer
let selectedGuestCount = null;
let rfidTimeout;
let kioskConfig = {};
let lastSwipedCard = null;
let socket = null;
let audioCtx;
let hasConnectedBefore = false; // Flag to track initial connection

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

    console.log(`Connecting to WebSocket at: ${socketURL}`);
    socket = new WebSocket(socketURL);

    socket.addEventListener('open', () => {
        // If we have connected before, a successful 'open' means we have reconnected.
        // A hard refresh is now safe and will load the latest code.
        if (hasConnectedBefore) {
            location.reload(true);
        } else {
            // This is the very first connection on initial page load.
            hasConnectedBefore = true;
            resetKiosk();
        }
    });

    socket.addEventListener('message', (event) => {
        try {
            const message = JSON.parse(event.data);
            console.log("Received message object:", message);

            if (message.event === 'kioskConfig') {
                kioskConfig = message.payload;
                if (kioskConfig.logo_url) {
                    logoImage.src = kioskConfig.logo_url;
                    logoImage.style.display = 'block';
                }
            } else if (message.event === 'cardSwiped') {
                const swipeData = message.payload;

                if (swipeData.isValid) {
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
                    clearTimeout(idleTimeout);
                    idleTimeout = setTimeout(handleIdleTimeout, AMENITY_SELECTION_TIMEOUT);
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
        console.log('WebSocket connection closed. Retrying in 3 seconds.');
        statusMessage.textContent = 'Status: Disconnected. Attempting to reconnect...';
        statusMessage.style.color = 'orange';
        setTimeout(connect, 5000);
    });
}

function createGuestButtons() {
    guestButtonsDiv.innerHTML = '';
    for (let i = 0; i <= 6; i++) {
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
    amenityButtonsDiv.innerHTML = '';
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

        button.addEventListener('click', function() {
            clearTimeout(idleTimeout);
            socket.send(JSON.stringify({
                event: 'amenitySelected',
                payload: {
                    rfid: lastSwipedCard,
                    amenity: this.dataset.name,
                    guests: selectedGuestCount
                }
            }));
            statusMessage.textContent = `Thank you for signing in to ${this.dataset.name}!`;
            setTimeout(resetKiosk, 2000);
        });
        amenityButtonsDiv.appendChild(button);
    });
}

function handleIdleTimeout() {
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
        setTimeout(resetKiosk, 4000);
}

function resetKiosk() {
        clearTimeout(idleTimeout);
        clearTimeout(resetKiosk);
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
        startFocusCapture();
}

// Initial connection attempt
connect();

function handleCardInput(event) {
    event.target.value = event.target.value.replace(/\D/g, '');
    const rfid = event.target.value;

    if (rfid.length === 1) {
        clearTimeout(rfidTimeout);
        rfidTimeout = setTimeout(() => {
            console.log("RFID input timed out.");
            cardReaderInput.value = '';
        }, 2000);
    }

    if (rfid.length === 8) {
        clearTimeout(rfidTimeout);
        console.log(`8 digits entered: ${rfid}. Sending to backend.`);
        socket.send(JSON.stringify({
            event: 'manualSwipe',
            payload: { rfid: rfid }
        }));
        event.target.value = '';
    }
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

