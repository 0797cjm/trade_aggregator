const STORAGE_KEY = 'bb_my_local';
const THEME_STORAGE_KEY = 'bb_visual_theme';
const DEFAULT_THEME = 'light';
window.INSULATORS_APP_VERSION = 'job-highlights-v1';
const MAP_TILE_LIGHT = {
  url: 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',
  attribution: '&copy; OpenStreetMap',
  maxZoom: 19
};
const MAP_TILE_DARK = {
  url: 'https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png',
  attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> &copy; <a href="https://carto.com/">CARTO</a>',
  maxZoom: 19
};
const SECTION_ORDER = {
  selectedLocal: 10,
  liveDispatch: 20,
  unionDirectory: 30,
  unionNews: 40,
  tradeNews: 50,
  publicJobs: 60
};
const JOBS_INITIAL_COUNT = 8;
const JOBS_EXPANDED_COUNT = 20;
const NEWS_FALLBACK = {
  unionNews: [
  {
    title: 'Official International Association Site Updates',
    source: 'Insulators International',
    url: 'https://www.insulators.org',
    category: 'union',
    excerpt: 'Official international union website and updates for members, locals, and industry activity.'
  },
  {
    title: 'Dispatch Update',
    source: 'Local 137',
    url: 'https://insulators137.org/news-details/news/dispatch/26316',
    category: 'union',
    excerpt: 'Local 137 dispatch and member-facing update page.'
  },
  {
    title: 'Subscribe to Dispatch Feed',
    source: 'Local 119',
    url: 'https://www.local119.com/subscribe-to-dispatch-feed',
    category: 'union',
    excerpt: 'Dispatch subscription page for Local 119 notices and updates.'
  },
  {
    title: 'Ontario Dispatch',
    source: 'Ontario Insulators',
    url: 'https://ontarioinsulators.com/dispatch/',
    category: 'union',
    excerpt: 'Ontario dispatch page for call and availability updates.'
  }
  ],
  tradeResources: [
  {
    title: 'News Stories',
    source: 'LNG Canada',
    url: 'https://www.lngcanada.ca/news/news-stories/',
    category: 'trade',
    excerpt: 'Official LNG Canada project news and updates from the Kitimat LNG development.'
  },
  {
    title: 'Energy',
    source: 'Government of Newfoundland and Labrador',
    url: 'https://www.gov.nl.ca/em/energy/',
    category: 'trade',
    excerpt: 'Provincial energy information and updates, including offshore and major energy development context.'
  },
  {
    title: 'Canada–Newfoundland and Labrador Offshore Energy Regulator',
    source: 'C-NLOER',
    url: 'https://www.cnloer.ca/',
    category: 'trade',
    excerpt: 'Official offshore regulator portal with project, safety, and operational information.'
  },
  {
    title: 'Market Snapshots',
    source: 'Canada Energy Regulator',
    url: 'https://www.cer-rec.gc.ca/en/data-analysis/energy-markets/market-snapshots/',
    category: 'trade',
    excerpt: 'Energy market snapshot coverage with timely summaries relevant to industrial project activity.'
  }
  ],
  tradeNews: []
};
let LOCALS = [];
let selMap = null;
let selMarker = null;
let selTileLayer = null;

async function init() {
  console.info('Insulators app version:', window.INSULATORS_APP_VERSION);
  applyTheme(loadThemePreference());
  try {
    const res = await fetch('locals.json');
    LOCALS = (await res.json()).locals;
  } catch (e) {
    document.querySelector('main').innerHTML = '<p style="padding:40px;color:#ef4444;">Could not load locals.json</p>';
    return;
  }

  applySectionOrder();
  renderList(LOCALS);
  renderNewsSections();
  loadNewsFromBackend();
  loadJobs();
  bindSearch();
  bindThemeToggle();
  bindMapDots();
  initProvincePicker();

  const saved = localStorage.getItem(STORAGE_KEY);
  if (saved && LOCALS.find(l => l.id === saved)) {
    selectLocal(saved);
  } else {
    showProvincePicker();
  }
}

function renderNewsSections() {
  renderNewsItems('union-news-list', NEWS_FALLBACK.unionNews, 'Coming soon.');
  renderNewsItems('trade-resources-list', NEWS_FALLBACK.tradeResources, 'Coming soon.');
  renderNewsStoryItems('trade-news-list', NEWS_FALLBACK.tradeNews, 'Stories unavailable right now.');
}

