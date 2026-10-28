(() => {
    const roots = Array.from(document.querySelectorAll('[data-culture-network]'));
    if (!roots.length) return;

    const CULTURE_COLORS = ['#5b4cdb', '#0f766e', '#b45309', '#be185d', '#1d4ed8', '#047857', '#9a3412', '#6d28d9'];
    const TYPE_META = {
        event: { label: 'Culture Day', size: 16, color: '#f59e0b' },
        culture: { label: 'Culture', size: 10, color: '#5b4cdb' },
        group: { label: 'Group', size: 6.5, color: '#2563eb' },
        'student-male': { label: 'Male', size: 3.4, color: '#3b82f6' },
        'student-female': { label: 'Female', size: 3.4, color: '#db2777' },
        'student-unknown': { label: 'Student', size: 3.2, color: '#94a3b8' },
    };

    const loadScript = (src, marker) => new Promise((resolve, reject) => {
        if (document.querySelector('script[data-culture-lib="' + marker + '"]')) {
            resolve();
            return;
        }
        const script = document.createElement('script');
        script.src = src;
        script.async = false;
        script.dataset.cultureLib = marker;
        script.onload = () => resolve();
        script.onerror = () => reject(new Error('Could not load ' + marker));
        document.head.appendChild(script);
    });

    const loadGraphLibs = async () => {
        if (typeof window.ForceGraph3D === 'function' && window.THREE && window.SpriteText) {
            return;
        }
        await loadScript('https://cdn.jsdelivr.net/npm/three@0.160.0/build/three.min.js', 'three');
        await loadScript('https://cdn.jsdelivr.net/npm/three-spritetext@1.8.2/dist/three-spritetext.min.js', 'spritetext');
        await loadScript('https://cdn.jsdelivr.net/npm/3d-force-graph@1.73.0/dist/3d-force-graph.min.js', 'fg3d');
    };

    const escapeHtml = (value) => String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');

    const truncate = (text, max) => {
        const value = String(text || '').trim();
        if (value.length <= max) return value;
        return value.slice(0, Math.max(1, max - 1)).trimEnd() + '…';
    };

    const shortLabel = (node, selected = false) => {
        const name = String(node.name || '').trim() || 'Untitled';
        if (node.type === 'event') return truncate(name, selected ? 28 : 18);
        if (node.type === 'culture' || node.type === 'group') return truncate(name, selected ? 22 : 16);
        const parts = name.split(/\s+/).filter(Boolean);
        if (selected) {
            if (parts.length <= 2) return truncate(name, 26);
            return truncate(parts[0] + ' ' + parts[parts.length - 1], 26);
        }
        if (parts.length === 1) return truncate(parts[0], 12);
        return truncate(parts[0] + ' ' + parts[parts.length - 1].charAt(0) + '.', 14);
    };

    const labelHeight = (node, selected) => {
        if (selected) {
            if (node.type === 'event') return 7.5;
            if (node.type === 'culture') return 5.8;
            if (node.type === 'group') return 4.6;
            return 3.8;
        }
        if (node.type === 'event') return 5.8;
        if (node.type === 'culture') return 4.4;
        if (node.type === 'group') return 3.5;
        return 2.7;
    };

    const labelOffsetY = (node, selected) => {
        const base = Math.cbrt(Math.max(node.val || 1, 1)) * 3.2;
        return base + (selected ? 3.2 : 1.8);
    };

    const buildDataset = (graph, {
        showStudents = true,
        query = '',
        cultureId = '',
        groupId = '',
        gender = 'all',
    } = {}) => {
        const nodes = [];
        const links = [];
        const q = String(query || '').trim().toLowerCase();
        const cultureFilter = cultureId === '' || cultureId == null ? '' : String(cultureId);
        const groupFilter = groupId === '' || groupId == null ? '' : String(groupId);
        const genderFilter = ['male', 'female'].includes(gender) ? gender : 'all';
        const event = graph.event || {};
        const eventId = 'event-' + (event.id || 'root');

        nodes.push({
            id: eventId,
            name: event.title || 'Culture Day',
            type: 'event',
            meta: (graph.student_count || 0) + ' students across all cultures',
            val: TYPE_META.event.size,
            color: TYPE_META.event.color,
        });

        (graph.cultures || []).forEach((culture, cultureIndex) => {
            if (cultureFilter !== '' && String(culture.id) !== cultureFilter) return;

            const cultureIdNode = 'culture-' + culture.id;
            const color = CULTURE_COLORS[cultureIndex % CULTURE_COLORS.length];
            const cultureHay = String(culture.name || '').toLowerCase();
            let cultureMatch = q === '' || cultureHay.includes(q);
            const groupNodes = [];

            (culture.groups || []).forEach((group) => {
                if (groupFilter !== '' && String(group.id) !== groupFilter) return;

                const groupIdNode = 'group-' + group.id;
                const groupHay = String(group.name || '').toLowerCase();
                let groupMatch = q === '' || groupHay.includes(q) || cultureMatch;
                const memberNodes = [];
                let matchingMemberCount = 0;

                (group.members || []).forEach((member) => {
                    const genderValue = member.gender === 'female'
                        ? 'female'
                        : (member.gender === 'male' ? 'male' : 'unknown');
                    if (genderFilter !== 'all' && genderValue !== genderFilter) return;

                    const memberHay = ((member.name || '') + ' ' + (member.code || '')).toLowerCase();
                    const memberMatch = q === '' || memberHay.includes(q) || groupMatch;
                    if (!memberMatch && q !== '') return;
                    matchingMemberCount += 1;
                    if (memberMatch && q !== '') {
                        groupMatch = true;
                        cultureMatch = true;
                    }
                    if (!showStudents) return;

                    const type = 'student-' + genderValue;
                    const memberId = 'student-' + group.id + '-' + member.id;
                    memberNodes.push({
                        id: memberId,
                        name: member.name || 'Student',
                        type,
                        code: member.code || '',
                        gender: genderValue,
                        groupName: group.name || 'Group',
                        cultureName: culture.name || 'Culture',
                        meta: (group.name || 'Group') + (member.code ? ' · ' + member.code : ''),
                        val: TYPE_META[type].size,
                        color: TYPE_META[type].color,
                    });
                    links.push({
                        source: groupIdNode,
                        target: memberId,
                        kind: genderValue === 'unknown' ? 'member' : genderValue,
                    });
                });

                const hasFilterPressure = q !== '' || genderFilter !== 'all' || groupFilter !== '';
                if (!groupMatch && memberNodes.length === 0 && matchingMemberCount === 0 && hasFilterPressure) {
                    return;
                }
                if ((groupMatch || matchingMemberCount > 0) && q !== '') cultureMatch = true;

                groupNodes.push({
                    id: groupIdNode,
                    name: group.name || 'Group',
                    type: 'group',
                    cultureName: culture.name || 'Culture',
                    meta: (matchingMemberCount || group.member_count || memberNodes.length)
                        + ' members · ' + (culture.name || 'Culture'),
                    val: TYPE_META.group.size,
                    color: TYPE_META.group.color,
                    _members: memberNodes,
                });
                links.push({ source: cultureIdNode, target: groupIdNode, kind: 'group' });
                memberNodes.forEach((n) => nodes.push(n));
            });

            if (!cultureMatch && groupNodes.length === 0 && (q !== '' || genderFilter !== 'all' || groupFilter !== '')) {
                return;
            }
            if (groupNodes.length === 0 && (cultureFilter !== '' || groupFilter !== '' || genderFilter !== 'all')) {
                // Keep selected culture visible even if empty after gender filter.
                if (cultureFilter === '' && groupFilter === '') return;
            }

            nodes.push({
                id: cultureIdNode,
                name: culture.name || 'Culture',
                type: 'culture',
                meta: (culture.student_count || 0) + ' students',
                val: TYPE_META.culture.size,
                color,
            });
            links.push({ source: eventId, target: cultureIdNode, kind: 'culture' });
            groupNodes.forEach((g) => {
                const { _members, ...node } = g;
                nodes.push(node);
            });
        });

        return { nodes, links };
    };

    const focusHtml = (node) => {
        if (!node) {
            return '<p class="muted">Click a sphere to select it. Labels sit above each node — drag to orbit · scroll to zoom.</p>';
        }
        const kind = TYPE_META[node.type]?.label || 'Node';
        const lines = [];
        if (node.code) lines.push('<span>ID ' + escapeHtml(node.code) + '</span>');
        if (node.cultureName) lines.push('<span>' + escapeHtml(node.cultureName) + '</span>');
        if (node.groupName && String(node.type).startsWith('student')) {
            lines.push('<span>' + escapeHtml(node.groupName) + '</span>');
        }
        if (node.meta && !String(node.type).startsWith('student') && !node.code) {
            lines.push('<span>' + escapeHtml(node.meta) + '</span>');
        }
        return (
            '<p class="culture-network-focus-kind">' + escapeHtml(kind) + '</p>' +
            '<strong>' + escapeHtml(node.name) + '</strong>' +
            (lines.length ? '<div class="culture-network-focus-meta">' + lines.join('') + '</div>' : '')
        );
    };

    const mount = async (root) => {
        let graph = {};
        try {
            graph = JSON.parse(root.getAttribute('data-graph') || '{}');
        } catch (e) {
            graph = {};
        }

        const canvas = root.querySelector('[data-culture-canvas]');
        const searchInput = root.querySelector('[data-culture-network-search]');
        const studentsToggle = root.querySelector('[data-culture-toggle-students]');
        const fitBtn = root.querySelector('[data-culture-fit]');
        const fullscreenBtn = root.querySelector('[data-culture-fullscreen]');
        const fullscreenExitBtn = root.querySelector('[data-culture-fullscreen-exit]');
        const status = root.querySelector('[data-culture-network-status]');
        const focusBox = root.querySelector('[data-culture-network-focus]');
        if (!canvas) return;

        if (status) status.textContent = 'Loading 3D graph…';

        try {
            await loadGraphLibs();
        } catch (e) {
            if (status) status.textContent = 'Could not load the 3D graph library. Check your connection.';
            return;
        }

        if (typeof window.ForceGraph3D !== 'function' || !window.THREE || !window.SpriteText) {
            if (status) status.textContent = '3D graph library unavailable.';
            return;
        }

        const THREE = window.THREE;
        const SpriteText = window.SpriteText;

        let showStudents = true;
        let showLabels = true;
        let genderFilter = 'all';
        let fg = null;
        let selectedId = null;
        let cameraReady = false;
        let cssFullscreen = false;

        const cultureSelect = root.querySelector('[data-culture-filter-culture]');
        const groupSelect = root.querySelector('[data-culture-filter-group]');
        const genderWrap = root.querySelector('[data-culture-filter-gender]');
        const labelsToggle = root.querySelector('[data-culture-toggle-labels]');
        const clearFiltersBtn = root.querySelector('[data-culture-clear-filters]');

        const refillGroupOptions = () => {
            if (!groupSelect) return;
            const cultures = graph.cultures || [];
            const selectedCulture = cultureSelect?.value || '';
            const groups = [];
            cultures.forEach((c) => {
                if (selectedCulture && String(c.id) !== selectedCulture) return;
                (c.groups || []).forEach((g) => {
                    groups.push({
                        id: g.id,
                        name: (g.name || 'Group') + (selectedCulture ? '' : ' · ' + (c.name || '')),
                    });
                });
            });
            const prev = groupSelect.value || '';
            groupSelect.innerHTML = '<option value="">All groups</option>' + groups.map((g) => (
                '<option value="' + String(g.id) + '">' + escapeHtml(g.name) + '</option>'
            )).join('');
            groupSelect.value = (prev && groups.some((g) => String(g.id) === prev)) ? prev : '';
        };

        if (cultureSelect) {
            const cultures = graph.cultures || [];
            cultureSelect.innerHTML = '<option value="">All cultures</option>' + cultures.map((c) => (
                '<option value="' + String(c.id) + '">' + escapeHtml(c.name || 'Culture') + '</option>'
            )).join('');
            cultureSelect.addEventListener('change', () => {
                refillGroupOptions();
                setFocus(null);
                redraw();
            });
        }
        if (groupSelect) {
            refillGroupOptions();
            groupSelect.addEventListener('change', () => {
                setFocus(null);
                redraw();
            });
        }

        const resizeGraph = () => {
            if (!fg) return;
            fg.width(canvas.clientWidth);
            fg.height(canvas.clientHeight);
        };

        const isFullscreen = () => (
            cssFullscreen
            || document.fullscreenElement === root
            || document.webkitFullscreenElement === root
        );

        const syncFullscreenUi = () => {
            const on = isFullscreen();
            root.classList.toggle('is-fullscreen', on);
            document.body.classList.toggle('culture-network-fs-lock', on);
            if (fullscreenBtn) {
                fullscreenBtn.textContent = on ? 'Exit full screen' : 'Full screen';
                fullscreenBtn.setAttribute('aria-pressed', on ? 'true' : 'false');
                fullscreenBtn.classList.toggle('is-active', on);
            }
            if (fullscreenExitBtn) {
                fullscreenExitBtn.hidden = !on;
            }
            window.requestAnimationFrame(() => {
                resizeGraph();
                window.setTimeout(resizeGraph, 80);
            });
        };

        const enterFullscreen = async () => {
            try {
                if (root.requestFullscreen) {
                    await root.requestFullscreen();
                } else if (root.webkitRequestFullscreen) {
                    root.webkitRequestFullscreen();
                } else {
                    cssFullscreen = true;
                }
            } catch (e) {
                cssFullscreen = true;
            }
            syncFullscreenUi();
        };

        const exitFullscreen = async () => {
            cssFullscreen = false;
            try {
                if (document.fullscreenElement) {
                    await document.exitFullscreen();
                } else if (document.webkitFullscreenElement && document.webkitExitFullscreen) {
                    document.webkitExitFullscreen();
                }
            } catch (e) {
                // CSS fallback still cleared above.
            }
            syncFullscreenUi();
        };

        const toggleFullscreen = () => {
            if (isFullscreen()) {
                exitFullscreen();
            } else {
                enterFullscreen();
            }
        };

        const setFocus = (node) => {
            selectedId = node ? node.id : null;
            root.classList.toggle('is-selecting', !!node);
            if (focusBox) {
                focusBox.innerHTML = focusHtml(node);
                focusBox.classList.toggle('is-active', !!node);
            }
        };
        setFocus(null);

        const linkColor = (link) => {
            if (link.kind === 'male') return 'rgba(59,130,246,0.5)';
            if (link.kind === 'female') return 'rgba(219,39,119,0.5)';
            if (link.kind === 'group') return 'rgba(37,99,235,0.4)';
            if (link.kind === 'culture') return 'rgba(91,76,219,0.45)';
            return 'rgba(148,163,184,0.4)';
        };

        const cameraDistance = (nodeCount) => Math.max(200, Math.min(560, 100 + Math.sqrt(Math.max(nodeCount, 1)) * 30));

        const fitCamera = (ms = 700) => {
            if (!fg) return;
            const count = (fg.graphData().nodes || []).length;
            fg.cameraPosition(
                { x: 0, y: count > 30 ? 48 : 28, z: cameraDistance(count) },
                { x: 0, y: 0, z: 0 },
                ms
            );
            cameraReady = true;
        };

        const mergePositions = (nextNodes) => {
            if (!fg) return nextNodes;
            const prev = new Map();
            (fg.graphData().nodes || []).forEach((n) => {
                if (n && n.id != null && Number.isFinite(n.x)) {
                    prev.set(n.id, { x: n.x, y: n.y, z: n.z, vx: n.vx, vy: n.vy, vz: n.vz });
                }
            });
            if (!prev.size) return nextNodes;
            return nextNodes.map((node) => {
                const keep = prev.get(node.id);
                return keep ? Object.assign({}, node, keep) : node;
            });
        };

        const makeNodeObject = (node) => {
            const selected = node.id === selectedId;
            const group = new THREE.Group();

            if (selected) {
                const radius = Math.cbrt(Math.max(node.val || 1, 1)) * 4.2;
                const glow = new THREE.Mesh(
                    new THREE.SphereGeometry(radius * 1.55, 28, 28),
                    new THREE.MeshBasicMaterial({
                        color: 0x111111,
                        transparent: true,
                        opacity: 0.14,
                        depthWrite: false,
                    })
                );
                group.add(glow);

                const ring = new THREE.Mesh(
                    new THREE.TorusGeometry(radius * 1.35, radius * 0.12, 12, 48),
                    new THREE.MeshBasicMaterial({
                        color: 0x1c1917,
                        transparent: true,
                        opacity: 0.88,
                    })
                );
                ring.rotation.x = Math.PI / 2;
                group.add(ring);

                const halo = new THREE.Mesh(
                    new THREE.SphereGeometry(radius * 1.15, 24, 24),
                    new THREE.MeshBasicMaterial({
                        color: node.color || '#f59e0b',
                        transparent: true,
                        opacity: 0.28,
                        depthWrite: false,
                    })
                );
                group.add(halo);
            }

            if (showLabels || selected) {
                const sprite = new SpriteText(shortLabel(node, selected));
                sprite.color = selected ? '#0c0a09' : '#1c1917';
                sprite.backgroundColor = selected ? 'rgba(255,255,255,0.98)' : 'rgba(255,255,255,0.9)';
                sprite.padding = selected ? 3.1 : 2.4;
                sprite.borderRadius = 6;
                sprite.borderWidth = selected ? 2.2 : 0.55;
                sprite.borderColor = selected ? '#111111' : 'rgba(28,25,23,0.18)';
                sprite.strokeWidth = selected ? 0.35 : 0.2;
                sprite.strokeColor = selected ? 'rgba(255,255,255,0.9)' : 'rgba(255,255,255,0.7)';
                sprite.fontFace = 'system-ui, "Segoe UI", sans-serif';
                sprite.fontWeight = selected ? '700' : '650';
                sprite.fontSize = 56;
                sprite.textHeight = labelHeight(node, selected);
                sprite.center.y = 0;
                sprite.position.y = labelOffsetY(node, selected);
                sprite.renderOrder = selected ? 20 : 10;
                group.add(sprite);
            }

            return group;
        };

        const refreshObjects = () => {
            if (!fg) return;
            fg.nodeVal((node) => (node.id === selectedId ? (node.val || 1) * 1.55 : (node.val || 1)));
            fg.nodeColor((node) => (node.id === selectedId ? '#111111' : node.color));
            fg.nodeThreeObject(makeNodeObject);
            if (typeof fg.refresh === 'function') {
                fg.refresh();
            } else {
                const data = fg.graphData();
                fg.graphData({ nodes: data.nodes, links: data.links });
            }
        };

        const selectNode = (node) => {
            setFocus(node || null);
            refreshObjects();
            if (!node || !fg) return;
            const distance = Math.max(56, (node.val || 4) * 11);
            const cam = fg.camera();
            const view = cam.position.clone().sub(fg.scene().position).normalize();
            fg.cameraPosition(
                {
                    x: node.x + view.x * distance,
                    y: node.y + view.y * distance * 0.7 + 8,
                    z: node.z + view.z * distance,
                },
                node,
                850
            );
        };

        const currentFilters = () => ({
            showStudents,
            query: searchInput?.value || '',
            cultureId: cultureSelect?.value || '',
            groupId: groupSelect?.value || '',
            gender: genderFilter,
        });

        const redraw = ({ resetCamera = false } = {}) => {
            const data = buildDataset(graph, currentFilters());
            if (selectedId && !data.nodes.some((n) => n.id === selectedId)) {
                setFocus(null);
            }

            const isFirst = !fg;
            if (!fg) {
                canvas.innerHTML = '';
                fg = ForceGraph3D()(canvas)
                    .backgroundColor('rgba(0,0,0,0)')
                    .showNavInfo(false)
                    .nodeId('id')
                    .nodeLabel(() => null)
                    .nodeVal((node) => (node.id === selectedId ? (node.val || 1) * 1.55 : (node.val || 1)))
                    .nodeColor((node) => (node.id === selectedId ? '#111111' : node.color))
                    .nodeOpacity(0.97)
                    .nodeResolution(28)
                    .nodeThreeObjectExtend(true)
                    .nodeThreeObject(makeNodeObject)
                    .linkColor(linkColor)
                    .linkWidth((link) => {
                        const hot = (link.source?.id || link.source) === selectedId
                            || (link.target?.id || link.target) === selectedId;
                        if (hot) return 2.2;
                        return (link.kind === 'culture' || link.kind === 'group') ? 1.4 : 0.75;
                    })
                    .linkOpacity(0.9)
                    .linkDirectionalParticles((link) => {
                        const hot = (link.source?.id || link.source) === selectedId
                            || (link.target?.id || link.target) === selectedId;
                        if (hot) return 4;
                        return (link.kind === 'culture' || link.kind === 'group') ? 2 : 0;
                    })
                    .linkDirectionalParticleWidth(1.6)
                    .linkDirectionalParticleSpeed(0.005)
                    .linkDirectionalParticleColor((link) => linkColor(link))
                    .cooldownTicks(100)
                    .d3AlphaDecay(0.026)
                    .d3VelocityDecay(0.3)
                    .onNodeClick((node) => selectNode(node))
                    .onBackgroundClick(() => {
                        setFocus(null);
                        refreshObjects();
                    });

                const controls = fg.controls();
                if (controls) {
                    controls.enableDamping = true;
                    controls.dampingFactor = 0.08;
                    controls.rotateSpeed = 0.55;
                    // Never auto-move the camera after the user orbits/zooms/pans.
                    controls.autoRotate = false;
                }

                try {
                    const scene = fg.scene();
                    if (scene) {
                        scene.fog = new THREE.FogExp2(0xf4f1ec, 0.00155);
                    }
                } catch (e) {
                    // optional
                }

                resizeGraph();
                window.addEventListener('resize', resizeGraph);
                if (window.ResizeObserver) {
                    new ResizeObserver(resizeGraph).observe(canvas);
                }
            }

            // Keep size/color accessors current for selection state.
            fg.nodeVal((node) => (node.id === selectedId ? (node.val || 1) * 1.55 : (node.val || 1)));
            fg.nodeColor((node) => (node.id === selectedId ? '#111111' : node.color));
            fg.nodeThreeObject(makeNodeObject);
            fg.graphData({
                nodes: isFirst ? data.nodes : mergePositions(data.nodes),
                links: data.links,
            });

            if (isFirst || resetCamera || !cameraReady) {
                window.setTimeout(() => fitCamera(900), 120);
            }

            if (status) {
                const filters = currentFilters();
                const bits = [];
                if (filters.cultureId) bits.push('culture');
                if (filters.groupId) bits.push('group');
                if (filters.gender !== 'all') bits.push(filters.gender);
                if (filters.query) bits.push('search');
                if (!showStudents) bits.push('students hidden');
                if (!showLabels) bits.push('labels hidden');
                status.textContent = data.nodes.length
                    ? data.nodes.length + ' nodes'
                        + (bits.length ? ' · filtered: ' + bits.join(', ') : '')
                        + ' · click to select · drag to orbit'
                    : 'No matches for those filters.';
            }
        };

        let timer = null;
        searchInput?.addEventListener('input', () => {
            window.clearTimeout(timer);
            timer = window.setTimeout(() => redraw(), 160);
        });
        searchInput?.addEventListener('keydown', (event) => {
            if (event.key === 'Enter') {
                event.preventDefault();
                redraw();
            }
        });
        studentsToggle?.addEventListener('click', () => {
            showStudents = !showStudents;
            studentsToggle.textContent = showStudents ? 'Hide students' : 'Show students';
            studentsToggle.classList.toggle('is-active', showStudents);
            setFocus(null);
            redraw();
        });
        labelsToggle?.addEventListener('click', () => {
            showLabels = !showLabels;
            labelsToggle.textContent = showLabels ? 'Hide labels' : 'Show labels';
            labelsToggle.classList.toggle('is-active', showLabels);
            refreshObjects();
        });
        genderWrap?.querySelectorAll('[data-gender]').forEach((btn) => {
            btn.addEventListener('click', () => {
                genderFilter = btn.getAttribute('data-gender') || 'all';
                genderWrap.querySelectorAll('[data-gender]').forEach((el) => {
                    el.classList.toggle('is-active', el === btn);
                });
                setFocus(null);
                redraw();
            });
        });
        clearFiltersBtn?.addEventListener('click', () => {
            if (searchInput) searchInput.value = '';
            if (cultureSelect) cultureSelect.value = '';
            refillGroupOptions();
            if (groupSelect) groupSelect.value = '';
            genderFilter = 'all';
            genderWrap?.querySelectorAll('[data-gender]').forEach((el) => {
                el.classList.toggle('is-active', el.getAttribute('data-gender') === 'all');
            });
            showStudents = true;
            if (studentsToggle) {
                studentsToggle.textContent = 'Hide students';
                studentsToggle.classList.add('is-active');
            }
            showLabels = true;
            if (labelsToggle) {
                labelsToggle.textContent = 'Hide labels';
                labelsToggle.classList.add('is-active');
            }
            setFocus(null);
            redraw();
        });
        fitBtn?.addEventListener('click', () => {
            setFocus(null);
            refreshObjects();
            fitCamera(800);
        });

        fullscreenBtn?.addEventListener('click', (event) => {
            event.preventDefault();
            toggleFullscreen();
        });
        fullscreenExitBtn?.addEventListener('click', (event) => {
            event.preventDefault();
            exitFullscreen();
        });
        document.addEventListener('fullscreenchange', syncFullscreenUi);
        document.addEventListener('webkitfullscreenchange', syncFullscreenUi);
        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && cssFullscreen) {
                exitFullscreen();
            }
        });

        redraw({ resetCamera: true });
    };

    roots.forEach((root) => {
        mount(root).catch(() => {
            const status = root.querySelector('[data-culture-network-status]');
            if (status) status.textContent = 'Unable to draw the 3D graph.';
        });
    });
})();
