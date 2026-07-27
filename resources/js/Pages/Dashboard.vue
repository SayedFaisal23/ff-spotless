<script setup>
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import Sortable from 'sortablejs';

const props = defineProps({
    mode: { type: String, default: 'welcome' },
    auth: { type: Object, default: () => ({ user: null, isAdmin: false }) },
    sessions: { type: Array, default: () => [] },
    tasks: { type: Array, default: () => [] },
    currentDate: { type: String, default: '' },
    isReadOnly: { type: Boolean, default: true },
    dayUnavailable: { type: Boolean, default: false },
    uploadLimits: { type: Object, default: () => ({ maxFiles: 20, maxFileMb: 10, uploadMax: '', postMax: '' }) },
    templates: { type: [Array, Object], default: () => [] },
    weeklyTemplates: { type: [Array, Object], default: () => [] },
    completedTasks: { type: [Array, Object], default: () => [] },
    statistics: { type: Object, default: null },
    workload: { type: Array, default: () => [] },
});

const page = usePage();
const screen = ref(resolveScreen());
const adminTab = ref('history');
const selectedDate = ref(props.currentDate);
const adminDate = ref(props.currentDate);
const localTasks = ref([]);
const theme = ref(typeof window !== 'undefined' ? (localStorage.getItem('ff-spotless-theme') || 'dark') : 'dark');
const notice = ref('');
const actionError = ref('');
const busy = ref(false);
const adminLogin = ref('');
const evidenceTask = ref(null);
const evidenceFiles = ref([]);
const evidencePreviews = ref([]);
const viewingEvidence = ref(null);
const editing = ref(null);
const sessionEditing = ref(null);
const sortables = [];
let noticeTimer;

const dailyForm = ref({ task_name: '', task_session_id: '', credit_hours: 1 });
const weeklyForm = ref({ task_name: '', task_session_id: '', due_weekday: 1, credit_hours: 1 });
const sessionForm = ref({ name: '' });
const editForm = ref({});

const activeSessions = computed(() => props.sessions.filter((session) => session.isActive));
const templates = computed(() => collectionItems(props.templates));
const weeklyTemplates = computed(() => collectionItems(props.weeklyTemplates));
const history = computed(() => collectionItems(props.completedTasks));
const today = computed(() => new Intl.DateTimeFormat('en-CA', {
    timeZone: 'Asia/Kuala_Lumpur', year: 'numeric', month: '2-digit', day: '2-digit',
}).format(new Date()));
const isToday = computed(() => selectedDate.value === today.value);
const locked = computed(() => props.isReadOnly || props.dayUnavailable || busy.value);
const completedCount = computed(() => localTasks.value.filter((task) => task.completed).length);
const progress = computed(() => localTasks.value.length ? Math.round((completedCount.value / localTasks.value.length) * 100) : 0);
const statsMax = computed(() => Math.max(1, ...(props.statistics?.trend ?? []).map((row) => row.completed + row.missed + row.pending)));

watch(() => [props.mode, props.currentDate, props.tasks, props.dayUnavailable], async () => {
    screen.value = resolveScreen();
    selectedDate.value = props.currentDate;
    adminDate.value = props.currentDate;
    localTasks.value = props.tasks.map((task) => ({ ...task }));
    await nextTick();
    initializeSortables();
}, { immediate: true, deep: true });

watch(theme, (value) => {
    if (typeof document === 'undefined') return;
    localStorage.setItem('ff-spotless-theme', value);
    document.documentElement.dataset.theme = value;
    document.documentElement.style.colorScheme = value;
}, { immediate: true });

watch(activeSessions, (sessions) => {
    const firstId = sessions[0]?.id ?? '';
    if (!dailyForm.value.task_session_id) dailyForm.value.task_session_id = firstId;
    if (!weeklyForm.value.task_session_id) weeklyForm.value.task_session_id = firstId;
}, { immediate: true });

onBeforeUnmount(() => {
    destroySortables();
    clearEvidenceFiles();
});

function collectionItems(value) {
    return Array.isArray(value) ? value : (value?.data ?? []);
}

function resolveScreen() {
    if (['welcome', 'checklist', 'admin'].includes(props.mode)) return props.mode;
    return props.auth?.isAdmin ? 'admin' : 'welcome';
}

function sessionTasks(sessionId) {
    return localTasks.value.filter((task) => Number(task.sessionId) === Number(sessionId))
        .sort((a, b) => a.position - b.position);
}

function historyFor(sessionId) {
    return history.value.filter((item) => Number(item.sessionId) === Number(sessionId));
}

function templatesFor(sessionId, weekly = false) {
    return (weekly ? weeklyTemplates.value : templates.value)
        .filter((item) => Number(item.sessionId) === Number(sessionId));
}

function sessionCredits(items) {
    return items.reduce((sum, item) => sum + Number(item.creditHours || 0), 0).toFixed(2).replace(/\.00$/, '');
}

function sessionTone(index) {
    return ['text-amber-300', 'text-sky-300', 'text-violet-300', 'text-emerald-300', 'text-rose-300'][index % 5];
}

function displayDate(value) {
    if (!/^\d{4}-\d{2}-\d{2}$/.test(value || '')) return value;
    const [year, month, day] = value.split('-').map(Number);
    return new Intl.DateTimeFormat('ms-MY', {
        weekday: 'long', day: 'numeric', month: 'short', year: 'numeric', timeZone: 'Asia/Kuala_Lumpur',
    }).format(new Date(Date.UTC(year, month - 1, day, 12)));
}