function renderNewsItems(elementId, items, emptyMessage) {
  const wrap = document.getElementById(elementId);
  if (!wrap) return;

  if (!items.length) {
    wrap.className = 'news-placeholder';
    wrap.textContent = emptyMessage || 'Coming soon.';
    return;
  }

  wrap.className = 'news-list';
  wrap.innerHTML = items.map(item => {
    const source = item.source ? '<span class="news-source">' + esc(item.source) + '</span>' : '';
    const category = item.category ? '<span class="news-category">' + esc(item.category) + '</span>' : '';
    const dateValue = item.publishedAt || item.date || '';
    const date = dateValue ? '<span class="news-date">' + esc(dateValue) + '</span>' : '';
    const meta = source || category || date
      ? '<div class="news-meta">' + source + category + date + '</div>'
      : '';
    const excerpt = item.excerpt ? '<p class="news-excerpt">' + esc(item.excerpt) + '</p>' : '';
    return '<a class="news-card" href="' + esc(item.url) + '" target="_blank" rel="noopener">' +
      '<div class="news-head">' +
        '<h3 class="news-title">' + esc(item.title) + '</h3>' +
        '<span class="news-external" aria-hidden="true">↗</span>' +
      '</div>' +
      meta +
      excerpt +
    '</a>';
  }).join('');
}

function renderNewsStoryItems(elementId, items, emptyMessage) {
  const wrap = document.getElementById(elementId);
  if (!wrap) return;

  if (!items.length) {
    wrap.className = 'news-placeholder';
    wrap.textContent = emptyMessage || 'Stories unavailable right now.';
    return;
  }

  wrap.className = 'news-list';
  wrap.innerHTML = items.map(item => {
    const source = item.source ? '<span class="news-source">' + esc(item.source) + '</span>' : '';
    const dateValue = item.publishedAt || item.date || '';
    const date = dateValue ? '<span class="news-date">' + esc(dateValue) + '</span>' : '';
    const meta = source || date ? '<div class="news-meta">' + source + date + '</div>' : '';
    const excerpt = item.excerpt ? '<p class="news-excerpt">' + esc(item.excerpt) + '</p>' : '';
    const hasImage = !!item.image;
    const cardClass = hasImage ? 'news-card news-story-card has-image' : 'news-card news-story-card';
    const image = hasImage ? '<img class="news-story-thumb" src="' + esc(item.image) + '" alt="" loading="lazy" referrerpolicy="no-referrer">' : '';
    return '<a class="' + cardClass + '" href="' + esc(item.url) + '" target="_blank" rel="noopener">' +
      image +
      '<div class="news-story-body">' +
        '<div class="news-head">' +
          '<h3 class="news-title">' + esc(item.title) + '</h3>' +
          '<span class="news-external" aria-hidden="true">↗</span>' +
        '</div>' +
        meta +
        excerpt +
      '</div>' +
    '</a>';
  }).join('');
}

async function loadNewsFromBackend() {
  try {
    const res = await fetch('news.php');
    const data = await res.json();
    if (!data || data.ok !== true) return;

    const unionItems = Array.isArray(data.unionNews) ? data.unionNews : NEWS_FALLBACK.unionNews;
    const tradeResources = Array.isArray(data.tradeResources) ? data.tradeResources : NEWS_FALLBACK.tradeResources;
    const tradeStories = Array.isArray(data.tradeNews) ? data.tradeNews : [];

    renderNewsItems('union-news-list', unionItems, 'Coming soon.');
    renderNewsItems('trade-resources-list', tradeResources, 'Coming soon.');
    renderNewsStoryItems('trade-news-list', tradeStories, 'Stories unavailable right now.');
  } catch (_) {
    // Keep static fallback cards rendered by renderNewsSections().
  }
}

function loadThemePreference() {
  const saved = localStorage.getItem(THEME_STORAGE_KEY);
  if (saved === 'light' || saved === 'dark') return saved;
  if (saved === 'classic') return 'light';
  if (saved === 'modern') return 'dark';
  return DEFAULT_THEME;
}

function applyTheme(theme) {
  const selected = theme === 'light' ? 'light' : 'dark';
  document.body.setAttribute('data-theme', selected);
  const lightBtn = document.getElementById('theme-light');
  const darkBtn = document.getElementById('theme-dark');
  if (lightBtn) lightBtn.setAttribute('aria-pressed', String(selected === 'light'));
  if (darkBtn) darkBtn.setAttribute('aria-pressed', String(selected === 'dark'));
  applyMapTheme(selected);
}

