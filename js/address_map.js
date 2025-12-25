let map;
let marker;
let autocomplete;
let geocoder;

// STRICT MAPPING: Google State Name -> Your Dropdown Value
const STATE_MAPPING = {
    "federal territory of kuala lumpur": "Kuala Lumpur",
    "kuala lumpur": "Kuala Lumpur",
    "labuan": "Labuan",
    "federal territory of labuan": "Labuan",
    "putrajaya": "Putrajaya",
    "federal territory of putrajaya": "Putrajaya",
    "johor": "Johor",
    "kedah": "Kedah",
    "kelantan": "Kelantan",
    "malacca": "Melaka", // Google often uses English "Malacca"
    "melaka": "Melaka",
    "negeri sembilan": "Negeri Sembilan",
    "pahang": "Pahang",
    "penang": "Penang",
    "pulau pinang": "Penang", // Google often uses Malay "Pulau Pinang"
    "perak": "Perak",
    "perlis": "Perlis",
    "sabah": "Sabah",
    "sarawak": "Sarawak",
    "selangor": "Selangor",
    "terengganu": "Terengganu"
};

function initMap() {
    const defaultLoc = { lat: 3.140853, lng: 101.693207 };

    map = new google.maps.Map(document.getElementById("map"), {
        center: defaultLoc,
        zoom: 13,
        mapTypeControl: false,
        fullscreenControl: false,
        streetViewControl: false
    });
    
    geocoder = new google.maps.Geocoder();

    marker = new google.maps.Marker({
        map: map,
        position: defaultLoc,
        draggable: true, 
        visible: false   
    });

    const input = document.getElementById("address_search");
    const options = {
        componentRestrictions: { country: "my" }, 
        fields: ["formatted_address", "geometry", "address_components", "name"], // Important: Request 'name'
    };

    autocomplete = new google.maps.places.Autocomplete(input, options);
    autocomplete.bindTo("bounds", map);

    autocomplete.addListener("place_changed", () => {
        const place = autocomplete.getPlace();
        if (!place.geometry || !place.geometry.location) {
            window.alert("No details available for input: '" + place.name + "'");
            return;
        }
        updateMapAndForm(place.geometry.location, place);
    });

    marker.addListener("dragend", () => {
        geocodePosition(marker.getPosition());
    });
    
    // "Use My Location" Button Logic
    const locateBtn = document.getElementById("btn-locate-me");
    if(locateBtn) {
        locateBtn.addEventListener("click", (e) => {
            e.preventDefault();
            if (navigator.geolocation) {
                locateBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Locating...';
                navigator.geolocation.getCurrentPosition(
                    (position) => {
                        const pos = {
                            lat: position.coords.latitude,
                            lng: position.coords.longitude,
                        };
                        geocodePosition(pos);
                        locateBtn.innerHTML = '<i class="fas fa-location-arrow"></i> Use My Location';
                    },
                    () => {
                        alert("Error: Geolocation failed.");
                        locateBtn.innerHTML = '<i class="fas fa-location-arrow"></i> Use My Location';
                    }
                );
            } else {
                alert("Error: Browser doesn't support geolocation.");
            }
        });
    }
}

function geocodePosition(pos) {
    geocoder.geocode({ location: pos }, (results, status) => {
        if (status === "OK" && results[0]) {
            // Geocoding results don't usually have the "name" property (like "Pavilion KL")
            // They only have address components.
            updateMapAndForm(pos, results[0]);
            document.getElementById("address_search").value = results[0].formatted_address;
        } else {
            console.log("Geocoder failed: " + status);
        }
    });
}

function updateMapAndForm(location, place) {
    // 1. Move Map
    map.setCenter(location);
    map.setZoom(17);
    marker.setPosition(location);
    marker.setVisible(true);

    console.log("Google Place Data:", place); // Debugging

    // 2. Extract Components
    let comp = {
        street_number: "",
        route: "",          // Street
        locality: "",       // City
        administrative_area_level_1: "", // State
        postal_code: "",
        subpremise: "",     // Unit
        premise: "",        // Building
        neighborhood: ""
    };

    if (place.address_components) {
        for (const c of place.address_components) {
            const type = c.types[0];
            if (comp[type] !== undefined) {
                comp[type] = c.long_name;
            }
        }
    }

    // 3. FILL LINE 1 (House No + Street)
    let line1 = "";
    if (comp.street_number) line1 += comp.street_number + " ";
    if (comp.route) line1 += comp.route;
    
    // Fallback if Line 1 is empty (e.g. some places only have a building name)
    if (!line1) {
        // Try formatted address before the first comma
        line1 = place.formatted_address.split(',')[0];
    }
    
    document.getElementById("line1").value = line1.trim();

    // 4. FILL LINE 2 (Building / Unit / Area)
    let line2Parts = [];

    // (A) Check specific address components
    if (comp.subpremise) line2Parts.push(comp.subpremise); // Unit
    if (comp.premise) line2Parts.push(comp.premise);       // Building
    if (comp.neighborhood) line2Parts.push(comp.neighborhood); // Area

    // (B) IMPORTANT: Check the Place NAME
    // Often "Pavilion KL" or "Sunway Pyramid" is in place.name, NOT in address components.
    // We only add it if it's NOT already in Line 1.
    if (place.name) {
        const nameLower = place.name.toLowerCase();
        const line1Lower = line1.toLowerCase();
        
        // If the place name is not part of the street address, it's likely the building name
        if (!line1Lower.includes(nameLower)) {
            // Add it to the FRONT of Line 2
            line2Parts.unshift(place.name); 
        }
    }

    // Remove duplicates and join
    let uniqueLine2 = [...new Set(line2Parts)];
    document.getElementById("line2").value = uniqueLine2.join(", ");

    // 5. CITY & POSTCODE
    // Fallback: Use 'administrative_area_level_2' (District) if City is empty (common in MY)
    let city = comp.locality;
    if (!city && place.address_components) {
        const district = place.address_components.find(c => c.types[0] === "administrative_area_level_2");
        if (district) city = district.long_name;
    }
    document.getElementById("city").value = city || "";
    document.getElementById("postcode").value = comp.postal_code || "";

    // 6. STATE (Strict Matching)
    const stateSelect = document.getElementById("state");
    const googleStateRaw = comp.administrative_area_level_1.toLowerCase().trim();
    
    console.log("Google Returned State:", googleStateRaw); // Debugging

    // Try finding it in our mapping list first
    let matchedState = STATE_MAPPING[googleStateRaw];

    if (matchedState) {
        // Find option with this text or value
        for (let i = 0; i < stateSelect.options.length; i++) {
            if (stateSelect.options[i].value === matchedState) {
                stateSelect.selectedIndex = i;
                break;
            }
        }
    } else {
        // Fallback: Fuzzy Search
        for (let i = 0; i < stateSelect.options.length; i++) {
            const optVal = stateSelect.options[i].value.toLowerCase();
            if (googleStateRaw.includes(optVal) || optVal.includes(googleStateRaw)) {
                stateSelect.selectedIndex = i;
                break;
            }
        }
    }
}

window.initMap = initMap;