function weekdayName(day) {
    return ['Isnin', 'Selasa', 'Rabu', 'Khamis', 'Jumaat', 'Sabtu', 'Ahad'][Number(day) - 1] ?? '';
}

function dateOffset(value, offset) {
    const [year, month, day] = value.split('-').map(Number);
    const date = new Date(year, month - 1, day, 12);
    date.setDate(date.getDate() + offset);
    return [date.getFullYear(), String(date.getMonth() + 1).padStart(2, '0'), String(date.getDate()).padStart(2, '0')].join('-');
}

function formatTimestamp(value) {
    if (!value) return '—';
    return new Intl.DateTimeFormat('ms-MY', {
        dateStyle: 'medium', timeStyle: 'short', timeZone: 'Asia/Kuala_Lumpur',
    }).format(new Date(value));
}

function setNotice(message) {
    actionError.value = '';
    notice.value = message;
    clearTimeout(noticeTimer);
    noticeTimer = setTimeout(() => notice.value = '', 4000);
}

function fail(errors, fallback) {
    actionError.value = Object.values(errors ?? {}).flat()[0] ?? fallback;
    notice.value = '';
}

function inertiaOptions(message, fallback, onSuccess = null) {
    return {
        preserveScroll: true,
        preserveState: true,
        onStart: () => busy.value = true,
        onSuccess: () => {
            setNotice(message);
            onSuccess?.();
        },
        onError: (errors) => fail(errors, fallback),
        onFinish: () => busy.value = false,
    };
}

function loginAdmin() {
    router.post('/admin/login', { password: adminLogin.value }, inertiaOptions(
        'Akses pentadbir dibuka.', 'Kata laluan pentadbir tidak diterima.', () => adminLogin.value = '',
    ));
}

function logoutAdmin() {
    router.post('/admin/logout', {}, {
        onFinish: () => { screen.value = 'welcome'; },
    });
}

function openChecklist(date = null) {
    router.get('/checklist', date ? { date } : {}, {
        preserveScroll: true, preserveState: true,
        onStart: () => busy.value = true,
        onError: (errors) => fail(errors, 'Senarai semak tidak dapat dibuka.'),
        onFinish: () => busy.value = false,
    });
}

function openAdmin(date = null, stats = null) {
    const data = {};
    if (date) data.date = date;
    if (stats) Object.assign(data, stats);
    router.get('/admin', data, {
        preserveScroll: true, preserveState: true,
        onStart: () => busy.value = true,
        onFinish: () => busy.value = false,
    });
}

function toggleAvailability() {
    router.post('/checklist/availability', {
        date: selectedDate.value,
        is_unavailable: !props.dayUnavailable,
    }, inertiaOptions(
        props.dayUnavailable ? 'Hari ini tersedia semula.' : 'Hari ini ditandakan MC/tidak tersedia.',
        'Status ketersediaan tidak dapat dikemas kini.',
    ));
}

function openEvidence(task) {
    if (locked.value || task.completed) return;
    evidenceTask.value = task;
    evidenceFiles.value = [];
    evidencePreviews.value = [];
}

function selectEvidence(event) {
    const files = [...evidenceFiles.value, ...Array.from(event.target.files ?? [])];
    if (files.length > Number(props.uploadLimits.maxFiles)) {
        fail({}, `Pelayan membenarkan maksimum ${props.uploadLimits.maxFiles} foto bagi satu penghantaran.`);
        event.target.value = '';
        return;
    }
    evidenceFiles.value = files;
    evidencePreviews.value.push(...Array.from(event.target.files ?? []).map((file) => URL.createObjectURL(file)));
    event.target.value = '';
}

function removeEvidence(index) {
    URL.revokeObjectURL(evidencePreviews.value[index]);
    evidenceFiles.value.splice(index, 1);
    evidencePreviews.value.splice(index, 1);
}

function clearEvidenceFiles() {
    evidencePreviews.value.forEach((url) => URL.revokeObjectURL(url));
    evidencePreviews.value = [];
    evidenceFiles.value = [];
}

function closeEvidence() {
    if (busy.value) return;
    clearEvidenceFiles();
    evidenceTask.value = null;
}

function completeTask() {
    if (!evidenceTask.value || !evidenceFiles.value.length) {
        fail({}, 'Pilih sekurang-kurangnya satu foto bukti.');
        return;
    }
    const form = new FormData();
    form.append('date', selectedDate.value);
    evidenceFiles.value.forEach((file) => form.append('photos[]', file));
    const task = evidenceTask.value;
    router.post(`/tasks/${task.type}/${task.id}/complete`, form, {
        ...inertiaOptions('Tugasan selesai dengan bukti foto.', 'Tugasan tidak dapat diselesaikan.', closeEvidence),
        forceFormData: true,
    });
}

function destroySortables() {
    while (sortables.length) sortables.pop().destroy();
}

function initializeSortables() {
    destroySortables();
    if (!isToday.value || props.dayUnavailable || screen.value !== 'checklist') return;
    document.querySelectorAll('[data-sortable-session]').forEach((element) => {
        const sessionId = Number(element.dataset.sortableSession);
        sortables.push(Sortable.create(element, {
            animation: 160,
            handle: '.drag-handle',
            ghostClass: 'opacity-40',
            onEnd: () => persistDomOrder(element, sessionId),
        }));
    });
}

function persistDomOrder(element, sessionId) {
    const keys = Array.from(element.querySelectorAll('[data-task-key]')).map((node) => node.dataset.taskKey);
    const items = keys.map((key) => {
        const [type, id] = key.split(':');
        return { type, id: Number(id) };
    });
    reorderLocal(sessionId, keys);
    persistOrder(sessionId, items);
}

