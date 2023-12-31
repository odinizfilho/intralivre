
<html>
<head>
    <title>PDF</title>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons+Outlined" rel="stylesheet">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/2.11.338/pdf.min.js"></script>
    <script>
        (function(exports, $) {
    'use strict';
    
    // Class used to help in zoom management; probably it can be moved to the main class, but it is used to group methods
    class Zoomer {
        /**
         * Construct the helper class
         * @param {PDFjsViewer} viewer - the viewer object
         * @param {*} options - the options object
         */
        constructor(viewer, options = {}) {
            let defaults = {
                // The possible zoom values to iterate through using "in" and "out"
                zoomValues: [ 0.25, 0.5, 0.75, 1, 1.25, 1.50, 2, 4, 8 ],
                // The area to fill the container with the zoomed pages
                fillArea: 0.9,
            }
    
            // The current zooom value
            this.current = 1;
            // The viewer instance whose pages may be zoomed
            this.viewer = viewer;
            // The settings
            this.settings = $.extend(defaults, options);
            
            // Need having the zoom values in order
            this.settings.zoomValues = this.settings.zoomValues.sort();
        }
    
        /** Translates a zoom value into a float value; possible values:
         * - a float value
         * - a string with a keyword (e.g. "width", "height", "fit", "in", "out")
         * @param {number} zoom - the zoom value to be translated
         * @return {number} The zoom value
        */
        get(zoom = null) {
            // If no zoom is specified, return the current one
            if (zoom === null) {
                return this.current;
            }
            // If it is a number, return it
            if (parseFloat(zoom) == zoom) {
                return zoom;
            } 
            let $activepage = this.viewer.getActivePage();
            let zoomValues = [];
            // If it is a keyword, return the corresponding value
            switch(zoom) {
                case "in":
                    zoom = this.current;
                    zoomValues = this.settings.zoomValues.filter((x) => x > zoom);
                    if (zoomValues.length > 0) {
                        zoom = Math.min(...zoomValues);
                    }
                    break;
                case "out":
                    zoom = this.current;
                    zoomValues = this.settings.zoomValues.filter((x) => x < zoom);
                    if (zoomValues.length > 0) {
                        zoom = Math.max(...zoomValues);
                    }
                    break;
                case "fit":
                    zoom = Math.min(this.get("width"), this.get("height"));
                    break;
                case "width":
                    zoom = this.settings.fillArea * this.viewer.$container.width() / $activepage.data("width");
                    break;
                case "height":
                    zoom = this.settings.fillArea * this.viewer.$container.height() / $activepage.data("height");
                    break;
                default:
                    zoom = this.current;
                    break;
            }
            return zoom;
        }
    
        /**
         * Sets the zoom value to each page (changes both the page and the content div); relies on the data-values for the page
         * @param {number} zoom - the zoom value to be set
         */
        zoomPages(zoom) {
            zoom = this.get(zoom);
            this.viewer.getPages().forEach(function(page) {
                let $page = page.$div;
                let c_width = $page.data("width");
                let c_height = $page.data("height");
    
                $page.width(c_width * zoom).height(c_height * zoom);
                $page.data('zoom', zoom);
                $page.find(`.${this.viewer.settings.contentClass}`).width(c_width * zoom).height(c_height * zoom);
            }.bind(this));
            this.current = zoom;
        }
    }

    class PDFjsViewer {
        /**
         * Constructs the object, and initializes actions:
         *   - add the scroll handler to the container
         *   - set the first adjusting action when the page is loaded
         *   - creates the zoom helper
         * @param {jQuery} $container the jQuery value that will hold the pages
         * @param {dictionary} options options for the viewer
         */
        constructor($container, options = {}) {
    
            let defaults = {
                visibleThreshold: 0.5,
                extraPagesToLoad: 3,
                // The class used for each page (the div that wraps the content of the page)
                pageClass: "pdfpage",
                // The class used for the content of each page (the div that contains the page)
                contentClass: "content-wrapper",
                // Function called when a document has been loaded and its structure has been created
                onDocumentReady: () => {},
                // Function called when a new page is created (it is binded to the object, and receives a jQuery object as parameter)
                onNewPage: (page, i) => {},
                // Function called when a page is rendered
                onPageRender: (page, i) => {},
                // Function called to obtain a page that shows an error when the document could not be loaded (returns a jQuery object)
                errorPage: () => {
                    $(`<div class="placeholder"></div>`).addClass(this.settings.pageClass).append($(`<p class="m-auto"></p>`).text("could not load document"))
                },
                // Posible zoom values to iterate over using "in" and "out"
                zoomValues: [ 0.25, 0.5, 0.75, 1, 1.25, 1.50, 2, 4, 8 ],
                // Function called when the zoom level changes (it receives the zoom level)
                onZoomChange: (zoomlevel) => {},
                // Function called whenever the active page is changed (the active page is the one that is shown in the viewer)
                onActivePageChanged: (page, i) => {},
                // Percentage of the container that will be filled with the page
                zoomFillArea: 0.95,
                // Function called to get the content of an empty page
                emptyContent: () => $('<div class="loader"></div>')
            }
    
            this.settings = $.extend(defaults, options);
    
            // Create the zoomer helper
            this._zoom = new Zoomer(this, {
                zoomValues: this.settings.zoomValues,
                fillArea: this.settings.zoomFillArea,
            });
    
            // Store the container
            this.$container = $container;
    
            // Add a reference to this object to the container
            $container.get(0)._pdfjsViewer = this;
    
            // Add the event listeners
            this._setScrollListener();

            // Initialize some variables
            this.pages = [];
            this.pdf = null;
        }    
    
        /**
         * Sets the current zoom level and applies it to all the pages
         * @param {number} zoom the desired zoom level, which will be a value (1 equals to 100%), or the keywords 'in', 'out', 'width', 'height' or 'fit'
         */
        setZoom(zoom) {
            let container = this.$container.get(0);

            // Get the previous zoom and scroll position
            let prevzoom = this._zoom.current;
            let prevScroll = {
                top: container.scrollTop,
                left: container.scrollLeft
            };

            // Now zoom the pages
            this._zoom.zoomPages(zoom);

            // Update the scroll position (to match the previous one), according to the new relationship of zoom
            container.scrollLeft = prevScroll.left * this._zoom.current / prevzoom;
            container.scrollTop = prevScroll.top * this._zoom.current / prevzoom;

            // Force to redraw the visible pages to upgrade the resolution
            this._visiblePages(true);

            // Call the callback (if provided)
            if (typeof this.settings.onZoomChange === "function")
                this.settings.onZoomChange.call(this, this._zoom.current);
        }
    
        /**
         * Obtain the current zoom level
         * @returns {number} the current zoom level
         */
        getZoom() {
            return this._zoom.current;
        }
    
        /**
         * Function that removes the content of a page and replaces it with the empty content (i.e. a content generated by function emptyContent)
         *   such content will not be visible except for the time that the 
         * @param {jQuery} $page the page to be emptied
         */
        _cleanPage($page) {
            let $emptyContent = this.settings.emptyContent();
            $page.find(`.${this.settings.contentClass}`).empty().append($emptyContent)
        }
    
        /**
         * Function that replaces the content with the empty class in a page with a new content
         * @param {*} $page the page to be modified
         * @param {*} $content the new content that will be set in the page
         */
        _setPageContent($page, $content) {
            $page.find(`.${this.settings.contentClass}`).empty().append($content)
        }

        /**
         *  Recalculates which pages are now visible and forces redrawing them (moreover it cleans those not visible) 
        */
        refreshAll() {
            this._visiblePages(true);
        }
    
        /** Function that creates a scroll handler to update the active page and to load more pages as the scroll position changes */
        _setScrollListener() {
            // Create a scroll handler that prevents reentrance if called multiple times and the loading of pages is not finished
            let scrollLock = false;
            let scrollPos = { top:0 , left:0 };
    
            this.__scrollHandler = function(e) {
                // Avoid re-entrance for the same event while loading pages
                if (scrollLock === true) {
                    return;
                }
                scrollLock = true;
    
                let container = this.$container.get(0);
                if ((Math.abs(container.scrollTop - scrollPos.top) > (container.clientHeight * 0.2 * this._zoom.current)) ||
                    (Math.abs(container.scrollLeft - scrollPos.left) > (container.clientWidth * 0.2 * this._zoom.current))) {
                    scrollPos = {
                        top: container.scrollTop,
                        left: container.scrollLeft
                    }
                    this._visiblePages();
                }
    
                scrollLock = false;
            }.bind(this);
    
            // Set the scroll handler
            this.$container.off('scroll');
            this.$container.on('scroll', this.__scrollHandler);            
        }    
        /**
         * Function that creates the pageinfo structure for one page, along with the skeleton to host the page (i.e. <div class="page"><div class="content-wrapper"></div></div>)
         *   If the page is a pageinfo, the new pageinfo structure will not rely on the size (it will copy it, but it won't be marked as loaded). If it is a page, the size will
         *   be calculated from the viewport and it will be marked as loaded.
         *   This is done in this way, because when creating the pages in the first time, they will be created assuming that they are of the same size than the first one. If they
         *   are not, the size will be adjusted later, when the pages are loaded.
         * 
         * @param {*} page - the pageinfo (or the page) from which to create the pageinfo structure
         * @param {*} i - the number of the page to be created
         * @returns pageinfo - the pageinfo structure for the page
         */
        _createSkeleton(page, i) {
            let pageinfo = {
                $div: null,
                width: 0,
                height: 0,
                loaded: false,
            };
    
            // If it is a page, the size will be obtained from the viewport; otherwise, it will be copied from the provided pageinfo
            if (page.getViewport !== undefined) {
                let viewport = page.getViewport({rotation:this._rotation,scale:1});
                pageinfo.width = viewport.width;
                pageinfo.height = viewport.height;
                pageinfo.loaded = true;
            } else {
                pageinfo.width = page.width;
                pageinfo.height = page.height;
            }
            console.assert(((pageinfo.width > 0) && (pageinfo.height > 0)), "Page width and height must be greater than 0");
    
            // Now create the skeleton for the divs
            pageinfo.$div = $(`<div id="page-${i}">`)
                .attr('data-page', i)
                .data('width', pageinfo.width)
                .data('height', pageinfo.height)
                .data('zoom', this._zoom.current)
                .addClass(this.settings.pageClass)
                .width(pageinfo.width)
                .height(pageinfo.height);

            let $content = $(`<div class="${this.settings.contentClass}">`)
                .width(pageinfo.width)
                .height(pageinfo.height);

            pageinfo.$div.append($content);
            
            // Clean the page (i.e. put the empty content, etc.)
            this._cleanPage(pageinfo.$div);
            
            return pageinfo;
        }
    
        /**
         * This function places the page.$div in the container, according to its page number (i.e. it searches for the previous page and puts this page after)
         *   * in principle, this method sould not be needed because all the pages are put in order; but this is created just in case it is needed in further versions
         * @param {*} pageinfo - the pageinfo structure for the page (needs a valid $div)
         * @param {*} i - the number of the page
         */
        _placeSkeleton(pageinfo, i) {
            let prevpage = i - 1;
            let $prevpage = null;
            while ((prevpage>0) && (($prevpage = this.$container.find(`.${this.settings.pageClass}[data-page="${prevpage}"]`)).length === 0)) {
                prevpage--;
            }
            if (prevpage === 0) {
                this.$container.append(pageinfo.$div);
            }
            else {
                $prevpage.after(pageinfo.$div);
            }
        }
    
        /**
         * Creates the initial skeletons for all the pages, and places them into the container
         * @param {page/pageinfo} pageinfo - the initial pageinfo (or page) structure
         */
        _createSkeletons(pageinfo) {
            for (let i = 1; i <= this.pageCount; i++) {
                if (this.pages[i] === undefined) {

                    // Create the pageinfo structure, store it and place it in the appropriate place (the next page will be created similar to the previous one)
                    pageinfo = this._createSkeleton(pageinfo, i);
                    this.pages[i] = pageinfo;
                    this._placeSkeleton(pageinfo, i);
    
                    // Call the callback function (if provided)
                    if (typeof this.settings.onNewPage === "function") {
                        this.settings.onNewPage.call(this, pageinfo.$div, i);
                    }
                }
            }
        }
    
        /**
         * Function to set the active page, and calling the callback (if provided)
         * @param {*} i - the number of the page to set active
         */
        _setActivePage(i) {
            if (this._activePage !== i) {
                this._activePage = i;
                if (typeof this.settings.onActivePageChanged === "function")
                    this.settings.onActivePageChanged.call(this, this.getActivePage(), i);
            }
        }
    
        /**
         * Obtains the area of a div that falls in the viewer
         * @param {*} $page - div whose area is to be calculated
         * @returns the visible area
         */
        _areaOfPageVisible($page) {
            if ($page === undefined) {
                return 0;
            }
            let c_offset = this.$container.offset();
            let c_width = this.$container.width();
            let c_height = this.$container.height();
            let position = $page.offset();
            position.top -= c_offset.top;
            position.left -= c_offset.left;
            position.bottom = position.top + $page.outerHeight();
            position.right = position.left + $page.outerWidth();
            let page_y0 = Math.min(Math.max(position.top, 0), c_height);
            let page_y1 = Math.min(Math.max($page.outerHeight() + position.top, 0), c_height);
            let page_x0 = Math.min(Math.max(position.left, 0), c_width);
            let page_x1 = Math.min(Math.max($page.outerWidth() + position.left, 0), c_width);
            let vis_x = page_x1 - page_x0;
            let vis_y = page_y1 - page_y0;
            return (vis_x * vis_y);
        }

        /**
         * Function that returns true if the page is considered to be visible (the amount of visible area is greater than the threshold)
         * @param {*} i - the number of page to check
         * @returns true if the page is visible
         */
        isPageVisible(i) {
            if ((this.pdf === null) || (i === undefined) || (i === null) || (i < 1) || (i > this.pdf.numPages)) {
                return false;
            }
            let $page = i;
            if (typeof i === "number") {
                if (this.pages[i] === undefined)
                    return false;
                $page = this.pages[i].$div;
            }
            return this._areaOfPageVisible($page) > ($page.outerWidth() * $page.outerHeight() * this.settings.visibleThreshold);
        }

        /**
         * Function that calculates which pages are visible in the viewer, draws them (if not already drawn), and clears those not visible
         * @param {*} forceRedraw - if true, the visible pages will be redrawn regardless of whether they are already drawn (useful for zoom changes)
         */
        _visiblePages(forceRedraw = false) {    
            // Will grab the page with the greater visible area to set it as active
            let max_area = 0;
            let i_page = null;
    
            // If there are no visible pages, return
            if (this.pages.length === 0) {
                this._visibles = [];
                this._setActivePage(0);
                return;
            }

            // Calculate the visible area for each page and consider it visible if the visible area is greater than 0
            let $visibles = this.pages.filter(function(pageinfo) {
                let areaVisible = this._areaOfPageVisible(pageinfo.$div);
                if (areaVisible > max_area) {
                    max_area = areaVisible;
                    i_page = pageinfo.$div.data('page');
                }
                return areaVisible > 0;
            }.bind(this)).map((x) => x.$div);
    
            // Set the active page
            this._setActivePage(i_page);
    
            // Now get the visible pages
            let visibles = $visibles.map((x) => $(x).data('page'));
            if (visibles.length > 0) {
                // Now will add some extra pages (before and after) the visible ones, to have them prepared in case of scroll
                let minVisible = Math.min(...visibles);
                let maxVisible = Math.max(...visibles);

                for (let i = Math.max(1, minVisible - this.settings.extraPagesToLoad) ; i < minVisible ; i++) {
                    if (!visibles.includes(i)) 
                        visibles.push(i)
                }
                for (let i = maxVisible + 1; i <= Math.min(maxVisible + this.settings.extraPagesToLoad, this.pdf.numPages); i++) {
                    if (!visibles.includes(i)) 
                        visibles.push(i)
                }
            }
    
            // Now will draw the visible pages, but if not forcing, will only draw those that were not visible before
            let nowVisibles = visibles;
            if (! forceRedraw) {
                nowVisibles = visibles.filter(function (x) { 
                    return !this._visibles.includes(x) 
                }.bind(this));
            }
    
            // Get the pages that were visible before, that are not visible now, and clear them
            this._visibles.filter(function (x) { 
                return !visibles.includes(x) 
            }).forEach(function (i) {
                this._cleanPage(this.pages[i].$div);
            }.bind(this))
    
            // Store the new visible pages
            this._visibles = visibles;
    
            // And now we'll queue the pages to load
            this.loadPages(...nowVisibles);
        }

        /**
         * Function queue a set of pages to be loaded; if not loading, the function starts the loading worker
         * @param  {...pageinfo} pages - the pages to load
         */
        loadPages(...pages) {
            this._pagesLoading.push(...pages);
            if (this._loading) {
                return;
            }
            this._loadingTask();
        }

        /**
         * Function that gets the pages pending to load and renders them sequentially (to avoid multiple rendering promises)
         */
         _loadingTask() {
            this._loading = true;
            if (this._pagesLoading.length > 0) {
                let pagei = this._pagesLoading.shift();                
                this.pdf.getPage(pagei).then(function(page) {
                    // Render the page and update the information about the page with the loaded values
                    this._renderPage(page, pagei);
                }.bind(this)).then(function(pageinfo) {
                    // Once loaded, we are not loading anymore
                    if (this._pagesLoading.length > 0) {
                        this._loadingTask();
                    }
                }.bind(this));
            }
            // Free the loading state
            this._loading = false;
        }        
    
        /**
         * Function that sets the scroll position of the container to the specified page
         * @param {*} i - the number of the page to set the scroll position
         */
        scrollToPage(i) {
            if ((this.pages.length === 0) || (this.pages[i] === undefined)) {
                return;
            }
            let $page = this.pages[i].$div;
            if ($page.length === 0) {
                console.warn(`Page ${i} not found`);
                return;
            }
            let position = $page.position();
            if (position !== undefined) {
                this.$container.get(0).scrollTop = this.$container.get(0).scrollTop + position.top;
                this.$container.get(0).scrollLeft = this.$container.get(0).scrollLeft + position.left;
            }
            this._setActivePage(i);
        }

        /**
         * Function that renders the page in a canvas, and sets the canvas into the $div
         * @param {*} page - the page to be rendered
         * @param {*} i - the number of the page to be rendered
         * @returns a promise to render the page (the result of the promise will be the pageinfo)
         */
        _renderPage(page, i) {
            // Get the pageinfo structure
            let pageinfo = this.pages[i];
    
            // Calculate the pixel ratio of the device (we'll use a minimum of 1)
            let pixel_ratio =  Math.max(window.devicePixelRatio || 1, 1);
    
            // Update the information that we know about the page to the actually loaded page
            let viewport = page.getViewport({rotation: this._rotation, scale: this._zoom.current * pixel_ratio});
            pageinfo.width = (viewport.width / this._zoom.current) / pixel_ratio;
            pageinfo.height = (viewport.height / this._zoom.current) / pixel_ratio;
            pageinfo.$div.data("width", pageinfo.width);
            pageinfo.$div.data("height", pageinfo.height);
            pageinfo.loaded = true;
    
            // Create the canvas and prepare the rendering context
            let $canvas = $('<canvas></canvas>');
            let canvas = $canvas.get(0);
            let context = canvas.getContext('2d');
            canvas.height = viewport.height; // * pixel_ratio;
            canvas.width = viewport.width; //  * pixel_ratio;
            canvas.getContext("2d"); //.scale(pixel_ratio, pixel_ratio);
            var renderContext = {
                canvasContext: context,
                viewport: viewport
            };
    
            // Render the page and put the resulting rendered canvas into the page $div
            return page.render(renderContext).promise.then(function() {
                this._setPageContent(pageinfo.$div, $canvas);

                // Call the callback (if provided)
                if (typeof this.settings.onPageRender === "function") {
                    this.settings.onPageRender.call(this, pageinfo.$div, i);
                }
                return pageinfo;
            }.bind(this));
        }
    
        /** Gets the div object corresponding to the active page */
        getActivePage() {
            if ((this._activePage === null) || (this.pdf === null)) {
                return null;
            }
            if ((this._activePage < 1) || (this._activePage > this.pdf.numPages)) {
                return null;
            }
            return this.pages[this._activePage].$div;
        }
    
        /** Gets all the pages of the document (the pageinfo structures) */
        getPages() {
            return this.pages;
        }
    
        /** Gets the number of pages of the document */
        getPageCount() {
            if (this.pdf === null) {
                return 0;
            }
            return this.pdf.numPages;
        }

        /** Scrolls to the next page (if any) */ 
        next() {
            if (this._activePage < this.pdf.numPages) {
                this.scrollToPage(this._activePage + 1);
            }
        }
    
        /** Scrolls to the previous page (if any) */
        prev() {
            if (this._activePage > 1) {
                this.scrollToPage(this._activePage - 1);
            }
        }

        first() {
            if (this._activePage !== 1) {
                this.scrollToPage(1);
            }
        }
    
        last() {
            if (this.pdf === null)
                return;
            if (this._activePage !== this.pdf.numPages) {
                this.scrollToPage(this.pdf.numPages);
            }
        }
        /**
         * Rotates the pages of the document
         * @param {*} deg - degrees to rotate the pages
         * @param {*} accumulate - whether the rotation is accumulated or not
         */
        rotate(deg, accumulate = false) {
            if (accumulate) {
                deg = deg + this._rotation;
            }
            this._rotation = deg;

            let container = this.$container.get(0);
            let prevScroll = {
                top: container.scrollTop,
                left: container.scrollLeft,
                height: container.scrollHeight,
                width: container.scrollWidth
            };

            return this.forceViewerInitialization().then(function() {
                let newScroll = {
                    top: container.scrollTop,
                    left: container.scrollLeft,
                    height: container.scrollHeight,
                    width: container.scrollWidth
                };
                container.scrollTop = prevScroll.top * (newScroll.height / prevScroll.height);
                container.scrollLeft = prevScroll.left * (newScroll.width / prevScroll.width);
            }.bind(this));
        }
        /**
         * This functions forces the creation of the whole content of the viewer (i.e. new divs, structures, etc.). It is usefull for full refresh of the viewer (e.g. when changes
         *   the rotation of the pages)
         * @returns a promise that is resolved when the viewer is fully initialized
         */
        forceViewerInitialization() {
            // Store the pdf file
            // Now prepare a placeholder for the pages
            this.pages = [];
    
            // Remove all the pages
            this.$container.find(`.${this.settings.pageClass}`).remove();

            this._pagesLoading = [];
            this._loading = false;
            this._visibles = [];
            this._activePage = null;
            return this.pdf.getPage(1).then(function(page) {
                this._createSkeletons(page);
                this._visiblePages();
                this._setActivePage(1);
            }.bind(this));
        }
        /** 
         * Loads the document and creates the pages
         * @param {string} document - the url of the document to load
         */
        async loadDocument(document) {
            // Now prepare a placeholder for the pages
            this.pages = [];
    
            // Remove all the pages
            this.$container.find(`.${this.settings.pageClass}`).remove();
    
            // Let's free the pdf file (if there was one before), and rely on the garbage collector to free the memory
            this.pdf = null;

            // Load the task and return the promise to load the document
            let loadingTask = pdfjsLib.getDocument(document);
            return loadingTask.promise.then(function(pdf) {
                // Store the pdf file and get the 
                this.pdf = pdf;
                this.pageCount = pdf.numPages;
                this._rotation = 0;
                return this.forceViewerInitialization();
            }.bind(this)).then(function() {
                if (typeof this.settings.onDocumentReady === "function") {
                    this.settings.onDocumentReady.call(this);
                }
            }.bind(this));
        }
    }
    exports.PDFjsViewer = PDFjsViewer;
})(window, jQuery)
    </script>
