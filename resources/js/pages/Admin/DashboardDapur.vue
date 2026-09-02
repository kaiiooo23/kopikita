<script setup lang="ts">
import { ref, computed, onMounted, onUnmounted } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import axios from 'axios';
import { echo } from '@laravel/echo-vue';
import {
    Coffee,
    Clock,
    CheckCircle2,
    Flame,
    Bell,
    BellOff,
    Volume2,
    ChefHat,
    Utensils,
    AlertCircle,
    ArrowRight,
    RefreshCw,
    Search,
    Wifi,
    Check,
    Store,
    Timer,
    Sparkles,
} from 'lucide-vue-next';

interface OrderItemData {
    id: number;
    menu_id: number;
    quantity: number;
    notes: string | null;
    menu?: {
        id: number;
        name: string;
        price: string | number;
    };
}

interface TableData {
    id: string;
    table_number: string;
    current_token?: string | null;
}

interface OrderData {
    id: string;
    table_id: string;
    total_price: string | number;
    status: 'pending' | 'cooking' | 'completed' | 'paid';
    created_at: string;
    updated_at?: string;
    table?: TableData;
    items?: OrderItemData[];
}

const props = defineProps<{
    initialOrders?: OrderData[];
}>();

// Reactive State
const activeOrders = ref<OrderData[]>(
    props.initialOrders ? [...props.initialOrders] : [],
);
const soundEnabled = ref(true);
const isConnected = ref(true);
const updatingOrderId = ref<string | null>(null);
const currentFilter = ref<'all' | 'pending' | 'cooking' | 'completed'>('all');
const searchQuery = ref('');
const currentTimeStr = ref('');
let timerInterval: any = null;

// Audio Chime Synthesizer via Web Audio API (Reliable without external mp3 files)
const playChimeSound = () => {
    if (!soundEnabled.value) return;
    try {
        const AudioContextClass =
            window.AudioContext || (window as any).webkitAudioContext;
        if (!AudioContextClass) return;
        const ctx = new AudioContextClass();

        const playTone = (freq: number, start: number, duration: number) => {
            const osc = ctx.createOscillator();
            const gain = ctx.createGain();
            osc.type = 'sine';
            osc.frequency.setValueAtTime(freq, ctx.currentTime + start);

            gain.gain.setValueAtTime(0.001, ctx.currentTime + start);
            gain.gain.exponentialRampToValueAtTime(
                0.35,
                ctx.currentTime + start + 0.03,
            );
            gain.gain.exponentialRampToValueAtTime(
                0.001,
                ctx.currentTime + start + duration,
            );

            osc.connect(gain);
            gain.connect(ctx.destination);

            osc.start(ctx.currentTime + start);
            osc.stop(ctx.currentTime + start + duration);
        };

        // Elegant Double Chime (D5 -> A5)
        playTone(587.33, 0, 0.22);
        playTone(880, 0.16, 0.45);
    } catch (e) {
        console.warn('Audio play restricted by browser policy:', e);
    }
};

// Update live clock
const updateClock = () => {
    const now = new Date();
    currentTimeStr.value = now.toLocaleTimeString('id-ID', {
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
    });
};

// Format Rupiah
const formatRupiah = (val: number | string) => {
    const num = typeof val === 'string' ? parseFloat(val) : val;
    return new Intl.NumberFormat('id-ID', {
        style: 'currency',
        currency: 'IDR',
        maximumFractionDigits: 0,
    }).format(num || 0);
};

// Format Timestamp
const formatTime = (dateStr: string) => {
    try {
        const d = new Date(dateStr);
        return d.toLocaleTimeString('id-ID', {
            hour: '2-digit',
            minute: '2-digit',
        });
    } catch {
        return dateStr;
    }
};

// Elapsed minutes
const getElapsedMinutes = (dateStr: string) => {
    try {
        const start = new Date(dateStr).getTime();
        const diff = Math.max(0, Math.floor((Date.now() - start) / 60000));
        if (diff === 0) return 'Baru saja';
        return `${diff} mnt lalu`;
    } catch {
        return '';
    }
};