function moveTask(sessionId, key, direction) {
    const tasks = sessionTasks(sessionId);
    const index = tasks.findIndex((task) => task.key === key);
    const target = index + direction;
    if (index < 0 || target < 0 || target >= tasks.length || locked.value) return;
    [tasks[index], tasks[target]] = [tasks[target], tasks[index]];
    const keys = tasks.map((task) => task.key);
    reorderLocal(sessionId, keys);
    persistOrder(sessionId, tasks.map(({ type, id }) => ({ type, id })));
}

function reorderLocal(sessionId, keys) {
    const positions = new Map(keys.map((key, index) => [key, index + 1]));
    localTasks.value = localTasks.value.map((task) => Number(task.sessionId) === Number(sessionId)
        ? { ...task, position: positions.get(task.key) ?? task.position }
        : task);
}

function persistOrder(sessionId, items) {
    router.post('/checklist/order', {
        date: selectedDate.value,
        task_session_id: sessionId,
        items,
    }, inertiaOptions('Susunan tugasan disimpan.', 'Susunan tugasan tidak dapat disimpan.'));
}

function createDaily() {
    router.post('/admin/templates', dailyForm.value, inertiaOptions(
        'Tugasan harian ditambah.', 'Tugasan harian tidak dapat ditambah.',
        () => dailyForm.value = { task_name: '', task_session_id: activeSessions.value[0]?.id ?? '', credit_hours: 1 },
    ));
}

function createWeekly() {
    router.post('/admin/weekly-templates', weeklyForm.value, inertiaOptions(
        'Tugasan mingguan ditambah.', 'Tugasan mingguan tidak dapat ditambah.',
        () => weeklyForm.value = { task_name: '', task_session_id: activeSessions.value[0]?.id ?? '', due_weekday: 1, credit_hours: 1 },
    ));
}

function openEdit(kind, item) {
    editing.value = { kind, item };
    editForm.value = kind === 'daily'
        ? { task_name: item.taskName, task_session_id: item.sessionId, credit_hours: item.creditHours }
        : { task_name: item.taskName, task_session_id: item.sessionId, credit_hours: item.creditHours, due_weekday: item.dueWeekday };
}

function saveEdit() {
    const base = editing.value.kind === 'daily' ? '/admin/templates' : '/admin/weekly-templates';
    router.patch(`${base}/${editing.value.item.id}`, editForm.value, inertiaOptions(
        'Templat dikemas kini.', 'Templat tidak dapat dikemas kini.', () => editing.value = null,
    ));
}

function deleteTemplate(kind, item) {
    if (!confirm(`Arkibkan “${item.taskName}”? Rekod sejarah akan dikekalkan.`)) return;
    const base = kind === 'daily' ? '/admin/templates' : '/admin/weekly-templates';
    router.delete(`${base}/${item.id}`, inertiaOptions('Templat diarkibkan.', 'Templat tidak dapat diarkibkan.'));
}

function createSession() {
    router.post('/admin/sessions', sessionForm.value, inertiaOptions(
        'Sesi ditambah.', 'Sesi tidak dapat ditambah.', () => sessionForm.value.name = '',
    ));
}

function editSession(session) {
    sessionEditing.value = session;
    editForm.value = { name: session.name };
}

function saveSession() {
    router.patch(`/admin/sessions/${sessionEditing.value.id}`, editForm.value, inertiaOptions(
        'Nama sesi dikemas kini.', 'Sesi tidak dapat dikemas kini.', () => sessionEditing.value = null,
    ));
}

function archiveSession(session) {
    if (!confirm(`Arkibkan sesi “${session.name}”?`)) return;
    router.delete(`/admin/sessions/${session.id}`, inertiaOptions('Sesi diarkibkan.', 'Sesi tidak dapat diarkibkan.'));
}

function moveSession(index, direction) {
    const ordered = [...activeSessions.value];
    const target = index + direction;
    if (target < 0 || target >= ordered.length) return;
    [ordered[index], ordered[target]] = [ordered[target], ordered[index]];
    router.patch('/admin/sessions/reorder', { session_ids: ordered.map((session) => session.id) },
        inertiaOptions('Susunan sesi disimpan.', 'Susunan sesi tidak dapat disimpan.'));
}

function statsPreset(days) {
    adminTab.value = 'statistics';
    openAdmin(adminDate.value, {
        stats_from: dateOffset(today.value, -(days - 1)),
        stats_to: today.value,
    });
}

function customStats(event) {
    const form = new FormData(event.target);
    openAdmin(adminDate.value, {
        stats_from: form.get('stats_from'),
        stats_to: form.get('stats_to'),
    });
}
</script>