<style>

.pdfjs-viewer {
    overflow: auto;
    border: 1px solid #aaa;
    background: #ccc;
    /* width: 100%; */
}
.pdfjs-viewer.horizontal-scroll {
    display: flex;
}
.pdfjs-viewer.horizontal-scroll .pdfpage {
    margin-left: 1em;
    margin-top: 0.25em !important;
    margin-bottom: 0.25em !important;
    display: block;
}
.pdfpage {
    position: relative;
    margin-bottom: 1em;
    margin-top: 1em;
    margin-left: auto;
    margin-right: auto;
    box-shadow: 0 4px 8px 0 rgba(0, 0, 0, 0.1), 0 6px 20px 0 rgba(0, 0, 0, 0.09);
    display: flex;
}
.pdfpage canvas {
    position: absolute;
    left: 0;
    top: 0;
    height: 100%;
    width: 100%;
}
.pdfpage.placeholder {
    display: flex;
    margin-bottom: 0em !important;
    margin-top: 0em !important;
    height: 100%;
    width: 100%;
}
.pdfpage .content-wrapper {
    margin: 0 !important;
    padding: 0 !important;
    display: flex !important;
}
.pdfpage .content-wrapper .loader {
    border: 2px solid #f3f3f3;
    border-top: 3px solid #3498db;
    border-radius: 50%;
    width: 24px;
    height: 24px;
    animation: spin 1s linear infinite;
    margin: auto;
}
@keyframes spin {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}
.pdftoolbar {
    width: 100%;
    height: 32px;
    background: #ddd;
    z-index: 100;
    vertical-align: middle;
    display: flex;
    margin: 0;
    padding: 0;
}
.pdftoolbar * {
    margin: auto 0;
}
.pdftoolbar span {
    margin-right: 0.5em;
    margin-left: 0.5em;
    width: 4em !important;
    font-size: 12px;
}
.pdftoolbar button, .pdftoolbar label.button {
    min-width: 26px;
    height: 28px;
    border: none;
    padding: 2px 4px 0;
    margin: auto 1px;
    border-radius: 2px;
    line-height: 12px;
    font-size: 14px;
    background-color: #ddd;
}
.pdftoolbar button i, .pdftoolbar label.button i {
    font-size: 26px;
    padding: 0;
    margin: 0;
}
.pdftoolbar button:hover, .pdftoolbar label.button:hover {
    background-color: #ccc;
}
button.pushed {
    background-color: #aaa !important;
}
.pdftoolbar a {
    color: inherit;
}
.pdftoolbar .divider {
    flex: 1;
}
.pdftoolbar .v-sep {
    width: 0px;
    height: 20px;
    border-left: 1px solid #bbb;
}
.pdftoolbar .h-sep {
    width: 100%;
    height: 0px;
    border-top: 1px solid #bbb;
    margin: 0.25em 0;
}

