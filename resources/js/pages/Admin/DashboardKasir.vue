<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import axios from 'axios';
import { echo } from '@laravel/echo-vue';
import {
    Banknote,
    CheckCircle2,
    ChevronDown,
    Coffee,
    CreditCard,
    Minus,
    Plus,
    QrCode,
    Search,
    ShoppingBag,
    Sparkles,
    Trash2,
    X,
} from 'lucide-vue-next';

interface Menu {
    id: number;
    name: string;
    price: string | number;
    is_available: boolean;
}

interface OrderItem {
    id: number;
    menu_id: number;
    quantity: number;
    notes: string | null;
    menu?: Menu;
}

interface Order {
    id: string;
    table_id: string;
    total_price: string | number;
    status: 'pending' | 'cooking' | 'completed' | 'paid';
    created_at: string;
    items?: OrderItem[];
}

interface Table {
    id: string;
    table_number: string;
    current_token?: string | null;
    active_order?: Order | null;
}

interface CartItem {
    key: string;
    menu_id: number;
    name: string;
    price: number;
    quantity: number;
    notes: string;
}

declare global {
    interface Window {
        snap?: {
            pay: (
                token: string,
                callbacks: {
                    onSuccess?: () => void;
                    onPending?: () => void;
                    onError?: () => void;
                    onClose?: () => void;
                },
            ) => void;
        };
    }
}

const props = defineProps<{
    tables: Table[];
    menus: Menu[];
}>();

const tables = ref<Table[]>(props.tables.map((table) => ({ ...table })));
const selectedTableId = ref<string | null>(null);
const searchQuery = ref('');
const paymentMethod = ref<'cash' | 'qris'>('cash');
const cart = ref<CartItem[]>([]);
const isProcessing = ref(false);
const errorMessage = ref<string | null>(null);

const selectedTable = computed(
    () =>
        tables.value.find((table) => table.id === selectedTableId.value) ??
        null,
);
const activeOrder = computed(() => selectedTable.value?.active_order ?? null);
const availableMenus = computed(() =>
    props.menus.filter((menu) => menu.is_available),
);
const filteredMenus = computed(() => {
    const query = searchQuery.value.trim().toLowerCase();
    return availableMenus.value.filter((menu) =>
        menu.name.toLowerCase().includes(query),
    );
});
const cartSubtotal = computed(() =>
    cart.value.reduce((total, item) => total + item.price * item.quantity, 0),
);
const orderSubtotal = computed(() =>
    Number(activeOrder.value?.total_price ?? 0),
);
const subtotal = computed(() =>
    activeOrder.value ? orderSubtotal.value : cartSubtotal.value,
);
const tax = computed(() => Math.round(subtotal.value * 0.1));
const grandTotal = computed(() => subtotal.value + tax.value);
const occupiedCount = computed(
    () => tables.value.filter((table) => table.active_order).length,
);

const formatRupiah = (value: string | number) =>
    new Intl.NumberFormat('id-ID', {
        style: 'currency',
        currency: 'IDR',
        maximumFractionDigits: 0,
    }).format(Number(value) || 0);

const selectTable = (table: Table) => {
    selectedTableId.value = table.id;
    paymentMethod.value = 'cash';
    errorMessage.value = null;
    cart.value = [];
};

const addMenuToCart = (menu: Menu) => {
    const existing = cart.value.find((item) => item.menu_id === menu.id);
    if (existing) {
        existing.quantity += 1;
        return;
    }

    cart.value.push({
        key: `${menu.id}-${Date.now()}`,
        menu_id: menu.id,
        name: menu.name,
        price: Number(menu.price),
        quantity: 1,
        notes: '',
    });
};

const changeCartQuantity = (key: string, amount: number) => {
    const item = cart.value.find((cartItem) => cartItem.key === key);
    if (!item) return;
    item.quantity += amount;
    if (item.quantity <= 0) {
        cart.value = cart.value.filter((cartItem) => cartItem.key !== key);
    }
};

const removeCartItem = (key: string) => {
    cart.value = cart.value.filter((item) => item.key !== key);
};

const applyOrderUpdate = (order: Order) => {
    const table = tables.value.find((item) => item.id === order.table_id);
    if (!table) return;
    table.active_order = order.status === 'paid' ? null : order;
    if (order.status === 'paid' && selectedTableId.value === order.table_id) {
        cart.value = [];
    }
};