<template>
    <div :class="theme === 'light' ? 'theme-light' : 'theme-dark'" class="min-h-screen bg-[#121212] text-zinc-100">
        <div v-if="notice || actionError" class="fixed inset-x-4 top-4 z-[80] mx-auto max-w-lg rounded-xl border px-4 py-3 text-sm font-semibold shadow-2xl" :class="actionError ? 'border-rose-500/40 bg-rose-950 text-rose-100' : 'border-emerald-500/40 bg-emerald-950 text-emerald-100'">
            {{ actionError || notice }}
        </div>

        <main v-if="screen === 'welcome'" class="mx-auto flex min-h-screen max-w-md flex-col justify-center px-6 py-12 text-center">
            <div class="mx-auto mb-7 flex h-20 w-20 items-center justify-center rounded-3xl bg-gradient-to-br from-[#ED4264] to-[#FFEDBC] text-3xl font-black text-zinc-950 shadow-xl shadow-rose-950/40">FF</div>
            <h1 class="text-3xl font-black tracking-tight">FF Spotless</h1>
            <p class="mt-3 text-sm leading-relaxed text-zinc-400">Senarai semak pembersihan harian dan tugasan mingguan.</p>
            <button class="mt-8 h-14 rounded-2xl bg-gradient-to-r from-[#ED4264] to-[#FFEDBC] font-black text-zinc-950" @click="openChecklist()">Buka senarai hari ini</button>
            <button class="mt-3 h-12 rounded-2xl border border-zinc-700 text-sm font-bold text-zinc-300" @click="screen = 'admin-login'">Pentadbir</button>
        </main>

        <main v-else-if="screen === 'admin-login'" class="mx-auto flex min-h-screen max-w-md flex-col justify-center px-6 py-12">
            <button class="mb-8 w-fit text-sm text-zinc-400" @click="screen = 'welcome'">← Kembali</button>
            <h1 class="text-center text-2xl font-black">Akses Pentadbir</h1>
            <form class="mt-7 space-y-3" @submit.prevent="loginAdmin">
                <input v-model="adminLogin" required type="password" autocomplete="current-password" class="h-14 w-full rounded-2xl border border-zinc-700 bg-zinc-900 px-4 text-center tracking-widest outline-none focus:border-[#ED4264]" placeholder="Kata laluan">
                <button :disabled="busy" class="h-14 w-full rounded-2xl bg-gradient-to-r from-[#ED4264] to-[#FFEDBC] font-black text-zinc-950 disabled:opacity-50">Log masuk</button>
            </form>
        </main>

        <main v-else-if="screen === 'checklist'" class="mx-auto min-h-screen max-w-3xl">
            <header class="sticky top-0 z-20 border-b border-zinc-800 bg-[#121212]/95 px-5 py-4 backdrop-blur">
                <div class="flex items-center justify-between">
                    <button class="text-sm font-bold text-zinc-400" @click="router.get('/')">← Keluar</button>
                    <button class="rounded-lg border border-zinc-700 px-3 py-2 text-xs" @click="theme = theme === 'light' ? 'dark' : 'light'">{{ theme === 'light' ? 'Gelap' : 'Cerah' }}</button>
                </div>
                <div class="mt-4 flex items-center gap-3">
                    <button :disabled="busy" class="h-10 w-10 rounded-xl border border-zinc-700" @click="openChecklist(dateOffset(selectedDate, -1))">‹</button>
                    <button class="min-w-0 flex-1 text-center" @click="openChecklist()">
                        <span class="block text-sm font-black">{{ displayDate(selectedDate) }}</span>
                        <span v-if="!isToday" class="text-[10px] font-bold uppercase text-[#ED4264]">Kembali ke hari ini</span>
                    </button>
                    <button :disabled="busy" class="h-10 w-10 rounded-xl border border-zinc-700" @click="openChecklist(dateOffset(selectedDate, 1))">›</button>
                </div>
            </header>

            <section class="px-5 pt-5">
                <label class="flex items-start gap-3 rounded-2xl border p-4" :class="dayUnavailable ? 'border-rose-500/40 bg-rose-500/10' : 'border-zinc-700 bg-zinc-900/50'">
                    <input type="checkbox" class="mt-1 h-5 w-5 accent-[#ED4264]" :checked="dayUnavailable" :disabled="!isToday || busy" @change="toggleAvailability">
                    <span>
                        <strong class="block text-sm">MC / tidak tersedia hari ini</strong>
                        <span class="mt-1 block text-xs leading-relaxed text-zinc-400">Mengunci tugasan harian dan memindahkan tugasan mingguan yang perlu dibuat hari ini.</span>
                    </span>
                </label>
                <div v-if="isReadOnly" class="mt-3 rounded-xl border border-zinc-700 bg-zinc-900/40 p-3 text-xs text-zinc-400">Tarikh lampau dan masa hadapan adalah baca sahaja.</div>
                <div class="mt-5 h-1.5 overflow-hidden rounded-full bg-zinc-800"><div class="h-full bg-gradient-to-r from-[#ED4264] to-[#FFEDBC]" :style="{ width: `${progress}%` }"></div></div>
                <div class="mt-2 flex justify-between text-xs text-zinc-400"><span>{{ completedCount }} daripada {{ localTasks.length }} selesai</span><span>{{ progress }}%</span></div>
            </section>

            <section class="space-y-7 px-5 py-6">
                <div v-if="!localTasks.length" class="rounded-2xl border border-dashed border-zinc-700 p-10 text-center text-sm text-zinc-500">Tiada tugasan untuk tarikh ini.</div>
                <section v-for="(session, sessionIndex) in sessions" :key="session.id" v-show="sessionTasks(session.id).length">
                    <header class="mb-3 flex items-center justify-between">
                        <h2 class="font-black uppercase tracking-wider" :class="sessionTone(sessionIndex)">{{ session.name }}</h2>
                        <span class="rounded-full border border-zinc-700 px-2.5 py-1 text-[10px] font-bold text-zinc-400">{{ sessionCredits(sessionTasks(session.id)) }} jam kredit</span>
                    </header>
                    <div class="space-y-2" :data-sortable-session="session.id">
                        <article v-for="(task, taskIndex) in sessionTasks(session.id)" :key="task.key" :data-task-key="task.key" class="flex items-center gap-2 rounded-2xl border border-zinc-700 bg-zinc-900 p-3">
                            <button v-if="isToday && !dayUnavailable" class="drag-handle cursor-grab px-1 text-lg text-zinc-500" aria-label="Seret untuk menyusun">⋮⋮</button>
                            <button class="min-w-0 flex-1 text-left" :disabled="locked || task.completed" @click="openEvidence(task)">
                                <span class="flex items-center gap-2">
                                    <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-lg border-2" :class="task.completed ? 'border-[#ED4264] bg-[#ED4264] text-white' : 'border-zinc-600'">{{ task.completed ? '✓' : '' }}</span>
                                    <span class="min-w-0">
                                        <span class="block text-sm font-semibold" :class="task.completed ? 'text-zinc-500 line-through' : 'text-zinc-100'">{{ task.text }}</span>
                                        <span class="mt-1 flex flex-wrap gap-2 text-[10px] font-bold uppercase text-zinc-500">
                                            <span>{{ task.creditHours }} jam</span>
                                            <span v-if="task.isWeekly" class="text-sky-300">Mingguan · perlu {{ displayDate(task.originalDueDate) }}</span>
                                            <span v-if="task.postponedCount">Ditunda {{ task.postponedCount }}×</span>
                                        </span>
                                    </span>
                                </span>
                            </button>
                            <div v-if="isToday && !dayUnavailable" class="flex flex-col gap-1">
                                <button class="text-xs text-zinc-500 disabled:opacity-20" :disabled="taskIndex === 0 || busy" @click="moveTask(session.id, task.key, -1)">▲</button>
                                <button class="text-xs text-zinc-500 disabled:opacity-20" :disabled="taskIndex === sessionTasks(session.id).length - 1 || busy" @click="moveTask(session.id, task.key, 1)">▼</button>
                            </div>
                        </article>
                    </div>
                </section>
            </section>
        </main>

        <main v-else-if="screen === 'admin'" class="mx-auto min-h-screen max-w-7xl">
            <header class="sticky top-0 z-30 border-b border-zinc-800 bg-[#121212]/95 px-5 py-4 backdrop-blur">
                <div class="flex items-center justify-between">
                    <div><p class="text-[10px] font-black uppercase tracking-[.2em] text-[#ED4264]">Pentadbir</p><h1 class="text-lg font-black">FF Spotless</h1></div>
                    <div class="flex gap-2">
                        <button class="rounded-lg border border-zinc-700 px-3 py-2 text-xs" @click="theme = theme === 'light' ? 'dark' : 'light'">{{ theme === 'light' ? 'Gelap' : 'Cerah' }}</button>
                        <button class="rounded-lg border border-zinc-700 px-3 py-2 text-xs font-bold" @click="logoutAdmin">Log keluar</button>
                    </div>
                </div>
                <nav class="mt-4 flex gap-2 overflow-x-auto pb-1">
                    <button v-for="tab in [
                        ['history','Sejarah'], ['daily','Harian'], ['weekly','Mingguan'], ['sessions','Sesi'], ['statistics','Statistik']
                    ]" :key="tab[0]" class="shrink-0 rounded-xl border px-3 py-2 text-xs font-bold" :class="adminTab === tab[0] ? 'border-[#ED4264]/40 bg-[#ED4264]/10 text-rose-200' : 'border-zinc-700 text-zinc-400'" @click="adminTab = tab[0]">{{ tab[1] }}</button>
                </nav>
            </header>

            <section class="px-5 py-6">
                <div v-if="adminTab === 'history'" class="space-y-6">
                    <div class="flex items-center gap-3 rounded-2xl border border-zinc-700 bg-zinc-900/50 p-3">
                        <button class="h-10 w-10 rounded-xl border border-zinc-700" @click="openAdmin(dateOffset(adminDate, -1))">‹</button>
                        <button class="flex-1 text-sm font-black" @click="openAdmin()">{{ displayDate(adminDate) }}</button>
                        <button class="h-10 w-10 rounded-xl border border-zinc-700" @click="openAdmin(dateOffset(adminDate, 1))">›</button>
                    </div>
                    <section v-for="(session, index) in sessions" :key="session.id" v-show="historyFor(session.id).length">
                        <header class="mb-3 flex justify-between"><h2 class="font-black uppercase" :class="sessionTone(index)">{{ session.name }}</h2><span class="text-xs text-zinc-500">{{ sessionCredits(historyFor(session.id)) }} jam kredit</span></header>
                        <div class="grid gap-2 md:grid-cols-2">
                            <button v-for="entry in historyFor(session.id)" :key="entry.key" class="rounded-xl border border-zinc-700 bg-zinc-900 p-4 text-left disabled:cursor-default" :disabled="!entry.evidence?.length" @click="viewingEvidence = entry">
                                <div class="flex justify-between gap-3"><strong class="text-sm">{{ entry.text }}</strong><span class="rounded-full px-2 py-1 text-[9px] font-black uppercase" :class="entry.status === 'completed' ? 'bg-emerald-500/10 text-emerald-300' : entry.status === 'missed' ? 'bg-rose-500/10 text-rose-300' : 'bg-zinc-800 text-zinc-400'">{{ entry.status === 'completed' ? 'Selesai' : entry.status === 'missed' ? 'Terlepas' : 'Menunggu' }}</span></div>
                                <p class="mt-2 text-xs text-zinc-500">{{ entry.creditHours }} jam<span v-if="entry.type === 'weekly'"> · Mingguan, perlu {{ displayDate(entry.originalDueDate) }}</span></p>
                                <p v-if="entry.isCompleted" class="mt-1 text-xs text-zinc-500">{{ formatTimestamp(entry.completedAt) }} · {{ entry.evidence.length }} foto</p>
                            </button>
                        </div>
                    </section>
                    <p v-if="!history.length" class="rounded-2xl border border-dashed border-zinc-700 p-10 text-center text-sm text-zinc-500">Tiada rekod untuk tarikh ini.</p>
                </div>

                <div v-else-if="adminTab === 'daily'" class="grid gap-6 lg:grid-cols-[360px_1fr]">
                    <form class="space-y-3 rounded-2xl border border-zinc-700 bg-zinc-900/50 p-5" @submit.prevent="createDaily">
                        <h2 class="font-black">Tambah Tugasan Harian</h2>
                        <input v-model.trim="dailyForm.task_name" required maxlength="255" class="field" placeholder="Nama tugasan">
                        <select v-model="dailyForm.task_session_id" required class="field"><option v-for="session in activeSessions" :key="session.id" :value="session.id">{{ session.name }}</option></select>
                        <input v-model.number="dailyForm.credit_hours" required type="number" min="0.25" max="24" step="0.25" class="field" placeholder="Jam kredit">
                        <button :disabled="busy" class="primary-button">Tambah tugasan</button>
                    </form>
                    <div class="space-y-6">
                        <section v-for="(session, index) in activeSessions" :key="session.id">
                            <header class="mb-2 flex justify-between"><h3 class="font-black uppercase" :class="sessionTone(index)">{{ session.name }}</h3><span class="text-xs text-zinc-500">{{ sessionCredits(templatesFor(session.id)) }} jam/hari</span></header>
                            <div class="space-y-2"><article v-for="item in templatesFor(session.id)" :key="item.id" class="flex items-center justify-between gap-3 rounded-xl border border-zinc-700 bg-zinc-900 p-3"><div><p class="text-sm font-semibold">{{ item.taskName }}</p><p class="text-xs text-zinc-500">{{ item.creditHours }} jam</p></div><div class="flex gap-2"><button class="small-button" @click="openEdit('daily', item)">Edit</button><button class="small-button text-rose-300" @click="deleteTemplate('daily', item)">Arkib</button></div></article></div>
                        </section>
                    </div>
                </div>

                <div v-else-if="adminTab === 'weekly'" class="grid gap-6 lg:grid-cols-[360px_1fr]">
                    <form class="space-y-3 rounded-2xl border border-zinc-700 bg-zinc-900/50 p-5" @submit.prevent="createWeekly">
                        <h2 class="font-black">Tambah Tugasan Mingguan</h2>
                        <input v-model.trim="weeklyForm.task_name" required maxlength="255" class="field" placeholder="Nama tugasan">
                        <select v-model="weeklyForm.task_session_id" required class="field"><option v-for="session in activeSessions" :key="session.id" :value="session.id">{{ session.name }}</option></select>
                        <select v-model.number="weeklyForm.due_weekday" class="field"><option v-for="day in 7" :key="day" :value="day">{{ weekdayName(day) }}</option></select>
                        <input v-model.number="weeklyForm.credit_hours" required type="number" min="0.25" max="24" step="0.25" class="field" placeholder="Jam kredit">
                        <button :disabled="busy" class="primary-button">Tambah mingguan</button>
                    </form>
                    <div class="space-y-6">
                        <section v-for="(session, index) in activeSessions" :key="session.id">
                            <header class="mb-2 flex justify-between"><h3 class="font-black uppercase" :class="sessionTone(index)">{{ session.name }}</h3><span class="text-xs text-zinc-500">{{ sessionCredits(templatesFor(session.id, true)) }} jam/minggu</span></header>
                            <div class="space-y-2"><article v-for="item in templatesFor(session.id, true)" :key="item.id" class="flex items-center justify-between gap-3 rounded-xl border border-zinc-700 bg-zinc-900 p-3"><div><p class="text-sm font-semibold">{{ item.taskName }}</p><p class="text-xs text-zinc-500">{{ weekdayName(item.dueWeekday) }} · {{ item.creditHours }} jam</p></div><div class="flex gap-2"><button class="small-button" @click="openEdit('weekly', item)">Edit</button><button class="small-button text-rose-300" @click="deleteTemplate('weekly', item)">Arkib</button></div></article></div>
                        </section>
                    </div>
                </div>

                <div v-else-if="adminTab === 'sessions'" class="grid gap-6 lg:grid-cols-[360px_1fr]">
                    <div class="space-y-5">
                        <form class="space-y-3 rounded-2xl border border-zinc-700 bg-zinc-900/50 p-5" @submit.prevent="createSession"><h2 class="font-black">Tambah Sesi</h2><input v-model.trim="sessionForm.name" required maxlength="100" class="field" placeholder="Nama sesi"><button class="primary-button">Tambah sesi</button></form>
                        <div class="rounded-2xl border border-zinc-700 bg-zinc-900/50 p-5"><h2 class="font-black">Beban Mingguan Dijangka</h2><div class="mt-3 space-y-2"><div v-for="row in workload" :key="row.sessionId" class="rounded-xl border p-3" :class="row.isOverloaded ? 'border-amber-500/40 bg-amber-500/10' : 'border-zinc-700'"><div class="flex justify-between text-sm font-bold"><span>{{ row.sessionName }}</span><span>{{ row.expectedWeeklyCredits }} jam</span></div><p class="mt-1 text-[10px] text-zinc-500">7 × {{ row.dailyCredits }} harian + {{ row.weeklyCredits }} mingguan<span v-if="row.isOverloaded" class="text-amber-300"> · melebihi purata 20%</span></p></div></div></div>
                    </div>
                    <div class="space-y-2">
                        <article v-for="(session, index) in activeSessions" :key="session.id" class="flex items-center gap-3 rounded-xl border border-zinc-700 bg-zinc-900 p-4"><span class="w-7 text-center font-black text-zinc-500">{{ index + 1 }}</span><strong class="min-w-0 flex-1">{{ session.name }}</strong><button class="small-button" :disabled="index === 0" @click="moveSession(index, -1)">▲</button><button class="small-button" :disabled="index === activeSessions.length - 1" @click="moveSession(index, 1)">▼</button><button class="small-button" @click="editSession(session)">Edit</button><button class="small-button text-rose-300" @click="archiveSession(session)">Arkib</button></article>
                    </div>
                </div>

                <div v-else-if="adminTab === 'statistics' && statistics" class="space-y-6">
                    <div class="flex flex-wrap gap-2"><button v-for="days in [7,30,90]" :key="days" class="small-button" @click="statsPreset(days)">{{ days }} hari</button><form class="flex flex-wrap gap-2" @submit.prevent="customStats"><input name="stats_from" type="date" required :value="statistics.from" class="field !w-auto"><input name="stats_to" type="date" required :value="statistics.to" class="field !w-auto"><button class="small-button">Tapis</button></form></div>
                    <p class="text-xs text-zinc-500">Statistik tepat tersedia dari {{ displayDate(statistics.trackingStart) }}.</p>
                    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                        <div v-for="card in [
                            ['Selesai', statistics.overview.completed], ['Terlepas', statistics.overview.missed], ['Kadar selesai', statistics.overview.completionRate + '%'], ['MC', statistics.overview.mcDays],
                            ['Kredit dirancang', statistics.overview.plannedCredits.toFixed(2)], ['Kredit selesai', statistics.overview.completedCredits.toFixed(2)], ['Ditunda', statistics.overview.postponements], ['Menunggu', statistics.overview.pending],
                        ]" :key="card[0]" class="rounded-2xl border border-zinc-700 bg-zinc-900 p-4"><p class="text-xs font-bold uppercase text-zinc-500">{{ card[0] }}</p><p class="mt-2 text-2xl font-black">{{ card[1] }}</p></div>
                    </div>
                    <div class="rounded-2xl border border-zinc-700 bg-zinc-900 p-5"><h2 class="font-black">Trend Harian</h2><div class="mt-5 flex h-44 items-end gap-1 overflow-x-auto"><div v-for="row in statistics.trend" :key="row.date" class="group flex min-w-4 flex-1 flex-col items-center justify-end" :title="`${displayDate(row.date)}: ${row.completed} selesai, ${row.missed} terlepas`"><div class="w-full rounded-t bg-emerald-500" :style="{ height: `${(row.completed / statsMax) * 130}px` }"></div><div class="w-full bg-rose-500" :style="{ height: `${(row.missed / statsMax) * 130}px` }"></div><span class="mt-2 hidden text-[8px] text-zinc-600 md:block">{{ row.date.slice(8) }}</span></div></div></div>
                    <div class="grid gap-4 lg:grid-cols-2">
                        <div class="rounded-2xl border border-zinc-700 bg-zinc-900 p-5"><h2 class="font-black">Mengikut Sesi</h2><div class="mt-3 space-y-3"><div v-for="row in statistics.sessions" :key="row.id" class="rounded-xl border border-zinc-700 p-3"><div class="flex justify-between text-sm font-bold"><span>{{ row.name }}</span><span>{{ row.completedCredits }} / {{ row.plannedCredits }} jam</span></div><p class="mt-1 text-xs text-zinc-500">{{ row.completed }} selesai · {{ row.missed }} terlepas</p></div></div></div>
                        <div class="rounded-2xl border border-zinc-700 bg-zinc-900 p-5"><h2 class="font-black">Status Mingguan</h2><div class="mt-4 grid grid-cols-3 gap-3 text-center"><div v-for="item in [['Selesai',statistics.weeklyStatus.completed],['Menunggu',statistics.weeklyStatus.pending],['Terlepas',statistics.weeklyStatus.missed]]" :key="item[0]" class="rounded-xl border border-zinc-700 p-4"><p class="text-2xl font-black">{{ item[1] }}</p><p class="mt-1 text-[10px] uppercase text-zinc-500">{{ item[0] }}</p></div></div></div>
                    </div>
                </div>
            </section>
        </main>

        <div v-if="evidenceTask" class="modal-backdrop">
            <form class="modal-card" @submit.prevent="completeTask">
                <div class="flex justify-between gap-3"><div><h2 class="font-black">Foto Bukti</h2><p class="mt-1 text-sm text-zinc-400">{{ evidenceTask.text }}</p></div><button type="button" class="small-button" @click="closeEvidence">✕</button></div>
                <div class="mt-5 rounded-2xl border border-dashed border-zinc-600 p-5 text-center">
                    <strong class="block text-sm">Tambah foto bukti</strong>
                    <span class="mt-2 block text-xs text-zinc-500">JPEG, PNG atau WebP · maksimum {{ uploadLimits.maxFileMb }} MB setiap satu · had pelayan {{ uploadLimits.maxFiles }} fail / {{ uploadLimits.postMax }}</span>
                    <div class="mt-4 grid grid-cols-2 gap-2">
                        <label class="small-button cursor-pointer">Ambil foto<input type="file" accept="image/jpeg,image/png,image/webp" capture="environment" class="sr-only" @change="selectEvidence"></label>
                        <label class="small-button cursor-pointer">Pilih galeri<input type="file" multiple accept="image/jpeg,image/png,image/webp" class="sr-only" @change="selectEvidence"></label>
                    </div>
                </div>
                <div v-if="evidencePreviews.length" class="mt-4 grid grid-cols-3 gap-2"><div v-for="(preview, index) in evidencePreviews" :key="preview" class="relative aspect-square overflow-hidden rounded-xl bg-zinc-800"><img :src="preview" alt="Pratonton bukti" class="h-full w-full object-cover"><button type="button" class="absolute right-1 top-1 h-7 w-7 rounded-full bg-black/75 text-xs" @click="removeEvidence(index)">✕</button></div></div>
                <button :disabled="busy || !evidenceFiles.length" class="primary-button mt-5">Hantar bukti & tandakan selesai</button>
                <p class="mt-3 text-center text-xs text-amber-300">Tugasan yang selesai tidak boleh dibuka semula oleh cleaner.</p>
            </form>
        </div>

        <div v-if="viewingEvidence" class="modal-backdrop">
            <div class="modal-card max-w-3xl">
                <div class="flex justify-between gap-3"><div><h2 class="font-black">{{ viewingEvidence.text }}</h2><p class="mt-1 text-xs text-zinc-500">{{ formatTimestamp(viewingEvidence.completedAt) }} · {{ viewingEvidence.creditHours }} jam</p></div><button class="small-button" @click="viewingEvidence = null">✕</button></div>
                <p v-if="viewingEvidence.type === 'weekly'" class="mt-3 text-xs text-sky-300">Mingguan · perlu {{ displayDate(viewingEvidence.originalDueDate) }} · dijadual akhir {{ displayDate(viewingEvidence.scheduledDate) }}</p>
                <div class="mt-5 grid gap-3 sm:grid-cols-2"><a v-for="photo in viewingEvidence.evidence" :key="photo.id" :href="photo.url" target="_blank" rel="noopener" class="overflow-hidden rounded-xl border border-zinc-700 bg-zinc-900"><img :src="photo.url" loading="lazy" alt="Foto bukti tugasan" class="max-h-96 w-full object-contain"></a></div>
            </div>
        </div>

        <div v-if="editing || sessionEditing" class="modal-backdrop">
            <form class="modal-card" @submit.prevent="sessionEditing ? saveSession() : saveEdit()">
                <div class="flex justify-between"><h2 class="font-black">{{ sessionEditing ? 'Edit Sesi' : 'Edit Templat' }}</h2><button type="button" class="small-button" @click="editing = null; sessionEditing = null">✕</button></div>
                <div class="mt-5 space-y-3">
                    <input v-if="sessionEditing" v-model.trim="editForm.name" required maxlength="100" class="field" placeholder="Nama sesi">
                    <template v-else>
                        <input v-model.trim="editForm.task_name" required maxlength="255" class="field" placeholder="Nama tugasan">
                        <select v-model="editForm.task_session_id" required class="field"><option v-for="session in activeSessions" :key="session.id" :value="session.id">{{ session.name }}</option></select>
                        <select v-if="editing.kind === 'weekly'" v-model.number="editForm.due_weekday" class="field"><option v-for="day in 7" :key="day" :value="day">{{ weekdayName(day) }}</option></select>
                        <input v-model.number="editForm.credit_hours" required type="number" min="0.25" max="24" step="0.25" class="field">
                    </template>
                </div>
                <button class="primary-button mt-5">Simpan perubahan</button>
            </form>
        </div>
    </div>