.pdftoolbar .dropdown.dropdown-right {
    float: right
}
.pdftoolbar .dropdown.dropdown-right .dropdown-content {
    right: 0;
    left: auto;
}
.pdftoolbar .dropdown-value {
    background-color: #ccc;
    padding: 0px 4px 2;
    cursor: pointer;
}
.pdftoolbar .dropdown-value i {
    width: auto;
    font-size: 12px;
}        
/* Dropdown Content (Hidden by Default) */
.pdftoolbar .dropdown-content {
    display: none;
    position: absolute;
    margin-top: 0;
    background-color: #eee;
    min-width: 10em;
    z-index: 1;
    font-size: 12px;
    box-shadow: 0 4px 8px 0 rgba(0, 0, 0, 0.1), 0 6px 20px 0 rgba(0, 0, 0, 0.09);
}
/* Links inside the dropdown */
.pdftoolbar .dropdown-content a {
    color: black;
    padding: 6px 8px;
    text-decoration: none;
    display: flex;
    cursor: pointer;
}
.pdftoolbar .dropdown-content i {
    font-size: 16px;
    padding-right: 0.5em;
}
.pdftoolbar .dropdown-content a:hover {
    background-color: #ddd;
}
.pdftoolbar .dropdown:hover .dropdown-content, .dropdown .dropdown-content:hover {
    display: block;
}
</style>
<script>
    var pdfjsLib = window['pdfjs-dist/build/pdf'];

    // The workerSrc property shall be specified.
    pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/2.11.338/pdf.worker.min.js';
    </script>
    <style>
        body {
            font-family: "Helvetica Neue", Helvetica, Arial, sans-serif;
            margin: 0;
            padding: 0;
        }
        .pdfviewer {
            height: 100%;
            margin: 0;
            padding: 0;
            display: flex;
            flex-direction: column;
        }
        .pdfviewer-container {
            margin: 0;
            padding: 0;
            display: flex;
            overflow: hidden;
            flex: 1;
        }
        .thumbnails {
            width: 150px !important;
            border: 1px solid #aaa;
            border-right: 3px solid #999;
            background: #ccc;
        }
        .thumbnails .pdfpage.selected {
            border: 2px solid #777;
            border-radius: 2px;
        }
        .maindoc {
            flex: 1;
        }
        .hide {
            display: none;
        }
        input[type="file"] {
            display: none;
        }
    </style>