// Filtered lists
const pendingOrders = computed(() =>
    activeOrders.value.filter((o) => o.status === 'pending'),
);
const cookingOrders = computed(() =>
    activeOrders.value.filter((o) => o.status === 'cooking'),
);
const completedOrders = computed(() =>
    activeOrders.value.filter((o) => o.status === 'completed'),
);

// Displayed orders based on filter & search
const displayedOrders = computed(() => {
    return activeOrders.value.filter((order) => {
        const matchFilter =
            currentFilter.value === 'all' ||
            order.status === currentFilter.value;
        const search = searchQuery.value.toLowerCase();
        const tableMatch =
            order.table?.table_number.toLowerCase().includes(search) ?? false;
        const itemMatch =
            order.items?.some((i) =>
                i.menu?.name.toLowerCase().includes(search),
            ) ?? false;
        return matchFilter && (search === '' || tableMatch || itemMatch);
    });
});

// Update Order Status (Async API Call)
const updateStatus = async (
    orderId: string,
    nextStatus: 'pending' | 'cooking' | 'completed',
) => {
    updatingOrderId.value = orderId;
    try {
        const response = await axios.patch(`/api/orders/${orderId}/status`, {
            status: nextStatus,
        });

        if (response.data?.status === 'success') {
            const target = activeOrders.value.find((o) => o.id === orderId);
            if (target) {
                target.status = nextStatus;
                target.updated_at = new Date().toISOString();
            }
        }
    } catch (err) {
        console.error('Gagal memperbarui status order:', err);
    } finally {
        updatingOrderId.value = null;
    }
};

// Refresh Orders manually
const fetchOrders = async () => {
    try {
        const response = await axios.get('/admin/dapur', {
            headers: { Accept: 'application/json' },
        });
        if (response.data?.initialOrders) {
            activeOrders.value = response.data.initialOrders;
        }
    } catch (e) {
        console.warn('Manual fetch error:', e);
    }
};

// Real-Time Event Listener via Laravel Echo & Reverb
onMounted(() => {
    updateClock();
    timerInterval = setInterval(updateClock, 1000);

    try {
        const handleOrderEvent = (payload: { order: OrderData }) => {
            const incomingOrder: OrderData =
                payload?.order || (payload as unknown as OrderData);
            if (!incomingOrder || !incomingOrder.id) return;

            // Check if already in active orders
            const existingIdx = activeOrders.value.findIndex(
                (o) => o.id === incomingOrder.id,
            );

            if (incomingOrder.status === 'paid') {
                if (existingIdx !== -1)
                    activeOrders.value.splice(existingIdx, 1);
                return;
            }

            if (existingIdx !== -1) {
                activeOrders.value[existingIdx] = incomingOrder;
            } else {
                // Masukkan ke barisan paling depan/atas
                activeOrders.value.unshift(incomingOrder);
                // Bunyikan notifikasi audio
                playChimeSound();
            }
        };

        echo()
            .channel('dapur-channel')
            .listen('.NewOrder', handleOrderEvent)
            .listen('.OrderUpdated', handleOrderEvent);

        isConnected.value = true;
    } catch (e) {
        console.error('Gagal menghubungkan ke WebSocket Reverb:', e);
        isConnected.value = false;
    }
});

onUnmounted(() => {
    if (timerInterval) clearInterval(timerInterval);
    try {
        echo().leaveChannel('dapur-channel');
    } catch (e) {}
});
</script>