</template>

<style scoped>
.field { width: 100%; height: 2.75rem; border-radius: .75rem; border: 1px solid rgb(63 63 70); background: #121212; padding: 0 .75rem; font-size: .875rem; outline: none; }
.field:focus { border-color: #ED4264; }
.primary-button { width: 100%; height: 2.75rem; border-radius: .75rem; background: linear-gradient(to right, #ED4264, #FFEDBC); color: #18181b; font-size: .875rem; font-weight: 800; }
.primary-button:disabled { opacity: .5; }
.small-button { border: 1px solid rgb(82 82 91); border-radius: .6rem; padding: .45rem .65rem; font-size: .7rem; font-weight: 700; }
.small-button:disabled { opacity: .25; }
.modal-backdrop { position: fixed; inset: 0; z-index: 60; display: flex; align-items: flex-end; justify-content: center; overflow-y: auto; background: rgb(0 0 0 / .78); padding: 1rem; }
.modal-card { width: 100%; max-width: 32rem; max-height: 92vh; overflow-y: auto; border: 1px solid rgb(82 82 91); border-radius: 1rem; background: #171717; padding: 1.25rem; box-shadow: 0 25px 50px -12px rgb(0 0 0 / .7); }
.theme-light { background: #f8fafc; color: #18181b; }
.theme-light :is(header, .modal-card, [class*="bg-[#121212]"]) { background-color: #f8fafc; }
.theme-light [class*="bg-zinc-900"] { background-color: #fff; }
.theme-light [class*="text-zinc-100"] { color: #18181b; }
.theme-light [class*="text-zinc-400"], .theme-light [class*="text-zinc-500"] { color: #52525b; }
.theme-light [class*="border-zinc-700"], .theme-light [class*="border-zinc-800"] { border-color: #d4d4d8; }
@media (min-width: 640px) { .modal-backdrop { align-items: center; } }
</style>
