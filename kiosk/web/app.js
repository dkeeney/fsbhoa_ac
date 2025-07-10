const statusMessage = document.getElementById('status-message');
const lastCardSwipeDiv = document.getElementById('last-card-swipe');
const amenityButtonsDiv = document.getElementById('amenity-buttons');
const idleScreen = document.getElementById('idle-screen');
const mainContent = document.getElementById('main-content');
const logoImage = document.getElementById('logo-image');
const cardDisplay = document.getElementById('card-display');
const cardPhoto = document.getElementById('card-photo');
const cardName = document.getElementById('card-name');
const cardReaderInput = document.getElementById('card-reader-input');
let rfidTimeout;

let kioskConfig = {};
let lastSwipedCard = null;
let socket = null;
let audioCtx;

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
    socket = new WebSocket(`ws://${window.location.host}/ws`);

    socket.addEventListener('open', () => {
        resetKiosk();
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
                    stopFocusCapture(); // Stop trapping focus to allow button clicks
                    idleScreen.style.display = 'none';
                    mainContent.style.display = 'block';
                    lastCardSwipeDiv.textContent = ``; 
                    statusMessage.textContent = 'Please Select an Amenity';
                    
                    cardName.textContent = swipeData.cardholder.name;
                    if (swipeData.cardholder.photo) {
                        cardPhoto.src = `data:image/jpeg;base64,${swipeData.cardholder.photo}`;
                    } else {
                        cardPhoto.src = ''; 
                    }
                    cardDisplay.className = 'card-display-visible';
                    createAmenityButtons(kioskConfig.amenities);
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
        setTimeout(connect, 3000);
    });
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
            socket.send(JSON.stringify({
                event: 'amenitySelected',
                payload: {
                    rfid: lastSwipedCard,
                    amenity: this.dataset.name
                }
            }));
            statusMessage.textContent = `Thank you for signing in to ${this.dataset.name}!`;
            mainContent.style.display = 'none';
            setTimeout(resetKiosk, 3000);
        });
        amenityButtonsDiv.appendChild(button);
    });
}

function resetKiosk() {
    mainContent.style.display = 'none';
    idleScreen.style.display = 'block';
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



// --- New Functions for Focus and Input Handling ---

function handleCardInput(event) {
    // Sanitize input to only allow digits
    event.target.value = event.target.value.replace(/\D/g, '');
    const rfid = event.target.value;

    if (rfid.length === 1) {
        // Start a 2-second timer when the first digit is entered
        clearTimeout(rfidTimeout);
        rfidTimeout = setTimeout(() => {
            console.log("RFID input timed out.");
            cardReaderInput.value = ''; // Clear the input field
        }, 2000);
    }

    if (rfid.length === 8) {
        clearTimeout(rfidTimeout);
        console.log(`8 digits entered: ${rfid}. Sending to backend.`);
        socket.send(JSON.stringify({
            event: 'manualSwipe',
            payload: { rfid: rfid }
        }));
        event.target.value = ''; // Clear for the next swipe
    }
}

function forceFocus() {
    cardReaderInput.focus();
}

function startFocusCapture() {
    cardReaderInput.addEventListener('blur', forceFocus);
    cardReaderInput.addEventListener('input', handleCardInput);
    forceFocus(); // Set initial focus
}

function stopFocusCapture() {
    cardReaderInput.removeEventListener('blur', forceFocus);
    cardReaderInput.removeEventListener('input', handleCardInput);
    clearTimeout(rfidTimeout);
}
