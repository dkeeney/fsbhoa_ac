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
                const message = JSON.parse(event.data);

                // Route message based on its type
                switch (message.messageType) {
                    case 'accessEvent':
                        addEventToLog(message.payload);
                        flashGateLight(message.payload.doorRecordId);
                        break;
                    case 'gateStatus':
                        updateGateStatus(message.payload);
                        break;
                    default:
                        // This handles the old, direct event format for backward compatibility
                        if (message.eventType) {
                           addEventToLog(message);
                        } else {
                           console.warn('Received unknown message type:', message.messageType);
                        }
                }
            } catch (e) {
                console.error('Error parsing incoming message:', e);
            }
        };

        socket.onclose = function(event) {
            console.log('WebSocket connection closed. Reconnecting in 3 seconds...');
            updateConnectionStatus('disconnected', 'Disconnected');
            Object.keys(gates).forEach(id => updateGateStatus({ doorRecordId: id, status: 'down' }));
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
    

    function sendDoorStateCommand(doorId, stateCode, lightElement) {
        // Temporarily make the light pulse to show a command is being sent
        if (lightElement) {
            lightElement.classList.add('flash');
        }

        const apiEndpoint = '/wp-json/fsbhoa/v1/monitor/set-door-state';
        const postData = {
            door_id: parseInt(doorId),
            state: parseInt(stateCode)
        };

        fetch(apiEndpoint, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': fsbhoa_monitor_vars.nonce // Add the nonce header
            },
            body: JSON.stringify(postData)
        })
        .then(response => {
            if (!response.ok) {
                return response.json().then(err => { throw new Error(err.message || 'Unknown error'); });
            }
            return response.json();
        })
        .then(data => {
            console.log('Command successful:', data.message);
            // The UI will update automatically when the event_service polls the new state.
            setTimeout(() => {
                if (lightElement) lightElement.classList.remove('flash');
            }, 1000);
        })
        .catch(error => {
            console.error('Error setting door state:', error);
            alert('Error sending command: ' + error.message);
            if (lightElement) lightElement.classList.remove('flash');
        });
    }

    function handleGateClick(e) {
        const light = e.target.closest('.gate-light');
        if (!light) {
            return;
        }

        const doorId = light.id.replace('gate-light-', '');
        const gateName = light.title;

        // Create and inject the modal HTML
        const modalHTML = `
            <div id="door-control-overlay" class="fsbhoa-modal-overlay">
                <div class="fsbhoa-modal-content">
                    <h3>Set State for "${gateName}"</h3>
                    <div id="door-control-buttons" class="fsbhoa-modal-buttons">
                        <button class="button-yellow" data-state="1">Unlock by Card (Yellow)</button>
                        <button class="button-green" data-state="2">Unlock (Green)</button>
                        <button class="button-red" data-state="3">Lock (Red)</button>
                        <button class="button-secondary" data-state="cancel">Cancel</button>
                    </div>
                </div>
            </div>
        `;
        document.body.insertAdjacentHTML('beforeend', modalHTML);

        // Add a click listener to the new button container
        const buttonContainer = document.getElementById('door-control-buttons');
        buttonContainer.addEventListener('click', function(event) {
            const button = event.target.closest('button');
            if (!button) return;

            const desiredState = button.getAttribute('data-state');

            // Remove the modal from the screen
            document.getElementById('door-control-overlay').remove();

            // If not "Cancel", send the command
            if (desiredState && desiredState !== 'cancel') {
                sendDoorStateCommand(doorId, desiredState, light);
            }
        });
    }






    function createEventCard(eventData, isExpanded) {
        const li = document.createElement('li');
        li.dataset.eventData = JSON.stringify(eventData);

        if (isExpanded) {
            const isGranted = eventData.eventType === 'accessGranted';
            li.className = `p-4 flex items-start space-x-4 border-l-4 ${isGranted ? 'border-green-500 bg-green-50' : 'border-red-500 bg-red-50'} is-expanded`;
            li.innerHTML = `
                <img class="h-16 w-16 rounded-lg object-cover" src="${eventData.photoURL}" alt="${eventData.cardholderName}" onerror="this.onerror=null;this.src='https://placehold.co/128x128/ccc/ffffff?text=Error';">
                <div class="flex-1 event-text-content">
                    <p class="text-lg font-semibold text-gray-900">${eventData.cardholderName}</p>
                    <p class="text-gray-500">${eventData.streetAddress}</p>
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


    const GATES_API_URL = '/wp-json/fsbhoa/v1/monitor/gates';
    const mapContainer = document.getElementById('map-container');
    let gates = {}; // Store gate data by door_record_id

    async function initializeMap() {
        if (!mapContainer) return;
        try {
            const response = await fetch(GATES_API_URL);
            if (!response.ok) {
                throw new Error(`Failed to fetch gates: ${response.statusText}`);
            }
            const gatesData = await response.json();

            gatesData.forEach(gate => {
                gates[gate.door_record_id] = gate;

                const light = document.createElement('div');
                light.id = `gate-light-${gate.door_record_id}`;
                light.className = 'gate-light status-down'; // Default to down
                light.style.left = `${gate.map_x}%`;
                light.style.top = `${gate.map_y}%`;
                light.title = gate.friendly_name;
                mapContainer.appendChild(light);
            });
        } catch (error) {
            console.error("Error initializing map:", error);
            mapContainer.innerHTML = '<p style="text-align:center; color:red; padding-top:20px;">Could not load gate map data.</p>';
        }
    }

    async function loadRecentActivity() {
        const ACTIVITY_API_URL = '/wp-json/fsbhoa/v1/monitor/recent-activity';
        try {
            const response = await fetch(ACTIVITY_API_URL);
            if (!response.ok) {
                throw new Error(`Failed to fetch recent activity: ${response.statusText}`);
            }
            const events = await response.json();

            // If there are events, add them to the log
            if (events.length > 0) {
                // The API returns newest first, so we loop backwards to add oldest first
                for (let i = events.length - 1; i >= 0; i--) {
                    addEventToLog(events[i]);
                }
            }
        } catch (error) {
            console.error("Error loading recent activity:", error);
            // Optionally, display an error in the log
            const eventList = document.getElementById('event-list');
            if (eventList) {
                const placeholder = document.getElementById('log-placeholder');
                if (placeholder) {
                    placeholder.textContent = 'Could not load recent activity.';
                }
            }
        }
    }

    function updateGateStatus(statusData) {
        const { doorRecordId, status } = statusData;
        const light = document.getElementById(`gate-light-${doorRecordId}`);
        if (light) {
            light.className = 'gate-light'; // Reset classes
            switch (status) {
                case 'locked':
                    light.classList.add('status-locked');
                    break;
                case 'unlocked':
                    light.classList.add('status-unlocked');
                    break;
                case 'intermediate':
                    light.classList.add('status-intermediate');
                    break;
                case 'down':
                default:
                    light.classList.add('status-down');
                    break;
            }
        }
    }

    function flashGateLight(doorRecordId) {
        const light = document.getElementById(`gate-light-${doorRecordId}`);
        if (light) {
            light.classList.add('flash');
            setTimeout(() => {
                light.classList.remove('flash');
            }, 700); // Must match animation duration
        }
    }

    // Add the main event listener to the map container
    if (mapContainer) {
        mapContainer.addEventListener('click', handleGateClick);
    }

    // Run all initialization tasks, then connect the WebSocket.
    Promise.all([
        initializeMap(),
        loadRecentActivity()
    ]).then(() => connect());
});


