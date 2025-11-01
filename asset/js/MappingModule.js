const MappingModule = {
    /**
     *
     * @param {DOM object} mapDiv The map div DOM object
     * @param {object} mapOptions Leaflet map options
     * @param {object} options Options for initializing the map
     *      - disableClustering: (bool) Disable feature clustering?
     *      - basemapProvider: (string) The default basemap provider
     *      - excludeLayersControl: (bool) Exclude the layers control?
     *      - excludeFitBoundsControl: (bool) Exclude the fit bounds control?
     * @returns array
     */
    initializeMap: function(mapDiv, mapOptions, options , isJourneyMap = false) {
        mapOptions.fullscreenControl = true;
        mapOptions.worldCopyJump = true;

        // Initialize the map and features.
        const map = new L.map(mapDiv, mapOptions);
        const features = L.featureGroup();
        const featuresPoint = (options.disableClustering || isJourneyMap)
        ? L.featureGroup()
        : L.markerClusterGroup({
              spiderfyOnMaxZoom:false,
                showCoverageOnHover: false,
                zoomToBoundsOnClick: false
            });
        const featuresPoly = L.deflate({
            // Enable clustering of poly features
            markerLayer: featuresPoint,
            // Must set to false or small poly features will not be inflated at high zoom.
            greedyCollapse: false
        });

        // Cluster click behavior
        featuresPoint.on("clusterclick", function (e) {
            if (e.originalEvent) {
                e.originalEvent.preventDefault();
                e.originalEvent.stopPropagation();
            }

            const clusterMarkers = e.layer.getAllChildMarkers();
            const count = clusterMarkers.length;

            let rowsHtml = "";
            clusterMarkers.forEach((marker, idx) => {
                const title = marker.feature.geometry.properties.title || "No title";
                rowsHtml += `
                    <tr>
                    <td><a href="#" class="cluster-jump" data-marker-index="${idx}">${title}</a></td>
                    </tr>`;
            });
            const popupContent = `
                <div class="cluster-popup">
                <h4>${count} ${count === 1 ? "place" : "places"} in this cluster</h4>
                <div class="cluster-list">
                    <table class="table table-striped">
                    <tbody>${rowsHtml}</tbody>
                    </table>
                </div>
                </div>`;

            const popup = L.popup({ maxWidth: 420 })
                .setLatLng(e.latlng)
                .setContent(popupContent)
                .openOn(map);

            // Make list scroll without moving the map
            const container = popup.getElement();
            if (!container) return;

            // Click a title => focus its marker, open its popup, show a quick highlight
            container.querySelectorAll(".cluster-jump").forEach((a) => {
                a.addEventListener("click", function (ev) {
                    ev.preventDefault();
                    ev.stopPropagation();
                    const idx = parseInt(this.getAttribute("data-marker-index"), 10);
                    const m = clusterMarkers[idx];
                    if (!m) return;

                    map.closePopup();
                    if (e.layer && e.layer.spiderfy) e.layer.spiderfy();
                    map.panTo(m.getLatLng(), { animate: true });
                    if (m.openPopup) m.openPopup();

                    const pulse = L.circleMarker(m.getLatLng(), {
                        radius: 16,
                        weight: 3,
                        color: "#ff6600",
                        fill: false,
                        opacity: 0.9
                    }).addTo(map);
                    setTimeout(() => map.removeLayer(pulse), 2000);
                });
            });
        });

        // Set base maps and grouped overlays.
        const urlParams = new URLSearchParams(window.location.search);
        let defaultProvider;
        try {
            defaultProvider = L.tileLayer.provider(urlParams.get('mapping_basemap_provider'));
        } catch (error) {
            try {
                defaultProvider = L.tileLayer.provider(options.basemapProvider);
            } catch (error) {
                defaultProvider = L.tileLayer.provider('OpenStreetMap.Mapnik');
            }
        }
        const baseMaps = {
            'Default': defaultProvider,
            'Streets': L.tileLayer.provider('OpenStreetMap.Mapnik'),
            'Grayscale': L.tileLayer.provider('CartoDB.Positron'),
            'Satellite': L.tileLayer.provider('Esri.WorldImagery'),
            'Terrain': L.tileLayer.provider('Esri.WorldShadedRelief')
        };

        // Add features and controls to the map.
        features.addLayer(featuresPoint).addLayer(featuresPoly);
        map.addLayer(defaultProvider).addLayer(features);
        if (!options.excludeLayersControl) {
            map.addControl(new L.Control.Layers(baseMaps));
        }
        if (!options.excludeFitBoundsControl) {
            map.addControl(new L.Control.FitBounds(features));
        }

        // Set the initial view to the geographical center of world.
        map.setView([20, 0], 2);

        return [map, features, featuresPoint, featuresPoly, baseMaps];
    },
    /**
     * Load features into a map asynchronously.
     *
     * @param {L.map}    map                       The Leaflet map object
     * @param {L.layer}  featuresPoint             The Leaflet layer object containing point features
     * @param {L.layer}  featuresPoly              The Leaflet layer object containing polygon features
     * @param {string}   getFeaturesUrl            The "get features" endpoint URL
     * @param {string}   getFeaturePopupContentUrl The "get feature popup content" endpoint URL
     * @param {object}   itemsQuery                The items query
     * @param {object}   featuresQuery             The features query
     * @param {callback} onFeaturesLoadSetView     An optional function called to set view after features are loaded
     * @param {object}   featuresByResource        An optional object
     * @param {int}      featuresPage              The
     */
    loadFeaturesAsync: function (
        map,
        featuresPoint,
        featuresPoly,
        getFeaturesUrl,
        getFeaturePopupContentUrl,
        itemsQuery,
        featuresQuery,
        onFeaturesLoad = () => null,
        featuresByResource = {},
        featuresPage = 1,
        blockData = null
    ) {
        // Normalize blockData (it may arrive as a JSON string)
        if (blockData && typeof blockData === "string") {
            try {
                blockData = JSON.parse(blockData);
            } catch (e) {
                blockData = {};
            }
        }
        if (!blockData) blockData = {};

        // Observe a map interaction (done programmatically or by the user).
        if ("undefined" === typeof map.mapping_map_interaction) {
            map.mapping_map_interaction = false;
            map.on("zoomend moveend", function (e) {
                map.mapping_map_interaction = true;
            });
        }
        const getFeaturesQuery = {
            features_page: featuresPage,
            items_query: itemsQuery,
            features_query: featuresQuery,
        };
        if (blockData) {
            getFeaturesQuery.block_data = JSON.stringify(blockData);
        }

        // Get features from the server, one page at a time.
        $.get(getFeaturesUrl, getFeaturesQuery).done(function (featuresData) {
            if (!featuresData.length) {
                // This page returned no features. Stop recursion.
                onFeaturesLoad();
                return;
            }
            // Iterate the features.
            featuresData.forEach((featureData) => {
                const featureId = featureData[0];
                const resourceId = featureData[1];
                const featureGeography = featureData[2];
                const color = featureData[3] || null; // NEW: hex or null

                // Build a consistent style object once
                const styleForPaths = color
                    ? {
                          color: color, // stroke
                          fillColor: color, // polygons
                          weight: featureGeography.properties?.['stroke-width'] || 2,
                          opacity: 1,
                          fillOpacity: 0.4,
                      }
                    : {};

                L.geoJSON(featureGeography, {
             
                    style: function (/* feature */) {
                        return styleForPaths;
                    },

                    // NEW: colorize points; if color present we render a circleMarker
                    pointToLayer: function (feature, latlng) {
                        if (color) {
                            // Create a custom-colored pin using Leaflet's built-in marker icon as a template.
                             const markerHtml = `
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"
                                width="38" height="38"> <!-- bigger -->
                                <path fill="${color}" stroke="#333" stroke-width="1.5"
                                    d="M12 2C8 2 5 5.1 5 9c0 4.6 7 13 7 13s7-8.4 7-13c0-3.9-3-7-7-7z"/>
                                <circle cx="12" cy="9" r="3" fill="white"/>
                            </svg>`;
                            const icon = L.divIcon({
                                html: markerHtml,
                                className: "custom-colored-pin",
                                iconSize: [38, 38], // match SVG size
                                iconAnchor: [19, 38], // anchor at bottom tip
                                popupAnchor: [0, -40],
                            });
                            return L.marker(latlng, { icon });
                        }
                        // Otherwise use the standard default marker
                        return L.marker(latlng);
                    },
                    onEachFeature: function (feature, layer) {
                        const popup = L.popup();
                        layer.bindPopup(popup);
                        if (getFeaturePopupContentUrl) {
                            layer.on("popupopen", function () {
                                const popupProps = Array.isArray(
                                    blockData.popup_display_properties
                                )
                                    ? blockData.popup_display_properties
                                    : [];

                                $.get(
                                    getFeaturePopupContentUrl,
                                    {
                                        feature_id: featureId,
                                        resource_id: resourceId,
                                        popup_props: JSON.stringify(popupProps),
                                        is_journey_map: (blockData && blockData.journey) ? 1 : 0,
                                    },
                                    function (popupContent) {
                                        popup.setContent(popupContent);
                                    }
                                );
                            });
                        }
                        MappingModule.addFeature(
                            map,
                            featuresPoint,
                            featuresPoly,
                            layer,
                            feature.type
                        );
                        if (!(resourceId in featuresByResource)) {
                            featuresByResource[resourceId] = L.featureGroup();
                        }
                        featuresByResource[resourceId].addLayer(layer);
                    },
                });
            });
            // Load more features recursively.
            MappingModule.loadFeaturesAsync(
                map,
                featuresPoint,
                featuresPoly,
                getFeaturesUrl,
                getFeaturePopupContentUrl,
                itemsQuery,
                featuresQuery,
                onFeaturesLoad,
                featuresByResource,
                ++featuresPage,
                blockData
            );
        });
    },
    /**
     * Add a feature layer to its respective layer.
     *
     * @param {L.map} map
     * @param {L.layer} featuresPoint
     * @param {L.layer} featuresPoly
     * @param {L.layer} layer
     * @param {string} type
     */
    addFeature: function(map, featuresPoint, featuresPoly, layer, type) {
        switch (type) {
            case 'Point':
                featuresPoint.addLayer(layer);
                break;
            case 'LineString':
            case 'Polygon':
            case 'MultiPolygon':
                layer.on('popupopen', function() {
                    layer.setStyle({color: '#9fc6fc'});
                    map.fitBounds(layer.getBounds());
                });
                layer.on('popupclose', function() {
                    layer.setStyle({color: '#3388ff'});
                });
                featuresPoly.addLayer(layer);
                break;
        }
    }
};
