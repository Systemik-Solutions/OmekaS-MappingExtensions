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
            if (map.mapping_sidebar_mode && map.mapping_show_cluster_sidebar) {
                if (e.layer && e.layer.spiderfy) e.layer.spiderfy();
                map.mapping_show_cluster_sidebar(clusterMarkers, e.latlng);
                return;
            }

            const count = clusterMarkers.length;

            let rowsHtml = "";
            clusterMarkers.forEach((marker, idx) => {
                const title = marker.feature.geometry.properties ? marker.feature.geometry.properties.title : "No title";
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

            // Click a title => focus its marker, open its popup, and highlight it.
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
                    MappingModule.highlightMarker(map, m);
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

        const sidebarTabsEnabled = !!(
            blockData.sidebar_tabs
            && (
                blockData.sidebar_tabs.enabled === "1"
                || blockData.sidebar_tabs.enabled === 1
                || blockData.sidebar_tabs.enabled === true
            )
        );
        const sidebarTabsInPopup = !!(
            sidebarTabsEnabled
            && blockData.sidebar_tabs
            && (
                blockData.sidebar_tabs.popup_enabled === "1"
                || blockData.sidebar_tabs.popup_enabled === 1
                || blockData.sidebar_tabs.popup_enabled === true
            )
        );
        map.mapping_sidebar_mode = sidebarTabsEnabled && !sidebarTabsInPopup;
        map.mapping_tabbed_popup_mode = sidebarTabsInPopup;

        if (map.mapping_sidebar_mode) {
            MappingModule.prepareSidebar(
                map,
                getFeaturePopupContentUrl,
                blockData
            );
        }

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

        // helper to build/update legend from AJAX data (NEW)
        function updateLegendFromAjax(legendEntries) {
            if (!legendEntries || !legendEntries.length) return;
    
            // Find the closest mapping-block for THIS map only
            const mapContainer = map._container;
            if (!mapContainer) return;

            const blockContainer = mapContainer.closest(".mapping-block");
            let el = mapContainer.querySelector(".mapping-legend");
            if (!el && blockContainer) {
                el = blockContainer.querySelector(".mapping-legend");
            }
            if (!el) {
                el = document.createElement("div");
                el.className = "mapping-legend";
                el.style.position = "absolute";
                el.style.left = "10px";
                el.style.bottom = "10px";
                el.style.zIndex = "1000";
                el.style.background = "#fff";
                el.style.border = "1px solid rgba(0,0,0,.15)";
                el.style.padding = "8px 10px";
                el.style.maxWidth = "240px";
                el.style.lineHeight = "1.3";
                el.style.boxShadow = "0 1px 10px rgba(0,0,0,0.4)";
                el.style.borderRadius = "10px";
                el.style.fontSize = "17px";
                el.style.maxHeight = "330px";
                el.style.overflow = "auto";
            }
            if (window.getComputedStyle(mapContainer).position === "static") {
                mapContainer.style.position = "relative";
            }
            mapContainer.appendChild(el);

            const esc = (s) =>
                String(s)
                    .replace(/&/g, "&amp;")
                    .replace(/</g, "&lt;")
                    .replace(/>/g, "&gt;")
                    .replace(/"/g, "&quot;")
                    .replace(/'/g, "&#39;");

            let html = '<div style="font-weight:600;margin-bottom:6px;"></div>';

            legendEntries.forEach(({ label, color }) => {
                const pinSvg =
                    '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 27" width="18" height="18" style="display:block;">' +
                    '<path fill="' + esc(color || "#6699ff") + '" stroke="#333" stroke-width="1.2" d="M12 1.8C8.5 1.8 5.5 5 5.5 9c0 4.8 6.5 14.5 6.5 14.5S18.5 13.8 18.5 9c0-4-3-7.2-6.5-7.2z"/>' +
                    '<circle cx="12" cy="9" r="2.8" fill="white"/>' +
                    "</svg>";

                html +=
                    '<div class="legend-row" style="display:flex;align-items:center;gap:8px;margin:4px 0;">' +
                    '<span class="legend-icon" aria-hidden="true" style="width:18px;height:18px;display:inline-block;">' +
                    pinSvg +
                    "</span>" +
                    '<span class="legend-label" style="flex:1 1 auto;">' +
                    esc(label) +
                    "</span>" +
                    "</div>";
            });

            el.innerHTML = html;
        }

        // Get features from the server, one page at a time.
        $.get(getFeaturesUrl, getFeaturesQuery).done(function (featuresData) {
            // ---- NEW: support object {features, legend} and old plain array ----
            let featuresArray = [];
            let legendEntries = [];

            if (Array.isArray(featuresData)) {
                // backward-compatible: old response = array of features
                featuresArray = featuresData;
            } else if (featuresData && typeof featuresData === "object") {
                featuresArray = Array.isArray(featuresData.features)
                    ? featuresData.features
                    : [];
                legendEntries = Array.isArray(featuresData.legend)
                    ? featuresData.legend
                    : [];
            }

            if (!featuresArray.length) {
                // This page returned no features. Stop recursion.
                // Also, if legend came only on last page, update once here
                if (legendEntries.length) {
                    updateLegendFromAjax(legendEntries);
                }
                onFeaturesLoad();
                return;
            }

            // If legend is provided on this page, update now (latest wins)
            if (legendEntries.length) {
                updateLegendFromAjax(legendEntries);
            }

            // Iterate the features.
            featuresArray.forEach((featureData) => {
                const featureId = featureData[0];
                const resourceId = featureData[1];
                const featureGeography = featureData[2];
                const color = featureData[3] || null; // NEW: hex or null
                const itemUrl = featureData[4] || '';

                // Build a consistent style object once
                const styleForPaths = color
                    ? {
                          color: color, // stroke
                          fillColor: color, // polygons
                          weight: featureGeography.properties?.['stroke-width'] || 6,
                          opacity: 1,
                          fillOpacity: 0.4,
                      }
                    : {
                        weight: featureGeography.properties?.['stroke-width'] || 6,
                    };

                L.geoJSON(featureGeography, {
                    style: function () {
                        return styleForPaths;
                    },
                    pointToLayer: function (feature, latlng) {
                        if (color) {
                            const markerHtml = `
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 27"
                                width="38" height="42"> 
                                <path fill="${color}"
                                d="M12 1.8C8.5 1.8 5.5 5 5.5 9c0 4.8 6.5 14.5 6.5 14.5S18.5 13.8 18.5 9c0-4-3-7.2-6.5-7.2z"/>
                                <circle cx="12" cy="9" r="2.8" fill="white"/>
                            </svg>
                            `;

                            const icon = L.divIcon({
                            html: markerHtml,
                            className: "custom-colored-pin",
                            iconSize: [38, 42],   // a little wider
                            iconAnchor: [19, 42], // keep tip aligned
                            popupAnchor: [0, -42],
                            });

                            return L.marker(latlng, { icon });
                        }

                        return L.marker(latlng);
                    },
                    onEachFeature: function (feature, layer) {
                        layer.mapping_feature_id = featureId;
                        layer.mapping_resource_id = resourceId;
                        layer.mapping_feature_title = featureGeography.properties?.title || '';
                        layer.mapping_feature_url = itemUrl;

                        if (getFeaturePopupContentUrl && sidebarTabsInPopup) {
                            const popup = L.popup({ maxWidth: 480 });
                            layer.bindPopup(popup);
                            layer.on("popupopen", function () {
                                MappingModule.showTabbedPopup(
                                    map,
                                    popup,
                                    layer,
                                    getFeaturePopupContentUrl,
                                    blockData
                                );
                            });
                        } else if (getFeaturePopupContentUrl && !sidebarTabsEnabled) {
                            const popup = L.popup();
                            layer.bindPopup(popup);
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
    clearMarkerHighlight: function(map, marker) {
        const current = map.mapping_marker_highlight;
        if (!current || (marker && current.marker !== marker)) {
            return;
        }

        if (current.pulse && map.hasLayer(current.pulse)) {
            map.removeLayer(current.pulse);
        }
        map.mapping_marker_highlight = null;
    },
    prepareSidebar: function(map, getFeaturePopupContentUrl, blockData) {
        if (map.mapping_sidebar_prepared) {
            return;
        }
        map.mapping_sidebar_prepared = true;

        const mapContainer = map._container;
        const block = mapContainer ? mapContainer.closest(".mapping-block") : null;
        if (!mapContainer) {
            return;
        }

        mapContainer.classList.add("mapping-sidebar-enabled");
        map.mapping_sidebar_popup_content_url = getFeaturePopupContentUrl;
        map.mapping_sidebar_block_data = blockData;

        let sidebar = mapContainer.querySelector(".mapping-sidebar-panel");
        if (!sidebar && block) {
            sidebar = block.querySelector(".mapping-sidebar-panel");
        }
        if (!sidebar) {
            sidebar = document.createElement("aside");
            sidebar.className = "mapping-sidebar-panel";
            sidebar.innerHTML = [
                '<button type="button" class="mapping-sidebar-close" aria-label="Close">&times;</button>',
                '<div class="mapping-sidebar-inner"></div>'
            ].join("");
        }
        if (window.getComputedStyle(mapContainer).position === "static") {
            mapContainer.style.position = "relative";
        }
        mapContainer.appendChild(sidebar);
        if (L.DomEvent) {
            L.DomEvent.disableClickPropagation(sidebar);
            L.DomEvent.disableScrollPropagation(sidebar);
        }

        const closeSidebar = function() {
            sidebar.classList.remove("is-open");
            sidebar.querySelector(".mapping-sidebar-inner").innerHTML = "";
            MappingModule.clearMarkerHighlight(map);
            if (map.closePopup) {
                map.closePopup();
            }
        };

        sidebar.querySelector(".mapping-sidebar-close").addEventListener("click", closeSidebar);
        map.on("popupclose", function() {
            if (!map.mapping_sidebar_mode) {
                return;
            }
            closeSidebar();
        });

        map.mapping_close_sidebar = closeSidebar;
        map.mapping_show_feature_sidebar = function(marker) {
            MappingModule.showSidebarFeature(
                map,
                sidebar,
                marker,
                getFeaturePopupContentUrl,
                blockData,
                false
            );
        };
        map.mapping_show_cluster_sidebar = function(markers, latlng) {
            const inner = sidebar.querySelector(".mapping-sidebar-inner");
            const count = markers.length;
            MappingModule.clearMarkerHighlight(map);
            map.mapping_sidebar_cluster_context = {
                markers: markers,
                latlng: latlng,
                label: count + ' ' + (count === 1 ? 'place' : 'places') + ' at this location'
            };

            let html = [
                '<div class="mapping-sidebar-header">',
                '<h3>' + MappingModule.escapeHtml(map.mapping_sidebar_cluster_context.label) + '</h3>',
                '<p>Choose a place to view its detail.</p>',
                '</div>',
                '<ul class="mapping-sidebar-list">'
            ].join("");

            markers.forEach(function(marker, index) {
                html += [
                    '<li>',
                    '<button type="button" class="mapping-sidebar-list-item" data-marker-index="' + index + '">',
                    '<span class="mapping-sidebar-list-dot" aria-hidden="true"></span>',
                    '<span>' + MappingModule.escapeHtml(marker.mapping_feature_title || 'Untitled') + '</span>',
                    '<span class="mapping-sidebar-list-arrow" aria-hidden="true">&rsaquo;</span>',
                    '</button>',
                    '</li>'
                ].join("");
            });
            html += '</ul>';

            inner.innerHTML = html;
            sidebar.classList.add("is-open");
            if (latlng) {
                map.panTo(latlng, { animate: true });
            }

            inner.querySelectorAll(".mapping-sidebar-list-item").forEach(function(button) {
                button.addEventListener("click", function() {
                    const marker = markers[parseInt(this.getAttribute("data-marker-index"), 10)];
                    if (!marker) {
                        return;
                    }
                    map.panTo(marker.getLatLng(), { animate: true });
                    MappingModule.highlightMarker(map, marker);
                    MappingModule.showSidebarFeature(map, sidebar, marker, getFeaturePopupContentUrl, blockData, true);
                });
            });
        };
    },
    showSidebarFeature: function(map, sidebar, marker, getFeaturePopupContentUrl, blockData, fromClusterList = false) {
        if (!sidebar || !marker) {
            return;
        }

        const inner = sidebar.querySelector(".mapping-sidebar-inner");
        const tabs = MappingModule.getSidebarTabs(blockData);
        const title = marker.mapping_feature_title || 'Item';
        const titleHtml = marker.mapping_feature_url
            ? '<a href="' + MappingModule.escapeHtml(marker.mapping_feature_url) + '">' + MappingModule.escapeHtml(title) + '</a>'
            : MappingModule.escapeHtml(title);

        const renderShell = function(activeIndex) {
            const tabButtons = tabs.map(function(tab, index) {
                return [
                    '<button type="button" class="mapping-sidebar-tab',
                    index === activeIndex ? ' is-active' : '',
                    '" data-tab-index="' + index + '">',
                    MappingModule.escapeHtml(tab.label || ('Tab ' + (index + 1))),
                    '</button>'
                ].join("");
            }).join("");
            const clusterContext = fromClusterList ? map.mapping_sidebar_cluster_context : null;
            const backButton = clusterContext
                ? '<button type="button" class="mapping-sidebar-back">&larr; ' + MappingModule.escapeHtml(clusterContext.label) + '</button>'
                : '';

            inner.innerHTML = [
                backButton,
                '<div class="mapping-sidebar-header">',
                '<h3>' + titleHtml + '</h3>',
                '</div>',
                '<div class="mapping-sidebar-tabs" role="tablist">',
                tabButtons,
                '</div>',
                '<div class="mapping-sidebar-content">Loading...</div>'
            ].join("");

            inner.querySelectorAll(".mapping-sidebar-tab").forEach(function(button) {
                button.addEventListener("click", function() {
                    loadTab(parseInt(this.getAttribute("data-tab-index"), 10));
                });
            });

            const back = inner.querySelector(".mapping-sidebar-back");
            if (back && clusterContext) {
                back.addEventListener("click", function() {
                    map.mapping_show_cluster_sidebar(clusterContext.markers, clusterContext.latlng);
                });
            }
        };

        const loadTab = function(activeIndex) {
            const tab = tabs[activeIndex] || tabs[0];
            renderShell(activeIndex);
            const content = inner.querySelector(".mapping-sidebar-content");

            if (!getFeaturePopupContentUrl || !marker.mapping_feature_id) {
                content.textContent = 'No details available.';
                return;
            }

            $.get(
                getFeaturePopupContentUrl,
                {
                    feature_id: marker.mapping_feature_id,
                    resource_id: marker.mapping_resource_id,
                    popup_props: JSON.stringify(MappingModule.tabUsesContent(tab, 'property') ? (tab.properties || []) : []),
                    is_journey_map: (blockData && blockData.journey) ? 1 : 0,
                    sidebar_content: 1,
                    sidebar_content_options: JSON.stringify(tab.popup_content || []),
                },
                function(popupContent) {
                    content.innerHTML = popupContent;
                }
            );
        };

        sidebar.classList.add("is-open");
        loadTab(0);
    },
    showTabbedPopup: function(map, popup, marker, getFeaturePopupContentUrl, blockData) {
        if (!popup || !marker) {
            return;
        }

        const tabs = MappingModule.getSidebarTabs(blockData);
        const title = marker.mapping_feature_title || 'Item';

        const renderShell = function(activeIndex) {
            const tabButtons = tabs.map(function(tab, index) {
                return [
                    '<button type="button" class="mapping-popup-tab',
                    index === activeIndex ? ' is-active' : '',
                    '" data-tab-index="' + index + '">',
                    MappingModule.escapeHtml(tab.label || ('Tab ' + (index + 1))),
                    '</button>'
                ].join("");
            }).join("");

            popup.setContent([
                '<div class="mapping-tabbed-popup">',
                '<div class="mapping-tabbed-popup-header">',
                '<h3>' + MappingModule.escapeHtml(title) + '</h3>',
                '</div>',
                '<div class="mapping-popup-tabs" role="tablist">',
                tabButtons,
                '</div>',
                '<div class="mapping-tabbed-popup-content mapping-sidebar-content">Loading...</div>',
                '</div>'
            ].join(""));

            const container = popup.getElement();
            if (!container) {
                return;
            }

            container.classList.add("mapping-tabbed-popup-leaflet");

            if (L.DomEvent) {
                L.DomEvent.disableClickPropagation(container);
                L.DomEvent.disableScrollPropagation(container);
            }

            container.querySelectorAll(".mapping-popup-tab").forEach(function(button) {
                button.addEventListener("click", function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    loadTab(parseInt(this.getAttribute("data-tab-index"), 10));
                });
            });
        };

        const loadTab = function(activeIndex) {
            const tab = tabs[activeIndex] || tabs[0];
            renderShell(activeIndex);

            const container = popup.getElement();
            const content = container ? container.querySelector(".mapping-tabbed-popup-content") : null;
            if (!content) {
                return;
            }

            if (!getFeaturePopupContentUrl || !marker.mapping_feature_id) {
                content.textContent = 'No details available.';
                return;
            }

            const requestId = (popup.mapping_tab_request_id || 0) + 1;
            popup.mapping_tab_request_id = requestId;
            $.get(
                getFeaturePopupContentUrl,
                {
                    feature_id: marker.mapping_feature_id,
                    resource_id: marker.mapping_resource_id,
                    popup_props: JSON.stringify(MappingModule.tabUsesContent(tab, 'property') ? (tab.properties || []) : []),
                    is_journey_map: (blockData && blockData.journey) ? 1 : 0,
                    sidebar_content: 1,
                    sidebar_content_options: JSON.stringify(tab.popup_content || []),
                },
                function(popupContent) {
                    if (popup.mapping_tab_request_id !== requestId) {
                        return;
                    }
                    const currentContainer = popup.getElement();
                    const currentContent = currentContainer ? currentContainer.querySelector(".mapping-tabbed-popup-content") : null;
                    if (currentContent) {
                        currentContent.innerHTML = popupContent;
                    }
                }
            );
        };

        loadTab(0);
    },
    getSidebarTabs: function(blockData) {
        const configuredTabs = blockData
            && blockData.sidebar_tabs
            && Array.isArray(blockData.sidebar_tabs.tabs)
            ? blockData.sidebar_tabs.tabs
            : [];

        const tabs = configuredTabs
            .filter(function(tab) {
                return tab && (
                    tab.label
                    || (Array.isArray(tab.properties) && tab.properties.length)
                    || (Array.isArray(tab.popup_content) && tab.popup_content.length)
                );
            })
            .map(function(tab) {
                return {
                    label: tab.label || 'Details',
                    properties: Array.isArray(tab.properties) ? tab.properties : [],
                    popup_content: Array.isArray(tab.popup_content) ? tab.popup_content : []
                };
            });

        return tabs.length ? tabs : [{
            label: 'Details',
            properties: Array.isArray(blockData.popup_display_properties)
                ? blockData.popup_display_properties
                : [],
            popup_content: ['property']
        }];
    },
    tabUsesContent: function(tab, contentKey) {
        return !!(tab && Array.isArray(tab.popup_content) && tab.popup_content.includes(contentKey));
    },
    escapeHtml: function(value) {
        return String(value)
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;")
            .replace(/'/g, "&#39;");
    },
    highlightMarker: function(map, marker) {
        if (!marker || !marker.getLatLng) {
            return;
        }

        MappingModule.clearMarkerHighlight(map);

        const pulse = L.circleMarker(marker.getLatLng(), {
            radius: 16,
            weight: 3,
            color: "#ff6600",
            fill: false,
            opacity: 0.9
        }).addTo(map);
        map.mapping_marker_highlight = {
            marker: marker,
            pulse: pulse
        };
    },
    addFeature: function(map, featuresPoint, featuresPoly, layer, type) {
        switch (type) {
            case 'Point':
            layer.on('click', function() {
                MappingModule.highlightMarker(map, layer);
                if (map.mapping_sidebar_mode && map.mapping_show_feature_sidebar) {
                    map.mapping_show_feature_sidebar(layer);
                }
            });
            layer.on('popupclose', function() {
                MappingModule.clearMarkerHighlight(map, layer);
            });
            featuresPoint.addLayer(layer);
            break;

            case 'LineString':
            case 'Polygon':
            case 'MultiPolygon':
            // cache original style (once)
            if (!layer._origStyle) {
                const o = layer.options || {};
                layer._origStyle = {
                color: o.color,
                weight: o.weight,
                fillColor: o.fillColor,
                opacity: o.opacity,
                fillOpacity: o.fillOpacity
                };
            }

            layer.on('popupopen', function() {
                const o = layer._origStyle;
                // keep the same color, just emphasize the weight (or tweak as you like)
                layer.setStyle({
                color: o.color,
                fillColor: o.fillColor,
                weight: (o.weight || 2) + 2,
                opacity: o.opacity ?? 2,
                fillOpacity: o.fillOpacity ?? 0.4
                });
                map.fitBounds(layer.getBounds());
            });

            layer.on('popupclose', function() {
                layer.setStyle(layer._origStyle);
            });

            featuresPoly.addLayer(layer);
            break;
        }
    }
};
