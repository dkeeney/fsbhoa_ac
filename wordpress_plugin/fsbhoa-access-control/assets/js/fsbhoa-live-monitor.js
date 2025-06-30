/**
 * Handles the real-time functionality for the Live Activity Monitor page.
 * Connects to a WebSocket server and dynamically updates the UI with events.
 */
document.addEventListener('DOMContentLoaded', function () {
    // These variables are passed from WordPress via wp_localize_script
    const WS_URL = fsbhoa_monitor_vars.ws_url || ''; 
    const MAX_EXPANDED_EVENTS = 3; 

    // --- DOM Elements ---
    const eventList = document.getElementById('event-list');
    let logPlaceholder = document.getElementById('log-placeholder'); 
    const connectionStatus = document.getElementById('connection-status');
    const statusDot = connectionStatus ? connectionStatus.querySelector('div') : null;
    const statusText = connectionStatus ? connectionStatus.querySelector('span') : null;
    let lastEventTimestamp = '';
    
    if (!eventList || !connectionStatus) {
        console.error("Live Monitor UI elements not found. Aborting script.");
        return;
    }
    
    let socket;

    function connect() {
        if (!WS_URL) {
            console.error("WebSocket URL is not defined.");
            updateConnectionStatus('error', 'Configuration Error');
            return;
        }

        updateConnectionStatus('connecting', 'Connecting...');
        socket = new WebSocket(WS_URL);

        socket.onopen = function(event) {
            console.log('WebSocket connection established');
            updateConnectionStatus('connected', 'Connected');
        };

        socket.onmessage = function(event) {
            try {
                const eventData = JSON.parse(event.data);
                addEventToLog(eventData);
                flashGateLight(eventData.Door);
            } catch (e) {
                console.error('Error parsing incoming message:', e);
            }
        };

        socket.onclose = function(event) {
            console.log('WebSocket connection closed. Reconnecting in 3 seconds...');
            updateConnectionStatus('disconnected', 'Disconnected');
            setTimeout(connect, 3000);
        };

        socket.onerror = function(error) {
            console.error('WebSocket error:', error);
            updateConnectionStatus('error', 'Error');
            socket.close();
        };
    }

    function updateConnectionStatus(state, message) {
        statusText.textContent = message;
        connectionStatus.className = 'flex items-center space-x-2 px-3 py-1 rounded-full text-sm font-medium ';
        statusDot.className = 'w-2 h-2 rounded-full ';

        switch (state) {
            case 'connected':
                connectionStatus.classList.add('bg-green-200', 'text-green-800');
                statusDot.classList.add('bg-green-500');
                break;
            case 'disconnected':
            case 'error':
                connectionStatus.classList.add('bg-red-200', 'text-red-800');
                statusDot.classList.add('bg-red-500');
                break;
            default: // connecting
                connectionStatus.classList.add('bg-yellow-200', 'text-yellow-800');
                statusDot.classList.add('bg-yellow-500');
        }
    }
    
    function flashGateLight(doorNumber) {
        // This is a placeholder for future functionality
    }

    function createEventCard(eventData, isExpanded) {
        const li = document.createElement('li');
        li.dataset.eventData = JSON.stringify(eventData);

        if (isExpanded) {
            const isGranted = eventData.eventType === 'accessGranted';
            li.className = `p-4 flex items-start space-x-4 border-l-4 ${isGranted ? 'border-green-500 bg-green-50' : 'border-red-500 bg-red-50'} is-expanded`;
            li.innerHTML = `
                <img class="h-16 w-16 rounded-lg object-cover" src="${eventData.photoURL}" alt="${eventData.cardholderName}" onerror="this.onerror=null;this.src='https://placehold.co/128x128/ccc/ffffff?text=Error';">
                <div class="flex-1">
                    <p class="text-lg font-semibold text-gray-900">${eventData.cardholderName}</p>
                    <p class="text-gray-600">Event at <span class="font-medium">${eventData.gateName}</span></p>
                    <p class="text-sm ${isGranted ? 'text-green-700' : 'text-red-700'} font-medium mt-1">${eventData.eventMessage}</p>
                </div>
                <time class="text-sm text-gray-500">${eventData.timestamp}</time>
            `;
        } else {
            const isGranted = eventData.eventType === 'accessGranted';
            li.className = 'px-4 py-2 text-sm text-gray-600';
            li.innerHTML = `
                <time class="font-mono text-gray-500 mr-2">[${eventData.timestamp}]</time> 
                ${eventData.cardholderName} at <span class="font-medium">${eventData.gateName}</span> 
                (<span class="${isGranted ? 'text-green-600' : 'text-red-600'}">${eventData.eventMessage}</span>)
            `;
        }
        return li;
    }

    function addEventToLog(eventData) {
        // 1. Check if the new event's timestamp is the same as the last one.
        if (eventData.timestamp === lastEventTimestamp) {
            return; // If it's the same, stop and ignore the event.
        }

        if (logPlaceholder) {
            logPlaceholder.remove();
            logPlaceholder = null;
        }

        const newEventCard = createEventCard(eventData, true);
        eventList.prepend(newEventCard);

        const allEvents = eventList.querySelectorAll('li');
        if (allEvents.length > MAX_EXPANDED_EVENTS) {
            const cardToCollapse = allEvents[MAX_EXPANDED_EVENTS];
            if (cardToCollapse.classList.contains('is-expanded')) {
                const originalData = JSON.parse(cardToCollapse.dataset.eventData);
                const collapsedCard = createEventCard(originalData, false);
                cardToCollapse.replaceWith(collapsedCard);
            }
        }

        while (eventList.children.length > 50) {
            eventList.removeChild(eventList.lastChild);
        }

        // 2. Update the 'lastEventTimestamp' with the new timestamp.
        lastEventTimestamp = eventData.timestamp;
    }

    connect();
});