<template>
    <Head title="Monitor Dapur (KDS) | KopiKita" />

    <div
        class="flex min-h-screen flex-col bg-stone-950 text-stone-100 selection:bg-amber-500 selection:text-stone-950"
    >
        <!-- 1. TOP MONITOR HEADER -->
        <header
            class="sticky top-0 z-40 border-b border-stone-800/80 bg-stone-900/90 px-4 py-3 shadow-lg backdrop-blur-md lg:px-6"
        >
            <div class="flex flex-wrap items-center justify-between gap-3">
                <!-- Brand & Monitor Title -->
                <div class="flex items-center gap-3">
                    <div
                        class="flex h-10 w-10 items-center justify-center rounded-xl bg-gradient-to-tr from-amber-600 to-amber-400 shadow-lg ring-2 shadow-amber-600/25 ring-amber-500/20"
                    >
                        <ChefHat class="h-6 w-6 stroke-[2.2] text-stone-950" />
                    </div>
                    <div>
                        <div class="flex items-center gap-2">
                            <h1
                                class="font-serif text-lg font-bold tracking-tight text-white"
                            >
                                KopiKita
                            </h1>
                            <span
                                class="rounded-full border border-amber-500/30 bg-amber-500/20 px-2 py-0.5 font-mono text-xs font-semibold text-amber-300"
                            >
                                KITCHEN DISPLAY
                            </span>
                        </div>
                        <p class="text-[11px] text-stone-400">
                            Live Barista & Kitchen Order Queue System
                        </p>
                    </div>
                </div>

                <!-- Center: Real-Time Stats & Live Clock -->
                <div
                    class="flex items-center gap-3 rounded-2xl border border-stone-800 bg-stone-950/80 px-4 py-2 shadow-inner"
                >
                    <!-- Status Antrean -->
                    <div
                        class="flex items-center gap-3 border-r border-stone-800 pr-3 text-xs font-semibold"
                    >
                        <div class="flex items-center gap-1.5 text-amber-400">
                            <span
                                class="h-2 w-2 animate-pulse rounded-full bg-amber-400"
                            ></span>
                            <span>{{ pendingOrders.length }} Pending</span>
                        </div>
                        <div class="flex items-center gap-1.5 text-sky-400">
                            <span
                                class="h-2 w-2 animate-pulse rounded-full bg-sky-400"
                            ></span>
                            <span>{{ cookingOrders.length }} Cooking</span>
                        </div>
                    </div>

                    <!-- Digital Live Clock -->
                    <div
                        class="flex items-center gap-1.5 font-mono text-sm font-bold tracking-wider text-stone-200"
                    >
                        <Clock class="h-4 w-4 text-stone-400" />
                        <span>{{ currentTimeStr }}</span>
                    </div>
                </div>

                <!-- Right Controls: Sound, Connection, Refresh -->
                <div class="flex items-center gap-2.5">
                    <!-- Live WebSocket Indicator -->
                    <div
                        class="flex items-center gap-1.5 rounded-xl border px-3 py-1.5 text-xs font-medium"
                        :class="
                            isConnected
                                ? 'border-emerald-500/30 bg-emerald-950/50 text-emerald-400'
                                : 'border-red-500/30 bg-red-950/50 text-red-400'
                        "
                    >
                        <Wifi class="h-3.5 w-3.5" />
                        <span>{{
                            isConnected ? 'Reverb Live' : 'Offline'
                        }}</span>
                    </div>

                    <!-- Audio Toggle & Sound Test -->
                    <button
                        @click="
                            soundEnabled = !soundEnabled;
                            if (soundEnabled) playChimeSound();
                        "
                        class="flex cursor-pointer items-center gap-1.5 rounded-xl border border-stone-700 bg-stone-800/90 px-3 py-1.5 text-xs font-medium text-stone-200 transition hover:bg-stone-700"
                        :title="
                            soundEnabled ? 'Matikan Suara' : 'Aktifkan Suara'
                        "
                    >
                        <Volume2
                            v-if="soundEnabled"
                            class="h-4 w-4 text-amber-400"
                        />
                        <BellOff v-else class="h-4 w-4 text-stone-400" />
                        <span class="hidden sm:inline">{{
                            soundEnabled ? 'Suara ON' : 'Mute'
                        }}</span>
                    </button>

                    <!-- E-Menu Link (Quick switch) -->
                    <Link
                        href="/menu"
                        class="flex items-center gap-1 rounded-xl border border-amber-500/40 bg-amber-600/20 px-3 py-1.5 text-xs font-medium text-amber-300 transition hover:bg-amber-600/30"
                    >
                        <Store class="h-3.5 w-3.5" />
                        <span class="hidden sm:inline">E-Menu</span>
                    </Link>
                </div>
            </div>

            <!-- View Tabs Bar & Quick Search -->
            <div
                class="mt-3 flex flex-wrap items-center justify-between gap-3 border-t border-stone-800/80 pt-2.5"
            >
                <!-- Filter Tabs -->
                <div class="flex items-center gap-1.5 overflow-x-auto">
                    <button
                        @click="currentFilter = 'all'"
                        class="cursor-pointer rounded-xl px-3 py-1.5 text-xs font-semibold transition"
                        :class="
                            currentFilter === 'all'
                                ? 'bg-stone-100 text-stone-950 shadow'
                                : 'bg-stone-900 text-stone-400 hover:text-stone-200'
                        "
                    >
                        Semua Antrean ({{ activeOrders.length }})
                    </button>
                    <button
                        @click="currentFilter = 'pending'"
                        class="flex cursor-pointer items-center gap-1.5 rounded-xl px-3 py-1.5 text-xs font-semibold transition"
                        :class="
                            currentFilter === 'pending'
                                ? 'bg-amber-500 text-stone-950 shadow-md shadow-amber-500/20'
                                : 'bg-stone-900 text-stone-400 hover:text-stone-200'
                        "
                    >
                        <span class="h-2 w-2 rounded-full bg-amber-400"></span>
                        <span>Antrean Baru ({{ pendingOrders.length }})</span>
                    </button>
                    <button
                        @click="currentFilter = 'cooking'"
                        class="flex cursor-pointer items-center gap-1.5 rounded-xl px-3 py-1.5 text-xs font-semibold transition"
                        :class="
                            currentFilter === 'cooking'
                                ? 'bg-sky-500 text-stone-950 shadow-md shadow-sky-500/20'
                                : 'bg-stone-900 text-stone-400 hover:text-stone-200'
                        "
                    >
                        <span class="h-2 w-2 rounded-full bg-sky-400"></span>
                        <span>Sedang Diracik ({{ cookingOrders.length }})</span>
                    </button>
                    <button
                        @click="currentFilter = 'completed'"
                        class="flex cursor-pointer items-center gap-1.5 rounded-xl px-3 py-1.5 text-xs font-semibold transition"
                        :class="
                            currentFilter === 'completed'
                                ? 'bg-emerald-500 text-stone-950 shadow-md shadow-emerald-500/20'
                                : 'bg-stone-900 text-stone-400 hover:text-stone-200'
                        "
                    >
                        <span
                            class="h-2 w-2 rounded-full bg-emerald-400"
                        ></span>
                        <span>Selesai ({{ completedOrders.length }})</span>
                    </button>
                </div>

                <!-- Search by Table / Menu -->
                <div class="relative w-full sm:w-64">
                    <Search
                        class="absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 text-stone-400"
                    />
                    <input
                        v-model="searchQuery"
                        type="text"
                        placeholder="Cari Meja atau Menu..."
                        class="w-full rounded-xl border border-stone-800 bg-stone-900 py-1.5 pr-3 pl-9 text-xs text-stone-200 placeholder:text-stone-500 focus:ring-1 focus:ring-amber-500 focus:outline-none"
                    />
                </div>
            </div>
        </header>

        <!-- 2. MAIN CONTENT AREA (KANBAN 3-COLUMN OR GRID) -->
        <main class="flex-1 overflow-x-auto p-4 lg:p-6">
            <!-- Empty State -->
            <div
                v-if="displayedOrders.length === 0"
                class="flex h-96 flex-col items-center justify-center rounded-3xl border border-stone-800/80 bg-stone-900/30 p-8 text-center"
            >
                <div
                    class="mb-3 flex h-16 w-16 items-center justify-center rounded-2xl bg-stone-800/60 text-stone-500"
                >
                    <Coffee class="h-8 w-8 stroke-[1.5]" />
                </div>
                <h3 class="font-serif text-base font-bold text-stone-300">
                    Belum Ada Pesanan Aktif
                </h3>
                <p class="mt-1 max-w-sm text-xs text-stone-500">
                    Pesanan dari pelanggan yang memesan melalui E-Menu akan
                    otomatis meluncur masuk ke layar monitor ini secara
                    real-time.
                </p>
            </div>

            <!-- KANBAN COLUMNS VIEW (Saat filter 'all') -->
            <div
                v-else-if="currentFilter === 'all'"
                class="grid grid-cols-1 items-start gap-5 md:grid-cols-2 lg:grid-cols-3"
            >
                <!-- KOLOM 1: ANTREAN BARU (PENDING) -->
                <div
                    class="flex min-h-[500px] flex-col gap-3 rounded-2xl border border-amber-900/30 bg-stone-900/50 p-3.5"
                >
                    <div
                        class="flex items-center justify-between border-b border-stone-800/80 px-1 pb-2"
                    >
                        <div class="flex items-center gap-2">
                            <span
                                class="h-3 w-3 rounded-full bg-amber-500 shadow-sm shadow-amber-500/50"
                            ></span>
                            <h2
                                class="font-serif text-sm font-bold text-stone-200"
                            >
                                Antrean Baru
                            </h2>
                        </div>
                        <span
                            class="rounded-full bg-amber-500/20 px-2 py-0.5 font-mono text-xs font-bold text-amber-300"
                        >
                            {{ pendingOrders.length }}
                        </span>
                    </div>

                    <!-- Kartu Pesanan Pending dengan Animasi Meluncur (TransitionGroup) -->
                    <TransitionGroup
                        name="order-card"
                        tag="div"
                        class="space-y-3.5"
                    >
                        <div
                            v-for="order in pendingOrders"
                            :key="order.id"
                            class="relative overflow-hidden rounded-2xl border border-amber-500/40 bg-stone-900 p-4 shadow-xl shadow-stone-950/60 transition-all hover:border-amber-400"
                        >
                            <!-- Top Card Header: Table Number & Time -->
                            <div
                                class="flex items-center justify-between border-b border-stone-800 pb-2.5"
                            >
                                <div class="flex items-center gap-2">
                                    <span
                                        class="rounded-xl border border-amber-500/30 bg-amber-950/80 px-3 py-1 font-serif text-lg font-black tracking-tight text-amber-400 shadow-inner"
                                    >
                                        {{
                                            order.table?.table_number ||
                                            'Meja --'
                                        }}
                                    </span>
                                </div>
                                <div class="text-right">
                                    <div
                                        class="flex items-center justify-end gap-1 font-mono text-xs font-bold text-stone-200"
                                    >
                                        <Clock class="h-3 w-3 text-amber-400" />
                                        <span>{{
                                            formatTime(order.created_at)
                                        }}</span>
                                    </div>
                                    <span
                                        class="text-[10px] font-medium text-amber-400/80"
                                    >
                                        {{
                                            getElapsedMinutes(order.created_at)
                                        }}
                                    </span>
                                </div>
                            </div>

                            <!-- List Item Kopi & Makanan -->
                            <div class="space-y-2 py-3">
                                <div
                                    v-for="item in order.items"
                                    :key="item.id"
                                    class="rounded-xl border border-stone-800/80 bg-stone-950/70 p-2.5"
                                >
                                    <div
                                        class="flex items-center justify-between text-xs"
                                    >
                                        <div class="flex items-center gap-2">
                                            <span
                                                class="flex h-6 w-6 items-center justify-center rounded-lg border border-amber-500/30 bg-amber-500/20 text-xs font-extrabold text-amber-300"
                                            >
                                                {{ item.quantity }}x
                                            </span>
                                            <span
                                                class="text-sm font-bold text-stone-100"
                                            >
                                                {{ item.menu?.name || 'Menu' }}
                                            </span>
                                        </div>
                                    </div>

                                    <!-- Catatan Kustom Pelanggan (Kontras Tinggi) -->
                                    <div
                                        v-if="item.notes"
                                        class="mt-1.5 flex items-start gap-1.5 rounded-lg border border-amber-400/50 bg-amber-950/90 px-2 py-1 text-[11px] leading-snug font-medium text-amber-200"
                                    >
                                        <AlertCircle
                                            class="mt-0.5 h-3.5 w-3.5 shrink-0 text-amber-400"
                                        />
                                        <span
                                            >Notes:
                                            <strong class="underline">{{
                                                item.notes
                                            }}</strong></span
                                        >
                                    </div>
                                </div>
                            </div>

                            <!-- Footer: Total & Action Button -->
                            <div
                                class="flex items-center justify-between gap-2 border-t border-stone-800 pt-2"
                            >
                                <span class="text-xs font-bold text-stone-400">
                                    {{ formatRupiah(order.total_price) }}
                                </span>

                                <button
                                    @click="updateStatus(order.id, 'cooking')"
                                    :disabled="updatingOrderId === order.id"
                                    class="flex cursor-pointer items-center gap-1.5 rounded-xl bg-gradient-to-r from-amber-600 to-amber-500 px-3.5 py-2 text-xs font-extrabold text-stone-950 shadow-md shadow-amber-600/30 transition hover:from-amber-500 hover:to-amber-400 active:scale-95 disabled:opacity-50"
                                >
                                    <Flame class="h-3.5 w-3.5 fill-current" />
                                    <span>{{
                                        updatingOrderId === order.id
                                            ? 'Memproses...'
                                            : 'Mulai Racik'
                                    }}</span>
                                </button>
                            </div>
                        </div>
                    </TransitionGroup>
                </div>

                <!-- KOLOM 2: SEDANG DIRACIK (COOKING) -->
                <div
                    class="flex min-h-[500px] flex-col gap-3 rounded-2xl border border-sky-900/30 bg-stone-900/50 p-3.5"
                >
                    <div
                        class="flex items-center justify-between border-b border-stone-800/80 px-1 pb-2"
                    >
                        <div class="flex items-center gap-2">
                            <span
                                class="h-3 w-3 animate-pulse rounded-full bg-sky-500 shadow-sm shadow-sky-500/50"
                            ></span>
                            <h2
                                class="font-serif text-sm font-bold text-stone-200"
                            >
                                Sedang Diracik
                            </h2>
                        </div>
                        <span
                            class="rounded-full bg-sky-500/20 px-2 py-0.5 font-mono text-xs font-bold text-sky-300"
                        >
                            {{ cookingOrders.length }}
                        </span>
                    </div>

                    <TransitionGroup
                        name="order-card"
                        tag="div"
                        class="space-y-3.5"
                    >
                        <div
                            v-for="order in cookingOrders"
                            :key="order.id"
                            class="relative overflow-hidden rounded-2xl border border-sky-500/40 bg-stone-900 p-4 shadow-xl shadow-stone-950/60 transition-all hover:border-sky-400"
                        >
                            <!-- Top Card Header: Table Number & Time -->
                            <div
                                class="flex items-center justify-between border-b border-stone-800 pb-2.5"
                            >
                                <div class="flex items-center gap-2">
                                    <span
                                        class="rounded-xl border border-sky-500/30 bg-sky-950/80 px-3 py-1 font-serif text-lg font-black tracking-tight text-sky-300 shadow-inner"
                                    >
                                        {{
                                            order.table?.table_number ||
                                            'Meja --'
                                        }}
                                    </span>
                                </div>
                                <div class="text-right">
                                    <div
                                        class="flex items-center justify-end gap-1 font-mono text-xs font-bold text-stone-200"
                                    >
                                        <Clock class="h-3 w-3 text-sky-400" />
                                        <span>{{
                                            formatTime(order.created_at)
                                        }}</span>
                                    </div>
                                    <span
                                        class="text-[10px] font-medium text-sky-400/80"
                                        >Sedang Diracik</span
                                    >
                                </div>
                            </div>

                            <!-- List Item -->
                            <div class="space-y-2 py-3">
                                <div
                                    v-for="item in order.items"
                                    :key="item.id"
                                    class="rounded-xl border border-stone-800/80 bg-stone-950/70 p-2.5"
                                >
                                    <div
                                        class="flex items-center justify-between text-xs"
                                    >
                                        <div class="flex items-center gap-2">
                                            <span
                                                class="flex h-6 w-6 items-center justify-center rounded-lg border border-sky-500/30 bg-sky-500/20 text-xs font-extrabold text-sky-300"
                                            >
                                                {{ item.quantity }}x
                                            </span>
                                            <span
                                                class="text-sm font-bold text-stone-100"
                                            >
                                                {{ item.menu?.name || 'Menu' }}
                                            </span>
                                        </div>
                                    </div>

                                    <!-- Catatan Kustom -->
                                    <div
                                        v-if="item.notes"
                                        class="mt-1.5 flex items-start gap-1.5 rounded-lg border border-amber-400/50 bg-amber-950/90 px-2 py-1 text-[11px] leading-snug font-medium text-amber-200"
                                    >
                                        <AlertCircle
                                            class="mt-0.5 h-3.5 w-3.5 shrink-0 text-amber-400"
                                        />
                                        <span
                                            >Notes:
                                            <strong class="underline">{{
                                                item.notes
                                            }}</strong></span
                                        >
                                    </div>
                                </div>
                            </div>

                            <!-- Action: Selesai Racik -->
                            <div
                                class="flex items-center justify-between gap-2 border-t border-stone-800 pt-2"
                            >
                                <button
                                    @click="updateStatus(order.id, 'pending')"
                                    class="text-xs text-stone-400 transition hover:text-stone-200"
                                    title="Kembalikan ke antrean baru"
                                >
                                    &larr; Antrean
                                </button>

                                <button
                                    @click="updateStatus(order.id, 'completed')"
                                    :disabled="updatingOrderId === order.id"
                                    class="flex cursor-pointer items-center gap-1.5 rounded-xl bg-gradient-to-r from-emerald-600 to-emerald-500 px-4 py-2 text-xs font-extrabold text-stone-950 shadow-md shadow-emerald-600/30 transition hover:from-emerald-500 hover:to-emerald-400 active:scale-95 disabled:opacity-50"
                                >
                                    <CheckCircle2 class="h-3.5 w-3.5" />
                                    <span>{{
                                        updatingOrderId === order.id
                                            ? 'Memproses...'
                                            : 'Selesai Racik'
                                    }}</span>
                                </button>
                            </div>
                        </div>
                    </TransitionGroup>
                </div>

                <!-- KOLOM 3: SIAP DISAJIKAN (COMPLETED) -->
                <div
                    class="flex min-h-[500px] flex-col gap-3 rounded-2xl border border-emerald-900/30 bg-stone-900/50 p-3.5"
                >
                    <div
                        class="flex items-center justify-between border-b border-stone-800/80 px-1 pb-2"
                    >
                        <div class="flex items-center gap-2">
                            <span
                                class="h-3 w-3 rounded-full bg-emerald-500 shadow-sm shadow-emerald-500/50"
                            ></span>
                            <h2
                                class="font-serif text-sm font-bold text-stone-200"
                            >
                                Siap Disajikan
                            </h2>
                        </div>
                        <span
                            class="rounded-full bg-emerald-500/20 px-2 py-0.5 font-mono text-xs font-bold text-emerald-300"
                        >
                            {{ completedOrders.length }}
                        </span>
                    </div>

                    <TransitionGroup
                        name="order-card"
                        tag="div"
                        class="space-y-3.5"
                    >
                        <div
                            v-for="order in completedOrders"
                            :key="order.id"
                            class="rounded-2xl border border-emerald-500/30 bg-stone-900/80 p-4 opacity-85 shadow-lg transition-all hover:opacity-100"
                        >
                            <div
                                class="flex items-center justify-between border-b border-stone-800 pb-2"
                            >
                                <span
                                    class="font-serif text-base font-bold text-emerald-400"
                                >
                                    {{ order.table?.table_number }}
                                </span>
                                <span class="font-mono text-xs text-stone-400">
                                    {{
                                        formatTime(
                                            order.updated_at ||
                                                order.created_at,
                                        )
                                    }}
                                </span>
                            </div>

                            <div class="space-y-1 py-2 text-xs text-stone-300">
                                <div
                                    v-for="item in order.items"
                                    :key="item.id"
                                    class="flex justify-between"
                                >
                                    <span
                                        >{{ item.quantity }}x
                                        {{ item.menu?.name }}</span
                                    >
                                </div>
                            </div>

                            <div
                                class="flex items-center justify-between border-t border-stone-800 pt-2 text-xs"
                            >
                                <span
                                    class="inline-flex items-center gap-1 font-semibold text-emerald-400"
                                >
                                    <Check class="h-3.5 w-3.5" />
                                    <span>Disajikan</span>
                                </span>
                                <button
                                    @click="updateStatus(order.id, 'cooking')"
                                    class="text-[11px] text-stone-500 hover:text-stone-300"
                                >
                                    Racik Ulang
                                </button>
                            </div>
                        </div>
                    </TransitionGroup>
                </div>
            </div>

            <!-- FOCUSED SINGLE COLUMN VIEW (Saat filter tab selain 'all' dipilih) -->
            <div
                v-else
                class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4"
            >
                <TransitionGroup name="order-card" tag="div" class="contents">
                    <div
                        v-for="order in displayedOrders"
                        :key="order.id"
                        class="rounded-2xl border bg-stone-900 p-4 shadow-xl transition-all"
                        :class="[
                            order.status === 'pending'
                                ? 'border-amber-500/50 hover:border-amber-400'
                                : order.status === 'cooking'
                                  ? 'border-sky-500/50 hover:border-sky-400'
                                  : 'border-emerald-500/40 opacity-85',
                        ]"
                    >
                        <!-- Card Header -->
                        <div
                            class="flex items-center justify-between border-b border-stone-800 pb-2.5"
                        >
                            <span
                                class="font-serif text-lg font-black text-stone-100"
                            >
                                {{ order.table?.table_number }}
                            </span>
                            <div class="text-right">
                                <span
                                    class="font-mono text-xs font-bold text-stone-300"
                                >
                                    {{ formatTime(order.created_at) }}
                                </span>
                                <p class="text-[10px] text-stone-400">
                                    {{ getElapsedMinutes(order.created_at) }}
                                </p>
                            </div>
                        </div>

                        <!-- Card Items -->
                        <div class="space-y-2 py-3">
                            <div
                                v-for="item in order.items"
                                :key="item.id"
                                class="rounded-xl border border-stone-800 bg-stone-950/80 p-2 text-xs"
                            >
                                <div class="flex items-center gap-2">
                                    <span
                                        class="flex h-5 w-5 items-center justify-center rounded bg-amber-500/20 text-[11px] font-bold text-amber-300"
                                    >
                                        {{ item.quantity }}x
                                    </span>
                                    <span class="font-bold text-stone-200">{{
                                        item.menu?.name
                                    }}</span>
                                </div>
                                <div
                                    v-if="item.notes"
                                    class="mt-1 rounded border border-amber-500/30 bg-amber-950/80 px-2 py-0.5 text-[11px] text-amber-200"
                                >
                                    Notes: <strong>{{ item.notes }}</strong>
                                </div>
                            </div>
                        </div>

                        <!-- Card Actions -->
                        <div
                            class="flex items-center justify-between gap-2 border-t border-stone-800 pt-2.5"
                        >
                            <span class="text-xs font-bold text-stone-400">
                                {{ formatRupiah(order.total_price) }}
                            </span>

                            <button
                                v-if="order.status === 'pending'"
                                @click="updateStatus(order.id, 'cooking')"
                                :disabled="updatingOrderId === order.id"
                                class="flex cursor-pointer items-center gap-1 rounded-xl bg-amber-500 px-3 py-1.5 text-xs font-bold text-stone-950 transition hover:bg-amber-400 active:scale-95"
                            >
                                <Flame class="h-3.5 w-3.5 fill-current" />
                                <span>Mulai Racik</span>
                            </button>

                            <button
                                v-else-if="order.status === 'cooking'"
                                @click="updateStatus(order.id, 'completed')"
                                :disabled="updatingOrderId === order.id"
                                class="flex cursor-pointer items-center gap-1 rounded-xl bg-emerald-500 px-3 py-1.5 text-xs font-bold text-stone-950 transition hover:bg-emerald-400 active:scale-95"
                            >
                                <CheckCircle2 class="h-3.5 w-3.5" />
                                <span>Selesai</span>
                            </button>

                            <span
                                v-else
                                class="inline-flex items-center gap-1 text-xs font-semibold text-emerald-400"
                            >
                                <Check class="h-3.5 w-3.5" /> Selesai
                            </span>
                        </div>
                    </div>
                </TransitionGroup>
            </div>
        </main>
    </div>
</template>

<style scoped>
/* Transisi kartu pesanan meluncur masuk (slide & fade) */
.order-card-enter-active {
    transition: all 0.4s cubic-bezier(0.16, 1, 0.3, 1);
}
.order-card-leave-active {
    transition: all 0.3s ease-in;
    position: absolute;
    width: 100%;
}
.order-card-enter-from {
    opacity: 0;
    transform: translateY(-25px) scale(0.95);
}
.order-card-leave-to {
    opacity: 0;
    transform: scale(0.9);
}
.order-card-move {
    transition: transform 0.4s ease;
}
</style>
