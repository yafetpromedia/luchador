(() => {
    const roots = Array.from(document.querySelectorAll('[data-culture-network]'));
    if (!roots.length) return;

    const CULTURE_COLORS = ['#5b4cdb', '#0f766e', '#b45309', '#be185d', '#1d4ed8', '#047857', '#9a3412', '#6d28d9'];
    const TYPE_META = {
        event: { label: 'Culture Day', size: 14, color: '#f59e0b' },
        culture: { label: 'Culture', size: 9, color: '#5b4cdb' },
        group: { label: 'Group', size: 5.5, color: '#2563eb' },
        'student-male': { label: 'Male', size: 2.6, color: '#3b82f6' },
        'student-female': { label: 'Female', size: 2.6, color: '#db2777' },
        'student-unknown': { label: 'Student', size: 2.4, color: '#94a3b8' },
    };

    const loadScript = (src) => new Promise((resolve, reject) => {
        if (window.ForceGraph3D) {
            resolve();
            return;
        }
        const existing = document.querySelector('script[data-culture-fg3d]');
        if (existing) {
            existing.addEventListener('load', () => resolve());
            existing.addEventListener('error', () => reject(new Error('Could not load 3D graph library.')));
            return;
        }
        const script = document.createElement('script');
        script.src = src;
        script.async = true;
        script.dataset.cultureFg3d = '1';
        script.onload = () => resolve();
        script.onerror = () => reject(new Error('Could not load 3D graph library.'));
        document.head.appendChild(script);
    });

    const escapeHtml = (value) => String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');

    const buildDataset = (graph, { showStudents = true, query = '' } = {}) => {
        const nodes = [];
        const links = [];
        const q = String(query || '').trim().toLowerCase();
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
            const cultureId = 'culture-' + culture.id;
            const color = CULTURE_COLORS[cultureIndex % CULTURE_COLORS.length];
            const cultureHay = String(culture.name || '').toLowerCase();
            let cultureMatch = q === '' || cultureHay.includes(q);
            const groupNodes = [];

            (culture.groups || []).forEach((group) => {
                const groupId = 'group-' + group.id;
                const groupHay = String(group.name || '').toLowerCase();
                let groupMatch = q === '' || groupHay.includes(q) || cultureMatch;
                const memberNodes = [];

                (group.members || []).forEach((member) => {
                    const memberHay = ((member.name || '') + ' ' + (member.code || '')).toLowerCase();
                    const memberMatch = q === '' || memberHay.includes(q) || groupMatch;
                    if (!memberMatch && q !== '') return;
                    if (memberMatch && q !== '') {
                        groupMatch = true;
                        cultureMatch = true;
                    }
                    if (!showStudents) return;
                    const gender = member.gender === 'female'
                        ? 'female'
                        : (member.gender === 'male' ? 'male' : 'unknown');
                    const type = 'student-' + gender;
                    const memberId = 'student-' + group.id + '-' + member.id;
                    memberNodes.push({
                        id: memberId,
                        name: member.name || 'Student',
                        type,
                        code: member.code || '',
                        gender,
                        groupName: group.name || 'Group',
                        cultureName: culture.name || 'Culture',
                        meta: [member.code || null, gender !== 'unknown' ? gender : null, group.name]
                            .filter(Boolean)
                            .join(' · '),
                        val: TYPE_META[type].size,
                        color: TYPE_META[type].color,
                    });
                    links.push({
                        source: groupId,
                        target: memberId,
                        kind: gender === 'unknown' ? 'member' : gender,
                    });
                });

                if (!groupMatch && memberNodes.length === 0 && q !== '') return;
                if (groupMatch && q !== '') cultureMatch = true;

                groupNodes.push({
                    id: groupId,
                    name: group.name || 'Group',
                    type: 'group',
                    cultureName: culture.name || 'Culture',
                    meta: (group.member_count || memberNodes.length) + ' members · ' + (culture.name || 'Culture'),
                    val: TYPE_META.group.size,
                    color: TYPE_META.group.color,
                    _members: memberNodes,
                });
                links.push({ source: cultureId, target: groupId, kind: 'group' });
                memberNodes.forEach((n) => nodes.push(n));
            });

            if (!cultureMatch && groupNodes.length === 0 && q !== '') return;

            nodes.push({
                id: cultureId,
                name: culture.name || 'Culture',
                type: 'culture',
                meta: (culture.student_count || 0) + ' students',
                val: TYPE_META.culture.size,
                color,
            });
            links.push({ source: eventId, target: cultureId, kind: 'culture' });
            groupNodes.forEach((g) => {
                const { _members, ...node } = g;
                nodes.push(node);
            });
        });

        return { nodes, links };
    };

    const focusHtml = (node) => {
        if (!node) {
            return '<p class="muted">Click a sphere to inspect it. Drag to orbit · scroll to zoom · right-drag to pan.</p>';
        }
        const kind = TYPE_META[node.type]?.label || 'Node';
        const lines = [];
        if (node.code) lines.push('<span>ID ' + escapeHtml(node.code) + '</span>');
        if (node.cultureName) lines.push('<span>' + escapeHtml(node.cultureName) + '</span>');
        if (node.groupName && node.type.startsWith('student')) {
            lines.push('<span>' + escapeHtml(node.groupName) + '</span>');
        }
        if (node.meta && !node.code) lines.push('<span>' + escapeHtml(node.meta) + '</span>');
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
        const status = root.querySelector('[data-culture-network-status]');
        const focusBox = root.querySelector('[data-culture-network-focus]');
        if (!canvas) return;

        if (status) status.textContent = 'Loading 3D graph…';

        try {
            await loadScript('https://cdn.jsdelivr.net/npm/3d-force-graph@1.73.0/dist/3d-force-graph.min.js');
        } catch (e) {
            if (status) status.textContent = 'Could not load the 3D graph library. Check your connection.';
            return;
        }

        if (typeof window.ForceGraph3D !== 'function') {
            if (status) status.textContent = '3D graph library unavailable.';
            return;
        }

        let showStudents = true;
        let fg = null;
        let autoRotate = true;
        let rotateAngle = 0;
        let lastInteract = Date.now();

        const setFocus = (node) => {
            if (focusBox) focusBox.innerHTML = focusHtml(node);
        };
        setFocus(null);

        const linkColor = (link) => {
            if (link.kind === 'male') return 'rgba(59,130,246,0.45)';
            if (link.kind === 'female') return 'rgba(219,39,119,0.45)';
            if (link.kind === 'group') return 'rgba(37,99,235,0.35)';
            if (link.kind === 'culture') return 'rgba(91,76,219,0.4)';
            return 'rgba(148,163,184,0.35)';
        };

        const cameraDistance = (nodeCount) => Math.max(180, Math.min(520, 90 + Math.sqrt(Math.max(nodeCount, 1)) * 28));

        const fitCamera = (ms = 700) => {
            if (!fg) return;
            const count = (fg.graphData().nodes || []).length;
            fg.cameraPosition(
                { x: 0, y: count > 30 ? 40 : 20, z: cameraDistance(count) },
                { x: 0, y: 0, z: 0 },
                ms
            );
        };

        const redraw = () => {
            const query = searchInput?.value || '';
            const data = buildDataset(graph, { showStudents, query });

            if (!fg) {
                canvas.innerHTML = '';
                fg = ForceGraph3D()(canvas)
                    .backgroundColor('rgba(0,0,0,0)')
                    .showNavInfo(false)
                    .nodeId('id')
                    .nodeLabel((node) => {
                        const kind = TYPE_META[node.type]?.label || '';
                        return (node.name || '') + (kind ? ' · ' + kind : '') + (node.meta ? '\n' + node.meta : '');
                    })
                    .nodeVal('val')
                    .nodeColor('color')
                    .nodeOpacity(0.96)
                    .nodeResolution(24)
                    .linkColor(linkColor)
                    .linkWidth((link) => (link.kind === 'culture' || link.kind === 'group' ? 1.35 : 0.7))
                    .linkOpacity(0.85)
                    .linkDirectionalParticles((link) => (link.kind === 'culture' || link.kind === 'group' ? 2 : 0))
                    .linkDirectionalParticleWidth(1.4)
                    .linkDirectionalParticleSpeed(0.004)
                    .linkDirectionalParticleColor((link) => linkColor(link))
                    .cooldownTicks(90)
                    .d3AlphaDecay(0.028)
                    .d3VelocityDecay(0.32)
                    .onNodeClick((node) => {
                        autoRotate = false;
                        lastInteract = Date.now();
                        setFocus(node);
                        const distance = Math.max(48, (node.val || 4) * 12);
                        const cam = fg.camera();
                        const view = cam.position.clone().sub(fg.scene().position).normalize();
                        fg.cameraPosition(
                            {
                                x: node.x + view.x * distance,
                                y: node.y + view.y * distance,
                                z: node.z + view.z * distance,
                            },
                            node,
                            900
                        );
                    })
                    .onBackgroundClick(() => {
                        setFocus(null);
                        lastInteract = Date.now();
                    })
                    .onNodeDrag(() => {
                        autoRotate = false;
                        lastInteract = Date.now();
                    })
                    .onEngineStop(() => {
                        // Keep a gentle idle orbit after layout settles.
                        autoRotate = true;
                    });

                const controls = fg.controls();
                if (controls) {
                    controls.enableDamping = true;
                    controls.dampingFactor = 0.08;
                    controls.rotateSpeed = 0.55;
                    controls.addEventListener('start', () => {
                        autoRotate = false;
                        lastInteract = Date.now();
                    });
                    controls.addEventListener('end', () => {
                        lastInteract = Date.now();
                        window.setTimeout(() => {
                            if (Date.now() - lastInteract > 2400) autoRotate = true;
                        }, 2600);
                    });
                }

                // Soft lighting for clean spheres.
                try {
                    const THREE = window.THREE;
                    if (THREE && fg.lights) {
                        // ForceGraph3D manages lights; tweak scene fog for depth.
                    }
                    const scene = fg.scene();
                    if (scene && window.THREE) {
                        scene.fog = new window.THREE.FogExp2(0xf4f1ec, 0.0018);
                    }
                } catch (e) {
                    // Optional polish only.
                }

                const tick = () => {
                    if (fg && autoRotate && Date.now() - lastInteract > 2800) {
                        rotateAngle += 0.0018;
                        const dist = cameraDistance((fg.graphData().nodes || []).length);
                        fg.cameraPosition({
                            x: dist * Math.sin(rotateAngle),
                            y: 24,
                            z: dist * Math.cos(rotateAngle),
                        });
                    }
                    requestAnimationFrame(tick);
                };
                requestAnimationFrame(tick);

                const resize = () => {
                    if (!fg) return;
                    fg.width(canvas.clientWidth);
                    fg.height(canvas.clientHeight);
                };
                resize();
                window.addEventListener('resize', resize);
                if (window.ResizeObserver) {
                    new ResizeObserver(resize).observe(canvas);
                }
            }

            fg.graphData({ nodes: data.nodes, links: data.links });
            window.setTimeout(() => fitCamera(900), 120);

            if (status) {
                status.textContent = data.nodes.length
                    ? data.nodes.length + ' nodes · 3D · drag to orbit · scroll to zoom'
                    : 'No matches for that search.';
            }
        };

        let timer = null;
        searchInput?.addEventListener('input', () => {
            window.clearTimeout(timer);
            timer = window.setTimeout(redraw, 160);
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
        fitBtn?.addEventListener('click', () => {
            autoRotate = true;
            lastInteract = Date.now() - 3000;
            fitCamera(800);
            setFocus(null);
        });

        redraw();
    };

    roots.forEach((root) => {
        mount(root).catch(() => {
            const status = root.querySelector('[data-culture-network-status]');
            if (status) status.textContent = 'Unable to draw the 3D graph.';
        });
    });
})();
