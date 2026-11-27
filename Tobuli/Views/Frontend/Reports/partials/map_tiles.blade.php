<script>
    // Base map for report maps.
    // The downloaded HTML is opened from disk (file://), so the browser sends no Referer and
    // tile.openstreetmap.org answers with its "Access blocked" (403) tile. Use the same Google
    // raster tiles the live map uses ("Google Normal B" in MapTiles.js): no key or Referer needed.
    function reportTileLayer(map) {
        return new L.TileLayer('https://mt{s}.google.com/vt/lyrs=m&hl={{ Language::iso() }}&x={x}&y={y}&z={z}&gl={{ Language::iso() }}', {
            subdomains: '0123',
            maxZoom: 21,
            minZoom: 0
        }).addTo(map);
    }
</script>