const confirmCashPayment = async () => {
    if (!activeOrder.value || isProcessing.value) return;
    isProcessing.value = true;
    errorMessage.value = null;
    try {
        const response = await axios.post(
            `/api/orders/${activeOrder.value.id}/cash`,
        );
        applyOrderUpdate(response.data.data);
    } catch (error: any) {
        errorMessage.value =
            error.response?.data?.message ?? 'Pembayaran cash gagal diproses.';
    } finally {
        isProcessing.value = false;
    }
};

const payWithQris = async () => {
    if (!activeOrder.value || isProcessing.value) return;
    if (!window.snap) {
        errorMessage.value =
            'SDK QRIS belum siap. Muat ulang halaman dan coba lagi.';
        return;
    }

    isProcessing.value = true;
    errorMessage.value = null;
    try {
        const response = await axios.post(
            `/api/orders/${activeOrder.value.id}/snap-token`,
        );
        window.snap.pay(response.data.snap_token, {
            onSuccess: () => {
                isProcessing.value = false;
            },
            onPending: () => {
                isProcessing.value = false;
            },
            onError: () => {
                errorMessage.value =
                    'Pembayaran QRIS gagal. Silakan coba lagi.';
                isProcessing.value = false;
            },
            onClose: () => {
                isProcessing.value = false;
            },
        });
    } catch (error: any) {
        errorMessage.value =
            error.response?.data?.message ?? 'Token QRIS gagal dibuat.';
        isProcessing.value = false;
    }
};

onMounted(() => {
    echo()
        .channel('dapur-channel')
        .listen('.NewOrder', (payload: { order: Order }) =>
            applyOrderUpdate(payload.order),
        )
        .listen('.OrderUpdated', (payload: { order: Order }) =>
            applyOrderUpdate(payload.order),
        );
});

onUnmounted(() => {
    echo().leaveChannel('dapur-channel');
});
</script>

