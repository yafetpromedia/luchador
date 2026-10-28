(() => {
    const roots = Array.from(document.querySelectorAll('[data-culture-network]'));
    if (!roots.length) return;

    const CULTURE_COLORS = ['#7c3aed', '#2563eb', '#059669', '#db2777', '#ea580c', '#0891b2', '#4f46e5', '#ca8a04'];

    const loadScript = (src) => new Promise((resolve, reject) => {
        if (document.querySelector('script[data-culture-vis="' + src + '"]')) {
            resolve();
            return;
        }
        const script = document.createElement('script');
        script.src = src;
        script.async = true;
        script.dataset.cultureVis = src;
        script.onload = () => resolve();
        script.onerror = () => reject(new Error('Could not load graph library.'));
        document.head.appendChild(script);
    });

    const loadCss = (href) => {
        if (document.querySelector('link[data-culture-vis="' + href + '"]')) return;
        const link = document.createElement('link');
        link.rel = 'stylesheet';
        link.href = href;
        link.dataset.cultureVis = href;
        document.head.appendChild(link);
    };

    const escapeHtml = (value) => String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');

    const buildDataset = (graph, { showStudents = true, query = '' } = {}) => {
        const nodes = [];
        const edges = [];
        const q = String(query || '').trim().toLowerCase();
        const event = graph.event || {};
        const eventId = 'event-' + (event.id || 'root');

        nodes.push({
            id: eventId,
            label: event.title || 'Culture Day',
            group: 'event',
            title: (event.title || 'Culture Day') + '\n' + (graph.student_count || 0) + ' students',
            level: 0,
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
                    const gender = member.gender === 'female' ? 'female' : (member.gender === 'male' ? 'male' : 'unknown');
                    const memberId = 'student-' + group.id + '-' + member.id;
                    memberNodes.push({
                        id: memberId,
                        label: member.name || 'Student',
                        group: 'student-' + gender,
                        title: (member.name || '') + (member.code ? '\n' + member.code : '') + '\n' + gender,
                        level: 3,
                    });
                    edges.push({
                        from: groupId,
                        to: memberId,
                        label: gender === 'unknown' ? '' : gender,
                        dashes: gender === 'unknown',
                    });
                });

                if (!groupMatch && memberNodes.length === 0 && q !== '') return;
                if (groupMatch && q !== '') cultureMatch = true;

                groupNodes.push({
                    id: groupId,
                    label: group.name || 'Group',
                    group: 'group',
                    title: (group.name || 'Group') + '\n' + (group.member_count || memberNodes.length) + ' members',
                    level: 2,
                    _members: memberNodes,
                });
                edges.push({ from: cultureId, to: groupId, label: 'group' });
                memberNodes.forEach((n) => nodes.push(n));
            });

            if (!cultureMatch && groupNodes.length === 0 && q !== '') return;

            nodes.push({
                id: cultureId,
                label: culture.name || 'Culture',
                group: 'culture',
                color: {
                    background: color,
                    border: color,
                    highlight: { background: color, border: '#111' },
                },
                font: { color: '#fff', face: 'Inter, system-ui, sans-serif', size: 14, bold: true },
                title: (culture.name || 'Culture') + '\n' + (culture.student_count || 0) + ' students',
                level: 1,
            });
            edges.push({ from: eventId, to: cultureId, label: 'culture' });
            groupNodes.forEach((g) => {
                const { _members, ...node } = g;
                nodes.push(node);
            });
        });

        return { nodes, edges };
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
        if (!canvas) return;

        if (status) status.textContent = 'Loading graph…';

        try {
            loadCss('https://unpkg.com/vis-network@9.1.9/styles/vis-network.min.css');
            await loadScript('https://unpkg.com/vis-network@9.1.9/standalone/umd/vis-network.min.js');
        } catch (e) {
            if (status) status.textContent = 'Could not load the graph library. Check your connection.';
            return;
        }

        if (!window.vis) {
            if (status) status.textContent = 'Graph library unavailable.';
            return;
        }

        let showStudents = true;
        let network = null;

        const options = {
            autoResize: true,
            interaction: {
                hover: true,
                tooltipDelay: 120,
                navigationButtons: true,
                keyboard: false,
                zoomView: true,
            },
            physics: {
                enabled: true,
                solver: 'forceAtlas2Based',
                forceAtlas2Based: {
                    gravitationalConstant: -42,
                    centralGravity: 0.012,
                    springLength: 110,
                    springConstant: 0.08,
                    avoidOverlap: 0.7,
                },
                stabilization: { iterations: 140, fit: true },
            },
            nodes: {
                shape: 'dot',
                borderWidth: 2,
                shadow: { enabled: true, size: 10, x: 0, y: 3, color: 'rgba(17,17,17,0.12)' },
                font: { face: 'Inter, system-ui, sans-serif', size: 13, color: '#1f1f1f' },
            },
            edges: {
                color: { color: '#c9c4bb', highlight: '#111', hover: '#555' },
                width: 1.2,
                smooth: { type: 'dynamic' },
                font: { size: 10, color: '#8a857c', strokeWidth: 0, align: 'middle' },
                arrows: { to: { enabled: true, scaleFactor: 0.55 } },
            },
            groups: {
                event: {
                    shape: 'dot',
                    size: 28,
                    color: { background: '#f59e0b', border: '#b45309' },
                    font: { size: 16, bold: true },
                },
                culture: {
                    shape: 'dot',
                    size: 22,
                },
                group: {
                    shape: 'dot',
                    size: 16,
                    color: { background: '#eff6ff', border: '#2563eb' },
                },
                'student-male': {
                    shape: 'dot',
                    size: 10,
                    color: { background: '#dbeafe', border: '#1d4ed8' },
                },
                'student-female': {
                    shape: 'dot',
                    size: 10,
                    color: { background: '#fce7f3', border: '#be185d' },
                },
                'student-unknown': {
                    shape: 'dot',
                    size: 10,
                    color: { background: '#f3f4f6', border: '#9ca3af' },
                },
            },
        };

        const redraw = () => {
            const query = searchInput?.value || '';
            const data = buildDataset(graph, { showStudents, query });
            const nodes = new vis.DataSet(data.nodes);
            const edges = new vis.DataSet(data.edges);
            if (!network) {
                network = new vis.Network(canvas, { nodes, edges }, options);
                network.once('stabilizationIterationsDone', () => {
                    network.setOptions({ physics: false });
                    network.fit({ animation: true });
                });
            } else {
                network.setData({ nodes, edges });
                network.setOptions({ physics: true });
                network.once('stabilizationIterationsDone', () => {
                    network.setOptions({ physics: false });
                });
            }
            if (status) {
                status.textContent = data.nodes.length
                    ? data.nodes.length + ' nodes · drag to explore · scroll to zoom'
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
            redraw();
        });
        fitBtn?.addEventListener('click', () => {
            network?.fit({ animation: true });
        });

        redraw();
    };

    roots.forEach((root) => {
        mount(root).catch(() => {
            const status = root.querySelector('[data-culture-network-status]');
            if (status) status.textContent = 'Unable to draw the graph.';
        });
    });
})();