function bindThemeToggle() {
  const toggleButtons = document.querySelectorAll('.theme-btn[data-theme-value]');
  if (!toggleButtons.length) return;

  toggleButtons.forEach(btn => {
    btn.addEventListener('click', () => {
      const nextTheme = btn.dataset.themeValue === 'light' ? 'light' : 'dark';
      localStorage.setItem(THEME_STORAGE_KEY, nextTheme);
      applyTheme(nextTheme);
    });
  });
}

function getTileConfigForTheme(theme) {
  return theme === 'light' ? MAP_TILE_LIGHT : MAP_TILE_DARK;
}

function applyMapTheme(theme) {
  if (!selMap) return;
  if (selTileLayer) {
    selMap.removeLayer(selTileLayer);
    selTileLayer = null;
  }
  const cfg = getTileConfigForTheme(theme);
  selTileLayer = L.tileLayer(cfg.url, {
    attribution: cfg.attribution,
    maxZoom: cfg.maxZoom
  }).addTo(selMap);
}

function bindSearch() {
  document.getElementById('search').addEventListener('input', e => {
    const q = e.target.value.toLowerCase();
    const filtered = LOCALS.filter(l =>
      l.province.toLowerCase().includes(q) ||
      l.name.toLowerCase().includes(q) ||
      l.id.includes(q) ||
      (l.hallAddress && l.hallAddress.toLowerCase().includes(q))
    );
    renderList(filtered);
  });
}

function bindMapDots() {
  document.querySelectorAll('.map-dot[data-local]').forEach(dot => {
    dot.addEventListener('click', () => selectLocal(dot.dataset.local));
  });
}

function updateMapDots(activeId) {
  document.querySelectorAll('.map-dot[data-local]').forEach(dot => {
    dot.classList.toggle('active', dot.dataset.local === activeId);
  });
}

function renderList(locals) {
  const wrap = document.getElementById('locals-list');

  wrap.innerHTML = locals
    .sort((a, b) => parseInt(a.id) - parseInt(b.id))
    .map(l => {
      const isLive = l.dispatchMode === 'scrape' || l.dispatchMode === 'link';
      const avatarCls = isLive ? 'live' : 'contact';
      const badgeCls = isLive ? 'live' : 'contact';
      const badgeText = isLive
        ? '<span class="badge-dot"></span>Live'
        : 'Contact';
      const city = l.hallAddress ? l.hallAddress.split(',').slice(-2).join(',').trim() : '';
      return '<div class="local-row" data-id="' + esc(l.id) + '">' +
        '<div class="local-avatar ' + avatarCls + '">' + esc(l.id) + '</div>' +
        '<div class="local-info">' +
          '<div class="local-name">' + esc(l.province) + '</div>' +
          '<div class="local-city">' + esc(l.name) + (city ? ' · ' + esc(city) : '') + '</div>' +
        '</div>' +
        '<div class="local-badge ' + badgeCls + '">' + badgeText + '</div>' +
      '</div>';
    }).join('');

  wrap.querySelectorAll('.local-row').forEach(row => {
    row.addEventListener('click', () => selectLocal(row.dataset.id));
  });
}