<template>
    <Head title="Kasir POS | KopiKita" />

    <main class="h-screen overflow-hidden bg-slate-50 text-slate-900">
        <header
            class="flex h-16 items-center justify-between bg-slate-900 px-5 text-white shadow-md shadow-slate-900/10"
        >
            <div class="flex items-center gap-3">
                <div
                    class="flex h-9 w-9 items-center justify-center rounded-xl bg-amber-400 text-slate-950 shadow-lg shadow-amber-500/20"
                >
                    <Coffee class="h-5 w-5" />
                </div>
                <div>
                    <h1
                        class="text-lg font-black tracking-tight drop-shadow-sm"
                    >
                        KopiKita <span class="text-amber-400">POS</span>
                    </h1>
                    <p
                        class="text-[10px] font-medium tracking-[0.2em] text-slate-400 uppercase"
                    >
                        Point of Sale
                    </p>
                </div>
            </div>
            <div
                class="flex items-center gap-5 text-xs font-semibold text-slate-300"
            >
                <span
                    ><strong class="text-amber-300">{{ occupiedCount }}</strong>
                    meja terisi</span
                >
                <span class="hidden text-slate-500 sm:inline">Kasir aktif</span>
                <span class="flex items-center gap-1.5 text-emerald-400"
                    ><i
                        class="h-2 w-2 rounded-full bg-emerald-400 shadow-[0_0_8px] shadow-emerald-400"
                    />
                    Live</span
                >
            </div>
        </header>

        <div
            class="grid h-[calc(100vh-64px)] grid-cols-1 lg:grid-cols-[25%_45%_30%]"
        >
            <aside
                class="hidden min-h-0 border-r border-slate-200 bg-white p-4 lg:block"
            >
                <div class="mb-4 flex items-center justify-between">
                    <div>
                        <h2 class="font-bold text-slate-800">Daftar Meja</h2>
                        <p class="mt-0.5 text-[11px] text-slate-400">
                            Pilih meja untuk melihat struk
                        </p>
                    </div>
                    <span
                        class="rounded-lg bg-slate-100 px-2 py-1 text-[10px] font-bold text-slate-500"
                        >{{ tables.length }} meja</span
                    >
                </div>
                <div class="grid grid-cols-2 gap-3 xl:grid-cols-3">
                    <button
                        v-for="table in tables"
                        :key="table.id"
                        type="button"
                        @click="selectTable(table)"
                        :class="[
                            table.active_order
                                ? 'border-rose-200 bg-rose-50 text-rose-700'
                                : 'border-emerald-200 bg-emerald-50 text-emerald-700',
                            selectedTableId === table.id
                                ? 'border-amber-500 ring-2 ring-amber-300/70'
                                : '',
                        ]"
                        class="rounded-xl border p-3 text-left shadow-sm transition hover:-translate-y-0.5 hover:shadow-md"
                    >
                        <div class="flex items-center justify-between">
                            <span
                                class="text-[10px] font-bold tracking-wider uppercase opacity-60"
                                >Meja</span
                            >
                            <i
                                :class="
                                    table.active_order
                                        ? 'bg-rose-500'
                                        : 'bg-emerald-500'
                                "
                                class="h-2.5 w-2.5 rounded-full"
                            />
                        </div>
                        <p class="mt-2 text-lg font-black">
                            {{ table.table_number }}
                        </p>
                        <p
                            v-if="table.active_order"
                            class="mt-1 text-[11px] font-bold"
                        >
                            {{ formatRupiah(table.active_order.total_price) }}
                        </p>
                        <p
                            v-else
                            class="mt-1 text-[10px] font-semibold opacity-60"
                        >
                            Kosong
                        </p>
                    </button>
                </div>
            </aside>

            <section
                class="min-h-0 overflow-y-auto border-r border-slate-200 bg-slate-50 p-4 sm:p-5"
            >
                <div class="mb-5 flex items-center justify-between">
                    <div>
                        <p
                            class="flex items-center gap-1.5 text-[10px] font-bold tracking-[0.2em] text-amber-700 uppercase"
                        >
                            <Sparkles class="h-3.5 w-3.5" /> Katalog KopiKita
                        </p>
                        <h2
                            class="mt-1 text-xl font-black tracking-tight text-slate-800"
                        >
                            Pilih produk
                        </h2>
                    </div>
                    <span
                        class="rounded-full bg-white px-3 py-1.5 text-xs font-semibold text-slate-500 shadow-sm"
                        >{{ filteredMenus.length }} produk</span
                    >
                </div>
                <div
                    class="sticky top-0 z-10 mb-4 flex items-center gap-3 rounded-2xl border border-slate-200 bg-white px-4 py-3 shadow-sm"
                >
                    <Search class="h-4 w-4 text-slate-400" />
                    <input
                        v-model="searchQuery"
                        type="search"
                        placeholder="Cari produk kopi/makanan..."
                        class="w-full bg-transparent text-sm outline-none placeholder:text-slate-400"
                    />
                    <button
                        v-if="searchQuery"
                        type="button"
                        aria-label="Hapus pencarian"
                        @click="searchQuery = ''"
                    >
                        <X class="h-4 w-4 text-slate-400" />
                    </button>
                </div>
                <div
                    v-if="filteredMenus.length"
                    class="grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-4"
                >
                    <article
                        v-for="menu in filteredMenus"
                        :key="menu.id"
                        class="group rounded-2xl border border-slate-200 bg-white p-3 shadow-sm transition hover:-translate-y-0.5 hover:shadow-md"
                    >
                        <div
                            class="flex h-24 items-center justify-center rounded-xl bg-gradient-to-br from-amber-100 via-orange-50 to-slate-100 text-amber-800"
                        >
                            <Coffee
                                class="h-9 w-9 stroke-[1.5] transition group-hover:scale-110"
                            />
                        </div>
                        <h3
                            class="mt-3 truncate text-sm font-semibold text-slate-800"
                        >
                            {{ menu.name }}
                        </h3>
                        <div
                            class="mt-2 flex items-center justify-between gap-2"
                        >
                            <span class="text-sm font-black text-slate-900">{{
                                formatRupiah(menu.price)
                            }}</span>
                            <button
                                type="button"
                                aria-label="Tambah produk"
                                class="flex h-7 w-7 items-center justify-center rounded-lg bg-slate-900 text-white transition hover:bg-amber-600"
                                @click="addMenuToCart(menu)"
                            >
                                <Plus class="h-4 w-4" />
                            </button>
                        </div>
                    </article>
                </div>
                <div
                    v-else
                    class="rounded-2xl border border-dashed border-slate-300 bg-white p-10 text-center text-sm text-slate-400"
                >
                    Produk tidak ditemukan.
                </div>
            </section>

            <section class="flex min-h-0 flex-col bg-white">
                <div class="border-b border-slate-200 px-5 py-4">
                    <div class="flex items-center justify-between">
                        <div>
                            <p
                                class="text-[10px] font-bold tracking-[0.2em] text-slate-400 uppercase"
                            >
                                Rincian struk
                            </p>
                            <h2 class="mt-1 text-xl font-black text-slate-800">
                                {{
                                    selectedTable?.table_number ??
                                    'Belum pilih meja'
                                }}
                            </h2>
                        </div>
                        <ShoppingBag class="h-5 w-5 text-amber-600" />
                    </div>
                    <div class="mt-3 flex items-center gap-2">
                        <span
                            :class="
                                activeOrder
                                    ? 'bg-rose-100 text-rose-700'
                                    : 'bg-emerald-100 text-emerald-700'
                            "
                            class="rounded-full px-2.5 py-1 text-[10px] font-bold"
                            >{{
                                activeOrder ? 'TAGIHAN AKTIF' : 'ORDER BARU'
                            }}</span
                        >
                        <span
                            v-if="activeOrder"
                            class="text-[10px] text-slate-400"
                            >#{{ activeOrder.id.slice(0, 8) }}</span
                        >
                    </div>
                </div>

                <div class="min-h-0 flex-1 overflow-y-auto px-5 py-4">
                    <div v-if="activeOrder?.items?.length" class="space-y-4">
                        <div
                            v-for="item in activeOrder.items"
                            :key="item.id"
                            class="border-b border-slate-100 pb-3"
                        >
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <p class="text-sm font-bold text-slate-800">
                                        {{ item.menu?.name ?? 'Menu' }}
                                    </p>
                                    <p
                                        v-if="item.notes"
                                        class="mt-1 text-[11px] leading-relaxed text-slate-400"
                                    >
                                        Catatan: {{ item.notes }}
                                    </p>
                                </div>
                                <span
                                    class="text-sm font-black text-slate-800"
                                    >{{
                                        formatRupiah(
                                            Number(item.menu?.price ?? 0) *
                                                item.quantity,
                                        )
                                    }}</span
                                >
                            </div>
                            <div class="mt-2 flex items-center gap-2">
                                <button
                                    type="button"
                                    class="flex h-6 w-6 items-center justify-center rounded-md bg-slate-100 text-slate-500"
                                >
                                    <Minus class="h-3 w-3" />
                                </button>
                                <span
                                    class="min-w-5 text-center text-xs font-bold"
                                    >{{ item.quantity }}</span
                                >
                                <button
                                    type="button"
                                    class="flex h-6 w-6 items-center justify-center rounded-md bg-slate-100 text-slate-500"
                                >
                                    <Plus class="h-3 w-3" />
                                </button>
                            </div>
                        </div>
                    </div>
                    <div v-else-if="cart.length" class="space-y-4">
                        <div
                            v-for="item in cart"
                            :key="item.key"
                            class="border-b border-slate-100 pb-3"
                        >
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <p class="text-sm font-bold text-slate-800">
                                        {{ item.name }}
                                    </p>
                                    <input
                                        v-model="item.notes"
                                        placeholder="Tambah catatan..."
                                        class="mt-1 w-full text-[11px] text-slate-400 outline-none placeholder:text-slate-300"
                                    />
                                </div>
                                <button
                                    type="button"
                                    aria-label="Hapus item"
                                    class="text-slate-300 hover:text-rose-500"
                                    @click="removeCartItem(item.key)"
                                >
                                    <Trash2 class="h-4 w-4" />
                                </button>
                            </div>
                            <div class="mt-2 flex items-center justify-between">
                                <span class="text-sm font-black">{{
                                    formatRupiah(item.price * item.quantity)
                                }}</span>
                                <div class="flex items-center gap-2">
                                    <button
                                        type="button"
                                        class="flex h-6 w-6 items-center justify-center rounded-md bg-slate-100"
                                        @click="
                                            changeCartQuantity(item.key, -1)
                                        "
                                    >
                                        <Minus class="h-3 w-3" /></button
                                    ><span
                                        class="min-w-5 text-center text-xs font-bold"
                                        >{{ item.quantity }}</span
                                    ><button
                                        type="button"
                                        class="flex h-6 w-6 items-center justify-center rounded-md bg-slate-900 text-white"
                                        @click="changeCartQuantity(item.key, 1)"
                                    >
                                        <Plus class="h-3 w-3" />
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div
                        v-else
                        class="flex h-full min-h-48 flex-col items-center justify-center text-center text-slate-400"
                    >
                        <ShoppingBag
                            class="h-10 w-10 stroke-1 text-slate-300"
                        />
                        <p class="mt-3 text-sm font-semibold">
                            Struk masih kosong
                        </p>
                        <p class="mt-1 text-xs">
                            Pilih meja atau tambahkan produk dari katalog.
                        </p>
                    </div>
                </div>

                <div class="border-t border-slate-200 bg-slate-50 px-5 py-4">
                    <div class="space-y-2 text-xs">
                        <div class="flex justify-between text-slate-500">
                            <span>Subtotal</span
                            ><span class="font-semibold text-slate-700">{{
                                formatRupiah(subtotal)
                            }}</span>
                        </div>
                        <div class="flex justify-between text-slate-500">
                            <span>Pajak (10%)</span
                            ><span class="font-semibold text-slate-700">{{
                                formatRupiah(tax)
                            }}</span>
                        </div>
                        <div
                            class="mt-3 flex justify-between border-t border-slate-200 pt-3 text-base font-black text-slate-900"
                        >
                            <span>Grand Total</span
                            ><span class="text-amber-700">{{
                                formatRupiah(grandTotal)
                            }}</span>
                        </div>
                    </div>
                    <div v-if="activeOrder" class="mt-4 grid grid-cols-2 gap-2">
                        <button
                            type="button"
                            @click="paymentMethod = 'cash'"
                            :class="
                                paymentMethod === 'cash'
                                    ? 'border-amber-500 bg-amber-50 text-amber-800'
                                    : 'border-slate-200 bg-white text-slate-500'
                            "
                            class="flex items-center justify-center gap-1.5 rounded-xl border p-2.5 text-xs font-bold"
                        >
                            <Banknote class="h-4 w-4" /> Cash
                        </button>
                        <button
                            type="button"
                            @click="paymentMethod = 'qris'"
                            :class="
                                paymentMethod === 'qris'
                                    ? 'border-amber-500 bg-amber-50 text-amber-800'
                                    : 'border-slate-200 bg-white text-slate-500'
                            "
                            class="flex items-center justify-center gap-1.5 rounded-xl border p-2.5 text-xs font-bold"
                        >
                            <QrCode class="h-4 w-4" /> QRIS
                        </button>
                    </div>
                    <p
                        v-if="errorMessage"
                        class="mt-3 rounded-lg bg-rose-50 p-2.5 text-xs text-rose-700"
                    >
                        {{ errorMessage }}
                    </p>
                    <button
                        v-if="activeOrder && paymentMethod === 'cash'"
                        type="button"
                        :disabled="isProcessing"
                        class="mt-3 flex w-full items-center justify-center gap-2 rounded-xl bg-slate-900 p-3.5 text-sm font-bold text-white shadow-lg shadow-slate-900/10 transition hover:bg-slate-800 disabled:opacity-60"
                        @click="confirmCashPayment"
                    >
                        <CheckCircle2 class="h-4 w-4" /> Konfirmasi Lunas (Cash)
                    </button>
                    <button
                        v-else-if="activeOrder"
                        type="button"
                        :disabled="isProcessing"
                        class="mt-3 flex w-full items-center justify-center gap-2 rounded-xl bg-amber-600 p-3.5 text-sm font-bold text-white shadow-lg shadow-amber-600/20 transition hover:bg-amber-700 disabled:opacity-60"
                        @click="payWithQris"
                    >
                        <CreditCard class="h-4 w-4" /> Bayar via QRIS
                    </button>
                    <div
                        v-else
                        class="mt-3 flex items-center justify-center gap-2 rounded-xl border border-dashed border-slate-300 bg-white p-3 text-xs font-semibold text-slate-400"
                    >
                        <ChevronDown class="h-4 w-4" /> Pilih meja terisi untuk
                        menagih
                    </div>
                </div>
            </section>
        </div>
    </main>
</template>