</head>
<body>
    <div class="pdfviewer">
        <div class="pdftoolbar">
            <button class="pushed" onclick="togglethumbs(this);"><i class="material-icons-outlined">view_sidebar</i></button>
            <div class="v-sep"></div>
            <button onclick="pdfViewer.prev();"><i class="material-icons-outlined">arrow_upward</i></button>
            <div class="v-sep"></div>
            <button onclick="pdfViewer.next();"><i class="material-icons-outlined">arrow_downward</i></button>
            <input id="pageno" class="pageno" type="number" class="form-control form-control-sm d-inline w-auto" value="1" min="1" max="1000" onchange="pdfViewer.scrollToPage(parseInt(this.value))">
            <span id="pagecount" class="pageno"></span>
            <div class="divider"></div>
            <button onclick="pdfViewer.setZoom('in')"><i class="material-icons-outlined">add</i></button>
            <div class="v-sep"></div>
            <button onclick="pdfViewer.setZoom('out')"><i class="material-icons-outlined">remove</i></button>
            <div class="dropdown">
                <div class="dropdown-value" onclick="this.parentNode.classList.toggle('show');">
                    <span class="zoomval">100%</span>
                    <i class="material-icons-outlined">
                        keyboard_arrow_down
                    </i>
                </div>
                <div class="dropdown-content" onclick="this.parentNode.classList.toggle('show');">
                    <a href="#" onclick='pdfViewer.setZoom("width"); return false;'>Adjust width</a>
                    <a href="#" onclick='pdfViewer.setZoom("height"); return false;'>Adjust height</a>
                    <a href="#" onclick='pdfViewer.setZoom("fit"); return false;'>Fit page</a>
                    <a href="#" onclick='pdfViewer.setZoom(0.5); return false;'>50%</a>
                    <a href="#" onclick='pdfViewer.setZoom(0.75); return false;'>75%</a>
                    <a href="#" onclick='pdfViewer.setZoom(1); return false;'>100%</a>
                    <a href="#" onclick='pdfViewer.setZoom(1.25); return false;'>125%</a>
                    <a href="#" onclick='pdfViewer.setZoom(1.5); return false;'>150%</a>
                    <a href="#" onclick='pdfViewer.setZoom(2); return false;'>200%</a>
                    <a href="#" onclick='pdfViewer.setZoom(3); return false;'>300%</a>
                    <a href="#" onclick='pdfViewer.setZoom(4); return false;'>400%</a>
                </div>
            </div>
            <div class="divider"></div>
            <label class="button" for="opendoc"><i class="material-icons-outlined">file_open</i></label>
            <input id="opendoc" type="file" accept="application/pdf">
            <a id="filedownload" class="btn btn-secondary btn-sm"><i class="material-icons-outlined">file_download</i></a>
            <div class="dropdown dropdown-right">
                <div onclick="this.parentNode.classList.toggle('show');">
                    <button><i class="material-icons-outlined">keyboard_double_arrow_right</i></button>
                </div>
                <div class="dropdown-content" onclick="this.parentNode.classList.toggle('show');">
                    <a href="#" onclick='pdfViewer.scrollToPage(1); return false;'><i class="material-icons-outlined">vertical_align_top</i>First page</a>
                    <a href="#" onclick='pdfViewer.scrollToPage(pdfViewer.pdf.numPages); return false;'><i class="material-icons-outlined">vertical_align_bottom</i>Last page</a>
                    <div class="h-sep"></div>
                    <a href="#" onclick='pdfViewer.rotate(-90, true); pdfThumbnails.rotate(-90, true).then(() => pdfThumbnails.setZoom("fit"));'><i class="material-icons-outlined">rotate_90_degrees_ccw</i>Rotate countrary clockwise</a>
                    <a href="#" onclick='pdfViewer.rotate(90, true); pdfThumbnails.rotate(90, true).then(() => pdfThumbnails.setZoom("fit"));'><i class="material-icons-outlined">rotate_90_degrees_cw</i>Rotate clockwise</a>
                    <div class="h-sep"></div>
                    <a href="#" onclick='document.querySelector(".pdfjs-viewer").classList.remove("horizontal-scroll"); pdfViewer.refreshAll();'><i class="material-icons-outlined">more_vert</i>Vertical scroll</a>
                    <a href="#" onclick='setHorizontal()'><i class="material-icons-outlined">more_horiz</i>Horizontal scroll</a>
                </div>
            </div>
        </div>
        <div class="pdfviewer-container">
            <div class="thumbnails pdfjs-viewer">
            </div>
            <div class="maindoc pdfjs-viewer">
                <div class="pdfpage placeholder">
                    <p class="my-auto mx-auto">Cargue un fichero</p>
                </div>
            </div>
        </div>
    </div>