function selectLocal(id) {
  localStorage.setItem(STORAGE_KEY, id);
  const l = LOCALS.find(x => x.id === id);
  if (!l) return;
  hideProvincePicker();
  updateMapDots(id);

  const section = document.getElementById('selected-local');
  section.hidden = false;

  document.getElementById('sel-name').textContent = l.name;
  document.getElementById('sel-province').textContent = l.province;
  document.getElementById('sel-address').textContent = l.hallAddress || '';
  document.getElementById('sel-address-row').style.display = l.hallAddress ? '' : 'none';

  const emailEl = document.getElementById('sel-email');
  const emailRow = document.getElementById('sel-email-row');
  if (l.email) {
    emailEl.textContent = l.email;
    emailEl.href = 'mailto:' + l.email;
    emailRow.style.display = '';
  } else {
    emailRow.style.display = 'none';
  }

  const webEl = document.getElementById('sel-website');
  webEl.textContent = l.website.replace(/^https?:\/\//, '');
  webEl.href = l.website;

  document.getElementById('sel-website-btn').href = l.website;
  document.getElementById('sel-directions-btn').href =
    getPlaceSearchUrl(l);
  renderOfficialLinks(l);

  document.getElementById('sel-clear').addEventListener('click', clearSelection);

  // Init or update map
  initSelMap(l);

  // Dispatch
  if (l.dispatchMode === 'scrape' && l.dispatchUrl) {
    loadDispatch(l);
  } else if (l.dispatchMode === 'link' && l.dispatchUrl) {
    showDispatchLink(l);
  } else {
    document.getElementById('dispatch-section').hidden = true;
  }

  // Refresh jobs with province preference and scroll to top
  loadJobs();
  section.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

function clearSelection() {
  localStorage.removeItem(STORAGE_KEY);
  document.getElementById('selected-local').hidden = true;
  document.getElementById('dispatch-section').hidden = true;
  const officialLinksSection = document.getElementById('official-links-section');
  const officialLinksList = document.getElementById('official-links-list');
  if (officialLinksSection) officialLinksSection.hidden = true;
  if (officialLinksList) officialLinksList.innerHTML = '';
  if (selMap) { selMap.remove(); selMap = null; }
  updateMapDots('');
  showProvincePicker();
  loadJobs();
}

function renderOfficialLinks(local) {
  const section = document.getElementById('official-links-section');
  const list = document.getElementById('official-links-list');
  if (!section || !list) return;

  const resources = Array.isArray(local.resources) ? local.resources.filter(r => r && r.label && r.url) : [];
  if (!resources.length) {
    section.hidden = true;
    list.innerHTML = '';
    return;
  }

  section.hidden = false;
  list.innerHTML = resources.map(r => {
    const type = r.type ? '<span class="official-link-type">' + esc(r.type) + '</span>' : '';
    return '<a class="official-link-row" href="' + esc(r.url) + '" target="_blank" rel="noopener">' +
      '<span class="official-link-label">' + esc(r.label) + '</span>' +
      type +
    '</a>';
  }).join('');
}

function initProvincePicker() {
  const picker = document.getElementById('province-picker');
  const sel = document.getElementById('province-select');
  const btn = document.getElementById('province-continue');
  if (!picker || !sel || !btn) return;

  const provinces = [...new Set(LOCALS.map(l => l.province).filter(Boolean))]
    .sort((a, b) => a.localeCompare(b));

  sel.innerHTML = '<option value="">Choose a province</option>' +
    provinces.map(p => '<option value="' + esc(p) + '">' + esc(p) + '</option>').join('');

  const chooseProvince = () => {
    const province = sel.value;
    if (!province) return;
    const local = LOCALS.find(l => l.province === province);
    if (local) selectLocal(local.id);
  };

  sel.addEventListener('change', chooseProvince);
  btn.addEventListener('click', chooseProvince);
}

function showProvincePicker() {
  const picker = document.getElementById('province-picker');
  if (picker) picker.hidden = false;
}

function hideProvincePicker() {
  const picker = document.getElementById('province-picker');
  if (picker) picker.hidden = true;
}

function isIOSDevice() {
  const ua = navigator.userAgent || '';
  const platform = navigator.platform || '';
  const maxTouch = navigator.maxTouchPoints || 0;
  return /iPad|iPhone|iPod/i.test(ua) ||
    (platform === 'MacIntel' && maxTouch > 1);
}

function getPlaceSearchUrl(local) {
  const name = local && local.name ? String(local.name) : '';
  const address = local && local.hallAddress ? String(local.hallAddress) : '';
  const province = local && local.province ? String(local.province) : '';
  const query = [name, address].filter(Boolean).join(', ') || [name, province].filter(Boolean).join(', ') || province || name;
  const q = encodeURIComponent(query);
  if (isIOSDevice()) return 'https://maps.apple.com/?q=' + q;
  return 'https://www.google.com/maps/search/?api=1&query=' + q;
}

function applySectionOrder() {
  const main = document.querySelector('main.container');
  if (!main) return;

  const ordered = [
    { id: 'selected-local', order: SECTION_ORDER.selectedLocal },
    { id: 'dispatch-section', order: SECTION_ORDER.liveDispatch },
    { id: 'union-directory-section', order: SECTION_ORDER.unionDirectory },
    { id: 'union-news-section', order: SECTION_ORDER.unionNews },
    { id: 'trade-news-section', order: SECTION_ORDER.tradeNews },
    { id: 'jobs-section', order: SECTION_ORDER.publicJobs }
  ]
    .map(item => ({ el: document.getElementById(item.id), order: item.order }))
    .filter(item => item.el)
    .sort((a, b) => a.order - b.order);

  ordered.forEach(item => {
    item.el.dataset.sectionOrder = String(item.order);
    main.appendChild(item.el);
  });
}

function getJobAvatarClass(source) {
  const s = String(source || '').toLowerCase();
  if (s.includes('job bank')) return 'jobbank';
  if (s.includes('indeed')) return 'indeed';
  if (s.includes('careerjet')) return 'careerjet';
  if (s.includes('union') || s.includes('local ')) return 'union';
  return 'fallback';
}

function getJobAvatarLabel(source) {
  const s = String(source || '').toLowerCase();
  if (s.includes('job bank')) return 'JB';
  if (s.includes('indeed')) return 'IN';
  if (s.includes('careerjet')) return 'CJ';
  if (s.includes('union') || s.includes('local ')) return 'LOC';
  return 'JOB';
}

function getJobSourceBadgeClass(source) {
  const s = String(source || '').toLowerCase();
  if (s.includes('job bank')) return 'jobsrc-jobbank';
  if (s.includes('indeed')) return 'jobsrc-indeed';
  if (s.includes('careerjet')) return 'jobsrc-careerjet';
  if (s.includes('adzuna')) return 'jobsrc-adzuna';
  if (s.includes('jooble')) return 'jobsrc-jooble';
  if (s.includes('union') || s.includes('local ')) return 'jobsrc-union';
  return 'jobsrc-generic';
}

async function loadJobs() {
  const listEl = document.getElementById('jobs-list');
  const timeEl = document.getElementById('jobs-time');
  if (!listEl || !timeEl) return;

  const selectedId = localStorage.getItem(STORAGE_KEY);
  const selectedLocal = selectedId ? LOCALS.find(l => l.id === selectedId) : null;
  const province = selectedLocal && selectedLocal.province ? selectedLocal.province : '';

  try {
    const endpoint = province ? ('jobs.php?province=' + encodeURIComponent(province)) : 'jobs.php';
    const res = await fetch(endpoint);
    const data = await res.json();
    if (!data.ok) {
      listEl.innerHTML = '<div class="dispatch-error">Could not load jobs: ' + esc(data.error || 'Unknown error') + '</div>';
      timeEl.textContent = '';
      return;
    }

    const jobs = Array.isArray(data.jobs) ? data.jobs : [];
    if (!jobs.length) {
      listEl.innerHTML = '<div class="dispatch-loading">No jobs found right now. Check back soon.</div>';
      timeEl.textContent = 'Updated ' + timeAgo(data.fetchedAt);
      return;
    }

    const isUnionItem = j => {
      const source = String(j.source || '').toLowerCase();
      const company = String(j.company || '').toLowerCase();
      return source.includes('union feed') || source.includes('local ') || company.includes('local ');
    };

    const publicItemsAll = jobs.filter(j => !isUnionItem(j));
    const realPublicItems = publicItemsAll.filter(j => !j || j.isFallback !== true);
    const publicItems = realPublicItems.length > 0 ? realPublicItems : publicItemsAll;

    const renderItem = j => {
      const location = j.location ? ' · ' + esc(j.location) : '';
      const tags = Array.isArray(j.tags) && j.tags.length
        ? '<div class="job-tags">' + j.tags.map(t => '<span class="job-tag">' + esc(t) + '</span>').join('') + '</div>'
        : '';
      const salary = j && j.salary ? '<div class="job-salary">' + esc(j.salary) + '</div>' : '';
      const allowedHighlights = ['Camp', 'FIFO', 'Red Seal', 'Shutdown', 'LOA', 'Travel', 'Remote', 'Apprentice', 'Journeyperson'];
      const highlightsList = Array.isArray(j.jobHighlights) ? j.jobHighlights.filter(h => allowedHighlights.includes(String(h))) : [];
      const highlights = highlightsList.length
        ? '<div class="job-highlights">' + highlightsList.map(h => '<span class="job-highlight">' + esc(h) + '</span>').join('') + '</div>'
        : '';
      const avatarCls = getJobAvatarClass(j.source);
      const avatarLabel = getJobAvatarLabel(j.source);
      return '<a class="local-row" href="' + esc(j.url) + '" target="_blank" rel="noopener">' +
        '<div class="job-avatar ' + avatarCls + '">' + avatarLabel + '</div>' +
        '<div class="local-info">' +
          '<div class="local-name">' + esc(j.title) + '</div>' +
          '<div class="local-city">' + esc(j.company || 'Unknown company') + location + '</div>' +
          salary +
          highlights +
          tags +
        '</div>' +
        '<div class="local-badge jobsrc ' + getJobSourceBadgeClass(j.source) + '"><span class="badge-dot"></span>' + esc(j.source || 'Site') + '</div>' +
      '</a>';
    };

    // Render with staged expansion: initial -> expanded (20) -> all.
    const visibleItems = publicItems.slice(0, JOBS_INITIAL_COUNT);
    const midItems = publicItems.slice(JOBS_INITIAL_COUNT, JOBS_EXPANDED_COUNT);
    const overflowItems = publicItems.slice(JOBS_EXPANDED_COUNT);

    let html = '<div class="jobs-subsection">' +
      '<div class="jobs-public-note">Union members: before applying to public job board listings, check with your local to confirm dispatch rules, eligibility, and obligations.</div>' +
      visibleItems.map(renderItem).join('');

    if (midItems.length) {
      html += '<div class="jobs-hidden jobs-hidden-mid" style="display:none">' + midItems.map(renderItem).join('') + '</div>';
      if (overflowItems.length) {
        html += '<div class="jobs-hidden jobs-hidden-overflow" style="display:none">' + overflowItems.map(renderItem).join('') + '</div>';
      }
      html += '<button class="show-more" type="button">Show more listings</button>';
    }

    html += '</div>';
    listEl.innerHTML = html;
    listEl.dataset.jobsLayout = 'public';

    // Bind show-more
    const showMoreBtn = listEl.querySelector('.show-more');
    if (showMoreBtn) {
      showMoreBtn.addEventListener('click', () => {
        const hiddenMid = listEl.querySelector('.jobs-hidden-mid');
        const hiddenOverflow = listEl.querySelector('.jobs-hidden-overflow');
        if (hiddenMid && hiddenMid.style.display === 'none') {
          hiddenMid.style.display = '';
          if (hiddenOverflow) {
            showMoreBtn.textContent = 'Show all listings';
            return;
          }
          showMoreBtn.remove();
          return;
        }
        if (hiddenOverflow && hiddenOverflow.style.display === 'none') {
          hiddenOverflow.style.display = '';
          showMoreBtn.remove();
        }
      });
    }

    timeEl.textContent = 'Updated ' + timeAgo(data.fetchedAt);
  } catch (e) {
    listEl.innerHTML = '<div class="dispatch-error">Job feed unavailable right now.</div>';
    timeEl.textContent = '';
  }
}

function initSelMap(local) {
  const container = document.getElementById('sel-map');

  if (selMap) { selMap.remove(); selMap = null; }

  selMap = L.map(container, { zoomControl: false }).setView([local.lat, local.lng], 15);
  L.control.zoom({ position: 'bottomright' }).addTo(selMap);
  applyMapTheme(loadThemePreference());

  selMarker = L.marker([local.lat, local.lng]).addTo(selMap);
  selMarker.bindPopup('<b>' + esc(local.name) + '</b><br>' + esc(local.hallAddress || ''));
}

async function loadDispatch(local) {
  const section = document.getElementById('dispatch-section');
  const content = document.getElementById('dispatch-content');
  section.hidden = false;

  content.innerHTML = '<div class="dispatch-loading">Loading dispatch…</div>';
  document.getElementById('dispatch-time').textContent = '';
  document.getElementById('dispatch-full-link').href = local.dispatchUrl;

  try {
    let res = await fetch('scrape.php?local=' + encodeURIComponent(local.id));
    let data = await res.json();
    const plain = String(data && data.html ? data.html : '').replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim().toLowerCase();
    const weak = !plain || plain.length < 80 || !/(call\s*#|contractor|personnel\s*required|date\s*required|dispatch)/.test(plain);

    if (data.ok && weak) {
      res = await fetch('scrape.php?local=' + encodeURIComponent(local.id) + '&refresh=1');
      data = await res.json();
    }

    if (data.ok) {
      content.innerHTML = renderDispatchContent(data.html, data.source || local.dispatchUrl);
      document.getElementById('dispatch-time').textContent = 'Updated ' + timeAgo(data.fetchedAt);
    } else {
      content.innerHTML = '<div class="dispatch-error">Could not load dispatch: ' + esc(data.error) + '</div>';
    }
  } catch (e) {
    content.innerHTML = '<div class="dispatch-error">Dispatch unavailable — <a href="' + esc(local.dispatchUrl) + '" target="_blank">view on their site →</a></div>';
  }
}

function renderDispatchContent(rawHtml, sourceUrl) {
  const html = String(rawHtml || '');
  const lines = html
    .replace(/<br\s*\/?>/gi, '\n')
    .replace(/<\/p>/gi, '\n')
    .replace(/<\/div>/gi, '\n')
    .replace(/<\/tr>/gi, '\n')
    .replace(/<\/li>/gi, '\n')
    .replace(/<[^>]+>/g, ' ')
    .replace(/\u00a0/g, ' ')
    .replace(/[ \t]+/g, ' ')
    .split('\n')
    .map(s => s.trim())
    .filter(Boolean);

  const titleLine = lines.find(l => /dispatch\s+for/i.test(l)) || 'Dispatch';
  const calls = parseDispatchCalls(lines, html, sourceUrl);
  if (!calls.length) {
    return '<div class="dispatch-raw">' + html + '</div>';
  }

  let out = '<div class="dispatch-clean">';
  out += '<div class="dispatch-clean-head">' + esc(titleLine) + '</div>';
  out += '<div class="dispatch-calls">';
  out += calls.map((c, idx) => {
    const badge = c.isNew ? '<span class="dispatch-new">NEW</span>' : '';
    const newCls = c.isNew ? ' is-new' : '';
    const rows = [
      ['Contractor', c.contractor],
      ['Worksite', c.worksite],
      ['Personnel', c.personnel],
      ['Date Required', c.dateRequired],
      ['Shift', c.shift]
    ].filter(r => r[1]);
    const rowsHtml = rows.map(r => '<div class="dispatch-kv"><span>' + esc(r[0]) + '</span><strong>' + esc(r[1]) + '</strong></div>').join('');
    const details = c.detailsUrl ? '<a class="dispatch-details" href="' + esc(c.detailsUrl) + '" target="_blank" rel="noopener">Full details</a>' : '';
    return '<article class="dispatch-card' + newCls + '">' +
      '<header><h3>Call #' + esc(c.callNo || String(idx + 1)) + '</h3>' + badge + '</header>' +
      '<div class="dispatch-card-body">' + rowsHtml + details + '</div>' +
    '</article>';
  }).join('');
  out += '</div>';
  out += '<div class="dispatch-source">Source: <a href="' + esc(sourceUrl) + '" target="_blank" rel="noopener">' + esc(sourceUrl.replace(/^https?:\/\//, '')) + '</a></div>';
  out += '</div>';
  return out;
}

function parseDispatchCalls(lines, rawHtml, sourceUrl) {
  const fromTextBlocks = parseDispatchCallsFromText(rawHtml, sourceUrl);
  if (fromTextBlocks.length) return fromTextBlocks;

  const idxs = [];
  for (let i = 0; i < lines.length; i++) {
    if (/^call\s*#?\s*\d+/i.test(lines[i])) idxs.push(i);
  }
  if (!idxs.length) return [];

  const urls = [...rawHtml.matchAll(/href=["']([^"']+)["']/gi)].map(m => m[1]);
  let urlPos = 0;
  const calls = [];
  for (let k = 0; k < idxs.length; k++) {
    const start = idxs[k];
    const end = k + 1 < idxs.length ? idxs[k + 1] : lines.length;
    const block = lines.slice(start, end);
    const callNoMatch = block[0].match(/(\d+)/);
    const call = {
      callNo: callNoMatch ? callNoMatch[1] : '',
      contractor: findVal(block, /contractor/i),
      worksite: findVal(block, /area\s*\/?\s*place\s*of\s*work|worksite/i),
      personnel: findVal(block, /personnel\s*required/i),
      dateRequired: findVal(block, /date\s*required/i),
      shift: findVal(block, /shift/i),
      isNew: block.some(l => /\bnew\b/i.test(l)),
      detailsUrl: ''
    };

    while (urlPos < urls.length) {
      const u = urls[urlPos++];
      if (/download|details|call|dispatch/i.test(u)) {
        call.detailsUrl = absolutizeUrl(sourceUrl, u);
        break;
      }
    }

    calls.push(call);
  }
  return calls;
}

function parseDispatchCallsFromText(rawHtml, sourceUrl) {
  const plain = String(rawHtml || '')
    .replace(/<br\s*\/?>/gi, '\n')
    .replace(/<\/p>/gi, '\n')
    .replace(/<\/div>/gi, '\n')
    .replace(/<\/tr>/gi, '\n')
    .replace(/<[^>]+>/g, ' ')
    .replace(/\u00a0/g, ' ')
    .replace(/[ \t]+/g, ' ');

  const blocks = [...plain.matchAll(/call\s*#\s*(\d+)\s*([\s\S]*?)(?=call\s*#\s*\d+|$)/ig)];
  if (!blocks.length) return [];

  const urls = [...String(rawHtml || '').matchAll(/href=["']([^"']+)["']/gi)].map(m => m[1]);
  let urlPos = 0;

  return blocks.map(m => {
    const body = m[2].replace(/\s+/g, ' ').trim();
    const call = {
      callNo: m[1],
      contractor: matchField(body, /contractor:\s*([\s\S]*?)(?=\barea\s*\/?\s*place\s*of\s*work:|\bpersonnel\s*required:|\bdate\s*required:|\bshift:|\bfull\s*details:|$)/i),
      worksite: matchField(body, /area\s*\/?\s*place\s*of\s*work:\s*([\s\S]*?)(?=\bpersonnel\s*required:|\bdate\s*required:|\bshift:|\bfull\s*details:|$)/i),
      personnel: matchField(body, /personnel\s*required:\s*([\s\S]*?)(?=\bdate\s*required:|\bshift:|\bfull\s*details:|$)/i),
      dateRequired: matchField(body, /date\s*required:\s*([\s\S]*?)(?=\bshift:|\bfull\s*details:|$)/i),
      shift: matchField(body, /shift:\s*([\s\S]*?)(?=\bfull\s*details:|$)/i),
      isNew: /\bnew\b/i.test(body),
      detailsUrl: ''
    };

    while (urlPos < urls.length) {
      const u = urls[urlPos++];
      if (/download|details|call|dispatch/i.test(u)) {
        call.detailsUrl = absolutizeUrl(sourceUrl, u);
        break;
      }
    }
    return call;
  });
}

function matchField(text, re) {
  const m = text.match(re);
  return m ? sanitizeDispatchValue(m[1]) : '';
}

function absolutizeUrl(base, maybeRelative) {
  const u = String(maybeRelative || '').trim();
  if (!u) return '';
  if (/^https?:\/\//i.test(u)) return u;
  if (u.startsWith('//')) return 'https:' + u;
  try {
    return new URL(u, base).toString();
  } catch (_) {
    return '';
  }
}

function findVal(block, labelRe) {
  for (let i = 0; i < block.length; i++) {
    const line = block[i];
    if (!labelRe.test(line)) continue;
    const inline = sanitizeDispatchValue(line.replace(/^[^:]+:\s*/, ''));
    if (inline && !labelRe.test(inline)) return inline;
    for (let j = i + 1; j < block.length && j <= i + 2; j++) {
      const next = sanitizeDispatchValue(block[j] || '');
      if (next && !/^[A-Za-z ]+:\s*$/.test(next) && !/^call\s*#?/i.test(next)) return next;
    }
  }
  return '';
}

function sanitizeDispatchValue(value) {
  let v = String(value || '').replace(/\s+/g, ' ').trim();
  v = v.replace(/\bNEW\b/gi, '').replace(/\s+/g, ' ').trim();
  return v;
}

function showDispatchLink(local) {
  const section = document.getElementById('dispatch-section');
  const content = document.getElementById('dispatch-content');
  section.hidden = false;
  content.innerHTML = '<div class="dispatch-loading">Dispatch info available on their website.</div>';
  document.getElementById('dispatch-time').textContent = '';
  document.getElementById('dispatch-full-link').href = local.dispatchUrl;
}

function timeAgo(isoStr) {
  const diff = Math.floor((Date.now() - new Date(isoStr).getTime()) / 60000);
  if (diff < 1) return 'just now';
  if (diff < 60) return diff + ' min ago';
  if (diff < 1440) return Math.floor(diff / 60) + 'h ago';
  return Math.floor(diff / 1440) + 'd ago';
}

function esc(str) {
  if (str == null) return '';
  return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

init();

if ('serviceWorker' in navigator) {
  window.addEventListener('load', function () {
    navigator.serviceWorker.register('/service-worker.js').catch(function () {});
  });
}
