(function () {
    const nodeMap = document.getElementById('fbgNodeMap');
    const nodeTooltip = document.getElementById('fbgNodeMapTooltip');
    const nodeTooltipContent = document.getElementById('fbgNodeTooltipContent');

    if (!nodeMap || !nodeTooltip || !nodeTooltipContent) {
        return;
    }

    const markers = Array.from(nodeMap.querySelectorAll('.fbg-home-node-marker'));

    function escapeHtml(value) {
        return String(value ?? '').replace(/[&<>"']/g, function (char) {
            switch (char) {
                case '&': return '&amp;';
                case '<': return '&lt;';
                case '>': return '&gt;';
                case '"': return '&quot;';
                case '\'': return '&#039;';
                default: return char;
            }
        });
    }

    function getMarkerGroup(marker) {
        const group = (marker.dataset.nodeGroup || '').trim();

        if (group !== '') {
            return markers.filter(function (item) {
                return (item.dataset.nodeGroup || '').trim() === group;
            });
        }

        return [marker];
    }

    function positionNodeTooltip(marker) {
        const mapRect = nodeMap.getBoundingClientRect();
        const markerRect = marker.getBoundingClientRect();

        const tooltipX = markerRect.left - mapRect.left + 18;
        const tooltipY = markerRect.top - mapRect.top - 12;

        nodeTooltip.style.left = tooltipX + 'px';
        nodeTooltip.style.top = tooltipY + 'px';
    }

    function buildNodeTooltipHtml(groupMarkers) {
        if (!groupMarkers.length) return '';

        const location = groupMarkers[0].dataset.nodeLocation || 'Unknown Location';
        const count = groupMarkers.length;

        const header = `
            <div class="fbg-home-node-tooltip-header">
                <strong>${escapeHtml(location)}</strong>
                <span>${count} Node${count > 1 ? 's' : ''}</span>
            </div>
        `;

        const entries = groupMarkers.map(function (marker) {
            const name = marker.dataset.nodeName || 'Unknown Node';
            const status = marker.dataset.nodeStatus || 'Unknown';
            const specs = marker.dataset.nodeSpecs || '';
            const markerClass = marker.classList.contains('is-planned') ? 'is-planned' : 'is-online';

            return [
                '<div class="fbg-home-node-tooltip-entry">',
                '   <div class="fbg-home-node-tooltip-topline">',
                '       <span class="fbg-home-node-tooltip-status-dot ' + markerClass + '"></span>',
                '       <strong>' + escapeHtml(name) + '</strong>',
                '   </div>',
                '   <span>Status: <span class="fbg-node-status ' + markerClass + '">' + escapeHtml(status) + '</span></span>',
                specs ? '   <small>' + escapeHtml(specs) + '</small>' : '',
                '</div>'
            ].join('');
        }).join('');

        return header + entries;
    }

    function showNodeTooltip(marker) {
        const groupMarkers = getMarkerGroup(marker);

        nodeTooltipContent.innerHTML = buildNodeTooltipHtml(groupMarkers);
        positionNodeTooltip(marker);

        nodeMap.classList.add('is-hovering');

        const rect = nodeMap.getBoundingClientRect();
        const markerRect = marker.getBoundingClientRect();

        const x = ((markerRect.left - rect.left) / rect.width) * 100;
        const y = ((markerRect.top - rect.top) / rect.height) * 100;

        nodeMap.style.setProperty('--x', x + '%');
        nodeMap.style.setProperty('--y', y + '%');

        nodeTooltip.style.display = 'flex';
    }

    function hideNodeTooltip() {
        nodeTooltip.style.display = 'none';
        nodeMap.classList.remove('is-hovering');
    }

    markers.forEach(function (marker) {
        marker.addEventListener('mouseenter', function () {
            showNodeTooltip(marker);
        });

        marker.addEventListener('mouseleave', function () {
            hideNodeTooltip();
        });

        marker.addEventListener('focus', function () {
            showNodeTooltip(marker);
        });

        marker.addEventListener('blur', function () {
            hideNodeTooltip();
        });
    });

    window.addEventListener('resize', function () {
        hideNodeTooltip();
    });
})();