</body>
<script>
let PDFFILE="/documentos/export/<?php echo e($id); ?>/pdf";
function dataURItoBinArray(data) {
    // taken from: https://stackoverflow.com/a/11954337/14699733
    var binary = atob(data);
    var array = [];
    for(var i = 0; i < binary.length; i++) {
        array.push(binary.charCodeAt(i));
    }
    return new Uint8Array(array);
}
/** Function to load a PDF file using the input=file API */
document.querySelector("#opendoc").addEventListener("change", function(e) {
    let file = e.target;
    let reader = new FileReader();
    reader.onload = async function() {
        await pdfViewer.loadDocument({data: dataURItoBinArray(reader.result.replace(/^data:.*;base64,/,""))});
        await pdfThumbnails.loadDocument({data: dataURItoBinArray(reader.result.replace(/^data:.*;base64,/,""))}).then(() => pdfThumbnails.setZoom("fit"));
    }
    if (file.files.length > 0) {
        reader.readAsDataURL(file.files[0]);
        document.querySelector('#filedownload').download = document.querySelector('#opendoc').files[0].name;
    }
});
/** Sets the document in horizontal scroll by changing the class for the pages container and refreshing the document
 *    so that the pages may be displayed in horizontal scroll if they were not visible before */
function setHorizontal() {
    document.querySelector(".maindoc").classList.add("horizontal-scroll");
    pdfViewer.refreshAll();
}
/** Toggles the visibility of the thumbnails */
function togglethumbs(el) {
    if (el.classList.contains('pushed')) {
        el.classList.remove('pushed');
        document.querySelector('.thumbnails').classList.add('hide');
    } else {
        el.classList.add('pushed');
        document.querySelector('.thumbnails').classList.remove('hide');
    }
}
/** Now create the PDFjsViewer object in the DIV */
let pdfViewer = new PDFjsViewer($('.maindoc'), {
    zoomValues: [ 0.5, 0.75, 1, 1.25, 1.5, 2, 3, 4 ],

    /** Update the zoom value in the toolbar */
    onZoomChange: function(zoom) {
        zoom = parseInt(zoom * 10000) / 100;
        $('.zoomval').text(zoom + '%');
    },

    /** Update the active page */
    onActivePageChanged: function(page) {
        let pageno = $(page).data('page');
        let pagetotal = this.getPageCount();

        pdfThumbnails.setActivePage(pageno);
        $('#pageno').val(pageno);
        $('#pageno').attr('max', pagetotal);
        $('#pagecount').text('de ' + pagetotal);
    },

    /** zoom to fit when the document is loaded and create the object if wanted to be downloaded */
    onDocumentReady: function () {
        pdfViewer.setZoom('fit');
        pdfViewer.pdf.getData().then(function(data) {
            document.querySelector('#filedownload').href = URL.createObjectURL(new Blob([data], {type: 'application/pdf'}));
            document.querySelector('#filedownload').target = '_blank';
        });
    }
});

/** Load the initial PDF file */
pdfViewer.loadDocument(PDFFILE).then(function() {
    document.querySelector('#filedownload').download = PDFFILE;
});

/** Create the thumbnails */
let pdfThumbnails = new PDFjsViewer($('.thumbnails'), {
    zoomFillArea: 0.7,
    onNewPage: function(page) {
        page.on('click', function() {
            if (!pdfViewer.isPageVisible(page.data('page'))) {
                pdfViewer.scrollToPage(page.data('page'));
            }
        })
    },
    onDocumentReady: function() {
        this.setZoom('fit');
    }
});

pdfThumbnails.setActivePage = function(pageno) {
    this.$container.find('.pdfpage').removeClass('selected');
    let $npage = this.$container.find('.pdfpage[data-page="' + pageno + '"]').addClass('selected');
    if (!this.isPageVisible(pageno)) {
        this.scrollToPage(pageno);
    }
}.bind(pdfThumbnails);

pdfThumbnails.loadDocument(PDFFILE);
</script>
<?php /**PATH C:\xampp2023\htdocs\intralivre\resources\views/documentos/iframe.blade.php ENDPATH**/ ?>